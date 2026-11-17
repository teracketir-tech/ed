<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReport;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\UsersLog;
use Yii;

/**
 * The two halves of the timesheet tab that a plain CRUD cannot express.
 *
 *  - `byDate()`  — GET  hq/users-log/by-date?date=YYYY-MM-DD
 *    the fingerprint rows of one day, so the supervisor can tick several of
 *    them at once. Rows already stamped by another report are hidden; rows
 *    stamped by *this* report come back so the picker can mark them.
 *
 *  - `attach()`  — POST hq/daily-report/<reportId>/timesheets
 *    with `log_ids`. Stamping is the whole point of the tab: this is where
 *    `daily_report_id` / `company_id` land on the log rows (issue 4 — the
 *    timesheet belongs to the report, not to an activity, so
 *    `daily_report_activity_id` stays NULL). A row can move between reports
 *    only when nobody else owns it — it is never stolen.
 */
class TimesheetService
{
    /** One picker page — a day never holds more people than this */
    public const MAX_ROWS = 500;

    /** GET hq/users-log/by-date?date=YYYY-MM-DD[&daily_report_id=] */
    public static function byDate(): array
    {
        User::checkAccess(ReportRoles::VIEW);

        $date = (string) Yii::$app->request->get('date');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'message' => 'تاریخ باید به صورت YYYY-MM-DD باشد'];
        }

        $reportId = (int) Yii::$app->request->get('daily_report_id');

        // Issue 5: a log row belongs to a system user — the picker shows that
        // user's name and position (same contract as the attendance list).
        // The select is an ARRAY on purpose: a single string gets comma-split
        // by Yii, which breaks CONCAT(...) into invalid SQL.
        $query = UsersLog::find()
            ->alias('l')
            ->leftJoin(User::tableName() . ' u', 'u.id = l.user_id')
            ->select([
                'l.*',
                'CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, "")) AS person_name',
                'u.position_id AS person_position',
            ])
            // A night shift can be written under the following day, so the row
            // belongs to a date when either of its stamps does.
            ->where('DATE(l.checkindate) = :date OR DATE(l.checkout_date) = :date', [':date' => $date])
            ->limit(self::MAX_ROWS)
            ->orderBy(['l.id' => SORT_ASC]);

        if ($reportId) {
            // Free rows plus the ones this report already owns (re-opening the
            // picker must show what was taken, not silently offer it twice)
            $query->andWhere(['or', ['l.daily_report_id' => null], ['l.daily_report_id' => $reportId]]);
        } else {
            $query->andWhere(['l.daily_report_id' => null]);
        }

        $company = Yii::$app->request->get('company_id');

        // An unassigned row has no company yet, so an empty company_id (the HQ
        // panel) must not filter anything out and a company must still see the
        // rows nobody has claimed.
        if ($company !== null && $company !== '') {
            $query->andWhere(['or', ['l.company_id' => null], ['l.company_id' => (int) $company]]);
        }

        return ['success' => true, 'data' => $query->asArray()->all()];
    }

    /**
     * Stamp several log rows with the report that asked for them (issue 4).
     *
     * @param int         $reportId
     * @param array|mixed $logIds     ids as an array or a comma separated string
     */
    public static function attach($reportId, $logIds): array
    {
        User::checkAccess(ReportRoles::CREATE);

        $report = DailyReport::findOne($reportId);

        if (!$report) {
            return ['success' => false, 'message' => 'گزارش روزانه‌ی انتخاب‌شده یافت نشد'];
        }

        $ids = self::normalizeIds($logIds);

        if (!$ids) {
            return ['success' => false, 'message' => 'دست‌کم یک ردیف لاگ انتخاب کنید'];
        }

        $rows = [];
        $errors = [];
        $transaction = Yii::$app->db->beginTransaction();

        try {
            foreach ($ids as $id) {
                $row = UsersLog::findOne($id);

                if (!$row) {
                    $errors[$id] = 'رکورد لاگ با این شناسه یافت نشد';
                    continue;
                }

                // A row claimed by another report must never be stolen: it is
                // somebody else's attendance for another day.
                if ($row->daily_report_id && (int) $row->daily_report_id !== (int) $report->id) {
                    $errors[$id] = 'این ردیف به گزارش روزانه‌ی دیگری تعلق دارد';
                    continue;
                }

                $row->daily_report_id = $report->id;
                $row->daily_report_activity_id = null;
                $row->company_id = $report->company_id;

                if (!$row->save()) {
                    $errors[$id] = implode('؛ ', $row->getFirstErrors());
                    continue;
                }

                $rows[] = $row;
            }

            if ($errors) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => $errors];
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['success' => true, 'data' => $rows, 'attached' => count($rows)];
    }

    /** Accepts an array, a JSON-ish payload or "1,2,3" — all three arrive in practice */
    private static function normalizeIds($logIds): array
    {
        if (!is_array($logIds)) {
            $logIds = explode(',', (string) $logIds);
        }

        $ids = [];

        foreach ($logIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
