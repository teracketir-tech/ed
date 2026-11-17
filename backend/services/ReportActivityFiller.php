<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportActivityRemoved;

/**
 * ساختِ خودکارِ ردیف‌های فعالیتِ گزارشِ روزانه
 *
 * فعالیت‌هایی که برای روزِ گزارش برنامه‌ریزی شده‌اند را خودِ سرور به گزارش
 * اضافه می‌کند تا کاربر از فهرستِ خالی و حافظه‌اش شروع نکند. هر ردیف با
 * پیشرفتِ صفر ساخته می‌شود (ستون `daily_progress` اجباری است) و snapshotِ
 * برنامه‌ای‌اش را از همان لحظه می‌گیرد.
 *
 * قاعدهٔ تکرار: هر تسک حداکثر یک بار در هر گزارش. تسکی که در گزارشِ دیگری از
 * همان روز ثبت شده باز هم می‌آید — گزارش‌ها از هم مستقل‌اند.
 *
 * زمانِ فراخوانی: ساختِ گزارش، عوض شدنِ تاریخ/پروژه، و همچنین باز کردنِ خودِ
 * گزارش (ReportService::view). باز کردن هم باید پر کند، وگرنه یک پیش‌نویسِ
 * قدیمی برای همیشه خالی می‌ماند و فعالیتِ تازه‌ای که بعداً برای همان روز
 * برنامه‌ریزی شده هرگز ظاهر نمی‌شود.
 *
 * تنها استثنا حذفِ دستیِ کاربر است: قبرِ آن در `daily_report_activity_removed`
 * می‌ماند و همین کلاس آن را رد می‌کند تا هر باز کردن، تصمیمِ کاربر را باطل نکند.
 */
class ReportActivityFiller
{
    /**
     * ردیف‌هایِ گزارش را با فعالیت‌هایِ برنامه‌ریزی‌شدهٔ همان روز کامل می‌کند.
     *
     * با `$pruneStale` برایِ روزِ قدیمی ردیفِ دیگر-روز هم برداشته می‌شود. فقط
     * «ذخیرهٔ سربرگ با پروژه/تاریخِ تازه» این را روشن می‌کند؛ باز کردنِ گزارش
     * نباید هیچ‌چیز حذف کند چون برنامه ممکن است بعد از هر محاسبهٔ CPM جابه‌جا
     * شده باشد و نباید دادهٔ ثبت‌شده با هر بار باز کردن از بین برود.
     *
     * @return int تعداد ردیفِ ساخته‌شده (0 یعنی چیزی کم نبود)
     */
    public static function fill(DailyReport $report, bool $pruneStale = false): int
    {
        // گزارشِ ثبت‌شده تاریخچه است و ردیفِ تازه نمی‌گیرد. از این پس پیش‌نویس
        // هر بار که باز شود پر می‌شود، پس بدون این بند گزارشِ تثبیت‌شده هم
        // با هر بار باز شدن فعالیتِ تازه می‌گرفت.
        if ((int) $report->status !== ReportRules::STATUS_DRAFT) {
            return 0;
        }

        $rows = ReportableTasks::rows($report->project_id);

        if ($pruneStale) {
            self::prune($report, ReportableTasks::staleIds($rows, $report->report_date));
        }

        $created = 0;
        // حذفِ دستیِ کاربر برای همیشه معتبر می‌ماند تا باز کردنِ بعدی آن را
        // برنگرداند (ایشوی ۳ — رفع باگِ «پیش‌نویسِ خالی برای همیشه خالی می‌ماند»)
        $blocked = DailyReportActivityRemoved::blockedTaskIds($report->id);
        // پایهٔ تجمعیِ همهٔ تسک‌ها در یک کوئری؛ ردیفِ خودکار پیشرفتِ صفر دارد،
        // پس تجمعی‌اش همین پایه است — بدونِ این، جدولِ فعالیت‌ها تا نخستین
        // ویرایشِ دستی ستونِ «پیشرفت تجمعی» را خالی نشان می‌دهد.
        $bases = TaskProgress::bases($report->toArray());

        foreach (ReportableTasks::filter($rows, $report->report_date) as $row) {
            $data = is_array($row) ? $row : $row->toArray();
            $taskId = (int) ($data['id'] ?? 0);

            if (!$taskId || in_array($taskId, $blocked, true) || self::exists($report->id, $taskId)) {
                continue;
            }

            $activity = new DailyReportActivity();
            $activity->daily_report_id = $report->id;
            $activity->task_id = $taskId;
            $activity->daily_progress = 0;
            $activity->cumulative_progress = $bases[$taskId] ?? 0;

            if ($activity->save()) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * ردیف‌هایِ روزِ قدیمی را برمی‌دارد که روزِ تازه دیگر آن‌ها را ندارد.
     *
     * خطوطِ نیرو/مصالح/تجهیزاتِ این ردیف‌ها با ON DELETE CASCADE می‌روند؛
     * «روز عوض شد» یعنی تصمیمِ روزِ قبل اعتباری ندارد — همان حکمی که قبرِ
     * حذفِ دستی را کنار می‌گذارد.
     */
    private static function prune(DailyReport $report, array $stale): void
    {
        if (!$stale) {
            return;
        }

        DailyReportActivity::deleteAll([
            'daily_report_id' => $report->id,
            'task_id' => $stale,
        ]);
    }

    /** این تسک از قبل در همین گزارش هست؟ (گزارش‌های دیگر حساب نمی‌شوند) */
    private static function exists($reportId, $taskId): bool
    {
        return DailyReportActivity::find()
            ->where(['daily_report_id' => $reportId, 'task_id' => $taskId])
            ->exists();
    }
}
