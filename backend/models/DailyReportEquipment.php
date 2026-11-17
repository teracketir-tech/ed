<?php

namespace frontend\modules\hq\models;

/**
 * ماشین‌آلات و تجهیزات مورد استفاده طی روز — جدول `daily_report_equipment`.
 *
 * سه شمارنده (فعال/غیرفعال/تعمیرات) NOT NULL و پیش‌فرضشان صفر است؛
 * برای همین required نیستند و اگر نیایند همان صفرِ دیتابیس می‌نشیند
 * (رشته‌ی خالی هم در `DailyReportLine::beforeValidate` به پیش‌فرض بدل می‌شود).
 */
class DailyReportEquipment extends DailyReportLine
{
    public static function tableName()
    {
        return 'daily_report_equipment';
    }

    public function rules()
    {
        return array_merge($this->activityRules(), [
            [['equipment_type_id'], 'required'],
            [['daily_report_id', 'equipment_type_id', 'task_id', 'contractor_id'], 'integer'],
            [['active_count', 'inactive_count', 'repair_count'], 'integer', 'min' => 0],
            [['equipment_type_id'], 'exist', 'targetClass' => DailyReportEquipmentType::class, 'targetAttribute' => 'id', 'message' => 'نوع ماشین‌آلات انتخاب‌شده یافت نشد'],
            [['contractor_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'پیمانکار انتخاب‌شده یافت نشد'],
            [['daily_report_id'], 'validateReport'],
            [['task_id'], 'validateTask'],
        ]);
    }

    public function getType()
    {
        return $this->hasOne(DailyReportEquipmentType::class, ['id' => 'equipment_type_id']);
    }

    public function getTask()
    {
        return $this->hasOne(Task::class, ['id' => 'task_id']);
    }

    public function getContractor()
    {
        return $this->hasOne(Contact::class, ['id' => 'contractor_id']);
    }

    public function attributeLabels()
    {
        return [
            'equipment_type_id' => 'نوع ماشین‌آلات',
            'active_count' => 'فعال',
            'inactive_count' => 'غیرفعال',
            'repair_count' => 'تعمیرات',
            'task_id' => 'کد فعالیت مرتبط',
            'contractor_id' => 'پیمانکار',
        ];
    }
}
