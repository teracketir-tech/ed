<?php

namespace frontend\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class CompanyBank extends ActiveRecord
{

    public static function tableName()
    {
        return 'company_bank';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'bank_id'], 'integer'],
            [[
                'branch',
                'name',
                'account_number',
                'card_number',
                'shaba_number',
                'pos_number',
                'account_owner_name',
                'balance',
                'registered_mobile_number',
                'payment_switch_number',
                'payment_terminal_number',
                'merchant_number',
                'description',
            ], 'safe'],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->get();

        if (!$params)
            $params = Yii::$app->request->post();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = CompanyBank::find()->with(['company', 'bank']);

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if (in_array($key, ['id', 'company_id'])) {

                    $query->andWhere([$key => $value]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC])->asArray(),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        return [
            'totalCount' => $totalCount,
            'data' => $dataProvider->getModels(),
            'pages' => ceil($totalCount / $perPage),
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new CompanyBank();

        } else {

            User::checkAccess(503);

            $model = CompanyBank::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'بانک با این شناسه یافت نشد',
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

        $model = CompanyBank::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'بانک با این شناسه یافت نشد',
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

        $seal = CompanyBank::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('بانک با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getBank()
    {
        return $this->hasOne(Bank::class, ['id' => 'bank_id']);
    }

}
