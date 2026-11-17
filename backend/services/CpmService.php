<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\TaskRelation;

/**
 * محاسبه مسیر بحرانی (CPM) — کاملاً سمت سرور.
 *
 * طبق استاندارد MS Project / اکسل، تسک‌های دارای زیرمجموعه (summary) در شبکه شرکت نمی‌کنند؛
 * فقط فعالیت‌های برگ (leaf) گره‌ی محاسبه‌اند و روابطی که یک سرشان تسکِ والد باشد نادیده
 * گرفته می‌شود (ساخت چنین رابطه‌ای از همین حالا در RelationValidator رد می‌شود).
 *
 * وظایف:
 *   CpmGraphService  → تبدیل واحدها، ساخت و ایندکس یال‌ها، مرتب‌سازی توپولوژیک
 *   CpmCalculator    → محاسبات خالص ES/EF/LS/LF/شناوری/مسیر بحرانی
 *   ScheduleService  → rollup ردیف‌های والد + تاریخ‌ها
 *
 * چکِ نقش اینجا نیست چون این متد هم از endpoint «محاسبه‌ی CPM» صدا زده می‌شود
 * و هم از داخلِ ثبتِ فعالیتِ گزارش (ReportSnapshot) و از لیستِ تسک‌ها؛ هر کدام
 * باید نقشِ خودش را لبه‌ی برنامه چک کند نه اینکه همه مجبور به نقش 2402 شوند.
 */
class CpmService
{
    /** @return array پاسخ آماده خروجی API */
    public static function calculate($projectId)
    {
        $taskRows = Task::find()
            ->select(['id', 'parent_id', 'wbs_code', 'name', 'duration', 'duration_unit'])
            ->where(['project_id' => $projectId])
            ->asArray()
            ->all();

        $project = Project::findOne($projectId);

        if (!$taskRows) {
            return self::emptyResult($project);
        }

        $relationRows = TaskRelation::find()
            ->select(['predecessor_task_id', 'successor_task_id', 'relation_type', 'lag', 'lag_unit'])
            ->where(['project_id' => $projectId])
            ->asArray()
            ->all();

        // CpmGraphService خودش فیلتر leaf/summary را اعمال می‌کند
        $graph = CpmGraphService::build($taskRows, $relationRows);

        if ($graph['order'] === null) {
            return [
                'success' => false,
                'project_id' => (int) $projectId,
                // نام پروژه حتی هنگام خطا لازم است تا ستون «پروژه» جدول خالی نماند
                'project_name' => $project ? $project->name : null,
                'unit' => $graph['unit'],
                'message' => 'گراف روابط دارای حلقه است؛ ابتدا آن را برطرف کنید',
                'cycle' => DependencyGraphService::findCycle($projectId),
            ];
        }

        $calculated = CpmCalculator::run($graph);

        // لنگرِ زمان‌بندی، تاریخِ شروعِ پروژه است — نه تاریخِ ایجادِ ردیف.
        $tasks = ScheduleService::build(
            $taskRows,
            $calculated['results'],
            $graph['unit'],
            $project ? $project->start_date : null,
            TaskCompletion::byTask($projectId)
        );

        return [
            'success' => true,
            'project_id' => (int) $projectId,
            'project_name' => $project ? $project->name : null,
            'unit' => $graph['unit'],
            'task_count' => count($tasks),
            'project_duration' => round($calculated['project_duration'], 2),
            'critical_path' => $calculated['critical_path'],
            'critical_tasks' => self::criticalTasks($calculated['critical_path'], $tasks),
            'tasks' => $tasks,
        ];
    }

    /**
     * خلاصه‌ی زمان‌بندی را به پاسخ لیست اضافه می‌کند و برای هر ردیفِ همین صفحه کلید `cpm` را
     * می‌گذارد تا جدول بدون درخواست دوم ستون‌های Critical/شروع/پایان/شناوری را نمایش دهد.
     *
     * اگر پروژه‌ای انتخاب نشده باشد یا گراف حلقه داشته باشد، پاسخ دست‌نخورده برمی‌گردد.
     */
    public static function attach(array $result, $projectId): array
    {
        if (!$projectId || !isset($result['data'])) {
            return $result;
        }

        $cpm = self::calculate($projectId);

        $result['cpm'] = [
            'success' => !empty($cpm['success']),
            'project_name' => $cpm['project_name'] ?? null,
            'unit' => $cpm['unit'] ?? 'day',
            'project_duration' => $cpm['project_duration'] ?? 0,
            // شمارش کلِ تسک‌های پروژه برای نوار خلاصه (تعدادِ صفحه‌ی جاری نیست)
            'task_count' => $cpm['task_count'] ?? 0,
            'critical_path' => $cpm['critical_path'] ?? [],
            'critical_tasks' => $cpm['critical_tasks'] ?? [],
        ];

        if (empty($cpm['success'])) {
            $result['cpm']['message'] = $cpm['message'] ?? 'محاسبه مسیر بحرانی ممکن نشد';
        }

        // نامِ پدر هر ردیف برای ستون «والد» جدول لیست؛ از همان نقشه‌ی کامل CPM
        // گرفته می‌شود تا کوئری اضافه‌ای لازم نباشد
        $names = $cpm['tasks'] ?? [];

        foreach ($result['data'] as $index => $row) {
            $data = is_array($row) ? $row : $row->toArray();
            $id = (int) ($data['id'] ?? 0);
            $parentId = $data['parent_id'] ?? null;

            $data['parent_name'] = ($parentId && isset($names[$parentId]['name']))
                ? $names[$parentId]['name']
                : null;
            $data['cpm'] = $cpm['tasks'][$id] ?? null;
            $result['data'][$index] = $data;
        }

        return $result;
    }

    /** پاسخ خالی برای پروژه‌ای بدون تسک */
    private static function emptyResult($project): array
    {
        return [
            'success' => true,
            'project_id' => $project ? (int) $project->id : 0,
            'project_name' => $project ? $project->name : null,
            'unit' => 'day',
            'project_duration' => 0,
            'task_count' => 0,
            'critical_path' => [],
            'critical_tasks' => [],
            'tasks' => [],
        ];
    }

    /**
     * اطلاعات کم‌حجم برای نوار خلاصه‌ی بالای جدول (نام فعالیت‌های مسیر بحرانی).
     */
    private static function criticalTasks(array $criticalPath, array $tasks): array
    {
        $list = [];

        foreach ($criticalPath as $id) {
            if (isset($tasks[$id])) {
                $list[] = [
                    'id' => $tasks[$id]['id'],
                    'wbs_code' => $tasks[$id]['wbs_code'],
                    'name' => $tasks[$id]['name'],
                ];
            }
        }

        return $list;
    }
}
