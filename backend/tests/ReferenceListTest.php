<?php

/**
 * ایشوی ۱۲: دو CRUD ساده برای لیست‌های مرجعِ گزارش روزانه (TDD — RED).
 * ایشوی ۱۵: ستون‌های company_id / group_id / منشاء انحراف / اثر غالب و
 * فیلترِ همه‌ی ستون‌ها در هر دو فهرست (بخش ۲-۴).
 * ایشوی ۱۶: «گروه» دیگر به جدول group وصل نیست و دو مقدارِ عددیِ داخلی/خارجی
 * دارد، منشاء انحراف به گروهِ خودش وصل است، اثر غالب در جدول رابطه ذخیره
 * می‌شود و همه‌ی فیلدها به‌جز شرکت الزامی‌اند (بخش ۲-۵).
 *
 * چرا لازم است: «نوع ماشین‌آلات» و «دلیل انحراف» جدول‌های مرجعی‌اند که تاکنون
 * فقط با SQL پر می‌شدند. کاربر باید از منوی «پروژه» — درست مثل «کالاها» —
 * آن‌ها را بسازد، ویرایش کند و حذف کند؛ همان فهرست هم واژه‌نامه‌ی فرمِ
 * گزارش روزانه را می‌پسازد (بنابراین جدولِ تازه لازم نیست).
 *
 * پیش از پیاده‌سازی این تست شکست می‌خورد:
 *   - سرویس `ReferenceListService` اصلاً وجود ندارد
 *   - دو کنترلر و دو روت ثبت نشده‌اند
 *   - `deviation_reasons` کلید واژه‌نامه نیست
 *   - حذفِ موردِ استفاده‌شده باید مودبانه رد شود نه با خطای ۵۰۰ (FK)
 *
 * دیتابیس فقط داخلِ یک تراکنش نوشته می‌شود و آخرِ کار rollBack.
 *
 * اجرا:  php frontend/modules/hq/tests/ReferenceListTest.php
 */

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportDeviationReason;
use frontend\modules\hq\models\DailyReportEquipment;
use frontend\modules\hq\models\DailyReportEquipmentType;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\DictionaryService;
use frontend\modules\hq\services\LineService;
use frontend\modules\hq\services\ReferenceListService;

$backend = dirname(__DIR__, 4);

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

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $failures, $checks;
    $checks++;
    echo ($condition ? '  ok   - ' : '  FAIL - ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function is($actual, $expected, $label)
{
    ok($actual === $expected, "$label (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** اجرای کدی که انتظار می‌رود استثنا بیندازد؛ خطا به‌جای توقفِ تست برگردانده می‌شود */
function attempt(callable $fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return $e;
    }
}

/** جایگاهِ ردیف در لیستِ خروجی — مقایسه loose چون PDO ممکن است عدد را رشته بدهد */
function positionOf(array $rows, $id): int
{
    foreach ($rows as $i => $row) {
        if ((int) ($row['id'] ?? 0) === (int) $id) {
            return $i;
        }
    }

    return -1;
}

// =====================================================================
// ۱) ساختار: روت، کنترلر، سرویس و واژه‌نامه
// =====================================================================
echo ">>> ساختار (روت / کنترلر / سرویس / واژه‌نامه)\n";

$routes = file_get_contents(dirname(__DIR__) . '/config/routes.php');
ok(strpos($routes, "Route::crud('hq/daily-report-equipment-type')") !== false, 'روتِ نوع ماشین‌آلات ثبت است');
ok(strpos($routes, "Route::crud('hq/daily-report-deviation-reason')") !== false, 'روتِ دلیل انحراف ثبت است');

$controllersDir = dirname(__DIR__) . '/controllers';
$namespace = 'frontend\modules\hq\controllers\\';

$baseClass = $namespace . 'DailyReportReferenceController';
ok(class_exists($baseClass), 'پایه‌ی انتزاعیِ لیست‌های مرجع وجود دارد');

if (class_exists($baseClass)) {
    $base = new ReflectionClass($baseClass);
    ok($base->isAbstract(), 'پایه‌ی انتزاعیِ لیست‌های مرجع انتزاعی است');
    ok(is_subclass_of($baseClass, 'yii\rest\ActiveController'), 'پایه از ActiveController ارث می‌برد');
}

$specs = [
    'DailyReportEquipmentTypeController' => DailyReportEquipmentType::class,
    'DailyReportDeviationReasonController' => DailyReportDeviationReason::class,
];

foreach ($specs as $name => $modelClass) {
    $file = $controllersDir . '/' . $name . '.php';
    ok(file_exists($file), "$name وجود دارد");

    if (!class_exists($namespace . $name)) {
        continue;
    }

    $rc = new ReflectionClass($namespace . $name);
    ok(!$rc->isAbstract(), "$name انتزاعی نیست (روت دارد)");

    $controller = $rc->newInstance('daily-report', new yii\base\Module('hq', null));
    is($controller->modelClass, $modelClass, "$name مدل درست را می‌سازد");

    $behaviors = $controller->behaviors();
    ok(isset($behaviors['jwtAuth']), "$name رفتار jwtAuth دارد");
    ok(strpos($behaviors['jwtAuth']['class'] ?? '', 'JwtAuthBehavior') !== false, "$name از JwtAuthBehavior استفاده می‌کند");

    $jwt = $behaviors['jwtAuth']['actions'] ?? [];
    sort($jwt);
    is($jwt, ['create', 'delete', 'index', 'update', 'view'], "$name هر پنج اکشن پشتِ JWT است");

    $config = $controller->actions();
    foreach (['index', 'create', 'update', 'delete'] as $id) {
        ok(!isset($config[$id]), "$name اکشنِ REST پیش‌فرضِ '$id' را نگه نداشته (منطق در سرویس است)");
    }

    foreach (['actionIndex', 'actionView', 'actionCreate', 'actionUpdate', 'actionDelete'] as $method) {
        ok($rc->hasMethod($method), "$name::$method تعریف شده");
    }
}

$serviceExists = class_exists(ReferenceListService::class);
ok($serviceExists, 'سرویس ReferenceListService وجود دارد');

foreach (['index', 'view', 'save', 'delete'] as $method) {
    ok($serviceExists && method_exists(ReferenceListService::class, $method), "ReferenceListService::$method وجود دارد");
}

ok(in_array('deviation_reasons', DictionaryService::KEYS, true), 'کلید deviation_reasons به واژه‌نامه اضافه شده');
is(DictionaryService::SOURCES['deviation_reasons'] ?? null, DailyReportDeviationReason::class, 'منبع deviation_reasons همان مدل دلایل انحراف است');

// سه سلکتِ عددیِ فرم عوامل انحراف (ایشوی ۱۶) باید در همان واژه‌نامه باشند
foreach (['factor_groups', 'deviation_sources', 'effects'] as $key) {
    ok(in_array($key, DictionaryService::KEYS, true), "کلید $key به واژه‌نامه اضافه شده");
}

// =====================================================================
// ۲) رفتار روی دیتابیس — تراکنش، rollBack در پایان
// =====================================================================
echo ">>> رفتار دیتابیس (تراکنش، rollBack در پایان)\n";

// بدون سرویس، ادامه فقط خطای فatal می‌سازد؛ همین‌جا قرمز بودن کافی است
if (!$serviceExists) {
    echo "\n$checks checks, $failures failures\n";
    exit(1);
}

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21; // گروهی که 2411..2414 و 2422 را دارد
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

// ---- ۲-۱) دلیل انحراف: ساخت / خواندن / فهرست / ویرایش / حذف
/**
 * بدنه‌ی کاملِ یک دلیل انحراف — به‌جز شرکت همه‌ی فیلدها الزامی‌اند (ایشوی ۱۶):
 * گروهِ داخلی، منشاءِ همان گروه و دو اثر غالب.
 */
$validReason = ['factor_group_id' => 1, 'deviation_source' => 1, 'effect_ids' => ['1', '3']];

Yii::$app->request->setBodyParams(['name' => 'تاخیر تحویل مصالح', 'sort_order' => 5] + $validReason);
$created = ReferenceListService::save(DailyReportDeviationReason::class);
ok(($created['success'] ?? false) === true, 'دلیل انحراف ساخته می‌شود');
$lateId = (int) ($created['data']->id ?? 0);
ok($lateId > 0, 'شناسه‌ی دلیل انحراف تعیین شد');

$view = ReferenceListService::view(DailyReportDeviationReason::class, $lateId);
ok(($view['success'] ?? false) === true, 'خواندنِ یک دلیل انحراف موفق است');
is($view['data']['name'] ?? null, 'تاخیر تحویل مصالح', 'نامِ خوانده‌شده همان چیزی است که نوشته شد');

Yii::$app->request->setBodyParams(['name' => 'بی‌کیفیتی مصالح', 'sort_order' => 1] + $validReason);
$early = ReferenceListService::save(DailyReportDeviationReason::class);
$earlyId = (int) ($early['data']->id ?? 0);
ok($earlyId > 0, 'دلیل انحرافِ دوم ساخته می‌شود');

Yii::$app->request->setQueryParams(['per-page' => 0]);
$index = ReferenceListService::index(DailyReportDeviationReason::class);
ok(($index['success'] ?? false) === true, 'فهرست دلایل انحراف جواب می‌دهد');
$rows = $index['data'] ?? [];
is((int) ($index['totalCount'] ?? -1), count($rows), 'totalCount با ردیف‌های برگشتی می‌خواند');
ok(positionOf($rows, $lateId) >= 0, 'ردیفِ تازه در فهرست هست');
ok(
    positionOf($rows, $earlyId) >= 0 && positionOf($rows, $earlyId) < positionOf($rows, $lateId),
    'ترتیبِ فهرست از روی sort_order است (۱ پیش از ۵)'
);

// ---- عنوان و مقدارِ سلکت‌ها در خودِ پاسخِ فهرست (الگوی `Order::get_all`)
$formData = $index['form_data'] ?? null;
ok(is_array($formData), 'پاسخِ فهرست کلید form_data دارد (عنوان و مقدار مثل order)');
$groupOptions = $formData['factor_group'] ?? [];
is(count($groupOptions), 2, 'گروه دقیقاً دو گزینه دارد');
is($groupOptions[0]['label'] ?? null, 'عوامل داخلی', 'برچسبِ نخستین گروه «عوامل داخلی» است');
is($groupOptions[0]['value'] ?? null, 1, 'مقدارِ نخستین گروه عدد ۱ است');
$sourceOptions = $formData['deviation_source'] ?? [];
is(count($sourceOptions), 20, 'بیست منشاء انحراف در form_data هست');
is($sourceOptions[0]['factor_group'] ?? null, 1, 'منشاءِ نخست به گروهِ داخلی وصل است');
is($sourceOptions[19]['factor_group'] ?? null, 2, 'منشاءِ بیستم به گروهِ خارجی وصل است');
$effectOptions = $formData['effect'] ?? [];
is(count($effectOptions), 3, 'سه اثر غالب در form_data هست');
is($effectOptions[0]['label'] ?? null, 'زمان', 'نخستین اثر غالب «زمان» است');

// نامِ خالی باید رد شود نه بی‌صدا ذخیره
Yii::$app->request->setBodyParams(['name' => ''] + $validReason);
$bad = ReferenceListService::save(DailyReportDeviationReason::class);
is($bad['success'] ?? null, false, 'دلیلِ بدون نام رد می‌شود');
ok(isset($bad['errors']['name']), 'خطای فیلد name برگردانده می‌شود');

// ---- الزامی بودنِ همه‌ی فیلدها به‌جز شرکت (ایشوی ۱۶)
Yii::$app->request->setBodyParams(['name' => 'فقط با نام']);
$missing = ReferenceListService::save(DailyReportDeviationReason::class);
is($missing['success'] ?? null, false, 'دلیلِ بدون گروه / منشاء / اثر غالب رد می‌شود');
ok(isset($missing['errors']['factor_group_id']), 'خطای فیلدِ گروه برمی‌گردد');
ok(isset($missing['errors']['deviation_source']), 'خطای فیلدِ منشاء برمی‌گردد');
ok(isset($missing['errors']['effect_ids']), 'خطای فیلدِ اثر غالب برمی‌گردد');

// منشاء باید به همان گروه تعلق داشته باشد (داخلی ۱..۱۳ / خارجی ۱۴..۲۰)
Yii::$app->request->setBodyParams(['name' => 'منشاءِ بیگانه', 'factor_group_id' => 1, 'deviation_source' => 14, 'effect_ids' => ['1']]);
$mismatch = ReferenceListService::save(DailyReportDeviationReason::class);
is($mismatch['success'] ?? null, false, 'منشاءِ خارجی زیرِ گروهِ داخلی ذخیره نمی‌شود');
ok(isset($mismatch['errors']['deviation_source']), 'خطای تطابقِ منشاء و گروه برمی‌گردد');

// اثر غالب فقط سه مقدارِ ۱..۳ را می‌پذیرد
Yii::$app->request->setBodyParams(['name' => 'اثرِ ناشناخته', 'factor_group_id' => 2, 'deviation_source' => 20, 'effect_ids' => ['9']]);
$badEffect = ReferenceListService::save(DailyReportDeviationReason::class);
is($badEffect['success'] ?? null, false, 'اثر غالبِ خارج از سه مقدار رد می‌شود');
ok(isset($badEffect['errors']['effect_ids']), 'خطای اثرِ ناشناخته برمی‌گردد');

// دامنه‌ی منشاء باید ۱ تا ۲۰ باشد، نه فقط دو سرش (`range => [1, 20]` یعنی فقط ۱ و ۲۰)
Yii::$app->request->setBodyParams(['name' => 'عامل خارجی', 'factor_group_id' => 2, 'deviation_source' => 15, 'effect_ids' => ['2']]);
$external = ReferenceListService::save(DailyReportDeviationReason::class);
is($external['success'] ?? null, true, 'گروهِ خارجی با منشاءِ ۱۵ پذیرفته می‌شود');
$externalId = (int) ($external['data']->id ?? 0);
ok($externalId > 0, 'دلیلِ خارجی شناسه گرفت');

// منشاءِ خارج از دامنه رد می‌شود نه بی‌صدا ذخیره
Yii::$app->request->setBodyParams(['name' => 'منشاءِ بیرون از دامنه', 'factor_group_id' => 2, 'deviation_source' => 99, 'effect_ids' => ['1']]);
$tooBig = ReferenceListService::save(DailyReportDeviationReason::class);
is($tooBig['success'] ?? null, false, 'منشاءِ ۹۹ خارج از دامنه ذخیره نمی‌شود');
ok(isset($tooBig['errors']['deviation_source']), 'خطای دامنه‌ی منشاء برمی‌گردد');

// گروه فقط دو مقدارِ داخلی/خارجی دارد
Yii::$app->request->setBodyParams(['name' => 'گروهِ نامعتبر', 'factor_group_id' => 5, 'deviation_source' => 1, 'effect_ids' => ['1']]);
$badGroup = ReferenceListService::save(DailyReportDeviationReason::class);
is($badGroup['success'] ?? null, false, 'گروهِ خارج از دو مقدار رد می‌شود');
ok(isset($badGroup['errors']['factor_group_id']), 'خطای دامنه‌ی گروه برمی‌گردد');
ReferenceListService::delete(DailyReportDeviationReason::class, $externalId);

// ویرایش
Yii::$app->request->setBodyParams(['name' => 'علت تاخیر پیمانکار', 'sort_order' => 7] + $validReason);
$upd = ReferenceListService::save(DailyReportDeviationReason::class, $lateId);
ok(($upd['success'] ?? false) === true, 'ویرایش دلیل انحراف موفق است');
$view2 = ReferenceListService::view(DailyReportDeviationReason::class, $lateId);
is($view2['data']['name'] ?? null, 'علت تاخیر پیمانکار', 'نام پس از ویرایش عوض شد');

// شناسه‌ی ناموجود در خواندن و ویرایش
Yii::$app->request->setBodyParams([]);
is(
    ReferenceListService::view(DailyReportDeviationReason::class, 999999999)['success'] ?? null,
    false,
    'خواندنِ شناسه‌ی ناموجود شکست می‌خورد'
);
Yii::$app->request->setBodyParams(['name' => 'x']);
is(
    ReferenceListService::save(DailyReportDeviationReason::class, 999999999)['success'] ?? null,
    false,
    'ویرایشِ شناسه‌ی ناموجود شکست می‌خورد'
);

// حذفِ ردیفِ آزاد
$del = ReferenceListService::delete(DailyReportDeviationReason::class, $earlyId);
ok(($del['success'] ?? false) === true, 'حذفِ دلیلِ استفاده‌نشده موفق است');
is(
    ReferenceListService::view(DailyReportDeviationReason::class, $earlyId)['success'] ?? null,
    false,
    'پس از حذف، ردیف دیده نمی‌شود'
);
is(
    (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM daily_report_deviation_reason_effect WHERE reason_id = :id', [':id' => $earlyId])->queryScalar(),
    0,
    'جدولِ رابطه‌ی اثر غالب هم با حذفِ ردیف خالی می‌شود'
);

// ---- ۲-۲) نوع ماشین‌آلات: ساخت و حذفِ موردِ استفاده‌شده
Yii::$app->request->setBodyParams(['name' => 'تجهیز آزمایشی', 'sort_order' => 99]);
$type = ReferenceListService::save(DailyReportEquipmentType::class);
ok(($type['success'] ?? false) === true, 'نوع ماشین‌آلات ساخته می‌شود');
$typeId = (int) ($type['data']->id ?? 0);
ok($typeId > 0, 'شناسه‌ی نوع ماشین‌آلات تعیین شد');

// جستجوی نام (فیلتری که صفحه‌ی لیست استفاده می‌کند) — پیش از هر حذفی
Yii::$app->request->setQueryParams(['name' => 'تجهیز آزمایشی', 'per-page' => 0]);
$found = ReferenceListService::index(DailyReportEquipmentType::class);
is((int) ($found['totalCount'] ?? -1), 1, 'جستجوی نام فقط ردیفِ منطبق را می‌آورد');
Yii::$app->request->setQueryParams([]);

$report = new DailyReport(['project_id' => 146, 'report_date' => '1999-01-11', 'site_status' => 1]);
ok(attempt(fn () => $report->save()) === true, 'فیکسچر: ساخت گزارش');

$activity = new DailyReportActivity(['daily_report_id' => $report->id, 'task_id' => 26, 'daily_progress' => 0]);
ok(attempt(fn () => $activity->save()) === true, 'فیکسچر: ساخت فعالیت');

Yii::$app->request->setBodyParams(['equipment_type_id' => $typeId, 'active_count' => 1, 'inactive_count' => 0]);
$equipment = LineService::save(DailyReportEquipment::class, null, $activity->id);
ok(($equipment['success'] ?? false) === true, 'فیکسچر: ردیفِ تجهیز با این نوع ساخته شد');

$blocked = ReferenceListService::delete(DailyReportEquipmentType::class, $typeId);
is($blocked['success'] ?? null, false, 'حذفِ نوعِ در حال استفاده رد می‌شود (نه خطای ۵۰۰)');
ok(!empty($blocked['message']), 'پیامِ روشنِ «در حال استفاده» برمی‌گردد');
is(
    ReferenceListService::view(DailyReportEquipmentType::class, $typeId)['success'] ?? null,
    true,
    'پس از حذفِ ردشده، ردیف هنوز سر جایش است'
);

// آزاد شدنِ مرجع ⇒ حذف مجاز
attempt(fn () => DailyReportEquipment::findOne($equipment['data']->id)->delete());
$freed = ReferenceListService::delete(DailyReportEquipmentType::class, $typeId);
ok(($freed['success'] ?? false) === true, 'پس از آزاد شدنِ مرجع، حذف موفق است');

// ---- ۲-۳) صفحه‌بندیِ فهرستِ نوع ماشین‌آلات (جدولِ ۱۱ ردیفیِ seed شده)
Yii::$app->request->setQueryParams(['per-page' => 4, 'page' => 2]);
$pages = ReferenceListService::index(DailyReportEquipmentType::class);
is((int) ($pages['pages'] ?? 0), 3, 'شمار صفحه‌ها از تعداد ردیف‌ها می‌آید');
is(count($pages['data'] ?? []), 4, 'هر صفحه همان تعداد ردیف دارد');

// ---- ۲-۴) ستون‌ها و فیلترهای تازه (ایشوی ۱۵: فرمِ کامل و بلاکِ فیلتر)
echo ">>> ستون‌ها و فیلترهای تازه\n";

/** فهرستِ کامل با یک پارامتر فیلتر — همان چیزی که صفحه به سرور می‌فرستد */
function filtered(string $modelClass, array $params = []): array
{
    Yii::$app->request->setQueryParams(['per-page' => 0] + $params);

    return ReferenceListService::index($modelClass)['data'] ?? [];
}

/** ردیفِ مشخص از فهرست — null یعنی در نتیجه نیست */
function rowById(array $rows, $id): ?array
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === (int) $id) {
            return $row;
        }
    }

    return null;
}

$companyId = (int) Yii::$app->db->createCommand('SELECT id FROM company ORDER BY id ASC')->queryScalar();
$otherCompanyId = (int) Yii::$app->db->createCommand('SELECT id FROM company WHERE id <> ' . $companyId . ' ORDER BY id ASC')->queryScalar();
$companyName = (string) Yii::$app->db->createCommand('SELECT name FROM company WHERE id = ' . $companyId)->queryScalar();

// ساخت با هر پنج فیلدِ تازه — گروه دیگر شناسه‌ی جدول group نیست (ایشوی ۱۶)
Yii::$app->request->setBodyParams([
    'name' => 'تأخیر در تحویل زمین',
    'company_id' => $companyId,
    'factor_group_id' => 1,
    'deviation_source' => 1,
    'effect_ids' => ['1', '3'],
]);
$rich = ReferenceListService::save(DailyReportDeviationReason::class);
ok(($rich['success'] ?? false) === true, 'دلیل انحراف با ستون‌های تازه ساخته می‌شود');
$richId = (int) ($rich['data']->id ?? 0);
ok($richId > 0, 'شناسه‌ی ردیفِ تازه تعیین شد');

$readBack = ReferenceListService::view(DailyReportDeviationReason::class, $richId);
is((int) ($readBack['data']['company_id'] ?? 0), $companyId, 'company_id از ورودی می‌آید');
is((int) ($readBack['data']['factor_group_id'] ?? 0), 1, 'گروهِ عددیِ داخلی ذخیره و برمی‌گردد');
is((int) ($readBack['data']['deviation_source'] ?? 0), 1, 'منشاء انحراف ذخیره می‌شود');
is($readBack['data']['effect_ids'] ?? null, [1, 3], 'اثر غالب از جدولِ رابطه به‌صورت آرایه‌ی عددی خوانده می‌شود');
is(
    (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM daily_report_deviation_reason_effect WHERE reason_id = :id', [':id' => $richId])->queryScalar(),
    2,
    'دو ردیف در جدولِ رابطه‌ی اثر غالب نوشته شده است'
);

// ---- فیلترهای لیستِ عوامل انحراف
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['deviation_source' => 1]), $richId) !== null,
    'فیلترِ منشاء انحراف ردیفِ منطبق را می‌آورد'
);
is(
    rowById(filtered(DailyReportDeviationReason::class, ['deviation_source' => 14]), $richId),
    null,
    'منشاءِ خارجی (۱۴) ردیفِ داخلی را نمی‌آورد'
);
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['factor_group_id' => 1]), $richId) !== null,
    'فیلترِ گروهِ داخلی ردیفِ منطبق را می‌آورد'
);
is(
    rowById(filtered(DailyReportDeviationReason::class, ['factor_group_id' => 2]), $richId),
    null,
    'گروهِ خارجی ردیفِ داخلی را کنار می‌گذارد'
);
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['effect_ids' => '3']), $richId) !== null,
    'فیلترِ اثر غالب ردیفِ دارای آن اثر را می‌آورد'
);
is(
    rowById(filtered(DailyReportDeviationReason::class, ['effect_ids' => '2']), $richId),
    null,
    'اثرِ ناموجود ردیف را نمی‌آورد'
);
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['effect_ids' => '1,3']), $richId) !== null,
    'فیلترِ چندمقداریِ اثر غالب («1,3» از سلکت مالتی) جواب می‌دهد'
);
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['company_id' => $companyId]), $richId) !== null,
    'فیلترِ شرکت ردیفِ همان شرکت را می‌آورد'
);
is(
    rowById(filtered(DailyReportDeviationReason::class, ['company_id' => $otherCompanyId]), $richId),
    null,
    'شرکتِ دیگر، ردیفِ این شرکت را کنار می‌گذارد'
);
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['company_id' => $otherCompanyId]), $lateId) !== null,
    'ردیفِ بدون شرکت (دانه‌ی اکسل) برای همه می‌ماند'
);
$byId = filtered(DailyReportDeviationReason::class, ['id' => $richId]);
is(count($byId), 1, 'فیلترِ شناسه دقیق است');
ok(
    rowById(filtered(DailyReportDeviationReason::class, ['name' => 'تحویل زمین']), $richId) !== null,
    'فیلترِ نام، ردیفِ منطبق را می‌آورد'
);

// ---- ستون‌های نمایشی: نامِ شرکت از join می‌آید؛ گروه و اثر غالب عددی‌اند
$richRow = rowById($byId, $richId);
is($richRow['company_name'] ?? null, $companyName, 'نامِ شرکت در خروجی فهرست هست');
is(array_key_exists('group_name', $richRow), false, 'گروه دیگر به جدول group وصل نیست، پس group_name ندارد');
is((int) ($richRow['factor_group_id'] ?? 0), 1, 'گروه به‌صورت عدد در ردیفِ فهرست هست');
is($richRow['effect_ids'] ?? null, [1, 3], 'اثر غالب در فهرست به‌صورت آرایه‌ی عددی هست');

// ---- فیلترهای لیستِ ماشین‌آلات (شناسه / نام / شرکت)
Yii::$app->request->setBodyParams(['name' => 'تست تجهیز منحصر', 'company_id' => $companyId]);
$eq = ReferenceListService::save(DailyReportEquipmentType::class);
ok(($eq['success'] ?? false) === true, 'نوع ماشین‌آلات با company_id ساخته می‌شود');
$eqId = (int) ($eq['data']->id ?? 0);

$eqRows = filtered(DailyReportEquipmentType::class, ['company_id' => $companyId]);
ok(rowById($eqRows, $eqId) !== null, 'فیلترِ شرکت ردیفِ همان شرکت را می‌آورد');
is(rowById($eqRows, $eqId)['company_name'] ?? null, $companyName, 'نامِ شرکت در فهرستِ ماشین‌آلات هست');

$eqOther = filtered(DailyReportEquipmentType::class, ['company_id' => $otherCompanyId]);
is(rowById($eqOther, $eqId), null, 'شرکتِ دیگر، ردیفِ تجهیز را کنار می‌گذارد');
ok(count($eqOther) > 0, 'دانه‌هایِ بدون شرکت برای همه می‌مانند');

is(count(filtered(DailyReportEquipmentType::class, ['id' => $eqId])), 1, 'فیلترِ شناسه دقیق است');
is(count(filtered(DailyReportEquipmentType::class, ['name' => 'منحصر'])), 1, 'فیلترِ نام ردیفِ منطبق را می‌آورد');

// ماشین‌آلات سلکتِ عددیِ مخصوصِ خودش را ندارد، پس form_data نمی‌فرستد
is(
    array_key_exists('form_data', ReferenceListService::index(DailyReportEquipmentType::class)),
    false,
    'فهرستِ ماشین‌آلات کلید form_data ندارد'
);

Yii::$app->request->setQueryParams([]);

// =====================================================================
// ۳) نقش: گروهِ بدون نقش باید استثنا بگیرد
// =====================================================================
echo ">>> نقش‌ها\n";

$identity->group_id = 15; // گروهِ خالی از نقش
Yii::$app->request->setQueryParams([]);
Yii::$app->request->setBodyParams(['name' => 'x']);

ok(
    attempt(fn () => ReferenceListService::index(DailyReportEquipmentType::class)) instanceof yii\web\UnauthorizedHttpException,
    'فهرست بدون نقش استثنا می‌اندازد'
);
ok(
    attempt(fn () => ReferenceListService::view(DailyReportDeviationReason::class, $lateId)) instanceof yii\web\UnauthorizedHttpException,
    'خواندن بدون نقش استثنا می‌اندازد'
);
ok(
    attempt(fn () => ReferenceListService::save(DailyReportDeviationReason::class)) instanceof yii\web\UnauthorizedHttpException,
    'افزودن بدون نقش استثنا می‌اندازد'
);
ok(
    attempt(fn () => ReferenceListService::delete(DailyReportDeviationReason::class, $lateId)) instanceof yii\web\UnauthorizedHttpException,
    'حذف بدون نقش استثنا می‌اندازد'
);

$identity->group_id = 21;

$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
