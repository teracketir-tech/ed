<?php

namespace frontend\modules\hq\models;

/**
 * HrmSalaryCalculator
 *
 * محاسبه‌گر خالص حقوق و دستمزد پرسنل.
 * این کلاس هیچ کوئری‌ای به دیتابیس نمی‌زند و فقط بر اساس
 * پارامترهای ورودی محاسبه انجام می‌دهد.
 *
 * ورودی: آرایه‌ای تخت از پارامترها (کلیدهای نیامده = صفر)
 * خروجی: آرایه‌ای با تفکیک کامل مزایا، کسورات، ناخالص و خالص
 */
class HrmSalaryCalculator
{
    /** ضریب پیش‌فرض اضافه‌کاری */
    const DEFAULT_OVERTIME_RATE = 1.4;

    /** ضریب پیش‌فرض جمعه‌کاری */
    const DEFAULT_FRIDAY_RATE = 1.8;

    /** ضریب پیش‌فرض تأخیر/تعجیل */
    const DEFAULT_DELAY_RATE = 1.4;

    /** نرخ بیمه سهم کارمند */
    const DEFAULT_INSURANCE_RATE = 0.07;

    /** ضریب بیمه (۷٪ × ۱.۱ = ۷.۷٪ مؤثر) */
    const DEFAULT_INSURANCE_MULTIPLIER = 1.1;

    /** ساعات استاندارد یک روز کاری برای تبدیل ساعت به روز */
    const HOURS_PER_DAY = 8;

    /** ساعات مبنای حقوق ساعتی (۷.۳۳) */
    const SALARY_HOURS_PER_DAY = 7.33;

    /** روزهای مبنای ماه حقوقی */
    const SALARY_DAYS_PER_MONTH = 30;

    /** ضریب جریمه غیبت (۲ روز حقوق به ازای هر روز غیبت) */
    const ABSENT_PENALTY_FACTOR = 2;

    /** جریمه هر ۸ ساعت تأخیر (۲ روز حقوق) */
    const DELAY_PENALTY_DAYS_PER_8H = 2;

    /**
     * محاسبه حقوق و دستمزد
     *
     * @param array $params پارامترهای ورودی
     * @return array
     */
    public static function calculate(array $params = [])
    {
        // ========== 1. خواندن پارامترها با پیش‌فرض صفر ==========
        $p = self::normalizeParams($params);

        // ========== 2. محاسبه مبناها ==========
        $baseSalary = (float) $p['base_salary'];
        $monthDays  = (int) $p['month_days'] > 0 ? (int) $p['month_days'] : self::SALARY_DAYS_PER_MONTH;

        $dailySalary  = $baseSalary / self::SALARY_DAYS_PER_MONTH;
        $hourlySalary = $baseSalary / self::SALARY_DAYS_PER_MONTH / self::SALARY_HOURS_PER_DAY;

        // ========== 3. محاسبه مزایا ==========

        // --- حقوق پایه / کارکرد ---
        if ($p['contract_type'] === 'hourly') {
            // قرارداد ساعتی: حقوق کارکرد = ساعت × نرخ ساعتی قرارداد
            $basePay = (float) $p['work_hours'] * (float) $p['hourly_rate'];
        } else {
            // قرارداد عادی و پروژه‌ای: حقوق پایه ثابت
            $basePay = $baseSalary;
        }

        // --- مزایای ثابت قرارداد ---
        $housing    = (float) $p['housing_allowance'];
        $grocery    = (float) $p['grocery_allowance'];
        $child      = (float) $p['child_allowance'];
        $seniority  = (float) $p['monthly_seniority'];
        $management = (float) $p['management_allowance'];
        $transport  = (float) $p['transport_allowance'];
        $performance = (float) $p['performance_bonus'];

        // --- اضافه‌کاری‌ها ---
        $overtimeRate        = $p['overtime_rate'] ?: self::DEFAULT_OVERTIME_RATE;
        $fridayRate          = $p['friday_rate'] ?: self::DEFAULT_FRIDAY_RATE;
        $delayRate           = $p['delay_rate'] ?: self::DEFAULT_DELAY_RATE;

        $overtimePay        = (float) $p['overtime_hours'] * $hourlySalary * $overtimeRate;
        $specialOvertimePay = (float) $p['special_overtime_hours'] * $hourlySalary * $overtimeRate;
        $missionOvertimePay = (float) $p['mission_overtime_hours'] * $hourlySalary * $overtimeRate;

        // --- جمعه‌کاری و تعطیل‌کاری ---
        $fridayPay  = (float) $p['friday_hours'] * $hourlySalary * $fridayRate;
        $holidayPay = (float) $p['holiday_hours'] * $hourlySalary * $overtimeRate;

        // --- مأموریت (تبدیل ساعت به روز) ---
        $missionDays = (float) $p['mission_hours'] / self::HOURS_PER_DAY;
        $missionPay  = $missionDays * $dailySalary;

        // --- آیتم‌های مستقیم ---
        $bonus             = (float) $p['bonus'];
        $commission        = (float) $p['commission'];
        $receivableCosts   = (float) $p['receivable_costs'];
        $arrears           = (float) $p['arrears'];
        $previousBalance   = (float) $p['previous_month_balance'];

        // --- بازخرید مرخصی ---
        $vacationBuybackDays = (float) $p['vacation_buyback_days'];
        $vacationBuyback     = $vacationBuybackDays * $dailySalary;

        // --- تعدیل ماه ۳۱ روزه ---
        $month31Adjust = ($monthDays === 31) ? $dailySalary : 0;

        // --- جمع مزایا ---
        $totalEarnings =
            $basePay +
            $housing +
            $grocery +
            $child +
            $seniority +
            $management +
            $transport +
            $performance +
            $overtimePay +
            $specialOvertimePay +
            $missionOvertimePay +
            $fridayPay +
            $holidayPay +
            $missionPay +
            $bonus +
            $commission +
            $receivableCosts +
            $arrears +
            $vacationBuyback +
            $previousBalance +
            $month31Adjust;

        // ========== 4. محاسبه کسورات ==========

        // --- بیمه ---
        // مبنای بیمه: پایه + مسکن + بن (برای قرارداد عادی)
        // برای ساعتی و پروژه‌ای: از base_salary (مبلغ تنظیمات) استفاده می‌شود
        if ($p['contract_type'] === 'normal') {
            $insuranceBase = $baseSalary + $housing + $grocery;
        } else {
            $insuranceBase = $baseSalary;
        }

        $insuranceRate       = $p['insurance_rate'] ?: self::DEFAULT_INSURANCE_RATE;
        $insuranceMultiplier = $p['insurance_multiplier'] ?: self::DEFAULT_INSURANCE_MULTIPLIER;
        $insurance = $insuranceBase * $insuranceRate * $insuranceMultiplier;

        // --- تأخیر (ساعتی) ---
        $delayHours = (float) $p['delay_hours'];
        $delayDeduction = $delayHours * $hourlySalary * $delayRate;

        // --- جریمه تأخیر: هر ۸ ساعت کامل تأخیر = ۲ روز حقوق ---
        $delayPenalty = floor($delayHours / self::HOURS_PER_DAY) * self::DELAY_PENALTY_DAYS_PER_8H * $dailySalary;

        // --- تعجیل / خروج زودهنگام ---
        $earlyLeaveDeduction = (float) $p['early_leave_hours'] * $hourlySalary * $delayRate;

        // --- خروج غیرمجاز (همان ضریب تعجیل) ---
        $unauthorizedLeaveDeduction = (float) $p['unauthorized_leave_hours'] * $hourlySalary * $delayRate;

        // --- غیبت: هر روز = ۲ روز حقوق ---
        $absentDays = (float) $p['absent_hours'] / self::HOURS_PER_DAY;
        $absentDeduction = $absentDays * $dailySalary * self::ABSENT_PENALTY_FACTOR;

        // --- مرخصی بدون حقوق ---
        // مبنا: پایه + مسکن + بن + پاداش عملکرد + اولاد
        $unpaidLeaveBase = $baseSalary + $housing + $grocery + $performance + $child;
        $unpaidLeaveDays = (float) $p['unpaid_leave_days'];
        $unpaidLeaveDeduction = ($unpaidLeaveBase / $monthDays) * $unpaidLeaveDays;

        // --- مرخصی دانشجویی (مثل بدون حقوق) ---
        $studentLeaveDays = (float) $p['student_leave_days'];
        $studentLeaveDeduction = ($unpaidLeaveBase / $monthDays) * $studentLeaveDays;

        // --- کسورات مستقیم ---
        $loanInstallment  = (float) $p['loan_installment'];
        $pettyCash        = (float) $p['petty_cash'];
        $companyPurchase  = (float) $p['company_purchase'];
        $miscDeductions   = (float) $p['misc_deductions'];

        // --- تعدیل ماه ۲۹ روزه ---
        $month29Adjust = ($monthDays === 29) ? $dailySalary : 0;

        // --- مالیات (فعلاً صفر) ---
        $tax = 0;

        // --- جمع کسورات ---
        $totalDeductions =
            $insurance +
            $loanInstallment +
            $pettyCash +
            $delayDeduction +
            $delayPenalty +
            $earlyLeaveDeduction +
            $unauthorizedLeaveDeduction +
            $absentDeduction +
            $unpaidLeaveDeduction +
            $studentLeaveDeduction +
            $companyPurchase +
            $miscDeductions +
            $month29Adjust +
            $tax;

        // ========== 5. خروجی ==========
        $grossPay = $totalEarnings;
        $netPay   = $grossPay - $totalDeductions;

        return [
            'success' => true,
            'data'    => [
                'contract_type' => $p['contract_type'],
                'month_days'    => $monthDays,
                'daily_salary'  => round($dailySalary, 2),
                'hourly_salary' => round($hourlySalary, 2),

                // مزایا
                'earnings' => [
                    'base_pay'              => round($basePay, 2),
                    'housing'               => round($housing, 2),
                    'grocery'               => round($grocery, 2),
                    'child'                 => round($child, 2),
                    'seniority'             => round($seniority, 2),
                    'management'            => round($management, 2),
                    'transport'             => round($transport, 2),
                    'performance'           => round($performance, 2),
                    'overtime_pay'          => round($overtimePay, 2),
                    'special_overtime_pay'  => round($specialOvertimePay, 2),
                    'mission_overtime_pay'  => round($missionOvertimePay, 2),
                    'friday_pay'            => round($fridayPay, 2),
                    'holiday_pay'           => round($holidayPay, 2),
                    'mission_pay'           => round($missionPay, 2),
                    'mission_days'          => round($missionDays, 2),
                    'bonus'                 => round($bonus, 2),
                    'commission'            => round($commission, 2),
                    'receivable_costs'      => round($receivableCosts, 2),
                    'arrears'               => round($arrears, 2),
                    'vacation_buyback'      => round($vacationBuyback, 2),
                    'previous_balance'      => round($previousBalance, 2),
                    'month_31_adjust'       => round($month31Adjust, 2),
                    'total_earnings'        => round($totalEarnings, 2),
                ],

                // کسورات
                'deductions' => [
                    'insurance'                  => round($insurance, 2),
                    'loan'                       => round($loanInstallment, 2),
                    'petty_cash'                 => round($pettyCash, 2),
                    'delay'                      => round($delayDeduction, 2),
                    'delay_penalty'              => round($delayPenalty, 2),
                    'early_leave'                => round($earlyLeaveDeduction, 2),
                    'unauthorized_leave'         => round($unauthorizedLeaveDeduction, 2),
                    'absent'                     => round($absentDeduction, 2),
                    'unpaid_leave'               => round($unpaidLeaveDeduction, 2),
                    'student_leave'              => round($studentLeaveDeduction, 2),
                    'company_purchase'           => round($companyPurchase, 2),
                    'misc'                       => round($miscDeductions, 2),
                    'month_29_adjust'            => round($month29Adjust, 2),
                    'tax'                        => round($tax, 2),
                    'total_deductions'           => round($totalDeductions, 2),
                ],

                'gross_pay' => round($grossPay, 2),
                'net_pay'   => round($netPay, 2),
            ],
        ];
    }

    /**
     * نرمال‌سازی پارامترها؛ هر کلید نیامده = صفر
     *
     * @param array $params
     * @return array
     */
    private static function normalizeParams(array $params)
    {
        $defaults = [
            // نوع قرارداد و مبنا
            'contract_type'             => 'normal',
            'month_days'                => self::SALARY_DAYS_PER_MONTH,

            // مبالغ ثابت قرارداد
            'base_salary'               => 0,
            'housing_allowance'         => 0,
            'grocery_allowance'         => 0,
            'child_allowance'           => 0,
            'monthly_seniority'         => 0,
            'management_allowance'      => 0,
            'transport_allowance'       => 0,
            'performance_bonus'         => 0,

            // نرخ ساعتی
            'hourly_rate'               => 0,

            // کارکرد و ساعات
            'work_hours'                => 0,
            'overtime_hours'            => 0,
            'special_overtime_hours'    => 0,
            'mission_overtime_hours'    => 0,
            'friday_hours'              => 0,
            'holiday_hours'             => 0,
            'mission_hours'             => 0,
            'delay_hours'               => 0,
            'early_leave_hours'         => 0,
            'unauthorized_leave_hours'  => 0,
            'absent_hours'              => 0,
            'unpaid_leave_days'         => 0,
            'student_leave_days'        => 0,

            // آیتم‌های مستقیم
            'bonus'                     => 0,
            'commission'                => 0,
            'receivable_costs'          => 0,
            'arrears'                   => 0,
            'vacation_buyback_days'     => 0,
            'previous_month_balance'    => 0,

            // کسورات مستقیم
            'loan_installment'          => 0,
            'petty_cash'                => 0,
            'company_purchase'          => 0,
            'misc_deductions'           => 0,

            // تنظیمات (اختیاری)
            'overtime_rate'             => self::DEFAULT_OVERTIME_RATE,
            'friday_rate'               => self::DEFAULT_FRIDAY_RATE,
            'delay_rate'                => self::DEFAULT_DELAY_RATE,
            'insurance_rate'            => self::DEFAULT_INSURANCE_RATE,
            'insurance_multiplier'      => self::DEFAULT_INSURANCE_MULTIPLIER,
        ];

        return array_merge($defaults, $params);
    }
}