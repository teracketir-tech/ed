<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;
use frontend\modules\hq\services\ProjectNodeService;
use frontend\modules\hq\services\ReportSnapshot;

class Project extends ActiveRecord
{

    public static function tableName()
    {
        return 'project';
    }

    public function rules()
    {
        return [
            [['id', 'company_id'], 'integer'],
            [['name'], 'safe'],
            // تاریخِ شروع — میلادی ISO در دیتابیس؛ تبدیل شمسی بر عهدهٔ انتخاب‌گرِ فرانت است
            [['start_date'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true],
        ];
    }

    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        // انتخاب‌گرِ خالی '' می‌فرستد و ستونِ DATE آن را رد می‌کند
        if ($this->start_date === '') {
            $this->start_date = null;
        }

        return true;
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->get();

        if (!$params)
            $params = Yii::$app->request->post();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = Project::find()->with('company');

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                // start_date (ایشوی ۱) هم تطابقِ دقیق می‌خواهد؛ LIKE روی ستونِ
                // date می‌تواند روزهای دیگر را هم بیاورد.
                if (in_array($key, ['id', 'company_id', 'start_date'])) {

                    $query->andWhere([$key => $value]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        $projects = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $projects,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new Project();

        } else {

            User::checkAccess(503);

            $model = Project::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'پروژه با این شناسه یافت نشد',
                ];

            }
        }

        if ($model->load($params, '') && $model->save()) {

            if (!$params['company_id']) {

                $model->company_id = null;
                $model->save();

            }

            // هر پروژه یک گرهٔ WBS (کد 0) با نام خودش دارد؛ ساخته یا هم‌نام می‌شود.
            ProjectNodeService::ensureRoot($model->id);

            // لنگرِ زمان‌بندی جابه‌جا شد؛ تاریخِ برنامه‌ایِ ثبت‌شده در گزارش‌های
            // پیشین هم باید حرکت کند تا گزارشِ تاریخی برنامهٔ مرده نشان ندهد.
            if ($model->isAttributeChanged('start_date')) {
                ReportSnapshot::refreshProject($model->id);
            }

            return [
                'success' => true,
                'data' => $model,
            ];

        }

        return [
            'success' => false,
            'errors' => $model->errors,
        ];
    }

    public static function _view($id)
    {
        User::checkAccess(502);

        $model = Project::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'پروژه با این شناسه یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _delete($id)
    {
        User::checkAccess(504);

        $seal = Project::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('پروژه با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

}
