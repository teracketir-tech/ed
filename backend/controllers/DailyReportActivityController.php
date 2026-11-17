<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\services\ActivityService;
use yii\rest\ActiveController;

/**
 * فعالیت‌های ثبت‌شده در گزارش روزانه — فاز ۲ (ایشوی ۳).
 *
 * سند فاز ۲: افزودن از مسیر تو در تو `POST hq/daily-report/{id}/activities`
 * انجام می‌شود؛ این کنترلر لیست، ویرایش و حذف را می‌دهد.
 */
class DailyReportActivityController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\DailyReportActivity';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'create', 'update', 'delete'],
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
        return ActivityService::get_all();
    }

    public function actionCreate()
    {
        return ActivityService::save();
    }

    public function actionUpdate($id)
    {
        return ActivityService::save($id);
    }

    public function actionDelete($id)
    {
        return ActivityService::delete($id);
    }
}
