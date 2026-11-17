<?php

namespace frontend\modules\hq\controllers;

use yii\rest\ActiveController;

class AppController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\User';

    public function actionLastVersion()
    {
        return ['success' => true, 'version' => '1.0.23'];
    }
}
