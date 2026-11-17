<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class HrmVacation extends ActiveRecord
{
    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_CANCELLED = 3;

    public static function tableName()
    {
        return 'hrm_vacations';
    }

    public function rules()
    {
        return [
            [['user_id', 'vacation_type_id', 'date_from', 'date_from_persian', 'date_to', 'date_to_persian'], 'required'],
            [['user_id', 'vacation_type_id', 'days_count', 'work_days_count', 'status', 'approved_by'], 'integer'],
            [['date_from', 'date_to', 'approved_at', 'created_at', 'updated_at'], 'safe'],
            [['description'], 'string'],
            [['attachment'], 'string', 'max' => 255],
            ['date_to', 'compare', 'compareAttribute' => 'date_from', 'operator' => '>=', 'message' => 'تاریخ پایان باید از تاریخ شروع بزرگتر یا مساوی باشد'],
            [['time_from', 'time_to'], 'safe'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'شناسه',
            'user_id' => 'پرسنل',
            'vacation_type_id' => 'نوع مرخصی',
            'date_from' => 'تاریخ شروع',
            'date_from_persian' => 'تاریخ شروع (شمسی)',
            'date_to' => 'تاریخ پایان',
            'date_to_persian' => 'تاریخ پایان (شمسی)',
            'days_count' => 'تعداد روز',
            'work_days_count' => 'روزهای کاری',
            'status' => 'وضعیت',
            'description' => 'توضیحات',
            'attachment' => 'پیوست',
            'approved_by' => 'تایید کننده',
            'approved_at' => 'تاریخ تایید',
            'created_at' => 'تاریخ ثبت',
            'updated_at' => 'تاریخ ویرایش',
            'time_from' => 'ساعت شروع',
            'time_to' => 'ساعت پایان',
        ];
    }

    public function getStatusLabel()
    {
        $statuses = [
            self::STATUS_PENDING => 'در انتظار',
            self::STATUS_APPROVED => 'تایید شده',
            self::STATUS_REJECTED => 'رد شده',
            self::STATUS_CANCELLED => 'لغو شده',
        ];
        return $statuses[$this->status] ?? 'نامشخص';
    }

    public function getStatusColor()
    {
        $colors = [
            self::STATUS_PENDING => 'text-yellow-600',
            self::STATUS_APPROVED => 'text-green-600',
            self::STATUS_REJECTED => 'text-red-600',
            self::STATUS_CANCELLED => 'text-gray-600',
        ];
        return $colors[$this->status] ?? 'text-gray-400';
    }

    // ============== روابط ==============

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getVacationType()
    {
        return $this->hasOne(HrmVacationType::class, ['id' => 'vacation_type_id']);
    }

    public function getApprovedBy()
    {
        return $this->hasOne(User::class, ['id' => 'approved_by']);
    }

    // ============== متدهای کمکی ==============

    /**
     * تبدیل اعداد فارسی/عربی به انگلیسی
     */
    private static function toEnglishDigits($value)
    {
        if ($value === null || $value === '') return $value;
        return str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            (string) $value
        );
    }

    /**
     * جستجوی user_id بر اساس personnel_code
     * اول در User، اگر نبود در HrmContract
     */
    private static function findUserIdsByPersonnelCode($personnelCode)
    {
        $userIds = User::find()
            ->where(['like', 'personnel_code', $personnelCode])
            ->select('id')
            ->column();

        if (empty($userIds)) {
            $contractUserIds = HrmContract::find()
                ->where(['like', 'personnel_code', $personnelCode])
                ->andWhere(['>', 'user_id', 0])
                ->select('user_id')
                ->column();
            $userIds = array_unique($contractUserIds);
        }

        return array_values(array_filter($userIds));
    }

    /**
     * جستجوی user_id بر اساس national_code
     */
    private static function findUserIdsByNationalCode($nationalCode)
    {
        $userIds = HrmPersonnelBasic::find()
            ->where(['like', 'national_code', $nationalCode])
            ->select('user_id')
            ->column();

        if (empty($userIds)) {
            $contractUserIds = HrmContract::find()
                ->where(['like', 'national_code', $nationalCode])
                ->andWhere(['>', 'user_id', 0])
                ->select('user_id')
                ->column();
            $userIds = array_unique($contractUserIds);
        }

        return array_values(array_filter($userIds));
    }

    // ============== متدهای API ==============

    public static function get_all()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = self::find()
            ->with(['user', 'vacationType', 'approvedBy'])
            ->orderBy(['id' => SORT_DESC]);

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['vacation_type_id'])) {
            $query->andWhere(['vacation_type_id' => $params['vacation_type_id']]);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->andWhere(['status' => $params['status']]);
        }

        if (!empty($params['date_from'])) {
            $query->andWhere(['>=', 'date_from', $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->andWhere(['<=', 'date_to', $params['date_to']]);
        }

        // فیلتر بر اساس کد پرسنلی (با fallback به HrmContract)
        if (!empty($params['personnel_code'])) {
            $userIds = self::findUserIdsByPersonnelCode($params['personnel_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        // فیلتر بر اساس کد ملی (با fallback به HrmContract)
        if (!empty($params['national_code'])) {
            $userIds = self::findUserIdsByNationalCode($params['national_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        // فیلتر بر اساس گروه کاری
        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        $totalCount = $query->count();
        $models = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        foreach ($models as &$model) {
            $model['user'] = !empty($model['user_id']) && $model['user_id'] > 0
                ? User::find()->where(['id' => $model['user_id']])->asArray()->one()
                : null;
            $model['vacationType'] = HrmVacationType::find()->where(['id' => $model['vacation_type_id']])->asArray()->one();
            if (!empty($model['approved_by'])) {
                $model['approvedBy'] = User::find()->where(['id' => $model['approved_by']])->asArray()->one();
            }
        }

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $models,
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        // ===== نرمال‌سازی اعداد فارسی → انگلیسی در تاریخ‌ها =====
        foreach (['date_from', 'date_to'] as $f) {
            if (!empty($params[$f])) {
                $params[$f] = self::toEnglishDigits($params[$f]);
            }
        }

        if (!$id) {
            User::checkAccess(701);
            $model = new self();
            $model->status = self::STATUS_PENDING;
        } else {
            User::checkAccess(703);
            $model = self::findOne($id);
            if (!$model) {
                return ['success' => false, 'message' => 'رکورد یافت نشد'];
            }
        }

        // ===== محاسبه تعداد روزها =====
        if (!empty($params['date_from']) && !empty($params['date_to'])) {
            try {
                $dateFrom = new \DateTime($params['date_from']);
                $dateTo = new \DateTime($params['date_to']);
                $dateTo->modify('+1 day');
                $interval = $dateFrom->diff($dateTo);
                $params['days_count'] = $interval->days;

                $workDays = 0;
                $current = clone $dateFrom;
                while ($current < $dateTo) {
                    $dayOfWeek = $current->format('N');
                    if ($dayOfWeek != 5) {
                        $workDays++;
                    }
                    $current->modify('+1 day');
                }
                $params['work_days_count'] = $workDays;
            } catch (\Exception $e) {
                return ['success' => false, 'message' => 'فرمت تاریخ نامعتبر است: ' . $e->getMessage()];
            }
        }

        // ===== تنظیم تاریخ شمسی =====
        if (!empty($params['date_from'])) {
            $params['date_from_persian'] = Persian::convert_date_to_fa($params['date_from']);
        }
        if (!empty($params['date_to'])) {
            $params['date_to_persian'] = Persian::convert_date_to_fa($params['date_to']);
        }

        // ===== اگر تایید شده، تاریخ تایید =====
        if (!empty($params['status']) && $params['status'] == self::STATUS_APPROVED && empty($model->approved_at)) {
            $params['approved_at'] = date('Y-m-d H:i:s');
            $params['approved_by'] = Yii::$app->user->id;
        }

        if ($model->load($params, '') && $model->save()) {
            return ['success' => true, 'data' => $model];
        }

        return ['success' => false, 'errors' => $model->errors];
    }

    public static function _delete($id)
    {
        User::checkAccess(704);

        $model = self::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        $model->delete();
        return ['success' => true];
    }

    // تایید مرخصی
    public static function approve($id)
    {
        User::checkAccess(703);

        $model = self::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        $model->status = self::STATUS_APPROVED;
        $model->approved_at = date('Y-m-d H:i:s');
        $model->approved_by = Yii::$app->user->id;
        $model->save(false);

        return ['success' => true, 'data' => $model];
    }

    // رد مرخصی
    public static function reject($id)
    {
        User::checkAccess(703);

        $model = self::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        $model->status = self::STATUS_REJECTED;
        $model->save(false);

        return ['success' => true, 'data' => $model];
    }

    /**
     * دریافت لیست مرخصی‌ها (برای تب لیست مرخصی - با ویرایش)
     */
    public static function getVacationsList()
    {
        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = self::find()
            ->with(['user', 'vacationType', 'approvedBy'])
            ->orderBy(['id' => SORT_DESC]);

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['vacation_type_id'])) {
            $query->andWhere(['vacation_type_id' => $params['vacation_type_id']]);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->andWhere(['status' => $params['status']]);
        }

        if (!empty($params['date_from'])) {
            $query->andWhere(['>=', 'date_from', $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->andWhere(['<=', 'date_to', $params['date_to']]);
        }

        // کد پرسنلی با fallback
        if (!empty($params['personnel_code'])) {
            $userIds = self::findUserIdsByPersonnelCode($params['personnel_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        // کد ملی با fallback
        if (!empty($params['national_code'])) {
            $userIds = self::findUserIdsByNationalCode($params['national_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        // گروه کاری
        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        $totalCount = $query->count();
        $models = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        foreach ($models as &$model) {
            $model['user'] = !empty($model['user_id']) && $model['user_id'] > 0
                ? User::find()->where(['id' => $model['user_id']])->asArray()->one()
                : null;
            $model['vacationType'] = HrmVacationType::find()->where(['id' => $model['vacation_type_id']])->asArray()->one();
            if (!empty($model['approved_by'])) {
                $model['approvedBy'] = User::find()->where(['id' => $model['approved_by']])->asArray()->one();
            }
            $model['vacation_hours'] = ($model['days_count'] ?? 0) * 8;
        }

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $models,
        ];
    }

    /**
     * دریافت گزارش مرخصی ماهانه (جمع ساعات هر ماه)
     */
    public static function getMonthlyReport()
    {
        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = self::find()
            ->with(['user', 'vacationType'])
            ->where(['status' => self::STATUS_APPROVED]);

        if (!empty($params['month'])) {
            $month = (int) $params['month'];
            $query->andWhere([
                'OR',
                ['like', 'date_from_persian', '/0' . $month . '/', false],
                ['like', 'date_to_persian', '/0' . $month . '/', false],
            ]);
        }

        if (!empty($params['year'])) {
            $year = (int) $params['year'];
            $query->andWhere([
                'OR',
                ['like', 'date_from_persian', $year . '/%', false],
                ['like', 'date_to_persian', $year . '/%', false],
            ]);
        }

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['personnel_code'])) {
            $userIds = self::findUserIdsByPersonnelCode($params['personnel_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        if (!empty($params['national_code'])) {
            $userIds = self::findUserIdsByNationalCode($params['national_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        if (!empty($params['vacation_type_id'])) {
            $query->andWhere(['vacation_type_id' => $params['vacation_type_id']]);
        }

        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        $models = $query->asArray()->all();

        $grouped = [];
        foreach ($models as $model) {
            $persianDateFrom = $model['date_from_persian'];
            $persianDateTo = $model['date_to_persian'];

            $year = (int) substr($persianDateFrom, 0, 4);
            $month = (int) substr($persianDateFrom, 5, 2);

            if (!empty($params['month']) && (int) $params['month'] !== $month) {
                $monthTo = (int) substr($persianDateTo, 5, 2);
                $yearTo = (int) substr($persianDateTo, 0, 4);
                if ((int) $params['month'] !== $monthTo || (int) $params['year'] !== $yearTo) {
                    continue;
                }
            }

            if (!empty($params['year']) && (int) $params['year'] !== $year) {
                $yearTo = (int) substr($persianDateTo, 0, 4);
                if ((int) $params['year'] !== $yearTo) {
                    continue;
                }
            }

            $key = $model['user_id'] . '_' . $year . '_' . $month . '_' . $model['vacation_type_id'];

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'user_id' => $model['user_id'],
                    'year' => $year,
                    'month' => $month,
                    'month_name' => self::getMonthName($month),
                    'vacation_type_id' => $model['vacation_type_id'],
                    'vacationType' => HrmVacationType::find()->where(['id' => $model['vacation_type_id']])->asArray()->one(),
                    'user' => User::find()->where(['id' => $model['user_id']])->asArray()->one(),
                    'total_hours' => 0,
                    'days_count' => 0,
                    'created_at' => $model['created_at'],
                ];
            }

            $daysCount = $model['days_count'] ?? 0;

            if ($daysCount == 0 && !empty($model['date_from']) && !empty($model['date_to'])) {
                $dateFrom = new \DateTime($model['date_from']);
                $dateTo = new \DateTime($model['date_to']);
                $dateTo->modify('+1 day');
                $interval = $dateFrom->diff($dateTo);
                $daysCount = $interval->days;
            }

            $grouped[$key]['total_hours'] += $daysCount * 8;
            $grouped[$key]['days_count'] += $daysCount;
        }

        $result = array_values($grouped);
        usort($result, function ($a, $b) {
            if ($a['year'] != $b['year']) {
                return $b['year'] - $a['year'];
            }
            return $b['month'] - $a['month'];
        });

        $totalCount = count($result);
        $result = array_slice($result, ($page - 1) * $perPage, $perPage);

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $result,
        ];
    }

    /**
     * دریافت گزارش مرخصی روزانه (هر روز یک ردیف)
     */
    public static function getDailyReport()
    {
        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = self::find()
            ->with(['user', 'vacationType'])
            ->where(['status' => self::STATUS_APPROVED])
            ->orderBy(['date_from' => SORT_DESC]);

        if (!empty($params['date_from'])) {
            $query->andWhere(['>=', 'date_from', $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->andWhere(['<=', 'date_to', $params['date_to']]);
        }

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        if (!empty($params['personnel_code'])) {
            $userIds = self::findUserIdsByPersonnelCode($params['personnel_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        if (!empty($params['national_code'])) {
            $userIds = self::findUserIdsByNationalCode($params['national_code']);
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        if (!empty($params['vacation_type_id'])) {
            $query->andWhere(['vacation_type_id' => $params['vacation_type_id']]);
        }

        if (!empty($params['workgroup_id'])) {
            $userIds = HrmWorkgroupPersonel::find()
                ->where(['workgroup_id' => $params['workgroup_id'], 'status' => 1])
                ->select('user_id')
                ->column();
            if (!empty($userIds)) {
                $query->andWhere(['user_id' => $userIds]);
            } else {
                $query->andWhere('1=0');
            }
        }

        $models = $query->asArray()->all();

        $result = [];

        foreach ($models as $model) {
            $user = User::find()->where(['id' => $model['user_id']])->asArray()->one();
            $vacationType = HrmVacationType::find()->where(['id' => $model['vacation_type_id']])->asArray()->one();

            $dateFrom = new \DateTime($model['date_from']);
            $dateTo = new \DateTime($model['date_to']);
            $dateTo->modify('+1 day');
            $interval = new \DateInterval('P1D');
            $dateRange = new \DatePeriod($dateFrom, $interval, $dateTo);

            foreach ($dateRange as $date) {
                $result[] = [
                    'id' => $model['id'],
                    'date' => $date->format('Y-m-d'),
                    'date_persian' => Persian::convert_date_to_fa($date->format('Y-m-d')),
                    'user_id' => $model['user_id'],
                    'user' => $user,
                    'vacation_type_id' => $model['vacation_type_id'],
                    'vacationType' => $vacationType,
                    'vacation_hours' => 8,
                    'days_count' => 1,
                    'status' => $model['status'],
                    'description' => $model['description'],
                    'created_at' => $model['created_at'],
                    'approved_at' => $model['approved_at'],
                ];
            }
        }

        usort($result, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        $totalCount = count($result);
        $result = array_slice($result, ($page - 1) * $perPage, $perPage);

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $result,
        ];
    }

    private static function getMonthName($month)
    {
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
            4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
            7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
            10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];
        return $months[$month] ?? 'نامشخص';
    }
}