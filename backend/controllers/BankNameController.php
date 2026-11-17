<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\BankName;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class BankNameController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Bank';

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
        return ['success' => true, 'data' => BankName::find()->all()];
    }
}
