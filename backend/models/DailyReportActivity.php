<?php

namespace frontend\modules\hq\models;

use frontend\modules\hq\services\ReportRules;
use frontend\modules\hq\services\ReportSnapshot;
use frontend\modules\hq\services\ReportableTasks;
use yii\db\ActiveRecord;

/**
 * عملکرد یک Task در یک گزارش روزانه — جدول `daily_report_activities`
 * مهم‌ترین جدول فاز دوم (سند: هر ردیف یعنی «عملکرد یک Task در یک گزارش»).
 *
 * قاعده‌ی مهم: منبع اصلی همیشه `task_id` است. ستون‌های snapshot و
 * `cumulative_progress` عمداً در rules نیستند — سند فاز ۲ (بند ۲۱۰) می‌گوید
 * تجمعی را Backend حساب کند نه کاربر، و snapshot را هم ReportSnapshot می‌سازد.
 */
class DailyReportActivity extends ActiveRecord
{
    use HasTimestamps;

    public static function tableName()
    {
        return 'daily_report_activities';
    }

    /**
     * `validateTask` آخرین قاعده است چون هم به گزارش و هم به تسک سر می‌زند
     * و باید بعد از سایر خطاهای همان فیلد اجرا شود.
     */
    public function rules()
    {
        return [
            [['daily_report_id', 'task_id', 'daily_progress'], 'required'],
            [['daily_report_id', 'task_id', 'executor_id', 'supervisor_id', 'contractor_id'], 'integer'],
            [['daily_report_id'], 'validateReport'],
            // targetAttribute باید صریح باشد: بدون آن Yii نام ستونِ ورودی
            // (مثلا contact.executor_id) را ستون هدف می‌گیرد و کوئری 500 می‌دهد.
            [['executor_id', 'supervisor_id', 'contractor_id'], 'exist', 'targetClass' => Contact::class, 'targetAttribute' => 'id', 'message' => 'شخص انتخاب‌شده یافت نشد'],
            [['supervisor_forecast_date'], 'date', 'format' => 'php:Y-m-d'],
            [['work_hours'], 'number', 'min' => 0],
            [['daily_progress', 'quality_percent'], 'number', 'min' => 0, 'max' => ReportRules::PROGRESS_MAX],
            [['status'], 'integer'],
            [['status'], 'in', 'range' => ReportRules::values(ReportRules::ACTIVITY_STATUS)],
            [['description', 'risks'], 'string'],
            [['task_id'], 'validateTask'],
        ];
    }

    public function validateReport($attribute)
    {
        if (!$this->$attribute) {
            return;
        }

        if (!DailyReport::findOne($this->$attribute)) {
            $this->addError($attribute, 'گزارش روزانه‌ی انتخاب‌شده یافت نشد');
        }
    }

    /**
     * سند فاز ۲ بند ۱۷: «task_id باید متعلق به همان project_id باشد.»
     * افزون بر آن، هر تسک فقط یک بار در هر گزارش قابل ثبت است تا پیشرفت
     * تجمعی دوباره شمرده نشود.
     */
    public function validateTask($attribute)
    {
        $report = $this->report;

        if (!$report) {
            return;
        }

        $task = Task::findOne($this->task_id);

        if (!$task) {
            $this->addError($attribute, 'تسک انتخاب‌شده یافت نشد');
        } elseif ($task->project_id != $report->project_id) {
            $this->addError($attribute, 'تسک باید متعلق به همین پروژه باشد');
        } elseif ($this->isDuplicate()) {
            $this->addError($attribute, 'این فعالیت قبلاً در همین گزارش ثبت شده است');
        } elseif (!ReportableTasks::allows($report->project_id, $this->task_id, $report->report_date)) {
            // ایشوی ۱۵: قاعدهٔ فهرستِ خودکار همین‌جاست هم اجرا می‌شود تا رابطه‌ای
            // که چند روز جلوتر برنامه‌ریزی شده از فرم قابلِ ثبت نشود.
            $this->addError($attribute, 'تاریخ گزارش خارج از پنجره برنامه‌ریزی این تسک است');
        }
    }

    private function isDuplicate(): bool
    {
        return self::find()
            ->where(['daily_report_id' => $this->daily_report_id, 'task_id' => $this->task_id])
            ->andFilterWhere(['!=', 'id', $this->id])
            ->exists();
    }

    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        foreach (['daily_report_id', 'task_id', 'executor_id', 'supervisor_id', 'contractor_id', 'status'] as $attr) {
            if ($this->$attr === '') {
                $this->$attr = null;
            }
        }

        foreach (['description', 'risks'] as $attr) {
            if (is_string($this->$attr)) {
                $this->$attr = trim($this->$attr) ?: null;
            }
        }

        return true;
    }

    /**
     * company_id از پروژه‌ی گزارش کپی می‌شود و snapshot فقط هنگام «افزودن»
     * یا «تغییرِ خودِ task_id» ساخته می‌شود تا ویرایش بقیه‌ی فیلدها،
     * تصویر برنامه‌ای ثبت‌شده در گزارش‌های قبلی را بازنویسی نکند.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->touchTimestamps($insert);

        $report = $this->report;

        if (!$report) {
            return true;
        }

        $project = Project::findOne($report->project_id);
        $this->company_id = $project ? $project->company_id : null;

        if ($insert || $this->isAttributeChanged('task_id')) {
            $snapshot = ReportSnapshot::forTask($report->project_id, $this->task_id);

            if ($snapshot !== null) {
                foreach ($snapshot as $column => $value) {
                    $this->$column = $value;
                }
            }
        }

        return true;
    }

    public function getReport()
    {
        return $this->hasOne(DailyReport::class, ['id' => 'daily_report_id']);
    }

    public function getTask()
    {
        return $this->hasOne(Task::class, ['id' => 'task_id']);
    }

    /** پیمانکارِ فعالیت (ایشوی ۳) — فقط اینجا و فقط یک بار پرسیده می‌شود */
    public function getContractor()
    {
        return $this->hasOne(Contact::class, ['id' => 'contractor_id']);
    }

    /**
     * عوامل انحرافِ این فعالیت (ایشوی ۴) — چندتایی، در جدول رابطه؛
     * ستونِ تکی `deviation_reason_id` حذف شد.
     */
    public function getDeviations()
    {
        return $this->hasMany(DailyReportActivityDeviation::class, ['daily_report_activity_id' => 'id']);
    }

    /**
     * Feedback 2: "deleting an activity deletes every item linked to it too."
     *
     * The manpower / equipment / material / delivery rows and the deviation
     * rows go away through `ON DELETE CASCADE` on this very table. `users_logs`
     * deliberately has NO such foreign key: the attendance machine owns those
     * rows and feedback 1 demands the source log is never deleted. So here only
     * its stamps are dropped, which returns the row to that day's pool instead
     * of leaving it pointing at nothing. Runs inside the
     * `ActivityService::delete` transaction.
     */
    public function afterDelete()
    {
        parent::afterDelete();

        UsersLog::updateAll(
            [
                'daily_report_activity_id' => null,
                'daily_report_id' => null,
                'company_id' => null,
            ],
            ['daily_report_activity_id' => (int) $this->id]
        );
    }
}
