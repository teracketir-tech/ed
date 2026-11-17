<?php
// frontend/modules/hq/models/HrmReports.php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;

class HrmReports extends ActiveRecord
{
    public static function tableName()
    {
        return 'hrm_salary_slips';
    }

    // ============================================================
    // ⭐ گزارش ۱: دستمزد و جریمه کارکرد
    // ============================================================
    public static function getSalaryPenalty()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = (int) ($params['per-page'] ?? 100);
        $page = (int) ($params['page'] ?? 1);

        $query = HrmSalarySlip::find()
            ->with(['user'])
            ->orderBy(['year' => SORT_DESC, 'month' => SORT_DESC, 'id' => SORT_DESC]);

        // فیلترها
        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['personnel_code'])) {
            $userIds = self::resolveUserIds($params['personnel_code'], null);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['national_code'])) {
            $userIds = self::resolveUserIds(null, $params['national_code']);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => (int) $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        $totalCount = (int) $query->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $data[] = self::formatSalaryPenalty($row);
        }

        return [
            'pages' => (int) ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $data,
        ];
    }

    // ============================================================
    // ⭐ گزارش ۲: شرح پرداخت
    // ============================================================
    public static function getPaymentDescription()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = (int) ($params['per-page'] ?? 100);
        $page = (int) ($params['page'] ?? 1);

        $query = HrmSalarySlip::find()
            ->with(['user'])
            ->orderBy(['year' => SORT_DESC, 'month' => SORT_DESC, 'id' => SORT_DESC]);

        // فیلتر ماه و سال
        if (!empty($params['month'])) {
            $query->andWhere(['month' => (int) $params['month']]);
        }
        if (!empty($params['year'])) {
            $query->andWhere(['year' => (int) $params['year']]);
        }

        // فیلترها
        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['personnel_code'])) {
            $userIds = self::resolveUserIds($params['personnel_code'], null);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['national_code'])) {
            $userIds = self::resolveUserIds(null, $params['national_code']);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => (int) $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['contract_type'])) {
            $userIds = HrmContract::find()
                ->where(['contract_type' => (int) $params['contract_type']])
                ->andWhere(['contract_status' => HrmContract::STATUS_ACTIVE])
                ->select('user_id')
                ->column();
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        $totalCount = (int) $query->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $data[] = self::formatPaymentDescription($row);
        }

        return [
            'pages' => (int) ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $data,
        ];
    }

    // ============================================================
    // ⭐ گزارش ۳: شرح کسور
    // ============================================================
    public static function getDeductionDescription()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = (int) ($params['per-page'] ?? 100);
        $page = (int) ($params['page'] ?? 1);

        $query = HrmSalarySlip::find()
            ->with(['user'])
            ->orderBy(['year' => SORT_DESC, 'month' => SORT_DESC, 'id' => SORT_DESC]);

        // فیلتر ماه و سال
        if (!empty($params['month'])) {
            $query->andWhere(['month' => (int) $params['month']]);
        }
        if (!empty($params['year'])) {
            $query->andWhere(['year' => (int) $params['year']]);
        }

        // فیلترها
        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['personnel_code'])) {
            $userIds = self::resolveUserIds($params['personnel_code'], null);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['national_code'])) {
            $userIds = self::resolveUserIds(null, $params['national_code']);
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => (int) $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        if (!empty($params['contract_type'])) {
            $userIds = HrmContract::find()
                ->where(['contract_type' => (int) $params['contract_type']])
                ->andWhere(['contract_status' => HrmContract::STATUS_ACTIVE])
                ->select('user_id')
                ->column();
            if ($userIds === []) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }
            $query->andWhere(['user_id' => $userIds]);
        }

        $totalCount = (int) $query->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $data[] = self::formatDeductionDescription($row);
        }

        return [
            'pages' => (int) ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $data,
        ];
    }

    // ============================================================
    // ⭐ فرمت‌دهی هر گزارش
    // ============================================================

    private static function formatSalaryPenalty(array $row): array
    {
        $user = self::resolveUser($row['user_id']);
        $workgroupName = self::resolveWorkgroupName($row['user_id']);

        return [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'user' => $user,
            'year' => $row['year'],
            'month' => $row['month'],
            'month_name' => $row['month_name'],
            'workgroup_name' => $workgroupName,

            // حقوق
            'base_salary' => (float) $row['base_salary'],

            // اضافه کار
            'overtime_hours' => (float) $row['overtime_hours'],
            'overtime_pay' => (float) $row['overtime_pay'],

            // جمعه کار
            'friday_work' => (float) ($row['friday_work_pay'] ?? 0),
            'friday_pay' => (float) ($row['friday_work_pay'] ?? 0),

            // غیبت
            'absence_days' => (int) ($row['absent_days'] ?? 0),
            'absence_penalty' => (float) ($row['absent_deduction'] ?? 0),

            // پاداش/جریمه
            'bonus' => (float) ($row['bonus_amount'] ?? 0),
            'penalty' => (float) ($row['penalty_amount'] ?? 0),

            // خالص
            'net_payable' => (float) $row['net_payable'],
        ];
    }

    private static function formatPaymentDescription(array $row): array
    {
        $user = self::resolveUser($row['user_id']);
        $workgroupName = self::resolveWorkgroupName($row['user_id']);

        return [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'user' => $user,
            'year' => $row['year'],
            'month' => $row['month'],
            'month_name' => $row['month_name'],
            'workgroup_name' => $workgroupName,

            'base_salary' => (float) $row['base_salary'],
            'housing_allowance' => (float) ($row['housing_allowance'] ?? 0),
            'food_allowance' => (float) ($row['food_allowance'] ?? 0),
            'child_allowance' => (float) ($row['child_allowance'] ?? 0),
            'transportation' => (float) ($row['transportation_allowance'] ?? 0),
            'responsibility' => (float) ($row['responsibility_allowance'] ?? 0),
            'overtime' => (float) ($row['overtime_pay'] ?? 0),
            'bonus' => (float) ($row['bonus_amount'] ?? 0),

            'total_payment' => (float) $row['total_additions'],
        ];
    }

    private static function formatDeductionDescription(array $row): array
    {
        $user = self::resolveUser($row['user_id']);
        $workgroupName = self::resolveWorkgroupName($row['user_id']);

        return [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'user' => $user,
            'year' => $row['year'],
            'month' => $row['month'],
            'month_name' => $row['month_name'],
            'workgroup_name' => $workgroupName,

            'insurance' => (float) ($row['insurance_amount'] ?? 0),
            'supplementary_insurance' => (float) ($row['supplementary_insurance'] ?? 0),
            'tax' => (float) ($row['tax'] ?? 0),
            'loan_installment' => (float) ($row['loan_installment'] ?? 0),
            'advance' => (float) ($row['advance_amount'] ?? 0),
            'absence_penalty' => (float) ($row['absent_deduction'] ?? 0),
            'other_deductions' => (float) ($row['other_deduction'] ?? 0),

            'total_deductions' => (float) $row['total_deductions'],
        ];
    }

    // ============================================================
    // ⭐ helpers
    // ============================================================

    /**
     * resolve user_ids از personnel_code و/یا national_code
     * - اول در User
     * - بعد در HrmContract
     * - بعد در HrmPersonnelBasic
     */
    private static function resolveUserIds(?string $personnelCode, ?string $nationalCode): array
    {
        $ids = [];

        if ($personnelCode !== null && $personnelCode !== '') {
            // personnel_code یا fingerprint_id
            $fromUser = User::find()
                ->where(['or',
                    ['personnel_code' => $personnelCode],
                    ['fingerprint_id' => $personnelCode],
                ])
                ->select('id')
                ->column();
            $ids = array_merge($ids, $fromUser);

            if (empty($ids)) {
                $fromContract = HrmContract::find()
                    ->where(['personnel_code' => $personnelCode])
                    ->select('user_id')
                    ->column();
                $ids = array_merge($ids, $fromContract);
            }
        }

        if ($nationalCode !== null && $nationalCode !== '') {
            $fromContract = HrmContract::find()
                ->where(['national_code' => $nationalCode])
                ->select('user_id')
                ->column();
            $ids = array_merge($ids, $fromContract);

            $fromBasic = HrmPersonnelBasic::find()
                ->where(['national_code' => $nationalCode])
                ->select('user_id')
                ->column();
            $ids = array_merge($ids, $fromBasic);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private static function resolveUser(int $userId): ?array
    {
        $user = User::find()
            ->select(['id', 'first_name', 'last_name', 'personnel_code', 'fingerprint_id'])
            ->where(['id' => $userId])
            ->asArray()
            ->one();

        if (!$user) {
            return null;
        }

        // personnel_code = fingerprint_id اگر بود
        if (!empty($user['fingerprint_id'])) {
            $user['personnel_code'] = (string) $user['fingerprint_id'];
        }

        return $user;
    }

    private static function resolveWorkgroupName(int $userId): string
    {
        $rows = HrmWorkgroupPersonel::find()
            ->with(['workgroup'])
            ->where(['user_id' => $userId, 'status' => 1])
            ->asArray()
            ->all();

        $names = [];
        foreach ($rows as $row) {
            if (!empty($row['workgroup']['name'])) {
                $names[] = $row['workgroup']['name'];
            }
        }

        return implode(' - ', $names) ?: '-';
    }
}