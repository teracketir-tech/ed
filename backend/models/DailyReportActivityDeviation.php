<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

/**
 * رابطه‌ی «فعالیت ↔ عامل انحراف» (ایشوی ۴).
 *
 * فرمِ «مشخصات فعالیت» حالا چند عامل انحراف را با سلکتِ مالتی می‌گیرد؛
 * ستونِ تکیِ `deviation_reason_id` روی خودِ فعالیت حذف شد چون نمی‌توانست
 * بیش از یک مقدار نگه دارد. حذفِ فعالیت، ردیف‌هایش را CASCADE می‌کند و
 * حذفِ عاملِ انحرافِ استفاده‌شده هم با `ReferenceListService::REFS` رد می‌شود
 * پس داده‌ی خام از کنارِ فعالیت پخش نمی‌شود.
 */
class DailyReportActivityDeviation extends ActiveRecord
{
    public static function tableName()
    {
        return 'daily_report_activity_deviation';
    }

    public function rules()
    {
        return [
            [['daily_report_activity_id', 'deviation_reason_id'], 'required'],
            [['daily_report_activity_id', 'deviation_reason_id'], 'integer'],
            [
                ['daily_report_activity_id'],
                'exist',
                'targetClass' => DailyReportActivity::class,
                'targetAttribute' => 'id',
                'message' => 'فعالیت انتخاب‌شده یافت نشد',
            ],
            [
                ['deviation_reason_id'],
                'exist',
                'targetClass' => DailyReportDeviationReason::class,
                'targetAttribute' => 'id',
                'message' => 'عامل انحراف انتخاب‌شده یافت نشد',
            ],
        ];
    }

    /**
     * جایگزینیِ کاملِ عواملِ یک فعالیت — حذفِ اضافه‌ها و افزودنِ تازه‌ها
     * داخلِ تراکنشِ خودِ فراخواننده انجام می‌شود (ActivityService).
     *
     * @param int $activityId
     * @param int[] $reasonIds
     *
     * @return bool false یعنی دست‌کم یک شناسه معتبر نبود
     */
    public static function sync($activityId, array $reasonIds): bool
    {
        $ids = [];

        foreach ($reasonIds as $reasonId) {
            $reasonId = (int) $reasonId;

            if ($reasonId > 0) {
                $ids[$reasonId] = $reasonId;
            }
        }

        if (!self::validateAll($ids)) {
            return false;
        }

        $current = array_column(
            self::find()->where(['daily_report_activity_id' => $activityId])->asArray()->all(),
            'deviation_reason_id'
        );

        $remove = array_diff($current, $ids);

        if ($remove) {
            self::deleteAll(['daily_report_activity_id' => $activityId, 'deviation_reason_id' => array_values($remove)]);
        }

        $add = array_values(array_diff($ids, $current));

        foreach ($add as $reasonId) {
            $row = new self();
            $row->daily_report_activity_id = $activityId;
            $row->deviation_reason_id = $reasonId;

            if (!$row->save()) {
                return false;
            }
        }

        return true;
    }

    /** همه‌ی شناسه‌ها باید عاملِ انحرافِ موجود باشند تا FK خطای ۵۰۱ ندهد */
    private static function validateAll(array $reasonIds): bool
    {
        if (!$reasonIds) {
            return true;
        }

        $found = DailyReportDeviationReason::find()
            ->select(['id'])
            ->where(['id' => array_values($reasonIds)])
            ->column();

        return count($found) === count($reasonIds);
    }

    /**
     * افزودنِ `deviation_reason_ids` به ردیف‌های آرایه‌ایِ فعالیت‌ها — یک کوئری
     * برای همه‌ی ردیف‌ها و بدونِ حلقه‌ی N+1. هم از لیست فعالیت‌ها (ActivityService)
     * و هم از «دریافت کامل گزارش» (ReportService::view) استفاده می‌شود.
     *
     * @param array<int, array> $rows ردیف‌های asArray با کلید `id`
     *
     * @return array<int, array> همان ردیف‌ها با `deviation_reason_ids`
     */
    public static function attach(array $rows): array
    {
        $map = self::idsByActivity(array_column($rows, 'id'));

        foreach ($rows as &$row) {
            $row['deviation_reason_ids'] = $map[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    /** شناسه‌های چند فعالیت در یک کوئری — برای پاسخِ لیستِ فعالیت‌ها */
    public static function idsByActivity(array $activityIds): array
    {
        $map = [];

        if (!$activityIds) {
            return $map;
        }

        $rows = self::find()
            ->select(['daily_report_activity_id', 'deviation_reason_id'])
            ->where(['daily_report_activity_id' => $activityIds])
            ->orderBy(['deviation_reason_id' => SORT_ASC])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $map[(int) $row['daily_report_activity_id']][] = (int) $row['deviation_reason_id'];
        }

        return $map;
    }
}
