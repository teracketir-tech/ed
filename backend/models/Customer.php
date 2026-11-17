<?php

namespace frontend\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Customer extends ActiveRecord
{

    public static function tableName()
    {
        return 'customer';
    }

    public function rules()
    {
        return [
            [['id', 'type', 'active', 'state_id', 'city_id'], 'integer'],
            [['first_name', 'last_name', 'alias', 'mobile', 'email', 'national_code', 'account_number', 'address', 'phone', 'phone_2'], 'safe'],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = Customer::find();

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if ($key == 'id') {

                    $query->andWhere(['id' => $params['id']]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        $banks = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $banks,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new Customer();

        } else {

            User::checkAccess(503);

            $model = Customer::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'شخص با این شناسه یافت نشد',
                ];

            }
        }

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

    public static function _view($id)
    {
        User::checkAccess(502);

        $model = Customer::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'شخص با این شناسه یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _delete($id)
    {
        User::checkAccess(504);

        $seal = Customer::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('شخص با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

}
