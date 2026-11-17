<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\DailyReportManpower;

/**
 * نیروی انسانی گزارش روزانه.
 *
 * GET/POST `hq/daily-report-manpower` و GET/PUT/DELETE `hq/daily-report-manpower/{id}`؛
 * افزودن تو در تو با POST `hq/daily-report/{id}/manpower`.
 */
class DailyReportManpowerController extends DailyReportLineController
{
    /** @var class-string */
    public $modelClass = DailyReportManpower::class;
}
