<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\DailyReportMaterials;

/**
 * مواد و مصالح گزارش روزانه.
 *
 * GET/POST `hq/daily-report-materials` و GET/PUT/DELETE `hq/daily-report-materials/{id}`؛
 * افزودن تو در تو با POST `hq/daily-report/{id}/materials`.
 */
class DailyReportMaterialsController extends DailyReportLineController
{
    /** @var class-string */
    public $modelClass = DailyReportMaterials::class;
}
