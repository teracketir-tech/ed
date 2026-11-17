<?php

/**
 * Issue 1 — a real project start date is the only scheduling anchor.
 *
 * Every planned date used to be `project.start = project.created_at`, i.e. "the
 * day this row happened to be inserted". That is not a schedule: a project is
 * entered into the system long before or after it actually starts. The user's
 * rule for this round is explicit — «دیگر هیچ‌جا برای تسک و گزارش روز از تاریخ
 * ایجاد پروژه استفاده نکن؛ فقط تاریخ شروع».
 *
 * What this proves, in order:
 *   1. wiring — the column exists, the model accepts it, and CpmService no
 *      longer mentions `created_at` at all (that is the requirement, encoded)
 *   2. a task's dates follow `start_date`, NOT `created_at` — the two differ
 *      by nine months on project 150, so the assertion cannot pass by accident
 *   3. moving `start_date` moves every planned date with it
 *   4. an empty picker value becomes NULL rather than the string '' (a DATE
 *      column would reject it)
 *
 * Storage is Gregorian ISO (`Y-m-d`); the Shamsi <-> Gregorian conversion
 * belongs to the frontend picker, as elsewhere in this module.
 *
 * Everything runs inside a transaction that is rolled back, so the local
 * database is left untouched (same pattern as WbsRootTest).
 *
 * Run:  php frontend/modules/hq/tests/ProjectStartDateTest.php
 */

use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\CpmService;

$backend = dirname(__DIR__, 4);
$module = dirname(__DIR__);

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
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label\n";
    }
}

function is($actual, $expected, $label)
{
    ok($actual === $expected, $label);
}

// ---------------------------------------------------------------------------
echo ">>> wiring\n";

$column = Yii::$app->db->getTableSchema('project')->getColumn('start_date');
ok($column !== null, 'project.start_date exists');
is($column ? $column->dbType : null, 'date', 'start_date is a DATE column (Gregorian ISO in storage)');

$rules = (string) file_get_contents($module . '/models/Project.php');
ok(strpos($rules, 'start_date') !== false, 'Project accepts start_date');

// The requirement itself: the scheduling anchor must not be created_at anywhere.
$cpmSrc = (string) file_get_contents($module . '/services/CpmService.php');
ok(strpos($cpmSrc, 'created_at') === false, 'CpmService never mentions created_at');
ok(strpos($cpmSrc, 'start_date') !== false, 'CpmService anchors on start_date');

// ---------------------------------------------------------------------------
echo ">>> behaviour\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21;
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

$projectId = 150;
$project = Project::findOne($projectId);
ok($project !== null, 'fixture: project 150 exists');

// created_at is 2026-10-06; anchoring nine months earlier cannot be faked by it.
$project->start_date = '2026-01-01';
ok((bool) $project->save(), 'start_date is mass-assignable and saves');

$cpm = CpmService::calculate($projectId);
is($cpm['success'] ?? false, true, 'CPM still calculates');

$rows = $cpm['tasks'];
ok(isset($rows[60]), 'fixture: task 1.1 (id 60) is in the map');

// Fixture: project 150 has three relations — 60->61 FS lag 3, 61->85 SS lag 3,
// 62->85 FS lag 0. 62 has no predecessor so it starts with the project, and 85
// waits on BOTH 61 (SS+3 = 11) and 62 (FS+0 = 15), so 62's late finish wins:
//   60: ES 0, EF 5 | 62: ES 0, EF 15 | 61: ES 8, EF 38 | 85: ES 15, EF 22
// Anchoring nine months away from created_at moves the WHOLE schedule, which is
// a stronger proof than moving a set of independent tasks would be.
$expected = [
    60 => ['2026-01-01', '2026-01-06'],
    62 => ['2026-01-01', '2026-01-16'],
    61 => ['2026-01-09', '2026-02-08'],
    85 => ['2026-01-16', '2026-01-23'],
];

foreach ($expected as $taskId => $dates) {
    is($rows[$taskId]['start_date'], $dates[0], "task $taskId starts on start_date (+ its own offset)");
    is($rows[$taskId]['finish_date'], $dates[1], "task $taskId finishes its duration later");
}

is($rows[60]['start_date'] === '2026-10-06', false, 'the old created_at anchor really is gone');

// 3) moving the start date moves every planned date
$project->start_date = '2027-06-15';
ok((bool) $project->save(), 'start_date can be moved');

$moved = CpmService::calculate($projectId)['tasks'];
is($moved[60]['start_date'], '2027-06-15', 'moving start_date moves the whole schedule');
is($moved[60]['finish_date'], '2027-06-20', 'the finish moves with it');
is($moved[85]['start_date'], '2027-06-30', 'the last link of the chain moves too');

// 4) an empty picker value becomes NULL, not the string ''
$project->start_date = '';
$project->validate();
is($project->start_date, null, "an empty value becomes NULL, not ''");

$project->start_date = 'not-a-date';
ok(!$project->validate(), 'a malformed date is rejected');

// ---------------------------------------------------------------------------
$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
