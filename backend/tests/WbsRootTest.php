<?php

/**
 * Issue 2 — every project gets a root node with WBS code 0.
 *
 * Until now the first row of a project was an ordinary task numbered 1, so
 * nothing in the tree represented *the project itself* and the rollup had no
 * single node to hang off. The user asked for a first row that IS the project
 * (code 0, named after the project) with every task below it.
 *
 * The numbering is deliberately NOT rewritten: children of the node keep the
 * codes they already had (1, 1.1, 1.2), so no existing code shifts — that is
 * design option (b). The node is a summary by construction, therefore the CPM
 * network and every task relation ignore it.
 *
 * What this proves, in order:
 *   1. wiring — the helpers, the guards and the frontend constant exist
 *   2. ensureRoot() creates exactly one node, named after its project
 *   3. the node adopts the project's existing top-level tasks
 *   4. rebuildWbs() numbers the node 0 and leaves every other code untouched
 *   5. a task saved with an empty parent becomes a child of the node
 *   6. a row that slipped past the model is repaired by the rebuild
 *   7. the node cannot take part in a relation (summary => out of the network)
 *   8. deleting the node is refused — its FK would cascade the whole WBS away
 *
 * Everything runs inside a transaction that is rolled back, so the local
 * database is left untouched (same pattern as RelationBatchTest).
 *
 * Run:  php frontend/modules/hq/tests/WbsRootTest.php
 */

use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\TaskRelation;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\ProjectNodeService;
use frontend\modules\hq\services\RelationValidator;
use frontend\modules\hq\services\WbsService;

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

is(ProjectNodeService::ROOT_CODE, '0', 'the project node is numbered 0');
ok(method_exists(ProjectNodeService::class, 'rootOf'), 'ProjectNodeService::rootOf exists');
ok(method_exists(ProjectNodeService::class, 'ensureRoot'), 'ProjectNodeService::ensureRoot exists');
ok(method_exists(ProjectNodeService::class, 'isRoot'), 'ProjectNodeService::isRoot exists');

$taskSrc = (string) file_get_contents($module . '/models/Task.php');
ok(strpos($taskSrc, 'ProjectNodeService::isRoot') !== false, 'Task asks the service whether a row is the node');

$projectSrc = (string) file_get_contents($module . '/models/Project.php');
ok(strpos($projectSrc, 'ensureRoot') !== false, 'creating a project creates its node');

$optionsSrc = (string) file_get_contents(dirname($module, 5) . '/frontend/hq/src/components/pages/scheduling/options.ts');
ok(strpos($optionsSrc, 'ROOT_WBS_CODE') !== false, 'the frontend knows the node code by name');

// ---------------------------------------------------------------------------
echo ">>> behaviour\n";

$identity = new User();
$identity->id = 1500070;
$identity->group_id = 21;
Yii::$app->user->setIdentity($identity);

$tx = Yii::$app->db->beginTransaction();
$projectId = 150;

// sql/project-wbs-root.sql has already run locally, so undo it inside the transaction: node gone, its four children back at the top level. That restores the pre-migration shape and keeps ensureRoot tested as a creation rather than as a reuse.
$existing = ProjectNodeService::rootOf($projectId);
if ($existing !== null) {
    Task::updateAll(['parent_id' => null], ['parent_id' => $existing->id]);
    Task::deleteAll(['id' => $existing->id]);
}

// Codes as they are today, so "nothing shifted" stays measurable.
$before = [];
$tasks = Task::find()->where(['project_id' => $projectId])
    ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])->all();
foreach ($tasks as $task) {
    $before[$task->id] = (string) $task->wbs_code;
}
is(count($before), 5, 'fixture: project 150 holds five tasks');
ok(!in_array(ProjectNodeService::ROOT_CODE, $before, true), 'fixture: it has no project node yet');

// 2) ensureRoot -----------------------------------------------------------------
$root = ProjectNodeService::ensureRoot($projectId);
ok($root !== null, 'ensureRoot returns the node');
is((string) $root->wbs_code, ProjectNodeService::ROOT_CODE, 'the node carries code 0');
is($root->parent_id, null, 'the node has no parent');
is($root->name, Project::findOne($projectId)->name, 'the node is named after its project');
ok(ProjectNodeService::isRoot($root), 'isRoot recognises the node');

is(ProjectNodeService::ensureRoot($projectId)->id, $root->id, 'a second call reuses the same node');
is((int) Task::find()->where(['project_id' => $projectId, 'parent_id' => null])->count(), 1, 'exactly one top-level row');

// 3) the node adopted the former top-level tasks ---------------------------------
$adopted = Task::find()->where(['project_id' => $projectId, 'parent_id' => $root->id])->count();
is((int) $adopted, 4, 'the four former top-level tasks hang off the node');

// 4) rebuild keeps every existing code -------------------------------------------
WbsService::rebuildWbs($projectId);
$after = [];
foreach (Task::find()->where(['project_id' => $projectId])->all() as $task) {
    $after[$task->id] = (string) $task->wbs_code;
}
is($after[$root->id], ProjectNodeService::ROOT_CODE, 'the node is numbered 0 after a rebuild');
foreach ($before as $taskId => $code) {
    is($after[$taskId], $code, "task $taskId keeps its code ($code)");
}

// 5) an empty parent means "a new top-level task" => a child of the node ---------
$fresh = new Task();
$fresh->project_id = $projectId;
$fresh->name = 'ردیف تازه';
$fresh->sort_order = 99;
ok((bool) $fresh->save(), 'a task saved with an empty parent succeeds');
is($fresh->parent_id, $root->id, 'the empty parent becomes the project node');

// 6) a row that slipped past the model is repaired by the rebuild ---------------
Task::updateAll(['parent_id' => null], ['id' => $fresh->id]);
WbsService::rebuildWbs($projectId);
$fresh->refresh();
is($fresh->parent_id, $root->id, 'rebuildWbs pulls a stray top-level row under the node');
is((string) $fresh->wbs_code, '5', 'the repaired row is numbered after the existing four');

// 7) the node is a summary, so relations ignore it ------------------------------
$refused = new TaskRelation();
$refused->project_id = $projectId;
$refused->predecessor_task_id = $root->id;
$refused->successor_task_id = 60;
$refused->relation_type = 'FS';
$refused->lag = 0;
$refused->lag_unit = TaskRelation::UNIT_DAY;
ok(count(RelationValidator::pairErrors($refused)) > 0, 'the node cannot take part in a relation');

$allowed = new TaskRelation();
$allowed->project_id = $projectId;
$allowed->predecessor_task_id = 61;
$allowed->successor_task_id = 62;
$allowed->relation_type = 'FS';
$allowed->lag = 0;
$allowed->lag_unit = TaskRelation::UNIT_DAY;
is(count(RelationValidator::pairErrors($allowed)), 0, 'a pair of ordinary tasks is still valid');

// 8) deleting the node is refused -----------------------------------------------
$deleted = Task::_delete($root->id);
is($deleted['success'] ?? null, false, 'deleting the node is refused');
ok(Task::findOne($root->id) !== null, 'the node is still there');

// ---------------------------------------------------------------------------
$tx->rollBack();
Yii::$app->user->setIdentity(null);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
