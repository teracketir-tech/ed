<?php

/**
 * The company picker that sits in front of an HQ login.
 *
 * Covered rules:
 *   1. step 1 takes the username and its password together - a request with
 *      no password never reaches the rows
 *   2. no user_id -> no row is named; `login()` then decides: one admitted
 *      row is signed into directly, several are handed to the picker, and a
 *      row is never guessed (the lowest id used to come back first, so the
 *      row of another panel was tested and the HQ row behind it never got
 *      the chance)
 *   3. a user_id this phone admits -> exactly that row; one outside the
 *      admitted rows, or a malformed one -> refused
 *   4. the option list only carries rows this panel admits: a row whose group
 *      `hq_group_is_allowed()` turns down is gone before the picker sees it,
 *      and a phone with nothing left is refused rather than shown empty
 *   5. every option carries row id + company id + company name; a row without
 *      a company carries no name, so the caller labels it itself
 *   6. the group rules are untouched: null, non numeric and dangling ids are
 *      all refused, a resolvable id that this panel created is allowed, and
 *      one that it did not create is refused
 *   7. the payload the front end stores as `auth` carries the company name
 *      behind the sidebar badge, and never the password hash
 *   8. the password is proved at step 1 against every admitted row: with one
 *      row the attempt goes straight on, with several a password matching
 *      none of them never reaches the picker
 *
 * Test data: phone 09127107619 holds three rows and admits two (see
 * PROJECT.md); 09105785301 admits exactly one. The two refusal fixtures are
 * stable whichever groups get marked - 09000000000 holds one row with a NULL
 * group, 09199999999 belongs to nobody.
 *
 * Everything here only reads the database. Every branch that succeeds writes
 * an auth token and a login log, so those are covered end to end over
 * `php -S` instead of being replayed here.
 *
 * Run:  php frontend/modules/hq/tests/LoginCompanySelectionTest.php
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

/** Reports whether a call was answered with the HTTP 401 the login uses. */
function refused($attempt, $label)
{
    try {
        $attempt();
        ok(false, $label);
    } catch (\yii\web\UnauthorizedHttpException $e) {
        ok(true, $label);
    } catch (\Throwable $e) {
        echo '        (' . get_class($e) . ': ' . $e->getMessage() . ")\n";
        ok(false, $label);
    }
}

/** Runs the login action against a body of parameters. */
function attempt(array $post)
{
    Yii::$app->request->setBodyParams($post);

    return User::login();
}

$phone = '09127107619';
$password = '123456';
$rows = User::findAll(['phone_number' => $phone]);

ok(count($rows) >= 2, 'the documented phone holds more than one row');

// Deployment guard, ahead of every rule below: without
// `sql/group-created-in-hq.sql` group 21 carries no mark, the gate refuses
// everybody, and nothing here can pass.
$panelGroupRow = Group::findOne(['id' => 21]);
ok($panelGroupRow !== null && isset($panelGroupRow->created_in_hq) && (int) $panelGroupRow->created_in_hq === 1, 'group 21 carries the mark, so the backfill ran');

$unmarkedGroupRow = Group::find()->andWhere(['created_in_hq' => 0])->one();
ok($unmarkedGroupRow !== null, 'this database still holds a group this panel did not create');

$ids = array_map(function ($row) {
    return (int) $row->id;
}, $rows);
$last = end($rows);

// Rule 1 - no row is named until the caller names it, however many there are.
ok(User::select_login_row($rows, null) === null, 'no user_id leaves the choice to login()');
ok(User::select_login_row([$rows[0]], null) === null, 'a phone with one row is not settled here either');
ok(User::select_login_row([$rows[0]], '') === null, 'an empty user_id behaves like no user_id');

// Rule 2 - a requested row is honoured.
ok(User::select_login_row($rows, $last->id) === $last, 'a requested user_id picks its own row');
ok(User::select_login_row($rows, (string) $last->id) === $last, 'a user_id sent as a string picks its own row');

// Rule 3 - nothing outside the admitted rows is reachable.
refused(function () use ($rows) {
    User::select_login_row($rows, '999999999');
}, 'a user_id belonging to another phone is refused');
refused(function () use ($rows) {
    User::select_login_row($rows, ['999999999']);
}, 'a user_id sent as a list is refused');

// Rule 4 - the option list the front end renders.
$expected = [];
foreach ($rows as $row) {
    if (User::hq_group_is_allowed($row->group_id, User::resolve_group($row->group_id))) {
        $expected[] = (int) $row->id;
    }
}

$options = User::login_company_options($rows);
$offered = array_column($options, 'user_id');

ok($offered === $expected, 'the picker offers exactly the rows this panel admits');
ok(count($expected) < count($rows), 'at least one row of this phone is turned down');
ok(!in_array(559, $offered, true), 'the row whose groups this panel did not create is not offered');

// The blind spot that let an empty picker ship: both sides above answer from
// the same gate, so they agree on "nothing" and stay green either way. The
// real database stores `user.group_id` as a JSON array, and the picker has to
// be proved to offer something on its own.
ok($offered !== [], 'the picker is not empty - a JSON array group_id is read as one');
ok($offered === array_values(array_intersect($ids, $offered)), 'the offered ids keep the order of the phone rows');

// ...and the filter really is the gate, not an accident of this one phone.
ok(User::login_company_options([
    (object) ['id' => 999901, 'company_id' => null, 'group_id' => '21'],
]) !== [], 'a row behind a marked group is offered');

ok(User::login_company_options([
    (object) ['id' => 999902, 'company_id' => 1, 'group_id' => (string) $unmarkedGroupRow->id],
]) === [], 'a row behind a group this panel did not create is never offered');

// Rule 5 - every offered row is labelled, and a company-less one is not.
$withoutCompany = 0;
foreach ($options as $option) {
    if ($option['company_id'] === null) {
        $withoutCompany++;
        ok($option['company_name'] === null, 'a row without a company carries no name');
    } else {
        ok($option['company_name'] !== null, 'a row with a company carries that company name');
    }
}
ok($withoutCompany >= 1, 'the offered rows include the account of this very panel');

// Rule 6 - the group_id rules still apply after a row was picked.
$missingGroup = 99999999;
ok(Group::findOne(['id' => $missingGroup]) === null, 'the id used below really has no group row');
ok(User::hq_group_is_allowed((string) $missingGroup, Group::findOne(['id' => $missingGroup])) === false, 'a group_id whose group row is gone is refused');
ok(User::hq_group_is_allowed(null, Group::findOne(['id' => 21])) === false, 'a null group_id is refused');
ok(User::hq_group_is_allowed('[' . $unmarkedGroupRow->id . ']', User::resolve_group('[' . $unmarkedGroupRow->id . ']')) === false, 'a JSON array group_id behind a group this panel did not create is refused');
ok(User::resolve_group('[' . $panelGroupRow->id . ']') !== null, 'a JSON array group_id is resolved to its own group');
ok(User::hq_group_is_allowed('21', $panelGroupRow) === true, 'a resolvable group_id that this panel created is allowed');
ok(User::hq_group_is_allowed((string) $unmarkedGroupRow->id, $unmarkedGroupRow) === false, 'a resolvable group_id that this panel did not create is refused');

// Rule 7 - the half of the login response the front end keeps as `auth`.
$panelRow = null;
$companyRow = null;
foreach ($rows as $row) {
    if ($row->company_id === null) {
        $panelRow = $row;
    } elseif ($companyRow === null) {
        $companyRow = $row;
    }
}

ok($panelRow !== null, 'the phone holds an account of this very panel');
ok($companyRow !== null, 'the phone holds a company account');

$panelData = User::login_user_data($panelRow, $panelGroupRow);

ok(array_key_exists('company_name', $panelData), 'the payload carries a company name key');
ok($panelData['company_name'] === null, 'an account of this panel carries no name, the front end labels it itself');
ok(isset($panelData['roles']), 'the payload carries the roles of its group');
ok(!isset($panelData['password_hash']), 'the payload never carries the password hash');
ok(!isset($panelData['auth_token']), 'payload never carries the auth token');

$companyData = User::login_user_data($companyRow, $panelGroupRow);

ok($companyData['company_name'] !== null, 'a company account carries the name of its company');
ok($companyData['company_name'] === User::login_company_name($companyRow->company_id), 'that name is the one the picker shows for the same row');

ok(User::login_company_name(null) === null, 'a null company_id has no name');
ok(User::login_company_name('') === null, 'an empty company_id has no name');
ok(User::login_company_name(999999999) === null, 'an unknown company_id has no name');

// Rule 8 - step 1 takes the username and its password together.
refused(function () use ($phone) {
    attempt(['phone_number' => $phone]);
}, 'a request with no password never reaches the rows');

// Several admitted rows and the password holds: the picker, and no token
// until one of them is picked.
$response = attempt(['phone_number' => $phone, 'password' => $password]);

ok(($response['success'] ?? null) === false, 'an unpicked phone does not log in');
ok(($response['requires_company_selection'] ?? null) === true, 'the phone is asked to pick a company');
ok(!isset($response['token']), 'no token is handed out before the choice');
ok(array_key_exists('companies', $response), 'the answer carries the company list');

$actionOffered = array_column($response['companies'], 'user_id');
ok($actionOffered === $expected, 'the action offers exactly the rows this panel admits');
ok(!in_array(559, $actionOffered, true), 'the action never offers the row whose group row is gone');
ok(count($actionOffered) >= 2, 'this phone really does offer more than one company');

// ...and a password none of those rows accepts never reaches the picker.
refused(function () use ($phone) {
    attempt(['phone_number' => $phone, 'password' => 'definitely-not-the-password']);
}, 'a password matching no admitted row is refused before the picker');

// A phone with exactly one admitted row never becomes a picker: the wrong
// password is answered as a plain failure, not with a company list. The
// matching-password half of that branch writes a token, so it is covered
// end to end instead.
refused(function () {
    attempt(['phone_number' => '09105785301', 'password' => 'definitely-not-the-password']);
}, 'a phone with one admitted company never offers a picker');

// Naming a row the panel refuses is refused like any other bad credential.
refused(function () use ($phone, $password) {
    attempt(['phone_number' => $phone, 'password' => $password, 'user_id' => 559]);
}, 'a user_id the panel does not admit is refused');
refused(function () use ($phone) {
    attempt(['phone_number' => $phone, 'password' => 'definitely-not-the-password', 'user_id' => 1500070]);
}, 'a picked row with the wrong password is refused');

// A phone with nothing to offer is refused outright, never an empty picker.
refused(function () {
    attempt(['phone_number' => '09000000000', 'password' => '123456']);
}, 'a phone whose only row carries no group is refused, not listed as an empty picker');
refused(function () {
    attempt(['phone_number' => '09199999999', 'password' => '123456']);
}, 'a phone nobody holds is refused');
refused(function () use ($phone, $password) {
    attempt(['phone_number' => $phone, 'password' => $password, 'user_id' => ['1']]);
}, 'a malformed user_id is refused');
refused(function () use ($phone, $password) {
    attempt(['phone_number' => $phone, 'password' => $password, 'user_id' => 999999999]);
}, 'a user_id outside the phone is refused');
refused(function () {
    attempt([]);
}, 'a request without a phone is refused');

// Rule 9 - the login row has to be writable. `user.group_id` is a JSON array
// while `user_login_logs.group_id` is an integer with an `integer` rule, and
// a row that fails validation is never written - which then makes every later
// request answer "invalid token", because that half looks the row up by token.
$probe = new \frontend\modules\hq\models\UserLoginLog();
$probe->load([
    'user_id' => (int) $panelRow->id,
    'group_id' => $panelRow->group_id,
    'active' => 1,
    'token' => 'validation-probe',
], '');
$probe->validate();
ok($probe->hasErrors('group_id'), 'the raw JSON group_id fails the integer rule, so it could never be written');

$probe->group_id = (int) $panelGroupRow->id;
$probe->validate();
ok(!$probe->hasErrors('group_id'), 'the resolved group id is accepted, so the login row is written');
ok((int) $panelGroupRow->id === (int) User::resolve_group($panelRow->group_id)->id, 'the id written is the very group the gate resolved');

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
