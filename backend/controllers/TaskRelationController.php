<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\models\TaskRelation;
use yii\rest\ActiveController;

class TaskRelationController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\TaskRelation';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'project-relations'],
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
        return TaskRelation::get_all();
    }

    public function actionView($id)
    {
        return TaskRelation::_view($id);
    }

    public function actionCreate()
    {
        return TaskRelation::_save();
    }

    public function actionUpdate($id)
    {
        return TaskRelation::_save($id);
    }

    public function actionDelete($id)
    {
        return TaskRelation::_delete($id);
    }

    /** روابط یک پروژه بدون صفحه‌بندی — GET hq/task-relation/project/{id} */
    public function actionProjectRelations($id)
    {
        return TaskRelation::get_all($id, 0);
    }
}
