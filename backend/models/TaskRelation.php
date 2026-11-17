<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use frontend\modules\hq\services\ListService;
use frontend\modules\hq\services\RelationBatchService;
use frontend\modules\hq\services\RelationValidator;

/**
 * رابطه بین دو تسک (Dependency) — جدول `task_relation`
 *
 * relation_type: FS / SS / FF / SF
 * lag: مثبت = Lag ، منفی = Lead
 */
class TaskRelation extends ActiveRecord
{
    public const TYPE_FS = 'FS';
    public const TYPE_SS = 'SS';
    public const TYPE_FF = 'FF';
    public const TYPE_SF = 'SF';

    public const TYPES = [
        self::TYPE_FS => 'پایان-شروع',
        self::TYPE_SS => 'شروع-شروع',
        self::TYPE_FF => 'پایان-پایان',
        self::TYPE_SF => 'شروع-پایان',
    ];

    public const UNIT_DAY = 1;
    public const UNIT_HOUR = 2;

    public static function tableName()
    {
        return 'task_relation';
    }

    public function rules()
    {
        return [
            [['project_id', 'predecessor_task_id', 'successor_task_id'], 'required'],
            [['project_id', 'predecessor_task_id', 'successor_task_id', 'lag_unit'], 'integer'],
            [['lag'], 'number'],
            [['relation_type'], 'in', 'range' => array_keys(self::TYPES)],
            [['predecessor_task_id', 'successor_task_id'], 'validatePair'],
            [['successor_task_id'], 'validateNoCycle'],
        ];
    }

    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if ($this->relation_type === '' || $this->relation_type === null) {
            $this->relation_type = self::TYPE_FS;
        }
        if ($this->lag === '' || $this->lag === null) {
            $this->lag = 0;
        }
        if (!$this->lag_unit) {
            $this->lag_unit = self::UNIT_DAY;
        }

        return true;
    }

    /**
     * هر دو تسک باید وجود داشته باشند، داخل همین پروژه باشند، با خودشان یکی نباشند
     * و هیچ‌کدام summary (دارای زیرمجموعه) نباشند.
     *
     * این ولیدیتور برای هر دو فیلد صدا زده می‌شود، پس فقط خطایِ همان فیلدِ جاری را
     * می‌افزاید تا پیام تکراری نگیریم.
     */
    public function validatePair($attribute)
    {
        $errors = RelationValidator::pairErrors($this);

        if (isset($errors[$attribute])) {
            $this->addError($attribute, $errors[$attribute]);
        }
    }

    /** جلوگیری از Circular Dependency — افزودن این رابطه نباید حلقه بسازد */
    public function validateNoCycle($attribute)
    {
        if ($message = RelationValidator::cycleError($this)) {
            $this->addError($attribute, $message);
        }
    }

    /**
     * company_id از پروژه کپی می‌شود (منبع واحد حقیقت).
     * عمداً در rules نیست تا از ورودی کاربر mass-assign نشود.
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $project = Project::findOne($this->project_id);
        $this->company_id = $project ? $project->company_id : null;

        return true;
    }

    /**
     * لیست روابط با فیلتر و صفحه‌بندی (برای صفحه‌ی «لیست روابط»).
     * $projectId مسیر project/{id}/task-relations، $perPage = 0 برای انتخاب‌گرها.
     */
    public static function get_all($projectId = null, $perPage = null)
    {
        User::checkAccess(2406);

        $params = ListService::params();
        $projectId = $projectId ?: ($params['project_id'] ?? null);
        $perPage = $perPage ?? (isset($params['per-page']) ? (int) $params['per-page'] : 100);
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = self::find()->with(['project', 'predecessor', 'successor']);

        if ($projectId) {
            $query->andWhere(['project_id' => $projectId]);
        }

        $rows = ListService::filter($query, $params, ['company_id', 'relation_type', 'predecessor_task_id', 'successor_task_id'])
            ->orderBy(['project_id' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return ['success' => true] + ListService::paginate($rows, $perPage, $page);
    }

    public static function _view($id)
    {
        User::checkAccess(2406);

        $relation = self::find()->with(['project', 'predecessor', 'successor'])->where(['id' => $id])->one();

        if (!$relation) {
            return ['success' => false, 'message' => 'رابطه با این شناسه یافت نشد'];
        }

        // ایشوی ۶: یک ثبتِ چندتسکی، ردیف‌های جدا می‌سازد؛ فرمِ ویرایش باید همهٔ
        // آن‌ها را یک‌جا ببیند وگرنه کاربر با ذخیره، بقیه را از دست می‌داد.
        $data = $relation->toArray();
        $data['successor_task_ids'] = self::groupSuccessorIds($relation);

        return ['success' => true, 'data' => $data];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if ($id) {
            User::checkAccess(2407);

            $relation = self::findOne($id);

            if (!$relation) {
                return ['success' => false, 'message' => 'رابطه با این شناسه یافت نشد'];
            }

            // Feedback 3: the edit page is multi-successor too, so a stored row
            // (which holds exactly one successor) is rewritten from the list.
            if (array_key_exists('successor_task_ids', $params)) {
                return RelationBatchService::replace($relation, $params, $params['successor_task_ids']);
            }
        } else {
            User::checkAccess(2405);

            // Multi-successor create: one row per successor, one DB transaction.
            // The single `successor_task_id` path below is kept for callers that
            // still post one id.
            if (array_key_exists('successor_task_ids', $params)) {
                return RelationBatchService::create($params, $params['successor_task_ids']);
            }

            $relation = new self();
        }

        if (!$relation->load($params, '') || !$relation->save()) {
            return ['success' => false, 'errors' => $relation->errors];
        }

        return ['success' => true, 'data' => $relation];
    }

    public static function _delete($id)
    {
        User::checkAccess(2408);

        $relation = self::findOne($id);

        if (!$relation) {
            return ['success' => false, 'message' => 'رابطه با این شناسه یافت نشد'];
        }

        // ایشوی ۶: لیست، یک «رابطهٔ چندتسکی» را یک سطر نشان می‌دهد؛ پس حذفِ
        // همان سطر باید همهٔ ردیف‌های آن گروه را بردارد نه فقط یکی را.
        foreach (self::groupRows($relation) as $row) {
            $row->delete();
        }

        return ['success' => true];
    }

    /**
     * کلیدِ گروهِ «رابطهٔ چندتسکی» (ایشوی ۶).
     *
     * فرمِ ثبت برای هر تسکِ وابسته یک ردیف می‌سازد که همه از همین پنج ستون
     * ارث می‌برند؛ پس این‌ها همان چیزی‌اند که کاربر «یک رابطه» می‌خواند.
     */
    public function groupKey(): array
    {
        return [
            'project_id' => (int) $this->project_id,
            'predecessor_task_id' => (int) $this->predecessor_task_id,
            'relation_type' => (string) $this->relation_type,
            'lag' => (string) $this->lag,
            'lag_unit' => (int) $this->lag_unit,
        ];
    }

    /** شناسه‌های همهٔ تسک‌های وابستهٔ همین گروه — برای پر کردنِ فرمِ ویرایش */
    public static function groupSuccessorIds(self $relation): array
    {
        return self::find()->select('successor_task_id')
            ->where($relation->groupKey())
            ->orderBy(['id' => SORT_ASC])
            ->column();
    }

    /** همهٔ ردیف‌های همین گروه — برای حذفِ یک‌جای آنچه در لیست یک سطر است */
    public static function groupRows(self $relation): array
    {
        return self::find()
            ->where($relation->groupKey())
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    public function getProject()
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }

    public function getPredecessor()
    {
        return $this->hasOne(Task::class, ['id' => 'predecessor_task_id']);
    }

    public function getSuccessor()
    {
        return $this->hasOne(Task::class, ['id' => 'successor_task_id']);
    }
}
