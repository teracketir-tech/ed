<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportActivityDeviation;
use frontend\modules\hq\models\DailyReportActivityRemoved;
use frontend\modules\hq\models\User;
use Yii;

/**
 * ذخیره‌سازی فعالیت‌های گزارش روزانه + بازمحاسبه‌ی پیشرفت تجمعی.
 *
 * جدا از مدل تا مدل قاعده‌ی توصیفی بماند و هر فایل زیر ۲۰۰ خط بماند.
 * همه‌ی نوشتن‌های یک عملیات داخل یک Transaction است (سند فاز ۲ بند ۳۵۷)
 * تا تجمعیِ ردیف‌های بعدی با ثبتِ ناقص از هم نپاشد.
 */
class ActivityService
{
    /** لیست فعالیت‌ها — GET hq/daily-report-activity */
    public static function get_all()
    {
        User::checkAccess(ReportRoles::LIST);

        $params = ListService::params();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 100;
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = DailyReportActivity::find()->with('report');
        ListService::filter($query, $params, ['daily_report_id', 'task_id', 'status']);

        $rows = $query->orderBy(['daily_report_id' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all();

        // عوامل انحرافِ چندتایی (ایشوی ۴) — یک کوئری برای همه‌ی ردیف‌ها
        $rows = DailyReportActivityDeviation::attach($rows);

        // ایشوی ۷.۱: ترتیب بر اساس کد فعالیت، از کم به زیاد
        $rows = ActivityOrder::byWbs($rows);

        return ['success' => true] + ListService::paginate($rows, $perPage, $page);
    }

    /**
     * افزودن یا ویرایش فعالیت.
     *
     * @param int|null $id       شناسه‌ی فعالیت (ویرایش)
     * @param int|null $reportId شناسه‌ی گزارش از مسیر تو در تو (افزودن)
     */
    public static function save($id = null, $reportId = null)
    {
        $params = Yii::$app->request->post();

        if ($id) {
            User::checkAccess(ReportRoles::UPDATE);

            $activity = DailyReportActivity::findOne($id);

            if (!$activity) {
                return ['success' => false, 'message' => 'فعالیت با این شناسه یافت نشد'];
            }
        } else {
            User::checkAccess(ReportRoles::CREATE);

            $activity = new DailyReportActivity();
        }

        // اول ورودی خوانده می‌شود، بعد شناسه‌ی مسیر تو در تو مقدم است
        // تا فعالیت نتواند به گزارش دیگری جابه‌جا شود
        $activity->load($params, '');

        if ($reportId !== null) {
            $activity->daily_report_id = $reportId;
        }

        if (!$activity->validate()) {
            return ['success' => false, 'errors' => $activity->errors];
        }

        $report = $activity->report;
        $chain = CumulativeProgress::chain(
            $report->project_id,
            $activity->task_id,
            $activity->daily_report_id,
            $report->report_date,
            $id ? (int) $id : null,
            (float) $activity->daily_progress
        );

        if ($chain['overflow'] > 0) {
            $activity->addError('daily_progress', CumulativeProgress::overflowError($chain['overflow']));

            return ['success' => false, 'errors' => $activity->errors];
        }

        $activity->cumulative_progress = $chain['pending'];

        $transaction = Yii::$app->db->beginTransaction();

        try {
            if (!$activity->save()) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => $activity->errors];
            }

            // ثبتِ دوبارهٔ کاربر قبرِ حذفِ قبلی را لغو می‌کند؛ وگرنه ردیف و قبرِ
            // آن هم‌زمان می‌مانند و حذفِ بعدیِ همین ردیف به کلیدِ یکتا می‌خورد
            DailyReportActivityRemoved::unmark($activity->daily_report_id, $activity->task_id);

            // عوامل انحرافِ چندتایی (ایشوی ۴) — خارج از rules چون ستونِ آرایه‌ای نیست
            $deviationError = self::syncDeviations($activity, $params);

            if ($deviationError !== null) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => ['deviation_reason_ids' => [$deviationError]]];
            }

            self::apply($chain['rows'], $id);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['success' => true, 'data' => $activity];
    }

    /**
     * جایگزینیِ عوامل انحرافِ انتخاب‌شده در فرمِ «مشخصات فعالیت» (ایشوی ۴).
     *
     * ورودی می‌تواند آرایه باشد (`[1,3]`) یا رشته‌ی جداشده با کاما (`"1,3"`)؛
     * کلیدِ نیامده یعنی دست نزن (PATCH فقط چند فیلد می‌فرستد).
     *
     * @return string|null پیام خطا یا null اگر همه‌چیز درست بود
     */
    private static function syncDeviations(DailyReportActivity $activity, array $params)
    {
        if (!array_key_exists('deviation_reason_ids', $params)) {
            return null;
        }

        $raw = $params['deviation_reason_ids'];

        if (!is_array($raw)) {
            $raw = trim((string) $raw) === '' ? [] : explode(',', (string) $raw);
        }

        if (!DailyReportActivityDeviation::sync((int) $activity->id, $raw)) {
            return 'یکی از عوامل انحراف انتخاب‌شده یافت نشد';
        }

        return null;
    }

    /**
     * حذف فعالیت؛ چون جمعِ زیرینِ ردیف‌های بعدی عوض می‌شود، تجمعی همه‌ی
     * ردیف‌های همان تسک دوباره حساب می‌شود.
     *
     * حذف، قبر هم می‌گذارد: پر کردنِ خودکارِ فعالیت‌ها حالا هنگامِ باز کردنِ
     * گزارش هم اجرا می‌شود، و بدونِ این قبر هر بار که گزارش باز شود همین ردیف
     * دوباره ساخته می‌شد و حذفِ کاربر بی‌اثر می‌ماند.
     */
    public static function delete($id)
    {
        User::checkAccess(ReportRoles::DELETE);

        $activity = DailyReportActivity::findOne($id);

        if (!$activity) {
            return ['success' => false, 'message' => 'فعالیت با این شناسه یافت نشد'];
        }

        $projectId = $activity->report->project_id;
        $reportId = $activity->daily_report_id;
        $taskId = $activity->task_id;

        $transaction = Yii::$app->db->beginTransaction();

        try {
            DailyReportActivityRemoved::mark($reportId, $taskId);
            $activity->delete();
            self::apply(CumulativeProgress::recompute($projectId, $taskId)['rows'], null);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['success' => true];
    }

    /**
     * نوشتن مقادیر تجمعیِ محاسبه‌شده.
     * ردیفِ در حال ذخیره (excludeId) قبلاً با مقدار درست نوشته شده، پس رد می‌شود.
     * `updateAll` عمداً استفاده می‌شود: اینجا مقدار محاسبه‌ای است نه ورودی کاربر،
     * پس نیازی به دوباره اجرای قواعد و snapshot نیست.
     */
    private static function apply(array $rows, $excludeId)
    {
        foreach ($rows as $activityId => $value) {
            if ($excludeId && (int) $activityId === (int) $excludeId) {
                continue;
            }

            DailyReportActivity::updateAll(['cumulative_progress' => $value], ['id' => $activityId]);
        }
    }
}
