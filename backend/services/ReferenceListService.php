<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\Company;
use frontend\modules\hq\models\DailyReportDeviationReason;
use frontend\modules\hq\models\DailyReportEquipmentType;
use frontend\modules\hq\models\User;
use Yii;
use yii\db\Query;

/**
 * CRUD دو لیستِ مرجعِ گزارش روزانه: نوع ماشین‌آلات و دلیل انحراف — ایشوی ۱۲، ۱۵ و ۱۶.
 *
 * هر دو جدولِ مرجع «نام + شرکت + ترتیب» دارند و دلیل انحراف سه ستونِ
 * تازه‌ی گروه / منشاء انحراف / اثر غالب را هم می‌پذیرد؛ یک منطق برای هر دو
 * کافی است و نباید در دو کنترلر تکرار شود (DRY). الگو همان `LineService` است:
 * کنترلر فقط `$modelClass` را می‌گوید و منطق اینجاست.
 *
 * چیزی که فقط مخصوصِ دلیل انحراف است — فیلتر، اثر غالب و `form_data` — در
 * خودِ مدل و `DeviationOptions` می‌نشیند؛ اینجا فقط پرسیده می‌شود که آیا
 * مدلِ دلیل انحراف است و همان متدها صدا زده می‌شوند.
 *
 * فیلترها ساختارِ صفحه‌ی «کالاها» را دارند: `id` و گروه و منشاء «مساوی»، بقیه
 * جستجوی `like`؛ `company_id` مساویِ شرکتِ انتخاب‌شده به‌علاوه‌ی ردیف‌هایِ بدون
 * شرکت است (دانه‌های اکسل نباید ناپدید شوند) و `effect_ids` مقداری چندتایی از
 * سلکتِ مالتی می‌گیرد («1,3») و ردیفِ دارای «هر کدام» را برمی‌گرداند.
 *
 * نقش‌ها همان بلوکِ «گزارش روزانه» (ایشوی ۱) است: خواندن VIEW و نوشتن
 * CREATE / UPDATE / DELETE.
 */
class ReferenceListService
{
    /**
     * ستون‌هایی که به این لیست اشاره می‌کنند — حذفِ موردِ استفاده‌شده باید با
     * پیامِ روشن رد شود (FK تجهیزات RESTRICT و دلیل انحراف SET NULL است).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REFS = [
        DailyReportEquipmentType::class => ['daily_report_equipment', 'equipment_type_id'],
        // ایشوی ۴: عوامل انحرافِ چندتایی به جدول رابطه رفت
        DailyReportDeviationReason::class => ['daily_report_activity_deviation', 'deviation_reason_id'],
    ];

    /**
     * فهرست با صفحه‌بندی — GET hq/daily-report-{list}
     *
     * ترتیب از `sort_order` می‌آید چون همان ترتیبِ کشوییِ فرمِ گزارش است؛
     * `company_name` با join می‌آید اما گروه و اثر غالب عددی می‌مانند و
     * برچسب‌شان را `form_data` با «عنوان و مقدار» می‌دهد (الگوی `Order::get_all`).
     */
    public static function index(string $modelClass): array
    {
        User::checkAccess(ReportRoles::VIEW);

        $params = ListService::params();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = max(1, (int) ($params['page'] ?? 1));

        $deviation = $modelClass === DailyReportDeviationReason::class;

        $query = $modelClass::find()
            ->alias('t')
            ->select(['t.*', 'company_name' => 'c.name'])
            ->leftJoin(['c' => Company::tableName()], 'c.id = t.company_id');

        if (!empty($params['id'])) {
            $query->andWhere(['t.id' => $params['id']]);
        }

        /**
         * فیلترِ شرکت «مساوی» است ولی ردیف‌هایِ بدون شرکت عمداً می‌مانند:
         * شرکت هم از سلکتِ فیلتر می‌آید و هم روی هر GET از طرف کتابخانه‌ی
         * `api`؛ سخت‌گیری، یازده دانه‌ی اکسلِ بدون شرکت را ناپدید می‌کرد.
         */
        if (!empty($params['company_id'])) {
            $query->andWhere(['or', ['t.company_id' => $params['company_id']], ['t.company_id' => null]]);
        }

        if (!empty($params['name'])) {
            $query->andWhere(['like', 't.name', $params['name']]);
        }

        if ($deviation) {
            $modelClass::applyFilters($query, $params);
        }

        $rows = $query->orderBy(['t.sort_order' => SORT_ASC, 't.id' => SORT_ASC])->asArray()->all();

        if ($deviation) {
            $rows = $modelClass::attachEffects($rows);
        }

        $result = ['success' => true] + ListService::paginate($rows, $perPage, $page);

        return $deviation ? $result + ['form_data' => DeviationOptions::formData()] : $result;
    }

    /** خواندن یک ردیف — GET hq/daily-report-{list}/{id} */
    public static function view(string $modelClass, $id): array
    {
        User::checkAccess(ReportRoles::VIEW);

        $row = $modelClass::find()->where(['id' => $id])->asArray()->one();

        if (!$row) {
            return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
        }

        if ($modelClass === DailyReportDeviationReason::class) {
            $row['effect_ids'] = $modelClass::effectIdsFor($id);
        }

        return ['success' => true, 'data' => $row];
    }

    /**
     * افزودن یا ویرایش — POST / PATCH hq/daily-report-{list}
     *
     * فقط ستون‌هایِ safe از ورودی می‌آیند پس `id` هرگز با بدنه عوض نمی‌شود؛
     * اثر غالب را خودِ مدل در جدولِ رابطه می‌نویسد (ایشوی ۱۶).
     */
    public static function save(string $modelClass, $id = null): array
    {
        if ($id) {
            User::checkAccess(ReportRoles::UPDATE);

            $model = $modelClass::findOne($id);

            if (!$model) {
                return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
            }
        } else {
            User::checkAccess(ReportRoles::CREATE);

            $model = new $modelClass();
        }

        $model->load(Yii::$app->request->post(), '');

        self::normalize($model);

        if (!$model->save()) {
            return ['success' => false, 'errors' => $model->errors];
        }

        return ['success' => true, 'data' => $model];
    }

    /**
     * «انتخاب نشده» نباید به ستونِ عددی برسد (MySQL در حالت strict روی `''`
     * خطا می‌دهد)؛ بقیه‌ی سلکت‌ها الزامی‌اند و اعتبارسنجیِ مدل می‌گیردشان.
     *
     * @param \yii\db\ActiveRecord $model
     */
    private static function normalize($model): void
    {
        if (in_array('company_id', $model->attributes(), true)
            && in_array($model->company_id, ['', null, 0, '0'], true)) {
            $model->company_id = null;
        }
    }

    /** حذف — DELETE hq/daily-report-{list}/{id} */
    public static function delete(string $modelClass, $id): array
    {
        User::checkAccess(ReportRoles::DELETE);

        $model = $modelClass::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'رکورد با این شناسه یافت نشد'];
        }

        $used = self::usedBy($modelClass, $id);

        if ($used > 0) {
            return ['success' => false, 'message' => "این مورد در {$used} ردیف استفاده شده و قابل حذف نیست"];
        }

        $model->delete();

        return ['success' => true];
    }

    /** تعداد ارجاع‌های زنده به این ردیف در جدول‌های وابسته */
    private static function usedBy(string $modelClass, $id): int
    {
        $refs = self::REFS[$modelClass] ?? null;

        if ($refs === null) {
            return 0;
        }

        return (int) (new Query())
            ->from($refs[0])
            ->where([$refs[1] => $id])
            ->count();
    }
}
