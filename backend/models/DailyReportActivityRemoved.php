<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

/**
 * قبرِ فعالیتِ حذف‌شده از گزارشِ روزانه — جدول `daily_report_activity_removed`
 *
 * چرا لازم است: پر کردنِ خودکارِ فعالیت‌ها باید هنگامِ باز کردنِ گزارش هم
 * انجام شود، وگرنه یک پیش‌نویسِ قدیمی برای همیشه خالی می‌ماند. اما اگر حذفِ
 * دستیِ کاربر فقط یک DELETE خالی باشد، هر باز کردنِ بعدی همان ردیف را دوباره
 * می‌سازد و کاربر هیچ‌وقت نمی‌تواند چیزی را کنار بگذارد.
 *
 * این جدول همان تصمیم کاربر را نگه می‌دارد تا پر کردنِ خودکار آن را رد کند.
 * تا وقتی تاریخ/پروژهٔ گزارش عوض نشود فعالیتِ حذف‌شده برنمی‌گردد (این پاک
 * کردن را ReportService::save انجام می‌دهد).
 *
 * عمداً مدلِ کوچکِ AR مانند DailyReportActivityDeviation است: اینجا قاعدهٔ
 * توصیفی نیست، فقط حافظهٔ یک تصمیم است.
 */
class DailyReportActivityRemoved extends ActiveRecord
{
    public static function tableName()
    {
        return 'daily_report_activity_removed';
    }

    public function rules()
    {
        return [
            [['daily_report_id', 'task_id'], 'required'],
            [['daily_report_id', 'task_id'], 'integer'],
        ];
    }

    /**
     * ثبتِ حذفِ عمدیِ یک فعالیت.
     *
     * خطا نتیجه نمی‌شود: اگر همین تسک قبلاً هم حذف شده باشد کلیدِ یکتا تکراری
     * است و آن یعنی دقیقاً همان چیزی که می‌خواستیم، نه اشکال.
     *
     * دقت: `save()` روی کلیدِ یکتا در Yii2 استثنا می‌پراند نه `false`؛ پس اول
     * خودمان می‌پرسیم و بعد همان استثنا را هم می‌گیریم تا دو حذفِ پشت‌سرِ هم
     * سرور را با ۵۰۰ از کار نیندازد (ایشوی ۱۲ — حذفِ فعالیتِ دوباره افزوده‌شده).
     *
     * @return bool آیا این جفت از این پس مسدود است
     */
    public static function mark($reportId, $taskId): bool
    {
        if (self::isRemoved($reportId, $taskId)) {
            return true;
        }

        $row = new self();
        $row->daily_report_id = (int) $reportId;
        $row->task_id = (int) $taskId;
        $row->removed_at = date('Y-m-d H:i:s');

        try {
            if ($row->save()) {
                return true;
            }
        } catch (\yii\db\IntegrityException $e) {
            // مسابقه بر سرِ همان کلید یکتا — نتیجه همان چیزی است که می‌خواستیم
        }

        return self::isRemoved($reportId, $taskId);
    }

    /**
     * برداشتنِ قبر وقتی کاربر همان فعالیت را دوباره ثبت می‌کند.
     *
     * بدونِ این، ردیف و قبرِ آن هم‌زمان می‌مانند و حذفِ بعدیِ همان ردیف به
     * کلیدِ یکتا می‌خورد؛ تصمیمِ تازهٔ کاربر («برگردان») باید قدیمی را لغو کند.
     */
    public static function unmark($reportId, $taskId): void
    {
        self::deleteAll(['daily_report_id' => (int) $reportId, 'task_id' => (int) $taskId]);
    }

    /** این تسک قبلاً از این گزارش حذف شده؟ */
    public static function isRemoved($reportId, $taskId): bool
    {
        return self::find()
            ->where(['daily_report_id' => (int) $reportId, 'task_id' => (int) $taskId])
            ->exists();
    }

    /**
     * شناسه‌های تسک‌هایی که نباید دوباره ساخته شوند.
     *
     * @return int[]
     */
    public static function blockedTaskIds($reportId): array
    {
        return array_map('intval', self::find()
            ->select('task_id')
            ->where(['daily_report_id' => (int) $reportId])
            ->column());
    }

    /**
     * کنار گذاشتنِ همهٔ تصمیم‌هایِ حذفِ این گزارش.
     *
     * عوض شدنِ تاریخ/پروژه یعنی پنجرهٔ برنامه کلاً فرق کرده؛ تصمیمِ «این روز
     * کاری نداشت» به روزِ دیگر ربطی ندارد.
     */
    public static function clear($reportId): void
    {
        self::deleteAll(['daily_report_id' => (int) $reportId]);
    }
}
