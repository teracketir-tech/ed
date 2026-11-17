<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Company;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class CompanyController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Company';
 
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
        return Company::get_all();
    }

    public function actionView($id)
    {
        return Company::_view($id);
    }

    public function actionCreate()
    {
        return Company::_save();
    }

    public function actionUpdate($id)
    {
        return Company::_save($id);
    }

    public function actionDelete($id)
    {
        return Company::_delete($id);
    }

}
