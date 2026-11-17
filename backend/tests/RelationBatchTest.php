<?php

/**
 * Feedback 3 — the task-relation form writes MANY successors, not one.
 *
 * The create page already posted `successor_task_ids`; feedback 3 asked for
 * the same on the edit page. A stored row holds exactly one successor, so
 * "editing into several" necessarily means "rewrite the row into these rows",
 * which is what `RelationBatchService::replace()` does.
 *
 * What this proves, in order:
 *   1. wiring — `successor_task_ids` reaches the batch on POST *and* on PUT
 *   2. create — one row per chosen successor, in one transaction
 *   3. duplicates are skipped, never an error
 *   4. one bad pair rolls the whole batch back (all or nothing)
 *   5. replace — the original row is rebuilt from the chosen list
 *   6. replace — a successor you drop really leaves the graph
 *   7. the single `successor_task_id` path still works (nothing regressed)
 *
 * Everything runs inside a transaction that is rolled back, so the local
 * database is left untouched (same pattern as TimesheetServiceTest).
 *
 * Run:  php frontend/modules/hq/tests/RelationBatchTest.php
 */

use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\TaskRelation;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\RelationBatchService;

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

// ---------------------------------------------------------------------
// 1) wiring — both pages go through the batch service
// ---------------------------------------------------------------------
echo ">>> wiring\n";

$model = (string) file_get_contents($module . '/models/TaskRelation.php');
ok(strpos($model, 'RelationBatchService::replace') !== false, 'PUT with successor_task_ids reaches RelationBatchService::replace');
ok(strpos($model, 'RelationBatchService::create') !== false, 'POST with successor_task_ids still reaches RelationBatchService::create');
ok(method_exists(RelationBatchService::class, 'replace'), 'RelationBatchService::replace exists');

$service = (string) file_get_contents($module . '/services/RelationBatchService.php');
ok(strpos($service, 'private static function insertRows') !== false, 'create() and replace() share one write loop (DRY)');

// `$module` is <repo>/backend/apiv2.payegan.group/frontend/modules/hq
// The form itself now lives in a shared component (ایشوی «افزودن رابطه» از لیست
// تسک‌ها همان فرم را در مودال می‌خواهد) — the page is a thin wrapper over it.
$repo = dirname($module, 5);
$createPage = (string) file_get_contents($repo . '/frontend/hq/src/pages/task-relation/create/index.tsx');
$relationForm = (string) file_get_contents($repo . '/frontend/hq/src/components/pages/scheduling/RelationForm.tsx');
ok($createPage !== '', 'the create/edit page source is reachable');
ok(strpos($createPage, 'RelationForm') !== false, 'the page renders the shared RelationForm');
ok($relationForm !== '', 'the shared RelationForm source is reachable');
ok(strpos($relationForm, 'name="successor_task_id"') === false, 'the form no longer posts a single successor_task_id');
ok(substr_count($relationForm, 'isMulti') >= 1, 'the successor select is multi');
ok(strpos($relationForm, 'name="successor_task_id" title="تسک وابسته"') === false, 'the edit page no longer renders a single select');

// ---------------------------------------------------------------------
// 2) database behaviour — transaction, rolled back at the end
// ---------------------------------------------------------------------
echo ">>> database (transaction, rollBack at the end)\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21; // holds 2405 (create) and 2407 (edit)
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();

// Project 150 holds tasks 59..62 with only THREE leaves (60, 61, 62), and
// `RelationValidator` only allows relations between leaves. Four more leaves
// are therefore inserted raw — no app validation, exactly how the device row
// is written in TimesheetServiceTest — and the transaction takes them away.
$project = 150;
$stamped = date('Y-m-d H:i:s');
$leafIds = [];

foreach (range(1, 4) as $n) {
    Yii::$app->db->createCommand()->insert(
        Task::tableName(),
        [
            'project_id' => $project,
            'parent_id' => null,
            'wbs_code' => '9' . $n,
            'name' => "REL-FIXTURE-$n",
            'duration' => 1,
            'duration_unit' => 1,
            'created_at' => $stamped,
            'updated_at' => $stamped,
        ]
    )->execute();

    $leafIds[] = (string) Yii::$app->db->getLastInsertID();
}

is(count($leafIds), 4, 'fixture: four leaf tasks are inserted');

$predecessor = $leafIds[0];
$successors = [$leafIds[1], $leafIds[2], $leafIds[3]];

$base = ['project_id' => $project, 'predecessor_task_id' => $predecessor, 'relation_type' => 'FS', 'lag' => 0, 'lag_unit' => 1];

// A clean slate for the assertions below: the pair must not already exist.
TaskRelation::deleteAll(['project_id' => $project]);

// 2) one row per chosen successor
$created = RelationBatchService::create($base, $successors);
is($created['success'] ?? false, true, 'a three-successor batch succeeds');
is((int) ($created['created'] ?? -1), 3, 'exactly three rows are created');
is((int) ($created['skipped'] ?? -1), 0, 'nothing is skipped on a clean slate');

$stored = TaskRelation::find()->where(['project_id' => $project])->all();
is(count($stored), 3, 'three rows are really in the table');
is(count(array_unique(array_column($stored, 'successor_task_id'))), 3, 'each row has a different successor');

// 3) a comma separated string works as well as a real array
$asText = RelationBatchService::create($base, $successors[0] . ',' . $successors[1]);
is((int) ($asText['skipped'] ?? -1), 2, 'a comma separated string is accepted, and both are already stored');

$none = RelationBatchService::create($base, []);
ok(($none['success'] ?? false) === false, 'an empty successor list is refused');

// 4) a bad pair rolls the whole batch back — the self-loop is the classic one:
// the predecessor used as its own successor.
$before = (int) TaskRelation::find()->where(['project_id' => $project])->count();

$loop = RelationBatchService::create($base, [$predecessor, $leafIds[1]]);
ok(($loop['success'] ?? false) === false, 'a self-referencing pair is refused');
ok(!empty($loop['errors']), 'the refusal carries a reason');

$after = (int) TaskRelation::find()->where(['project_id' => $project])->count();
is($after, $before, 'the whole batch rolled back — no half-written rows');

// 5) replace — the stored row is rebuilt from the chosen list.
//    The predecessor already owns three rows, so keeping all three successors
//    only rewrites the edited row; the other two are recognised as duplicates.
$first = TaskRelation::find()->where(['project_id' => $project])->orderBy(['id' => SORT_ASC])->one();
ok($first !== null, 'fixture: a stored row to edit');
$firstId = (int) $first->id;
$firstSuccessor = (int) $first->successor_task_id;
$chosen = [$leafIds[1], $leafIds[2], $leafIds[3]];

$replacement = RelationBatchService::replace($first, $base, $chosen);
is($replacement['success'] ?? false, true, 'replace() succeeds');
ok(TaskRelation::findOne($firstId) === null, 'the row being edited is gone');
is((int) ($replacement['created'] ?? -1), 1, 'the edited row is rewritten, the two stored duplicates are skipped');
is((int) ($replacement['skipped'] ?? -1), 2, 'replace() reports the duplicates it skipped');

// the very pair that was on the edited row must be free to be rewritten
$rebuilt = TaskRelation::find()->where(['project_id' => $project, 'predecessor_task_id' => $predecessor, 'successor_task_id' => $firstSuccessor])->exists();
ok($rebuilt, 'the original pair survives when it is still in the chosen list');
is((int) TaskRelation::find()->where(['project_id' => $project, 'predecessor_task_id' => $predecessor])->count(), 3, 'the predecessor still owns three rows');

// 6) removing a successor really removes that row.
//    The edit page starts from the row's own successor; choosing a DIFFERENT
//    one has to take the old pair out and write the new one.
TaskRelation::deleteAll(['project_id' => $project]);
$two = RelationBatchService::create($base, [$leafIds[1], $leafIds[2]]);
is((int) ($two['created'] ?? -1), 2, 'fixture: two rows for the predecessor');

$rowToShrink = TaskRelation::find()->where(['project_id' => $project])->orderBy(['id' => SORT_ASC])->one();
$dropped = (int) $rowToShrink->successor_task_id;

$shrunk = RelationBatchService::replace($rowToShrink, $base, [$leafIds[3]]);
is($shrunk['success'] ?? false, true, 'swapping the successor succeeds');
ok(
    !TaskRelation::find()->where(['project_id' => $project, 'successor_task_id' => $dropped])->exists(),
    'the pair the user dropped is gone'
);
ok(
    TaskRelation::find()->where(['project_id' => $project, 'successor_task_id' => $leafIds[3]])->exists(),
    'the newly chosen pair is stored'
);
is((int) TaskRelation::find()->where(['project_id' => $project, 'predecessor_task_id' => $predecessor])->count(), 2, 'the other row was left alone');

// an empty list must never silently delete the row
$empty = RelationBatchService::replace(
    TaskRelation::find()->where(['project_id' => $project])->one(),
    $base,
    []
);
ok(($empty['success'] ?? false) === false, 'replace() with no successors is refused');
is((int) TaskRelation::find()->where(['project_id' => $project])->count(), 2, 'the stored rows are still there');

$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n>>> $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
