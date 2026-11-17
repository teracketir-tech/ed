<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\Task;

/**
 * گرهٔ پروژه (ردیفِ کد 0) — ساختار WBS
 *
 * هر پروژه دقیقاً یک ردیف ریشه دارد: کدش 0 است، نامش نامِ پروژه است و همهٔ
 * تسک‌های پروژه زیرمجموعهٔ آن‌اند. این گره «summary» است، پس هرگز وارد شبکهٔ
 * CPM نمی‌شود و رابطه‌ای هم نمی‌پذیرد.
 *
 * شماره‌گذاری فرزندان عمداً بازنویسی نمی‌شود: فرزندانِ گره همان کدهایی را
 * می‌گیرند که پیش‌تر داشتند (1 ، 1.1 ، 1.2) تا هیچ کدِ موجودی جابه‌جا نشود.
 */
class ProjectNodeService
{
    /** کد WBS گرهٔ پروژه — فرزندانش 1 ، 1.1 ، 1.2 می‌گیرند */
    public const ROOT_CODE = '0';

    /**
     * آیا این ردیف گرهٔ پروژه است؟
     * گره تنها ردیفِ بدون والدِ پروژه است و کدش همیشه 0 است.
     */
    public static function isRoot(Task $task): bool
    {
        return !$task->parent_id && (string) $task->wbs_code === self::ROOT_CODE;
    }

    /** گرهٔ پروژه، یا null اگر پروژه هنوز گره ندارد */
    public static function rootOf($projectId): ?Task
    {
        return Task::find()
            ->where(['project_id' => $projectId, 'parent_id' => null, 'wbs_code' => self::ROOT_CODE])
            ->one();
    }

    /**
     * گرهٔ پروژه را می‌سازد اگر نباشد و نامش را با نامِ پروژه هم‌نگه می‌دارد.
     * همیشه خودِ گره را برمی‌گرداند.
     *
     * «تنها ردیفِ ریشه بودن» — یعنی زیرِ گره نشاندنِ هر بی‌والدِ دیگر — بر عهدهٔ
     * `WbsService::rebuildWbs` است که همین‌جا و بعد از هر ذخیرهٔ تسک صدا زده
     * می‌شود؛ پس اینجا دوباره‌کاری نمی‌شود.
     */
    public static function ensureRoot($projectId): ?Task
    {
        $project = Project::findOne($projectId);

        if (!$project) {
            return null;
        }

        $root = self::rootOf($projectId);

        if (!$root) {
            $root = new Task();
            $root->project_id = $projectId;
            $root->parent_id = null;
            $root->wbs_code = self::ROOT_CODE;
            $root->sort_order = 0;
        }

        // نامِ گره همیشه نامِ پروژه است.
        $root->name = $project->name;

        if (!$root->save(false)) {
            return null;
        }

        WbsService::rebuildWbs($projectId);

        return self::rootOf($projectId) ?: $root;
    }
}
