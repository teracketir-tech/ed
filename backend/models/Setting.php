<?php

namespace frontend\models;

use Yii;
use yii\db\ActiveRecord;

class Setting extends ActiveRecord
{

    public static function tableName()
    {
        return 'setting';
    }

    public function rules()
    {
        return [
            [['id', 'allow_negative_stock', 'tax', 'state_id', 'city_id'], 'integer'],
            [['company_name', 'address', 'economical_number', 'national_code', 'postal_code', 'phone'], 'safe'],
        ];
    }

    public static function _save()
    {
        $params = Yii::$app->request->post();

        $model = Setting::findOne(1);

        if ($model->load($params, '') && $model->save()) {

            return [
                'success' => true,
                'data' => $model,
            ];

        }

        return [
            'success' => false,
            'errors' => $model->errors,
        ];
    }

    public static function _view()
    {
        User::checkAccess(502);

        $model = Setting::find()->with(['state', 'city'])->where(['id' => 1])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'تنظیمات یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public function getState()
    {
        return $this->hasOne(State::class, ['id' => 'state_id']);
    }

    public function getCity()
    {
        return $this->hasOne(City::class, ['id' => 'city_id']);
    }

}
