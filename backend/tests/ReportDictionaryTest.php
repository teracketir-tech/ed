<?php

/**
 * تست واژه‌نامه‌ی سلکت‌های فرم گزارش روزانه — ایشوی ۹، ۱۲، ۱۵ و ۱۶، بدون دیتابیس و بدون Yii.
 * (نوع ماشین‌آلات، واحد، مصالح، دلیل انحراف و گروه که endpoint جدا ندارند؛
 *  به‌علاوه‌ی سه سلکتِ عددیِ فرم عوامل انحراف که از جدول نمی‌آیند)
 *
 * اجرا:  php frontend/modules/hq/tests/ReportDictionaryTest.php
 * در صورت خطا exit code غیر صفر برمی‌گرداند.
 */

require dirname(__DIR__) . '/services/ReportRoles.php';
require dirname(__DIR__) . '/services/DictionaryService.php';

use frontend\modules\hq\services\DictionaryService;

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

$modelsDir = dirname(__DIR__) . '/models';

// ---------------------------------------------------------------------
// واژه‌نامه‌ی فرم — کلیدهای پاسخ باید با تب‌ها یکی باشند
// (ایشوی ۱۲: دلیل انحراف هم به همین درخواست اضافه شد تا تبِ «عوامل انحراف»
//  درخواستِ دومی نزند؛ ایشوی ۱۶: گروه / منشاء / اثر غالبِ فرم عوامل انحراف
//  که در جدول جدا نیستند و از ثابتِ مدل ساخته می‌شوند)
// ---------------------------------------------------------------------
$tableKeys = ['units', 'equipment_types', 'products', 'deviation_reasons', 'groups'];
$constKeys = ['factor_groups', 'deviation_sources', 'effects'];

is(
    DictionaryService::KEYS,
    array_merge($tableKeys, $constKeys),
    'کلیدهای پاسخ پنج کلیدِ جدولی و سه کلیدِ ثابتیِ فرم عوامل انحراف هستند'
);
is(count(DictionaryService::SOURCES), count($tableKeys), 'هر کلیدِ جدولی دقیقاً یک منبعِ مدل دارد');
is(array_keys(DictionaryService::SOURCES), $tableKeys, 'ترتیب منابعِ جدولی همان پنج کلیدِ اول است');
is(DictionaryService::CONST_KEYS, $constKeys, 'سه کلیدِ سلکتِ عددی به‌صورت ثابت ثبت شده‌اند');
is(
    array_slice(DictionaryService::KEYS, count($tableKeys)),
    $constKeys,
    'کلیدهای ثابتی در انتهای پاسخ می‌نشینند'
);

// ---------------------------------------------------------------------
// سه سلکتِ عددی از `DeviationOptions` می‌آیند — منشاء به گروهش وصل است
// ---------------------------------------------------------------------
$optionsFile = dirname(__DIR__) . '/services/DeviationOptions.php';
ok(file_exists($optionsFile), 'سرویس DeviationOptions (گزینه‌های عددی) وجود دارد');
$optionsSource = file_exists($optionsFile) ? file_get_contents($optionsFile) : '';

foreach (['FACTOR_GROUP', 'DEVIATION_SOURCE', 'EFFECT'] as $constant) {
    ok(strpos($optionsSource, "const {$constant}") !== false, "DeviationOptions ثابت {$constant} را دارد");
}

foreach ($constKeys as $key) {
    ok(strpos($optionsSource, "'{$key}' =>") !== false, "DeviationOptions کلید {$key} را برای واژه‌نامه می‌سازد");
}

ok(preg_match("/'factor_group' => 1\]/", $optionsSource) === 1, 'منشاءهای داخلی (۱ تا ۱۳) به گروهِ ۱ وصل‌اند');
ok(preg_match("/'factor_group' => 2\]/", $optionsSource) === 1, 'منشاءهای خارجی (۱۴ تا ۲۰) به گروهِ ۲ وصل‌اند');

// ---------------------------------------------------------------------
// هر منبع باید مدلِ واقعیِ همان جدولی باشد که SQL فاز ۲ ساخته است
// ---------------------------------------------------------------------
$expectedTables = [
    'units' => ['model' => 'Unit', 'table' => 'unit'],
    'equipment_types' => ['model' => 'DailyReportEquipmentType', 'table' => 'daily_report_equipment_type'],
    'products' => ['model' => 'Product', 'table' => 'product'],
    'deviation_reasons' => ['model' => 'DailyReportDeviationReason', 'table' => 'daily_report_deviation_reason'],
    // گروه: `GET group` پشتِ نقش 1202 است و کاربرِ گزارش روزانه آن را ندارد؛
    // بی‌رون‌ریختنِ کاربر با 401 (ایشوی ۱۵)
    'groups' => ['model' => 'Group', 'table' => 'group'],
];

foreach ($expectedTables as $key => $expect) {
    $class = DictionaryService::SOURCES[$key];
    is($class, 'frontend\modules\hq\models\\' . $expect['model'], "$key به مدل درست اشاره می‌کند");

    $file = $modelsDir . '/' . $expect['model'] . '.php';
    ok(file_exists($file), "فایل مدل {$expect['model']} وجود دارد");

    $source = file_exists($file) ? file_get_contents($file) : '';
    ok(strpos($source, "'{$expect['table']}'") !== false, "مدل {$expect['model']} جدول '{$expect['table']}' را برمی‌گرداند");
    ok(strpos($source, "'name'") !== false || preg_match('/\bname\b/', $source) === 1, "مدل {$expect['model']} ستون name دارد");
}

// ---------------------------------------------------------------------
// مسیر و کنترلر — واژه‌نامه باید پیش از `GET {id}` بیاید و بدون توکن اجرا نشود
// ---------------------------------------------------------------------
$routes = file_get_contents(dirname(__DIR__) . '/config/routes.php');
ok(strpos($routes, "'GET dictionaries' => 'dictionaries'") !== false, 'route واژه‌نامه روی daily-report ثبت است');

$controller = file_get_contents(dirname(__DIR__) . '/controllers/DailyReportController.php');
ok(strpos($controller, "'dictionaries'") !== false, 'dictionaries در فهرست اکشن‌های JwtAuthBehavior است');
ok(strpos($controller, 'function actionDictionaries') !== false, 'actionDictionaries در کنترلر تعریف شده است');
ok(
    strpos($controller, 'function actionDictionaries') < strpos($controller, 'function actionSubmit'),
    'اکشن خواندنی واژه‌نامه بالاتر از submit می‌نشیند (خوانایی فایل)'
);

// ---------------------------------------------------------------------
// نقش: خواندنِ واژه‌نامه همان نقشِ مشاهده‌ی گزارش است
// ---------------------------------------------------------------------
$service = file_get_contents(dirname(__DIR__) . '/services/DictionaryService.php');
ok(
    strpos($service, 'User::checkAccess(ReportRoles::VIEW)') !== false,
    'واژه‌نامه پشتِ نقش مشاهده (2412) است'
);
ok(
    strpos($service, 'DeviationOptions::dictionaryOptions()') !== false,
    'واژه‌نامه سه سلکتِ عددی را از DeviationOptions می‌گیرد (یک منبع حقیقت)'
);
ok(strpos($service, 'ReportRoles::CREATE') === false, 'واژه‌نامه نقش افزودن را چک نمی‌کند (خواندن است، نه نوشتن)');

// ---------------------------------------------------------------------
// واحدِ کالا باید همراهِ خودِ ردیفِ کالا بیاید (ایشوی «مقدار (واحد)»)
//
// فرمِ تحویل کالا/مصالح برچسبِ «مقدار» را از واحدِ محصول می‌سازد. اگر واحد
// فقط از لیستِ `units` خوانده شود، شرکتِ گزارش باید همان واحد را هم داشته
// باشد وگرنه برچسب برای همیشه «مقدار (—)» می‌ماند؛ پس واحد به‌صورت نام
// روی خودِ محصول می‌نشیند و دیگر به دومین جستجو وابسته نیست.
// ---------------------------------------------------------------------
ok(
    strpos($service, "public const EXTRA_COLUMNS = [") !== false,
    'ستونِ اضافهی هر واژه‌نامه در یک ثابتِ واحد نگه داشته شده است'
);
ok(
    strpos($service, "['products' => ['unit_id']]") !== false || preg_match("/'products'\\s*=>\\s*\\[['\"].*unit_id/", $service) === 1,
    'محصول unit_id را از دیتابیس می‌خواند'
);
ok(
    strpos($service, 'unit_name') !== false,
    'نامِ واحد روی ردیفِ محصول می‌نشیند تا برچسبِ «مقدار (واحد)» به لیستِ واحد وابسته نباشد'
);
ok(
    strpos($service, 'Unit::find()') !== false,
    'نامِ واحدها با یک کوئریِ جدا و یک‌جا خوانده می‌شود (بدون N+1)'
);
ok(
    strpos($service, 'IndexBy') !== false || strpos($service, 'indexBy') !== false,
    'نگاشتِ واحد بر اساس شناسه ساخته می‌شود'
);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
