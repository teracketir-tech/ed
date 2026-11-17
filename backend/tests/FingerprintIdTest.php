<?php

/**
 * The fingerprint id sequence: one number, issued once, never re-used.
 *
 * Covered rules:
 *   1. an enrolment takes MAX + 1 - never the lowest free gap below it, so
 *      ids 1,2,4,5,6,9 hand out 10 rather than 3
 *   2. a number freed by a removal is burned for good: the next enrolment
 *      skips it, and the user who lost it does not get it back either
 *   3. the sequence lives in `fingerprint_id_counter` and is seeded from the
 *      current MAX, so deleting a user row can never rewind it
 *
 * Needs `sql/hq-fingerprint-id-counter.sql` applied to the database first -
 * rule 3 fails without it (the code answers a refusal, never a wrong number).
 *
 * Run:  php frontend/modules/hq/tests/FingerprintIdTest.php
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

/** The stored fingerprint id of a row, or null - never a stale in-memory copy. */
function fp($id): ?int
{
    $row = User::findOne($id);

    return $row !== null && $row->fingerprint_id !== null ? (int) $row->fingerprint_id : null;
}

/** The counter's last issued number, or null while the table is not there. */
function counterLast(): ?int
{
    try {
        $row = Yii::$app->db->createCommand(
            'SELECT `last_id` FROM `fingerprint_id_counter` WHERE `id` = 1'
        )->queryOne();
    } catch (\Throwable $e) {
        return null;
    }

    return $row === false ? null : (int) $row['last_id'];
}

/** Group 21 carries 707/708 (enrol / remove), which is all this test calls. */
function actingAs($groupId)
{
    $identity = new User();
    $identity->id = 1500070;
    $identity->group_id = $groupId;
    Yii::$app->user->setIdentity($identity);
}

$marker = 'fpt' . substr(md5((string) microtime(true)), 0, 8);

$fixtures = [];

// The device queue allows ONE pending enrolment at a time, and the dev
// database may already hold one - stash it so the test never replaces a real
// row, and put the flag back whatever happens below.
$stashed = User::find()->where(['add_fingerid' => 2])->one();

try {

    if ($stashed !== null) {
        $stashed->add_fingerid = null;
        $stashed->save(false, ['add_fingerid']);
    }

    for ($i = 1; $i <= 4; $i++) {
        $user = new User();
        $user->first_name = $marker;
        $user->last_name = 'F' . $i;
        $user->phone_number = '0912' . str_pad((string) $i, 3, '0', STR_PAD_LEFT) . substr($marker, -4);
        $user->group_id = '1';

        ok($user->save(false) === true, "fixture user $i saved");
        $fixtures[] = $user->id;
    }

    [$f1, $f2, $f3, $f4] = $fixtures;

    $maxUser = (int) User::find()->max('fingerprint_id');
    $counter = counterLast();
    $base = max($maxUser, $counter ?? 0);

    // ---- rule 3: the sequence is a table of its own ------------------------

    ok($counter !== null, 'rule 3: the counter table exists');
    ok($counter !== null && $counter >= $maxUser, 'rule 3: the counter has not fallen behind the live ids');

    // A hole below the top: base+1 and base+3 are taken, base+2 is free. The
    // old gap-fill handed out base+2 (or anything smaller) from here on.
    User::updateAll(['fingerprint_id' => $base + 1], ['id' => $f1]);
    User::updateAll(['fingerprint_id' => $base + 3], ['id' => $f2]);

    is((int) User::find()->max('fingerprint_id'), $base + 3, 'the fixture hole is in place');

    // ---- rule 1: MAX + 1, the hole below stays free ------------------------

    actingAs(21);

    $result = User::fingerprint_add($f3);

    ok(($result['success'] ?? false) === true, 'rule 1: the enrolment is accepted');
    is(fp($f3), $base + 4, 'rule 1: the next number is MAX + 1');
    is(User::find()->where(['fingerprint_id' => $base + 2])->count(), 0, 'rule 1: the gap below is not filled');

    // ---- rule 2: the freed number is burned for good -----------------------

    // The replace path is what frees a number in production: the pending row
    // loses `fingerprint_id` when somebody else takes its place.
    $result = User::fingerprint_add($f4, true);

    ok(($result['success'] ?? false) === true, 'rule 2: the replacement is accepted');
    is(fp($f4), $base + 5, 'rule 2: the replacement takes MAX + 1, not the freed number');
    is(fp($f3), null, 'rule 2: the replaced row lost its number');
    is(User::find()->where(['fingerprint_id' => $base + 4])->count(), 0, 'rule 2: nobody holds the freed number');

    // The very same user asking again must not get its old number back.
    $result = User::fingerprint_add($f3, true);

    ok(($result['success'] ?? false) === true, 'rule 2: the old owner may enrol again');
    is(fp($f3), $base + 6, 'rule 2: the old owner does not get its number back');

    is(counterLast(), $base + 6, 'rule 3: the counter recorded every issued number');

} finally {

    User::deleteAll(['id' => $fixtures]);

    if ($stashed !== null) {
        $row = User::findOne($stashed->id);

        if ($row !== null) {
            $row->add_fingerid = 2;
            $row->save(false, ['add_fingerid']);
        }
    }

    Yii::$app->user->setIdentity(null);
}

echo "\n" . $checks . ' checks, ' . $failures . " failures\n";
exit($failures > 0 ? 1 : 0);
