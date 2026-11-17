<?php

namespace frontend\modules\hq\controllers;

use Yii;
use yii\rest\ActiveController;
use frontend\modules\hq\models\AccountingItem;
use frontend\components\JwtAuthBehavior;

class AccountingItemController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\AccountingItem';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'pay', 'receive'],
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
        return AccountingItem::get_all();
    }

    public function actionCalendar()
    {
        return AccountingItem::calendar();
    }

    public function actionCalendarTodayReport()
    {
        return AccountingItem::calendar_today_report(Yii::$app->request->get());
    }

    public function actionView($id)
    {
        return AccountingItem::_view($id);
    }

    public function actionCreate()
    {
        return AccountingItem::_save();
    }

    public function actionUpdate($id)
    {
        return AccountingItem::_save($id);
    }

    public function actionDelete($id)
    {
        return AccountingItem::_delete($id);
    }

    public function actionPay()
    {
        return AccountingItem::_pay();
    }

    public function actionReceive()
    {
        return AccountingItem::_receive();
    }
}
