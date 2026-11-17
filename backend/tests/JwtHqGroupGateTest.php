<?php

/**
 * The second place that asks whether a group belongs to the HQ panel.
 *
 * `JwtAuthBehavior` is shared by hq, seal and dash. Only one of its branches
 * resolves a company-1 user's group from `user.active_roles` instead of
 * `user.group_id`, and since 2026-10-08 that branch runs under `dash` alone -
 * `hq` reads `user_login_log.group_id` and settles the group when the token
 * is issued, so the token alone names the row there. The two questions below
 * decide whether that branch is gated at all:
 *
 * Covered rules:
 *   1. the gate belongs to the hq module and to nothing else - seal and dash
 *      create their groups in their own panels, so turning it on for them
 *      would refuse every one of their own tokens
 *   2. an explicit `requireHqGroup` wins over the module it was read from,
 *      in both directions
 *   3. the same mark the login asks for, and the same failure mode: a row
 *      that does not carry `created_in_hq` yet counts as somebody else's
 *
 * Rule 3 is the live one: `hq` asks it in `User::login()` on every attempt,
 * so these helpers and the login must never drift apart.
 *
 * The three methods under test are static and take plain values, so no
 * request, no controller and no token are needed.
 *
 * Run:  php frontend/modules/hq/tests/JwtHqGroupGateTest.php
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

use frontend\components\JwtAuthBehavior;

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

/** Marks a stand-in group row as this panel's own. */
function hqGroup()
{
    return (object) ['id' => 21, 'company_id' => null, 'roles' => '[]', 'created_in_hq' => 1];
}

/** Marks a stand-in group row as somebody else's. */
function foreignGroup()
{
    return (object) ['id' => 16, 'company_id' => 2, 'roles' => '[]', 'created_in_hq' => 0];
}

// Rule 1 - the gate belongs to the hq module and to nothing else.
ok(JwtAuthBehavior::hq_group_gate_applies('hq') === true, 'the hq module is gated');
ok(JwtAuthBehavior::hq_group_gate_applies('seal') === false, 'the seal module is not gated');
ok(JwtAuthBehavior::hq_group_gate_applies('dash') === false, 'the dash module is not gated');
ok(JwtAuthBehavior::hq_group_gate_applies(null) === false, 'a controller without a module is not gated');

// Rule 2 - an explicit setting wins over the module it was read from.
ok(JwtAuthBehavior::hq_group_gate_applies('seal', true) === true, 'an explicit true gates a module that would not be');
ok(JwtAuthBehavior::hq_group_gate_applies('hq', false) === false, 'an explicit false spares the hq module');
ok(JwtAuthBehavior::hq_group_gate_applies(null, true) === true, 'an explicit true gates even without a module');

// Rule 3 - the same mark the login asks for, failing the same way.
ok(JwtAuthBehavior::group_is_from_hq(hqGroup()) === true, 'a marked group passes');
ok(JwtAuthBehavior::group_is_from_hq(foreignGroup()) === false, 'an unmarked group fails');
ok(JwtAuthBehavior::group_is_from_hq((object) ['id' => 21, 'roles' => '[]']) === false, 'a row without the column fails closed');
ok(JwtAuthBehavior::group_is_from_hq(null) === false, 'a group that could not be resolved fails');

// The two questions must not drift apart: the login and the token check
// accept and refuse exactly the same rows.
$loginGate = [
    'marked' => frontend\modules\hq\models\User::hq_group_is_allowed('21', hqGroup()),
    'unmarked' => frontend\modules\hq\models\User::hq_group_is_allowed('16', foreignGroup()),
];
ok($loginGate['marked'] === JwtAuthBehavior::group_is_from_hq(hqGroup()), 'the login and the token check accept the same group');
ok($loginGate['unmarked'] === JwtAuthBehavior::group_is_from_hq(foreignGroup()), 'the login and the token check refuse the same group');

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
