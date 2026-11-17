<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Task;

/**
 * ترتیبِ ردیف‌های فعالیت بر اساسِ کد WBS (ایشوی ۷.۱).
 *
 * تبِ «فعالیت‌ها» و «پیش‌نمایش» باید یک ترتیب داشته باشند پس هر دو از همینجا
 * می‌گذرند. مرتب‌سازیِ SQL رویِ رشته «۱.۱۰» را قبل از «۱.۲» می‌گذارد؛
 * `strnatcmp` همان مقایسهٔ طبیعی را می‌کند که کاربر انتظارش را دارد.
 *
 * ردیفِ بدونِ کد (تسکِ حذف‌شده یا هنوز ثبت‌نشده) به انتهای همان گزارش
 * می‌رود تا وسطِ فهرست بی‌جهت نپرد.
 */
class ActivityOrder
{
    /** @param array $rows ردیف‌های آرایه‌ایِ `daily_report_activities` */
    public static function byWbs(array $rows): array
    {
        $codes = self::codes(array_column($rows, 'task_id'));

        usort($rows, static function (array $a, array $b) use ($codes) {
            $byReport = ((int) ($a['daily_report_id'] ?? 0)) <=> ((int) ($b['daily_report_id'] ?? 0));

            if ($byReport !== 0) {
                return $byReport;
            }

            $left = (string) ($codes[$a['task_id'] ?? 0] ?? '');
            $right = (string) ($codes[$b['task_id'] ?? 0] ?? '');

            if ($left === '' || $right === '') {
                return ($left === '' ? 1 : 0) - ($right === '' ? 1 : 0);
            }

            return strnatcmp($left, $right) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        });

        return $rows;
    }

    /** کدِ WBSِ همه‌ی تسک‌ها با یک کوئری — نه جستجوی ردیف‌به‌ردیف */
    private static function codes(array $taskIds): array
    {
        $taskIds = array_unique(array_filter($taskIds));

        if (!$taskIds) {
            return [];
        }

        $codes = [];

        foreach (Task::find()->select(['id', 'wbs_code'])->where(['id' => $taskIds])->asArray()->all() as $task) {
            $codes[$task['id']] = (string) $task['wbs_code'];
        }

        return $codes;
    }
}
