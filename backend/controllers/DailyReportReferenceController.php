<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\services\ReferenceListService;
use yii\rest\ActiveController;

/**
 * کنترلر پایه‌ی دو لیست مرجعِ گزارش روزانه (نوع ماشین‌آلات و دلیل انحراف) — ایشوی ۱۲.
 *
 * هر فرزند فقط `$modelClass` را می‌گوید و کل منطق در `ReferenceListService`
 * می‌ماند؛ دو کنترلرِ کاملِ کپی‌شده نباید ساخته شود (DRY) — همان الگوی
 * `DailyReportLineController`.
 *
 * مسیرها (از `Route::crud` در config/routes.php):
 *   GET    hq/daily-report-{list}        فهرست با صفحه‌بندی و جستجوی نام
 *   POST   hq/daily-report-{list}        افزودن (name + sort_order)
 *   GET    hq/daily-report-{list}/{id}   خواندن یک ردیف
 *   PATCH  hq/daily-report-{list}/{id}   ویرایش
 *   DELETE hq/daily-report-{list}/{id}   حذف (ردِ موردِ استفاده‌شده)
 */
abstract class DailyReportReferenceController extends ActiveController
{
    /**
     * ⚠ هیچ اکشنی را از این لیست حذف نکن: JwtAuthBehavior فقط اکشن‌هایِ
     * لیست‌شده را چک می‌کند و بقیه بدون توکن اجرا می‌شوند (ایشوی ۳).
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
        return ReferenceListService::index($this->modelClass);
    }

    public function actionView($id)
    {
        return ReferenceListService::view($this->modelClass, $id);
    }

    public function actionCreate()
    {
        return ReferenceListService::save($this->modelClass);
    }

    public function actionUpdate($id)
    {
        return ReferenceListService::save($this->modelClass, $id);
    }

    public function actionDelete($id)
    {
        return ReferenceListService::delete($this->modelClass, $id);
    }
}
