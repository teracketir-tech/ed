<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\User;
use yii\db\Query;

/**
 * پیشرفتِ قبلیِ هر تسک در پروژه — ایشوی ۱۱.
 *
 * دیالوگِ فعالیت هنگام انتخاب تسک باید بداند «این تسک قبلاً چقدر پیش رفته» تا
 * نوارِ «درصد پیشرفت» از ادامه‌ی همان عدد بیاید نه هر بار از صفر.
 *
 * قاعده باید عیناً همان چیزی باشد که `CumulativeProgress::chain()` پیش از
 * نوشتنِ ردیفِ جاری به عنوان `base` می‌بیند: جمعِ `daily_progress` ردیف‌هایِ
 * پیش از این گزارش — یعنی تاریخِ کوچک‌تر، یا هم‌تاریخِی که شماره‌ی گزارشِ
 * کوچک‌تری دارد (چون chain بر اساس [تاریخ، شماره‌ی گزارش، شناسه] مرتب می‌کند).
 * ردیف‌های همین گزارش جزو «قبلی» نیستند: کارِ همین گزارش است، نه سابقه.
 *
 * اگر این برابری برقرار نباشد نوارِ فرانت عددِ کمتری نشان می‌دهد و ذخیره‌ی
 * سرور ناگهان «بیش از ۱۰۰ درصد» می‌دهد؛ `tests/TaskProgressTest` همین را می‌سنجد.
 */
class TaskProgress
{
    /**
     * پیشرفتِ قبلیِ همه‌ی تسک‌های پروژه‌ی این گزارش.
     *
     * خروجی `{task_id: درصد}` است؛ تسکِ بدون سابقه در پاسخ نیست و فرانت
     * نبودِ کلید را صفر می‌گیرد (نوار از صفر شروع می‌شود).
     *
     * @param int|string $reportId
     *
     * @return array{success: bool, data?: array<string, float>, message?: string}
     */
    public static function forReport($reportId)
    {
        User::checkAccess(ReportRoles::VIEW);

        $report = DailyReport::find()
            ->select(['id', 'project_id', 'report_date'])
            ->where(['id' => $reportId])
            ->asArray()
            ->one();

        if (!$report) {
            return ['success' => false, 'message' => 'گزارش با این شناسه یافت نشد'];
        }

        return ['success' => true, 'data' => self::bases($report)];
    }

    /**
     * پایهٔ تجمعیِ همهٔ تسک‌های پروژهٔ همان گزارش — بدونِ قاعدهٔ دسترسی، تا
     * سرویس‌های داخلی (مثل `ReportActivityFiller`) همان کوئری را دوباره ننویسند.
     *
     * @param array $report ردیفِ گزارش با `id`، `project_id` و `report_date`
     *
     * @return array<string, float>
     */
    public static function bases(array $report): array
    {
        $rows = (new Query())
            ->select(['task_id', 'total' => 'SUM(a.daily_progress)'])
            ->from(['a' => 'daily_report_activities'])
            ->innerJoin(['r' => 'daily_reports'], 'r.id = a.daily_report_id')
            ->where(['r.project_id' => $report['project_id']])
            ->andWhere([
                'or',
                ['<', 'r.report_date', $report['report_date']],
                ['and', ['r.report_date' => $report['report_date']], ['<', 'r.id', $report['id']]],
            ])
            ->groupBy(['a.task_id'])
            ->all();

        $data = [];

        foreach ($rows as $row) {
            $data[(string) $row['task_id']] = round((float) $row['total'], 2);
        }

        return $data;
    }
}
