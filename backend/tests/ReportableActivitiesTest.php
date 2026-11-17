<?php

/**
 * Issue 3 — the daily report lists the activities planned for that day itself.
 *
 * The «فعالیت‌ها» tab opened on an empty list that the user had to fill by hand,
 * from memory, one WBS entry at a time. A task's planned window already exists
 * in the CPM, so the report can list them itself.
 *
 * The rules the user fixed for this round:
 *   1. a task is reportable when `start <= report_date <= finish`
 *   2. LEAVES only — a summary parent is not an activity (1.1, never 1)
 *   3. the rows are created, not merely suggested
 *   4. a task already recorded in ANOTHER report that day still appears
 *   5. the stored plan snapshot follows the project start date when it moves
 *   6. a task past `finish` but not done yet still appears — a delay has to be
 *      reportable too (feedback 7.2)
 *
 * Real data used here: project 150 starts 2026-10-06 and holds
 *   60 (1.1)  window 10-06 .. 10-11   <- covers 10-06
 *   62 (2)    window 10-06 .. 10-21   <- covers 10-06 too (no predecessor)
 *   61 (3)    window 10-14 .. 11-13
 *   85 (4)    window 10-21 .. 10-28
 * with the chain 60->61 FS 3, 61->85 SS 3, 62->85 FS 0. Report 882 (same day)
 * already holds task 60; report 883 is a draft with no activities at all — which
 * is exactly the case rule 4 describes.
 *
 * Everything runs inside a transaction that is rolled back.
 *
 * Run:  php frontend/modules/hq/tests/ReportableActivitiesTest.php
 */

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\ReportActivityFiller;
use frontend\modules\hq\services\ReportableTasks;
use frontend\modules\hq\services\ReportSnapshot;

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

/** کلیدهای id در خروجیِ فیلتر، به‌صورت عدد */
function idsOf(array $rows): array
{
    return array_map(function ($row) {
        return (int) (is_array($row) ? $row['id'] : $row->id);
    }, $rows);
}

// ---------------------------------------------------------------------------
echo ">>> wiring\n";

ok(method_exists(ReportableTasks::class, 'filter'), 'ReportableTasks::filter exists');
ok(method_exists(ReportableTasks::class, 'forReport'), 'ReportableTasks::forReport exists');
ok(method_exists(ReportActivityFiller::class, 'fill'), 'ReportActivityFiller::fill exists');
ok(method_exists(ReportSnapshot::class, 'refreshProject'), 'ReportSnapshot::refreshProject exists');

$controller = (string) file_get_contents($module . '/controllers/ProjectController.php');
ok(strpos($controller, 'report_date') !== false, 'reportable-tasks accepts a report_date');

// ---------------------------------------------------------------------------
echo ">>> filter\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21;
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

$projectId = 150;
$onDay = ReportableTasks::forReport($projectId, '2026-10-06');

// rule 1 + rule 2: only leaves whose window covers the day — 60 (10-06..10-11)
// and 62 (10-06..10-21) both open on the project's very first day.
is(count($onDay), 2, 'two activities are planned on 2026-10-06');
is($onDay[0]['id'] ?? null, 60, 'the first is task 1.1 (id 60)');
is($onDay[0]['cpm']['start_date'] ?? null, '2026-10-06', 'its window opens on the report date');
is($onDay[0]['cpm']['finish_date'] ?? null, '2026-10-11', 'and closes five days later');
is($onDay[1]['id'] ?? null, 62, 'the second is task 2 (id 62), which has no predecessor');
is($onDay[1]['cpm']['finish_date'] ?? null, '2026-10-21', 'its fifteen-day window closes on the 21st');

$noSummary = array_filter($onDay, function ($row) {
    $cpm = is_array($row) ? $row : $row->toArray();
    return empty($cpm['cpm']['is_summary']);
});
is(count($noSummary), 2, 'no summary parent slips in (rule 2)');

is(count(ReportableTasks::forReport($projectId, '2026-10-06')), 2, 'a mid-window date still matches');
is(count(ReportableTasks::forReport($projectId, '2026-10-11')), 2, 'the closing day still matches (inclusive)');
$afterWindow = ReportableTasks::forReport($projectId, '2026-10-12');
is(count($afterWindow), 2, 'the day after the window still reports it (rule 6)');
is($afterWindow[0]['id'] ?? null, 60, 'because it is past its finish and not done yet');
is(count(ReportableTasks::forReport($projectId, '2026-01-01')), 0, 'a day before the project starts matches nothing');

// ---------------------------------------------------------------------------
echo ">>> fill\n";

$report = DailyReport::findOne(883);
ok($report !== null, 'fixture: draft report 883 exists');
is((int) DailyReportActivity::find()->where(['daily_report_id' => 883])->count(), 0, 'fixture: it starts empty');

is((int) ReportActivityFiller::fill($report), 2, 'fill creates the two planned activities (rule 3)');

$added = DailyReportActivity::find()->where(['daily_report_id' => 883, 'task_id' => 60])->one();
is($added ? (int) $added->task_id : 0, 60, 'the created row is task 60');
is((float) $added->daily_progress, 0.0, 'a fresh row starts at 0% (daily_progress is required)');
is($added->planned_start, '2026-10-06', 'the row carries the planned window');
is($added->planned_finish, '2026-10-11', 'including its finish');

// rule 4: task 60 is already recorded in report 882 on the very same day
is((int) DailyReportActivity::find()->where(['daily_report_id' => 882, 'task_id' => 60])->count(), 1, 'fixture: 882 already holds it');
is((int) DailyReportActivity::find()->where(['daily_report_id' => 883, 'task_id' => 60])->count(), 1, 'and 883 gets it too (rule 4)');

// idempotent: filling again must not double up
is((int) ReportActivityFiller::fill($report), 0, 'filling again adds nothing');
is((int) DailyReportActivity::find()->where(['daily_report_id' => 883])->count(), 2, 'still exactly two rows');

// ---------------------------------------------------------------------------
echo ">>> snapshots follow the start date (rule 5)\n";

$project = Project::findOne($projectId);
$project->start_date = '2026-02-02';
ok((bool) $project->save(), 'the project start date moves');

// both reports of that day hold a snapshot for task 60, so both must move
$tracked = (int) DailyReportActivity::find()
    ->alias('dra')
    ->innerJoin('daily_reports dr', 'dr.id = dra.daily_report_id')
    ->where(['dr.project_id' => $projectId])
    ->count();
ok($tracked >= 2, 'the project holds at least the two snapshots of that day');
is((int) ReportSnapshot::refreshProject($projectId), $tracked, 'every stored snapshot of that project is refreshed');
is($added->refresh() ? $added->planned_start : null, '2026-02-02', 'the stored planned_start moved with the project');
is($added->planned_finish, '2026-02-07', 'and so did the finish');

$older = DailyReportActivity::findOne(563);
is($older->planned_start, '2026-02-02', 'the snapshot in the OTHER report (882) moved too');

// the day the activities are planned on moves with it
$moved60 = null;
foreach (ReportableTasks::forReport($projectId, '2026-10-06') as $row) {
    $data = is_array($row) ? $row : $row->toArray();
    if ((int) ($data['id'] ?? 0) === 60) {
        $moved60 = $data;
    }
}
is($moved60['cpm']['start_date'] ?? null, '2026-02-02', 'the old day sits past the window now, not inside it');
is(count(ReportableTasks::forReport($projectId, '2026-02-02')), 2, 'the new opening day is');

// ---------------------------------------------------------------------------
$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
