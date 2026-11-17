<?php

namespace frontend\modules\hq\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Group extends ActiveRecord
{

    public static function tableName()
    {
        return 'group';
    }

    public function rules()
    {
        return [
            [['id', 'company_id'], 'integer'],
            [['name', 'roles'], 'safe'],
        ];
    }

    public function behaviors()
    {
        return [
            'audit' => [
                'class' => AuditBehavior::class,
            ],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(1202);

        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = Group::find()->with('company');

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
            'query' => $query->orderBy(['id' => SORT_DESC])->asArray(),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        $materials = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $materials,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            // User::checkAccess(1201);

            $model = new Group();

            // A group born here belongs to this panel. The column is
            // deliberately absent from `rules()`, so `load()` below can never
            // take it from the request: an edit leaves it exactly as it was,
            // and a caller cannot hand itself a group the HQ login accepts.
            $model->created_in_hq = 1;

        } else {

            // User::checkAccess(1203);

            $model = Group::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'گروه با این شناسه یافت نشد',
                ];

            }
        }

        if ($model->load($params, '') && $model->save()) {

            if (!$params['company_id']) {

                $model->company_id = null;
                $model->save();

            }

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
        // User::checkAccess(1202);

        $model = Group::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'گروه با این شناسه یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _delete($id)
    {
        User::checkAccess(1204);

        $seal = Group::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('گروه با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true,];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

}
