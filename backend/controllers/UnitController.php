<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\models\Unit;
use yii\rest\ActiveController;

/**
 * واحدِ کالا (ایشوی ۷) — کپیِ ساختارِ کنترلرِ پنل seal.
 *
 * «واحد» در کنارِ «کالاها» می‌نشیند و همان چهار عملیات را می‌دهد؛ فقط
 * مدل به `frontend\modules\hq\models\Unit` برمی‌گردد و چکِ دسترسی از
 * نقش‌های همین ماژول (911..914) است نه 501..504 که مالِ «حساب ها» است.
 */
class UnitController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Unit';

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
        return Unit::get_all();
    }

    public function actionView($id)
    {
        return Unit::_view($id);
    }

    public function actionCreate()
    {
        return Unit::_save();
    }

    public function actionUpdate($id)
    {
        return Unit::_save($id);
    }

    public function actionDelete($id)
    {
        return Unit::_delete($id);
    }
}
