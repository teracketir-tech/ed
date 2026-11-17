<?php

/**
 * Editing a draft document from the hq panel.
 *
 * The hq panel takes a registration fee the same way dash does: the form
 * lists the stored urls that stay (`kept_images`) and sends only the files
 * just picked. The rows are rewritten wholesale - a row keeps its id only
 * when it belongs to this very document.
 *
 * Unlike dash, hq never writes bank.balance: it derives every bank figure
 * from accounting_item when it is read (get_related_banks()). So an edit
 * only has to leave the rows truthful, which is the whole of the work here.
 *
 * Covered rules:
 *   1. an approved document is read-only: _update() refuses it with a
 *      message before touching anything
 *   2. a draft edit applies the payload: the fields, the kept rows (same
 *      id, new values), the added row and the removed row - the totals
 *      follow the payload
 *   3. an edit never approves the document nor rewrites its creator
 *   4. an item id that belongs to another document is never adopted; it
 *      arrives as a brand new row and the other document keeps its own
 *   5. detail resolution (apply_detail) books a contact detail into
 *      contact_id, and a detail_id of 1 on an account_id account books
 *      through sub_account_id - shared with _save()
 *   6. kept_images decides the registration files: listed stay, unlisted
 *      go, a client that sends neither keeps what it held, and an empty
 *      list wipes them; the new uploads append and never reach
 *      approve_image
 *   7. an invalid upload is refused before anything changes
 *   8. the mismatch flag follows the date the edit actually saved
 *   9. _view() hands the edit form the rows it has to hydrate
 *  10. AccountingController's jwtAuth list carries `update`
 *
 * The publish step is stubbed through AccountingFileService::$publisher,
 * so this never talks to the real FTP server.
 *
 * This one writes on purpose: _save()/_update() are the code under test.
 * Every row it creates is prefixed and deleted on the way out.
 *
 * Run:  php frontend/modules/hq/tests/AccountingDraftUpdateTest.php
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
use frontend\modules\hq\controllers\AccountingController;
use frontend\modules\hq\models\Accounting;
use frontend\modules\hq\models\AccountingItem;
use frontend\modules\hq\services\AccountingFileService;

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
    $path = tempnam(sys_get_temp_dir(), 'acct_edit_');
    file_put_contents($path, $content);

    return $path;
}

function purge($prefix)
{
    AccountingItem::deleteAll(['in', 'accounting_id', Accounting::find()->select('id')->where(['like', 'description', $prefix])]);
    Accounting::deleteAll(['like', 'description', $prefix]);
}

/**
 * The body _save() expects: the draft status, a company and a description.
 * `items` is what the document books.
 */
function payload($description, array $items, array $extra = [])
{
    return array_merge([
        'company_id' => 1,
        'business_id' => 1,
        'base_currency' => 2,
        'date' => date('Y-m-d'),
        'status' => 1,
        'description' => $description,
        'items' => $items,
        'project_id' => null,
        'rent_id' => null,
        'pay_accounting_item_id' => null,
        'user_ip' => null,
    ], $extra);
}

/** A balanced two-row document - every edit case starts from one of these. */
function makeDraft($description)
{
    // each case owns its own upload batch; a leftover one must not leak in
    $_FILES = [];

    Yii::$app->request->setBodyParams(payload($description, [
        ['accounting_data_id' => 110101, 'debit' => '1000', 'credit' => '0', 'description' => 'first'],
        ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1000', 'description' => 'second'],
    ]));

    $result = Accounting::_save();

    return Accounting::findOne((int) ($result['data']->id ?? 0));
}

/** The row ids of one document, oldest first. */
function itemIds($accountId)
{
    return AccountingItem::find()->where(['accounting_id' => $accountId])->orderBy(['id' => SORT_ASC])->column();
}

$prefix = '__acct_edit_test__';
purge($prefix);

// the publish step is replaced wholesale: no test may reach the real FTP
$publishCalls = 0;
AccountingFileService::$publisher = function ($path, $remotePath) use (&$publishCalls) {
    $publishCalls++;

    return "https://storage.test/$remotePath/" . basename($path);
};

echo ">>> 1. an approved document is read-only\n";

$approved = makeDraft($prefix . '_approved');
$approved->status = 'approved';
$approved->save(false, ['status']);
$approvedIds = itemIds($approved->id);

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_approved_edited', [
    ['accounting_data_id' => 110101, 'debit' => '9999', 'credit' => '0', 'description' => 'hijack'],
]));

$result = Accounting::_update($approved->id);
$approved->refresh();

ok(($result['success'] ?? false) === false && !empty($result['message']), 'editing an approved document is refused with a message');
ok($approved->status === 'approved', 'the approved document keeps its status');
ok($approved->description === $prefix . '_approved', 'its description is untouched');
ok(itemIds($approved->id) == $approvedIds, 'its rows are untouched');
ok(Accounting::find()->where(['description' => $prefix . '_approved_edited'])->count() === 0, 'the edit never lands anywhere else');

echo ">>> 2. a draft edit applies the payload\n";

$draft = makeDraft($prefix . '_draft');
$originalIds = itemIds($draft->id);

ok(count($originalIds) === 2, 'the starting draft books two rows');

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_draft_v2', [
    ['id' => $originalIds[0], 'accounting_data_id' => 110101, 'debit' => '400', 'credit' => '0', 'description' => 'kept and changed'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '600', 'description' => 'brand new'],
], ['extraForContact' => 'changed too']));

$result = Accounting::_update($draft->id);
$draft->refresh();
$rows = AccountingItem::find()->where(['accounting_id' => $draft->id])->orderBy(['id' => SORT_ASC])->all();

ok(($result['success'] ?? false) === true, 'editing a draft succeeds');
ok($draft->description === $prefix . '_draft_v2', 'the new description lands');
ok($draft->extraForContact === 'changed too', 'a field the form owns is updated');
ok(count($rows) === 2, 'the kept row stays and the removed row is gone');
ok((int) $rows[0]->id === (int) $originalIds[0], 'the kept row keeps its id');
ok($rows[0]->description === 'kept and changed' && (int) $rows[0]->debit === 400, 'the kept row takes the new values');
ok($rows[1]->description === 'brand new', 'the added row is booked on the same document');
ok((int) $draft->total_debit === 400 && (int) $draft->total_credit === 600, 'the totals follow the payload');
ok((int) $draft->price === 400, 'the registered price follows the payload too');

echo ">>> 3. an edit never approves the document nor rewrites its creator\n";

$draft = makeDraft($prefix . '_creator');
$before = $draft->attributes;

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_creator_v2', [
    ['accounting_data_id' => 110101, 'debit' => '10', 'credit' => '0', 'description' => 'x'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '10', 'description' => 'y'],
], [
    'status' => 2,
    'id' => 999999,
    'user_id' => 999999,
    'created_at' => '2000-01-01 00:00:00',
    'approve_date' => '2000-01-01 00:00:00',
]));

Accounting::_update($draft->id);
$draft->refresh();

ok($draft->status === 'draft', 'the payload cannot approve its own document');
ok($draft->approve_date === $before['approve_date'], 'approve_date stays empty on an edit');
ok((int) $draft->id === (int) $before['id'], 'the primary key is taken from the row, never from the payload');
ok((int) $draft->user_id === (int) $before['user_id'], 'the creator is taken from the row, never from the payload');
ok($draft->created_at === $before['created_at'], 'the creation timestamp is not rewritten');

echo ">>> 4. an item id that belongs to another document is never adopted\n";

$owner = makeDraft($prefix . '_owner');
$foreign = makeDraft($prefix . '_foreign');
$foreignId = itemIds($foreign->id)[0];

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_owner_v2', [
    ['id' => $foreignId, 'accounting_data_id' => 110101, 'debit' => '55', 'credit' => '0', 'description' => 'stolen'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '55', 'description' => 'honest'],
]));

Accounting::_update($owner->id);
$ownerRows = AccountingItem::find()->where(['accounting_id' => $owner->id])->orderBy(['id' => SORT_ASC])->all();
$foreignRow = AccountingItem::findOne($foreignId);

ok(count($ownerRows) === 2 && (int) $ownerRows[0]->id !== (int) $foreignId, 'the foreign id is booked as a brand new row');
ok($ownerRows[0]->description === 'stolen', 'the new row carries the posted values');
ok($foreignRow !== null && (int) $foreignRow->accounting_id === (int) $foreign->id, 'the other document still owns its row');
ok($foreignRow->description !== 'stolen', 'the other document keeps the values it had');

echo ">>> 5. detail resolution is shared with _save()\n";

// 11010211 books a contact; 140107 books its own parent account through
// sub_account_id when the posted detail is 1.
$detail = makeDraft($prefix . '_detail');

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_detail_v2', [
    ['accounting_data_id' => 11010211, 'detail_id' => 58, 'debit' => '5', 'credit' => '0', 'description' => 'c'],
    ['accounting_data_id' => 140107, 'detail_id' => 1, 'debit' => '0', 'credit' => '5', 'description' => 'a'],
]));

Accounting::_update($detail->id);
$detailRows = AccountingItem::find()->where(['accounting_id' => $detail->id])->orderBy(['id' => SORT_ASC])->all();

ok((int) $detailRows[0]->contact_id === 58, 'a contact detail lands in contact_id');
ok((int) $detailRows[1]->sub_account_id === 140107, 'a detail_id of 1 on an account_id account books through sub_account_id');
ok((int) $detailRows[1]->accounting_data_id === 1401, 'that row books against the parent account');

echo ">>> 6. kept_images decides the registration files\n";

$withFiles = makeDraft($prefix . '_files');
$_FILES = ['images' => fakeUpload(['a.pdf', 'b.pdf'], [realTmp('a'), realTmp('b')])];
Yii::$app->request->setBodyParams(payload($withFiles->description, [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
]));

Accounting::_update($withFiles->id);
$withFiles->refresh();
$stored = json_decode($withFiles->image ?? '[]', true);

ok(count($stored) === 2, 'the first edit carries the two uploaded urls');
ok($withFiles->approve_image === null, 'the approval column stays out of an edit');

// only the listed file survives, and the new one joins it
$publishCalls = 0;
$_FILES = ['images' => fakeUpload(['c.pdf'], [realTmp('c')])];
Yii::$app->request->setBodyParams(payload($withFiles->description, [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
], ['kept_images' => json_encode([$stored[0]])]));

Accounting::_update($withFiles->id);
$withFiles->refresh();
$afterKeep = json_decode($withFiles->image ?? '[]', true);

ok(count($afterKeep) === 2 && $afterKeep[0] === $stored[0], 'kept_images keeps the listed file and appends the new one');
ok($publishCalls === 1, 'only the newly picked file is published');

// a client that sends neither keeps what the document held
$_FILES = [];
Yii::$app->request->setBodyParams(payload($withFiles->description, [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
]));

Accounting::_update($withFiles->id);
$withFiles->refresh();

ok(json_decode($withFiles->image ?? '[]', true) == $afterKeep, 'sending neither kept_images nor files leaves the attachments alone');

// an empty list wipes them
$_FILES = [];
Yii::$app->request->setBodyParams(payload($withFiles->description, [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
], ['kept_images' => '[]']));

Accounting::_update($withFiles->id);
$withFiles->refresh();

ok($withFiles->image === '[]', 'an empty kept_images wipes the attachments');

echo ">>> 7. an invalid upload is refused before anything changes\n";

$refused = makeDraft($prefix . '_refused');
$refusedIds = itemIds($refused->id);

$_FILES = ['images' => fakeUpload(['malware.php'])];
Yii::$app->request->setBodyParams(payload($prefix . '_refused_v2', [
    ['accounting_data_id' => 110101, 'debit' => '777', 'credit' => '0', 'description' => 'x'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '777', 'description' => 'y'],
]));

$result = Accounting::_update($refused->id);
$refused->refresh();

ok(($result['success'] ?? false) === false && !empty($result['message']), 'a forbidden extension is refused with a message');
ok($refused->description === $prefix . '_refused', 'the document keeps the description it had');
ok((int) $refused->total_debit !== 777, 'the totals are not recomputed');
ok(itemIds($refused->id) == $refusedIds, 'the rows are untouched');

echo ">>> 8. the mismatch flag follows the saved date\n";

$flagged = makeDraft($prefix . '_flag');

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_flag', [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
], ['date' => '2020-01-01']));

Accounting::_update($flagged->id);
$flagged->refresh();

ok((int) $flagged->flag === 1, 'moving the date away from today raises the flag');

$_FILES = [];
Yii::$app->request->setBodyParams(payload($prefix . '_flag', [
    ['accounting_data_id' => 110101, 'debit' => '1', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '1', 'description' => 'b'],
], ['date' => date('Y-m-d')]));

Accounting::_update($flagged->id);
$flagged->refresh();

ok((int) $flagged->flag === 0, 'bringing the date back to today clears the flag');

echo ">>> 9. _view() hands the edit form its rows\n";

$viewDoc = makeDraft($prefix . '_view');
$_FILES = ['images' => fakeUpload(['v.pdf'], [realTmp('v')])];
Yii::$app->request->setBodyParams(payload($viewDoc->description, [
    ['accounting_data_id' => 110101, 'debit' => '3', 'credit' => '0', 'description' => 'a'],
    ['accounting_data_id' => 110102, 'debit' => '0', 'credit' => '3', 'description' => 'b'],
]));

Accounting::_update($viewDoc->id);

$view = Accounting::_view($viewDoc->id);
$viewRows = $view['data']['items'] ?? [];

ok(($view['success'] ?? false) === true, '_view() answers a draft');
ok(is_array($viewRows) && count($viewRows) === 2, 'the rows come back as arrays the form can hydrate');
ok(isset($viewRows[0]['accounting_data_id'], $viewRows[0]['debit'], $viewRows[0]['credit'], $viewRows[0]['id']), 'a row carries everything the form shows');
ok(($view['data']['status'] ?? null) === 'draft', 'the status the form needs comes back too');
ok(is_array(json_decode($view['data']['image'] ?? 'null', true)), 'the attachments come back for the upload field');

echo ">>> 10. the controller carries update\n";

// behaviors() reads no instance state, so no web module has to be wired up
$controller = (new ReflectionClass(AccountingController::class))->newInstanceWithoutConstructor();
$behaviors = $controller->behaviors();

ok(in_array('update', $behaviors['jwtAuth']['actions'], true), "AccountingController's jwtAuth list carries update");

// --- cleanup ----------------------------------------------------------------

purge($prefix);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
