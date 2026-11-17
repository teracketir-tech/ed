<?php

/**
 * Which side of a group save writes `created_in_hq`.
 *
 * Covered rules:
 *   1. a create marks the group - that is the whole point of the column
 *   2. the mark can never be switched off from the request: it is absent from
 *      `Group::rules()`, so `load()` treats it as unsafe and drops it
 *   3. an edit never changes the mark, in either direction - not off (2), and
 *      not on either, so a group another panel created cannot be promoted by
 *      editing it here
 *
 * This one writes on purpose: `_save()` is the code path under test. It only
 * ever touches rows whose name starts with the test prefix, and it deletes
 * them again on the way out.
 *
 * Run:  php frontend/modules/hq/tests/GroupCreatedInHqTest.php
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

/** Puts a request body in front of `_save()`, which reads `post()`. */
function post(array $body)
{
    Yii::$app->request->setBodyParams($body);
}

/** Reports whether `_save()` claimed success. */
function saved($result)
{
    return ($result['success'] ?? null) === true;
}

$prefix = '__hq_flag_test__';
$firstName = $prefix . '_one';
$secondName = $prefix . '_two';

// A run that died last time must not leave rows behind to confuse this one.
Group::deleteAll(['name' => [$firstName, $secondName]]);

// Rule 1 - a create marks the group.
post(['name' => $firstName, 'roles' => '[]', 'company_id' => null]);
$created = Group::_save();

ok(saved($created), 'a new group is saved');
$createdId = $created['data']->id ?? null;
ok($createdId !== null, 'the new group came back with an id');
ok((int) ($created['data']->created_in_hq ?? 0) === 1, 'a group created from this panel carries the mark');
ok((int) (Group::findOne(['id' => $createdId])->created_in_hq ?? 0) === 1, 'the mark really reached the database');

// Rule 2 - the request cannot choose the mark for itself.
post(['name' => $secondName, 'roles' => '[]', 'company_id' => null, 'created_in_hq' => 0]);
$spoofed = Group::_save();

ok(saved($spoofed), 'the second group is saved');
$spoofedId = $spoofed['data']->id ?? null;
ok($spoofedId !== null, 'the second group came back with an id');
ok((int) ($spoofed['data']->created_in_hq ?? 0) === 1, 'sending created_in_hq=0 in the body does not switch the mark off');
ok((int) (Group::findOne(['id' => $spoofedId])->created_in_hq ?? 0) === 1, 'and the database still holds 1');

// Rule 3, first half - an edit cannot switch an existing mark off.
post(['name' => $firstName . '_renamed', 'roles' => '[]', 'company_id' => null, 'created_in_hq' => 0]);
$edited = Group::_save($createdId);

ok(saved($edited), 'the marked group is edited');
ok($edited['data']->name === $firstName . '_renamed', 'the edit itself went through');
ok((int) ($edited['data']->created_in_hq ?? 0) === 1, 'an edit leaves the mark in place');

// Rule 3, second half - an edit cannot promote a group another panel created.
Group::updateAll(['created_in_hq' => 0], ['id' => $spoofedId]);

post(['name' => $secondName . '_renamed', 'roles' => '[]', 'company_id' => null, 'created_in_hq' => 1]);
$promoted = Group::_save($spoofedId);

ok(saved($promoted), 'the unmarked group is edited');
ok((int) ($promoted['data']->created_in_hq ?? 0) === 0, 'an edit never adds the mark either');
ok((int) (Group::findOne(['id' => $spoofedId])->created_in_hq ?? 0) === 0, 'the database still holds 0');

// A missing id is still refused, so the flag work changed nothing there.
post(['name' => $firstName, 'roles' => '[]', 'company_id' => null]);
$missing = Group::_save(999999999);
ok(($missing['success'] ?? null) === false, 'an unknown id is still refused');

Group::deleteAll(['name' => [$firstName, $firstName . '_renamed', $secondName, $secondName . '_renamed']]);
ok(Group::find()->where(['like', 'name', $prefix, false])->count() == 0, 'every test row was removed again');

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
