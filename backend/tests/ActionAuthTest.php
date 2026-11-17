<?php

/**
 * پوشش auth برای کنترلرهای فاز ۲ + دو کنترلری که ایشوی ۶ تغییرشان داد —
 * بدون دیتابیس (فقط bootstrap + reflection).
 *
 * ایشوی ۳ یک باگ امنیتی داشت: `create-activity` در آرایه‌ی `actions`ی
 * JwtAuthBehavior نبود و بدون توکن اجرا می‌شد. این تست جلوی تکرارش را می‌گیرد،
 * هر دو طرفش را:
 *   ۱) هر اکشنی که کنترلر پیاده می‌کند باید در لیست JWT باشد (بدون توکن نمی‌شود رفت)
 *   ۲) هر ورودیِ لیست باید اکشنِ واقعی باشد (ورایش تایپی یعنی حفاظتِ قلابی)
 *
 * کنترلرها با glob کشف می‌شوند تا کنترلرِ تازه هم خودکار پوشش بگیرد.
 *
 * اجرا:  php frontend/modules/hq/tests/ActionAuthTest.php
 */

$backend = dirname(__DIR__, 4);

// همان bootstrapِ frontend/web/index.php بدون run() — لازم است چون
// `behaviors()` کنترلرها به کامپوننت `request` اپلیکیشن دست می‌زند.
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

    if (!$condition) {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

/** `actionCreateActivity` → `create-activity` — وارونِ دقیقِ `Inflector::id2camel` */
function actionId($methodName)
{
    return strtolower(preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', substr($methodName, 6)));
}

$files = array_merge(
    glob(dirname(__DIR__) . '/controllers/DailyReport*.php'),
    [
        // کنترلرهایی که ایشوی ۶ تغییرشان داد و کنترلری که endpoint جدید فاز ۲
        // را رویش سوار کرد. `update` در `ProjectController` سال‌ها خارج از
        // لیست JWT بود (یافته‌ی C) و اینجا باید قرمز می‌شد.
        // Issue 1: the timesheet tab moved from DailyReportTimesheetsController
        // to UsersLogController, so the explicit entry replaces it in the set
        // of controllers the JWT list is checked against.
        dirname(__DIR__) . '/controllers/ProjectController.php',
        dirname(__DIR__) . '/controllers/TaskController.php',
        dirname(__DIR__) . '/controllers/UsersLogController.php',
    ]
);
sort($files);
ok(count($files) >= 9, 'nine hq controllers found (got ' . count($files) . ')');

$namespace = 'frontend\modules\hq\controllers\\';
$module = new yii\base\Module('hq', null);
$tested = [];

foreach ($files as $file) {
    $class = $namespace . basename($file, '.php');
    $rc = new ReflectionClass($class);

    // پایه‌ی انتزاعی خودش ساخته نمی‌شود؛ پنج فرزندش همان `behaviors()` را
    // به ارث می‌برند، پس پوشش از راه آن‌ها کامل می‌شود.
    if ($rc->isAbstract()) {
        ok(is_subclass_of($class, 'yii\rest\ActiveController'), basename($file) . ' is the abstract line base');
        continue;
    }

    try {
        $controller = $rc->newInstance('daily-report', $module);
        $behaviors = $controller->behaviors();
        $actions = $controller->actions();

        $jwt = $behaviors['jwtAuth']['actions'] ?? [];
        ok(isset($behaviors['jwtAuth']), "$class has the jwtAuth behavior");
        ok(strpos($behaviors['jwtAuth']['class'] ?? '', 'JwtAuthBehavior') !== false, "$class uses JwtAuthBehavior");
        ok(is_array($jwt) && count($jwt) > 0, "$class JWT list is not empty");

        $implemented = [];

        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // `actions()` و `behaviors()` هم با «action» شروع می‌شوند ولی اکشن نیستند.
            if (!preg_match('/^action[A-Z]/', $method->getName())) {
                continue;
            }
            if (strpos($method->getDeclaringClass()->getName(), $namespace) !== 0) {
                continue;
            }
            $implemented[] = actionId($method->getName());
        }

        foreach (array_keys($actions) as $id) {
            // `OptionsAction` فقط HTTP verbهای مجاز را برمی‌گرداند و داده‌ای ندارد؛
            // به‌علاوه frontend/web/index.php درخواست‌های OPTIONS را پیش از Yii
            // قطع می‌کند، پس اصلاً به این اکشن نمی‌رسد.
            if ($id === 'options') {
                continue;
            }
            $implemented[] = $id;
        }

        $implemented = array_values(array_unique($implemented));
        $jwt = array_values(array_unique($jwt));
        sort($implemented);
        sort($jwt);

        foreach ($implemented as $id) {
            ok(in_array($id, $jwt, true), "$class: implemented action '$id' is in the JWT list");
        }
        foreach ($jwt as $id) {
            ok(in_array($id, $implemented, true), "$class: JWT entry '$id' is a real action");
        }

        $tested[] = basename($file) . ' [' . implode(', ', $implemented) . ']';
    } catch (Throwable $e) {
        ok(false, "$class could not be inspected: " . $e->getMessage());
    }
}

ok(count($tested) >= 6, 'at least six concrete controllers inspected (got ' . count($tested) . ')');

echo "\n--- controllers ---\n";
foreach ($tested as $one) {
    echo "  $one\n";
}

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
