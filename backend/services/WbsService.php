<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Task;

/**
 * منطق ساختار درختی WBS: پیمایش، بازسازی کد و شمارش زیرمجموعه‌ها
 */
class WbsService
{
    /** حداکثر پیمایش هنگام بازسازی کد WBS (محافظت در برابر داده خراب) */
    private const MAX_WALK = 10000;

    /** لیست تسک‌های یک پروژه، به ترتیب سلسله‌مراتبی */
    public static function findProjectTasks($projectId)
    {
        return Task::find()
            ->where(['project_id' => $projectId])
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * لیست تسک‌ها با فیلترهای لیست، مرتب‌شده اول بر اساس پروژه و بعد به ترتیب
     * سلسله‌مراتبی WBS تا نمای درختی از دل صفحه‌بندی هم درست درآید.
     *
     * parent: 'root' یعنی تسک‌های ریشه، عدد یعنی فرزندان مستقیم آن تسک.
     */
    public static function findFiltered(array $params, $projectId = null)
    {
        $query = Task::find()->with('project');

        if ($projectId) {
            $query->andWhere(['project_id' => $projectId]);
        }

        if (!empty($params['company_id'])) {
            $query->andWhere(['company_id' => (int) $params['company_id']]);
        }

        if (!empty($params['duration_unit'])) {
            $query->andWhere(['duration_unit' => (int) $params['duration_unit']]);
        }

        if (isset($params['parent']) && $params['parent'] !== '') {
            $query->andWhere($params['parent'] === 'root' ? ['parent_id' => null] : ['parent_id' => (int) $params['parent']]);
        }

        if (!empty($params['name'])) {
            $query->andWhere(['like', 'name', $params['name']]);
        }

        if (!empty($params['wbs_code'])) {
            $query->andWhere(['like', 'wbs_code', $params['wbs_code']]);
        }

        $rows = $query->all();

        usort($rows, function ($a, $b) {
            return ($a->project_id <=> $b->project_id)
                ?: (self::compareWbs($a->wbs_code, $b->wbs_code) ?: ($a->id <=> $b->id));
        });

        return $rows;
    }

    /** مقایسه عددی کدهای WBS تا 1.2 قبل از 1.10 و والد قبل از فرزندش قرار گیرد */
    public static function compareWbs($a, $b)
    {
        $pa = explode('.', (string) $a);
        $pb = explode('.', (string) $b);
        $len = max(count($pa), count($pb));

        for ($i = 0; $i < $len; $i++) {
            if (!isset($pa[$i])) {
                return -1;
            }

            if (!isset($pb[$i])) {
                return 1;
            }

            if ($pa[$i] !== $pb[$i]) {
                return (int) $pa[$i] <=> (int) $pb[$i];
            }
        }

        return 0;
    }

    /**
     * کدهای WBS کل پروژه را از نو می‌سازد.
     *
     * گرهٔ پروژه (کد 0) صاحبِ کدِ 0 است و فرزندانش همان شماره‌گذاریِ تاریخی را
     * می‌گیرند (1 ، 1.1 ، 1.2) تا هیچ کدِ موجودی جابه‌جا نشود. گره همچنین تنها
     * ردیفِ ریشه است؛ هر ردیفی که بی‌والد مانده باشد زیرش کشیده می‌شود.
     *
     * بعد از هر افزودن / ویرایش / حذف تسک فراخوانی می‌شود.
     */
    public static function rebuildWbs($projectId)
    {
        $children = [];
        $rootId = null;

        foreach (self::findProjectTasks($projectId) as $task) {
            if (!$task->parent_id && ProjectNodeService::isRoot($task)) {
                $rootId = $task->id;
                continue;
            }

            $children[$task->parent_id ?: 0][] = $task;
        }

        // گره تنها ردیفِ ریشه است؛ هر بی‌والدِ دیگری زیرش می‌نشیند.
        if ($rootId && !empty($children[0])) {
            $children[$rootId] = array_merge($children[$rootId] ?? [], $children[0]);

            foreach ($children[0] as $task) {
                Task::updateAll(['parent_id' => $rootId], ['id' => $task->id]);
            }

            unset($children[0]);
        }

        // بچه‌هایِ گره با پیشوندِ خالی شماره می‌گیرند تا کدها 1 و 1.1 بمانند.
        $queue = [[$rootId ?: 0, '']];
        $changed = [];
        $guard = 0;

        while ($queue && $guard++ < self::MAX_WALK) {
            list($parentId, $prefix) = array_shift($queue);

            foreach ($children[$parentId] ?? [] as $index => $task) {
                $code = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);

                if ($task->wbs_code !== $code) {
                    $task->wbs_code = $code;
                    $changed[$task->id] = $code;
                }

                $queue[] = [$task->id, $code];
            }
        }

        foreach ($changed as $id => $code) {
            Task::updateAll(['wbs_code' => $code], ['id' => $id]);
        }
    }

    /**
     * آیا $ancestorId خودِ $nodeId یا بالای سر آن است؟
     * (برای جلوگیری از حلقه شدن ساختار درختی)
     */
    public static function isAncestor($ancestorId, $nodeId)
    {
        $current = $nodeId;
        $guard = 0;

        while ($current && $guard++ < self::MAX_WALK) {
            if ($current == $ancestorId) {
                return true;
            }

            $parent = Task::findOne($current);
            $current = $parent ? $parent->parent_id : null;
        }

        return false;
    }

    /**
     * آیا این تسک زیرمجموعه دارد؟
     * چنین تسکی «summary» است: نباید در شبکه‌ی CPM شرکت کند و نباید رابطه داشته باشد.
     */
    public static function hasChildren($id): bool
    {
        return Task::find()->where(['parent_id' => $id])->exists();
    }

    /** تعداد زیرمجموعه‌های مستقیم و غیرمستقیم یک تسک */
    public static function countDescendants($id)
    {
        $queue = [$id];
        $count = 0;
        $guard = 0;

        while ($queue && $guard++ < self::MAX_WALK) {
            foreach (Task::find()->where(['parent_id' => array_shift($queue)])->column() as $childId) {
                $count++;
                $queue[] = $childId;
            }
        }

        return $count;
    }
}
