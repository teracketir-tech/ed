<?php

namespace frontend\modules\hq\controllers;

use Yii;
use yii\web\Controller;
use frontend\modules\hq\models\HrmDailyAttendance;
use frontend\modules\hq\models\UsersLog;
use frontend\modules\hq\models\User;
use frontend\modules\hq\models\HrmContract;
use frontend\modules\hq\models\Persian;

class DelogController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * GET /hq/delog?user_id=5&year=1405&month=8
     */
    public function actionIndex()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Yii::$app->request;
        $fingerprintId = $request->get('user_id');
        $year  = $request->get('year');
        $month = $request->get('month');

        if (empty($fingerprintId)) {
            return [
                'success' => false,
                'message' => 'user_id الزامی است (fingerprint_id)',
            ];
        }

        try {
            $summary = HrmDailyAttendance::getMonthlySummary($fingerprintId, $year, $month);
            return ['success' => true, 'data' => $summary];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * GET /hq/delog/contract?user_id=5
     * ⭐ نمایش قرارداد فعال کاربر
     */
    public function actionContract()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $fingerprintId = Yii::$app->request->get('user_id');

        if (empty($fingerprintId)) {
            return ['success' => false, 'message' => 'user_id الزامی است'];
        }

        try {
            // پیدا کردن کاربر
            $user = User::findOne(['fingerprint_id' => $fingerprintId]);
            if (!$user) {
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            // قرارداد فعال
            $contract = HrmContract::find()
                ->where(['user_id' => $user->id, 'contract_status' => HrmContract::STATUS_ACTIVE])
                ->orderBy(['id' => SORT_DESC])
                ->one();

            if (!$contract) {
                return [
                    'success' => false,
                    'message' => 'قرارداد فعالی یافت نشد',
                    'user' => [
                        'id' => $user->id,
                        'fingerprint_id' => $user->fingerprint_id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                    ],
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'fingerprint_id' => $user->fingerprint_id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                    ],
                    'contract' => [
                        'id' => $contract->id,
                        'contract_type' => $contract->contract_type,
                        'contract_type_label' => $contract->getContractTypeLabel(),
                        'contract_status' => $contract->contract_status,
                        'work_start_time' => $contract->work_start_time,
                        'work_end_time' => $contract->work_end_time,
                        'base_salary' => $contract->base_salary,
                        'housing_allowance' => $contract->housing_allowance,
                        'child_allowance' => $contract->child_allowance,
                        'welfare_allowance' => $contract->welfare_allowance,
                        'seniority_allowance' => $contract->seniority_allowance,
                        'performance_bonus' => $contract->performance_bonus,
                        'responsibility_allowance' => $contract->responsibility_allowance,
                        'transportation_allowance' => $contract->transportation_allowance,
                        'other_allowance' => $contract->other_allowance,
                        'total_salary' => $contract->total_salary,
                        'contract_from_date' => $contract->contract_from_date,
                        'contract_to_date' => $contract->contract_to_date,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }

    /**
     * GET /hq/delog/logs?user_id=5&year=1405&month=8
     * ⭐ نمایش رکوردهای خام users_logs
     */
    public function actionLogs()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Yii::$app->request;
        $fingerprintId = $request->get('user_id');
        $year  = $request->get('year');
        $month = $request->get('month');

        if (empty($fingerprintId)) {
            return ['success' => false, 'message' => 'user_id الزامی است'];
        }

        try {
            // بازه ماه
            if (empty($year) || empty($month)) {
                $todayFa = Persian::convert_date_to_fa(date('Y-m-d'));
                [$jy, $jm, $jd] = explode('/', $todayFa);
                $year  = (int) $jy;
                $month = (int) $jm;
            }

            $lastDay = Persian::get_last_day_of_month($month);
            $fromFa = sprintf('%04d/%02d/01', $year, $month);
            $toFa   = sprintf('%04d/%02d/%02d', $year, $month, $lastDay);

            $from = Persian::convert_date_to_en($fromFa);
            $to   = Persian::convert_date_to_en($toFa);

            // رکوردهای خام
            $logs = UsersLog::find()
                ->where(['user_id' => $fingerprintId])
                ->andWhere(['between', 'checkindate', "$from 00:00:00", "$to 23:59:59"])
                ->orderBy(['checkindate' => SORT_ASC])
                ->asArray()
                ->all();

            return [
                'success' => true,
                'data' => [
                    'fingerprint_id' => $fingerprintId,
                    'year' => $year,
                    'month' => $month,
                    'date_from' => $from,
                    'date_to' => $to,
                    'count' => count($logs),
                    'logs' => $logs,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }

    /**
     * GET /hq/delog/calculate?user_id=5&year=1405&month=8
     * ⭐ اجرای calculateRange برای ماه مشخص
     */
    public function actionCalculate()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Yii::$app->request;
        $fingerprintId = $request->get('user_id');
        $year  = $request->get('year');
        $month = $request->get('month');

        try {
            if (empty($year) || empty($month)) {
                $todayFa = Persian::convert_date_to_fa(date('Y-m-d'));
                [$jy, $jm, $jd] = explode('/', $todayFa);
                $year  = (int) $jy;
                $month = (int) $jm;
            }

            $lastDay = Persian::get_last_day_of_month($month);
            $fromFa = sprintf('%04d/%02d/01', $year, $month);
            $toFa   = sprintf('%04d/%02d/%02d', $year, $month, $lastDay);

            $from = Persian::convert_date_to_en($fromFa);
            $to   = Persian::convert_date_to_en($toFa);

            $result = HrmDailyAttendance::calculateRange($from, $to, $fingerprintId);

            return [
                'success' => true,
                'data' => array_merge($result, [
                    'year' => $year,
                    'month' => $month,
                    'date_from' => $from,
                    'date_to' => $to,
                ]),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }
    }  /**
     * ⭐ این باید باشه
     */
    public function actionPayroll()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Yii::$app->request;
        $fingerprintId = $request->get('user_id');
        $year  = $request->get('year');
        $month = $request->get('month');

        if (empty($fingerprintId)) {
            return ['success' => false, 'message' => 'user_id الزامی است'];
        }

        try {
            return HrmDailyAttendance::getPayrollPreview($fingerprintId, $year, $month);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }


    public function actionUserContracts()
{
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

    $fingerprintId = Yii::$app->request->get('user_id');
    if (empty($fingerprintId)) {
        return ['success' => false, 'message' => 'user_id الزامی است'];
    }

    $user = User::findOne(['fingerprint_id' => $fingerprintId]);
    if (!$user) {
        return ['success' => false, 'message' => 'کاربر یافت نشد'];
    }

    // همه قراردادها
    $allContracts = HrmContract::find()->asArray()->all();

    // فیلتر بر اساس user_id
    $byUserId = array_filter($allContracts, fn($c) => (int) $c['user_id'] === (int) $user->id);
    $byUserIdActive = array_filter($byUserId, fn($c) => (int) $c['contract_status'] === 1);

    // فیلتر بر اساس personnel_code
    $byPersonnelCode = array_filter($allContracts, fn($c) =>
        !empty($c['personnel_code']) && !empty($user->personnel_code) && $c['personnel_code'] === $user->personnel_code
    );

    // فیلتر بر اساس national_code
    $byNationalCode = array_filter($allContracts, fn($c) =>
        !empty($c['national_code']) && !empty($user->national_id) && $c['national_code'] === $user->national_id
    );

    // ⭐ همه قراردادهایی که user_id دارن (برای دیباگ)
    $withUserId = array_filter($allContracts, fn($c) => !empty($c['user_id']) && (int) $c['user_id'] > 0);

    return [
        'success' => true,
        'data' => [
            'user' => [
                'id' => $user->id,
                'fingerprint_id' => $user->fingerprint_id,
                'personnel_code' => $user->personnel_code,
                'national_id' => $user->national_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            ],
            'counts' => [
                'total_contracts' => count($allContracts),
                'by_user_id' => count($byUserId),
                'by_user_id_active' => count($byUserIdActive),
                'by_personnel_code' => count($byPersonnelCode),
                'by_national_code' => count($byNationalCode),
                'with_user_id' => count($withUserId),
            ],
            'by_user_id' => array_values($byUserId),
            'by_personnel_code' => array_values($byPersonnelCode),
            'by_national_code' => array_values($byNationalCode),
        ],
    ];
}
}