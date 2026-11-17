<?php

namespace frontend\modules\hq\controllers;

/**
 * لیست «نوع ماشین‌آلات» — ایشوی ۱۲.
 *
 * مسیر: hq/daily-report-equipment-type — منطق در `ReferenceListService`
 * و کلید واژه‌نامه‌ی فرم `equipment_types`.
 */
class DailyReportEquipmentTypeController extends DailyReportReferenceController
{
    public $modelClass = 'frontend\modules\hq\models\DailyReportEquipmentType';
}
