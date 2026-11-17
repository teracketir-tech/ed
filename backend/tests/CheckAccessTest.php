<?php

/**
 * باگ B (امنیتی): `User::checkAccess` باید واقعاً بررسی کند.
 *
 * پیش از رفع، اولین خطِ متد `if ($error) return true;` بود؛ یعنی همه‌ی
 * فراخوانی‌های عادی (بدون آرگومان دوم) هیچ‌چک نمی‌کردند و همیشه true
 * برمی‌گرداندند — کل لایه‌ی مجوز در ماژول hq بی‌اثر بود.
 *
 * نکته‌ی نگاشت: `group.roles` در بعضی گروه‌ها آرایه‌ی رشته (`["2422"]`) و در
 * بعضی دیگر آرایه‌ی عدد (`[2422]`) است؛ پس مقایسه باید loose بماند.
 *
 * اجرا:  php frontend/modules/hq/tests/CheckAccessTest.php
 */

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
    if ($condition) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label\n";
    }
}

/** هویت را روی کاربری با گروهِ داده‌شده می‌گذارد (بدون نشستن واقعی) */
function actingAs($groupId)
{
    $user = new frontend\modules\hq\models\User();
    $user->id = 1500070;
    $user->group_id = $groupId;
    Yii::$app->user->setIdentity($user);
}

/** checkAccess بدون آرگومان دوم باید در صورت نبودِ نقش استثنا بیندازد */
function checkThrows($roleId)
{
    try {
        frontend\modules\hq\models\User::checkAccess($roleId);
    } catch (\yii\web\UnauthorizedHttpException $e) {
        return true;
    } catch (\Throwable $e) {
        return 'wrong:' . get_class($e);
    }

    return false;
}

echo ">>> checkAccess: حالتِ bool (دو آرگومان)\n";

// گروه ۲۱ (hq) نقش‌ها را رشته نگه می‌دارد → 2422 باید پیدا شود
actingAs(21);
ok(frontend\modules\hq\models\User::checkAccess(2422, false) === true, 'نقشِ موجود با roles رشته‌ای پیدا می‌شود (2422)');
ok(frontend\modules\hq\models\User::checkAccess(502, false) === true, 'نقشِ موجودِ عددی-رشته‌ای (502) پیدا می‌شود');
ok(frontend\modules\hq\models\User::checkAccess(1102, false) === false, 'نقشِ ناموجود در گروه ۲۱ (1102) رد می‌شود');

// گروه ۱۵ roles آن خالی است
actingAs(15);
ok(frontend\modules\hq\models\User::checkAccess(402, false) === false, 'گروهِ بدون نقش همه‌چیز را رد می‌کند');
ok(checkThrows(402) === true, 'گروهِ بدون نقش در حالتِ عادی استثنا می‌اندازد');

// گروهی که اصلاً وجود ندارد نباید 500 بدهد
actingAs(999999);
ok(frontend\modules\hq\models\User::checkAccess(502, false) === false, 'گروهِ ناموجود به‌جای 500 رد می‌کند');
ok(checkThrows(502) === true, 'گروهِ ناموجود در حالتِ عادی استثنا می‌اندازد');

// بدون هویت
Yii::$app->user->setIdentity(null);
ok(frontend\modules\hq\models\User::checkAccess(502, false) === false, 'بدون هویت رد می‌شود');
ok(checkThrows(502) === true, 'بدون هویت در حالتِ عادی استثنا می‌اندازد');

echo ">>> checkAccess: حالتِ استثنا (یک آرگومان) — قبلاً همیشه true بود\n";

actingAs(21);
ok(checkThrows(1102) === true, 'کاربرِ بدون نقش 401 می‌گیرد (قبلاً رد می‌شد)');
ok(checkThrows(2422) === false, 'کاربرِ با نقش استثنا نمی‌گیرد');
ok(frontend\modules\hq\models\User::checkAccess(2422) === true, 'کاربرِ با نقش true برمی‌گرداند');

// تغییرِ سهواً خزنده: میان‌برِ قدیمی نباید دوباره برگردد
$source = file_get_contents(dirname(__DIR__) . '/models/User.php');
ok(preg_match('/if\s*\(\s*\$error\s*\)\s*return\s+true\s*;/', $source) !== 1, 'میان‌برِ `if ($error) return true;` حذف شده است');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
