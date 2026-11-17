<?php

namespace frontend\modules\hq\models;

use frontend\modules\hq\services\ReportRules;

/**
 * تحویل کالا طی روز — جدول `daily_report_deliveries`.
 *
 * برخلاف زیرمجموعه‌هایِ فعالیت‌محور «کد فعالیت مرتبط» ندارد (طبق شیت اکسل و
 * ایشوی ۴): مالکِ ردیف خودِ گزارش است و یک‌بار برای کلِ روز ثبت می‌شود؛
 * کالا هم یا از `product` انتخاب می‌شود یا با `item_name` نوشته می‌شود.
 */
class DailyReportDeliveries extends DailyReportLine
{
    public static function tableName()
    {
        return 'daily_report_deliveries';
    }

    /** ایشوی ۴: مالکِ ردیف گزارش است نه فعالیت */
    protected function reportScoped(): bool
    {
        return true;
    }

    public function rules()
    {
        return array_merge($this->activityRules(), [
            [['action_type', 'item_group', 'delivery_type', 'quantity'], 'required'],
            [['daily_report_id', 'product_id', 'unit_id', 'delivered_from', 'received_by'], 'integer'],
            [['quantity'], 'number'],
            [['action_type'], 'in', 'range' => ReportRules::values(ReportRules::ACTION_TYPE)],
            // ایشوی ۱۰: «گروه کالا» دیگر کشوییِ کالا/تجهیز نیست و متن آزاد
            // می‌نویسد؛ ستون هم به varchar رفت. `strict => false` تا کلاینتِ
            // قدیمی که عدد می‌فرستد (۱/۲) همچنان پذیرفته شود.
            [['item_group'], 'string', 'max' => 100, 'strict' => false],
            [['delivery_type'], 'in', 'range' => ReportRules::values(ReportRules::DELIVERY_TYPE)],
            [['quantity'], 'validateQuantity'],
            [['product_id'], 'exist', 'targetClass' => Product::class, 'targetAttribute' => 'id', 'message' => 'کالای انتخاب‌شده یافت نشد'],
            [['unit_id'], 'exist', 'targetClass' => Unit::class, 'targetAttribute' => 'id', 'message' => 'واحد انتخاب‌شده یافت نشد'],
            [['delivered_from'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'تحویل‌دهنده‌ی انتخاب‌شده یافت نشد'],
            [['received_by'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'تحویل‌گیرنده‌ی انتخاب‌شده یافت نشد'],
            [['description'], 'string'],
            // این قاعده لازم است: بدون آن `item_name` «unsafe» می‌شود و `load()`
            // بی‌صدا آن را کنار می‌گذارد (لاگِ Failed to set unsafe attribute).
            [['item_name'], 'string', 'max' => 255],
        ]);
    }

    /**
     * سند فاز ۲ بند ۱۷: «مقادیر quantity منفی نباشند مگر اینکه نوع اقدام
     * مشخصاً خروج/برگشت را اجازه دهد.» قاعده‌ی `number` عمداً `min` ندارد
     * تا برای «خروج کالا» منفی پذیرفته شود؛ خودِ قاعده در ReportRules است.
     */
    public function validateQuantity($attribute)
    {
        $error = ReportRules::quantityError($this->$attribute, $this->action_type);

        if ($error !== null) {
            $this->addError($attribute, $error);
        }
    }

    protected function textAttributes(): array
    {
        return ['item_name', 'description', 'item_group'];
    }

    /**
     * ایشوی ۱۰: واحد از محصول می‌آید (کاربر دیگر سلکتِ واحد ندارد).
     */
    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        $this->inheritUnitFromProduct();

        return true;
    }

    public function getProduct()
    {
        return $this->hasOne(Product::class, ['id' => 'product_id']);
    }

    public function getUnit()
    {
        return $this->hasOne(Unit::class, ['id' => 'unit_id']);
    }

    public function getDeliveredFrom()
    {
        return $this->hasOne(Contact::class, ['id' => 'delivered_from']);
    }

    public function getReceivedBy()
    {
        return $this->hasOne(Contact::class, ['id' => 'received_by']);
    }

    public function attributeLabels()
    {
        return [
            'action_type' => 'نوع اقدام',
            'item_group' => 'گروه کالا',
            'delivery_type' => 'نوع تحویل',
            'product_id' => 'نام کالا یا تجهیز',
            'item_name' => 'نام کالا یا تجهیز',
            'unit_id' => 'واحد',
            'quantity' => 'مقدار',
            'delivered_from' => 'تحویل از',
            'received_by' => 'تحویل گیرنده',
            'description' => 'توضیح',
        ];
    }
}
