<?php
// frontend/modules/hq/controllers/HrmReportsController.php

namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\HrmReports;
use Yii;
use yii\rest\Controller;
use frontend\components\JwtAuthBehavior;

class HrmReportsController extends Controller
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['salary-penalty', 'payment-description', 'deduction-description'],
        ];
        return $behaviors;
    }

    public function actionSalaryPenalty()
    {
        return HrmReports::getSalaryPenalty();
    }

    public function actionPaymentDescription()
    {
        return HrmReports::getPaymentDescription();
    }

    public function actionDeductionDescription()
    {
        return HrmReports::getDeductionDescription();
    }
}