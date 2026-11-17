<?php

namespace frontend\modules\hq\services;

use yii\db\Query;

/**
 * پیشرفت تجمعی (`cumulative_progress`) — سند فاز ۲ بند ۲۱۰:
 *
 *   «daily_progress ورودی ناظر باشد و cumulative_progress توسط Backend از
 *     سوابق قبلی و گزارش جاری محاسبه شود؛ از کاربر نخواهیم عدد تجمعی را دستی تکرار کند.»
 *
 * قاعده: جمع تدریجیِ `daily_progress` همه‌ی گزارش‌های همان تسک به ترتیب زمان،
 * با سقف ۱۰۰٪ (بند ۱۷ سند). اگر جمع از سقف رد شود ذخیره با پیام روشن متوقف می‌شود؛
 * هم قرارداد CHECK پایگاه داده نقض نمی‌شود، هم کاربر می‌داند کجا را اصلاح کند.
 *
 * `series()` خالص است (Yii لازم ندارد) تا قابل تست باشد؛ `chain()` بخش دیتابیسی است.
 */
class CumulativeProgress
{
    /** بالاترین مقدار مجاز (درصد) */
    public const MAX = ReportRules::PROGRESS_MAX;

    private const EPSILON = 0.0001;

    /**
     * جمع تدریجی به ترتیب زمانی (قدیمی‌ترین اول).
     *
     * @param float[] $dailyProgress
     *
     * @return array{values: float[], overflow: float}
     *         values   = تجمعی هر گام
     *         overflow = نخستین مقداری که از سقف گذشت (۰ یعنی هیچ‌جا رد نشد)
     */
    public static function series(array $dailyProgress): array
    {
        $values = [];
        $running = 0.0;
        $overflow = 0.0;

        foreach ($dailyProgress as $step) {
            $running += (float) $step;
            $values[] = round($running, 2);

            if ($overflow <= 0.0 && $running > self::MAX + self::EPSILON) {
                $overflow = round($running, 2);
            }
        }

        return ['values' => $values, 'overflow' => $overflow];
    }

    /** پیام خطای روشن برای مقدارِ ردشده، یا null اگر مشکلی نباشد */
    public static function overflowError($overflow): ?string
    {
        if (!$overflow || $overflow <= 0) {
            return null;
        }

        return 'پیشرفت تجمعی این فعالیت به ' . round((float) $overflow, 2)
            . ' درصد می‌رسد و از 100 درصد بیشتر است؛ پیشرفت این روز یا گزارش‌های قبلی همین فعالیت را کاهش دهید';
    }

    /**
     * بازمحاسبه‌ی زنجیره‌ی تجمعی یک تسک از تاریخ گزارش جاری به بعد.
     *
     * ردیف‌های قدیمی‌تر دست‌نخورده می‌مانند؛ ردیف جاری + همه‌ی ردیف‌های بعدیِ همان تسک
     * دوباره محاسبه می‌شوند چون جمعِ زیرین‌شان عوض شده است.
     *
     * @param int      $projectId
     * @param int      $taskId
     * @param int      $reportId  گزارشی که ردیف جاری در آن است
     * @param string   $date      تاریخ همان گزارش (Y-m-d)
     * @param int|null $excludeId ردیفِ در حال ویرایش (برای افزودن null)
     * @param float    $pending   daily_progress ردیف جاری
     *
     * @return array{pending: float|null, rows: array<int,float>, overflow: float}
     *         rows = [activityId => مقدار جدید تجمعی] فقط برای ردیف‌هایی که باید نوشته شوند
     */
    public static function chain($projectId, $taskId, $reportId, $date, $excludeId, $pending): array
    {
        $rows = self::rows($projectId, $taskId);

        $base = 0.0;
        $chain = [];

        foreach ($rows as $row) {
            if ($excludeId && (int) $row['id'] === (int) $excludeId) {
                continue;
            }

            if ((string) $row['report_date'] < (string) $date) {
                $base += (float) $row['daily_progress'];
                continue;
            }

            $chain[] = [
                'date' => (string) $row['report_date'],
                'report_id' => (int) $row['daily_report_id'],
                'id' => (int) $row['id'],
                'progress' => (float) $row['daily_progress'],
                'pending' => false,
            ];
        }

        $chain[] = [
            'date' => (string) $date,
            'report_id' => (int) $reportId,
            'id' => $excludeId === null ? null : (int) $excludeId,
            'progress' => (float) $pending,
            'pending' => true,
        ];

        // هم‌تاریخ‌ها: اول بر اساس شماره‌ی گزارش، بعد شناسه — تا ردیفِ جاری سرِ جایش بنشیند
        usort($chain, function ($a, $b) {
            return [$a['date'], $a['report_id'], $a['id']] <=> [$b['date'], $b['report_id'], $b['id']];
        });

        $series = self::series(array_merge([$base], array_column($chain, 'progress')));
        $values = array_slice($series['values'], 1);

        $out = [];
        $pendingValue = null;

        foreach ($chain as $index => $entry) {
            if ($entry['pending']) {
                $pendingValue = $values[$index];
            }

            if ($entry['id'] !== null) {
                $out[(int) $entry['id']] = $values[$index];
            }
        }

        return ['pending' => $pendingValue, 'rows' => $out, 'overflow' => $series['overflow']];
    }

    /**
     * بازمحاسبه‌ی همه‌ی ردیف‌های یک تسک (بعد از «حذف» یک فعالیت).
     * حذف فقط جمعِ ردیف‌های بعدی را کم می‌کند، پس سرریز ممکن نیست؛
     * باز هم گزارش می‌شود تا داده‌ی خرابِ از قبل‌مانده پنهان نماند.
     *
     * @return array{rows: array<int,float>, overflow: float}
     */
    public static function recompute($projectId, $taskId): array
    {
        $rows = self::rows($projectId, $taskId);
        $series = self::series(array_column($rows, 'daily_progress'));

        $out = [];

        foreach ($rows as $index => $row) {
            $out[(int) $row['id']] = $series['values'][$index];
        }

        return ['rows' => $out, 'overflow' => $series['overflow']];
    }

    /** همه‌ی ردیف‌های یک تسک به ترتیب زمان، همراه با تاریخ گزارششان */
    private static function rows($projectId, $taskId): array
    {
        return (new Query())
            ->select(['a.id', 'a.daily_progress', 'a.daily_report_id', 'r.report_date'])
            ->from(['a' => 'daily_report_activities'])
            ->innerJoin(['r' => 'daily_reports'], 'r.id = a.daily_report_id')
            ->where(['r.project_id' => $projectId, 'a.task_id' => $taskId])
            ->orderBy(['r.report_date' => SORT_ASC, 'a.daily_report_id' => SORT_ASC, 'a.id' => SORT_ASC])
            ->all();
    }
}
