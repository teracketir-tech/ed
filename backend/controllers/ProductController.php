<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Product;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class ProductController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Product';

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
        return Product::get_all();
    }

    public function actionView($id)
    {
        return Product::_view($id);
    }

    public function actionCreate()
    {
        return Product::_save();
    }

    public function actionUpdate($id)
    {
        return Product::_save($id);
    }

    public function actionDelete($id)
    {
        return Product::_delete($id);
    }

}
