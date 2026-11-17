<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

/**
 * نوع ماشین‌آلات/تجهیزات — جدول مرجع `daily_report_equipment_type`.
 *
 * یازده مقدار این جدول از شیت Data اکسل آمده و ایشوی ۱ آن‌ها را seed کرده است.
 * برخلاف بقیه‌ی لیست‌های چندمقداریِ فاز ۲ جدول است نه enum در کد، چون
 * `daily_report_equipment.equipment_type_id` به آن FK از نوع RESTRICT دارد
 * و کاربر بعداً می‌تواند نوع تجهیز تازه ثبت کند.
 *
 * endpoint خودش ندارد (سند فاز ۲ بند ۱۴ فقط گزارش و پنج زیرمجموعه را می‌خواهد)؛
 * اینجا صرفاً برای قاعده‌ی `exist` و رابط‌های مدل لازم است.
 */
class DailyReportEquipmentType extends ActiveRecord
{
    use HasTimestamps;

    public static function tableName()
    {
        return 'daily_report_equipment_type';
    }

    public function rules()
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 100],
            [['company_id', 'sort_order'], 'integer'],
        ];
    }

    /** گزینه‌های کشویی «نوع ماشین‌آلات» برای فرم: id => نام */
    public static function options($companyId = null): array
    {
        $rows = self::find()
            ->andFilterWhere(['company_id' => $companyId])
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])
            ->asArray()
            ->all();

        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = $row['name'];
        }

        return $options;
    }

    public function attributeLabels()
    {
        return [
            'name' => 'نوع ماشین‌آلات',
            'sort_order' => 'ترتیب نمایش',
            'company_id' => 'شرکت',
        ];
    }
}
