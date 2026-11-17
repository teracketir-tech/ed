<?php

namespace frontend\modules\hq\models;

use Yii;
// در UsersLog

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Attendance rows behind the daily-report "timesheet" tab.
 *
 * This table is NOT owned by the hq module: a fingerprint device writes one
 * row per person per working day. The hq module only stamps
 * `daily_report_id` / `daily_report_activity_id` / `company_id` on the rows a
 * supervisor picked, which is why all three are nullable — an unassigned log
 * row is the normal state.
 *
 * `user_id` holds `contact.id` (the same contract as every "person" field of
 * a daily report), never `user.id`.
 */
class UsersLog extends DailyReportLine
{
    public static function tableName()
    {
        return 'users_logs';
    }

    public function rules()
    {
        return array_merge($this->activityRules(), [
            [['user_id'], 'required'],
            [['user_id', 'daily_report_id', 'branch_id', 'delay_minutes', 'extra_minutes', 'custom_added'], 'integer'],
            [['hour_normal', 'hour_extra'], 'number', 'min' => 0],
            [['checkindate', 'checkout_date'], 'validateDatetime'],
            [['user_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'شخص انتخاب‌شده یافت نشد'],
            [['daily_report_id'], 'validateReport'],
            [['custom_added'], 'safe'],
            [['early_leave_minutes', 'calculated', 'calculated_by'], 'integer'],
            [['calculated_at', 'early_leave_minutes', 'calculated', 'calculated_by','created_at','updated_at'], 'safe'],
        ]);
    }

    /**
     * The device writes `Y-m-d H:i:s`; the manual form sends `Y-m-d H:i`.
     * Both are accepted so a typed-in row is not rejected for its seconds.
     */
    public function validateDatetime($attribute)
    {
        $value = $this->$attribute;

        if ($value === null || $value === '') {
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', (string) $value)) {
            $this->addError($attribute, 'تاریخ و ساعت باید به صورت YYYY-MM-DD HH:MM باشد');
        }
    }

public function behaviors()
{
    return [
        [
            'class' => TimestampBehavior::class,
            'createdAtAttribute' => 'created_at',
            'updatedAtAttribute' => 'updated_at',
            'value' => new Expression('NOW()'),
        ],
    ];
}
    /** Date part of the row, used to bucket logs by day */
    public function getLogDate()
    {
        if (!$this->checkindate) {
            return null;
        }

        return substr((string) $this->checkindate, 0, 10);
    }

   public function getUser()
{
    return $this->hasOne(User::class, ['fingerprint_id' => 'user_id']);
} 
public function getContract()
{
    return $this->hasOne(HrmContract::class, ['personnel_code' => 'user_id']);
}

public function getActiveContract()
{
    return $this->hasOne(HrmContract::class, ['user_id' => 'user.id'])
        ->via('user')
        ->andWhere(['contract_status' => HrmContract::STATUS_ACTIVE])
        ->orderBy(['id' => SORT_DESC]);
}
    public function attributeLabels()
    {
        return [
            'user_id' => 'شخص',
            'checkindate' => 'ساعت ورود',
            'checkout_date' => 'ساعت خروج',
            'hour_normal' => 'ساعت عادی',
            'hour_extra' => 'ساعت اضافه',
            'delay_minutes' => 'تاخیر (دقیقه)',
            'extra_minutes' => 'اضافه (دقیقه)',
            'branch_id' => 'شعبه',
            'early_leave_minutes' => 'تعجیل (دقیقه)',
            'calculated' => 'وضعیت محاسبه',
            'calculated_at' => 'زمان محاسبه',
            'calculated_by' => 'محاسبه‌کننده',
        ];
    }

    /**
     * The global attendance list behind the sidebar route (permission 709).
     *
     * `user_id` points at contact.id, so each returned row carries a resolved
     * `person` name — fetched for the requested page only. Newest check-in
     * first; SQL-level pagination because the table grows every working day.
     */
    public static function all_logs(): array
    {
        $params = Yii::$app->request->getQueryParams();

        $perPage = max(1, (int) ($params['per-page'] ?? 10));
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = self::find()->with('user');

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => (int) $params['user_id']]);
        }

        // One comma-joined param from the range date picker — the accounting
        // items pattern (`from,to`), both ends inclusive.
        if (!empty($params['checkindate'])) {
            $range = explode(',', $params['checkindate']);

            if (count($range) == 2) {
                $start = date('Y-m-d', strtotime($range[0])) . ' 00:00:00';
                $end = date('Y-m-d', strtotime($range[1])) . ' 23:59:59';
                $query->andWhere(['between', 'checkindate', $start, $end]);
            }
        }

        $totalCount = (int) $query->count();

        $rows = $query
            ->orderBy(['checkindate' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        foreach ($rows as &$row) {

            if ($row['user'])
                $row['person'] = $row['user']['first_name'] . ' ' . $row['user']['last_name'];

        }

        unset($row);

        return [
            'success' => true,
            'pages' => max(1, (int) ceil($totalCount / $perPage)),
            'totalCount' => $totalCount,
            'data' => $rows,
        ];
    }
}
