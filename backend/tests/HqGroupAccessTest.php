<?php

/**
 * Access gate that decides whether a user's group may open the HQ panel.
 *
 * Covered rules (all must reject, everything else must pass):
 *   1. group_id is not a plain integer (JSON arrays, null, empty, garbage)
 *   2. group_id is a plain integer but no such group row exists
 *   3. the group row is not marked `created_in_hq`, i.e. this panel did not
 *      create it
 *
 * Deliberately NOT part of the gate: which company owns the group. The login
 * now asks the caller which of a phone's several rows it means (see
 * `select_login_row()` / `login_company_options()`), so a row picked on
 * purpose is followed through. What marks a group as this panel's own is
 * `created_in_hq` - set by `Group::_save()` on a create only, and backfilled
 * by `sql/group-created-in-hq.sql` for every group outside companies 1 and 2.
 * So the company-less groups and companies 3/4/7/8/10 pass, while a group
 * still owned by dash (1) or seal (2) stops here.
 *
 * The method under test is pure, so this runs without a database connection.
 *
 * Run:  php frontend/modules/hq/tests/HqGroupAccessTest.php
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

use frontend\modules\hq\models\User;

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

/**
 * Stand-in for a Group row so the gate stays testable without a database.
 *
 * `$createdInHq` defaults to true because most rules below are about the id,
 * not about the provenance - rule 3 flips it.
 */
function group($companyId, $createdInHq = true)
{
    return (object) ['id' => 0, 'company_id' => $companyId, 'roles' => '[]', 'created_in_hq' => $createdInHq];
}

/** Swallows "method does not exist yet" so a red run reports instead of dying. */
function allowed($groupId, $row)
{
    try {
        return User::hq_group_is_allowed($groupId, $row);
    } catch (\Throwable $e) {
        echo '        (' . get_class($e) . ': ' . $e->getMessage() . ")\n";
        return null;
    }
}

// Rule 1 - group_id must name at least one numeric id. Every real row stores
// a JSON array there (one id per group the user belongs to), so the gate has
// to read it; a plain integer is simply the other shape it accepts.
ok(allowed('[1,23,15]', group(null)) === true, 'a JSON array group_id is read - the shape every real row uses');
ok(allowed('[21]', group(null)) === true, 'a one-item JSON array group_id is read');
ok(allowed('[]', group(null)) === false, 'a JSON array holding no id is rejected');
ok(allowed('["a",21]', group(null)) === true, 'the numeric members of an array still count');
ok(allowed('[1,abc]', group(null)) === false, 'a malformed array holds no id at all, so it is rejected');
ok(allowed('[abc]', group(null)) === false, 'a JSON array holding no numeric member is rejected');
ok(allowed(null, group(null)) === false, 'null group_id is rejected');
ok(allowed('', group(null)) === false, 'empty group_id is rejected');
ok(allowed('admin', group(null)) === false, 'non numeric group_id is rejected');

// Rule 2 - the integer must point at a group that still exists.
ok(allowed('38', null) === false, 'dangling group id (group row missing) is rejected');
ok(allowed('21', null) === false, 'plain id without a resolved group is rejected');

// Rule 3 - the group has to be one this panel created.
ok(allowed('21', group(null, false)) === false, 'a company-less group this panel did not create is rejected');
ok(allowed('1', group(1, false)) === false, 'a dash group without the mark is rejected');
ok(allowed('16', group(2, false)) === false, 'a seal group without the mark is rejected');
ok(allowed('21', (object) ['id' => 21, 'roles' => '[]']) === false, 'a row that does not carry the mark yet fails closed');

// The company that owns a group is still not part of the gate: the login asks
// the caller which of a phone's rows it means, and a row picked on purpose is
// followed through. Only the provenance of the group decides (rule 3 above).
ok(allowed('1', group(1)) === true, 'a marked company 1 group passes, the company is not the gate');
ok(allowed('16', group(2)) === true, 'a marked company 2 group passes, the company is not the gate');

// Allowed: HQ's own company-less groups.
ok(allowed('21', group(null)) === true, 'company-less group is allowed');

// Allowed: companies that have no panel of their own and scope data in HQ.
ok(allowed('32', group(4)) === true, 'company 4 group is allowed');
ok(allowed('25', group(7)) === true, 'company 7 group is allowed');
ok(allowed('28', group(10)) === true, 'company 10 group is allowed');

// A row without a usable hash must never match, and must never throw:
// Yii's validator raises InvalidArgumentException instead of returning false.
ok(User::password_matches('anything', null) === false, 'null hash does not match');
ok(User::password_matches('anything', '') === false, 'empty hash does not match');
ok(User::password_matches('anything', 'not-a-bcrypt-hash') === false, 'malformed hash does not match');

$known = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
ok(User::password_matches('secret', $known) === true, 'correct password matches');
ok(User::password_matches('wrong', $known) === false, 'wrong password does not match');

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
