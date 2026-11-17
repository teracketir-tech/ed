<?php

namespace frontend\controllers;

use frontend\models\LoginLog;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class LoginLogController extends ActiveController
{
    public $modelClass = 'frontend\models\LoginLog';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'logout', 'user-logs', 'logout-all'],
        ];
        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    public function actionIndex()
    {
        return LoginLog::get_all();
    }

    public function actionLogout($id)
    {
        return LoginLog::_logout($id);
    }

    public function actionLogoutAll()
    {
        return LoginLog::logout_all();
    }

    public function actionUserLogs($id)
    {
        return LoginLog::user_logs($id);
    }
}
