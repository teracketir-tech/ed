<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use frontend\modules\hq\services\CpmService;
use frontend\modules\hq\services\ListService;
use frontend\modules\hq\services\ProjectNodeService;
use frontend\modules\hq\services\RelationValidator;
use frontend\modules\hq\services\WbsService;

/**
 * تسک / گره ساختار WBS — جدول `task`
 */
class Task extends ActiveRecord
{
    public const UNIT_DAY = 1;
    public const UNIT_HOUR = 2;

    public static function tableName()
    {
        return 'task';
    }

    public function rules()
    {
        return [
            [['project_id', 'name'], 'required'],
            [['project_id', 'parent_id', 'duration_unit', 'sort_order'], 'integer'],
            [['duration'], 'number', 'min' => 0],
            [['duration_unit'], 'in', 'range' => [self::UNIT_DAY, self::UNIT_HOUR]],
            [['name'], 'string', 'max' => 255],
            // wbs_code عمداً در rules نیست: همیشه توسط WbsService::rebuildWbs ساخته می‌شود
            // و نباید از ورودی کاربر mass-assign شود.
            [['parent_id'], 'validateParent'],
        ];
    }

    public function beforeValidate()
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if ($this->parent_id === '') {
            $this->parent_id = null;
        }
        if ($this->duration === '' || $this->duration === null) {
            $this->duration = 0;
        }
        if (!$this->duration_unit) {
            $this->duration_unit = self::UNIT_DAY;
        }
        if ($this->sort_order === '' || $this->sort_order === null) {
            $this->sort_order = 0;
        }

        return true;
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

        // «بدون والد» یعنی یک ردیفِ ریشهٔ تازه، و تنها ردیفِ ریشهٔ ممکن گرهٔ
        // پروژه است؛ پس چنین تسکی زیرِ گره می‌نشیند. خودِ گره استثناست.
        if (!$this->parent_id && !ProjectNodeService::isRoot($this)) {
            $node = ProjectNodeService::rootOf($this->project_id);

            if ($node) {
                $this->parent_id = $node->id;
            }
        }

        return true;
    }

    /**
     * والد باید داخل همین پروژه باشد، نباید از زیرمجموعه‌های خود تسک انتخاب شود
     * (در غیر این صورت ساختار درختی حلقه می‌شود) و نباید در رابطه باشد.
     *
     * چون تسکِ دارای زیرمجموعه (summary) در شبکه‌ی CPM شرکت نمی‌کند، دادن زیرمجموعه به
     * تسکِ رابطه‌دار باعث می‌شد آن رابطه بی‌اثر شود؛ پس همین‌جا جلویش را می‌گیریم.
     * بررسی فقط هنگام تغییرِ والد انجام می‌شود تا ویرایش نام تسک‌های موجود به خطا نیفتد.
     */
    public function validateParent($attribute)
    {
        if (!$this->parent_id) {
            return;
        }

        $parent = self::findOne($this->parent_id);
        $changingParent = $this->isNewRecord || array_key_exists('parent_id', $this->getDirtyAttributes());

        if (!$parent) {
            $this->addError($attribute, 'والد انتخاب‌شده یافت نشد');
        } elseif ($parent->project_id != $this->project_id) {
            $this->addError($attribute, 'والد باید متعلق به همین پروژه باشد');
        } elseif ($this->id && WbsService::isAncestor($this->id, $this->parent_id)) {
            $this->addError($attribute, 'والد نمی‌تواند از زیرمجموعه‌های همین تسک باشد');
        } elseif ($changingParent && RelationValidator::involves($parent->id)) {
            $this->addError($attribute, 'والد انتخاب‌شده در یک رابطه است و نمی‌تواند زیرمجموعه بگیرد؛ ابتدا رابطه‌هایش را حذف یا جابه‌جا کنید');
        }
    }

    /**
     * لیست تسک‌ها با فیلتر و صفحه‌بندی (برای صفحه‌ی «لیست تسک‌ها»).
     * $perPage = 0 یعنی بدون صفحه‌بندی — برای انتخاب‌گرهای فرم‌ها استفاده می‌شود.
     * $withCpm = false برای انتخاب‌گرها تا محاسبه‌ی CPM برای هر بار باز شدن فرم انجام نشود.
     *
     * چکِ نقش عمداً اینجا نیست: همین متد هم از «لیست تسک‌ها» (2402) صدا زده
     * می‌شود و هم از «تسک‌های قابل گزارش» فاز ۲ که نقشِ فاز ۲ می‌خواهد؛ پس
     * چک به endpoint (لبه‌ی برنامه) سپرده شده تا هر کدام نقشِ خودش را بگیرد.
     */
    public static function get_all($projectId = null, $perPage = null, $withCpm = true)
    {
        $params = ListService::params();
        $projectId = $projectId ?: ($params['project_id'] ?? null);

        $perPage = $perPage ?? (isset($params['per-page']) ? (int) $params['per-page'] : 100);
        $page = max(1, (int) ($params['page'] ?? 1));

        $rows = WbsService::findFiltered($params, $projectId);
        $result = ['success' => true] + ListService::paginate($rows, $perPage, $page);

        return $withCpm ? CpmService::attach($result, $projectId) : $result;
    }

    public static function _view($id)
    {
        User::checkAccess(2402);

        $task = self::find()->with('project')->where(['id' => $id])->one();

        if (!$task) {
            return ['success' => false, 'message' => 'تسک با این شناسه یافت نشد'];
        }

        return ['success' => true, 'data' => $task];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if ($id) {
            User::checkAccess(2403);

            $task = self::findOne($id);

            if (!$task) {
                return ['success' => false, 'message' => 'تسک با این شناسه یافت نشد'];
            }
        } else {
            User::checkAccess(2401);

            $task = new self();
        }

        if (!$task->load($params, '') || !$task->save()) {
            return ['success' => false, 'errors' => $task->errors];
        }

        WbsService::rebuildWbs($task->project_id);

        return ['success' => true, 'data' => $task];
    }

    /**
     * حذف تسک. زیرمجموعه‌ها و روابط آن با ON DELETE CASCADE پاک می‌شوند،
     * بنابراین هیچ رابطه‌ای برای تسک حذف‌شده باقی نمی‌ماند.
     */
    public static function _delete($id)
    {
        User::checkAccess(2404);

        $task = self::findOne($id);

        if (!$task) {
            return ['success' => false, 'message' => 'تسک با این شناسه یافت نشد'];
        }

        // حذفِ گره، کل WBS پروژه را با CASCADE می‌برد؛ پس اصلاً نباید رخ دهد.
        if (ProjectNodeService::isRoot($task)) {
            return ['success' => false, 'message' => 'گرهٔ پروژه قابل حذف نیست؛ همهٔ تسک‌های پروژه زیرمجموعهٔ آن‌اند'];
        }

        $projectId = $task->project_id;
        $descendants = WbsService::countDescendants($id);

        $task->delete();
        WbsService::rebuildWbs($projectId);

        return ['success' => true, 'deleted_descendants' => $descendants];
    }

    public function getProject()
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }
}
