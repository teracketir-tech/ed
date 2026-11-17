<?php
// frontend/modules/hq/services/AttendanceReadService.php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\HrmDailyAttendance;
use frontend\modules\hq\models\UsersLog;
use frontend\modules\hq\models\User;
use Yii;

/**
 * AttendanceReadService
 *
 * فقط خواندن. هیچ‌وقت چیزی نمی‌نویسه.
 * برای realtime listing و polling
 */
class AttendanceReadService
{
    /**
     * لیست کارکردها با ساعت‌های LIVE از users_logs
     *
     * @param array $filters  user_id, date_from, date_to, personnel_code, ...
     * @param int   $page
     * @param int   $perPage
     * @return array
     */
    public static function getList(array $filters, int $page = 1, int $perPage = 10): array
    {
        $query = HrmDailyAttendance::find()
            ->with(['user'])
            ->orderBy(['attendance_date' => SORT_DESC, 'id' => SORT_DESC]);

        // فیلترها
        if (!empty($filters['user_id'])) {
            $query->andWhere(['user_id' => $filters['user_id']]);
        }
        if (!empty($filters['date_from'])) {
            $query->andWhere(['>=', 'attendance_date', $filters['date_from']]);
        }
        if (!empty($filters['date_to'])) {
            $query->andWhere(['<=', 'attendance_date', $filters['date_to']]);
        }
        if (!empty($filters['personnel_code'])) {
            $query->joinWith('user u')
                ->andWhere(['u.personnel_code' => $filters['personnel_code']]);
        }
        if (!empty($filters['status'])) {
            // فیلتر وضعیت (is_vacation, is_absent, ...)
            self::applyStatusFilter($query, $filters['status']);
        }
        if (!empty($filters['workgroup_ids'])) {
            foreach ($filters['workgroup_ids'] as $wgId) {
                $query->andWhere(['like', 'workgroup_ids', '"' . (int) $wgId . '"']);
            }
        }

        $totalCount = (int) $query->count();

        $models = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        // ⭐ LIVE ساعت‌ها از users_logs
        self::enrichWithLiveDeviceData($models);

        return [
            'pages' => (int) ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $models,
        ];
    }

    /**
     * ⭐ اضافه کردن ساعت‌های LIVE از users_logs
     * اگه دستگاه هنوز آپدیت نکرده، از cache (hrm_daily_attendance) استفاده می‌کنه
     *
     * @param array $models خروجی hrm_daily_attendance
     */
    private static function enrichWithLiveDeviceData(array &$models): void
    {
        if (empty($models)) return;

        // جمع‌آوری user_id و date ها
        $userIds = array_unique(array_column($models, 'user_id'));
        $dates   = array_unique(array_column($models, 'attendance_date'));

        // نقشه: user.id → fingerprint_id
        $users = User::find()
            ->where(['id' => $userIds])
            ->indexBy('id')
            ->asArray()
            ->all();

        $fingerprintToUser = [];
        foreach ($users as $u) {
            if (!empty($u['fingerprint_id'])) {
                $fingerprintToUser[$u['fingerprint_id']] = $u['id'];
            }
        }

        if (empty($fingerprintToUser)) {
            return; // هیچ کاربر دستگاه‌داری نیست
        }

        // گرفتن لاگ‌های دستگاه برای این بازه
        $logs = UsersLog::find()
            ->where(['in', 'user_id', array_keys($fingerprintToUser)])
            ->andWhere(['between', 'checkindate',
                min($dates) . ' 00:00:00',
                max($dates) . ' 23:59:59',
            ])
            ->asArray()
            ->all();

        // index: "user_id|date" → log
        $logIndex = [];
        foreach ($logs as $log) {
            $date = substr((string) $log['checkindate'], 0, 10);
            $userId = $fingerprintToUser[$log['user_id']] ?? null;
            if ($userId) {
                $logIndex[$userId . '|' . $date] = $log;
            }
        }

        // اعمال روی هر model
        foreach ($models as &$model) {
            $key = $model['user_id'] . '|' . $model['attendance_date'];

            if (!isset($logIndex[$key])) {
                continue; // لاگ دستگاه نداره → cache استفاده بشه
            }

            $log = $logIndex[$key];

            // ⭐ ساعت‌های live
            $model['check_in_time']  = self::extractTime($log['checkindate']);
            $model['check_out_time'] = self::extractTime($log['checkout_date']);
            $model['is_from_device'] = true;

            // اگه hour_normal/hour_extra پر بود، جایگزین کن
            if (!empty($log['hour_normal'])) {
                $model['work_duration'] = (float) $log['hour_normal'];
            }
            if (!empty($log['hour_extra'])) {
                $model['overtime'] = (float) $log['hour_extra'];
            }
            if (isset($log['delay_minutes'])) {
                $model['delay_minutes'] = (int) $log['delay_minutes'];
            }
            if (isset($log['extra_minutes'])) {
                $model['early_leave_minutes'] = (int) $log['extra_minutes'];
            }
        }
    }

    private static function applyStatusFilter($query, string $status): void
    {
        switch ($status) {
            case 'full_day':    $query->andWhere(['is_full_day' => 1]); break;
            case 'absent':      $query->andWhere(['is_absent' => 1]);   break;
            case 'vacation':    $query->andWhere(['is_vacation' => 1]); break;
            case 'mission':     $query->andWhere(['is_mission' => 1]);  break;
            case 'holiday':     $query->andWhere(['is_holiday' => 1]);  break;
        }
    }

    private static function extractTime(?string $datetime): ?string
    {
        if (empty($datetime)) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}:\d{2})/', $datetime, $m)) {
            return $m[1] . ':00';
        }
        return null;
    }
}