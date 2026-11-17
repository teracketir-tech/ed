<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

/**
 * پایه‌ی مشترک پنج جدول زیرمجموعه‌ی گزارش روزانه:
 * نیروی انسانی / تجهیزات / مصالح / تحویل / تایم‌شیت.
 *
 * فقط آنچه واقعاً یکسان است اینجاست: مالکیت (ایشوی ۱۰ و ایشوی ۴)، وجودِ گزارش،
 * تعلّقِ task به همان پروژه، تبدیلِ رشته‌ی خالی و کپیِ company_id.
 * ستون‌ها و قواعدِ اختصاصی در مدل فرزند می‌مانند تا هر فایل کوچک بماند.
 */
abstract class DailyReportLine extends ActiveRecord
{
    use HasTimestamps;

    /**
     * مالکِ ردیف: `true` یعنی سطحِ گزارش، `false` یعنی سطحِ فعالیت.
     *
     * سه زیرمجموعه‌ی نیرو/ماشین‌آلات/مصالح مالِ فعالیت‌اند (ایشوی ۱۰) ولی
     * «تحویل کالا» و «تایم‌شیت» طبق ایشوی ۴ یک‌بار برای کلِ گزارش ثبت
     * می‌شوند و در تبِ نخستِ فرم می‌نشینند؛ پس مالکشان خودِ گزارش است.
     */
    protected function reportScoped(): bool
    {
        return false;
    }

    /**
     * سند فاز ۲ بند ۱۷: «ثبت رکوردهای زیرمجموعه فقط برای daily_report
     * موجود مجاز باشد.» فعالیتِ مالک این شناسه را می‌دهد ولی باز هم بررسی
     * می‌شود تا ردیفِ بدونِ گزارشِ معتبر ساخته نشود.
     */
    public function validateReport($attribute)
    {
        if (!$this->$attribute) {
            return;
        }

        if (!DailyReport::findOne($this->$attribute)) {
            $this->addError($attribute, 'گزارش روزانه‌ی انتخاب‌شده یافت نشد');
        }
    }

    /**
     * قاعده‌های مالکیت — با `array_merge` جلوی قواعدِ مدل فرزند می‌آیند
     * تا پنج بار تکرار نشود (DRY) و **ترتیبشان حفظ شود**: `validateActivity`
     * باید پیش از `validateReport` اجرا شود چون شناسه‌ی گزارش را خودش تعیین می‌کند.
     *
     * ایشوی ۴: زیرمجموعه‌هایِ گزارش‌محور در عوضِ فعالیت، خودِ گزارش را
     * مالک می‌خواهند و فعالیت برایشان نه الزامی است و نه معتبر.
     */
    protected function activityRules(): array
    {
        if ($this->reportScoped()) {
            return [
                [['daily_report_id'], 'required'],
                [['daily_report_id'], 'integer'],
                [['daily_report_id'], 'validateReport'],
            ];
        }

        return [
            [['daily_report_activity_id'], 'required'],
            [['daily_report_activity_id'], 'integer'],
            [['daily_report_activity_id'], 'validateActivity'],
        ];
    }

    /**
     * سند ایشوی ۱۰: «هر ردیفِ زیرمجموعه متعلق به یک فعالیت است.»
     *
     * فعالیت، *مالک*ِ ردیف است و `daily_report_id` از خودش می‌آید نه از ورودی
     * کاربر؛ به همین دلیل نه می‌توان ردیفی را به گزارشِ دیگری بست و نه از
     * فعالیتِ گزارشِ دیگری ردیفی گرفت. `daily_report_id` دیگر «الزامی» نیست
     * چون همیشه از همین‌جا پر می‌شود (و ستونِ NOT NULL پشتش است).
     */
    public function validateActivity($attribute)
    {
        if (!$this->$attribute) {
            return; // پیامِ «الزامی» را قاعده‌ی required می‌دهد
        }

        $activity = DailyReportActivity::findOne($this->$attribute);

        if (!$activity) {
            $this->addError($attribute, 'فعالیت انتخاب‌شده یافت نشد');
            return;
        }

        $this->daily_report_id = $activity->daily_report_id;
    }

    /**
     * سند فاز ۲ بند ۱۷: «task_id باید متعلق به همان project_id باشد.»
     *
     * برخلاف جدول فعالیت‌ها اینجا تکرارِ task ممنوع نیست: هر ردیف یک «منبع»
     * است و یک تسک می‌تواند چند نیرو/تجهیز/مصالح داشته باشد.
     */
    public function validateTask($attribute)
    {
        $report = $this->report;

        if (!$report || !$this->$attribute) {
            return;
        }

        $task = Task::findOne($this->$attribute);

        if (!$task) {
            $this->addError($attribute, 'تسک انتخاب‌شده یافت نشد');
        } elseif ($task->project_id != $report->project_id) {
            $this->addError($attribute, 'تسک باید متعلق به همین پروژه باشد');
        }
    }

    /**
     * رشته‌ی خالی (`""`) که از سمت JSON می‌آید باید جایگزینِ درستِ خود را بگیرد:
     *   - ستونِ nullable            → null
     *   - ستونِ NOT NULL با پیش‌فرض  → خودِ پیش‌فرض (مثلاً صفر)
     *   - ستونِ NOT NULL بی‌پیش‌فرض  → null تا قاعده‌ی `required` آن را گزارش کند
     * بدون این، `''` داخل ستون عددی می‌رود و MySQL در حالت strict خطای ۵۰۰ می‌دهد.
     */
    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        $schema = $this->getTableSchema();

        foreach ($this->getAttributes() as $name => $value) {
            $column = $value === '' ? $schema->getColumn($name) : null;

            if ($column) {
                $this->$name = $column->defaultValue ?? null;
            }
        }

        foreach ($this->textAttributes() as $name) {
            if (is_string($this->$name)) {
                $this->$name = trim($this->$name) ?: null;
            }
        }

        return true;
    }

    /** ستون‌های متنی که باید trim شوند — مدل فرزند فهرست‌شان می‌کند */
    protected function textAttributes(): array
    {
        return [];
    }

    /**
     * واحدِ کالا از خودِ محصول خوانده می‌شود (ایشوی ۹ و ۱۰).
     *
     * فرمِ «افزودن مصالح» و «تحویل کالا» دیگر سلکتِ واحد ندارند؛ کاربر فقط
     * محصول را انتخاب می‌کند و `product.unit_id` جای همان واحد می‌نشیند. پس
     * مقدارِ ارسالیِ کلاینت عمداً نادیده گرفته می‌شود و اگر محصولی واحد نداشت
     * `null` می‌ماند تا قاعده‌ی `exist` هم آن را خطا ندهد.
     */
    protected function inheritUnitFromProduct()
    {
        if (!$this->product_id) {
            return;
        }

        $unitId = Product::find()
            ->select(['unit_id'])
            ->where(['id' => $this->product_id])
            ->scalar();

        $this->unit_id = ($unitId === false || $unitId === null) ? null : (int) $unitId;
    }

    /**
     * ایشوی ۱۰ و ۴: مالکِ ردیف روشن است — فعالیت‌محورها گزارششان را از خودِ
     * فعالیت می‌گیرند، گزارش‌محورها همان گزارشی را می‌گیرند که مسیرِ افزودن
     * داده است (ورودیِ کاربر تعیین‌کننده نیست). شرکت همیشه از همان گزارش
     * می‌آید، درست مثل قراردادِ `company_id` در سربرگ.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->touchTimestamps($insert);

        if ($this->reportScoped()) {
            // ایشوی ۴: ردیفِ گزارش‌محور هرگز به فعالیتی وصل نمی‌شود
            $this->daily_report_activity_id = null;
        } elseif ($this->daily_report_activity_id) {
            $activity = DailyReportActivity::findOne($this->daily_report_activity_id);

            if ($activity) {
                $this->daily_report_id = $activity->daily_report_id;
            }
        }

        $report = $this->daily_report_id ? DailyReport::findOne($this->daily_report_id) : null;

        if ($report) {
            $this->company_id = $report->company_id;
        }

        return true;
    }

    public function getReport()
    {
        return $this->hasOne(DailyReport::class, ['id' => 'daily_report_id']);
    }
}
