<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Bank;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class BankController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Bank';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete'],
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
        return Bank::get_all();
    }

    public function actionView($id)
    {
        return Bank::_view($id);
    }

    public function actionCreate()
    {
        return Bank::_save();
    }

    public function actionUpdate($id)
    {
        return Bank::_save($id);
    }

    public function actionDelete($id)
    {
        return Bank::_delete($id);
    }

}
