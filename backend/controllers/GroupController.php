<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Group;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;
 
class GroupController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Group';

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
        return Group::get_all();
    }

    public function actionView($id)
    {
        return Group::_view($id);
    }

    public function actionCreate()
    {
        return Group::_save();
    }

    public function actionUpdate($id)
    {
        return Group::_save($id);
    }

    public function actionDelete($id)
    {
        return Group::_delete($id);
    }

}
