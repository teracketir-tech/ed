<?php

namespace frontend\modules\hq\services;

/**
 * قواعد و لیست‌های چندمقداری «گزارش روزانه» — بدون Yii تا قابل تست باشد.
 *
 * طبق سند فاز ۲ این لیست‌ها جدول جدا نمی‌خواهند و enum در کد تعریف می‌شوند
 * (برعکس «نوع ماشین‌آلات» و «دلیل انحراف» که جدول شدند).
 * مدل ActiveRecord فقط کلیدهای همین لیست‌ها را به عنوان range می‌پذیرد،
 * پس اینجا «منبع واحد حقیقت» است و تست مستقیم همان را می‌سنجد.
 *
 * برچسب‌های همین لیست‌ها بعدها مبنای نمایش سلکت‌های فرم React قرار می‌گیرند.
 */
class ReportRules
{
    /** وضعیت ثبت گزارش — ستون `status` */
    public const STATUS_DRAFT = 0;
    public const STATUS_SUBMITTED = 1;

    /** وضعیت کارگاه — ستون `site_status` */
    public const SITE_ACTIVE = 1;
    public const SITE_INACTIVE = 2;

    /** وضعیت آب و هوا — ترتیب عین شیت Data اکسل */
    public const WEATHER = [
        1 => 'آفتابی',
        2 => 'ابری',
        3 => 'بارانی',
        4 => 'برفی',
        5 => 'باد شدید',
    ];

    /** وضعیت کارگاه */
    public const SITE = [
        self::SITE_ACTIVE => 'فعال',
        self::SITE_INACTIVE => 'غیرفعال',
    ];

    /** وضعیت ثبت گزارش */
    public const STATUS = [
        self::STATUS_DRAFT => 'پیش‌نویس',
        self::STATUS_SUBMITTED => 'ثبت نهایی',
    ];

    /** حداکثر مجاز برای درصد — سند فاز ۲ بند ۱۷: «بین 0 تا 100» */
    public const PROGRESS_MAX = 100;

    /** وضعیت فعلی فعالیت — ترتیب عین شیت Data اکسل (ستون E) */
    public const ACTIVITY_STATUS = [
        1 => 'شروع',
        2 => 'در حال اجرا',
        3 => 'اتمام',
        4 => 'شروع و اتمام',
        5 => 'متوقف',
        6 => 'شروع نشده',
    ];

    /** نوع اقدام در «تحویل کالا» — ستون I شیت Data */
    public const ACTION_IN = 1;
    public const ACTION_OUT = 2;

    public const ACTION_TYPE = [
        self::ACTION_IN => 'ورود کالا',
        self::ACTION_OUT => 'خروج کالا',
    ];

    /** گروه کالا — ستون K شیت Data */
    public const ITEM_GROUP = [
        1 => 'کالا',
        2 => 'تجهیز',
    ];

    /** نوع تحویل — ستون M شیت Data */
    public const DELIVERY_TYPE = [
        1 => 'قطعی',
        2 => 'امانی',
    ];

    /** نوع همکاری (تایم‌شیت) — ستون AB شیت Data */
    public const EMPLOYMENT_TYPE = [
        1 => 'تمام وقت',
        2 => 'روز مزد',
    ];

    /**
     * سمت — ستون O شیت Data (۲۱ مقدار) به‌علاوه‌ی دو سمتی که فقط در فرمِ
     * گزارش به‌کار می‌روند ولی در اکسل نیستند (ایشوی ۲.۲).
     *
     * همین لیست «نوع» (type) فرمِ اشخاص را برای شرکت‌های غیرِ «مسکن» می‌سازد؛
     * برای مسکن (company_id = 1) همان حقیقی/حقوقیِ قبلی می‌ماند. عددِ سمت
     * داخلِ ستونِ `contact.type` می‌نشیند پس باید از ۱ پیوسته و بدون حذفِ
     * میان‌ ردیف‌ها تغییر کند وگرنه داده‌ی ذخیره‌شده جابه‌جا می‌شود.
     */
    public const POSITION = [
        1 => 'مهندس',
        2 => 'کارگر',
        3 => 'گچکار',
        4 => 'آهنگر',
        5 => 'تاسیسات کار',
        6 => 'سرپرست کارگاه',
        7 => 'سیمانکار',
        8 => 'برقکار',
        9 => 'کانال ساز',
        10 => 'بنا',
        11 => 'سرامیک کار',
        12 => 'کناف کار',
        13 => 'میکروسمنت کار',
        14 => 'نقاش',
        15 => 'ام دی اف کار',
        16 => 'دکورساز',
        17 => 'کابینت کار',
        18 => 'پیمانکار پنجره',
        19 => 'پیمانکار درب',
        20 => 'نصاب پارکت',
        21 => 'نصاب آیینه',
        22 => 'مسئول اجرای فعالیت',
        23 => 'مسئول نظارت فعالیت',
    ];

    /** سمتِ «مدیر پروژه» و «تنظیم‌کننده فرم» در سربرگ — مهندس */
    public const POSITION_MANAGER = 1;

    /** سمتِ «سرپرست کارگاه» در سربرگ */
    public const POSITION_SUPERVISOR = 6;

    /** سمتِ «مسئول اجرای فعالیت» در تبِ مشخصات فعالیت */
    public const POSITION_EXECUTOR = 22;

    /** سمتِ «مسئول نظارت فعالیت» در تبِ مشخصات فعالیت */
    public const POSITION_SUPERVISOR_ACTIVITY = 23;

    /**
     * کلیدهای مجاز یک لیست — همان range که قاعده‌ی `in` در مدل می‌گیرد.
     *
     * @return int[]
     */
    public static function values(array $list): array
    {
        return array_keys($list);
    }

    /** آیا مقدار یکی از کلیدهای لیست است؟ (برای رشته‌های عددی هم درست کار می‌کند) */
    public static function isValidValue(array $list, $value): bool
    {
        return in_array((int) $value, array_keys($list), true);
    }

    /** تاریخ باید ISO باشد: `Y-m-d` و از نظر تقویمی معتبر (نه 2026-02-30) */
    public static function isIsoDate($value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        list($y, $m, $d) = explode('-', $value);

        return checkdate((int) $m, (int) $d, (int) $y);
    }

    /**
     * سند فاز ۲، بند ۱۷: برای «کارگاه غیرفعال» دلیل غیرفعالی اجباری است.
     * در حالت فعال/ثبت‌نشده دلیل بی‌معناست و خطایی هم نمی‌گیرد.
     *
     * @return string|null پیام خطا یا null اگر معتبر باشد
     */
    public static function siteReasonError($siteStatus, $reason): ?string
    {
        if (!self::isValidValue(self::SITE, $siteStatus)) {
            return null;
        }

        if ((int) $siteStatus !== self::SITE_INACTIVE) {
            return null;
        }

        return trim((string) $reason) === ''
            ? 'برای کارگاه غیرفعال، دلیل غیرفعالی الزامی است'
            : null;
    }

    /**
     * سند فاز ۲ بند ۱۷: «مقادیر quantity منفی نباشند مگر اینکه نوع اقدام
     * مشخصاً خروج/برگشت را اجازه دهد.» همان CHECK در SQL:
     * `quantity >= 0 OR action_type = 2`.
     *
     * @return string|null پیام خطا یا null اگر مقدار مجاز باشد
     */
    public static function quantityError($value, $actionType): ?string
    {
        if ($value === null || $value === '' || (float) $value >= 0) {
            return null;
        }

        if (self::isValidValue(self::ACTION_TYPE, $actionType) && (int) $actionType === self::ACTION_OUT) {
            return null;
        }

        return 'مقدار نمی‌تواند منفی باشد مگر نوع اقدام «خروج کالا» انتخاب شده باشد';
    }

    /**
     * ستون‌های `time` تایم‌شیت باید `HH:MM` یا `HH:MM:SS` باشند.
     * `DateValidator` این دو را با هم نمی‌پذیرد، پس همین‌جا یکجا بررسی می‌شود.
     */
    public static function isTime($value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{2}):(\d{2})(:\d{2})?$/', $value, $m) !== 1) {
            return false;
        }

        return (int) $m[1] < 24 && (int) $m[2] < 60 && (!isset($m[3]) || (int) substr($m[3], 1) < 60);
    }
}
