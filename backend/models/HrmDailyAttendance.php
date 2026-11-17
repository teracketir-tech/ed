<?php
// frontend/modules/hq/models/HrmDailyAttendance.php

namespace frontend\modules\hq\models;
use frontend\modules\hq\models\HrmLoan;
use frontend\modules\hq\models\HrmAdvance;
use frontend\modules\hq\models\HrmAdjustment;
use frontend\modules\hq\models\HrmVacationBuyback;
use frontend\modules\hq\models\HrmInsurance;
use frontend\modules\hq\models\HrmVacationType;
use Yii;
use yii\db\ActiveRecord;

class HrmDailyAttendance extends ActiveRecord
{
    const SOURCE_MANUAL = 1;
    const SOURCE_DEVICE = 2;

    // ثابت‌های جدید
    const STANDARD_WORK_HOURS = 8.0;
    const WORK_HOURS_TOLERANCE = 0.1;

    // ثابت‌های ساعت کاری پیش‌فرض
    const DEFAULT_START_TIME = '08:00';
    const DEFAULT_END_TIME = '16:00';
    const DEFAULT_WORK_HOURS = 8;

    // جدول همچنان hrm_daily_attendance می‌مونه (برای فیلدهای HRM آینده)
    public static function tableName()
    {
        return 'hrm_daily_attendance';
    }

    public function rules()
    {
        return [
            [['user_id', 'attendance_date', 'attendance_date_persian'], 'required'],
            [['user_id', 'is_full_day', 'is_holiday', 'is_absent', 'is_vacation', 'is_mission', 'source', 'delay_minutes', 'early_leave_minutes'], 'integer'],
            [['attendance_date'], 'date', 'format' => 'php:Y-m-d'],
            [['check_in_time', 'check_out_time'], 'safe'],
            [['work_duration', 'overtime'], 'number'],
            [['description'], 'string'],
            [['workgroup_ids', 'custom_added'], 'safe'],
            ['attendance_date', 'unique', 'targetAttribute' => ['user_id', 'attendance_date'], 'message' => 'این تاریخ برای این کاربر قبلاً ثبت شده است'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'شناسه',
            'user_id' => 'کد پرسنلی',
            'workgroup_ids' => 'گروه‌های کاری',
            'attendance_date' => 'تاریخ حضور',
            'attendance_date_persian' => 'تاریخ حضور (شمسی)',
            'check_in_time' => 'ساعت ورود',
            'check_out_time' => 'ساعت خروج',
            'is_full_day' => 'حضور کامل',
            'is_holiday' => 'روز تعطیل',
            'is_absent' => 'غیبت',
            'is_vacation' => 'مرخصی',
            'is_mission' => 'ماموریت',
            'work_duration' => 'مدت کارکرد (ساعت)',
            'overtime' => 'اضافه کار (ساعت)',
            'delay_minutes' => 'تاخیر (دقیقه)',
            'early_leave_minutes' => 'ترک زودهنگام (دقیقه)',
            'description' => 'توضیحات',
            'source' => 'منبع ثبت',
            'created_at' => 'تاریخ ثبت',
            'updated_at' => 'تاریخ ویرایش',
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    // ============================================================
    // get_all: فقط از users_logs می‌خونه
    // ============================================================

    public static function get_all()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = (int) ($params['per-page'] ?? 10);
        $page = (int) ($params['page'] ?? 1);

        // مرحله ۱: کاربران دستگاه‌دار
        $usersQuery = User::find()
            ->where(['not', ['fingerprint_id' => null]]);

        // فیلتر کاربر
        if (!empty($params['user_id'])) {
            $usersQuery->andWhere(['id' => $params['user_id']]);
        }
        if (!empty($params['personnel_code'])) {
            $usersQuery->andWhere([
                'or',
                ['personnel_code' => $params['personnel_code']],
                ['fingerprint_id' => $params['personnel_code']],
            ]);
        }
        if (!empty($params['national_code'])) {
            $nationalCode = trim((string) $params['national_code']);

            // جستجو در hrm_contract
            $contractUserIds = HrmContract::find()
                ->select('user_id')
                ->distinct()
                ->where(['national_code' => $nationalCode])
                ->andWhere(['not', ['user_id' => null]])
                ->column();

            // جستجو در hrm_personnel_basic
            $personnelUserIds = HrmPersonnelBasic::find()
                ->select('user_id')
                ->distinct()
                ->where(['national_code' => $nationalCode])
                ->andWhere(['not', ['user_id' => null]])
                ->column();

            // ادغام (بدون تکرار)
            $allUserIds = array_values(array_unique(array_merge(
                $contractUserIds,
                $personnelUserIds
            )));

            if (empty($allUserIds)) {
                return ['pages' => 0, 'totalCount' => 0, 'data' => []];
            }

            $usersQuery->andWhere(['id' => $allUserIds]);
        }

        $users = $usersQuery->indexBy('fingerprint_id')->all();

        if (empty($users)) {
            return ['pages' => 0, 'totalCount' => 0, 'data' => []];
        }

        $fingerprintIds = array_keys($users);

        // مرحله ۲: لاگ‌های users_logs در بازه
        $logsQuery = UsersLog::find()
            ->where(['in', 'user_id', $fingerprintIds]);

        // فیلتر تاریخ
        if (!empty($params['date_from'])) {
            $logsQuery->andWhere(['>=', 'checkindate', $params['date_from'] . ' 00:00:00']);
        }
        if (!empty($params['date_to'])) {
            $logsQuery->andWhere(['<=', 'checkindate', $params['date_to'] . ' 23:59:59']);
        }

        $totalCount = (int) $logsQuery->count();

        $logs = $logsQuery
            ->orderBy(['checkindate' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        // مرحله ۳: تبدیل هر users_logs به ساختار HrmDailyAttendance
        $data = [];
        foreach ($logs as $log) {
            $user = $users[$log['user_id']] ?? null;
            if (!$user)
                continue;

            $data[] = self::logToRow($log, $user);
        }

        return [
            'pages' => (int) ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $data,
        ];
    }

    /**
     * تبدیل یک ردیف users_logs به ساختار خروجی کامل
     */
    private static function logToRow(array $log, User $user, array $nationalCodes = []): array
    {
        // ============================================================
        // ۱) استخراج ساعت‌ها
        // ============================================================
        $checkIn = self::extractTime($log['checkindate'] ?? null);
        $checkOut = self::extractTime($log['checkout_date'] ?? null);

        $attendanceDate = !empty($log['checkindate'])
            ? substr($log['checkindate'], 0, 10)
            : null;

        // ============================================================
        // ۲) کارکرد — اولویت: hour_normal → محاسبه محلی
        // ============================================================
        $workDuration = 0.0;

        if (!empty($log['hour_normal']) && (float) $log['hour_normal'] > 0) {
            $workDuration = (float) $log['hour_normal'];
        } elseif (!empty($checkIn) && !empty($checkOut)) {
            $workDuration = self::calcDuration($checkIn, $checkOut);
        }

        // ============================================================
        // ۳) اضافه کار — اولویت: hour_extra → محاسبه محلی
        // ============================================================
        $overtime = 0.0;

        if (!empty($log['hour_extra']) && (float) $log['hour_extra'] > 0) {
            $overtime = (float) $log['hour_extra'];
        } elseif ($workDuration > self::STANDARD_WORK_HOURS) {
            $overtime = round($workDuration - self::STANDARD_WORK_HOURS, 2);
        }

        // ============================================================
        // ۴) تأخیر و تعجیل — از فیلدهای محاسبه‌شده
        // ============================================================
        $delayMinutes = (int) ($log['delay_minutes'] ?? 0);
        $earlyLeave = (int) ($log['early_leave_minutes'] ?? 0); // اصلاح شد

        // ============================================================
        // ۵) وضعیت حضور
        // ============================================================
        $status = self::getAttendanceStatus($workDuration, $checkIn, $checkOut);

        // ============================================================
        // ۶) جمعه کار
        // ============================================================
        $dayOfWeek = $attendanceDate ? (int) date('N', strtotime($attendanceDate)) : 0;
        $isFriday = ($dayOfWeek === 5);
        $fridayWork = ($isFriday && $workDuration > 0) ? round($workDuration, 2) : 0.0;

        // ============================================================
        // ۷) تعطیل کار (فعلاً غیرفعال — بعداً از HrmSetting/API)
        // ============================================================
        $isHoliday = false;
        $holidayWork = 0.0;

        // ============================================================
        // ۸) غیبت (روز) — از status
        // ============================================================
        $isAbsent = ($status['value'] === 'absent') ? 1 : 0;

        // ============================================================
        // ۹) ماموریت (روز) — از users_logs فیلد نداریم، فعلاً 0
        // ============================================================
        $isMission = 0;

        // ============================================================
        // ۱۰) خروج غیرمجاز — فعلاً 0
        // ============================================================
        $unauthorizedExit = 0;

        // ============================================================
        // ۱۱) کد ملی و کد پرسنلی
        // ============================================================
        $nationalCode = $nationalCodes[$user->id] ?? null;

        $personnelCode = $user->fingerprint_id !== null
            ? (string) $user->fingerprint_id
            : ($user->personnel_code ?? null);

        // ============================================================
        // ۱۲) خروجی نهایی
        // ============================================================
        return [
            // ===== شناسه‌ها =====
            'id' => $log['id'],
            'user_id' => $user->id,

            // ===== اطلاعات کاربر =====
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'personnel_code' => $personnelCode,
                'national_code' => $nationalCode,
                'fingerprint_id' => $user->fingerprint_id,
            ],

            // ===== تاریخ =====
            'attendance_date' => $attendanceDate,
            'attendance_date_persian' => $attendanceDate
                ? Persian::convert_date_to_fa($attendanceDate)
                : null,

            // ===== ساعت‌ها =====
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,

            // ===== کارکرد =====
            'work_duration' => round($workDuration, 2),
            'work_duration_formatted' => self::formatDuration($workDuration),

            // ===== اضافه کار =====
            'overtime' => round($overtime, 2),
            'overtime_formatted' => self::formatDuration($overtime),

            // ===== تأخیر / تعجیل =====
            'delay_minutes' => $delayMinutes,
            'early_leave_minutes' => $earlyLeave,

            // ===== وضعیت حضور =====
            'attendance_status' => $status['value'],
            'attendance_status_label' => $status['label'],
            'attendance_status_color' => $status['color'],

            // ===== جمعه کار =====
            'friday_work_hours' => $fridayWork,
            'friday_work_formatted' => self::formatDuration($fridayWork),
            'is_friday' => $isFriday,

            // ===== تعطیل کار (فعلاً 0) =====
            'holiday_work_hours' => $holidayWork,
            'holiday_work_formatted' => self::formatDuration($holidayWork),
            'is_holiday' => $isHoliday ? 1 : 0,

            // ===== فیلدهای HRM =====
            'workgroup_ids' => [],
            'workgroup_names' => '',
            'is_full_day' => $status['value'] === 'full_day' ? 1 : 0,
            'is_absent' => $isAbsent,
            'is_vacation' => 0,
            'is_mission' => $isMission,
            'description' => null,
            'source' => self::SOURCE_DEVICE,

            // ===== فیلدهای اضافی فرانت =====
            'special_overtime' => 0,
            'special_overtime_formatted' => '00:00',
            'mission_overtime' => 0,
            'mission_overtime_formatted' => '00:00',
            'unauthorized_exit' => $unauthorizedExit,
            'custom_added' => $log['custom_added'] ?? 0,

            // ===== اطلاعات تکمیلی =====
            'is_from_device' => true,
            'created_at' => $log['created_at'] ?? $attendanceDate,
            'updated_at' => $log['updated_at'] ?? $attendanceDate,
            'last_date' => ($log['created_at'] ?? $log['updated_at']) ?? $attendanceDate,
        ];
    }

    /**
     * محاسبه وضعیت حضور
     */
    private static function getAttendanceStatus(
        float $workDuration,
        ?string $checkIn,
        ?string $checkOut
    ): array {
        // ۱) فقط ورود، بدون خروج
        if (!empty($checkIn) && empty($checkOut)) {
            return [
                'label' => 'بدون خروج',
                'color' => 'text-purple-600',
                'value' => 'no_checkout',
            ];
        }

        // ۲) فقط خروج، بدون ورود
        if (empty($checkIn) && !empty($checkOut)) {
            return [
                'label' => 'بدون ورود',
                'color' => 'text-purple-600',
                'value' => 'no_checkin',
            ];
        }

        // ۳) هیچ ساعتی ندارد
        if (empty($checkIn) && empty($checkOut)) {
            return [
                'label' => 'غیبت',
                'color' => 'text-red-600',
                'value' => 'absent',
            ];
        }

        // ۴) کارکرد صفر (با وجود ساعت)
        if ($workDuration <= 0) {
            return [
                'label' => 'بدون کارکرد',
                'color' => 'text-red-600',
                'value' => 'zero_duration',
            ];
        }

        // ۵) اضافه کار
        if ($workDuration > self::STANDARD_WORK_HOURS + self::WORK_HOURS_TOLERANCE) {
            return [
                'label' => 'اضافه کار',
                'color' => 'text-orange-600',
                'value' => 'overtime',
            ];
        }

        // ۶) کمتر از حد مجاز
        if ($workDuration < self::STANDARD_WORK_HOURS - self::WORK_HOURS_TOLERANCE) {
            return [
                'label' => 'کمتر از حد مجاز',
                'color' => 'text-yellow-600',
                'value' => 'under_hours',
            ];
        }

        // ۷) حضور کامل
        return [
            'label' => 'حضور کامل',
            'color' => 'text-green-600',
            'value' => 'full_day',
        ];
    }
    /**
     * خلاصه ماهانه یک پرسنل
     *
     * @param int $fingerprintId  fingerprint_id کاربر
     * @param int|null $year      سال شمسی (null → جاری)
     * @param int|null $month     ماه شمسی (null → جاری)
     * @return array
     */
    public static function getMonthlySummary($fingerprintId, $year = null, $month = null): array
    {
        // ===== ۱. بازه =====
        if (empty($year) || empty($month)) {
            $todayFa = Persian::convert_date_to_fa(date('Y-m-d'));
            [$jy, $jm, $jd] = explode('/', $todayFa);
            $year = (int) $jy;
            $month = (int) $jm;
        }
        $lastDay = Persian::get_last_day_of_month($month);
        $fromFa = sprintf('%04d/%02d/01', $year, $month);
        $toFa = sprintf('%04d/%02d/%02d', $year, $month, $lastDay);
        $from = Persian::convert_date_to_en($fromFa);
        $to = Persian::convert_date_to_en($toFa);

        // ===== ۲. map =====
        $user = User::findOne(['fingerprint_id' => $fingerprintId]);
        if (!$user) {
            return ['error' => 'کاربر یافت نشد'];
        }
        $realUserId = $user->id;

        // ===== ۳. users_logs =====
        $logs = UsersLog::find()
            ->where(['user_id' => $fingerprintId])
            ->andWhere(['between', 'checkindate', "$from 00:00:00", "$to 23:59:59"])
            ->orderBy(['checkindate' => SORT_ASC])
            ->all();

        // گروه‌بندی بر اساس تاریخ
        $logsByDate = [];
        foreach ($logs as $log) {
            $date = substr($log->checkindate, 0, 10);
            if (!isset($logsByDate[$date])) {
                $logsByDate[$date] = [];
            }
            $logsByDate[$date][] = $log;
        }

        $totalWorkHours = 0.0;
        $totalOvertimeHours = 0.0;
        $totalDelayMinutes = 0;
        $totalEarlyLeaveMinutes = 0;
        $totalFridayHours = 0.0;
        $workDaysCount = 0;
        $presentDaysCount = 0;
        $absentDaysCount = 0;
        $debugLogs = [];

        // برای هر روز، بهترین رکورد رو انتخاب کن
        foreach ($logsByDate as $date => $dayLogs) {
            // انتخاب بهترین: بیشترین hour_normal
            $bestLog = null;
            $maxNormal = -1;
            foreach ($dayLogs as $log) {
                $normal = (float) ($log->hour_normal ?? 0);
                if ($normal > $maxNormal) {
                    $maxNormal = $normal;
                    $bestLog = $log;
                }
            }
            if (!$bestLog)
                continue;

            $normal = (float) ($bestLog->hour_normal ?? 0);
            $extra = (float) ($bestLog->hour_extra ?? 0);
            $delay = (int) ($bestLog->delay_minutes ?? 0);
            $early = (int) ($bestLog->early_leave_minutes ?? 0);

            $totalWorkHours += $normal;
            $totalOvertimeHours += $extra;
            $totalDelayMinutes += $delay;
            $totalEarlyLeaveMinutes += $early;

            if ($normal > 0)
                $workDaysCount++;

            $isFriday = (int) date('N', strtotime($date)) === 5;
            if ($isFriday && $normal > 0) {
                $totalFridayHours += $normal;
            }

            $hasCheckIn = !empty($bestLog->checkindate)
                && substr($bestLog->checkindate, 11, 8) !== '00:00:00';
            if ($hasCheckIn) {
                $presentDaysCount++;
            } else {
                $absentDaysCount++;
            }

            $debugLogs[] = [
                'date' => $date,
                'chosen_log_id' => $bestLog->id,
                'all_log_ids' => array_map(fn($l) => $l->id, $dayLogs),
                'hour_normal' => $normal,
                'hour_extra' => $extra,
                'delay_minutes' => $delay,
                'early_leave_minutes' => $early,
                'is_friday' => $isFriday,
                'is_present' => $hasCheckIn,
            ];
        }

        // ===== ۴. ماموریت =====
        $missionHours = 0.0;
        $missionDays = 0;
        $debugMissions = [];
        $missions = HrmMission::find()
            ->where(['user_id' => $realUserId, 'status' => HrmMission::STATUS_APPROVED])
            ->andWhere(['<=', 'date_from', $to])
            ->andWhere(['>=', 'date_to', $from])
            ->all();

        foreach ($missions as $mission) {
            $mFrom = max(strtotime($mission->date_from), strtotime($from));
            $mTo = min(strtotime($mission->date_to), strtotime($to));
            if ($mTo >= $mFrom) {
                $days = (int) floor(($mTo - $mFrom) / 86400) + 1;
                $missionDays += $days;
                $missionHours += $days * self::DEFAULT_WORK_HOURS;
                $debugMissions[] = [
                    'id' => $mission->id,
                    'overlap_days' => $days,
                    'hours' => $days * self::DEFAULT_WORK_HOURS,
                ];
            }
        }

        // ===== ۵. مرخصی =====
        $vacationHours = 0.0;
        $vacationDays = 0;
        $debugVacations = [];
        $vacations = HrmVacation::find()
            ->where(['user_id' => $realUserId, 'status' => HrmVacation::STATUS_APPROVED])
            ->andWhere(['<=', 'date_from', $to])
            ->andWhere(['>=', 'date_to', $from])
            ->all();

        foreach ($vacations as $vacation) {
            $vFrom = max(strtotime($vacation->date_from), strtotime($from));
            $vTo = min(strtotime($vacation->date_to), strtotime($to));
            if ($vTo >= $vFrom) {
                $days = (int) floor(($vTo - $vFrom) / 86400) + 1;
                $vacationDays += $days;
                $vacationHours += $days * self::DEFAULT_WORK_HOURS;
                $debugVacations[] = [
                    'id' => $vacation->id,
                    'overlap_days' => $days,
                    'hours' => $days * self::DEFAULT_WORK_HOURS,
                ];
            }
        }

        // ===== ۶. خروجی =====
        return [
            'user_id' => $fingerprintId,
            'real_user_id' => $realUserId,
            'year' => $year,
            'month' => $month,
            'date_from' => $from,
            'date_to' => $to,

            'total_work_hours' => round($totalWorkHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'total_delay_minutes' => $totalDelayMinutes,
            'total_early_leave_minutes' => $totalEarlyLeaveMinutes,
            'total_friday_hours' => round($totalFridayHours, 2),
            'work_days_count' => $workDaysCount,
            'present_days_count' => $presentDaysCount,
            'absent_days_count' => $absentDaysCount,

            'total_mission_hours' => round($missionHours, 2),
            'mission_days_count' => $missionDays,

            'total_vacation_hours' => round($vacationHours, 2),
            'vacation_days_count' => $vacationDays,

            'logs_count' => count($logs),
            'unique_days' => count($logsByDate),
            'missions_count' => count($missions),
            'vacations_count' => count($vacations),

            'debug' => [
                'logs' => $debugLogs,
                'missions' => $debugMissions,
                'vacations' => $debugVacations,
            ],
        ];
    }
    // ============================================================
    // _view: یک رکورد از users_logs
    // ============================================================

    public static function _view($id)
    {
        User::checkAccess(702);

        // $id اینجا = id از users_logs
        $log = UsersLog::find()->where(['id' => $id])->asArray()->one();

        if (!$log) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        // پیدا کردن کاربر با fingerprint_id
        $user = User::findOne(['fingerprint_id' => $log['user_id']]);

        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }

        return ['success' => true, 'data' => self::logToRow($log, $user)];
    }

    // ============================================================
    // _save: نوشتن در users_logs
    // ============================================================

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {
            User::checkAccess(701);
        } else {
            User::checkAccess(703);
        }

        // ===== تبدیل تاریخ شمسی =====
        if (!empty($params['attendance_date_persian']) && empty($params['attendance_date'])) {
            $params['attendance_date'] = Persian::convert_date_to_en($params['attendance_date_persian']);
        }

        if (empty($params['attendance_date'])) {
            return ['success' => false, 'message' => 'تاریخ الزامی است'];
        }

        if (empty($params['user_id'])) {
            return ['success' => false, 'message' => 'کاربر الزامی است'];
        }

        // ===== پیدا کردن کاربر =====
        $user = User::findOne($params['user_id']);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }

        if ($user->fingerprint_id === null) {
            return ['success' => false, 'message' => 'این کاربر دستگاه اثر انگشت ندارد'];
        }

        // ===== منطق is_full_day =====
        $isFullDay = !empty($params['is_full_day']) && (int) $params['is_full_day'] === 1;

        $checkIn = $params['check_in_time'] ?? null;
        $checkOut = $params['check_out_time'] ?? null;

        if ($isFullDay) {
            // حضور کامل: خودکار 8 ساعت (08:00 - 16:00)
            $checkIn = '08:00';
            $checkOut = '16:00';
        } else {
            // حضور عادی: حداقل یکی از ساعت‌ها الزامیه
            if (empty($checkIn) && empty($checkOut)) {
                return [
                    'success' => false,
                    'message' => 'حداقل یکی از ساعت ورود یا خروج الزامی است',
                ];
            }
        }

        // ===== جستجوی لاگ موجود =====
        $log = UsersLog::find()
            ->where(['user_id' => $user->fingerprint_id])
            ->andWhere([
                'between',
                'checkindate',
                $params['attendance_date'] . ' 00:00:00',
                $params['attendance_date'] . ' 23:59:59',
            ])
            ->one();

        if (!$log) {
            $log = new UsersLog();
            $log->user_id = $user->fingerprint_id;
            $log->custom_added = 1;
        }

        // ===== نوشتن ساعت‌ها =====
        if (!empty($checkIn)) {
            $log->checkindate = self::combineDateTime($params['attendance_date'], $checkIn);
        }
        if (!empty($checkOut)) {
            $log->checkout_date = self::combineDateTime($params['attendance_date'], $checkOut);
        }

        // اگه is_full_day بود، ساعت‌های محاسباتی هم ست کن
        if ($isFullDay) {
            $log->hour_normal = 8;
            $log->hour_extra = 0;
            $log->delay_minutes = 0;
            $log->early_leave_minutes = 0;
        }

        // reset calculated برای محاسبه مجدد
        $log->calculated = 0;
        $log->calculated_at = null;

        if ($log->save(false)) {
            return [
                'success' => true,
                'data' => [
                    'id' => $log->id,
                    'user_id' => $user->id,
                    'attendance_date' => $params['attendance_date'],
                    'check_in_time' => $checkIn,
                    'check_out_time' => $checkOut,
                    'is_full_day' => $isFullDay ? 1 : 0,
                    'work_duration' => $isFullDay ? 8 : null,
                ],
            ];
        }

        return ['success' => false, 'errors' => $log->errors];
    }

    // ============================================================
    // _delete: حذف از users_logs
    // ============================================================

    public static function _delete($id)
    {
        User::checkAccess(704);

        $log = UsersLog::findOne($id);
        if (!$log) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        $log->delete();
        return ['success' => true];
    }

    // ============================================================
    // saveRange: ثبت رنج تاریخ در users_logs
    // ============================================================

    public static function saveRange()
    {
        $params = Yii::$app->request->post();

        User::checkAccess(701);

        $userId = $params['user_id'] ?? null;
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        $checkInTime = $params['check_in_time'] ?? null;
        $checkOutTime = $params['check_out_time'] ?? null;
        $isFullDay = !empty($params['is_full_day']);

        if (!$userId || !$dateFrom || !$dateTo) {
            return ['success' => false, 'message' => 'اطلاعات کامل نیست'];
        }

        $user = User::findOne($userId);
        if (!$user || $user->fingerprint_id === null) {
            return ['success' => false, 'message' => 'کاربر دستگاه اثر انگشت ندارد'];
        }

        // تبدیل تاریخ
        $dateFromGregorian = Persian::convert_date_to_en($dateFrom);
        $dateToGregorian = Persian::convert_date_to_en($dateTo);

        if (!$dateFromGregorian || !$dateToGregorian) {
            return ['success' => false, 'message' => 'فرمت تاریخ صحیح نیست'];
        }

        $start = new \DateTime($dateFromGregorian);
        $end = new \DateTime($dateToGregorian);

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $end->modify('+1 day');
        $period = new \DatePeriod($start, new \DateInterval('P1D'), $end);

        $savedCount = 0;
        $errors = [];
        $savedDates = [];

        foreach ($period as $date) {
            $attendanceDate = $date->format('Y-m-d');

            // بررسی وجود
            $exists = UsersLog::find()
                ->where(['user_id' => $user->fingerprint_id])
                ->andWhere([
                    'between',
                    'checkindate',
                    $attendanceDate . ' 00:00:00',
                    $attendanceDate . ' 23:59:59',
                ])
                ->exists();

            if ($exists) {
                $persianDate = Persian::convert_date_to_fa($attendanceDate);
                $errors[] = "تاریخ {$persianDate} قبلاً ثبت شده است";
                continue;
            }

            $log = new UsersLog();
            $log->user_id = $user->fingerprint_id;

            // ⚠️ حتی برای is_full_day، باید checkindate پر باشه (NOT NULL)
            if ($isFullDay) {
                // ساعت 00:00 به عنوان "حضور کامل بدون ساعت"
                $log->checkindate = $attendanceDate . ' 00:00:00';
            } else {
                if (empty($checkInTime)) {
                    $errors[] = "تاریخ {$attendanceDate}: ساعت ورود الزامی است";
                    continue;
                }
                $log->checkindate = self::combineDateTime($attendanceDate, $checkInTime);
                if (!empty($checkOutTime)) {
                    $log->checkout_date = self::combineDateTime($attendanceDate, $checkOutTime);
                }
            }

            if ($log->save(false)) {
                $savedCount++;
                $savedDates[] = $attendanceDate;
            } else {
                $errors[] = "خطا در تاریخ {$attendanceDate}";
            }
        }

        return [
            'success' => true,
            'message' => "{$savedCount} روز با موفقیت ثبت شد",
            'errors' => $errors,
            'saved_count' => $savedCount,
            'saved_dates' => $savedDates,
        ];
    }

    // ============================================================
    // bulkEditRange: ویرایش رنج در users_logs
    // ============================================================

    public static function bulkEditRange()
    {
        $params = Yii::$app->request->post();

        $userId = $params['user_id'] ?? null;
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        $checkInTime = $params['check_in_time'] ?? null;
        $checkOutTime = $params['check_out_time'] ?? null;

        if (!$userId || !$dateFrom || !$dateTo) {
            return ['success' => false, 'message' => 'اطلاعات کامل نیست'];
        }

        $user = User::findOne($userId);
        if (!$user || $user->fingerprint_id === null) {
            return ['success' => false, 'message' => 'کاربر دستگاه اثر انگشت ندارد'];
        }

        $dateFromGregorian = Persian::convert_date_to_en($dateFrom);
        $dateToGregorian = Persian::convert_date_to_en($dateTo);

        $logs = UsersLog::find()
            ->where(['user_id' => $user->fingerprint_id])
            ->andWhere([
                'between',
                'checkindate',
                $dateFromGregorian . ' 00:00:00',
                $dateToGregorian . ' 23:59:59',
            ])
            ->all();

        if (empty($logs)) {
            return ['success' => false, 'message' => 'هیچ رکوردی در این بازه یافت نشد'];
        }

        $updatedCount = 0;
        $errors = [];

        foreach ($logs as $log) {
            $date = substr($log->checkindate, 0, 10);

            if (!empty($checkInTime)) {
                $log->checkindate = self::combineDateTime($date, $checkInTime);
            }
            if (!empty($checkOutTime)) {
                $log->checkout_date = self::combineDateTime($date, $checkOutTime);
            }

            // reset calculated
            $log->calculated = 0;
            $log->calculated_at = null;

            if ($log->save(false)) {
                $updatedCount++;
            } else {
                $errors[] = "خطا در تاریخ {$date}";
            }
        }

        return [
            'success' => true,
            'message' => "{$updatedCount} رکورد با موفقیت ویرایش شد",
            'updated_count' => $updatedCount,
            'errors' => $errors,
        ];
    }

    // ============================================================
    // bulkEditMonthly: ویرایش ماهانه در users_logs
    // ============================================================

    public static function bulkEditMonthly()
    {
        $params = Yii::$app->request->post();

        $userId = $params['user_id'] ?? null;
        $month = $params['month'] ?? null;
        $year = $params['year'] ?? null;
        $checkInTime = $params['check_in_time'] ?? null;
        $checkOutTime = $params['check_out_time'] ?? null;

        if (!$userId || !$month || !$year) {
            return ['success' => false, 'message' => 'اطلاعات کامل نیست'];
        }

        $user = User::findOne($userId);
        if (!$user || $user->fingerprint_id === null) {
            return ['success' => false, 'message' => 'کاربر دستگاه اثر انگشت ندارد'];
        }

        // محاسبه بازه ماه شمسی
        $month = (int) $month;
        $year = (int) $year;
        $dateFromFa = sprintf('%04d/%02d/01', $year, $month);
        $dateToFa = sprintf('%04d/%02d/31', $year, $month);

        $dateFrom = Persian::convert_date_to_en($dateFromFa);
        $dateTo = Persian::convert_date_to_en($dateToFa);

        $logs = UsersLog::find()
            ->where(['user_id' => $user->fingerprint_id])
            ->andWhere([
                'between',
                'checkindate',
                $dateFrom . ' 00:00:00',
                $dateTo . ' 23:59:59',
            ])
            ->all();

        if (empty($logs)) {
            return ['success' => false, 'message' => 'هیچ رکوردی در این ماه یافت نشد'];
        }

        $updatedCount = 0;
        $errors = [];

        foreach ($logs as $log) {
            $date = substr($log->checkindate, 0, 10);

            if (!empty($checkInTime)) {
                $log->checkindate = self::combineDateTime($date, $checkInTime);
            }
            if (!empty($checkOutTime)) {
                $log->checkout_date = self::combineDateTime($date, $checkOutTime);
            }

            // reset calculated
            $log->calculated = 0;
            $log->calculated_at = null;

            if ($log->save(false)) {
                $updatedCount++;
            } else {
                $errors[] = "خطا در تاریخ {$date}";
            }
        }

        return [
            'success' => true,
            'message' => "{$updatedCount} رکورد با موفقیت ویرایش شد",
            'updated_count' => $updatedCount,
            'errors' => $errors,
        ];
    }

    // ============================================================
    //     محاسبات حقوق (UsersLog به عنوان واسط)
    // ============================================================

    /**
     * قرارداد فعال بر اساس یک UsersLog
     * مسیر: users_logs.user_id (fingerprint_id) → user.id → hrm_contracts.user_id
     *
     * @param UsersLog $log
     * @return HrmContract|null
     */
    public static function getActiveContractByLog(UsersLog $log)
    {
        return HrmContract::find()
            ->alias('c')
            ->innerJoin(['u' => User::tableName()], 'u.id = c.user_id')
            ->where(['u.fingerprint_id' => $log->user_id])
            ->andWhere(['c.contract_status' => HrmContract::STATUS_ACTIVE])
            ->orderBy(['c.id' => SORT_DESC])
            ->one();
    }

    /**
     * محاسبه یک رکورد UsersLog
     *
     * @param UsersLog $log
     * @param array $options [
     *   'start_time' => 'HH:MM',
     *   'end_time'   => 'HH:MM',
     *   'work_hours' => 8,
     * ]
     * @return bool
     */
    public static function calculateLog(UsersLog $log, array $options = []): bool
    {
        $startTime = $options['start_time'] ?? self::DEFAULT_START_TIME;
        $endTime = $options['end_time'] ?? self::DEFAULT_END_TIME;
        $workHours = (float) ($options['work_hours'] ?? self::DEFAULT_WORK_HOURS);

        $checkIn = self::extractTime($log->checkindate);
        $checkOut = self::extractTime($log->checkout_date);

        // حالت حضور کامل (checkindate = 00:00:00)
        $isFullDayMarker = ($checkIn === '00:00:00' || $checkIn === '00:00');
        if ($isFullDayMarker) {
            $log->delay_minutes = 0;
            $log->early_leave_minutes = 0;
            if (empty($log->hour_normal) || (float) $log->hour_normal <= 0) {
                $log->hour_normal = $workHours;
            }
            if ($log->hour_extra === null) {
                $log->hour_extra = 0;
            }
            $log->calculated = 1;
            $log->calculated_at = date('Y-m-d H:i:s');
            return (bool) $log->save(false, [
                'delay_minutes',
                'early_leave_minutes',
                'hour_normal',
                'hour_extra',
                'calculated',
                'calculated_at',
            ]);
        }

        // کارکرد
        $workDuration = 0.0;
        if (!empty($checkIn) && !empty($checkOut)) {
            $workDuration = self::calcDuration($checkIn, $checkOut);
        }
        if (!empty($log->hour_normal) && (float) $log->hour_normal > 0) {
            $workDuration = (float) $log->hour_normal;
        }

        // تأخیر (حداکثر 8 ساعت)
        $delayMinutes = 0;
        if (!empty($checkIn) && $checkIn !== '00:00:00') {
            $delayMinutes = self::diffMinutes($startTime, $checkIn);
            if ($delayMinutes < 0)
                $delayMinutes = 0;
            $delayMinutes = min($delayMinutes, (int) ($workHours * 60));
        }
        $log->delay_minutes = $delayMinutes;

        // تعجیل (حداکثر 8 ساعت)
        $earlyMinutes = 0;
        if (!empty($checkOut)) {
            $earlyMinutes = self::diffMinutes($checkOut, $endTime);
            if ($earlyMinutes < 0)
                $earlyMinutes = 0;
            $earlyMinutes = min($earlyMinutes, (int) ($workHours * 60));
        }
        $log->early_leave_minutes = $earlyMinutes;

        // hour_normal / hour_extra (اگه خالی یا صفر بودن)
        if (empty($log->hour_normal) || (float) $log->hour_normal <= 0) {
            $log->hour_normal = min($workDuration, $workHours);
        }
        if (empty($log->hour_extra) || (float) $log->hour_extra <= 0) {
            $log->hour_extra = max(0, $workDuration - $workHours);
        }

        $log->calculated = 1;
        $log->calculated_at = date('Y-m-d H:i:s');

        return (bool) $log->save(false, [
            'delay_minutes',
            'early_leave_minutes',
            'hour_normal',
            'hour_extra',
            'calculated',
            'calculated_at',
        ]);
    }
 /**
 * ⭐ پیش‌نمایش کامل فیش حقوقی یک پرسنل در یک ماه
 *
 * @param int $fingerprintId  fingerprint_id کاربر
 * @param int|null $year      سال شمسی (null → جاری)
 * @param int|null $month     ماه شمسی (null → جاری)
 * @return array
 */
public static function getPayrollPreview($fingerprintId, $year = null, $month = null): array
{
    // ===== ۱. خلاصه ماهانه =====
    $summary = self::getMonthlySummary($fingerprintId, $year, $month);
    if (isset($summary['error'])) {
        return ['success' => false, 'message' => $summary['error']];
    }

    // ===== ۲. کاربر =====
    $user = User::findOne(['fingerprint_id' => $fingerprintId]);
    if (!$user) {
        return ['success' => false, 'message' => 'کاربر یافت نشد'];
    }

    // ===== ۳. قرارداد (۶ مسیر) =====
    $contract = null;
    $findMethod = null;

    $contract = HrmContract::find()
        ->where(['user_id' => $user->id, 'contract_status' => HrmContract::STATUS_ACTIVE])
        ->orderBy(['id' => SORT_DESC])->one();
    if ($contract) $findMethod = 'user_id + ACTIVE';

    if (!$contract) {
        $contract = HrmContract::find()
            ->where(['user_id' => $user->id])
            ->orderBy(['id' => SORT_DESC])->one();
        if ($contract) $findMethod = 'user_id (any status)';
    }

    if (!$contract && !empty($user->personnel_code)) {
        $contract = HrmContract::find()
            ->where(['personnel_code' => $user->personnel_code, 'contract_status' => HrmContract::STATUS_ACTIVE])
            ->orderBy(['id' => SORT_DESC])->one();
        if ($contract) $findMethod = 'personnel_code + ACTIVE';
    }

    if (!$contract && !empty($user->personnel_code)) {
        $contract = HrmContract::find()
            ->where(['personnel_code' => $user->personnel_code])
            ->orderBy(['id' => SORT_DESC])->one();
        if ($contract) $findMethod = 'personnel_code (any status)';
    }

    if (!$contract && !empty($user->national_id)) {
        $contract = HrmContract::find()
            ->where(['national_code' => $user->national_id, 'contract_status' => HrmContract::STATUS_ACTIVE])
            ->orderBy(['id' => SORT_DESC])->one();
        if ($contract) $findMethod = 'national_code + ACTIVE';
    }

    if (!$contract && !empty($user->national_id)) {
        $contract = HrmContract::find()
            ->where(['national_code' => $user->national_id])
            ->orderBy(['id' => SORT_DESC])->one();
        if ($contract) $findMethod = 'national_code (any status)';
    }

    if (!$contract) {
        return [
            'success' => false,
            'message' => 'قرارداد فعالی برای این پرسنل یافت نشد',
            'debug' => [
                'user_id' => $user->id,
                'fingerprint_id' => $user->fingerprint_id,
                'personnel_code' => $user->personnel_code,
                'national_id' => $user->national_id,
            ],
            'summary' => $summary,
        ];
    }

    // ===== ۴. نوع قرارداد =====
    $contractType = 'normal';
    if ((int) $contract->contract_type === HrmContract::TYPE_HOURLY) {
        $contractType = 'hourly';
    } elseif ((int) $contract->contract_type === HrmContract::TYPE_PROJECT) {
        $contractType = 'project';
    }

    $monthDays = Persian::get_last_day_of_month((int) $summary['month']);

    // ===== ۵. وام =====
    $loan = HrmLoan::find()
        ->where(['user_id' => $user->id, 'status' => 0])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    $loanInstallment = 0;
    $loanData = [
        'has_loan' => false,
        'total_amount' => 0,
        'installment_amount' => 0,
        'installment_count' => 0,
        'paid_installments' => 0,
        'remaining_installments' => 0,
        'remaining_amount' => 0,
    ];

    if ($loan) {
        $installmentAmount = (float) ($loan->installment_amount ?? 0);
        $paidCount = (int) ($loan->paid_installments ?? 0);
        $totalCount = (int) ($loan->installment_count ?? 0);
        $totalAmount = (float) ($loan->total_amount ?? 0);

        $loanInstallment = $installmentAmount;
        $loanData = [
            'has_loan' => true,
            'total_amount' => $totalAmount,
            'installment_amount' => $installmentAmount,
            'installment_count' => $totalCount,
            'paid_installments' => $paidCount,
            'remaining_installments' => max(0, $totalCount - $paidCount),
            'remaining_amount' => max(0, $totalAmount - ($paidCount * $installmentAmount)),
        ];
    }

    // ===== ۶. مساعده (ماه جاری) =====
    $pettyCash = (float) HrmAdvance::find()
        ->where([
            'user_id' => $user->id,
            'status' => 0,
            'year' => $summary['year'],
            'month' => $summary['month'],
        ])
        ->sum('amount');

    $advanceList = HrmAdvance::find()
        ->where([
            'user_id' => $user->id,
            'status' => 0,
            'year' => $summary['year'],
            'month' => $summary['month'],
        ])
        ->asArray()
        ->all();

    // ===== ۷. تعدیلات (پاداش/جریمه/معوقه/خرید/کسور متفرقه/ایاب و ذهاب) =====
    $adjustments = HrmAdjustment::find()
        ->where([
            'user_id' => $user->id,
            'status' => 0,
            'year' => $summary['year'],
            'month' => $summary['month'],
        ])
        ->all();

    $bonus = 0;
    $penalty = 0;
    $arrears = 0;
    $companyPurchase = 0;
    $miscDeductions = 0;
    $adjustmentTransport = 0;
    $adjustmentsList = [];

    foreach ($adjustments as $adj) {
        $type = (int) $adj->type;
        $amount = (float) $adj->amount;

        switch ($type) {
            case 1: $bonus += $amount; break;            // پاداش
            case 2: $penalty += $amount; break;          // جریمه
            case 3: $arrears += $amount; break;          // معوقه
            case 4: $companyPurchase += $amount; break;  // خرید از شرکت
            case 5: $miscDeductions += $amount; break;   // کسور متفرقه
            case 6: $adjustmentTransport += $amount; break; // ایاب و ذهاب
        }

        $adjustmentsList[] = [
            'id' => $adj->id,
            'type' => $type,
            'type_label' => self::getAdjustmentTypeLabel($type),
            'amount' => $amount,
            'description' => $adj->description,
        ];
    }

    // ===== ۸. بازخرید مرخصی =====
    // ===== ۸. بازخرید مرخصی (با try-catch) =====
$vacationBuybackDays = 0;
try {
    $vacationBuyback = HrmVacationBuyback::find()
        ->where(['user_id' => $user->id])
        ->andWhere(['between', 'created_at', "$from 00:00:00", "$to 23:59:59"])
        ->one();

    if (!$vacationBuyback) {
        // fallback: آخرین بازخرید کاربر
        $vacationBuyback = HrmVacationBuyback::find()
            ->where(['user_id' => $user->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    if ($vacationBuyback) {
        $vacationBuybackDays = (float) ($vacationBuyback->days_count ?? 0);
    }
} catch (\Throwable $e) {
    Yii::error('VacationBuyback error: ' . $e->getMessage(), 'hrm-payroll');
    $vacationBuybackDays = 0;
}

    // ===== ۱۰. مرخصی بدون حقوق =====
    $unpaidLeaveDays = self::calculateUnpaidLeaveDays($user->id, $summary['year'], $summary['month']);

    // ===== ۱۱. اموال در اختیار =====
    $assetsResult = HrmAssetAssignment::getPersonnelAssets($user->id);
    $assetsList = $assetsResult['data'] ?? [];
    $assetsTotalValue = 0;
    foreach ($assetsList as $asset) {
        $assetsTotalValue += (float) ($asset['total_price'] ?? 0);
    }

    // ===== ۱۲. ساخت params برای HrmSalaryCalculator =====
    $params = [
        'contract_type' => $contractType,
        'month_days' => $monthDays,

        // مزایای ثابت
        'base_salary' => (float) ($contract->base_salary ?? 0),
        'housing_allowance' => (float) ($contract->housing_allowance ?? 0),
        'grocery_allowance' => (float) ($contract->welfare_allowance ?? 0),
        'child_allowance' => (float) ($contract->child_allowance ?? 0),
        'monthly_seniority' => (float) ($contract->seniority_allowance ?? 0),
        'management_allowance' => (float) ($contract->responsibility_allowance ?? 0),
        'transport_allowance' => (float) ($contract->transportation_allowance ?? 0) + $adjustmentTransport,
        'performance_bonus' => (float) ($contract->performance_bonus ?? 0),

        // نرخ ساعتی
        'hourly_rate' => (float) ($contract->hourly_salary ?? 0),

        // کارکرد
        'work_hours' => (float) $summary['total_work_hours'],
        'overtime_hours' => (float) $summary['total_overtime_hours'],
        'special_overtime_hours' => 0,
        'mission_overtime_hours' => 0,
        'friday_hours' => (float) $summary['total_friday_hours'],
        'holiday_hours' => 0,
        'mission_hours' => (float) $summary['total_mission_hours'],
        'delay_hours' => round($summary['total_delay_minutes'] / 60, 2),
        'early_leave_hours' => round($summary['total_early_leave_minutes'] / 60, 2),
        'unauthorized_leave_hours' => 0,
        'absent_hours' => (float) ($summary['absent_days_count'] * 8),
        'unpaid_leave_days' => $unpaidLeaveDays,
        'student_leave_days' => 0,

        // آیتم‌های مستقیم
        'bonus' => $bonus,
        'commission' => 0,
        'receivable_costs' => 0,
        'arrears' => $arrears,
        'vacation_buyback_days' => $vacationBuybackDays,
        'previous_month_balance' => 0,

        // کسورات مستقیم
        'loan_installment' => $loanInstallment,
        'petty_cash' => $pettyCash,
        'company_purchase' => $companyPurchase,
        'misc_deductions' => $miscDeductions + $penalty,

        // تنظیمات
        'overtime_rate' => 1.4,
        'friday_rate' => 1.8,
        'delay_rate' => 1.4,
        'insurance_rate' => 0.07,
        'insurance_multiplier' => 1.1,
    ];

    // ===== ۱۳. محاسبه =====
    $result = HrmSalaryCalculator::calculate($params);

    if (!$result['success']) {
        return ['success' => false, 'message' => 'خطا در محاسبه', 'data' => $result];
    }

    // ===== ۱۴. خروجی =====
    return [
        'success' => true,
        'data' => [
            'user' => [
                'fingerprint_id' => $fingerprintId,
                'real_user_id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'personnel_code' => $user->personnel_code,
                'national_id' => $user->national_id,
                'mobile' => $user->phone_number,
            ],

            'contract' => [
                'id' => $contract->id,
                'found_by' => $findMethod,
                'contract_type' => $contractType,
                'contract_type_label' => $contract->getContractTypeLabel(),
                'work_start_time' => $contract->work_start_time,
                'work_end_time' => $contract->work_end_time,
                'base_salary' => (float) $contract->base_salary,
                'housing_allowance' => (float) $contract->housing_allowance,
                'child_allowance' => (float) $contract->child_allowance,
                'welfare_allowance' => (float) $contract->welfare_allowance,
                'seniority_allowance' => (float) $contract->seniority_allowance,
                'performance_bonus' => (float) $contract->performance_bonus,
                'responsibility_allowance' => (float) $contract->responsibility_allowance,
                'transportation_allowance' => (float) $contract->transportation_allowance,
                'total_salary' => (float) $contract->total_salary,
                'contract_from_date' => $contract->contract_from_date,
                'contract_to_date' => $contract->contract_to_date,
            ],

            'summary' => $summary,

            'payroll' => $result['data'],

            // ===== بخش‌های جدید =====

            'loan' => $loanData,

            'petty_cash' => [
                'total' => $pettyCash,
                'list' => $advanceList,
            ],

            'adjustments' => [
                'bonus' => $bonus,
                'penalty' => $penalty,
                'arrears' => $arrears,
                'company_purchase' => $companyPurchase,
                'misc_deductions' => $miscDeductions,
                'transport' => $adjustmentTransport,
                'list' => $adjustmentsList,
            ],

            'vacation_buyback' => [
                'days' => $vacationBuybackDays,
                'amount' => $result['data']['earnings']['vacation_buyback'] ?? 0,
            ],

            'supplementary_insurance' => $supplementaryInsurance,

            'unpaid_leave_days' => $unpaidLeaveDays,

            'assets' => [
                'count' => count($assetsList),
                'list' => $assetsList,
                'total_value' => $assetsTotalValue,
            ],
        ],
    ];
}
    /**
     * محاسبه گروهی روی UsersLog در بازه
     *
     * @param string $from    تاریخ شروع (Y-m-d)
     * @param string $to      تاریخ پایان (Y-m-d)
     * @param int|null $userId fingerprint_id (اختیاری)
     * @return array ['total' => int, 'success' => int, 'failed' => int]
     */
    public static function calculateRange($from, $to, $userId = null): array
    {
        $query = UsersLog::find()
            ->where(['calculated' => 0])
            ->andWhere(['between', 'checkindate', "$from 00:00:00", "$to 23:59:59"]);

        if ($userId) {
            $query->andWhere(['user_id' => $userId]);
        }

        $logs = $query->all();

        $success = 0;
        $failed = 0;

        // ===== کش قرارداد بر اساس fingerprint_id =====
        $contractCache = [];

        foreach ($logs as $log) {
            $fingerprintId = $log->user_id;

            if (!array_key_exists($fingerprintId, $contractCache)) {
                $contractCache[$fingerprintId] = self::getActiveContractByLog($log);
            }
            $contract = $contractCache[$fingerprintId];

            // ===== ساخت options =====
            $options = [
                'start_time' => self::DEFAULT_START_TIME,
                'end_time' => self::DEFAULT_END_TIME,
                'work_hours' => self::DEFAULT_WORK_HOURS,
            ];

            if ($contract) {
                if (!empty($contract->work_start_time)) {
                    $options['start_time'] = substr($contract->work_start_time, 0, 5);
                }
                if (!empty($contract->work_end_time)) {
                    $options['end_time'] = substr($contract->work_end_time, 0, 5);
                }
            }

            try {
                if (self::calculateLog($log, $options)) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Yii::error('Calculate failed (UsersLog id=' . $log->id . '): ' . $e->getMessage(), 'hrm-attendance');
                $failed++;
            }
        }

        return [
            'total' => count($logs),
            'success' => $success,
            'failed' => $failed,
        ];
    }

    // ============================================================
    // helpers
    // ============================================================

    private static function extractTime(?string $datetime): ?string
    {
        if (empty($datetime))
            return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}:\d{2})/', $datetime, $m)) {
            return $m[1] . ':00';
        }
        return null;
    }

    private static function combineDateTime(string $date, string $time): string
    {
        if (strlen($time) === 5)
            $time .= ':00';
        return $date . ' ' . $time;
    }

    private static function calcDuration(string $checkIn, string $checkOut): float
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);
        if ($in === false || $out === false)
            return 0;
        if ($out < $in)
            $out += 86400;
        $diff = ($out - $in) / 3600;
        if ($diff < 0 || $diff > 24)
            return 0;
        return round($diff, 2);
    }

    private static function formatDuration($hours): string
    {
        if (empty($hours) || $hours <= 0)
            return "00:00";
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        if ($m >= 60) {
            $h++;
            $m = 0;
        }
        return sprintf("%02d:%02d", $h, $m);
    }

    /**
     * اختلاف دو ساعت به دقیقه
     * diffMinutes('08:00', '08:15') → 15
     * diffMinutes('08:00', '07:50') → -10
     */
    private static function diffMinutes(string $fromTime, string $toTime): int
    {
        $from = strtotime($fromTime);
        $to = strtotime($toTime);
        if ($from === false || $to === false)
            return 0;
        return (int) round(($to - $from) / 60);
    }



    /**
     * محاسبه گروهی (قابل صدا زدن از کنترلر یا کرانجاب)
     *
     * @return array
     */
    public static function calculate()
    {
        $params = Yii::$app->request->post();

        // اگه از کرانجاب صدا زده شد، ممکنه GET باشه
        if (empty($params)) {
            $params = Yii::$app->request->get();
        }

        $from = $params['date_from'] ?? null;
        $to = $params['date_to'] ?? null;
        $userId = $params['user_id'] ?? null; // fingerprint_id
        if (empty($from) || empty($to)) {
            $todayFa = Persian::convert_date_to_fa(date('Y-m-d')); // مثلاً "1403/09/15"
            [$jy, $jm, $jd] = explode('/', $todayFa);
            $jm = (int) $jm;

            $lastDay = Persian::get_last_day_of_month($jm); // 31 یا 30

            $fromFa = sprintf('%04d/%02d/01', $jy, $jm);
            $toFa = sprintf('%04d/%02d/%02d', $jy, $jm, $lastDay);

            $from = Persian::convert_date_to_en($fromFa);
            $to = Persian::convert_date_to_en($toFa);
        }
        if (!$from || !$to) {
            return [
                'success' => false,
                'message' => 'بازه تاریخ (date_from / date_to) الزامی است',
            ];
        }

        $result = self::calculateRange($from, $to, $userId);

        return [
            'success' => true,
            'message' => "محاسبه انجام شد: {$result['success']} موفق، {$result['failed']} ناموفق",
            'data' => $result,
        ];
    }
/**
 * محاسبه روزهای مرخصی بدون حقوق در ماه
 *
 * @param int $userId  user.id (نه fingerprint_id)
 * @param int $year    سال شمسی
 * @param int $month   ماه شمسی
 * @return float
 */
private static function calculateUnpaidLeaveDays($userId, $year, $month): float
{
    $lastDay = Persian::get_last_day_of_month((int) $month);
    $fromFa = sprintf('%04d/%02d/01', $year, $month);
    $toFa = sprintf('%04d/%02d/%02d', $year, $month, $lastDay);

    $from = Persian::convert_date_to_en($fromFa);
    $to = Persian::convert_date_to_en($toFa);

    $vacations = HrmVacation::find()
        ->alias('v')
        ->innerJoin(['t' => HrmVacationType::tableName()], 't.id = v.vacation_type_id')
        ->where(['v.user_id' => $userId, 'v.status' => HrmVacation::STATUS_APPROVED])
        ->andWhere(['t.is_paid' => 0])
        ->andWhere(['<=', 'v.date_from', $to])
        ->andWhere(['>=', 'v.date_to', $from])
        ->all();

    $totalDays = 0;
    foreach ($vacations as $vacation) {
        $vFrom = max(strtotime($vacation->date_from), strtotime($from));
        $vTo = min(strtotime($vacation->date_to), strtotime($to));
        if ($vTo >= $vFrom) {
            $days = (int) floor(($vTo - $vFrom) / 86400) + 1;
            $totalDays += $days;
        }
    }

    return (float) $totalDays;
}
    /**
 * برچسب نوع تعدیل
 */
private static function getAdjustmentTypeLabel(int $type): string
{
    $types = [
        1 => 'پاداش',
        2 => 'جریمه',
        3 => 'معوقه',
        4 => 'خرید از شرکت',
        5 => 'کسور متفرقه',
        6 => 'کمک ایاب و ذهاب',
    ];
    return $types[$type] ?? 'نامشخص';
}
}