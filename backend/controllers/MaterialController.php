<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Material;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class MaterialController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Material';

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
        return Material::get_all();
    }
}
