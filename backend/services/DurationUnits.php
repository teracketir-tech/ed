<?php

namespace frontend\modules\hq\services;

/**
 * تبدیل واحدهای زمان (روز/ساعت) به واحد پایه‌ی محاسبه.
 *
 * اگر همه‌ی فعالیت‌ها و lagها ساعت باشند، واحد پایه «ساعت» است؛ در غیر این صورت «روز»
 * و فعالیت‌های ساعتی به روز تبدیل می‌شوند (HOURS_PER_DAY ساعت در هر روز).
 *
 * خالص است (Yii لازم ندارد) تا در نمایش تاریخ هم قابل استفاده و تست باشد.
 */
class DurationUnits
{
    /** ساعت در هر روزِ مبنای محاسبه */
    public const HOURS_PER_DAY = 8;

    /** کد واحد برای خروجی API (Task::UNIT_DAY / Task::UNIT_HOUR) */
    public const CODE_DAY = 1;
    public const CODE_HOUR = 2;

    /**
     * تشخیص واحد پایه: فقط وقتی همه‌ی واحدها یکسان و «ساعت» باشند، پایه ساعت است.
     *
     * @param array $taskRows    ردیف‌هایی با کلید duration_unit
     * @param array $relationRows ردیف‌هایی با کلید lag_unit
     * @return string 'day' یا 'hour'
     */
    public static function detectBase(array $taskRows, array $relationRows): string
    {
        $units = [];

        foreach ($taskRows as $row) {
            $units[(int) $row['duration_unit']] = true;
        }
        foreach ($relationRows as $row) {
            $units[(int) $row['lag_unit']] = true;
        }

        return (count($units) === 1 && isset($units[self::CODE_HOUR])) ? 'hour' : 'day';
    }

    /** نام واحد پایه → کد واحد */
    public static function code($baseUnit): int
    {
        return $baseUnit === 'hour' ? self::CODE_HOUR : self::CODE_DAY;
    }

    /** تبدیل مقدار به واحد پایه ('day' یا 'hour') */
    public static function toBase($value, $unit, $baseUnit): float
    {
        $expected = $baseUnit === 'hour' ? self::CODE_HOUR : self::CODE_DAY;

        if ((int) $unit === $expected) {
            return (float) $value;
        }

        // واحدِ غیر از پایه است: ساعت ← روز تقسیم، روز ← ساعت ضرب
        return (int) $unit === self::CODE_HOUR
            ? (float) $value / self::HOURS_PER_DAY
            : (float) $value * self::HOURS_PER_DAY;
    }
}
