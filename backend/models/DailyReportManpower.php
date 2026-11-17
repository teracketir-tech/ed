<?php

namespace frontend\modules\hq\models;

/**
 * نیروی انسانی در حال کار طی روز — جدول `daily_report_manpower`.
 *
 * «سمت» جدول جدا نیست: کامنت دیتابیس می‌گوید مقادیر همان `contact.type` است
 * (10..30 یا 1..2 بسته به استقرار)، پس فقط `> 0` اعتبارسنجی می‌شود — همان
 * `chk_drm_pos` در SQL — و نه یک enum بسته که استقرارِ دیگر را بشکند.
 */
class DailyReportManpower extends DailyReportLine
{
    public static function tableName()
    {
        return 'daily_report_manpower';
    }

    public function rules()
    {
        return array_merge($this->activityRules(), [
            [['position_type'], 'required'],
            [['daily_report_id', 'task_id', 'contractor_id', 'person_id'], 'integer'],
            [['position_type'], 'integer', 'min' => 1],
            [['quantity'], 'number', 'min' => 0],
            [['person_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'نیروی انتخاب‌شده یافت نشد'],
            [['contractor_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'پیمانکار انتخاب‌شده یافت نشد'],
            [['daily_report_id'], 'validateReport'],
            [['task_id'], 'validateTask'],
        ]);
    }

    /**
     * ایشوی ۲.۳: هر ردیف یک نیروی مشخص با یک سمت است؛ «تعداد» از فرم حذف شد
     * پس ستونِ NOT NULL به‌صورت پیش‌فرض ۰ می‌نشیند و باید ۱ شود. ردیفِ قدیمیِ
     * دارای تعدادِ صفر هم بی‌معناست پس همین شرط هر دو را می‌پوشاند؛ اگر
     * کلاینتی صریحِ تعدادِ بزرگ‌تر فرستاد (نسخه‌های قدیمی) دست نخورده می‌ماند.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if ($this->person_id && (float) $this->quantity <= 0) {
            $this->quantity = 1;
        }

        return true;
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
            'position_type' => 'سمت',
            'person_id' => 'نیرو',
            'quantity' => 'تعداد',
            'task_id' => 'کد فعالیت مرتبط',
            'contractor_id' => 'پیمانکار',
        ];
    }
}