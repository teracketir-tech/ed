<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Contact;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class ContactController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Contact';

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
        return Contact::get_all();
    }

    public function actionView($id)
    {
        return Contact::_view($id);
    }

    public function actionCreate()
    {
        return Contact::_save();
    }

    public function actionUpdate($id)
    {
        return Contact::_save($id);
    }

    public function actionDelete($id)
    {
        return Contact::_delete($id);
    }
}
