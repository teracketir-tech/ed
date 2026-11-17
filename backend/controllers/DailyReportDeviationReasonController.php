<?php

namespace frontend\modules\hq\controllers;

/**
 * لیست «دلیل انحراف» — ایشوی ۱۲.
 *
 * مسیر: hq/daily-report-deviation-reason — منطق در `ReferenceListService`
 * و همین فهرست، تبِ «عوامل انحراف» دیالوگِ فعالیت را پر می‌کند.
 */
class DailyReportDeviationReasonController extends DailyReportReferenceController
{
    public $modelClass = 'frontend\modules\hq\models\DailyReportDeviationReason';
}
