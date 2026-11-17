<?php

namespace frontend\modules\hq\services;

use yii\db\Query;

/**
 * درصد پیشرفت هر تسک از گزارش‌های روزانه — ستون «درصد پیشرفت» لیست تسک‌ها.
 *
 * قاعده عیناً همان `TaskProgress::forReport` است: جمعِ `daily_progress` همه‌ی
 * فعالیت‌های همان پروژه برای هر تسک؛ یعنی همان عددی که `cumulative_progress`
 * آخرین گزارشِ آن تسک نشان می‌دهد. سقف ۱۰۰ چون سمت سرور هم همین محدودیت اعمال می‌شود.
 *
 * ردیف‌های والد (summary) را `ScheduleService` از فرزندانش rollup می‌کند.
 */
class TaskCompletion
{
    /**
     * @param int|string $projectId
     *
     * @return array<int, float> شناسه‌ی تسک → درصد (تسکِ بدون گزارش در پاسخ نیست)
     */
    public static function byTask($projectId): array
    {
        if (!$projectId) {
            return [];
        }

        $rows = (new Query())
            ->select(['task_id', 'total' => 'SUM(a.daily_progress)'])
            ->from(['a' => 'daily_report_activities'])
            ->innerJoin(['r' => 'daily_reports'], 'r.id = a.daily_report_id')
            ->where(['r.project_id' => $projectId])
            ->groupBy(['a.task_id'])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['task_id']] = min(100, round((float) $row['total'], 2));
        }

        return $out;
    }
}
