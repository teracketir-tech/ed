<?php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\Accounting;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class AccountingController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Accounting';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'edit', 'delete', 'approve', 'import-by-excel', 'get-related-banks'],
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
        return Accounting::get_all();
    }

    public function actionView($id)
    {
        return Accounting::_view($id);
    }

    public function actionCreate()
    {
        return Accounting::_save();
    }

    /** Editing is draft-only; the model refuses an approved document. */
    public function actionUpdate($id)
    {
        return Accounting::_update($id);
    }

    public function actionApprove($id)
    {
        return Accounting::_approve($id);
    }

    public function actionDelete($id)
    {
        return Accounting::_delete($id);
    }

    public function actionImportByExcel()
    {
        return Accounting::import_by_excel();
    }

    public function actionGetRelatedBanks($project_id, $company_id = null, $show_all = false)
    {
        return Accounting::get_related_banks($project_id, $company_id, $show_all);
    }
}
