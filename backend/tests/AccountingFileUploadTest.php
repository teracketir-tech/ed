<?php

/**
 * Accounting attachments: two upload moments, two file columns.
 *
 * The hq panel takes files when a document is registered (-> `image`) and
 * when a draft is approved (-> `approve_image`, added by
 * sql/accounting-approve-image.sql). The documents list shows the two
 * moments under separate headings, so the columns must stay apart.
 *
 * Covered rules:
 *   1. validate(): a batch over the 10-file cap, a forbidden extension, a
 *      file over 10MB and a failed PHP upload are each refused with a
 *      message before anything touches disk; clean batches and an
 *      untouched file input pass
 *   2. read()/append(): a column holds a JSON array of urls, but legacy
 *      rows hold one bare url - both read back, junk entries are dropped,
 *      and new urls append without losing the old ones
 *   3. _save() with an invalid upload is refused before the document exists
 *   4. _save() with a valid upload writes the urls into `image`
 *   5. _approve() with an invalid upload changes nothing: status stays
 *      'draft' - the fix: the old code wrote the integer 2 here, which the
 *      status filter cannot match - and both file columns stay untouched
 *   6. _approve() with a valid upload writes into `approve_image` only,
 *      never into `image`, and a second approval appends
 *   7. a publish failure (ftp_put returning null or throwing) falls back
 *      to the locally served url instead of dropping the file
 *   8. get_all()'s `image` filter counts both columns
 *   9. AccountingController's jwtAuth list carries `approve` - the action
 *      used to run without a token
 *  10. pay()/receive() store the modal's files on the settlement document
 *      they create (`image`), refuse an invalid batch before any document
 *      exists, and the pay->receive recursion never uploads the batch twice
 *
 * The publish step is stubbed through AccountingFileService::$publisher,
 * so this never talks to the real FTP server.
 *
 * This one writes on purpose: _save()/_approve() are the code under test.
 * Every row it creates is prefixed and deleted on the way out.
 *
 * Run:  php frontend/modules/hq/tests/AccountingFileUploadTest.php
 */

$backend = dirname(__DIR__, 4);

// the same frontend bootstrap as web/index.php, minus run()
error_reporting(E_ERROR | E_PARSE);
date_default_timezone_set('Asia/Tehran');
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require $backend . '/vendor/autoload.php';
require $backend . '/vendor/yiisoft/yii2/Yii.php';
require $backend . '/common/config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require $backend . '/common/config/main.php',
    require $backend . '/common/config/main-local.php',
    require $backend . '/frontend/config/main.php',
    require $backend . '/frontend/config/main-local.php'
);

new yii\web\Application($config);

use common\constants\AccountingDataIds;
use frontend\models\AccountingItemPaid;
use frontend\modules\hq\controllers\AccountingController;
use frontend\modules\hq\models\Accounting;
use frontend\modules\hq\models\AccountingItem;
use frontend\modules\hq\services\AccountingFileService;
use frontend\modules\hq\services\AccountingItemService;

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $failures, $checks;
    $checks++;

    if ($condition) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

/** A draft document; only the fields _approve()/get_all() care about. */
function makeDoc($description)
{
    $doc = new Accounting();
    $doc->description = $description;
    $doc->status = 'draft';
    $doc->business_id = 1;
    $doc->company_id = 1;
    $doc->date = date('Y-m-d');
    $doc->base_currency = 2;
    $doc->save(false);

    return $doc;
}

/** An `images[]` $_FILES batch; empty tmp names unless the case moves them. */
function fakeUpload(array $names, array $tmpNames = [], array $sizes = [], array $errors = [])
{
    $count = count($names);

    return [
        'name' => $names,
        'tmp_name' => $tmpNames ?: array_fill(0, $count, ''),
        'type' => array_fill(0, $count, 'application/octet-stream'),
        'size' => $sizes ?: array_fill(0, $count, 1024),
        'error' => $errors ?: array_fill(0, $count, UPLOAD_ERR_OK),
    ];
}

/** A readable file standing in for whatever PHP uploaded. */
function realTmp($content = 'x')
{
    $path = tempnam(sys_get_temp_dir(), 'acct_upload_');
    file_put_contents($path, $content);

    return $path;
}

function purge($prefix)
{
    Accounting::deleteAll(['like', 'description', $prefix]);
}

$prefix = '__acct_file_test__';
purge($prefix);

// the publish step is replaced wholesale: no test may reach the real FTP
AccountingFileService::$publisher = function ($path, $remotePath) {
    return "https://storage.test/$remotePath/" . basename($path);
};

echo ">>> validate()\n";

ok(AccountingFileService::validate(null)['ok'] === true, 'no upload at all passes');
ok(AccountingFileService::validate(fakeUpload(['']))['ok'] === true, 'an untouched file input passes');
ok(AccountingFileService::validate(fakeUpload(['invoice.pdf', 'scan.jpg']))['ok'] === true, 'a clean batch passes');
ok(AccountingFileService::validate(fakeUpload(array_fill(0, 10, 'doc.pdf')))['ok'] === true, 'exactly 10 files pass the cap');
ok(AccountingFileService::validate(fakeUpload(array_fill(0, 11, 'doc.pdf')))['ok'] === false, 'an 11-file batch is refused');
ok(AccountingFileService::validate(fakeUpload(['setup.exe']))['ok'] === false, 'a forbidden extension is refused');
ok(AccountingFileService::validate(fakeUpload(['big.pdf'], [], [11 * 1024 * 1024]))['ok'] === false, 'a file over 10MB is refused');
ok(AccountingFileService::validate(fakeUpload(['half.pdf'], [], [], [UPLOAD_ERR_PARTIAL]))['ok'] === false, 'a failed PHP upload is refused');
ok(AccountingFileService::validate(fakeUpload(['setup.exe']))['message'] !== '', 'a refusal carries a message');

echo ">>> read()/append() - JSON array now, bare url in older rows\n";

ok(AccountingFileService::read('["https://s/a.pdf","https://s/b.pdf"]') === ['https://s/a.pdf', 'https://s/b.pdf'], 'a JSON array reads back as-is');
ok(AccountingFileService::read('https://s/legacy.pdf') === ['https://s/legacy.pdf'], 'a bare url (legacy row) reads as one file');
ok(AccountingFileService::read('"https://s/quoted.pdf"') === ['https://s/quoted.pdf'], 'a JSON-quoted url reads as one file');
ok(AccountingFileService::read('38') === [], 'a numeric value reads as no files instead of a bogus link');
ok(AccountingFileService::read(null) === [] && AccountingFileService::read('') === [], 'null and empty read as no files');
ok(AccountingFileService::read('["https://s/a.pdf",5]') === ['https://s/a.pdf'], 'junk entries are dropped');

ok(json_decode(AccountingFileService::append(null, ['https://s/new.pdf']), true) === ['https://s/new.pdf'], 'append starts a fresh list');
ok(json_decode(AccountingFileService::append('["https://s/a.pdf"]', ['https://s/b.pdf']), true) === ['https://s/a.pdf', 'https://s/b.pdf'], 'append keeps the old files and adds the new ones in order');

echo ">>> _save() with files\n";

$_FILES = ['images' => fakeUpload(['invoice.pdf', 'scan.jpg'], [realTmp(), realTmp()])];
Yii::$app->request->setBodyParams([
    'company_id' => 1,
    'business_id' => 1,
    'base_currency' => 2,
    'date' => date('Y-m-d'),
    'status' => 1,
    'description' => $prefix . '_create',
    'items' => [],
    'project_id' => null,
    'pay_accounting_item_id' => null,
    'user_ip' => null,
]);

$result = Accounting::_save();
$created = Accounting::find()->where(['description' => $prefix . '_create'])->one();
$createdUrls = json_decode($created->image ?? '[]', true);

ok(($result['success'] ?? false) === true, 'a document with a clean upload is created');
ok(is_array($createdUrls) && count($createdUrls) === 2, 'both uploaded urls land in the image column');
ok(is_array($createdUrls) && strpos($createdUrls[0], 'https://storage.test/accounting/') === 0, 'the urls come from the publish step');
ok($created->approve_image === null, 'the approval column stays empty on registration');

$_FILES = ['images' => fakeUpload(['malware.php'])];
Yii::$app->request->setBodyParams(['description' => $prefix . '_create_bad', 'items' => [], 'status' => 1, 'date' => date('Y-m-d')]);

$result = Accounting::_save();

ok(($result['success'] ?? false) === false && !empty($result['message']), 'an invalid upload is refused with a message');
ok(Accounting::find()->where(['description' => $prefix . '_create_bad'])->count() === 0, 'the document is never created when its upload is refused');

echo ">>> _approve() without files - and the status fix\n";

$plain = makeDoc($prefix . '_plain');
$_FILES = [];

$result = Accounting::_approve($plain->id);
$plain->refresh();

ok(($result['success'] ?? false) === true, 'approving without files succeeds');
ok($plain->status === 'approved', "status is the string 'approved' (the old code wrote the integer 2)");
ok($plain->approve_date !== null, 'approve_date is set');
ok($plain->approve_image === null, 'approve_image stays empty when nothing is uploaded');

echo ">>> _approve() with an invalid upload changes nothing\n";

$draft = makeDoc($prefix . '_approve_bad');
$_FILES = ['images' => fakeUpload(['evil.exe'])];

$result = Accounting::_approve($draft->id);
$draft->refresh();

ok(($result['success'] ?? false) === false && !empty($result['message']), 'an invalid upload is refused with a message');
ok($draft->status === 'draft', 'the document stays a draft when its upload is refused');
ok($draft->approve_date === null && $draft->approve_image === null, 'nothing is written on a refused upload');

echo ">>> _approve() with a valid upload - approve_image only, appends on re-approve\n";

$doc = makeDoc($prefix . '_approve_files');
$doc->image = json_encode(['https://storage.test/accounting/registered.pdf']);
$doc->save(false, ['image']);

$_FILES = ['images' => fakeUpload(['at-approval.pdf'], [realTmp()])];
Accounting::_approve($doc->id);
$doc->refresh();

$first = json_decode($doc->approve_image ?? 'null', true);
ok(is_array($first) && count($first) === 1 && strpos($first[0], 'https://storage.test/accounting/') === 0, 'the approval file lands in approve_image');
ok($doc->image === json_encode(['https://storage.test/accounting/registered.pdf']), 'the registration files in image stay untouched');

$_FILES = ['images' => fakeUpload(['second.pdf'], [realTmp()])];
Accounting::_approve($doc->id);
$doc->refresh();

$second = json_decode($doc->approve_image ?? 'null', true);
ok(is_array($second) && count($second) === 2, 'a second approval appends instead of replacing');
ok($second[0] === $first[0], 'the first approval file survives the second one');

echo ">>> a publish failure falls back to the locally served url\n";

Yii::$app->request->hostInfo = 'https://api.test';

$fallbackDoc = makeDoc($prefix . '_fallback');
AccountingFileService::$publisher = function () {
    return null;   // what ftp_put failing looks like
};

$_FILES = ['images' => fakeUpload(['kept.pdf'], [realTmp()])];
Accounting::_approve($fallbackDoc->id);
$fallbackDoc->refresh();

$fallback = json_decode($fallbackDoc->approve_image ?? 'null', true);
ok(is_array($fallback) && count($fallback) === 1 && strpos($fallback[0], 'https://api.test/uploads/accounting/') === 0, 'a publish failure keeps the file under a locally served url');

$keptPath = str_replace(Yii::$app->request->hostInfo, '', $fallback[0]);
$keptPath = Yii::getAlias('@frontend/web') . $keptPath;

ok(file_exists($keptPath), 'the local copy is kept when the publish failed');
unlink($keptPath);

AccountingFileService::$publisher = function () {
    throw new \Exception('FTP is down');   // what ftp_connect throwing looks like
};

$throwDoc = makeDoc($prefix . '_fallback_throw');
$_FILES = ['images' => fakeUpload(['kept2.pdf'], [realTmp()])];
Accounting::_approve($throwDoc->id);
$throwDoc->refresh();

$thrown = json_decode($throwDoc->approve_image ?? 'null', true);
ok(is_array($thrown) && count($thrown) === 1 && strpos($thrown[0], 'https://api.test/uploads/accounting/') === 0, 'a publish exception falls back the same way instead of blowing up the request');

$keptPath = str_replace(Yii::$app->request->hostInfo, '', $thrown[0]);
unlink(Yii::getAlias('@frontend/web') . $keptPath);

echo ">>> get_all()'s image filter counts both columns\n";

$onlyImage = makeDoc($prefix . '_list_image');
$onlyImage->image = json_encode(['https://s/a.pdf']);
$onlyImage->save(false, ['image']);

$onlyApprove = makeDoc($prefix . '_list_approve');
$onlyApprove->approve_image = json_encode(['https://s/b.pdf']);
$onlyApprove->save(false, ['approve_image']);

$noFiles = makeDoc($prefix . '_list_none');

Yii::$app->request->setQueryParams(['image' => 1, 'description' => $prefix . '_list', 'per-page' => 50]);
$ids = array_column(Accounting::get_all()['data'], 'id');

ok(in_array($onlyImage->id, $ids, true), 'a document with registration files is kept');
ok(in_array($onlyApprove->id, $ids, true), 'a document with only approval files is kept');
ok(!in_array($noFiles->id, $ids, true), 'a document without files is dropped');

Yii::$app->request->setQueryParams(['image' => '2', 'description' => $prefix . '_list', 'per-page' => 50]);
$ids = array_column(Accounting::get_all()['data'], 'id');

ok(in_array($noFiles->id, $ids, true), 'the no-files filter keeps a document without files');
ok(!in_array($onlyImage->id, $ids, true) && !in_array($onlyApprove->id, $ids, true), 'the no-files filter drops both kinds of files');

echo ">>> pay()/receive(): the settlement document carries the files\n";

// the previous section left a throwing publisher behind
AccountingFileService::$publisher = function ($path, $remotePath) {
    return "https://storage.test/$remotePath/" . basename($path);
};

$baseDoc = makeDoc($prefix . '_settlement_base');
$baseItem = new AccountingItem();
$baseItem->accounting_id = $baseDoc->id;
$baseItem->company_id = 1;
$baseItem->accounting_data_id = AccountingDataIds::RECEIVABLE;
$baseItem->contact_id = 0;
$baseItem->debit = '5000';
$baseItem->credit = '0';
$baseItem->save(false);

$receiveParams = [
    'id' => $baseItem->id,
    'price' => '1000',
    'date' => date('Y-m-d'),
    'accounting_data_id' => AccountingDataIds::BANK,
    'detail_id' => 0,
    'detail_name' => '',
    'pay_accounting_item_id' => null,
];

$_FILES = ['images' => fakeUpload(['receipt.pdf'], [realTmp()])];
$result = AccountingItemService::receive($receiveParams);

$receiveDocs = Accounting::find()->where(['description' => "دریافت سند $baseItem->id"])->all();
$receiveUrls = json_decode($receiveDocs[0]->image ?? 'null', true);

ok(($result['success'] ?? false) === true, 'a receive with a clean upload succeeds');
ok(count($receiveDocs) === 1, 'the receive creates its settlement document');
ok(is_array($receiveUrls) && count($receiveUrls) === 1 && strpos($receiveUrls[0], 'https://storage.test/accounting/') === 0, 'the receive files land in the new document\'s image column');
ok($receiveDocs[0]->approve_image === null, 'the settlement document keeps its approval column empty');

$_FILES = ['images' => fakeUpload(['evil.php'])];
$beforeCount = Accounting::find()->where(['description' => "دریافت سند $baseItem->id"])->count();

$result = AccountingItemService::receive($receiveParams);

ok(($result['success'] ?? false) === false && !empty($result['message']), 'a receive with an invalid upload is refused with a message');
ok(Accounting::find()->where(['description' => "دریافت سند $baseItem->id"])->count() === $beforeCount, 'no settlement document is created when the upload is refused');

$payableDoc = makeDoc($prefix . '_payable_base');
$payableItem = new AccountingItem();
$payableItem->accounting_id = $payableDoc->id;
$payableItem->company_id = 1;
$payableItem->accounting_data_id = AccountingDataIds::PAYABLE;
$payableItem->contact_id = 0;
$payableItem->debit = '3000';
$payableItem->credit = '0';
$payableItem->save(false);

$publishCalls = 0;
AccountingFileService::$publisher = function ($path, $remotePath) use (&$publishCalls) {
    $publishCalls++;
    return "https://storage.test/$remotePath/" . basename($path);
};

$_FILES = ['images' => fakeUpload(['settle.pdf'], [realTmp()])];
$result = AccountingItemService::pay([
    'id' => $payableItem->id,
    'price' => '500',
    'date' => date('Y-m-d'),
    'accounting_data_id' => AccountingDataIds::BANK,
    'detail_id' => 0,
    'detail_name' => '',
    'pay_accounting_item_id' => $baseItem->id,   // also settles the original item
]);

$payDocs = Accounting::find()->where(['description' => "پرداخت سند $payableItem->id"])->all();
$receiveDocs = Accounting::find()->where(['description' => "دریافت سند $baseItem->id"])->all();
$payUrls = json_decode($payDocs[0]->image ?? 'null', true);

ok(($result['success'] ?? false) === true, 'a pay that also settles the original item succeeds');
ok(count($payDocs) === 1 && count($receiveDocs) === 2, 'the pay and its recursion each create their own document');
ok($publishCalls === 1, 'the uploaded batch is published once - the recursion never re-uploads it');
ok(is_array($payUrls) && count($payUrls) === 1, 'the files land on the pay document only');
ok(count(array_filter($receiveDocs, function ($one) { return $one->image !== null; })) === 1, 'the recursion-created receive document carries no files');

// these documents carry generated descriptions, so purge() cannot see them
$settlementDocIds = [$baseDoc->id, $payableDoc->id];

foreach (array_merge($payDocs, $receiveDocs) as $one) {
    $settlementDocIds[] = $one->id;
}

AccountingItem::deleteAll(['accounting_id' => $settlementDocIds]);
AccountingItemPaid::deleteAll(['accounting_id_main' => [$baseItem->id, $payableItem->id]]);
AccountingItem::deleteAll(['id' => [$baseItem->id, $payableItem->id]]);
Accounting::deleteAll(['id' => $settlementDocIds]);

echo ">>> AccountingController: approve requires a token now\n";

$controller = new AccountingController('accounting', new yii\base\Module('hq', null));
$behaviors = $controller->behaviors();

ok(isset($behaviors['jwtAuth']), 'AccountingController has the jwtAuth behavior');
ok(in_array('approve', $behaviors['jwtAuth']['actions'] ?? [], true), 'the approve action is in the JWT list');

AccountingFileService::$publisher = null;
$_FILES = [];
purge($prefix);

echo "\n" . ($failures ? "FAILED: $failures of $checks checks\n" : "PASSED: all $checks checks\n");

exit($failures ? 1 : 0);
