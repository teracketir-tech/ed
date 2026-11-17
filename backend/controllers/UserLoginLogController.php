<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\UserLoginLog;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class UserLoginLogController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\UserLoginLog';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'edit', 'delete'],
        ];
        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    public function actionLogout($id)
    {
        return UserLoginLog::_logout($id);
    }

    public function actionLogoutAll()
    {
        return UserLoginLog::logout_all();
    }

    public function actionUserLogs($id)
    {
        return UserLoginLog::user_logs($id);
    }
}
