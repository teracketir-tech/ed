<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\DailyReportDeliveries;

/**
 * تحویل کالای گزارش روزانه.
 *
 * GET/POST `hq/daily-report-deliveries` و GET/PUT/DELETE `hq/daily-report-deliveries/{id}`؛
 * افزودن تو در تو با POST `hq/daily-report/{id}/deliveries`.
 */
class DailyReportDeliveriesController extends DailyReportLineController
{
    /** @var class-string */
    public $modelClass = DailyReportDeliveries::class;
}
