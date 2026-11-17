<?php

namespace frontend\modules\hq\controllers;

use Yii;
use frontend\modules\hq\models\Project;
use frontend\modules\hq\models\Task;
use frontend\modules\hq\models\TaskRelation;
use frontend\modules\hq\models\User;
use frontend\modules\hq\services\CpmService;
use frontend\modules\hq\services\ReportableTasks;
use frontend\modules\hq\services\ReportRoles;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;

class ProjectController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\Project';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            // `edit` سال‌ها کلیدِ یک اکشنِ ناموجود بود و `update` (اکشن واقعیِ
            // `actionUpdate`) اصلاً در لیست نبود؛ یعنی `PUT hq/project/{id}`
            // بدون توکن اجرا می‌شد (یافته‌ی C — اثبات تجربی: 200 + نوشتن).
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'tasks', 'task-relations', 'reportable-tasks', 'calculate-cpm'],
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
        return Project::get_all();
    }

    public function actionView($id)
    {
        return Project::_view($id);
    }

    public function actionCreate()
    {
        return Project::_save();
    }

    public function actionUpdate($id)
    {
        return Project::_save($id);
    }

    public function actionDelete($id)
    {
        return Project::_delete($id);
    }

    /** تسک‌های (گره‌های WBS) یک پروژه بدون صفحه‌بندی — برای انتخاب‌گر فرم‌ها (بدون هزینه‌ی CPM) */
    public function actionTasks($id)
    {
        User::checkAccess(2402);

        return Task::get_all($id, 0, false);
    }

    /**
     * Taskهای WBS قابل انتخاب در فرم گزارش روزانه — سند فاز ۲ بند ۱۴.
     *
     * فرقش با `tasks` این است که CPM هم دارد تا فرم بتواند بعد از انتخاب Task،
     * «کد/عنوان/مدت/شروع/پایان/Free Float/Latest Finish» را از Backend پر کند
     * (بندهای ۵ و ۱۶). نقش: فاز ۲، چون فقط برای همین فرم ساخته شده و خواندنِ
     * صفحه‌ی «لیست تسک‌ها» ربطی به آن ندارد.
     *
     * با `report_date` فقط فعالیت‌های برگی را برمی‌گرداند که پنجره‌ی CPM آن روز
     * را در بر بگیرد (ایشوی ۳ — قاعده: شروع ≤ روزِ گزارش ≤ پایان).
     */
    public function actionReportableTasks($id)
    {
        User::checkAccess(ReportRoles::VIEW);

        $result = Task::get_all($id, 0, true);

        // قاعدهٔ ایشوی ۳: شروعِ فعالیت ≤ روزِ گزارش ≤ پایانِ فعالیت، فقط برگ‌ها.
        // بدون report_date دست‌نخورده برمی‌گردد تا مصرف‌کنندهٔ قدیمی‌تر خالی نشود.
        if (!empty($result['data'])) {
            $result['data'] = ReportableTasks::filter(
                $result['data'],
                Yii::$app->request->get('report_date')
            );
        }

        return $result;
    }

    /** روابط بین تسک‌های یک پروژه بدون صفحه‌بندی */
    public function actionTaskRelations($id)
    {
        return TaskRelation::get_all($id, 0);
    }

    /** محاسبه CPM و مسیر بحرانی */
    public function actionCalculateCpm($id)
    {
        User::checkAccess(2402);

        return CpmService::calculate($id);
    }

}
