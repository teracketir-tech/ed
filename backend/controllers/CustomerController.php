<?php

namespace frontend\controllers;

use frontend\models\Customer;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class CustomerController extends ActiveController
{
    public $modelClass = 'frontend\models\Customer';

    // public function behaviors()
    // {
    //     $behaviors = parent::behaviors();
    //     $behaviors['jwtAuth'] = [
    //         'class' => JwtAuthBehavior::class,
    //         'actions' => ['index', 'view', 'create', 'edit', 'delete'],
    //     ];
    //     return $behaviors;
    // }

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    public function actionIndex()
    {
        return Customer::get_all();
    }

    public function actionView($id)
    {
        return Customer::_view($id);
    }

    public function actionCreate()
    {
        return Customer::_save();
    }

    public function actionEdit($id)
    {
        return Customer::_save($id);
    }

    public function actionDelete($id)
    {
        return Customer::_delete($id);
    }

}
