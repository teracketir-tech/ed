<?php

/**
 * ایشوی ۱۰-B: هر ردیفِ زیرمجموعه باید متعلق به یک فعالیت باشد (TDD — RED)،
 * به جز «تحویل کالا» و «تایم‌شیت» که ایشوی ۴ آن‌ها را مالِ خودِ گزارش کرد.
 *
 * پیش از پیاده‌سازی، این تست شکست می‌خورد:
 *   - `daily_report_activity_id` در rules هیچ‌کدام از پنج مدل نیست
 *   - `LineService::save` آرگومان سوم را «شناسه‌ی گزارش» می‌خواند
 *   - فیلترِ لیست فقط daily_report_id/task_id را می‌شناسد
 *   - روت‌ها هنوز `POST {id}/{line}` هستند نه `POST activity/<activityId>/{line}`
 *
 * دیتابیس فقط داخلِ یک تراکنش نوشته می‌شود و آخرِ کار rollBack؛ اگر وسطِ کار
 * استثنا بدهد، بسته‌شدن اتصال rollback خودکار MySQL را ممکن می‌کند.
 *
 * ⚠ بخشِ ۲-ب به `sql/daily-report-report-scoped-lines.sql` روی همین دیتابیس
 *   نیاز دارد (ستونِ NULLپذیر + FK با ON DELETE SET NULL).
 *
 * اجرا:  php frontend/modules/hq/tests/ActivityLineTest.php
 */

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportDeliveries;
use frontend\modules\hq\models\DailyReportManpower;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\LineService;

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

/** ذخیره/حذفِ امن: خطای SQL هم شکست است، نه پایانِ تست */
function attempt($fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return 'exception:' . get_class($e);
    }
}

// ---------------------------------------------------------------------
// ۱) روت‌ها: سه زیرمجموعه زیرِ فعالیت، دو زیرمجموعه زیرِ گزارش (ایشوی ۴)
// ---------------------------------------------------------------------
echo ">>> روت‌ها و لیست مجوزها\n";

$routes = file_get_contents(dirname(__DIR__) . '/config/routes.php');
$controller = file_get_contents(dirname(__DIR__) . '/controllers/DailyReportController.php');

foreach (array_keys(LineService::MODELS) as $line) {
    // `<>` سینتکسِ بومیِ UrlRule است؛ `{name}` فقط برای توکنِ ازپیش‌تعریف‌شده‌ی `{id}` باز می‌شود
    if (LineService::isReportScoped($line)) {
        // ایشوی ۴: تحویل کالا و تایم‌شیت یک‌بار برای کلِ گزارش ثبت می‌شوند
        ok(strpos($routes, "'POST {id}/$line'") !== false, "روت گزارش‌محور POST {id}/$line ثبت است");
        ok(strpos($routes, "'POST activity/<activityId>/$line'") === false, "روت فعالیت‌محور POST activity/<activityId>/$line برداشته شده");
    } else {
        ok(strpos($routes, "'POST activity/<activityId>/$line'") !== false, "روت POST activity/<activityId>/$line ثبت است");
        ok(strpos($routes, "'POST {id}/$line'") === false, "روت قدیمی POST {id}/$line حذف شده");
    }

    ok(strpos($controller, "'create-$line',") !== false, "اکشن create-$line در jwtAuth.actions هست");
}

// این دو روت گزارش‌محورند و نباید دست بخورند (قرارداد ایشوی ۵ و ۲)
ok(strpos($routes, "'POST {id}/activities'") !== false, 'روت POST {id}/activities (گزارش‌محور) باقی مانده');
ok(strpos($routes, "'POST {id}/submit'") !== false, 'روت POST {id}/submit باقی مانده');

// ---------------------------------------------------------------------
// ۲) قواعد پنج مدل: مالکِ سه زیرمجموعه فعالیت و مالکِ دو زیرمجموعه گزارش است
// ---------------------------------------------------------------------
echo ">>> قواعد پنج مدل زیرمجموعه\n";

foreach (LineService::MODELS as $line => $modelClass) {
    $activityValidators = [];
    $reportValidators = [];

    foreach ((new $modelClass)->rules() as $rule) {
        $attributes = (array) $rule[0];

        if (in_array('daily_report_activity_id', $attributes, true)) {
            $activityValidators[] = $rule[1];
        }

        if (in_array('daily_report_id', $attributes, true)) {
            $reportValidators[] = $rule[1];
        }
    }

    if (LineService::isReportScoped($line)) {
        // ایشوی ۴: گزارش مالک است؛ فعالیت نه الزامی است و نه راستی‌آزمایی دارد
        ok(!in_array('required', $activityValidators, true), "$line: daily_report_activity_id الزامی نیست");
        ok(in_array('required', $reportValidators, true), "$line: daily_report_id الزامی است");
        ok(
            count(array_filter($reportValidators, fn ($v) => strpos((string) $v, 'validate') === 0)) > 0,
            "$line: راستی‌آزمایِ گزارش دارد"
        );
        continue;
    }

    ok(in_array('required', $activityValidators, true), "$line: daily_report_activity_id الزامی است");
    ok(count(array_filter($activityValidators, fn ($v) => strpos((string) $v, 'validate') === 0)) > 0, "$line: راستی‌آزمایِ فعالیت دارد");
}

// ---------------------------------------------------------------------
// ۲-ب) ایشوی ۴: ستونِ تحویل باید NULL بپذیرد و FK اش «حذف فعالیت» را به
//       حذفِ ردیف بدل نکند. بدون `sql/daily-report-report-scoped-lines.sql`
//       این دو تست شکست می‌خورند.
// ---------------------------------------------------------------------
$nullable = Yii::$app->db->createCommand(
    "SELECT is_nullable FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'daily_report_deliveries'
        AND column_name = 'daily_report_activity_id'"
)->queryScalar();
is($nullable, 'YES', 'daily_report_deliveries.daily_report_activity_id حالا NULL می‌پذیرد');

$deleteRule = Yii::$app->db->createCommand(
    "SELECT delete_rule FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_dde_activity'"
)->queryScalar();
is($deleteRule, 'SET NULL', 'حذفِ فعالیت دیگر ردیفِ تحویل را پاک نمی‌کند');

// ---------------------------------------------------------------------
// ۳) رفتار روی دیتابیس — همه‌چیز داخلِ تراکنش و بدون اثر دائمی
// ---------------------------------------------------------------------
echo ">>> رفتار دیتابیس (تراکنش، rollBack در پایان)\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21; // گروهی که 2411..2414 و 2422 را دارد
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

$report = new DailyReport(['project_id' => 146, 'report_date' => '1999-01-10', 'site_status' => 1]);
ok(attempt(fn () => $report->save()) === true, 'فیکسچر: ساخت گزارش');

$activityA = new DailyReportActivity(['daily_report_id' => $report->id, 'task_id' => 25, 'daily_progress' => 10]);
$activityB = new DailyReportActivity(['daily_report_id' => $report->id, 'task_id' => 26, 'daily_progress' => 20]);
ok(attempt(fn () => $activityA->save()) === true, 'فیکسچر: ساخت فعالیت A');
ok(attempt(fn () => $activityB->save()) === true, 'فیکسچر: ساخت فعالیت B');

// ۳-۱) فقط شناسه‌ی فعالیت می‌دهیم؛ گزارش و شرکت باید از خودِ فعالیت بیایند
$manA = new DailyReportManpower(['daily_report_activity_id' => $activityA->id, 'position_type' => 1, 'quantity' => 2]);
ok(attempt(fn () => $manA->save()) === true, 'نیرو فقط با شناسه‌ی فعالیت ذخیره می‌شود');
is((int) $manA->daily_report_id, (int) $report->id, 'daily_report_id از فعالیت مشتق می‌شود');
is($manA->company_id, $report->company_id, 'company_id از گزارش (پروژه) مشتق می‌شود');

// ۳-۱/ب) فعالیتِ دوم هم باید ردیفِ خودش را داشته باشد تا فیلترِ لیست معنا پیدا کند
$manB = new DailyReportManpower(['daily_report_activity_id' => $activityB->id, 'position_type' => 3, 'quantity' => 1]);
ok(attempt(fn () => $manB->save()) === true, 'نیروی فعالیت B ذخیره می‌شود');

// ۳-۲) گزارشِ اشتباهِ همراهِ فعالیتِ درست نباید بماند
$manC = new DailyReportManpower([
    'daily_report_activity_id' => $activityA->id,
    'daily_report_id' => 999999999,
    'position_type' => 1,
    'quantity' => 1,
]);
attempt(fn () => $manC->save());
is((int) $manC->daily_report_id, (int) $report->id, 'گزارشِ فعالیت بر ورودی کاربر مقدم است');

// ۳-۳) تحویل کالا مالِ خودِ گزارش است (ایشوی ۴): نه ستون task_id دارد، نه فعالیت
$delivery = new DailyReportDeliveries([
    'daily_report_id' => $report->id,
    'action_type' => 1,
    'item_group' => 1,
    'delivery_type' => 1,
    'quantity' => 4,
]);
ok(attempt(fn () => $delivery->save()) === true, 'تحویل کالا (بدون task_id) با شناسه‌ی گزارش ذخیره می‌شود');
is($delivery->daily_report_activity_id, null, 'تحویل کالا به هیچ فعالیتی وصل نمی‌شود');

// ایشوی ۴: بدونِ گزارشِ معتبر ردیف ساخته نمی‌شود
$noReport = new DailyReportDeliveries(['action_type' => 1, 'item_group' => 1, 'delivery_type' => 1, 'quantity' => 1]);
attempt(fn () => $noReport->save());
ok($noReport->hasErrors('daily_report_id'), 'تحویلِ بدونِ گزارش رد می‌شود');

$badReport = new DailyReportDeliveries(['daily_report_id' => 999999999, 'action_type' => 1, 'item_group' => 1, 'delivery_type' => 1, 'quantity' => 1]);
attempt(fn () => $badReport->save());
ok($badReport->hasErrors('daily_report_id'), 'گزارشِ ناموجود رد می‌شود');

// ۳-۴) بدون فعالیت ⇒ رد می‌شود (وگرنه MySQL به خاطر NOT NULL استثنا می‌اندازد)
$noActivity = new DailyReportManpower(['daily_report_id' => $report->id, 'position_type' => 1, 'quantity' => 1]);
attempt(fn () => $noActivity->save());
ok($noActivity->hasErrors('daily_report_activity_id'), 'ردیفِ بدون فعالیت رد می‌شود');

$badActivity = new DailyReportManpower(['daily_report_activity_id' => 999999999, 'position_type' => 1, 'quantity' => 1]);
attempt(fn () => $badActivity->save());
ok($badActivity->hasErrors('daily_report_activity_id'), 'فعالیتِ ناموجود رد می‌شود');

// ۳-۵) مسیرِ تو در تو (همان چیزی که فرانت صدا می‌زند)
Yii::$app->request->setBodyParams(['position_type' => 2, 'quantity' => 5]);
$service = LineService::save(DailyReportManpower::class, null, $activityA->id);
ok(
    ($service['success'] ?? false) === true
        && (int) ($service['data']->daily_report_id ?? 0) === (int) $report->id,
    'LineService::save با شناسه‌ی فعالیت موفق است و گزارشش درست است'
);

// ۳-۶) لیستِ فرانت باید بتواند بر اساس فعالیت فیلتر کند
Yii::$app->request->setQueryParams(['daily_report_activity_id' => $activityB->id]);
$listed = LineService::index(DailyReportManpower::class);
$listedRows = $listed['data'] ?? [];
ok(
    (int) ($listed['totalCount'] ?? -1) === 1
        && (int) ($listedRows[0]['daily_report_activity_id'] ?? 0) === (int) $activityB->id,
    'لیست نیروها با فیلترِ فعالیت فقط ردیف‌های همان فعالیت را می‌دهد'
);

// ۳-۷) «دریافت کامل گزارش» باید کلید فعالیت را داشته باشد تا پیش‌نمایش گروه‌بندی کند
$rows = LineService::rows($report->id);
$hasKey = false;
foreach ($rows as $group) {
    foreach ($group as $row) {
        $hasKey = $hasKey || array_key_exists('daily_report_activity_id', $row);
    }
}
ok($hasKey, 'rows() ردیف‌ها شامل کلید daily_report_activity_id است');

// ۳-۸) حذفِ فعالیت ⇒ حذفِ ردیف‌هایش (FK ON DELETE CASCADE)
ok((int) attempt(fn () => $activityA->delete()) > 0, 'حذفِ فعالیت A');
is((int) DailyReportManpower::find()->where(['daily_report_activity_id' => $activityA->id])->count(), 0, 'حذفِ فعالیت ⇒ حذفِ ردیف‌هایش');

// ۳-۹) حذفِ گزارش ⇒ حذفِ فعالیت‌ها (رفتار فعلی نباید بشکند)
attempt(fn () => $report->delete());
is((int) DailyReportActivity::find()->where(['daily_report_id' => $report->id])->count(), 0, 'حذفِ گزارش ⇒ حذفِ فعالیت‌ها');

$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n>>> $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
