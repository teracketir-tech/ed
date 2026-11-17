<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\State;
use yii\rest\ActiveController;

class StateController extends ActiveController
{ 
    public $modelClass = 'frontend\modules\hq\models\State';

    public function actions()
    {
        $actions = parent::actions();
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete']);
        return $actions;
    }

    public function actionIndex()
    {
        return State::get_all();
    }

    public function actionCities($id)
    {
        return State::get_cities($id);
    }

}
