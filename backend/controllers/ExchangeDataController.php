<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\ExchangeData;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;
 
class ExchangeDataController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\ExchangeData';

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
        return ExchangeData::get_all();
    }

    public function actionImportByExcel()
    {
        return ExchangeData::import_by_excel();
    }
}
