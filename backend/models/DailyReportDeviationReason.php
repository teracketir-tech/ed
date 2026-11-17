<?php

namespace frontend\modules\hq\models;

use frontend\modules\hq\services\DeviationOptions;
use yii\db\ActiveRecord;
use yii\db\Query;

/**
 * لیست دلایل انحراف — جدول `daily_report_deviation_reason` (ایشوی ۱۲، ۱۵ و ۱۶)
 *
 * قاعده‌ی کلیِ ایشوی ۱۶: سلکت باید در دیتابیس عدد ذخیره شود و لیست «عنوان و
 * مقدار» بدهد. پس گروه به جدول `group` وصل نیست، منشاء انحراف عددیِ ۱..۲۰ است
 * و به گروهِ خودش وصل می‌شود، و اثر غالب در جدولِ رابطه می‌نشیند نه در ستونِ
 * متنی. خودِ گزینه‌ها و برچسب‌هایشان در `DeviationOptions` است تا فهرست و
 * واژه‌نامه هر دو از یک منبع بخوانند.
 */
class DailyReportDeviationReason extends ActiveRecord
{
    use HasTimestamps;

    /**
     * اثرهای انتخاب‌شده — ستونِ جدا ندارد؛ در `afterSave` به جدولِ رابطه می‌رود
     * و پس از `afterFind` پر می‌شود. `null` یعنی ورودی آن را نیاورده است.
     *
     * @var array<int, int>|null
     */
    public $effect_ids;

    public static function tableName()
    {
        return 'daily_report_deviation_reason';
    }

    public function rules()
    {
        return [
            // به‌جز شرکت، همه‌ی فیلدهای فرم الزامی‌اند (ایشوی ۱۶)
            [['name', 'factor_group_id', 'deviation_source', 'effect_ids'], 'required'],
            [['name'], 'string', 'max' => 100],
            [['company_id', 'factor_group_id', 'deviation_source', 'sort_order'], 'integer'],
            // دامنه‌ها از همان کانستانت‌های `DeviationOptions` می‌آیند تا با شیتِ
            // اکسل یکی بماند — `[1, 20]` یعنی فقط دو مقدار ۱ و ۲۰!
            [['factor_group_id'], 'in', 'range' => array_column(DeviationOptions::FACTOR_GROUP, 'value'), 'message' => 'گروه عامل انحراف انتخاب‌شده معتبر نیست'],
            [['deviation_source'], 'in', 'range' => array_column(DeviationOptions::DEVIATION_SOURCE, 'value'), 'message' => 'منشاء انحراف انتخاب‌شده معتبر نیست'],
            ['deviation_source', 'validateSource'],
            ['effect_ids', 'validateEffects'],
        ];
    }

    /** منشاء باید به همان گروه تعلق داشته باشد (داخلی ۱..۱۳ / خارجی ۱۴..۲۰) */
    public function validateSource($attribute, $params)
    {
        if ((int) $this->factor_group_id < 1) {
            return; // گروه خودش خطای «الزامی» دارد
        }

        foreach (DeviationOptions::DEVIATION_SOURCE as $option) {
            if ($option['value'] !== (int) $this->$attribute) {
                continue;
            }

            if ($option['factor_group'] !== (int) $this->factor_group_id) {
                $this->addError($attribute, 'منشاء انحراف به گروه انتخاب‌شده تعلق ندارد');
            }

            return;
        }
    }

    /** فقط سه اثرِ غالبِ تعریف‌شده پذیرفته می‌شوند */
    public function validateEffects($attribute, $params)
    {
        $valid = array_column(DeviationOptions::EFFECT, 'value');

        foreach ((array) $this->$attribute as $one) {
            if (!in_array((int) $one, $valid, true)) {
                $this->addError($attribute, 'اثر غالب انتخاب‌شده معتبر نیست');

                return;
            }
        }
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->touchTimestamps($insert);

        if ($this->sort_order === null || $this->sort_order === '') {
            $this->sort_order = 0;
        }

        return true;
    }

    /** خواندنِ یک ردیف باید اثرهایش را هم بیاورد (فهرست bulk می‌خواند) */
    public function afterFind()
    {
        parent::afterFind();

        $this->effect_ids = DailyReportDeviationReasonEffect::idsFor((int) $this->id);
    }

    /** نوشتنِ اثرها در جدولِ رابطه — سرویس نباید از ستونِ متنی چیزی بداند */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($this->effect_ids !== null) {
            DailyReportDeviationReasonEffect::replace((int) $this->id, (array) $this->effect_ids);
        }
    }

    /**
     * فیلترهای مخصوصِ این فهرست — گروه و منشاء «مساوی»، اثر غالب «هر کدام»
     *
     * @param \yii\db\ActiveRecordQuery $query
     */
    public static function applyFilters($query, array $params): void
    {
        foreach (['factor_group_id', 'deviation_source'] as $key) {
            if (!empty($params[$key])) {
                $query->andWhere(['t.' . $key => $params[$key]]);
            }
        }

        $effects = [];

        foreach (explode(',', (string) ($params['effect_ids'] ?? '')) as $one) {
            $one = trim($one);

            if ($one !== '' && (int) $one > 0) {
                $effects[] = (int) $one;
            }
        }

        if ($effects) {
            $query->andWhere(['exists', (new Query())
                ->from(DailyReportDeviationReasonEffect::tableName())
                ->where('reason_id = t.id')
                ->andWhere(['effect_id' => $effects])]);
        }
    }

    /** اثرهای چند ردیفِ فهرست با یک کوئری — `[['id' => …, …], …]` را با effect_ids کامل می‌کند */
    public static function attachEffects(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }

        $map = DailyReportDeviationReasonEffect::idsForMany(array_column($rows, 'id'));

        foreach ($rows as &$row) {
            $row['effect_ids'] = $map[(int) $row['id']] ?? [];
        }

        unset($row);

        return $rows;
    }

    /** اثرهای یک ردیفِ تنها — برای خواندنِ تکی */
    public static function effectIdsFor($id): array
    {
        return DailyReportDeviationReasonEffect::idsFor((int) $id);
    }
}
