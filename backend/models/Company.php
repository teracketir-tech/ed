<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Company extends ActiveRecord
{

    public static function tableName()
    {
        return 'company';
    }

    public function rules()
    {
        return [
            [['id'], 'integer'],
            [['name'], 'safe'],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = Company::find();

        unset($params['per-page'], $params['page'], $params['company_id']);

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

        $companies = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $companies,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new Company();

        } else {

            User::checkAccess(503);

            $model = Company::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'شرکت با این شناسه یافت نشد',
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

        $model = Company::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'شرکت با این شناسه یافت نشد',
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

        $seal = Company::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('شرکت با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true,];
    }

}
