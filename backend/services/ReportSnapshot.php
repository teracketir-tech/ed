<?php

namespace frontend\modules\hq\services;

use frontend\modules\hq\models\DailyReportActivity;
use frontend\modules\hq\models\Task;

/**
 * snapshot مقادیر برنامه‌ای هنگام ثبت گزارش — سند فاز ۲ (بند ۷۹ و ۳۹۲):
 *
 *   «در DB فقط task_id ذخیره شود. کد WBS و عنوان Task از جدول tasks خوانده شوند.
 *     برای گزارش‌های تاریخی می‌توان snapshot کد و عنوان را نیز هنگام ثبت ذخیره کرد،
 *     ولی منبع اصلی همیشه task_id است.»
 *   «اگر Task در WBS تغییر کرده باشد، گزارش‌های قبلی نباید به‌صورت بی‌دلیل بازنویسی شوند.»
 *
 * پس snapshot فقط هنگام «افزودن» یا «تغییرِ خودِ task_id» ساخته می‌شود؛
 * ویرایش بقیه‌ی ستون‌ها تصویر برنامه‌ای ثبت‌شده را دست نمی‌زند.
 *
 * «نوع فعالیت» snapshot ندارد چون جدول `task` ستون task_type ندارد
 * (سند: «در صورت وجود task_type»).
 */
class ReportSnapshot
{
    /** @var array CPM هر پروژه یک‌بار در هر درخواست محاسبه شود */
    private static $cache = [];

    /**
     * نگاشت یک ردیف خروجی CPM به ستون‌های snapshot.
     * کلیدهای خروجی عین نام ستون‌های daily_report_activities هستند.
     */
    public static function fromCpm(array $cpm): array
    {
        return [
            'wbs_code' => $cpm['wbs_code'] ?? null,
            'task_name' => $cpm['name'] ?? null,
            'duration_value' => $cpm['duration'] ?? null,
            'duration_unit' => $cpm['unit'] ?? null,
            'planned_start' => $cpm['start_date'] ?? null,
            'planned_finish' => $cpm['finish_date'] ?? null,
            'free_float' => $cpm['free_float'] ?? null,
            'latest_finish' => $cpm['late_finish_date'] ?? null,
        ];
    }

    /**
     * جایگزین وقتی CPM در دسترس نیست (گراف حلقه دارد یا پروژه هنوز تسک ندارد)؛
     * دست‌کم کد و عنوان از خودِ جدول task برداشته می‌شود تا سند بی‌عنوان نماند.
     */
    public static function fromTask(array $task): array
    {
        return [
            'wbs_code' => $task['wbs_code'] ?? null,
            'task_name' => $task['name'] ?? null,
            'duration_value' => $task['duration'] ?? null,
            'duration_unit' => $task['duration_unit'] ?? null,
            'planned_start' => null,
            'planned_finish' => null,
            'free_float' => null,
            'latest_finish' => null,
        ];
    }

    /**
     * snapshot کامل یک تسک. اگر تسک در پروژه نباشد null برمی‌گردد
     * (مدل باید پیش‌تر خطای «تسک متعلق به این پروژه نیست» داده باشد).
     */
    public static function forTask($projectId, $taskId): ?array
    {
        $task = Task::find()
            ->select(['id', 'wbs_code', 'name', 'duration', 'duration_unit'])
            ->where(['project_id' => $projectId, 'id' => $taskId])
            ->asArray()
            ->one();

        if (!$task) {
            return null;
        }

        $cpm = self::cpm($projectId);

        if (!empty($cpm['success']) && isset($cpm['tasks'][(int) $taskId])) {
            return self::fromCpm($cpm['tasks'][(int) $taskId]);
        }

        return self::fromTask($task);
    }

    /**
     * تاریخ‌هایِ ثبت‌شدهٔ فعالیت‌های یک پروژه را با برنامهٔ تازه هم‌گام می‌کند.
     *
     * وقتی تاریخِ شروعِ پروژه جابه‌جا می‌شود، تاریخِ برنامه‌ایِ ثبت‌شده در
     * گزارش‌هایِ پیشین هم باید حرکت کند؛ وگرنه گزارشِ تاریخی به تاریخی اشاره
     * می‌کند که دیگر برنامه نیست. خودِ کد و عنوانِ تسک دست نمی‌خورد — منبعِ
     * حقیقت همچنان task_id است و snapshotِ کد/عنوان عمداً یک‌بار ساخته می‌شود.
     *
     * این تنها زیرمجموعهٔ snapshot است که بعد از ثبت به‌روزرسانی می‌شود، چون
     * تنها بخشی است که به لنگرِ زمانی وابسته است.
     *
     * @return int تعداد ردیفِ به‌روزشده
     */
    public static function refreshProject($projectId): int
    {
        // تازه محاسبه می‌شود: این متد بعد از ذخیرهٔ start_date صدا زده می‌شود،
        // پس cacheِ داخلیِ درخواستِ جاری ممکن است لنگرِ قدیمی را نگه داشته باشد.
        unset(self::$cache[$projectId]);
        $cpm = CpmService::calculate($projectId);

        if (empty($cpm['success'])) {
            return 0;
        }

        $rows = DailyReportActivity::find()
            ->alias('dra')
            ->innerJoin('daily_reports dr', 'dr.id = dra.daily_report_id')
            ->where(['dr.project_id' => $projectId])
            ->all();

        $columns = ['planned_start' => 'start_date', 'planned_finish' => 'finish_date', 'latest_finish' => 'late_finish_date'];
        $updated = 0;

        foreach ($rows as $row) {
            $task = $cpm['tasks'][(int) $row->task_id] ?? null;

            if ($task === null) {
                continue;
            }

            $changed = false;

            foreach ($columns as $column => $cpmKey) {
                $value = $task[$cpmKey] ?? null;

                if ($row->$column !== $value && ($value !== null || $row->$column !== null)) {
                    $row->$column = $value;
                    $changed = true;
                }
            }

            if ($changed && $row->save(false)) {
                $updated++;
            }
        }

        return $updated;
    }

    /** CPM یک پروژه را یک‌بار در هر درخواست می‌سازد تا ثبت چند فعالیت چند بار حساب نشود */
    private static function cpm($projectId): array
    {
        if (!array_key_exists($projectId, self::$cache)) {
            self::$cache[$projectId] = CpmService::calculate($projectId);
        }

        return self::$cache[$projectId];
    }

    /**
     * پاک‌کردن حافظه نهانِ CPM — برای تست‌ها یا وقتی start_date پروژه تغییر می‌کند
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * ردیفِ خامِ CPMِ یک تسک — برای قاعدهٔ «پنجرهٔ گزارش‌پذیری» (ایشوی ۱۵).
     *
     * null یعنی CPM در دسترس نیست: گرافِ حلقه‌دار یا تسکِ خارج از پروژه.
     */
    public static function cpmRow($projectId, $taskId): ?array
    {
        $cpm = self::cpm($projectId);

        if (empty($cpm['success'])) {
            return null;
        }

        return $cpm['tasks'][(int) $taskId] ?? null;
    }
}
