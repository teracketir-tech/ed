<?php

namespace frontend\modules\hq\services;

/**
 * مونتاژ نهایی یک ردیف زمان‌بندی + محاسبه‌ی تاریخ‌ها.
 *
 * تاریخ = شروعِ پروژه + تعداد روز. تقویم/تعطیلات لحاظ نمی‌شود (تصمیم پروژه)،
 * یعنی پنجشنبه و جمعه هم روز کاری فرض می‌شوند. خروجی ISO میلادی است ('Y-m-d')
 * و تبدیل به شمسی بر عهده‌ی فرانت (toLocaleDateString('fa-IR')) است.
 *
 * قرارداد تاریخ — عین `smaple.xlsx` (شیت WBS):
 *   شروع = پروژه + ES، پایان = پروژه + EF، دیرترین پایان = پروژه + LF.
 *   یعنی پایانِ یک فعالیتِ ۳ روزه‌ی آغازِ ۱۳ مهر، ۱۶ مهر است (پایان = شروع + ۳)
 *   و فعالیتِ صفر-روزه شروع و پایانش یکی است.
 *   (نتیجه‌ی بررسی نمونه: در هیچ ردیفی از اکسل «پایان − شروع = مدت − ۱» نبود.)
 *
 * واحدها:
 *   duration/unit  → واحد نمایش (leaf: همان چیزی که کاربر وارد کرده؛ summary: واحد پایه‌ی CPM)
 *   es/ef/ls/lf/float/free_float → همیشه در واحد پایه‌ی CPM (روز یا ساعت)
 *
 * خالص است (Yii لازم ندارد) تا قابل تست باشد.
 */
class ScheduleRow
{
    /** ردیف بی‌اثر برای تسک‌هایی که به هیچ نتیجه‌ای وصل نشدند */
    public static function blank($unitCode): array
    {
        return [
            'duration' => 0,
            'unit' => $unitCode ?? DurationUnits::CODE_DAY,
            'es' => 0, 'ef' => 0, 'ls' => 0, 'lf' => 0,
            'float' => 0, 'free_float' => 0,
            'is_critical' => false,
        ];
    }

    /**
     * @param array $taskRow ردیف خام تسک از دیتابیس
     * @param array $value   نتیجه‌ی CPM (leaf) یا rollup (summary)
     * @param bool $isSummary آیا این تسک زیرمجموعه دارد؟
     * @param int $unitCode  کد واحد پایه‌ی CPM
     * @param string|null $projectStartDate تاریخ شروع پروژه
     */
    public static function make(array $taskRow, array $value, $isSummary, $unitCode, $projectStartDate): array
    {
        // leaf مدت/واحد خودش را نگه می‌دارد تا آنچه کاربر وارد کرده نمایش داده شود
        $duration = $isSummary ? (float) ($value['duration'] ?? 0) : (float) $taskRow['duration'];
        $unit = $isSummary ? $unitCode : (int) ($taskRow['duration_unit'] ?: DurationUnits::CODE_DAY);

        $es = round((float) ($value['es'] ?? 0), 2);
        $ef = round((float) ($value['ef'] ?? 0), 2);
        $lf = round((float) ($value['lf'] ?? 0), 2);

        return [
            'id' => (int) $taskRow['id'],
            'wbs_code' => (string) $taskRow['wbs_code'],
            'name' => (string) $taskRow['name'],
            'duration' => round($duration, 2),
            'unit' => $unit,
            'es' => $es,
            'ef' => $ef,
            'ls' => round((float) ($value['ls'] ?? 0), 2),
            'lf' => $lf,
            'float' => round((float) ($value['float'] ?? 0), 2),
            'free_float' => round((float) ($value['free_float'] ?? 0), 2),
            'is_critical' => (bool) ($value['is_critical'] ?? false),
            'is_summary' => (bool) $isSummary,
            'progress' => round((float) ($value['progress'] ?? 0), 2),
            'start_date' => self::dateAt($projectStartDate, $es, $unitCode),
            'finish_date' => self::dateAt($projectStartDate, $ef, $unitCode),
            'late_finish_date' => self::dateAt($projectStartDate, $lf, $unitCode),
        ];
    }

    /**
     * شروعِ پروژه + مقدارِ واحدِ پایه (ES/EF/LF) → تاریخ.
     *
     * برای شروع و پایان از یک قاعده استفاده می‌شود تا «پایان = شروع + مدت» برقرار بماند.
     */
    private static function dateAt($projectStartDate, $value, $unitCode): ?string
    {
        return self::at($projectStartDate, self::days($value, $unitCode));
    }

    /** مقدارِ واحدِ پایه (روز یا ساعت) → تعداد روزِ گردش‌شده */
    private static function days($value, $unitCode): int
    {
        $perDay = $unitCode == DurationUnits::CODE_HOUR ? DurationUnits::HOURS_PER_DAY : 1;

        return (int) round(((float) $value) / $perDay);
    }

    /** افزودن تعداد روز به شروع پروژه (بدون تقویم؛ همه‌ی روزها کاری‌اند) */
    private static function at($projectStartDate, $days): ?string
    {
        if ($projectStartDate === null || $projectStartDate === '') {
            return null;
        }

        try {
            $date = new \DateTime($projectStartDate);
        } catch (\Exception $e) {
            return null;
        }

        $date->setTime(0, 0)->modify(($days < 0 ? '-' : '+') . abs($days) . ' days');

        return $date->format('Y-m-d');
    }
}
