<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\services\LineService;
use yii\rest\ActiveController;

/**
 * کنترلر پایه‌ی پنج جدول زیرمجموعه‌ی گزارش روزانه.
 *
 * هر کنترلر فرزند فقط `$modelClass` را می‌گوید و کل منطق در `LineService`
 * می‌ماند؛ پنج کنترلرِ کاملِ کپی‌شده نباید ساخته شود (DRY).
 *
 * مسیرها (از `Route::crud` در config/routes.php):
 *   GET    hq/daily-report-{line}        لیست با فیلتر و صفحه‌بندی
 *   POST   hq/daily-report-{line}        افزودن با مالک در بدنه
 *   GET    hq/daily-report-{line}/{id}   خواندن یک ردیف
 *   PUT    hq/daily-report-{line}/{id}   ویرایش
 *   DELETE hq/daily-report-{line}/{id}   حذف
 *
 * افزودنِ تو در تو روی `DailyReportController` است چون URL زیرِ پیشوندِ همان
 * کنترلر می‌نشیند؛ مالکِ ردیف همان‌جا از مسیر می‌آید:
 *   POST hq/daily-report/activity/<activityId>/{line}  نیرو/ماشین/مصالح (ایشوی ۱۰)
 *   POST hq/daily-report/{id}/{line}                   تحویل/تایم‌شیت (ایشوی ۴)
 */
abstract class DailyReportLineController extends ActiveController
{
    /**
     * ⚠ هیچ اکشنی را از این لیست حذف نکن: JwtAuthBehavior فقط اکشن‌هایِ
     * لیست‌شده را چک می‌کند و بقیه بدون توکن اجرا می‌شوند
     * (ایشوی ۳ — همان باگی که رفع شد و تست دارد).
     */
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
        return LineService::index($this->modelClass);
    }

    public function actionView($id)
    {
        return LineService::view($this->modelClass, $id);
    }

    public function actionCreate()
    {
        return LineService::save($this->modelClass);
    }

    public function actionUpdate($id)
    {
        return LineService::save($this->modelClass, $id);
    }

    public function actionDelete($id)
    {
        return LineService::delete($this->modelClass, $id);
    }
}
