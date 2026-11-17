<?php

/**
 * Signing into the panel as another user, and the list's door for it.
 *
 * Covered rules:
 *   1. the row is enterable only through a group this panel created, so a row
 *      whose groups were all born elsewhere never offers a door that would be
 *      shut one step later
 *   2. the permission is separate from reading the list, and is enforced on
 *      the call itself rather than only drawn for the button
 *   3. several admissible groups make the caller name one; none is guessed,
 *      and naming one outside the row's own groups is turned down
 *   4. the token carries the roles of the group that was named, and the login
 *      log records that very group rather than the row's first one
 *   5. the list filter keeps only rows holding every picked group, and the ids
 *      are cast because they arrive from the query string
 *
 * Run:  php frontend/modules/hq/tests/LoginAsTest.php
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
// firebase/php-jwt is vendored by hand next to the entry script rather than
// through composer, and issuing a token is exactly what needs it here.
require $backend . '/frontend/web/jwt/autoload.php';

$config = yii\helpers\ArrayHelper::merge(
    require $backend . '/common/config/main.php',
    require $backend . '/common/config/main-local.php',
    require $backend . '/frontend/config/main.php',
    require $backend . '/frontend/config/main-local.php'
);

new yii\web\Application($config);

use frontend\modules\hq\models\Group;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\UserLoginLog;

// add_login_log() reads the peer address straight off the server array, which
// a CLI run never fills in.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

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

/** Puts the caller in front of a row as a member of the given group. */
function actingAs($groupId)
{
    $user = new User();
    $user->id = 1500070;
    $user->group_id = $groupId;

    Yii::$app->user->setIdentity($user);
}

/** The exception a call answered with, so a wrong refusal cannot pass as one. */
function refusal(callable $attempt, string $class, string $label)
{
    try {
        $attempt();
        ok(false, $label);
    } catch (\Throwable $e) {
        ok(get_class($e) === $class, "$label (" . get_class($e) . ')');
    }
}

/** One page of the user list, narrowed to the fixture rows by first name. */
function listRows(string $marker, array $extra = [])
{
    Yii::$app->request->setQueryParams(array_merge([
        'page' => 1,
        'per-page' => 50,
        'name' => $marker,
        'active' => null,
        'fingerprint' => null,
        'device_status' => null,
        'expand' => null,
    ], $extra));

    return User::get_all()['data'];
}

function ids(array $rows)
{
    $found = [];

    foreach ($rows as $row) {
        $found[] = (int) $row['id'];
    }

    sort($found);

    return $found;
}

// ---- fixtures -------------------------------------------------------------

$marker = 'as' . substr(md5((string) microtime(true)), 0, 8);
$roles = json_encode([User::LOGIN_AS_ROLE]);

/** A group this panel created, with or without the permission under test. */
function makeGroup(string $name, int $createdInHq, string $roles)
{
    $group = new Group();
    $group->name = $name;
    $group->roles = $roles;
    $group->created_in_hq = $createdInHq;
    $group->save(false);

    return $group->id;
}

$gA = makeGroup($marker . '-A', 1, $roles);
$gB = makeGroup($marker . '-B', 1, $roles);
// created here, but never granted the permission
$gN = makeGroup($marker . '-N', 1, json_encode([709]));
// born elsewhere: never an entrance, whatever their roles say
$gX = makeGroup($marker . '-X', 0, json_encode([709]));
$gY = makeGroup($marker . '-Y', 0, json_encode([709]));

/** A fixture row whose group column is stored the way the panel reads it. */
function makeUser(string $marker, string $suffix, $groupId)
{
    $user = new User();
    $user->first_name = $marker;
    $user->last_name = $suffix;
    $user->phone_number = '09' . substr(preg_replace('/\D/', '', microtime()) . '000000000', 0, 9);
    $user->group_id = $groupId;
    $user->save(false);

    return $user->id;
}

$single = makeUser($marker, 'Single', (string) $gA);
$multi = makeUser($marker, 'Multi', json_encode([$gA, $gB]));
$mixed = makeUser($marker, 'Mixed', json_encode([$gX, $gA]));
$outsider = makeUser($marker, 'Outsider', (string) $gX);

try {

    // ---- rule 1: the door is drawn from the row's own groups --------------

    is(count(User::hq_groups((string) $gA)), 1, 'a single hq group is offered');
    is(User::hq_groups((string) $gA)[0]['id'], $gA, 'it is the very group of the row');

    $offered = User::hq_groups(json_encode([$gA, $gB]));
    is(count($offered), 2, 'every hq group of a multi-group row is offered');
    is($offered[0]['id'], $gA, 'the offered groups keep the order of the column');
    is($offered[1]['id'], $gB, 'the offered groups keep the order of the column (second)');

    is(User::hq_groups((string) $gX), [], 'a group born elsewhere offers nothing');
    is(User::hq_groups(json_encode([$gX, $gY])), [], 'a row of foreign groups offers nothing');

    $kept = User::hq_groups(json_encode([$gX, $gA]));
    is(count($kept), 1, 'a foreign group is dropped and the hq one kept');
    is($kept[0]['id'], $gA, 'the kept group is the one this panel created');

    // ---- rule 5: the list filter ------------------------------------------

    $found = ids(listRows($marker, ['group_id' => [$gA]]));
    is(count($found), 3, 'filtering on one group keeps the three rows holding it');
    is(in_array($outsider, $found, true), false, 'a row without the picked group is dropped');

    $found = ids(listRows($marker, ['group_id' => [$gA, $gB]]));
    is($found, [(int) $multi], 'filtering on several groups keeps only the row holding all of them');

    // the list hands the button its own admission list
    $byId = [];
    foreach (listRows($marker) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    is(count($byId[$multi]['hq_groups']), 2, 'the list row carries both admissible groups');
    is($byId[$outsider]['hq_groups'], [], 'the list row of a foreign group carries none');
    is($byId[$mixed]['hq_groups'][0]['id'], (int) $gA, 'the list row drops the foreign group');

    // ---- rule 2: the permission is its own --------------------------------

    actingAs($gN);
    refusal(function () use ($single) {
        User::login_as($single);
    }, 'yii\web\UnauthorizedHttpException', 'a group without the permission is turned down');

    actingAs($gA);
    refusal(function () {
        User::login_as(99999999);
    }, 'yii\web\NotFoundHttpException', 'an unknown row is not found');

    refusal(function () use ($outsider) {
        User::login_as($outsider);
    }, 'yii\web\ForbiddenHttpException', 'a row with no hq group cannot be entered');

    // ---- rule 3: several groups, no guessing ------------------------------

    refusal(function () use ($multi) {
        User::login_as($multi);
    }, 'yii\web\BadRequestHttpException', 'a row of several groups makes the caller name one');

    refusal(function () use ($mixed, $gX) {
        User::login_as($mixed, $gX);
    }, 'yii\web\ForbiddenHttpException', 'the foreign group of the row cannot be named');

    // ---- rule 4: the token and the log carry the named group --------------

    $entered = User::login_as($single);
    is($entered['success'], true, 'a row with one hq group is entered without naming a group');
    is($entered['user']['roles'], $roles, 'the token carries the roles of that group');

    $entered = User::login_as($multi, $gB);
    is($entered['success'], true, 'a multi-group row is entered through the group it named');
    is($entered['user']['roles'], $roles, 'the token carries the roles of the named group');

    $logged = UserLoginLog::find()->where(['user_id' => $multi, 'active' => 1])->one();
    ok($logged !== null, 'entering a row writes a login log');
    is((int) $logged->group_id, (int) $gB, 'the login log records the group that was named');

    $logged = UserLoginLog::find()->where(['user_id' => $mixed, 'active' => 1])->one();
    is($logged, null, 'a refused attempt writes no login log');

} finally {

    UserLoginLog::deleteAll(['user_id' => [$single, $multi, $mixed, $outsider]]);
    User::deleteAll(['id' => [$single, $multi, $mixed, $outsider]]);
    Group::deleteAll(['id' => [$gA, $gB, $gN, $gX, $gY]]);

}

echo "\n" . $checks . ' checks, ' . $failures . " failures\n";
exit($failures > 0 ? 1 : 0);
