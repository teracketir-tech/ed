<?php

/**
 * Feedback round — the three rules the browser testing exposed (items 6 / 12 / 15).
 *
 *   15. a task cannot be recorded on a day outside its plan window. The list
 *       already filtered by that window, so the form was the one hole: «تخریب»
 *       could be written into a report dated before its own start.
 *   12. saving the header with unchanged project/date used to look like a
 *       reschedule, which wiped the tombstones and resurrected activities the
 *       user had deleted. `isAttributeChanged` compares strictly and the form
 *       posts strings, so "150" !== 150.
 *    6. a multi-successor relation is several rows in `task_relation` but ONE
 *       line in the list, so deleting it has to take the whole group.
 *
 * Real data used here: project 150 starts 2026-10-06, draft report 883 is
 * dated 2026-10-06, task 60 (1.1) covers 10-06 .. 10-11 and task 61 (3) only
 * opens on 10-14.
 */

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

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportActivityRemoved;
use frontend\modules\hq\models\TaskRelation;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\ActivityService;
use frontend\modules\hq\services\RelationBatchService;
use frontend\modules\hq\services\ReportService;
use frontend\modules\hq\services\ReportSnapshot;
use frontend\modules\hq\services\ReportableTasks;

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $checks, $failures;
    $checks++;
    echo ($condition ? "  ok   - " : "  FAIL - ") . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function is($actual, $expected, $label)
{
    ok($actual === $expected, $label);
}

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21;
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

    // Clear CPM cache to ensure fresh CPM calculation with fixture data
    \frontend\modules\hq\services\ReportSnapshot::clearCache();

    try {
    // -------------------------------------------------------------------------
    echo ">>> wiring\n";

    ok(method_exists(ReportableTasks::class, 'allows'), 'ReportableTasks::allows exists');
    ok(method_exists(DailyReportActivity::class, 'validateTask'), 'the activity model validates task_id');
    ok(method_exists(ActivityService::class, 'delete'), 'ActivityService::delete exists');
    ok(method_exists(TaskRelation::class, 'groupSuccessorIds'), 'TaskRelation::groupSuccessorIds exists');
    ok(method_exists(TaskRelation::class, 'groupRows'), 'TaskRelation::groupRows exists');

    $window = ReportSnapshot::cpmRow(150, 61);
    is($window['start_date'] ?? null, '2026-10-14', 'fixture: task 61 only opens on 2026-10-14');

    // -------------------------------------------------------------------------
    echo ">>> an activity outside its plan window is refused (item 15)\n";

    $report = DailyReport::findOne(883);
    ok($report !== null, 'fixture: draft report 883 exists');
    is($report->report_date, '2026-10-06', 'and it is dated 2026-10-06');

    ok(ReportableTasks::allows(150, 60, '2026-10-06'), 'task 60 may be reported on that day');
    ok(!ReportableTasks::allows(150, 61, '2026-10-06'), 'task 61 may not — it has not started yet');
    ok(ReportableTasks::allows(150, 60, '2026-10-12'), 'a task past its finish but not done is still reportable');

    $tooEarly = new DailyReportActivity();
    $tooEarly->daily_report_id = 883;
    $tooEarly->task_id = 61;
    $tooEarly->daily_progress = 0;
    ok(!$tooEarly->save(), 'saving that activity fails');
    ok(strpos(implode(' ', $tooEarly->getErrors('task_id')), 'پنجره') !== false, 'the message points at the plan window');

    $onTime = new DailyReportActivity();
    $onTime->daily_report_id = 883;
    $onTime->task_id = 60;
    $onTime->daily_progress = 0;
    ok((bool) $onTime->save(), 'an activity inside its window still saves');

    // -------------------------------------------------------------------------
    echo ">>> a plain header save keeps a deleted activity deleted (item 12)\n";

    is((int) DailyReportActivity::find()->where(['daily_report_id' => 883])->count(), 1, 'the draft holds that one row');

    $deleted = ActivityService::delete($onTime->id);
    ok(!empty($deleted['success']), 'the row is deleted');
    $blocked = DailyReportActivityRemoved::blockedTaskIds(883);
    is(count($blocked), 1, 'and leaves a tombstone');
    is((int) ($blocked[0] ?? 0), 60, 'for the right task');

    // exactly what the header form posts back: strings, and only if unchanged
    Yii::$app->request->setBodyParams([
        'project_id' => (string) $report->project_id,
        'report_date' => (string) $report->report_date,
        'company_id' => '',
        'document_no' => '',
        'revision' => '',
    ]);
    $saved = ReportService::save(883);
    ok(!empty($saved['success']), 'the header saves');

    is(count(DailyReportActivityRemoved::blockedTaskIds(883)), 1, 'an unchanged save keeps the tombstone');
    is((int) DailyReportActivity::find()->where(['daily_report_id' => 883])->count(), 0, 'so reopening does not resurrect it');

    ReportService::view(883);
    // the day plans TWO tasks; only the tombstoned one has to stay down
    is((int) DailyReportActivity::find()->where(['daily_report_id' => 883, 'task_id' => 60])->count(), 0, 'and the view does not resurrect the deleted one');
    is((int) DailyReportActivity::find()->where(['daily_report_id' => 883])->count(), 1, 'while the other task of that day is filled in as usual');

    // -------------------------------------------------------------------------
    echo ">>> one multi-successor relation is one row to delete (item 6)\n";

    $before = (int) TaskRelation::find()->where(['project_id' => 150])->count();

    // 62 already carries a FS/0 line (to 85), so this batch hangs two FRESH
    // successors off 60 with lag 0 — its only other line is FS/3, a different
    // group key. The group is (predecessor, type, lag), which is what _delete
    // has to walk; the whole run is rolled back, so nothing is kept.
    $batch = RelationBatchService::create(
        ['project_id' => 150, 'predecessor_task_id' => 60, 'relation_type' => 'FS', 'lag' => 0, 'lag_unit' => 1],
        [62, 85]
    );
    ok(!empty($batch['success']), 'a two-successor relation is created');
    is((int) ($batch['created'] ?? 0), 2, 'as two rows');

    $group = [
        'project_id' => 150,
        'predecessor_task_id' => 60,
        'relation_type' => 'FS',
        'lag' => 0,
    ];

    $head = TaskRelation::find()->where($group)->orderBy(['id' => SORT_ASC])->one();
    ok($head !== null, 'the row to delete is found');
    is(count(TaskRelation::groupSuccessorIds($head)), 2, 'its group holds both successors');
    is(count(TaskRelation::groupRows($head)), 2, 'and both physical rows');

    $deleted = TaskRelation::_delete($head->id);
    ok(!empty($deleted['success']), 'deleting the line succeeds');
    is((int) TaskRelation::find()->where($group)->count(), 0, 'the whole group goes, not just one row');
    is((int) TaskRelation::find()->where(['project_id' => 150])->count(), $before, 'the other relations are untouched');
} finally {
    if ($tx->getIsActive()) {
        $tx->rollBack();
    }
    Yii::$app->user->setIdentity(null);
}

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
