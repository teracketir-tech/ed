<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\DailyReportActivityDeviation;
use frontend\modules\hq\models\DailyReportActivityRemoved;
use frontend\modules\hq\models\User;
use Yii;
use yii\db\Expression;

/**
 * CRUD سربرگ گزارش روزانه — فاز ۲.
 *
 * جدا از مدل تا مدل قاعده‌ی توصیفی (rules / validators / hooks) بماند
 * و فایل زیر ۲۰۰ خط بماند. همین الگو در ActivityService هم به کار رفته است.
 */
class ReportService
{
    /** لیست گزارش‌ها با فیلتر و صفحه‌بندی — GET hq/daily-report */
    public static function get_all()
    {
        User::checkAccess(ReportRoles::LIST);

        $params = ListService::params();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 20;
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = DailyReport::find()->with('project');

        ListService::filter($query, $params, ['project_id', 'report_date', 'site_status']);

        // status=0 (پیش‌نویس) مقدار معتبری است؛ با !empty فیلتر حذف می‌شد
        if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== null) {
            $query->andWhere(['status' => (int) $params['status']]);
        }

        if (!empty($params['date_from'])) {
            $query->andWhere(['>=', 'report_date', $params['date_from']]);
        }

        if (!empty($params['date_to'])) {
            $query->andWhere(['<=', 'report_date', $params['date_to']]);
        }

        $rows = $query->orderBy(['report_date' => SORT_DESC, 'id' => SORT_DESC])->asArray()->all();

        return ['success' => true] + ListService::paginate($rows, $perPage, $page);
    }

    /**
     * خواندن کامل گزارش: سربرگ + فعالیت‌ها + پنج جدول زیرمجموعه.
     * سند فاز ۲ بند ۱۴: «دریافت کامل گزارش روزانه» با یک فراخوانی.
     */
    public static function view($id)
    {
        User::checkAccess(ReportRoles::VIEW);

        $model = DailyReport::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'گزارش روزانه با این شناسه یافت نشد'];
        }

        // ایشوی ۳: باز کردنِ پیش‌نویس هم فعالیت‌هایِ برنامه‌ریزی‌شدهٔ همان روز را
        // می‌سازد، وگرنه یک پیش‌نویسِ قدیمی برای همیشه خالی می‌ماند و فعالیتِ
        // تازه‌ای که بعداً برای همان روز برنامه‌ریزی شده هرگز ظاهر نمی‌شود.
        //
        // fill خودش برای گزارشِ ثبت‌شده بی‌اثر است و قبرِ حذفِ دستیِ کاربر را
        // هم رعایت می‌کند، پس فراخوانیِ بی‌شرطِ آن امن است.
        ReportActivityFiller::fill($model);

        // دوباره خوانده می‌شود چون fill ممکن است ردیفِ تازه ساخته باشد
        $report = DailyReport::find()->with('project')->where(['id' => $id])->asArray()->one();

        $report['activities'] = ActivityOrder::byWbs(DailyReportActivityDeviation::attach(
            DailyReportActivity::find()
                ->where(['daily_report_id' => $id])
                ->orderBy(['id' => SORT_ASC])
                ->asArray()
                ->all()
        ));

        foreach (LineService::rows($id) as $line => $rows) {
            $report[$line] = $rows;
        }

        // ایشوی ۱۸: prepared_by شناسه‌ی کاربر است؛ نامش را برای «تنظیم‌کننده فرم» می‌خوانیم
        $report['prepared_by_name'] = $report['prepared_by']
            ? User::find()->select(new Expression("CONCAT(first_name, ' ', last_name)"))->where(['id' => $report['prepared_by']])->scalar() ?: null
            : null;

        return ['success' => true, 'data' => $report];
    }

    public static function save($id = null)
    {
        $params = Yii::$app->request->post();

        if ($id) {
            User::checkAccess(ReportRoles::UPDATE);

            $report = DailyReport::findOne($id);

            if (!$report) {
                return ['success' => false, 'message' => 'گزارش روزانه با این شناسه یافت نشد'];
            }
        } else {
            User::checkAccess(ReportRoles::CREATE);

            $report = new DailyReport();
        }

        if (!$report->load($params, '')) {
            return ['success' => false, 'errors' => $report->errors];
        }

        // ایشوی ۱۸: «تنظیم‌کننده فرم» از فرم حذف شد و همیشه = کاربرِ ثبت‌کننده؛
        // بعد از load گذاشته می‌شود تا مقدارِ آمده از client آن را بازنویسی نکند.
        if (!$id) {
            $report->prepared_by = Yii::$app->user->identity ? Yii::$app->user->identity->id : null;
        }

        /**
         * ایشوی ۱۲: `isAttributeChanged` مقایسهٔ سخت‌گیرانه است و فرمِ client
         * رشته می‌فرستد (`"150"`) در حالی که مدل عدد است؛ نتیجه این می‌شد که
         * هر ذخیرهٔ سربرگ «جابه‌جاییِ پروژه» حساب می‌شد، قبرِ حذفِ کاربر پاک
         * می‌شد و فعالیتِ حذف‌شده دوباره ساخته می‌شد. مقایسه با مقدارِ
         * قدیمیِ خودِ ستون، یعنی همان چیزی که در دیتابیس است.
         *
         * دقت: باید پیش از save خوانده شود، چون afterSave قدیمی‌ها را با
         * مقدارهای تازه هم‌ارز می‌کند و آن‌وقت دیگر «تغییری» دیده نمی‌شود.
         */
        $rescheduled = !$id
            || (string) $report->getOldAttribute('project_id') !== (string) $report->project_id
            || (string) $report->getOldAttribute('report_date') !== (string) $report->report_date;

        if (!$report->save()) {
            return ['success' => false, 'errors' => $report->errors];
        }

        if ($rescheduled) {
            // پنجرهٔ برنامه عوض شده، پس تصمیمِ «این روز کاری نداشت» به روزِ
            // دیگر ربطی ندارد: قبرها کنار گذاشته می‌شوند، ردیفِ روزِ قدیمی
            // برداشته می‌شود و فعالیت‌هایِ روزِ تازه از نو ساخته می‌شوند.
            DailyReportActivityRemoved::clear($report->id);
            ReportActivityFiller::fill($report, true);
        }

        return ['success' => true, 'data' => $report];
    }

    /**
     * حذف سربرگ؛ همه‌ی جدول‌های زیرمجموعه با ON DELETE CASCADE پاک می‌شوند.
     * (ستون‌هایی که به task وصل‌اند RESTRICT‌اند تا تاریخچه‌ی خودِ گزارش محفوظ بماند.)
     */
    public static function delete($id)
    {
        User::checkAccess(ReportRoles::DELETE);

        $report = DailyReport::findOne($id);

        if (!$report) {
            return ['success' => false, 'message' => 'گزارش روزانه با این شناسه یافت نشد'];
        }

        $report->delete();

        return ['success' => true];
    }
}
