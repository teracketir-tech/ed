<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\AccountingData;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class AccountingDataController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\AccountingData';

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
        return AccountingData::get_all();
    }

    public function actionReport()
    {
        return AccountingData::_report();
    }

    public function actionView($id)
    {
        return AccountingData::_view($id);
    }

    public function actionCreate()
    {
        return AccountingData::_save();
    }

    public function actionUpdate($id)
    {
        return AccountingData::_save($id);
    }

    public function actionDelete($id)
    {
        return AccountingData::_delete($id);
    }

}
