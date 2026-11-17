<?php

namespace frontend\modules\hq\models;

use frontend\modules\hq\services\ReportRules;
use yii\db\ActiveRecord;

/**
 * سربرگ گزارش روزانه‌ی عملیات اجرایی — جدول `daily_reports`
 *
 * فقط قاعده‌ها و هوک‌های این جدول اینجاست؛ CRUD در ReportService
 * (همان الگوی ActivityService برای جدول فعالیت‌ها).
 *
 * قرارداد فاز ۲:
 *   - `company_id` از پروژه کپی می‌شود (منبع واحد حقیقت)، هرگز از ورودی کاربر.
 *   - `status` و `submitted_at` در rules نیستند؛ فقط `submit` (ایشوی ۵) می‌نویسشان.
 *   - range لیست‌ها از ReportRules می‌آید تا منبع حقیقت یکی باشد.
 */
class DailyReport extends ActiveRecord
{
    use HasTimestamps;

    public static function tableName()
    {
        return 'daily_reports';
    }

    /**
     * `validateSiteStatus` باید آخرین قاعده باشد: خطای `inactive_reason`
     * را خودش اضافه می‌کند و نباید بعداً توسط قاعده‌ای دیگر نادیده گرفته شود.
     */
    public function rules()
    {
        return [
            // ایشوی «وضعیت کارگاه اجباری»: هم سرور و هم yup فرم آن را می‌خواهند
            [['project_id', 'report_date', 'site_status'], 'required'],
            [['project_id'], 'validateProject'],
            [['report_date'], 'validateDate'],
            [['project_manager_id', 'site_supervisor_id'], 'integer'],
            [['project_manager_id', 'site_supervisor_id'], 'validateContacts'],
            // ایشوی ۱۸: prepared_by دیگر contact نیست و با شناسه‌ی کاربرِ
            // ثبت‌کننده پر می‌شود؛ پس اعتبارسنجیِ «وجود در contact» برداشته شد.
            [['prepared_by'], 'integer'],
            [['weather_status', 'site_status'], 'integer'],
            [['document_no'], 'string', 'max' => 50],
            [['revision'], 'string', 'max' => 20],
            [['weather_note'], 'string', 'max' => 255],
            [['inactive_reason'], 'string'],
            [['weather_status'], 'in', 'range' => ReportRules::values(ReportRules::WEATHER)],
            [['site_status'], 'in', 'range' => ReportRules::values(ReportRules::SITE)],
            [['site_status'], 'validateSiteStatus'],
        ];
    }

    public function validateProject($attribute)
    {
        if (!$this->$attribute) {
            return;
        }

        if (!Project::findOne($this->$attribute)) {
            $this->addError($attribute, 'پروژه انتخاب‌شده یافت نشد');
        }
    }

    public function validateDate($attribute)
    {
        if (!$this->$attribute) {
            return;
        }

        if (!ReportRules::isIsoDate($this->$attribute)) {
            $this->addError($attribute, 'تاریخ گزارش باید به صورت YYYY-MM-DD باشد');
        }
    }

    public function validateContacts($attribute)
    {
        if (!$this->$attribute) {
            return;
        }

        if (!Contact::findOne($this->$attribute)) {
            $this->addError($attribute, 'شخص انتخاب‌شده یافت نشد');
        }
    }

    /** سند فاز ۲ بند ۱۷: کارگاه غیرفعال ⇒ دلیل اجباری؛ کارگاه فعال ⇒ دلیل پاک می‌شود */
    public function validateSiteStatus($attribute)
    {
        $error = ReportRules::siteReasonError($this->site_status, $this->inactive_reason);

        if ($error !== null) {
            $this->addError('inactive_reason', $error);
        }

        if ((int) $this->site_status === ReportRules::SITE_ACTIVE) {
            $this->inactive_reason = null;
        }
    }

    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        // status در rules نیست (فقط submit آن را عوض می‌کند)؛ بدون این، مقدارِ مدلِ
        // داخلی null می‌ماند و پاسخ create به‌جای 0 مقدار null می‌داد.
        if ($this->isNewRecord && ($this->status === null || $this->status === '')) {
            $this->status = ReportRules::STATUS_DRAFT;
        }

        foreach (['project_manager_id', 'site_supervisor_id', 'prepared_by'] as $attr) {
            if ($this->$attr === '') {
                $this->$attr = null;
            }
        }

        if (is_string($this->weather_note)) {
            $this->weather_note = trim($this->weather_note) ?: null;
        }

        if (is_string($this->inactive_reason)) {
            $this->inactive_reason = trim($this->inactive_reason) ?: null;
        }

        return true;
    }

    /**
     * company_id از پروژه کپی می‌شود؛ عمداً در rules نیست تا mass-assign نشود.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $this->touchTimestamps($insert);

        $project = Project::findOne($this->project_id);
        $this->company_id = $project ? $project->company_id : null;

        return true;
    }

    public function getProject()
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }

    /**
     * Feedback 1 applied to the WHOLE report, not just one activity.
     *
     * `ReportService::delete()` deletes the header and lets the
     * `fk_dra_report` foreign key take the activities away. That cascade runs
     * in the database, so `DailyReportActivity::afterDelete()` — which is what
     * releases the log stamps — never fires. Without this hook the log rows
     * would keep pointing at activities that no longer exist, and
     * `TimesheetService::byDate()` shows a row only when its activity stamp is
     * NULL or belongs to the report being picked for: a dangling row is
     * therefore invisible in the picker forever, which loses that person's
     * real attendance for that day.
     *
     * Only the three stamps are dropped; the device rows themselves survive.
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
            ['daily_report_id' => (int) $this->id]
        );
    }
}
