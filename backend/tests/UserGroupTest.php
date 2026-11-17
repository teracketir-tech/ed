<?php

/**
 * The multi-group user - the shape the dash panel writes.
 *
 * Covered rules:
 *   1. the form may send the group as a list of ids, and the column stores it
 *      as the JSON array every other panel already reads back; the driver
 *      cannot bind an array, so the ids are encoded before load() sees them
 *   2. a single id keeps its plain shape, so the rows already stored that way
 *      are rewritten by nothing
 *   3. the user list spells every group of such a row, joined with " - ",
 *      because group_id is a JSON array and the `group` relation - which
 *      matches one id - resolves nothing for it
 *   4. a single-group row is unchanged: one name, and the group column of the
 *      list still resolves it through the relation
 *
 * Run:  php frontend/modules/hq/tests/UserGroupTest.php
 * Exits non-zero when a check fails.
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

use frontend\modules\hq\models\Group;
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

function is($actual, $expected, $label)
{
    ok($actual === $expected, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

// ---- fixtures -------------------------------------------------------------
// Markers are ASCII so a mis-declared charset cannot turn a passing check
// into a comparison of two differently mangled words.

$marker = 'mtg' . substr(md5((string) microtime(true)), 0, 8);

$groups = [];

foreach (['Alpha', 'Beta'] as $suffix) {
    $group = new Group();
    $group->name = $marker . '-' . $suffix;
    ok($group->save(false) === true, "fixture group $suffix saved");
    $groups[] = $group->id;
}

$user = new User();
$user->first_name = $marker;
$user->last_name = 'Multi';
$user->phone_number = '09' . substr(preg_replace('/\D/', '', microtime()) . '000000000', 0, 9);
$user->group_id = '1';

ok($user->save(false) === true, 'fixture user saved');
$userId = $user->id;

/** Re-reads the row so a check never reads a stale in-memory value. */
function reloaded($id)
{
    return User::findOne($id);
}

/** One page of the user list, filtered down to the fixture row only. */
function listRow($marker)
{
    Yii::$app->request->setQueryParams([
        'page' => 1,
        'per-page' => 10,
        'name' => $marker,
        'active' => null,
        'fingerprint' => null,
        'device_status' => null,
        'expand' => null,
    ]);

    return User::get_all()['data'];
}

try {

    // ---- rule 1: a list of ids is stored as the JSON array ---------------

    Yii::$app->request->setBodyParams([
        'id' => $userId,
        'company_id' => null,
        'group_id' => [(string) $groups[0], (string) $groups[1]],
    ]);

    $saved = User::_save($userId);

    ok(($saved['success'] ?? false) === true, 'a list of group ids is saved');
    is(reloaded($userId)->group_id, '[' . $groups[0] . ',' . $groups[1] . ']', 'the list is stored as JSON');

    // ---- rule 3: the list spells every group name -------------------------

    $rows = listRow($marker);

    is(count($rows), 1, 'the fixture row is the only one the list returns');
    is($rows[0]['group_names'] ?? null, $marker . '-Alpha - ' . $marker . '-Beta', 'the list joins every group name with " - "');

    // ---- rule 2: a single id keeps its plain shape ------------------------

    Yii::$app->request->setBodyParams([
        'id' => $userId,
        'company_id' => null,
        'group_id' => (string) $groups[0],
    ]);

    User::_save($userId);

    is(reloaded($userId)->group_id, (string) $groups[0], 'a single group id stays a plain id');

    $rows = listRow($marker);
    is($rows[0]['group_names'] ?? null, $marker . '-Alpha', 'a single-group row still spells its one name');

    // ---- rule 4: a row whose group ids are gone ---------------------------

    Yii::$app->request->setBodyParams([
        'id' => $userId,
        'company_id' => null,
        'group_id' => [99999999],
    ]);

    User::_save($userId);

    $rows = listRow($marker);
    is($rows[0]['group_names'] ?? null, null, 'a row pointing at no group spells nothing');

} finally {

    User::deleteAll(['id' => $userId]);
    Group::deleteAll(['id' => $groups]);

}

echo "\n" . $checks . ' checks, ' . $failures . " failures\n";
exit($failures > 0 ? 1 : 0);
