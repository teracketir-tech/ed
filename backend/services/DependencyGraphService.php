<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\TaskRelation;

/**
 * گراف وابستگی‌ها بین تسک‌ها (یال: مقدم → وابسته)
 *
 * هم برای جلوگیری از Circular Dependency هنگام ذخیره رابطه
 * و هم برای محاسبه CPM استفاده می‌شود.
 */
class DependencyGraphService
{
    /** حداکثر پیمایش گره‌ها (محافظت در برابر داده خراب) */
    private const MAX_WALK = 100000;

    /** نگاشت «مقدم → لیست وابسته‌ها» برای یک پروژه */
    public static function successorsMap($projectId, $excludeId = null)
    {
        $query = TaskRelation::find()
            ->select(['predecessor_task_id', 'successor_task_id', 'relation_type', 'lag'])
            ->where(['project_id' => $projectId]);

        if ($excludeId) {
            $query->andWhere(['<>', 'id', $excludeId]);
        }

        $map = [];

        foreach ($query->asArray()->all() as $row) {
            $map[$row['predecessor_task_id']][] = $row;
        }

        return $map;
    }

    /** نگاشت «وابسته → لیست مقدم‌ها» (برای محاسبه CPM) */
    public static function predecessorsMap($projectId)
    {
        $map = [];

        foreach (TaskRelation::find()
                     ->select(['predecessor_task_id', 'successor_task_id', 'relation_type', 'lag'])
                     ->where(['project_id' => $projectId])
                     ->asArray()->all() as $row) {
            $map[$row['successor_task_id']][] = $row;
        }

        return $map;
    }

    /**
     * آیا از $fromId با پیمایش یال‌ها به $toId می‌رسیم؟
     *
     * $excludeId یالِ همین ردیف را (هنگام ویرایش) کنار می‌گذارد، چون گراف نتیجه
     * = گراف قدیم منهای یال قدیمی این ردیف + یال جدید.
     */
    public static function reaches($projectId, $fromId, $toId, $excludeId = null)
    {
        if ($fromId == $toId) {
            return true;
        }

        $successors = self::successorsMap($projectId, $excludeId);
        $visited = [(int) $fromId => true];
        $queue = [(int) $fromId];
        $guard = 0;

        while ($queue && $guard++ < self::MAX_WALK) {
            $current = array_shift($queue);

            foreach ($successors[$current] ?? $successors[(string) $current] ?? [] as $row) {
                $next = (int) $row['successor_task_id'];

                if ($next === (int) $toId) {
                    return true;
                }
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * آیا گراف وابستگی‌های پروژه حلقه دارد؟ (برای CPM)
     * خروجی: لیست شناسه تسک‌های داخل حلقه (بسته‌شده با اولین تسک)، یا آرایه خالی.
     */
    public static function findCycle($projectId)
    {
        $successors = self::successorsMap($projectId);
        $guard = 0;

        foreach (array_keys($successors) as $start) {
            $start = (int) $start;
            $visited = [$start => true];
            $parentOf = [];
            $queue = [$start];

            while ($queue && $guard++ < self::MAX_WALK) {
                $current = array_shift($queue);

                foreach ($successors[$current] ?? [] as $row) {
                    $next = (int) $row['successor_task_id'];

                    if ($next === $start) {
                        // بازسازی مسیر: start ← ... ← current ، سپس بستن حلقه
                        $path = [];
                        $node = $current;

                        while ($node !== null) {
                            $path[] = $node;
                            if ($node === $start) {
                                break;
                            }
                            $node = $parentOf[$node] ?? null;
                        }

                        $path[] = $start;

                        return array_reverse($path);
                    }

                    if (!isset($visited[$next])) {
                        $visited[$next] = true;
                        $parentOf[$next] = $current;
                        $queue[] = $next;
                    }
                }
            }
        }

        return [];
    }
}
