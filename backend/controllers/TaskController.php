<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\User;
use yii\rest\ActiveController;

class TaskController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Task';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'project-tasks'],
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
        User::checkAccess(2402);

        return Task::get_all();
    }

    public function actionView($id)
    {
        return Task::_view($id);
    }

    public function actionCreate()
    {
        return Task::_save();
    }

    public function actionUpdate($id)
    {
        return Task::_save($id);
    }

    public function actionDelete($id)
    {
        return Task::_delete($id);
    }

    /** تسک‌های یک پروژه بدون صفحه‌بندی — برای انتخاب‌گرهای فرم‌ها (بدون هزینه‌ی CPM) */
    public function actionProjectTasks($id)
    {
        User::checkAccess(2402);

        return Task::get_all($id, 0, false);
    }
}
