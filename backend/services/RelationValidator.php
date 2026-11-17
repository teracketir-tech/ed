<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\TaskRelation;

/**
 * اعتبارسنجی یک رابطه پیش از ذخیره:
 * جفت تسک‌ها باید معتبر باشند و رابطه نباید حلقه (Circular Dependency) بسازد.
 */
class RelationValidator
{
    /**
     * خطاهای جفت تسک‌ها: وجود، تعلق به همین پروژه، یکی نبودن و «طرفِ رابطه summary نبودن».
     * خروجی: شناسه فیلد → پیام
     */
    public static function pairErrors(TaskRelation $relation): array
    {
        if ($relation->predecessor_task_id == $relation->successor_task_id) {
            return ['successor_task_id' => 'تسک مقدم و وابسته نمی‌توانند یکی باشند'];
        }

        $errors = [];

        foreach (['predecessor_task_id', 'successor_task_id'] as $attr) {
            $task = Task::findOne($relation->$attr);

            if (!$task) {
                $errors[$attr] = 'تسک انتخاب‌شده یافت نشد';
            } elseif ($task->project_id != $relation->project_id) {
                $errors[$attr] = 'تسک باید متعلق به همین پروژه باشد';
            } elseif (WbsService::hasChildren($task->id)) {
                // summary (دارای زیرمجموعه) در شبکه‌ی CPM شرکت نمی‌کند؛ رابطه‌اش بی‌اثر می‌شد
                $errors[$attr] = 'این تسک زیرمجموعه دارد و نمی‌تواند در رابطه شرکت کند؛ رابطه را بین فعالیت‌های برگ بسازید';
            }
        }

        return $errors;
    }

    /**
     * آیا این تسک در هیچ رابطه‌ای (مقدم یا وابسته) شرکت دارد؟
     * تسکِ رابطه‌دار نمی‌تواند والد شود، چون در آن صورت از شبکه‌ی CPM خارج می‌شود
     * و رابطه‌اش بی‌اثر می‌ماند.
     */
    public static function involves($taskId): bool
    {
        return TaskRelation::find()->where([
            'or',
            ['predecessor_task_id' => $taskId],
            ['successor_task_id' => $taskId],
        ])->exists();
    }

    /**
     * اگر «وابسته» از طریق روابط موجود به «مقدم» برسد، افزودن این رابطه حلقه می‌سازد.
     * خروجی: پیام خطا، یا null اگر سالم باشد.
     */
    public static function cycleError(TaskRelation $relation): ?string
    {
        foreach (['project_id', 'predecessor_task_id', 'successor_task_id'] as $attr) {
            if ($relation->hasErrors($attr)) {
                return null;
            }
        }

        $reaches = DependencyGraphService::reaches(
            $relation->project_id,
            $relation->successor_task_id,
            $relation->predecessor_task_id,
            $relation->id
        );

        return $reaches ? 'این رابطه باعث ایجاد حلقه (Circular Dependency) می‌شود' : null;
    }
}
