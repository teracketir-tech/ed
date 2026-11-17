<?php

namespace frontend\modules\hq\controllers;

use Yii;
use frontend\modules\hq\models\HrmVacation;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;
use yii\filters\VerbFilter;

class HrmVacationController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\HrmVacation';

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => [
                'index', 'view', 'create', 'update', 'delete',
                'approve', 'reject',
                'vacations-list', 'daily-report', 'monthly-report',
            ],
        ];

        $behaviors['verbs'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'index' => ['GET'],
                'view' => ['GET'],
                'create' => ['POST'],
                'update' => ['PATCH', 'PUT', 'POST'],
                'delete' => ['DELETE'],
                'approve' => ['POST'],
                'reject' => ['POST'],
                'vacations-list' => ['GET'],
                'daily-report' => ['GET'],
                'monthly-report' => ['GET'],
            ],
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
        return HrmVacation::get_all();
    }

    public function actionView($id)
    {
        return HrmVacation::_view($id);
    }

    public function actionCreate()
    {
        return HrmVacation::_save();
    }

    public function actionUpdate($id)
    {
        return HrmVacation::_save($id);
    }

    public function actionDelete($id)
    {
        return HrmVacation::_delete($id);
    }

    public function actionApprove($id)
    {
        return HrmVacation::approve($id);
    }

    public function actionReject($id)
    {
        return HrmVacation::reject($id);
    }

    public function actionVacationsList()
    {
        return HrmVacation::getVacationsList();
    }

    public function actionDailyReport()
    {
        return HrmVacation::getDailyReport();
    }

    public function actionMonthlyReport()
    {
        return HrmVacation::getMonthlyReport();
    }
}