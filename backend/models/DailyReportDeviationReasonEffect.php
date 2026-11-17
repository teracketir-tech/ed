<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

/**
 * جدولِ رابطه‌ی «اثر غالب» — ایشوی ۱۶
 *
 * سلکتِ مالتیِ اثر غالب نباید در ستونِ متنیِ JSON ذخیره شود: هر اثرِ انتخاب‌شده
 * یک عددِ جدا می‌گیرد تا قاعده‌ی «سلکت = عدد در دیتابیس» برقرار بماند و فیلترِ
 * «هر کدام از انتخاب‌ها» با EXISTS انجام شود. حذفِ ردیفِ مادر با `ON DELETE
 * CASCADE` همین جدول را هم خالی می‌کند.
 */
class DailyReportDeviationReasonEffect extends ActiveRecord
{
    public static function tableName()
    {
        return 'daily_report_deviation_reason_effect';
    }

    public function rules()
    {
        return [
            [['reason_id', 'effect_id'], 'required'],
            [['reason_id', 'effect_id'], 'integer'],
        ];
    }

    /** اثرهای یک دلیل، به ترتیبِ فهرستِ گزینه‌ها (۱،۲،۳) */
    public static function idsFor(int $reasonId): array
    {
        $column = self::find()
            ->select('effect_id')
            ->where(['reason_id' => $reasonId])
            ->orderBy(['effect_id' => SORT_ASC])
            ->column();

        return array_map('intval', $column);
    }

    /**
     * اثرهای چند دلیل با یک کوئری — فهرستِ صفحه‌ای نباید به تعداد ردیف کوئری بزند
     *
     * @return array<int, array<int, int>> شناسه‌ی دلیل => اثرها
     */
    public static function idsForMany(array $reasonIds): array
    {
        $map = [];

        if (!$reasonIds) {
            return $map;
        }

        $rows = self::find()
            ->select(['reason_id', 'effect_id'])
            ->where(['reason_id' => $reasonIds])
            ->orderBy(['effect_id' => SORT_ASC])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            $map[(int) $row['reason_id']][] = (int) $row['effect_id'];
        }

        return $map;
    }

    /** جایگزینی همه‌ی ردیف‌های یک دلیل با انتخابِ تازه‌ی فرم */
    public static function replace(int $reasonId, array $ids): void
    {
        $values = [];

        foreach (array_unique(array_map('intval', $ids)) as $effectId) {
            if ($effectId > 0) {
                $values[] = [$reasonId, $effectId];
            }
        }

        $connection = self::getDb();
        $connection->createCommand()->delete(self::tableName(), ['reason_id' => $reasonId])->execute();

        if ($values) {
            $connection->createCommand()
                ->batchInsert(self::tableName(), ['reason_id', 'effect_id'], $values)
                ->execute();
        }
    }
}
