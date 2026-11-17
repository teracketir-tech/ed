<?php

namespace frontend\modules\hq\models;

/**
 * پر کردن `created_at` / `updated_at` برای جدول‌های فاز ۲.
 *
 * این ستون‌ها در دیتابیس `timestamp NULL DEFAULT NULL` هستند و MySQL خودش
 * چیزی نمی‌گذارد؛ بدون این، همه‌ی ردیف‌های گزارش روزانه تاریخ ثبتِ NULL
 * می‌گیرند. عمداً در `rules` نیستند تا mass-assign نشوند (همان قراردادِ
 * `company_id` که آن هم از داخل کد پر می‌شود نه از ورودی کاربر).
 *
 * چهار مدل از آن استفاده می‌کنند: DailyReport، DailyReportActivity،
 * DailyReportDeviationReason و DailyReportLine (پایه‌ی پنج زیرمجموعه).
 */
trait HasTimestamps
{
    /**
     * Only called from inside `beforeSave`.
     *
     * Columns that the table does not own are skipped: `users_logs` is a
     * fingerprint-device table and carries no timestamp columns at all, so
     * writing them would raise an UnknownPropertyException on every save.
     *
     * @param bool $insert Is the record being inserted?
     */
    protected function touchTimestamps($insert)
    {
        $schema = $this->getTableSchema();
        $now = date('Y-m-d H:i:s');

        if ($insert && $schema->getColumn('created_at') && $this->created_at === null) {
            $this->created_at = $now;
        }

        if ($schema->getColumn('updated_at')) {
            $this->updated_at = $now;
        }
    }
}
