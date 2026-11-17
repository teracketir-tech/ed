<?php

namespace frontend\modules\hq\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;
use yii\web\UnauthorizedHttpException;

class Contact extends ActiveRecord
{

    public static function tableName()
    {
        return 'contact';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'created_by', 'type', 'position_id', 'active', 'state', 'city'], 'integer'],
            [[
                'fname',
                'lname',
                'alias',
                'agent',
                'address',
                'phone1',
                'phone2',
                'business_location_address',
                'business_location_phone',
                'fax',
                'mobile',
                'email',
                'website',
                'economicCode',
                'nationalId',
                'postal_code',
                'position',
            ], 'safe'],
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
        $user = Yii::$app->user->identity;
        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        unset($params['per-page'], $params['page']);

        $query = Contact::find()->with(['creator', 'company']);

        if (!User::checkAccess(402, false)) {

            $query->where(['created_by' => $user->id]);

        }

        if (isset($params['type']) && $params['type'] == 0) {

            $query->andWhere(['not in', 'type', [4, 5]]);
            unset($params['type']);

        }

        if ($params['fname']) {

            $query->andWhere(['or', ['like', 'fname', $params['fname']], ['like', 'alias', $params['fname']]]);
            unset($params['fname']);

        }

        if ($params['lname']) {

            $query->andWhere(['or', ['like', 'lname', $params['lname']], ['like', 'alias', $params['lname']]]);
            unset($params['lname']);

        }

        if (isset($params['company_id']) && $params['company_id'] == '') {

            $params['company_id'] = null;

        }

        if (isset($params['q'])) {

            $query->andWhere(['or', ['like', 'alias', $params['q']], ['like', 'id', $params['q']]]);
            unset($params['q']);

        }

        foreach ($params as $key => $value) {

            if (!empty($value) && !in_array($key, ['expand', 'excel'])) {

                // Exact match for id, company, type and position: `type LIKE '%1%'`
                // would also return rows 11 and 21 and mislead both the "type"
                // filter (issue 5.a) and `position_id`, whose LIKE '%1%' would
                // swallow positions 10-19 and 21.
                if (in_array($key, ['id', 'company_id', 'type', 'position_id'])) {

                    $query->andWhere([$key => $value]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC])->asArray(),
            'pagination' => $params['excel'] ? false : ['pageSize' => $perPage, 'page' => $page - 1],
        ]);

        $contacts = $dataProvider->getModels();

        return [
            'success' => true,
            'totalCount' => $totalCount,
            'data' => $contacts,
            'pages' => ceil($totalCount / $perPage),
            
        ];
    }

    public static function _save($id = null)
    {
        $user = Yii::$app->user->identity;
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(1001);

            $model = new Contact();
            $model->created_by = $user->id;

        } else {

            User::checkAccess(1003);

            $model = Contact::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'شخص با این شناسه یافت نشد',
                ];

            }
        }

        // An empty "position" select means "no position" (issue 2.2). Left as ''
        // the integer column would either be stored as 0 — which the daily-report
        // filters read as a real position — or be rejected by strict MySQL.
        if (array_key_exists('position_id', $params) && $params['position_id'] === '') {
            $params['position_id'] = null;
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
        User::checkAccess(1002);

        $model = Contact::find()->with(['creator', 'company'])->where(['id' => $id])->one();

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
        User::checkAccess(1004);

        $contact = Contact::findOne($id);

        if (!$contact) {
            throw new UnauthorizedHttpException('شخص با این شناسه یافت نشد');
        }

        $contact->delete();

        return ['success' => true];
    }

    public static function form_data()
    {
        return Contact::find()->with(['creator', 'company'])->select(['*', 'id AS value', 'fname AS label'])->asArray()->all();
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getState()
    {
        return $this->hasOne(State::class, ['id' => 'state']);
    }

    public function getCity()
    {
        return $this->hasOne(City::class, ['id' => 'city']);
    }

    public function getCreator()
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }
}
