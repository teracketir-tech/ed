<?php

/**
 * Issue 1: the daily-report timesheet tab runs on the shared fingerprint log
 * (`users_logs`) instead of a table of its own.
 * Issue 4: the timesheet hangs from the REPORT, not from an activity.
 * Issue 5: `users_logs.user_id` is `user.id`.
 *
 * What this proves, in order:
 *   1. wiring — the `users-log` route, the `UsersLog` model, old files gone,
 *      and the report-scoped create route
 *   2. stamping — `attach()` writes daily_report_id / company and leaves the
 *      activity stamp NULL (issue 4), the reason a plain line create was not
 *      enough
 *   3. `byDate()` — one day, unassigned rows plus this report's own rows,
 *      with the user name the picker needs (issue 1.2 / 5)
 *   4. a row claimed by another report is never stolen
 *   5. the manual row path still works end to end (issue 1.4)
 *   6. feedback 1 — deleting from the tab releases the log, never eats it
 *   7. deleting an activity leaves the report's attendance alone
 *   8. deleting the WHOLE report releases them too (the `DailyReport`
 *      hook — the database cascade skips the activity model event)
 *
 * Everything is written inside a transaction and rolled back, exactly like
 * ActivityLineTest, so the local database is left untouched.
 *
 * Run:  php frontend/modules/hq/tests/TimesheetServiceTest.php
 */

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\UsersLog;
use frontend\modules\hq\services\LineService;
use frontend\modules\hq\services\TimesheetService;

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
    global $failures, $checks;
    $checks++;
    echo ($condition ? '  ok   - ' : '  FAIL - ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

function is($actual, $expected, $label)
{
    ok($actual === $expected, "$label (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function attempt($fn)
{
    try {
        return $fn();
    } catch (\Throwable $e) {
        return 'exception:' . get_class($e);
    }
}

// ---------------------------------------------------------------------
// 1) wiring — the old table and its controller are gone, the log is wired in
// ---------------------------------------------------------------------
echo ">>> wiring\n";

is(UsersLog::tableName(), 'users_logs', 'model points at the users_logs table');
is(LineService::MODELS['timesheets'], UsersLog::class, 'MODELS maps timesheets to UsersLog');
is(LineService::model('timesheets'), UsersLog::class, "model('timesheets') returns the same class");
ok(!file_exists($module . '/models/DailyReportTimesheets.php'), 'the old DailyReportTimesheets model is gone');
ok(!file_exists($module . '/controllers/DailyReportTimesheetsController.php'), 'the old controller is gone');
ok(file_exists($module . '/models/UsersLog.php') && file_exists($module . '/controllers/UsersLogController.php'), 'the new model and controller exist');

$routes = (string) file_get_contents($module . '/config/routes.php');
ok(strpos($routes, "Route::crud('hq/users-log'") !== false, 'the hq/users-log route is registered');
ok(strpos($routes, "'GET by-date' => 'by-date'") !== false, 'by-date is registered as an extraPattern');
ok(strpos($routes, 'hq/daily-report-timesheets') === false, 'the old daily-report-timesheets route is gone');

$reportController = (string) file_get_contents($module . '/controllers/DailyReportController.php');
ok(strpos($reportController, 'log_ids') !== false, 'create-timesheets has the log_ids branch (issue 1.5)');

// Issue 4: the create route hangs from the report, not from an activity.
ok(strpos($routes, "'POST {id}/timesheets'") !== false, 'the report-scoped create route is registered');
ok(strpos($routes, "'POST activity/<activityId>/timesheets'") === false, 'the activity-scoped create route is gone');
ok(LineService::isReportScoped('timesheets') === true, 'the timesheet is report-scoped');
ok(LineService::isReportScoped('manpower') === false, 'manpower is still activity-owned');

// Issue 5: a log row resolves its person against `user`, never `contact`.
$userExist = null;
foreach ((new UsersLog())->rules() as $rule) {
    if (in_array('user_id', (array) $rule[0], true) && ($rule[1] ?? '') === 'exist') {
        $userExist = $rule;
    }
}
ok($userExist !== null && ($userExist['targetClass'] ?? null) === User::class, 'user_id must exist in the user table (issue 5)');

$timesheetSource = (string) file_get_contents($module . '/services/TimesheetService.php');
ok(strpos($timesheetSource, 'u.first_name') !== false, 'byDate reads the picker name from user (issue 5)');

$model = new UsersLog();
foreach (['user_id', 'checkindate', 'checkout_date', 'hour_normal', 'hour_extra', 'delay_minutes', 'extra_minutes', 'branch_id', 'daily_report_id', 'company_id', 'daily_report_activity_id'] as $column) {
    ok($model->hasAttribute($column), "column $column is on the model");
}

// Routing: the URL must reach the controller, and by-date must win over {id}.
$route = Yii::$app->createController('hq/users-log/by-date');
ok(is_array($route) && $route[0]->id === 'users-log' && $route[1] === 'by-date', 'hq/users-log/by-date reaches UsersLogController::actionByDate');
$oldRoute = Yii::$app->createController('hq/daily-report-timesheets');
ok(!is_array($oldRoute), 'the old URL no longer resolves to a controller');

// ---------------------------------------------------------------------
// 2) database behaviour — transaction, rolled back at the end
// ---------------------------------------------------------------------
echo ">>> database (transaction, rollBack at the end)\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21; // the group holding 2411..2414 and 2422
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

// Issue 5: every fixture row points at a real `user.id` — attach() validates it.
$fixtureUserId = (int) User::find()->select(['id'])->orderBy(['id' => SORT_ASC])->scalar();
ok($fixtureUserId > 0, 'fixture: the database has at least one user');

// The dates below sit inside a real plan window: task 59/60 of project 150 are
// planned for 2026-10-06 .. 2026-10-11, and an activity can no longer be
// recorded outside its window (item 15).
$report = new DailyReport(['project_id' => 150, 'report_date' => '2026-10-07', 'site_status' => 1]);
ok(attempt(fn () => $report->save()) === true, 'fixture: report is created');

$activity = new DailyReportActivity(['daily_report_id' => $report->id, 'task_id' => 59, 'daily_progress' => 10]);
ok(attempt(fn () => $activity->save()) === true, 'fixture: activity is created');

// A device row: written without app validation, exactly like the device does.
$deviceRow = new UsersLog([
    'user_id' => $fixtureUserId,
    'checkindate' => '2026-10-07 08:30:00',
    'checkout_date' => '2026-10-07 17:00:00',
    'hour_extra' => 1.5,
]);
ok(attempt(fn () => $deviceRow->insert(false)) === true, 'a device row saves without app validation');
ok(!$deviceRow->hasErrors(), 'saving a device row does not hit a created_at/updated_at error');

// 2-1) stamping — the whole point of the tab (issue 1.5 / 4)
$result = TimesheetService::attach($report->id, [$deviceRow->id]);
ok(($result['success'] ?? false) === true, 'attach() succeeds');
$deviceRow->refresh();
is((int) $deviceRow->daily_report_id, (int) $report->id, 'daily_report_id lands on the log row');
is($deviceRow->daily_report_activity_id, null, 'the row hangs from the report and never carries an activity (issue 4)');
is($deviceRow->company_id, $report->company_id, 'company_id is copied from the report');

// 2-2) by-date — this report's rows come back so the picker can mark them
Yii::$app->request->setQueryParams(['date' => '2026-10-07', 'daily_report_id' => $report->id]);
$byDate = TimesheetService::byDate();
$ids = array_column($byDate['data'] ?? [], 'id');
ok(($byDate['success'] ?? false) === true, 'byDate() succeeds');
ok(in_array($deviceRow->id, $ids, false), "this report's own row comes back (for re-ticking)");
ok(isset($byDate['data'][0]['person_name']), 'byDate carries the user name the picker shows');

// 2-3) another day must not leak into this one
$otherDay = new UsersLog(['user_id' => $fixtureUserId, 'checkindate' => '2026-10-10 08:00:00']);
ok(attempt(fn () => $otherDay->insert(false)) === true, 'a row of another day is saved');
$ids = array_column($byDate['data'] ?? [], 'id');
ok(!in_array($otherDay->id, $ids, false), 'byDate returns exactly that one day');

// 2-4) a row claimed by another report is never stolen
$reportB = new DailyReport(['project_id' => 150, 'report_date' => '2026-10-08', 'site_status' => 1]);
attempt(fn () => $reportB->save());

$stolen = TimesheetService::attach($reportB->id, [$deviceRow->id]);
ok(($stolen['success'] ?? false) === false, 'stealing a row of another report is refused');
ok(!empty($stolen['errors']), 'the refusal carries a reason in errors');
$deviceRow->refresh();
is((int) $deviceRow->daily_report_id, (int) $report->id, "the row's report is untouched");

$empty = TimesheetService::attach($reportB->id, []);
ok(($empty['success'] ?? false) === false, 'attach() with no ids is refused');

$missing = TimesheetService::attach($reportB->id, [999999999]);
ok(($missing['success'] ?? false) === false, 'an unknown id is refused');

// 2-5) the manual row (issue 1.4) goes through the ordinary line save
Yii::$app->request->setBodyParams([
    'user_id' => $fixtureUserId,
    'checkindate' => '2026-10-07 08:45:00',
    'checkout_date' => '2026-10-07 17:15:00',
    'hour_normal' => 8,
    'hour_extra' => 0,
    'delay_minutes' => 5,
    'extra_minutes' => 0,
]);
$manual = LineService::save(UsersLog::class, null, null, $report->id);
ok(($manual['success'] ?? false) === true, 'a manual row saves through the generic line save');
is((int) ($manual['data']->daily_report_id ?? 0), (int) $report->id, 'the manual row gets its report too');
is($manual['data']->daily_report_activity_id ?? 'x', null, 'the manual row is report-owned too');

// 2-6) the tab lists exactly this report's rows
Yii::$app->request->setQueryParams(['daily_report_id' => $report->id, 'per-page' => 0]);
$listed = LineService::index(UsersLog::class);
is((int) ($listed['totalCount'] ?? -1), 2, "the tab lists only this report's rows");

// ---------------------------------------------------------------------
// 3) feedback 1 — deleting from the tab RELEASES the log, never eats it
// ---------------------------------------------------------------------
echo ">>> delete from the tab releases the row (feedback 1)\n";

$manualId = (int) ($manual['data']->id ?? 0);
ok($manualId > 0, 'fixture: the manual row has an id to delete');

$released = LineService::delete(UsersLog::class, $manualId);
is($released['released'] ?? null, true, 'the server reports the row as released, not deleted');
is($released['success'] ?? false, true, 'the release is a success');

$stillThere = UsersLog::findOne($manualId);
ok($stillThere !== null, 'the device row still exists after "deleting" it');
is($stillThere->daily_report_activity_id, null, 'daily_report_activity_id is detached');
is($stillThere->daily_report_id, null, 'daily_report_id is detached');
is($stillThere->company_id, null, 'company_id is detached');
is((int) $stillThere->user_id, $fixtureUserId, 'the person on the row is untouched');
ok($stillThere->checkindate !== null && $stillThere->checkindate !== '', 'the attendance stamps are untouched');

// the row is free again, so by-date offers it to any report of that day
Yii::$app->request->setQueryParams(['date' => '2026-10-07']);
$freed = TimesheetService::byDate();
ok(in_array($manualId, array_column($freed['data'] ?? [], 'id'), false), 'the released row is back in the free pool');

// a release must not move a row owned by somebody else
$otherRelease = LineService::delete(UsersLog::class, $deviceRow->id);
is($otherRelease['released'] ?? null, true, 'the attached row is released too');

// ---------------------------------------------------------------------
// 4) deleting an activity must leave the report's attendance alone — since
//    issue 4 a log row hangs from the report, so an activity has nothing to
//    release and takes no row with it
// ---------------------------------------------------------------------
echo ">>> deleting an activity leaves the report's attendance alone\n";

$attachedRow = UsersLog::findOne($deviceRow->id);
ok($attachedRow !== null && $attachedRow->daily_report_id === null, 'fixture: the row is free before the activity is deleted');

// re-stamp it to the report, then delete one activity of that same report
TimesheetService::attach($report->id, [$deviceRow->id]);
$deviceRow->refresh();
is($deviceRow->daily_report_activity_id, null, 'fixture: the row is stamped without an activity');

$beforeDelete = (int) UsersLog::find()->where(['daily_report_id' => $report->id])->count();
ok($beforeDelete > 0, 'fixture: at least one log hangs from the report');

$deleted = \frontend\modules\hq\services\ActivityService::delete($activity->id);
ok(($deleted['success'] ?? false) === true, 'the activity is deleted');

is(DailyReportActivity::findOne($activity->id) === null, true, 'the activity row is gone');
is((int) UsersLog::find()->where(['daily_report_id' => $report->id])->count(), $beforeDelete, "the report's attendance is untouched");
$deviceRow->refresh();
is($deviceRow->daily_report_activity_id, null, 'a report-owned log never carries an activity stamp');
is((int) $deviceRow->daily_report_id, (int) $report->id, 'its report stamp is still there');

// ---------------------------------------------------------------------
// 3) deleting the WHOLE report must release the log too (the gap found by
//    the probe: fk_dra_report cascades in the database, which does NOT fire
//    DailyReportActivity::afterDelete)
// ---------------------------------------------------------------------
echo ">>> whole report delete\n";

$reportC = new DailyReport(['project_id' => 150, 'report_date' => '2026-10-09', 'site_status' => 1]);
ok(attempt(fn () => $reportC->save()) === true, 'fixture: report C is created');

$activityC = new DailyReportActivity(['daily_report_id' => $reportC->id, 'task_id' => 59, 'daily_progress' => 10]);
ok(attempt(fn () => $activityC->save()) === true, 'fixture: activity of report C is created');

$reportCId = $reportC->id;
$activityCId = $activityC->id;

$deviceRowC = new UsersLog(['user_id' => $fixtureUserId, 'checkindate' => '2026-10-09 08:30:00', 'checkout_date' => '2026-10-09 17:00:00']);
ok(attempt(fn () => $deviceRowC->insert(false)) === true, 'a second device row saves');

ok((TimesheetService::attach($reportCId, [$deviceRowC->id])['success'] ?? false) === true, 'attach() stamps it');
$deviceRowC->refresh();
is((int) $deviceRowC->daily_report_id, $reportCId, 'fixture: the row is stamped to report C');

// Deleting the header relies on the FK, so the activities go away WITHOUT the
// model event. The hook on DailyReport is the only thing that can release.
$deletedReport = \frontend\modules\hq\services\ReportService::delete($reportCId);
ok(($deletedReport['success'] ?? false) === true, 'the whole report is deleted');

is(DailyReport::findOne($reportCId) === null, true, 'the report header is gone');
is(DailyReportActivity::findOne($activityCId) === null, true, 'its activity went with it (DB cascade)');

ok(UsersLog::findOne($deviceRowC->id) !== null, 'the log row itself survived the report delete');
$deviceRowC->refresh();
is($deviceRowC->daily_report_activity_id, null, 'the activity stamp is NULL (never set, issue 4)');
is($deviceRowC->daily_report_id, null, 'the dangling report stamp was released');
is($deviceRowC->company_id, null, 'the company stamp was released too');

// The row must be back in that day's pool, i.e. visible to the picker again.
Yii::$app->request->setQueryParams(['date' => '2026-10-09', 'daily_report_id' => 0]);
$poolAgain = TimesheetService::byDate();
$poolIds = array_column($poolAgain['data'] ?? [], 'id');
ok(in_array($deviceRowC->id, $poolIds, false), 'the released row is offered by the picker again');

$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n>>> $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
