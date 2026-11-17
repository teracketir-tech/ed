<?php

namespace frontend\modules\hq\models;

/**
 * مواد و مصالح مورد استفاده طی روز — جدول `daily_report_materials`.
 *
 * «مصالح» از جدول `product` می‌آید (قرارداد ایشوی ۱: اشخاص→contact،
 * مصالح→product، واحد→unit)؛ خودِ جدولِ مصالحِ پروژه نداریم.
 */
class DailyReportMaterials extends DailyReportLine
{
    public static function tableName()
    {
        return 'daily_report_materials';
    }

    public function rules()
    {
        return array_merge($this->activityRules(), [
            [['product_id', 'quantity'], 'required'],
            [['daily_report_id', 'product_id', 'unit_id', 'task_id', 'contractor_id'], 'integer'],
            [['quantity'], 'number', 'min' => 0],
            [['product_id'], 'exist', 'targetClass' => Product::class, 'targetAttribute' => 'id', 'message' => 'مصالح انتخاب‌شده یافت نشد'],
            [['unit_id'], 'exist', 'targetClass' => Unit::class, 'targetAttribute' => 'id', 'message' => 'واحد انتخاب‌شده یافت نشد'],
            [['contractor_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'پیمانکار انتخاب‌شده یافت نشد'],
            [['daily_report_id'], 'validateReport'],
            [['task_id'], 'validateTask'],
        ]);
    }

    public function getProduct()
    {
        return $this->hasOne(Product::class, ['id' => 'product_id']);
    }

    public function getUnit()
    {
        return $this->hasOne(Unit::class, ['id' => 'unit_id']);
    }

    /**
     * ایشوی ۹: کاربر دیگر سلکتِ واحد ندارد؛ با انتخاب محصول، واحدِ همان
     * محصول جلوی «مقدار» می‌نشیند و همین‌جا داخلِ ردیف کپی می‌شود.
     */
    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        $this->inheritUnitFromProduct();

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
            'product_id' => 'نوع مواد و مصالح',
            'unit_id' => 'واحد',
            'quantity' => 'مقدار',
            'task_id' => 'کد فعالیت مرتبط',
            'contractor_id' => 'پیمانکار',
        ];
    }
}
