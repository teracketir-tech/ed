<?php

namespace frontend\modules\hq\controllers;

use Yii;
use frontend\modules\hq\models\HrmMissionType;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;
use yii\filters\VerbFilter;

class HrmMissionTypeController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\HrmMissionType';

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'list', 'toggle-status'],
        ];

        $behaviors['verbs'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'index' => ['GET'],
                'view' => ['GET'],
                'create' => ['POST'],
                'update' => ['PATCH', 'PUT', 'POST'],
                'delete' => ['DELETE'],
                'list' => ['GET'],
                'toggle-status' => ['POST'],
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
        $params = Yii::$app->request->get();
        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = HrmMissionType::find()
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_DESC]);

        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $query->andWhere(['is_active' => $params['is_active']]);
        }

        if (!empty($params['search'])) {
            $query->andWhere(['like', 'name', $params['search']]);
        }

        $totalCount = $query->count();
        $models = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->asArray()
            ->all();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $models,
        ];
    }

    public function actionView($id)
    {
        $model = HrmMissionType::find()
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$model) {
            return ['success' => false, 'message' => 'نوع ماموریت یافت نشد'];
        }

        return ['success' => true, 'data' => $model];
    }

    public function actionCreate()
    {
        // ⚠️ برای POST هم getBodyParams امن است (همان post را برمی‌گرداند)
        $params = Yii::$app->request->getBodyParams();
        $model = new HrmMissionType();

        if ($model->load($params, '') && $model->save()) {
            return ['success' => true, 'data' => $model];
        }

        return ['success' => false, 'errors' => $model->errors];
    }

    public function actionUpdate($id)
    {
        $model = HrmMissionType::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'نوع ماموریت یافت نشد'];
        }

        $params = Yii::$app->request->getBodyParams();

        if ($model->load($params, '') && $model->save()) {
            return ['success' => true, 'data' => $model];
        }

        return ['success' => false, 'errors' => $model->errors];
    }

    public function actionDelete($id)
    {
        $model = HrmMissionType::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'نوع ماموریت یافت نشد'];
        }

        $inUse = \frontend\modules\hq\models\HrmMission::find()
            ->where(['mission_type_id' => $id])
            ->exists();

        if ($inUse) {
            return ['success' => false, 'message' => 'این نوع ماموریت در حال استفاده است و قابل حذف نمی‌باشد'];
        }

        $model->delete();
        return ['success' => true];
    }

    /**
     * GET /hrm-mission-type/list
     * لیست انواع ماموریت فعال برای dropdown
     * خروجی: { id: name, ... }
     */
   public function actionList()
{
    $types = HrmMissionType::find()
        ->where(['is_active' => 1])
        ->orderBy(['sort_order' => SORT_ASC])
        ->asArray()
        ->all();

    return [
        'success' => true,
        'data' => $types,   // ← آرایه از {id, name, sort_order, description, is_active}
    ];
}

    public function actionToggleStatus($id)
    {
        $model = HrmMissionType::findOne($id);
        if (!$model) {
            return ['success' => false, 'message' => 'نوع ماموریت یافت نشد'];
        }

        $model->is_active = $model->is_active ? 0 : 1;
        $model->save(false);

        return ['success' => true, 'data' => $model];
    }
}