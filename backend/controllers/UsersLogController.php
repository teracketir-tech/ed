<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\UsersLog;
use frontend\modules\hq\services\TimesheetService;
use yii\web\UnauthorizedHttpException;

/**
 * The shared attendance log behind the daily-report timesheet tab.
 *
 * It replaces the old `daily-report-timesheets` controller: the tab reads and
 * writes `users_logs` now, so the CRUD lives on the table the fingerprint
 * device owns. `by-date` is the one action the other line controllers do not
 * have — it feeds the multi-select picker.
 *
 * Routes (from `Route::crud` in config/routes.php):
 *   GET    hq/users-log                 rows of one report (the tab table)
 *   GET    hq/users-log/by-date         rows of one day, for the multi picker
 *   GET    hq/users-log/all             the global sidebar list (permission 709)
 *   POST   hq/users-log                 add with `daily_report_id`
 *   GET    hq/users-log/{id}            read one row
 *   PUT    hq/users-log/{id}            edit one row
 *   DELETE hq/users-log/{id}            detach / delete one row
 *
 * `by-date` and `all` are extraPatterns, so they win over `GET {id}`.
 */
class UsersLogController extends DailyReportLineController
{
    public $modelClass = UsersLog::class;

    /**
     * ⚠ Every action must be listed: JwtAuthBehavior only guards the listed
     * ones and the rest would answer without a token (issue 3). The base
     * list is repeated here on purpose because `by-date` and `all` are ours.
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth']['actions'][] = 'by-date';
        $behaviors['jwtAuth']['actions'][] = 'all';

        return $behaviors;
    }

    /** GET hq/users-log/by-date?date=YYYY-MM-DD — the multi-select source */
    public function actionByDate()
    {
        return TimesheetService::byDate();
    }

    /** GET hq/users-log/all — the sidebar's login/logout list (permission 709) */
    public function actionAll()
    {
        if (!User::checkAccess(709, false)) {
            throw new UnauthorizedHttpException('شما به این بخش دسترسی ندارید');
        }

        return UsersLog::all_logs();
    }
}
