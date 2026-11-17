<?php

/**
 * تست قواعد گزارش روزانه (سربرگ) — بدون دیتابیس و بدون Yii.
 *
 * اجرا:  php frontend/modules/hq/tests/DailyReportTest.php
 * در صورت خطا exit code غیر صفر برمی‌گرداند.
 */

require dirname(__DIR__) . '/services/ReportRules.php';
require dirname(__DIR__) . '/services/CumulativeProgress.php';
require dirname(__DIR__) . '/services/ReportSnapshot.php';

use frontend\modules\hq\services\CumulativeProgress;
use frontend\modules\hq\services\ReportRules;
use frontend\modules\hq\services\ReportSnapshot;

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $failures, $checks;
    $checks++;

    if (!$condition) {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

function is($actual, $expected, $label)
{
    ok($actual === $expected, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

// ---------------------------------------------------------------------
// لیست‌ها: کلیدها باید دقیقاً همان مقدارهای سند فاز ۲ باشند
// ---------------------------------------------------------------------
is(array_keys(ReportRules::WEATHER), [1, 2, 3, 4, 5], 'وضعیت هوا دقیقاً 1..5');
is(count(ReportRules::WEATHER), 5, 'وضعیت هوا 5 گزینه دارد');
is(ReportRules::WEATHER[4], 'برفی', 'ترتیب شیت Data حفظ شده (4=برفی)');
is(array_keys(ReportRules::SITE), [1, 2], 'وضعیت کارگاه دقیقاً 1..2');
is(ReportRules::SITE[ReportRules::SITE_INACTIVE], 'غیرفعال', 'مقدار 2 یعنی غیرفعال');
is(ReportRules::values(ReportRules::WEATHER), [1, 2, 3, 4, 5], 'values کلیدها را می‌دهد');
is(ReportRules::values(ReportRules::SITE), [1, 2], 'values وضعیت کارگاه');
is(ReportRules::STATUS_DRAFT, 0, 'پیش‌نویس = 0');
is(ReportRules::STATUS_SUBMITTED, 1, 'ثبت نهایی = 1');
ok(ReportRules::STATUS_DRAFT !== ReportRules::STATUS_SUBMITTED, 'دو وضعیت ثبت باید متمایز باشند');

// ---------------------------------------------------------------------
// isValidValue: قبول مقدار معتبر، رد نامعتبر (شامل 0 و رشته‌ی عددی)
// ---------------------------------------------------------------------
ok(ReportRules::isValidValue(ReportRules::WEATHER, 3), 'هوا=3 معتبر');
ok(ReportRules::isValidValue(ReportRules::WEATHER, '5'), 'هوا="5" (رشته) معتبر');
ok(!ReportRules::isValidValue(ReportRules::WEATHER, 0), 'هوا=0 نامعتبر');
ok(!ReportRules::isValidValue(ReportRules::WEATHER, 6), 'هوا=6 نامعتبر');
ok(!ReportRules::isValidValue(ReportRules::SITE, 3), 'کارگاه=3 نامعتبر');
ok(ReportRules::isValidValue(ReportRules::SITE, ReportRules::SITE_INACTIVE), 'کارگاه=غیرفعال معتبر');

// ---------------------------------------------------------------------
// isIsoDate
// ---------------------------------------------------------------------
ok(ReportRules::isIsoDate('2026-10-04'), 'تاریخ معتبر پذیرفته می‌شود');
ok(ReportRules::isIsoDate('2024-02-29'), 'سال کبیسه 29 بهمن قبول');
ok(!ReportRules::isIsoDate('2026-02-29'), 'غیرکبیسه 29 بهمن رد می‌شود');
ok(!ReportRules::isIsoDate('2026-02-30'), 'روز ناموجود رد می‌شود');
ok(!ReportRules::isIsoDate('2026-13-01'), 'ماه ناموجود رد می‌شود');
ok(!ReportRules::isIsoDate('1405/07/12'), 'تاریخ شمسی با جداکننده / رد می‌شود');
ok(!ReportRules::isIsoDate('2026-10-4'), 'روز تک‌رقمی بدون صفر رد می‌شود');
ok(!ReportRules::isIsoDate(''), 'خالی رد می‌شود');
ok(!ReportRules::isIsoDate(null), 'null رد می‌شود');
ok(!ReportRules::isIsoDate(20261004), 'عدد صحیح رد می‌شود');

// ---------------------------------------------------------------------
// siteReasonError — قاعده‌ی بند ۱۷ سند: کارگاه غیرفعال ⇒ دلیل اجباری
// ---------------------------------------------------------------------
ok(ReportRules::siteReasonError(ReportRules::SITE_INACTIVE, '') !== null, 'غیرفعال بدون دلیل خطا می‌دهد');
ok(ReportRules::siteReasonError(ReportRules::SITE_INACTIVE, '   ') !== null, 'غیرفعال با دلیل فقط فاصله خطا می‌دهد');
ok(ReportRules::siteReasonError(ReportRules::SITE_INACTIVE, null) !== null, 'غیرفعال با null خطا می‌دهد');
is(
    ReportRules::siteReasonError(ReportRules::SITE_INACTIVE, ''),
    'برای کارگاه غیرفعال، دلیل غیرفعالی الزامی است',
    'پیام خطا فارسی و روشن است'
);
ok(ReportRules::siteReasonError(ReportRules::SITE_INACTIVE, 'بارندگی شدید') === null, 'غیرفعال با دلیل معتبر آزاد است');
ok(ReportRules::siteReasonError(ReportRules::SITE_ACTIVE, '') === null, 'فعال بدون دلیل مشکلی ندارد');
ok(ReportRules::siteReasonError(ReportRules::SITE_ACTIVE, 'دلیل قدیمی') === null, 'فعال با دلیل هم خطا نمی‌دهد');
ok(ReportRules::siteReasonError(null, '') === null, 'وضعیت ثبت‌نشده خطا نمی‌دهد (required خودش می‌گیرد)');
ok(ReportRules::siteReasonError(99, '') === null, 'مقدار نامعتبر توسط قاعده‌ی `in` گرفته می‌شود نه اینجا');

// ---------------------------------------------------------------------
// وضعیت فعلی فعالیت — 6 مقدار، ترتیب شیت Data اکسل
// ---------------------------------------------------------------------
is(array_keys(ReportRules::ACTIVITY_STATUS), [1, 2, 3, 4, 5, 6], 'وضعیت فعلی دقیقاً 1..6');
is(count(ReportRules::ACTIVITY_STATUS), 6, 'وضعیت فعلی 6 گزینه دارد');
is(ReportRules::ACTIVITY_STATUS[1], 'شروع', '1=شروع');
is(ReportRules::ACTIVITY_STATUS[4], 'شروع و اتمام', '4=شروع و اتمام');
is(ReportRules::ACTIVITY_STATUS[6], 'شروع نشده', '6=شروع نشده');
is(ReportRules::PROGRESS_MAX, 100, 'سقف درصد = 100 (بند 17 سند)');

// ---------------------------------------------------------------------
// CumulativeProgress::series — جمع تدریجی (بند 210 سند)
// ---------------------------------------------------------------------
is(CumulativeProgress::series([])['values'], [], 'بدون گزارش، تجمعی خالی است');
is(CumulativeProgress::series([])['overflow'], 0.0, 'بدون گزارش، سرریز ندارد');
is(CumulativeProgress::series([0])['values'], [0.0], 'پیشرفت صفر تجمعی صفر می‌دهد');
is(CumulativeProgress::series([30, 40])['values'], [30.0, 70.0], 'جمع تدریجی دو روز');
is(CumulativeProgress::series([10, 20, 30, 40])['values'], [10.0, 30.0, 60.0, 100.0], 'چهار روز تا دقیقاً 100');
is(CumulativeProgress::series([10, 20, 30, 40])['overflow'], 0.0, 'رسیدن دقیقاً به 100 سرریز نیست');

$boundary = CumulativeProgress::series([100]);
is($boundary['values'], [100.0], '100 درصد پذیرفته می‌شود');
is($boundary['overflow'], 0.0, 'دقیقاً 100 سرریز نمی‌کند');

$over = CumulativeProgress::series([100, 1]);
is($over['overflow'], 101.0, '100 + 1 سرریز می‌کند');
is(count($over['values']), 2, 'همه‌ی گام‌ها محاسبه می‌شوند حتی بعد از سرریز');

$mid = CumulativeProgress::series([60, 50]);
is($mid['values'], [60.0, 110.0], 'جمع تدریجی ادامه پیدا می‌کند');
is($mid['overflow'], 110.0, 'نخستین مقدارِ ردشده گزارش می‌شود');

is(CumulativeProgress::series([50, 50, 50])['overflow'], 150.0, 'سرریز از همان گامِ سوم دیده می‌شود');
is(CumulativeProgress::series([50, 50, 50])['values'], [50.0, 100.0, 150.0], 'مرز 100 در گام دوم نگه داشته می‌شود');

$rounding = CumulativeProgress::series([33.333, 33.333, 33.334]);
is($rounding['values'], [33.33, 66.67, 100.0], 'گردش اعشار تا دو رقم');
is($rounding['overflow'], 0.0, 'جمع اعشاریِ دقیقاً 100 سرریز نیست');

is(CumulativeProgress::series([0, 0, 0])['values'], [0.0, 0.0, 0.0], 'گزارش‌های بدون پیشرفت');
is(CumulativeProgress::series([-10])['values'], [-10.0], 'مقدار منفی (که validation رد می‌کند) اینجا خراب نمی‌شود');

// ---------------------------------------------------------------------
// CumulativeProgress::overflowError
// ---------------------------------------------------------------------
is(CumulativeProgress::overflowError(0), null, 'سرریز صفر یعنی بدون خطا');
is(CumulativeProgress::overflowError(null), null, 'سرریز null یعنی بدون خطا');
$err = CumulativeProgress::overflowError(110.0);
ok($err !== null, 'سرریز 110 خطا می‌دهد');
ok(strpos($err, '110') !== false, 'پیام، مقدار سرریز را نشان می‌دهد');
ok(strpos($err, '100') !== false, 'پیام، سقف را نشان می‌دهد');

// ---------------------------------------------------------------------
// ReportSnapshot — نگاشت ردیف CPM به ستون‌های جدول
// ---------------------------------------------------------------------
$cpmRow = [
    'id' => 12, 'wbs_code' => '1.2.3', 'name' => 'نصب شیرآلات',
    'duration' => 2.5, 'unit' => 1, 'free_float' => 4.0,
    'start_date' => '2026-10-04', 'finish_date' => '2026-10-06',
    'late_finish_date' => '2026-10-10',
];
$snap = ReportSnapshot::fromCpm($cpmRow);
is($snap['wbs_code'], '1.2.3', 'snapshot: کد WBS');
is($snap['task_name'], 'نصب شیرآلات', 'snapshot: عنوان تسک');
is($snap['duration_value'], 2.5, 'snapshot: مدت');
is($snap['duration_unit'], 1, 'snapshot: واحد مدت');
is($snap['planned_start'], '2026-10-04', 'snapshot: شروع برنامه‌ای');
is($snap['planned_finish'], '2026-10-06', 'snapshot: پایان برنامه‌ای');
is($snap['free_float'], 4.0, 'snapshot: شناوری آزاد از CPM');
is($snap['latest_finish'], '2026-10-10', 'snapshot: دیرترین پایان از CPM');
is(count($snap), 8, 'snapshot دقیقاً 8 ستون دارد');
ok(!array_key_exists('task_type', $snap), 'task_type snapshot نمی‌شود چون در جدول task نیست');

$partial = ReportSnapshot::fromCpm(['wbs_code' => '9', 'name' => 'X']);
is($partial['planned_start'], null, 'ستونِ غایب CPM به null می‌شود');
is($partial['free_float'], null, 'شناوری غایب null است');
is($partial['task_name'], 'X', 'عنوان حتی در ورودی ناقص گرفته می‌شود');

$taskRow = [
    'id' => 5, 'wbs_code' => '2.1', 'name' => 'گچ‌کاری',
    'duration' => 3, 'duration_unit' => 2,
];
$fallback = ReportSnapshot::fromTask($taskRow);
is($fallback['task_name'], 'گچ‌کاری', 'جایگزین: عنوان از جدول task');
is($fallback['wbs_code'], '2.1', 'جایگزین: کد از جدول task');
is($fallback['duration_value'], 3, 'جایگزین: مدت از جدول task');
is($fallback['duration_unit'], 2, 'جایگزین: واحد از جدول task');
is($fallback['planned_start'], null, 'جایگزین: تاریخ برنامه‌ای null');
is($fallback['planned_finish'], null, 'جایگزین: پایان برنامه‌ای null');
is($fallback['free_float'], null, 'جایگزین: شناوری null');
is($fallback['latest_finish'], null, 'جایگزین: دیرترین پایان null');

// ---------------------------------------------------------------------
// ایشوی «وضعیت کارگاه اجباری» — هم سمت سرور و هم سمت فرم
// ---------------------------------------------------------------------
$repo = dirname(dirname(__DIR__), 5);
$model = (string) file_get_contents(dirname(__DIR__) . '/models/DailyReport.php');
ok(
    preg_match("/'project_id', 'report_date', 'site_status'\], 'required'/u", $model) === 1,
    'DailyReport: site_status جزو قاعده required است'
);
ok(
    strpos($model, "[['site_status'], 'validateSiteStatus']") !== false,
    'validateSiteStatus سر جای خودش مانده و آخرین قاعده است'
);

$schema = (string) file_get_contents($repo . '/frontend/hq/src/pages/daily-report/create/validationSchema.ts');
ok(
    strpos($schema, 'site_status: yup.string().trim().required("وضعیت کارگاه را انتخاب کنید")') !== false,
    'فرم: yup فیلد site_status را required می‌کند'
);

$header = (string) file_get_contents($repo . '/frontend/hq/src/components/pages/daily-report/HeaderPanel.tsx');
ok(
    preg_match('/name="site_status"[^>]*required=\{false\}/u', $header) !== 1,
    'HeaderPanel دیگر site_status را اختیاری نمی‌کند'
);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
