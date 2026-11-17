<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class HrmPersonnelContact extends ActiveRecord
{
    const HOUSING_OWNER = 1;
    const HOUSING_TENANT = 2;
    const HOUSING_OTHER = 3;

    public static function tableName()
    {
        return 'hrm_personnel_contact';
    }

    public function rules()
    {
        return [
            [['user_id'], 'required'],
            [['user_id', 'state_id', 'city_id', 'housing_status'], 'integer'],
            [['address'], 'string'],
            [['postal_code', 'phone_prefix', 'phone_number', 'mobile', 'emergency_phone'], 'string', 'max' => 15],
            [['email'], 'string', 'max' => 255],
            ['email', 'email', 'message' => 'فرمت ایمیل صحیح نیست'],
            ['postal_code', 'string', 'min' => 10, 'max' => 10, 'message' => 'کد پستی باید ۱۰ رقم باشد'],
            ['phone_prefix', 'match', 'pattern' => '/^0[0-9]{2,3}$/', 'message' => 'پیش شماره باید با ۰ شروع و ۳ یا ۴ رقم باشد'],
            ['mobile', 'match', 'pattern' => '/^09[0-9]{9}$/', 'message' => 'شماره موبایل باید با ۰۹ شروع و ۱۱ رقم باشد'],
            ['user_id', 'unique', 'message' => 'این کاربر قبلاً ثبت شده است'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'شناسه',
            'user_id' => 'کاربر',
            'state_id' => 'استان',
            'city_id' => 'شهر',
            'address' => 'آدرس محل زندگی',
            'postal_code' => 'کد پستی',
            'housing_status' => 'وضعیت مسکن',
            'phone_prefix' => 'پیش شماره',
            'phone_number' => 'تلفن ثابت',
            'mobile' => 'موبایل',
            'emergency_phone' => 'تلفن ضروری',
            'email' => 'ایمیل',
            'created_at' => 'تاریخ ثبت',
            'updated_at' => 'تاریخ ویرایش',
        ];
    }

    public function getHousingStatusLabel()
    {
        $statuses = [
            self::HOUSING_OWNER => 'مالک',
            self::HOUSING_TENANT => 'مستاجر',
            self::HOUSING_OTHER => 'سایر',
        ];
        return $statuses[$this->housing_status] ?? 'نامشخص';
    }

    // ============== روابط ==============

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getState()
    {
        return $this->hasOne(State::class, ['id' => 'state_id']);
    }

    public function getCity()
    {
        return $this->hasOne(City::class, ['id' => 'city_id']);
    }

    // ============== متدهای API ==============

    public static function get_all()
    {
        User::checkAccess(702);

        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = self::find()
            ->with(['user', 'state', 'city'])
            ->orderBy(['id' => SORT_DESC]);

        if (!empty($params['user_id'])) {
            $query->andWhere(['user_id' => $params['user_id']]);
        }

        $totalCount = $query->count();
        $models = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        foreach ($models as &$model) {
            $model['user'] = User::find()->where(['id' => $model['user_id']])->asArray()->one();
            $model['state'] = State::find()->where(['id' => $model['state_id']])->asArray()->one();
            $model['city'] = City::find()->where(['id' => $model['city_id']])->asArray()->one();
        }

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $models,
        ];
    }

    public static function _view($id)
    {
        User::checkAccess(702);

        $model = self::find()
            ->with(['user', 'state', 'city'])
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$model) {
            return ['success' => false, 'message' => 'رکورد یافت نشد'];
        }

        $model['user'] = User::find()->where(['id' => $model['user_id']])->asArray()->one();
        $model['state'] = State::find()->where(['id' => $model['state_id']])->asArray()->one();
        $model['city'] = City::find()->where(['id' => $model['city_id']])->asArray()->one();

        return ['success' => true, 'data' => $model];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        // اگر id ارسال نشده، بر اساس user_id چک کن
        if (!$id && isset($params['user_id'])) {
            $existing = self::find()->where(['user_id' => $params['user_id']])->one();
            if ($existing) {
                $id = $existing->id;
            }
        }

        if (!$id) {
            User::checkAccess(701);
            $model = new self();
        } else {
            User::checkAccess(703);
            $model = self::findOne($id);
            if (!$model) {
                return ['success' => false, 'message' => 'رکورد یافت نشد'];
            }
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
/**
 * دریافت اطلاعات تماس و سکونت یک کاربر خاص
 *
 * اولویت:
 *   1) hrm_personnel_contact (اگر رکوردی برای این کاربر باشد)
 *   2) hrm_contracts (آخرین قرارداد این کاربر)
 *
 * @param int $userId
 * @return array
 */
public static function getByUser($userId)
{
    $userId = (int) $userId;

    if ($userId <= 0) {
        return ['success' => false, 'message' => 'شناسه کاربر نامعتبر است'];
    }

    // ===== 1) جستجو در جدول جاری =====
    $model = self::find()
        ->where(['user_id' => $userId])
        ->asArray()
        ->one();

    if ($model) {
        return ['success' => true, 'data' => $model, 'source' => 'personnel_contact'];
    }

    // ===== 2) Fallback: آخرین قرارداد این کاربر =====
    // اول قرارداد فعال، بعد هر قراردادی
    $contract = HrmContract::find()
        ->where(['user_id' => $userId, 'contract_status' => HrmContract::STATUS_ACTIVE])
        ->orderBy(['id' => SORT_DESC])
        ->asArray()
        ->one();

    if (!$contract) {
        $contract = HrmContract::find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => SORT_DESC])
            ->asArray()
            ->one();
    }

    if (!$contract) {
        return ['success' => false, 'message' => 'اطلاعاتی برای این کاربر یافت نشد'];
    }

    // ===== مپ کردن فیلدهای قرارداد به فیلدهای ContactTab =====
    $data = [
        // ----- استان و شهر -----
        'state_id' =>  $contract['state_id'] ?? null,
        'city_id' => $contract['city_id'] ?? null,

        // ----- آدرس -----
        'address' => $contract['address'] ?? null,

        // ----- کد پستی -----
        'postal_code' => $contract['postal_code'] ?? null,

        // ----- وضعیت مسکن (در قرارداد نیست) -----
        'housing_status' => null,

        // ----- تلفن ثابت -----
        'phone_prefix' => $contract['phone_prefix'] ?? null,
        'phone_number' => $contract['phone'] ?? null,   // ← نکته: در قرارداد phone است
        'phone' => $contract['phone'] ?? null,          // ← برای سازگاری با کدهای دیگر

        // ----- موبایل -----
        'mobile' => $contract['mobile'] ?? null,

        // ----- تلفن ضروری (در قرارداد نیست) -----
        'emergency_phone' => null,

        // ----- ایمیل (در قرارداد نیست) -----
        'email' => null,

        // ----- فیلدهای اضافی (برای سازگاری با کدهای دیگر) -----
        'user_id' => $userId,
    ];

    return ['success' => true, 'data' => $data, 'source' => 'contract'];
}
}