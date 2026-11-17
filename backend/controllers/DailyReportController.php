<?php

namespace frontend\modules\hq\controllers;

use frontend\components\JwtAuthBehavior;
use frontend\modules\hq\services\ActivityService;
use frontend\modules\hq\services\DictionaryService;
use frontend\modules\hq\services\LineService;
use frontend\modules\hq\services\ReportService;
use frontend\modules\hq\services\SubmitService;
use frontend\modules\hq\services\TaskProgress;
use frontend\modules\hq\services\TimesheetService;
use yii\rest\ActiveController;
use Yii;

/**
 * سربرگ گزارش روزانه — فاز ۲ (ایشوی ۲ تا ۵).
 *
 * علاوه بر CRUD سربرگ، «افزودنِ تو در توِ» فعالیت، زیرمجموعه‌ها و «ثبت
 * نهایی» را هم دارد چون این مسیرها زیرِ پیشوند همین کنترلر می‌نشینند:
 * نیرو/ماشین/صالح زیرِ فعالیت (ایشوی ۱۰) و تحویل/تایم‌شیت زیرِ گزارش (ایشوی ۴).
 */
class DailyReportController extends ActiveController
{
    public $modelClass = 'frontend\modules\hq\models\DailyReport';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            // هر اکشن باید اینجا باشد: JwtAuthBehavior فقط اکشن‌هایِ لیست‌شده را
            // چک می‌کند و بقیه بدون توکن اجرا می‌شوند (ایشوی ۳ — رفع باگ امنیتی).
            // تستِ پوششِ این لیست در DailyReportControllerTest.php است.
            'actions' => [
                'index', 'view', 'create', 'update', 'delete', 'dictionaries',
                'create-activity', 'submit', 'task-progress',
                'create-manpower', 'create-equipment', 'create-materials',
                'create-deliveries', 'create-timesheets',
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
        return ReportService::get_all();
    }

    public function actionView($id)
    {
        return ReportService::view($id);
    }

    public function actionCreate()
    {
        return ReportService::save();
    }

    public function actionUpdate($id)
    {
        return ReportService::save($id);
    }

    public function actionDelete($id)
    {
        return ReportService::delete($id);
    }

    /**
     * GET hq/daily-report/dictionaries — واژه‌نامه‌ی سلکت‌های تب‌های زیرمجموعه
     * (ایشوی ۹): نوع ماشین‌آلات، واحد و مصالح که endpoint جدا ندارند؛
     * دلیل انحراف (ایشوی ۱۲) و گروه (ایشوی ۱۵) هم به همین درخواست اضافه شدند.
     *
     * `company_id` (ایشوی ۱): سربرگِ گزارش اول شرکت را انتخاب می‌کند و همه‌ی
     * واژه‌نامه‌ها به همان شرکت محدود می‌شوند؛ بدونِ آن، همه‌ی شرکت‌ها می‌آیند.
     */
    public function actionDictionaries()
    {
        return DictionaryService::all(Yii::$app->request->get('company_id'));
    }

    /** POST hq/daily-report/{id}/activities — افزودن فعالیت به گزارش */
    public function actionCreateActivity($id)
    {
        return ActivityService::save(null, $id);
    }

    /** POST hq/daily-report/activity/<activityId>/manpower — افزودن نیروی انسانی */
    public function actionCreateManpower($activityId)
    {
        return LineService::create('manpower', $activityId);
    }

    /** POST hq/daily-report/activity/<activityId>/equipment — افزودن ماشین‌آلات */
    public function actionCreateEquipment($activityId)
    {
        return LineService::create('equipment', $activityId);
    }

    /** POST hq/daily-report/activity/<activityId>/materials — افزودن مصالح */
    public function actionCreateMaterials($activityId)
    {
        return LineService::create('materials', $activityId);
    }

    /**
     * POST hq/daily-report/{id}/deliveries — افزودن تحویل کالا
     * ایشوی ۴: تحویل کالا مالِ خودِ گزارش است، پس `{id}` همان شناسه‌ی گزارش است.
     */
    public function actionCreateDeliveries($id)
    {
        return LineService::create('deliveries', $id);
    }

    /**
     * POST hq/daily-report/{id}/timesheets — one request, two shapes, both
     * report-scoped (issue 4):
     *   `{log_ids: [...]}` — tick rows of the fingerprint log of the report
     *     day and stamp them with this report (the normal path);
     *   `{user_id, checkindate, ...}` — a manual row for a person the device
     *     did not write (the fallback when the day has no log at all).
     */
    public function actionCreateTimesheets($id)
    {
        $params = Yii::$app->request->post();

        if (array_key_exists('log_ids', $params)) {
            return TimesheetService::attach($id, $params['log_ids']);
        }

        return LineService::create('timesheets', $id);
    }

    /** POST hq/daily-report/{id}/submit — ثبت نهایی گزارش (ایشوی ۵) */
    public function actionSubmit($id)
    {
        return SubmitService::submit($id);
    }

    /**
     * GET hq/daily-report/{id}/task-progress — پیشرفتِ قبلیِ هر تسک در این پروژه
     * (ایشوی ۱۱): دیالوگِ فعالیت هنگام انتخاب تسک می‌داند نوارِ درصد پیشرفت
     * باید از کجا ادامه پیدا کند.
     */
    public function actionTaskProgress($id)
    {
        return TaskProgress::forReport($id);
    }
}
