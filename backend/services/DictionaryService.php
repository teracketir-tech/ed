<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReportDeviationReason;
use frontend\modules\hq\models\DailyReportEquipmentType;
use frontend\modules\hq\models\Group;
use frontend\modules\hq\models\Product;
use frontend\modules\hq\models\Unit;
use frontend\modules\hq\models\User;

/**
 * واژه‌نامه‌ی سلکت‌های فرم گزارش روزانه — ایشوی ۹، ۱۲، ۱۵ و ۱۶.
 *
 * «نوع ماشین‌آلات» و «واحد» جدول‌هایی هستند که endpoint جدا ندارند و «مصالح»
 * هم از `product` می‌آید (قرارداد ایشوی ۱)؛ «دلیل انحراف» (ایشوی ۱۲) هم به همین
 * درخواست اضافه شد تا تبِ «عوامل انحراف» درخواستِ دومی نزند و همه با یک
 * درخواست بیایند و تغییر API فقط در ماژول hq بماند.
 *
 * «گروه» (ایشوی ۱۵) هم به همین دلیل اینجا نشست: `GET group` پشتِ نقش 1202 است
 * و کاربرِ ماژول گزارش روزانه آن نقش را ندارد؛ فراخوانی‌اش از سمت فرم عوامل
 * انحراف با 401 کل نشست را به صفحه‌ی ورود می‌برد.
 *
 * سه سلکتِ عددیِ فرم عوامل انحراف (ایشوی ۱۶) جدولِ جدا ندارند و از ثابتِ
 * `DailyReportDeviationReason` ساخته می‌شوند؛ همان ثابتِی که `form_data`
 * پاسخِ فهرست را می‌سازد، پس برچسب‌ها فقط یک منبع دارند (DRY).
 *
 * نقش: VIEW — اینجا فقط خواندن است؛ افزودن/ویرایشِ ردیف نقش خودش را دارد
 * (ایشوی ۴ و ایشوی ۱۲) و در این سرویس تکرار نمی‌شود.
 */
class DictionaryService
{
    /** کلیدهای پاسخ — همان ترتیب تب‌های فرم */
    public const KEYS = ['units', 'equipment_types', 'products', 'deviation_reasons', 'groups', 'factor_groups', 'deviation_sources', 'effects'];

    /** کلید پاسخ => مدلی که `id` و `name` آن خوانده می‌شود */
    public const SOURCES = [
        'units' => Unit::class,
        'equipment_types' => DailyReportEquipmentType::class,
        'products' => Product::class,
        'deviation_reasons' => DailyReportDeviationReason::class,
        'groups' => Group::class,
    ];

    /** کلیدهایِ بدونِ جدول — از ثابتِ مدل ساخته می‌شوند (ایشوی ۱۶) */
    public const CONST_KEYS = ['factor_groups', 'deviation_sources', 'effects'];

    /**
     * ستون‌هایِ اضافه‌ای که بعضی واژه‌نامه‌ها باید بیاورند.
     *
     * `products.unit_id` برای فرمِ «افزودن مصالح» است که فیلدِ واحدِ جدا ندارد و
     * واحد را از خودِ محصول می‌خواند تا کنارِ «مقدار» بنویسد (ایشوی ۹).
     */
    public const EXTRA_COLUMNS = [
        'products' => ['unit_id'],
    ];

    /**
     * نامِ واحد روی خودِ ردیفِ محصول می‌نشیند (`unit_name`).
     *
     * فرمِ «افزودن مصالح» و «افزودن تحویل کالا» برچسبِ «مقدار (واحد)» را از
     * واحدِ محصول می‌سازند. اگر واحد را فقط از لیستِ `units` بخوانیم، آن لیست
     * به شرکتِ سربرگ فیلتر است (ایشوی ۱) و هر واحدِ متعلق به شرکتِ دیگر، یا هر
     * محصولِ بی‌واحد، برچسب را برای همیشه «مقدار (—)» می‌گذارد. پس نامِ واحد
     * همراهِ خودِ محصول می‌آید و برچسب به دومین جستجو وابسته نمی‌شود.
     */
    public const UNIT_NAME_COLUMN = 'unit_name';

    /**
     * همه‌ی لیست‌ها با یک درخواست — GET hq/daily-report/dictionaries
     *
     * `companyId` (ایشوی ۱): اگر داده شود فقط همان شرکت + ردیف‌هایِ بدونِ شرکت
     * برمی‌گردند؛ خالی بودن یعنی «شرکتی انتخاب نشده» و دیتای همه‌ی شرکت‌ها
     * باید دیده شود. ستونِ `company_id` را فقط جدول‌هایی دارند که شرکتی‌اند
     * (واحد/کالا/ماشین‌آلات/انحراف)؛ بقیه‌ی واژه‌نامه عمداً بی‌شرکت می‌مانند.
     *
     * @param int|string|null $companyId
     *
     * @return array{success: bool, units: array, equipment_types: array, products: array, deviation_reasons: array, groups: array, factor_groups: array, deviation_sources: array, effects: array}
     */
    public static function all($companyId = null): array
    {
        User::checkAccess(ReportRoles::VIEW);

        $out = ['success' => true];

        foreach (self::SOURCES as $key => $modelClass) {
            // ستونِ اضافه‌ی بعضی واژه‌نامه‌ها — مثلاً محصول باید `unit_id` بیاورد
            // تا فرمِ مصالح بتواند «مقدار (واحد)» را بسازد (ایشوی ۹)
            $columns = array_merge(['id', 'name'], self::EXTRA_COLUMNS[$key] ?? []);
            $query = $modelClass::find()->select($columns);

            if ($companyId && $modelClass::getTableSchema()->getColumn('company_id')) {
                $query->andWhere(['or', ['company_id' => $companyId], ['company_id' => null]]);
            }

            $out[$key] = $query->orderBy(['id' => SORT_ASC])->asArray()->all();
        }

        $out['products'] = self::withUnitNames($out['products']);

        return $out + DeviationOptions::dictionaryOptions();
    }

    /**
     * نامِ واحدِ هر محصول — یک کوئری برای همه‌ی واحد ها، نه یکی برای هر ردیف.
     *
     * `unit_id` محصولِ بی‌واحد یا واحدِ پاک‌شده بی‌صدا `null` می‌ماند؛ فرم همان
     * زمانی که نامی ندارد «مقدار (—)» را نشان می‌دهد.
     *
     * @param array<array-key, array<string, mixed>> $products
     *
     * @return array<array-key, array<string, mixed>>
     */
    private static function withUnitNames(array $products): array
    {
        $unitIds = array_values(array_unique(array_filter(array_column($products, 'unit_id'))));

        if ($unitIds === []) {
            return $products;
        }

        // واحدِ محصول ممکن است مالِ شرکتِ دیگری باشد؛ اینجا فقط برچسب می‌خواهیم،
        // پس برخلاف لیستِ `units` شرکت را فیلتر نمی‌کنیم. `select` تک‌ستونه است
        // تا `indexBy('id')` خودش شناسه را اضافه کند و `column()` نام را بدهد
        $names = Unit::find()
            ->select(['name'])
            ->where(['id' => $unitIds])
            ->indexBy('id')
            ->asArray()
            ->column();

        foreach ($products as $index => $product) {
            $unitId = $product['unit_id'] ?? null;

            $products[$index][self::UNIT_NAME_COLUMN] = $unitId === null ? null : ($names[$unitId] ?? null);
        }

        return $products;
    }
}
