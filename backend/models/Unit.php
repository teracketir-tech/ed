<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;
use yii\web\UnauthorizedHttpException;

class Unit extends ActiveRecord
{

    public static function tableName()
    {
        return 'unit';
    }

    public function rules()
    {
        return [
            [['id', 'company_id'], 'integer'],
            [['company_id'], 'required', 'message' => 'شرکت را انتخاب کنید'],
            [['name'], 'required', 'message' => 'نام واحد را بنویسید'],
            [['name'], 'string', 'max' => 250],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(912);

        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        // مثل Product: نامِ شرکت در همان پاسخ بیاید تا فهرست درخواستِ دومی نزند
        $query = Unit::find()->with('company');

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if ($key == 'id') {

                    $query->andWhere(['id' => $params['id']]);

                } elseif ($key == 'company_id') {

                    // مثل Product: فیلترِ شرکت دقیق است، نه `like`
                    $query->andWhere(['company_id' => $value]);

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

        $units = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $units,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(911);

            $model = new Unit();

        } else {

            User::checkAccess(913);

            $model = Unit::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'واحد با این شناسه یافت نشد',
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
        User::checkAccess(912);

        $model = Unit::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'واحد با این شناسه یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _delete($id)
    {
        User::checkAccess(914);

        $unit = Unit::findOne($id);

        if (!$unit) {
            throw new UnauthorizedHttpException('واحد با این شناسه یافت نشد');
        }

        $unit->delete();

        return ['success' => true,];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }
}
