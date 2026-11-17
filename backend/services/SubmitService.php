<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\User;
use Yii;

/**
 * «ثبت نهایی» گزارش روزانه — POST hq/daily-report/{id}/submit
 * (سند فاز ۲ بندهای ۱۴ و ۳۵۷).
 *
 * جدا از ReportService تا هر فایل زیر ۲۰۰ خط بماند (همان الگوی
 * ActivityService / LineService).
 *
 * ترتیبِ کار مهم است: اول قواعدِ سربرگ (بند ۱۷) بدون هیچ نوشتنی بررسی می‌شود؛
 * بعد سربرگ و وضعیت داخل یک Transaction نوشته می‌شود و همان‌جا همه‌ی ردیف‌ها
 * هم دوباره بررسی می‌شوند. اگر هر بخشی ناقص باشد rollback می‌شود، پس گزارشِ
 * ناقص هرگز ذخیره نمی‌ماند (سند فاز ۲ بند ۳۵۷).
 *
 * نقش: UPDATE — «ثبت نهایی» تغییر وضعیتِ خودِ گزارش است و همان اختیارِ
 * ویرایشِ آن را می‌خواهد؛ بلوک نقش فاز ۲ کد اختصاصی برایش ندارد.
 *
 * `status` و `submitted_at` در rules مدل نیستند، پس از مسیر `update`
 * قابل نوشتن نیستند و فقط همین اکشن آن‌ها را عوض می‌کند.
 */
class SubmitService
{
    public static function submit($id)
    {
        User::checkAccess(ReportRoles::UPDATE);

        $report = DailyReport::findOne($id);

        if (!$report) {
            return ['success' => false, 'message' => 'گزارش روزانه با این شناسه یافت نشد'];
        }

        if ((int) $report->status === ReportRules::STATUS_SUBMITTED) {
            return ['success' => false, 'message' => 'این گزارش قبلاً ثبت نهایی شده است'];
        }

        // تغییراتِ آخرِ سربرگ می‌تواند همراه همین درخواست بیاید؛ ردیف‌ها از
        // endpointهای خودشان ثبت می‌شوند و اینجا فقط خوانده و کنترل می‌شوند.
        $report->load(Yii::$app->request->post(), '');

        if (!$report->validate()) {
            return ['success' => false, 'errors' => $report->errors];
        }

        $report->status = ReportRules::STATUS_SUBMITTED;
        $report->submitted_at = date('Y-m-d H:i:s');

        $transaction = Yii::$app->db->beginTransaction();

        try {
            if (!$report->save(false)) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => $report->errors];
            }

            // ردیف‌ها بعد از نوشتنِ سربرگ بررسی می‌شوند، نه قبلش: اعتبارسنجیِ
            // یک ردیف، سربرگ را از خودِ دیتابیس می‌خواند (روابط ActiveRecord)،
            // پس فقط بعد از نوشتن است که تغییرِ سربرگ (مثلاً پروژه) در بررسیِ
            // ردیف دیده می‌شود. اگر ردیفی ناسازگار درآمد rollback می‌شود و
            // هیچ‌چیزی از گزارش ناقص نمی‌ماند.
            $incomplete = self::incomplete($report);

            if ($incomplete) {
                $transaction->rollBack();

                return ['success' => false, 'message' => 'برخی ردیف‌های این گزارش نامعتبرند', 'errors' => $incomplete];
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['success' => true, 'data' => $report];
    }

    /**
     * ردیف‌های ناقص گزارش — خطاها به تفکیکِ نام همان بخش‌ها کلید می‌خورند
     * (همان کلیدهایی که `view` برمی‌گرداند) و هر کدام زیر شناسه‌ی ردیف.
     *
     * ردیف‌ها موقع ذخیره هم اعتبارسنجی شده‌اند؛ اینجا دوباره خوانده می‌شوند
     * چون «ثبت نهایی» باید پیش از ارسال مطمئن شود چیزی که قرار است نهایی
     * شود سالم است (سند فاز ۲ بند ۳۵۷: «تا گزارش ناقص ذخیره نشود»).
     */
    private static function incomplete(DailyReport $report): array
    {
        $errors = [];

        foreach (DailyReportActivity::findAll(['daily_report_id' => $report->id]) as $row) {
            if (!$row->validate()) {
                $errors['activities'][$row->id] = $row->errors;
            }
        }

        foreach (LineService::MODELS as $line => $modelClass) {
            foreach ($modelClass::findAll(['daily_report_id' => $report->id]) as $row) {
                if (!$row->validate()) {
                    $errors[$line][$row->id] = $row->errors;
                }
            }
        }

        return $errors;
    }
}
