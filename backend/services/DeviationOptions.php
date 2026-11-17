<?php

namespace frontend\modules\hq\services;

/**
 * سلکت‌های عددیِ «عوامل انحراف» — ایشوی ۱۶، بدون Yii تا قابل تست باشد.
 *
 * طبق قاعده‌ی کلیِ ایشوی ۱۶ هر سلکتِ فرم باید عدد ذخیره شود و لیست «عنوان و
 * مقدار» بدهد؛ این سه سلکت جدولِ جدا نمی‌خواهند (مثل لیست‌های `ReportRules`)
 * و اینجاست «منبع واحدِ حقیقت» برای هر دو مقصد:
 *
 *  - `formData()` همان چیزی است که پاسخِ فهرست در کلید `form_data` می‌فرستد
 *    (الگوی `Order::get_all` در ماژول seal)
 *  - `dictionaryOptions()` همان را به شکل `id/name` برای واژه‌نامه‌ی فرم
 *    (`GET daily-report/dictionaries`) می‌دهد، پس برچسب‌ها فقط یکجا نوشته می‌شوند
 *
 * گروه به جدول `group` وصل نیست (ایشوی ۱۵) و دو مقدارِ ثابت دارد؛ منشاء
 * انحراف بیست مقدارِ ستون C شیت «عوامل انحراف پروژه» است و ستون B می‌گوید
 * کدام به گروهِ داخلی (۱..۱۳) و کدام به خارجی (۱۴..۲۰) تعلق دارند — همین
 * `factor_group` است که سلکتِ منشاء را به سلکتِ گروه وصل می‌کند.
 */
class DeviationOptions
{
    /** گروهِ عامل — فقط دو گزینه (ستون B شیت) */
    public const FACTOR_GROUP = [
        ['label' => 'عوامل داخلی', 'value' => 1],
        ['label' => 'عوامل خارجی', 'value' => 2],
    ];

    /** منشاء انحراف (ستون C) به همراه گروهِ خودش */
    public const DEVIATION_SOURCE = [
        ['label' => 'کارفرما', 'value' => 1, 'factor_group' => 1],
        ['label' => 'مشاور / معمار / طراح', 'value' => 2, 'factor_group' => 1],
        ['label' => 'مجری', 'value' => 3, 'factor_group' => 1],
        ['label' => 'پیمانکار جزء', 'value' => 4, 'factor_group' => 1],
        ['label' => 'تأمین‌کننده / فروشنده', 'value' => 5, 'factor_group' => 1],
        ['label' => 'زنجیره تأمین / حمل‌ونقل', 'value' => 6, 'factor_group' => 1],
        ['label' => 'کنترل و نظارت', 'value' => 7, 'factor_group' => 1],
        ['label' => 'شرایط کارگاه', 'value' => 8, 'factor_group' => 1],
        ['label' => 'مالی / قراردادی', 'value' => 9, 'factor_group' => 1],
        ['label' => 'منابع انسانی', 'value' => 10, 'factor_group' => 1],
        ['label' => 'ایمنی و HSE', 'value' => 11, 'factor_group' => 1],
        ['label' => 'مستندسازی / کنترل پروژه', 'value' => 12, 'factor_group' => 1],
        ['label' => 'کنترل کیفیت', 'value' => 13, 'factor_group' => 1],
        ['label' => 'عوامل محیطی و طبیعی', 'value' => 14, 'factor_group' => 2],
        ['label' => 'عوامل سیاسی', 'value' => 15, 'factor_group' => 2],
        ['label' => 'عوامل اقتصادی', 'value' => 16, 'factor_group' => 2],
        ['label' => 'عوامل اجتماعی', 'value' => 17, 'factor_group' => 2],
        ['label' => 'عوامل قانونی و حقوقی', 'value' => 18, 'factor_group' => 2],
        ['label' => 'عوامل بین‌المللی و ژئوپلیتیک', 'value' => 19, 'factor_group' => 2],
        ['label' => 'تجهیزات و عوامل تکنولوژیک', 'value' => 20, 'factor_group' => 2],
    ];

    /** اثر غالب (ستون E) — سلکتِ مالتیِ سه‌گانه */
    public const EFFECT = [
        ['label' => 'زمان', 'value' => 1],
        ['label' => 'هزینه', 'value' => 2],
        ['label' => 'کیفیت', 'value' => 3],
    ];

    /**
     * «عنوان و مقدار»ی که پاسخِ فهرست می‌فرستد
     *
     * @return array<string, array<int, array<string, int|string>>>
     */
    public static function formData(): array
    {
        return [
            'factor_group' => self::FACTOR_GROUP,
            'deviation_source' => self::DEVIATION_SOURCE,
            'effect' => self::EFFECT,
        ];
    }

    /**
     * همان سه سلکت برای واژه‌نامه‌ی فرم — `id` / `name` / گروهِ منشاء
     *
     * @return array<string, array<int, array<string, int|string>>>
     */
    public static function dictionaryOptions(): array
    {
        return [
            'factor_groups' => self::toIdName(self::FACTOR_GROUP),
            'deviation_sources' => self::toIdName(self::DEVIATION_SOURCE),
            'effects' => self::toIdName(self::EFFECT),
        ];
    }

    /**
     * `[['label' => …, 'value' => …], …]` → `[['id' => …, 'name' => …], …]`
     *
     * @return array<int, array<string, int|string>>
     */
    private static function toIdName(array $options): array
    {
        $rows = [];

        foreach ($options as $option) {
            $row = ['id' => $option['value'], 'name' => $option['label']];

            if (isset($option['factor_group'])) {
                $row['factor_group'] = $option['factor_group'];
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
