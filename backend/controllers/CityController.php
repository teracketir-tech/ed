<?php

namespace frontend\controllers;

use frontend\models\City;
use yii\rest\ActiveController;
 
class CityController extends ActiveController
{
    public $modelClass = 'frontend\models\City';

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    public function actionView($q)
    {
        return City::_view($q);
    }

}
