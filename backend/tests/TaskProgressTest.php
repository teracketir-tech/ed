<?php

/**
 * ایشوی ۱۱-A: پیشرفتِ قبلیِ هر تسک در پروژه (TDD — RED).
 *
 * چرا لازم است: دیالوگِ فعالیت هنگام انتخاب تسک باید بداند این تسک در این
 * پروژه قبلاً چقدر پیش رفته تا نوارِ «درصد پیشرفت» از ادامه‌ی همان عدد بیاید
 * نه هر بار از صفر.
 *
 * پیش از پیاده‌سازی این تست شکست می‌خورد:
 *   - روت `GET {id}/task-progress` در routes.php نیست
 *   - `actionTaskProgress` در کنترلر و در فهرست JWT نیست
 *   - سرویس `TaskProgress` اصلاً وجود ندارد
 *
 * قاعده‌ی محاسبه باید **دقیقاً** همان عددی باشد که `CumulativeProgress::chain()`
 * پیش از نوشتنِ ردیفِ جاری می‌بیند (جمعِ `daily_progress` گزارش‌هایِ پیش از
 * این گزارش، به ترتیب زمان و شماره‌ی گزارش)؛ وگرنه نوارِ فرانت با عددِ
 * ذخیره‌شده‌ی سرور نمی‌خواند و خطای «بیش از ۱۰۰ درصد» غیرمنتظره می‌شود.
 * بخش ۳ همین برابری را برای هر دو حالت (تاریخِ متفاوت و هم‌تاریخ) اثبات می‌کند.
 *
 * دیتابیس فقط داخلِ یک تراکنش نوشته می‌شود و آخرِ کار rollBack.
 *
 * اجرا:  php frontend/modules/hq/tests/TaskProgressTest.php
 */

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\CumulativeProgress;

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

/** مقایسه‌ی امنِ اعداد اعشاری (SUM در MySQL رشته برمی‌گرداند) */
function near($actual, $expected, $label)
{
    ok(abs((float) $actual - (float) $expected) < 0.001, "$label (expected $expected, got $actual)");
}

/** ذخیره/حذفِ امن: خطای SQL هم شکست است، نه پایانِ تست */
function attempt($fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return 'exception:' . get_class($e);
    }
}

/** فراخوانی سرویس؛ تا وقتی ساخته نشده خروجیِ بی‌اثر برمی‌گرداند تا تست قرمز بماند */
function progressOf($reportId)
{
    $class = 'frontend\modules\hq\services\TaskProgress';

    if (!class_exists($class)) {
        return ['success' => false, 'data' => []];
    }

    return $class::forReport($reportId);
}

// ---------------------------------------------------------------------
// ۱) روت، اکشن و پوشش JWT
// ---------------------------------------------------------------------
echo ">>> روت و auth\n";

$routes = file_get_contents(dirname(__DIR__) . '/config/routes.php');
$controller = file_get_contents(dirname(__DIR__) . '/controllers/DailyReportController.php');

ok(strpos($routes, "'GET {id}/task-progress'") !== false, 'روت GET {id}/task-progress ثبت است');
ok(strpos($controller, "'task-progress',") !== false, "task-progress در jwtAuth.actions هست (بدون توکن نمی‌شود رفت)");
ok(strpos($controller, 'function actionTaskProgress') !== false, 'actionTaskProgress پیاده شده');
ok(class_exists('frontend\modules\hq\services\TaskProgress'), 'سرویس TaskProgress ساخته شده');

// ---------------------------------------------------------------------
// ۲) رفتار روی دیتابیس — همه‌چیز داخلِ تراکنش و بدون اثر دائمی
// ---------------------------------------------------------------------
echo ">>> رفتار دیتابیس (تراکنش، rollBack در پایان)\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21; // گروهی که 2411..2414 و 2422 را دارد
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

$r1 = new DailyReport(['project_id' => 146, 'report_date' => '1999-01-10', 'site_status' => 1]);
$r2 = new DailyReport(['project_id' => 146, 'report_date' => '1999-01-20', 'site_status' => 1]);
ok(attempt(fn () => $r1->save()) === true, 'فیکسچر: گزارش اول');
ok(attempt(fn () => $r2->save()) === true, 'فیکسچر: گزارش دوم');

foreach ([[$r1, 25, 30], [$r1, 26, 10], [$r2, 25, 20]] as [$report, $task, $progress]) {
    $activity = new DailyReportActivity([
        'daily_report_id' => $report->id,
        'task_id' => $task,
        'daily_progress' => $progress,
    ]);
    ok(attempt(fn () => $activity->save()) === true, "فیکسچر: تسک $task با پیشرفت $progress در گزارش {$report->id}");
}

$second = progressOf($r2->id);
is($second['success'] ?? null, true, 'خواندنِ پیشرفتِ قبلیِ گزارش دوم موفق است');
near($second['data'][25] ?? -1, 30, 'تسک ۲۵: فقط پیشرفتِ گزارش اول (۳۰)؛ ۲۰ همین گزارش حساب نمی‌شود');
near($second['data'][26] ?? -1, 10, 'تسک ۲۶: پیشرفتِ قبلی ۱۰');

$first = progressOf($r1->id);
is($first['success'] ?? null, true, 'خواندنِ پیشرفتِ قبلیِ گزارش اول هم موفق است');
is(count($first['data'] ?? []), 0, 'برای نخستین گزارشِ تسک، هیچ پیشرفتِ قبلی نیست (نوار از صفر شروع می‌شود)');

$missing = progressOf(999999999);
is($missing['success'] ?? null, false, 'گزارشِ ناموجود رد می‌شود');

// ---------------------------------------------------------------------
// ۳) برابری با base محاسبه‌ی تجمعی — نوارِ فرانت باید با ذخیره‌ی سرور بخواند
// ---------------------------------------------------------------------
echo ">>> برابری با CumulativeProgress::chain\n";

$chain = CumulativeProgress::chain(146, 25, $r2->id, '1999-01-20', null, 20.0);
near($chain['pending'], (float) ($second['data'][25] ?? -1) + 20, 'تجمعیِ ذخیره‌شده = نوارِ نمایش‌داده‌شده + پیشرفتِ این گزارش');

// گزارشِ هم‌تاریخ با شماره‌ی کوچک‌تر، قبل از ردیفِ جاری می‌نشیند؛ پس باید
// در «قبلی» بیاید تا برابری بالا برای هر دو حالت برقرار باشد.
$r3 = new DailyReport(['project_id' => 146, 'report_date' => '1999-01-20', 'site_status' => 1]);
ok(attempt(fn () => $r3->save()) === true, 'فیکسچر: گزارشِ هم‌تاریخ');
$activity3 = new DailyReportActivity([
    'daily_report_id' => $r3->id,
    'task_id' => 25,
    'daily_progress' => 5,
]);
ok(attempt(fn () => $activity3->save()) === true, 'فیکسچر: فعالیتِ هم‌تاریخ');

$sameDay = progressOf($r3->id);
is($sameDay['success'] ?? null, true, 'خواندنِ پیشرفتِ قبلیِ گزارشِ هم‌تاریخ موفق است');
near($sameDay['data'][25] ?? -1, 50, 'هم‌تاریخِ کوچک‌تر (۲۰) و تاریخِ قدیمی‌تر (۳۰) هر دو جزو «قبلی»‌اند');
near($sameDay['data'][26] ?? -1, 10, 'تسکِ بدون ردیفِ هم‌تاریخ دست نمی‌خورد');

$chain3 = CumulativeProgress::chain(146, 25, $r3->id, '1999-01-20', null, 5.0);
near($chain3['pending'], (float) ($sameDay['data'][25] ?? -1) + 5, 'برابری برای گزارشِ هم‌تاریخ هم برقرار است');

$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n>>> $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
