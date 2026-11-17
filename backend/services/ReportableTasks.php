<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Task;

/**
 * فعالیت‌های قابلِ گزارشِ یک روز
 *
 * گزارشِ روزانه دیگر از فهرستِ خالی شروع نمی‌شود: هر فعالیتِ برگی که پنجرهٔ
 * برنامه‌ریزی‌شده‌اش روزِ گزارش را در بر بگیرد، خودِ سرور برش می‌دارد.
 *
 *   قاعده:  شروعِ فعالیت ≤ روزِ گزارش ≤ پایانِ فعالیت
 *            + روزِ گزارش بعد از پایانِ برنامه ولی تسک هنوز تمام نشده (ایشوی ۷.۲)
 *   فقط برگ: تسکِ والد (summary) وارد شبکهٔ CPM نمی‌شود و فعالیتِ شمرده‌شدنی نیست
 *
 * تاریخ‌ها میلادی ISO‌اند (همان‌ها که ScheduleRow می‌سازد)، پس مقایسهٔ رشته‌ای
 * همان مقایسهٔ تقویمی است و به هیچ کتابخانه‌ای نیاز ندارد.
 */
class ReportableTasks
{
    /**
     * ردیف‌های خروجیِ `reportable-tasks` را برای یک روز فیلتر می‌کند.
     *
     * دادنِ `report_date` خالی یعنی «بدون فیلتر» تا مصرف‌کنندهٔ قدیمی‌تر که
     * کل فهرست را می‌خواهد بی‌دلیل خالی نشود.
     *
     * @param array $rows خروجیِ Task::get_all که روی هر ردیف کلید `cpm` دارد
     */
    public static function filter(array $rows, $reportDate): array
    {
        $reportDate = self::normalise($reportDate);

        if ($reportDate === null) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($reportDate) {
            return self::covers($row, $reportDate);
        }));
    }

    /**
     * فعالیت‌هایِ برنامه‌ریزی‌شدهٔ یک پروژه برای یک روز، همان شکلِ خروجیِ
     * `reportable-tasks`. مبنای ساختِ خودکارِ ردیف‌های گزارش است.
     */
    public static function forReport($projectId, $reportDate): array
    {
        $reportDate = self::normalise($reportDate);

        if ($reportDate === null || !$projectId) {
            return [];
        }

        return self::filter(self::rows($projectId), $reportDate);
    }

    /**
     * ردیف‌های خامِ تسک‌هایِ پروژه همراه با CPM همان لحظه — ورودیِ هر دو
     * `filter` و `staleIds` تا یک ذخیرهٔ سربرگ یک بار CPM حساب کند
     * (هیچ‌کدام کش ندارند).
     */
    public static function rows($projectId): array
    {
        return $projectId ? Task::get_all($projectId, 0, true)['data'] ?? [] : [];
    }

    /**
     * شناسهٔ برگ‌هایی که پنجرهٔ CPMِ‌شان این روز را در بر نمی‌گیرد — همان‌ها
     * که باید از گزارشِ دیگر-روز برداشته شوند.
     *
     * فقط با پنجرهٔ معتبر داوری می‌شود: نبودنِ CPM (گرافِ حلقه‌دار) یعنی
     * نمی‌توان حکم به حذف داد، و خلاصه هم فعالیتِ شمرده‌شدنی نیست پس اینجا
     * حساب نمی‌شود — هر دو سرِ جایشان می‌مانند.
     *
     * @return int[]
     */
    public static function staleIds(array $rows, $reportDate): array
    {
        $reportDate = self::normalise($reportDate);

        if ($reportDate === null) {
            return [];
        }

        $stale = [];

        foreach ($rows as $row) {
            $data = is_array($row) ? $row : $row->toArray();
            $id = (int) ($data['id'] ?? 0);
            $cpm = $data['cpm'] ?? null;

            if (!$id || !$cpm || empty($cpm['start_date']) || empty($cpm['finish_date'])) {
                continue;
            }

            if (!empty($cpm['is_summary'])) {
                continue;
            }

            if (!self::covers($row, $reportDate)) {
                $stale[] = $id;
            }
        }

        return $stale;
    }

    /**
     * آیا این تسک در این روزِ گزارش قابلِ ثبت است؟ — قاعدهٔ زمانِ `filter`، تکی.
     *
     * اعتبارسنجِ ثبتِ فعالیت از همین‌جا می‌پرسد تا فهرستِ خودکار و فهرستِ
     * دستیِ کاربر یک چیز بگویند؛ وگرنه رابطه‌ای که چند روز جلوتر است همچنان
     * از فرم قابلِ ثبت می‌ماند (ایشوی ۱۵).
     *
     * فقط زمان سنجیده می‌شود، نه «برگ» بودن: فهرستِ خودکار برگ‌ها را می‌آورد،
     * ولی دستی ثبت کردنِ والد را اینجا نمی‌گیریم — این قاعده جای دیگری است.
     *
     * نبودِ CPM (گرافِ حلقه‌دار یا تسکِ خارج از پروژه) یعنی نمی‌توان داوری کرد،
     * پس مانندِ رفتارِ امروزِ فرم اجازه می‌دهد.
     */
    public static function allows($projectId, $taskId, $reportDate): bool
    {
        $reportDate = self::normalise($reportDate);

        if ($reportDate === null || !$projectId || !$taskId) {
            return true;
        }

        $cpm = ReportSnapshot::cpmRow($projectId, $taskId);

        $start = $cpm['start_date'] ?? null;
        $finish = $cpm['finish_date'] ?? null;

        if ($cpm === null || $start === null || $finish === null) {
            return true;
        }

        if ($start <= $reportDate && $reportDate <= $finish) {
            return true;
        }

        return $reportDate > $finish && (float) ($cpm['progress'] ?? 0) < 100;
    }

    /**
     * آیا پنجرهٔ CPMِ این ردیف، این روز را در بر می‌گیرد و برگ است؟
     *
     * ایشوی ۷.۲: روزِ گزارش از پایانِ برنامه گذشته ولی تسک هنوز تمام
     * نشده — آن روز هم قابلِ گزارش است، چون تأخیر باید ثبت شود.
     */
    private static function covers($row, $reportDate): bool
    {
        $data = is_array($row) ? $row : $row->toArray();
        $cpm = $data['cpm'] ?? null;

        // بدون CPM (گرافِ حلقه‌دار یا پروژهٔ بدون تسک) هیچ فعالیتی قابلِ گزارش نیست
        if (!$cpm || !empty($cpm['is_summary'])) {
            return false;
        }

        $start = $cpm['start_date'] ?? null;
        $finish = $cpm['finish_date'] ?? null;

        if ($start === null || $finish === null) {
            return false;
        }

        if ($start <= $reportDate && $reportDate <= $finish) {
            return true;
        }

        return $reportDate > $finish && (float) ($cpm['progress'] ?? 0) < 100;
    }

    /** تاریخِ ورودی را به 'Y-m-d' می‌برد؛ خالی/نامعتبر یعنی بدون فیلتر */
    private static function normalise($reportDate): ?string
    {
        if (!$reportDate) {
            return null;
        }

        $date = substr(trim((string) $reportDate), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }
}
