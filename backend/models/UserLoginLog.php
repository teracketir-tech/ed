<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class UserLoginLog extends ActiveRecord
{
    public $imageFile, $visit_count;

    public static function tableName()
    {
        return 'user_login_logs';
    }

    public function rules()
    {
        return [
            [['user_id', 'group_id', 'token', 'active'], 'required'],
            [['ip', 'os'], 'string', 'max' => 255],
            [['user_id', 'group_id', 'active'], 'integer'],
        ];
    }

    public static function _logout($id)
    {
        $log = UserLoginLog::findOne($id);

        if (!$log)
            return ['success' => false, 'message' => 'لاگ یافت نشد'];

        $log->active = 0;
        $log->save();

        return ['success' => true, 'message' => 'با موفقیت انجام شد'];        
    }

    public static function logout_all()
    {
        try {
            
            UserLoginLog::updateAll(['active' => 0]);
        
            return ['success' => true, 'message' => 'با موفقیت انجام شد'];   

        } catch (\Throwable $th) {
            
            return ['success' => false, 'message' => 'خطایی رخ داده است'];

        }
    }

    public static function user_logs($id)
    {
        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        unset($params['per-page'], $params['page'], $params['id']);

        $query = UserLoginLog::find()->where(['user_id' => $id]);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                $query->andWhere(['like', $key, $value]);

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

        $logs = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $logs,
            'user' => User::findOne($id)
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
