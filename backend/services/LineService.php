<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReportDeliveries;
use frontend\modules\hq\models\DailyReportEquipment;
use frontend\modules\hq\models\DailyReportManpower;
use frontend\modules\hq\models\DailyReportMaterials;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\UsersLog;
use Yii;

/**
 * CRUD یکسانِ پنج جدول زیرمجموعه‌ی گزارش روزانه.
 *
 * الگوی ایشوی ۲ و ۳ (مدلِ توصیفی + CRUD در سرویس) اینجا به‌صورت generic
 * درمی‌آید: پنج جدول فقط در ستون‌ها فرق دارند، منطقِ یکی را نباید پنج بار کپی کرد.
 *
 * برای هر ذخیره تراکنش لازم نیست چون هر عملیات یک INSERT/UPDATE بیشتر نیست؛
 * تراکنشِ چندمرحله‌ایِ واقعیِ سند (بند ۳۵۷) به «ثبت نهایی» مربوط است (ایشوی ۵).
 */
class LineService
{
    /**
     * نام هر زیرمجموعه (در URL و کلیدِ پاسخِ view) => مدل آن
     */
    public const MODELS = [
        'manpower' => DailyReportManpower::class,
        'equipment' => DailyReportEquipment::class,
        'materials' => DailyReportMaterials::class,
        'deliveries' => DailyReportDeliveries::class,
        // The timesheet tab writes the shared fingerprint log, not a
        // daily-report-owned table: `users_logs` keeps its own columns and
        // only gains the three daily-report stamps (issue 1).
        'timesheets' => UsersLog::class,
    ];

    /**
     * Issue 4: these two are owned by the REPORT itself (one row set per
     * day, edited from the first tab) — the other three stay activity-owned.
     */
    public const REPORT_SCOPED = ['deliveries', 'timesheets'];

    /** ایشوی ۴: آیا این زیرمجموعه مالِ خودِ گزارش است؟ */
    public static function isReportScoped(string $line): bool
    {
        return in_array($line, self::REPORT_SCOPED, true);
    }

    /**
     * مدلِ یک زیرمجموعه با نامش — نامِ ناشناخته (تایپو در URL) همین‌جا
     * رد می‌شود تا به کوئریِ SQL خطادار نرسد.
     *
     * @return class-string|null
     */
    public static function model(string $line)
    {
        return self::MODELS[$line] ?? null;
    }

    /** لیست ردیف‌های یک زیرمجموعه — GET hq/daily-report-{line} */
    public static function index(string $modelClass): array
    {
        User::checkAccess(ReportRoles::LIST);

        $params = ListService::params();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 100;
        $page = max(1, (int) ($params['page'] ?? 1));

        // فقط ستون‌هایی که واقعاً در همین جدول هستند؛ task_id مثلاً در
        // تحویل و تایم‌شیت وجود ندارد و فیلترش SQL خطادار می‌سازد.
        // ایشوی ۱۰ و ۴: فیلترِ اصلیِ فرانت «فعالیت» برای سه زیرمجموعه و
        // «گزارش» برای تحویل کالا و تایم‌شیت است (هر دو کلید همین‌جا پذیرفته‌اند).
        $columns = $modelClass::getTableSchema()->columnNames;
        $keys = array_values(array_intersect(['daily_report_activity_id', 'daily_report_id', 'task_id'], $columns));

        $query = ListService::filter($modelClass::find(), $params, $keys);

        $rows = $query->orderBy(['daily_report_id' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all();

        return ['success' => true] + ListService::paginate($rows, $perPage, $page);
    }

    /** خواندن یک ردیف — GET hq/daily-report-{line}/{id} */
    public static function view(string $modelClass, $id): array
    {
        User::checkAccess(ReportRoles::VIEW);

        $row = $modelClass::find()->where(['id' => $id])->asArray()->one();

        if (!$row) {
            return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
        }

        return ['success' => true, 'data' => $row];
    }

    /**
     * افزودن یا ویرایش یک ردیف.
     *
     * @param class-string $modelClass  مدل زیرمجموعه
     * @param int|null     $id          شناسه‌ی رکورد (ویرایش)
     * @param int|null     $activityId  مالکِ فعالیتی — برای سه زیرمجموعه‌ی فعالیت‌محور
     * @param int|null     $reportId    مالکِ گزارش — برای تحویل کالا و تایم‌شیت (ایشوی ۴)
     */
    public static function save(string $modelClass, $id = null, $activityId = null, $reportId = null): array
    {
        $params = Yii::$app->request->post();

        // ایشوی ۲.۳: مالتیِ نیروها — هر انتخاب یک ردیف می‌شود (نگه‌داشتنِ تعداد
        // و پیمانکار کنارِ هر نیرو پرت بود؛ سمت مشترک از بدنه می‌آید).
        if (!$id && $modelClass === DailyReportManpower::class && !empty($params['person_ids'])) {
            return self::saveMany($modelClass, $params, $activityId);
        }

        if ($id) {
            User::checkAccess(ReportRoles::UPDATE);

            $line = $modelClass::findOne($id);

            if (!$line) {
                return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
            }

            // مالکیت در ویرایشِ ردیف قفل است (هر دو ستون)
            $ownerActivity = $line->daily_report_activity_id;
            $ownerReport = $line->daily_report_id;
        } else {
            User::checkAccess(ReportRoles::CREATE);

            $line = new $modelClass();
            $ownerActivity = null;
            $ownerReport = null;
        }

        // اول ورودی خوانده می‌شود، بعد مالکِ ردیف مقدم است تا نه مسیر و نه
        // بدنه نتوانند ردیف را به فعالیت/گزارشِ دیگری جابه‌جا کنند (ایشوی ۱۰ و ۴)
        $line->load($params, '');

        if ($id) {
            $line->daily_report_activity_id = $ownerActivity;
            $line->daily_report_id = $ownerReport;
        } elseif ($activityId !== null) {
            $line->daily_report_activity_id = $activityId;
        } elseif ($reportId !== null) {
            $line->daily_report_id = $reportId;
        }

        if (!$line->save()) {
            return ['success' => false, 'errors' => $line->errors];
        }

        return ['success' => true, 'data' => $line];
    }

    /**
     * افزودنِ یک ردیف — دو مسیر، یک تابع.
     *
     * POST hq/daily-report/activity/<activityId>/{line} برای نیرو/ماشین/مصالح
     * (ایشوی ۱۰) و POST hq/daily-report/{id}/{line} برای تحویل کالا و تایم‌شیت
     * (ایشوی ۴). مالکِ ردیف همیشه از مسیر می‌آید و ورودیِ کاربر آن را
     * تعیین نمی‌کند؛ `validateActivity` / `beforeSave` بقیه را روشن می‌کنند.
     */
    public static function create(string $line, $ownerId): array
    {
        $modelClass = self::model($line);

        if ($modelClass === null) {
            return ['success' => false, 'message' => 'نوع زیرمجموعه نامعتبر است'];
        }

        if (self::isReportScoped($line)) {
            return self::save($modelClass, null, null, $ownerId);
        }

        return self::save($modelClass, null, $ownerId);
    }

    /**
     * افزودنِ هم‌زمانِ چند نیرو در یک سطر فرم (ایشوی ۲.۳).
     *
     * `person_ids` آرایه است یا رشته‌ی جداشده با کاما؛ سمت و سایر ستون‌ها از
     * همان بدنه‌ی تکی خوانده می‌شوند. همه‌ی ردیف‌ها داخلِ یک تراکنش ثبت
     * می‌شوند تا اگر نیرویِ آخر نامعتبر بود، نیروهایِ اول نیمه‌کاره نمانند.
     *
     * @param class-string $modelClass
     */
    private static function saveMany(string $modelClass, array $params, $activityId): array
    {
        User::checkAccess(ReportRoles::CREATE);

        $raw = $params['person_ids'];

        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        $ids = [];

        foreach ($raw as $personId) {
            $personId = (int) $personId;

            if ($personId > 0) {
                $ids[$personId] = $personId;
            }
        }

        if (!$ids) {
            return ['success' => false, 'message' => 'دست‌کم یک نیرو انتخاب کنید'];
        }

        $created = null;
        $transaction = Yii::$app->db->beginTransaction();

        try {
            foreach ($ids as $personId) {
                $line = new $modelClass();
                $line->load($params, '');
                $line->person_id = $personId;
                $line->daily_report_activity_id = $activityId;

                if (!$line->save()) {
                    $transaction->rollBack();

                    return ['success' => false, 'errors' => $line->errors];
                }

                $created = $created ?? $line;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return ['success' => true, 'data' => $created];
    }

    /**
     * Delete — DELETE hq/daily-report-{line}/{id}
     *
     * Feedback 1: "the source log must never be deleted." The timesheet tab no
     * longer owns a table of its own — it sits on `users_logs` — so "deleting"
     * a row of that tab may only drop the report / activity / company stamps,
     * which returns the row to its own day's pool. The device row itself stays
     * forever.
     *
     * Every other line kind keeps its previous behaviour (a real delete).
     */
    public static function delete(string $modelClass, $id): array
    {
        User::checkAccess(ReportRoles::DELETE);

        $line = $modelClass::findOne($id);

        if (!$line) {
            return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
        }

        if ($modelClass === UsersLog::class) {
            // updateAll deliberately bypasses the model events: the model rules
            // demand "activity ownership" and beforeSave re-creates the stamps —
            // both are exactly what has to be skipped here.
            $modelClass::updateAll(
                [
                    'daily_report_activity_id' => null,
                    'daily_report_id' => null,
                    'company_id' => null,
                ],
                ['id' => (int) $id]
            );

            return ['success' => true, 'released' => true];
        }

        $line->delete();

        return ['success' => true];
    }

    /**
     * هر پنج زیرمجموعه‌ی یک گزارش — برای «دریافت کامل گزارش» (بند ۱۴).
     * فقط ردیف‌ها را برمی‌گرداند؛ سربرگ و فعالیت‌ها جای دیگر پر می‌شوند.
     */
    public static function rows($reportId): array
    {
        $rows = [];

        foreach (self::MODELS as $line => $modelClass) {
            $rows[$line] = $modelClass::find()
                ->where(['daily_report_id' => $reportId])
                ->orderBy(['id' => SORT_ASC])
                ->asArray()
                ->all();
        }

        return $rows;
    }
}
