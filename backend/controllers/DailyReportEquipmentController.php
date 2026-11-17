<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\DailyReportEquipment;

/**
 * ماشین‌آلات و تجهیزات گزارش روزانه.
 *
 * GET/POST `hq/daily-report-equipment` و GET/PUT/DELETE `hq/daily-report-equipment/{id}`؛
 * افزودن تو در تو با POST `hq/daily-report/{id}/equipment`.
 */
class DailyReportEquipmentController extends DailyReportLineController
{
    /** @var class-string */
    public $modelClass = DailyReportEquipment::class;
}
