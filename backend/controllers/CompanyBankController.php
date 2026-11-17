<?php

namespace frontend\controllers;

use frontend\models\CompanyBank;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class CompanyBankController extends ActiveController
{
    public $modelClass = 'frontend\models\CompanyBank';

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

    public function actionIndex()
    {
        return CompanyBank::get_all();
    }

    public function actionView($id)
    {
        return CompanyBank::_view($id);
    }

    public function actionCreate()
    {
        return CompanyBank::_save();
    }

    public function actionEdit($id)
    {
        return CompanyBank::_save($id);
    }

    public function actionDelete($id)
    {
        return CompanyBank::_delete($id);
    }

}
