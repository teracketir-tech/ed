<?php

/**
 * تست قواعد زیرمجموعه‌های گزارش روزانه — بدون دیتابیس و بدون Yii.
 * (ایشوی ۴: نیروی انسانی / تجهیزات / مصالح / تحویل / تایم‌شیت)
 *
 * اجرا:  php frontend/modules/hq/tests/DailyReportLineTest.php
 * در صورت خطا exit code غیر صفر برمی‌گرداند.
 */

require dirname(__DIR__) . '/services/ReportRules.php';
require dirname(__DIR__) . '/services/ReportRoles.php';
require dirname(__DIR__) . '/services/LineService.php';

use frontend\modules\hq\models\UsersLog;
use frontend\modules\hq\services\LineService;
use frontend\modules\hq\services\ReportRoles;
use frontend\modules\hq\services\ReportRules;

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
// enumهای تازه — کلیدها باید دقیقاً همان CHECKهای SQL باشند
// ---------------------------------------------------------------------
is(array_keys(ReportRules::ACTION_TYPE), [1, 2], 'نوع اقدام دقیقاً 1..2');
is(ReportRules::ACTION_IN, 1, 'ورود کالا = 1');
is(ReportRules::ACTION_OUT, 2, 'خروج کالا = 2');
is(ReportRules::ACTION_TYPE[2], 'خروج کالا', 'مقدار 2 واقعاً خروج کالاست');
is(array_keys(ReportRules::ITEM_GROUP), [1, 2], 'گروه کالا دقیقاً 1..2');
is(ReportRules::ITEM_GROUP[2], 'تجهیز', 'گروه 2 تجهیز است');
is(array_keys(ReportRules::DELIVERY_TYPE), [1, 2], 'نوع تحویل دقیقاً 1..2');
is(ReportRules::DELIVERY_TYPE[1], 'قطعی', 'نوع تحویل 1 قطعی است');
is(array_keys(ReportRules::EMPLOYMENT_TYPE), [1, 2], 'نوع همکاری دقیقاً 1..2');
is(ReportRules::EMPLOYMENT_TYPE[2], 'روز مزد', 'نوع همکاری 2 روز مزد است');

ok(ReportRules::isValidValue(ReportRules::ACTION_TYPE, 1), 'نوع اقدام 1 معتبر');
ok(!ReportRules::isValidValue(ReportRules::ACTION_TYPE, 3), 'نوع اقدام 3 نامعتبر');
ok(!ReportRules::isValidValue(ReportRules::EMPLOYMENT_TYPE, 3), 'نوع همکاری 3 نامعتبر');
ok(ReportRules::isValidValue(ReportRules::ITEM_GROUP, '2'), 'گروه کالا "2" رشته‌ای معتبر');

// ---------------------------------------------------------------------
// quantityError — بند ۱۷ سند: منفی فقط با «خروج کالا» (چک SQL: `>=0 OR action=2`)
// ---------------------------------------------------------------------
is(ReportRules::quantityError(10, ReportRules::ACTION_IN), null, 'مثبت با ورود آزاد است');
is(ReportRules::quantityError(0, ReportRules::ACTION_IN), null, 'صفر با ورود آزاد است');
is(ReportRules::quantityError(0.5, ReportRules::ACTION_OUT), null, 'کسری مثبت با خروج آزاد است');
is(ReportRules::quantityError(-5, ReportRules::ACTION_OUT), null, 'منفی با «خروج کالا» مجاز است');

$blocked = ReportRules::quantityError(-5, ReportRules::ACTION_IN);
ok($blocked !== null, 'منفی با «ورود کالا» رد می‌شود');
ok(is_string($blocked) && mb_strlen($blocked) > 10, 'پیام خطا فارسی و کامل است');

ok(ReportRules::quantityError(-5, 99) !== null, 'منفی با نوع اقدام نامعتبر رد می‌شود');
ok(ReportRules::quantityError(-5, null) !== null, 'منفی بدون نوع اقدام رد می‌شود');
ok(ReportRules::quantityError(-5, '') !== null, 'منفی با نوع اقدام خالی رد می‌شود');
is(ReportRules::quantityError(null, ReportRules::ACTION_IN), null, 'null را required می‌گیرد نه اینجا');
is(ReportRules::quantityError('', ReportRules::ACTION_IN), null, 'خالی را required می‌گیرد نه اینجا');
ok(ReportRules::quantityError(-0.01, ReportRules::ACTION_IN) !== null, 'منفیِ کوچک هم رد می‌شود');

// ---------------------------------------------------------------------
// isTime — ستون‌های time تایم‌شیت (H:i یا H:i:s)
// ---------------------------------------------------------------------
ok(ReportRules::isTime('08:00'), 'ساعت معتبر H:i');
ok(ReportRules::isTime('23:59'), 'آخرین دقیقه معتبر');
ok(ReportRules::isTime('00:00'), 'نیمه‌شب معتبر');
ok(ReportRules::isTime('08:30:15'), 'فرمت H:i:s هم پذیرفته می‌شود');
ok(!ReportRules::isTime('8:00'), 'تک‌رقمی بدون صفر رد می‌شود');
ok(!ReportRules::isTime('24:00'), 'ساعت 24 رد می‌شود');
ok(!ReportRules::isTime('08:60'), 'دقیقه 60 رد می‌شود');
ok(!ReportRules::isTime('08:00:60'), 'ثانیه 60 رد می‌شود');
ok(!ReportRules::isTime('08-00'), 'جداکننده‌ی غلط رد می‌شود');
ok(!ReportRules::isTime('2026-10-04'), 'تاریخ رد می‌شود');
ok(!ReportRules::isTime(''), 'خالی رد می‌شود');
ok(!ReportRules::isTime(null), 'null رد می‌شود');
ok(!ReportRules::isTime('abc'), 'متن غیرعددی رد می‌شود');
ok(!ReportRules::isTime(800), 'عدد رد می‌شود');

// ---------------------------------------------------------------------
// ReportRoles — نگاشت تأییدشده با کاربر: لیست 2422، بقیه 2411..2414
// ---------------------------------------------------------------------
is(ReportRoles::CREATE, 2411, 'افزودن = 2411');
is(ReportRoles::VIEW, 2412, 'مشاهده = 2412');
is(ReportRoles::UPDATE, 2413, 'ویرایش = 2413');
is(ReportRoles::DELETE, 2414, 'حذف = 2414');
is(ReportRoles::LIST, 2422, 'لیست = 2422');

$roles = [ReportRoles::CREATE, ReportRoles::VIEW, ReportRoles::UPDATE, ReportRoles::DELETE, ReportRoles::LIST];
is(count(array_unique($roles)), 5, 'پنج کد نقش از هم متمایزند');
foreach ($roles as $code) {
    ok($code >= 2411 && $code <= 2424, "کد $code داخل بلوک‌های گزارش روزانه است (2411..2424)");
    ok(!($code >= 2401 && $code <= 2408), "کد $code داخل بلوک تسک/روابط نیست");
}

// ---------------------------------------------------------------------
// ثبت بلوک‌ها در User::roles — قرارداد ایشوی ۱: «ساخته و استفاده شوند»
// ---------------------------------------------------------------------
$userSource = file_get_contents(dirname(__DIR__) . '/models/User.php');
foreach ([2411, 2412, 2413, 2414, 2421, 2422, 2423, 2424] as $code) {
    ok(strpos($userSource, "'id' => $code") !== false, "نقش $code در User::roles ثبت است");
}

// ---------------------------------------------------------------------
// LineService — پنج زیرمجموعه و نام‌هایی که URL و پاسخ view استفاده می‌کنند
// ---------------------------------------------------------------------
is(
    array_keys(LineService::MODELS),
    ['manpower', 'equipment', 'materials', 'deliveries', 'timesheets'],
    'نام پنج زیرمجموعه دقیقاً همان چیزی است که سند فاز ۲ می‌خواهد'
);
is(count(LineService::MODELS), 5, 'دقیقاً پنج زیرمجموعه ثبت است');

foreach (LineService::MODELS as $line => $class) {
    is(LineService::model($line), $class, "model('$line') مدل درست را می‌دهد");

    // Issue 1: the timesheet tab no longer owns a table of its own — it writes
    // the shared fingerprint log `users_logs`, so it is deliberately not
    // named DailyReportTimesheets. The other four keep the convention.
    if ($line === 'timesheets') {
        is($class, UsersLog::class, "کلاس '$class' لاگِ مشترک users_logs را می‌خواند");
        continue;
    }

    ok(substr($class, -strlen('DailyReport' . ucfirst($line)) ) === 'DailyReport' . ucfirst($line), "کلاس '$class' با '$line' می‌خواند");
}

is(LineService::model('foo'), null, 'نام ناشناخته null می‌دهد تا به کوئری SQL خطادار نرسد');
is(LineService::model('activities'), null, 'activities زیرمجموعه نیست (جدول جدا و سرویس جدا دارد)');

// ---------------------------------------------------------------------
// ایشوی ۴: تحویل کالا و تایم‌شیت مالِ خودِ گزارش‌اند، بقیه مالِ فعالیت
// ---------------------------------------------------------------------
is(LineService::REPORT_SCOPED, ['deliveries', 'timesheets'], 'دقیقاً دو زیرمجموعه گزارش‌محور ثبت است');

foreach (array_keys(LineService::MODELS) as $line) {
    $expected = in_array($line, ['deliveries', 'timesheets'], true);
    is(LineService::isReportScoped($line), $expected, "'$line' " . ($expected ? 'گزارش‌محور است' : 'فعالیت‌محور است'));
}

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
