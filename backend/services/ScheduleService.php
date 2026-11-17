<?php

namespace frontend\modules\hq\services;

/**
 * ساخت ردیف زمان‌بندی هر تسک برای نمایش (لیست و خروجی اکسل) — فقط منطق درخت و rollup.
 *
 * رفتار «عین اکسل / MS Project»:
 *   - تسکِ بدون زیرمجموعه (leaf) همان نتیجه‌ی CPM را می‌گیرد.
 *   - تسکِ والد (summary) در شبکه‌ی CPM شرکت نمی‌کند و مقادیرش rollup فرزندان است:
 *       شروع           = کمینه‌ی شروع فرزندان
 *       پایان          = بیشینه‌ی پایان فرزندان
 *       مدت            = پایان − شروع
 *       دیرترین شروع   = کمینه‌ی دیرترین شروع فرزندان
 *       دیرترین پایان   = بیشینه‌ی دیرترین پایان فرزندان
 *       شناوری         = کمینه‌ی شناوری فرزندان (تا والدِ حاوی فعالیت بحرانی، بحرانی شود)
 *       شناوری آزاد    = کمینه‌ی شناوری آزاد فرزندان
 *       درصد پیشرفت    = میانگینِ ساده‌ی درصد پیشرفت فرزندان
 *
 * مونتاژ نهایی ردیف و تاریخ‌ها بر عهده‌ی ScheduleRow است.
 * این کلاس خالص است (Yii را لازم ندارد) تا با داده‌ی آرایه‌ای قابل تست باشد.
 */
class ScheduleService
{
    /** برای مقایسه‌ی اعشاری هنگام تعیین بحرانی بودن */
    private const EPSILON = 0.0001;

    /**
     * @param array $taskRows    همه‌ی تسک‌های پروژه (id, parent_id, wbs_code, name, duration, duration_unit)
     * @param array $leafResults نتیجه‌ی CPM برای هر تسکِ بدون زیرمجموعه، کلید = id
     * @param string $unit       واحد پایه‌ی CPM: 'day' یا 'hour'
     * @param string|null $projectStartDate تاریخ شروع پروژه (برای تبدیل به تاریخ)
     * @param array<int,float> $progress درصد پیشرفت هر برگ از گزارش‌های روزانه
     *
     * @return array<int,array> خروجی با کلید id؛ هر ردیف آماده‌ی نمایش است
     */
    public static function build(array $taskRows, array $leafResults, $unit, $projectStartDate, array $progress = []): array
    {
        $byId = [];
        $children = [];
        $parentIds = [];

        foreach ($taskRows as $row) {
            $id = (int) $row['id'];
            $byId[$id] = $row;
            $parentId = (int) ($row['parent_id'] ?? 0);
            $children[$parentId][] = $id;

            if ($parentId > 0) {
                $parentIds[$parentId] = true;
            }
        }

        $raw = self::walk($children, $parentIds, $leafResults, $progress);
        $unitCode = DurationUnits::code($unit);
        $out = [];

        foreach ($byId as $id => $row) {
            // تسکِ دست‌نخورده (مثلاً زنجیره‌ی parent_id خراب) — به‌جای حذف، ردیف خالی می‌دهیم
            $value = $raw[$id] ?? null;

            if ($value === null) {
                $value = $leafResults[$id] ?? ScheduleRow::blank($unitCode);
                $value['progress'] = $progress[$id] ?? 0.0;
            }

            $out[$id] = ScheduleRow::make($row, $value, isset($parentIds[$id]), $unitCode, $projectStartDate);
        }

        return $out;
    }

    /**
     * پیمایش پس‌ترتیب (iterative تا عمق درخت محدودیت نداشته باشد):
     * ابتدا فرزندان، بعد والدشان rollup می‌شود.
     *
     * @return array<int,array> مقادیر خام هر گره (بدون شناسه، نام و تاریخ)
     */
    private static function walk(array $children, array $parentIds, array $leafResults, array $progress): array
    {
        $done = [];
        $stack = [];
        $total = 0;

        foreach ($children as $siblings) {
            $total += count($siblings);
        }

        foreach ($children[0] ?? [] as $id) {
            $stack[] = [$id, false];
        }

        // هر گره حداکثر دو بار روی پشته می‌رود (بدون بازدید + با بازدید)
        $guard = 0;
        $limit = $total * 3 + 100;

        while ($stack && $guard++ < $limit) {
            list($id, $visited) = array_pop($stack);

            if ($visited) {
                if (isset($parentIds[$id])) {
                    $done[$id] = self::rollup($id, $children, $done);
                } else {
                    $done[$id] = $leafResults[$id] ?? ScheduleRow::blank(null);
                    // درصد پیشرفتِ برگ از گزارش‌های روزانه می‌آید؛ نبودِ گزارش صفر است
                    $done[$id]['progress'] = $progress[$id] ?? 0.0;
                }
                continue;
            }

            if (isset($done[$id])) {
                continue;
            }

            $stack[] = [$id, true];

            foreach ($children[$id] ?? [] as $childId) {
                if (!isset($done[$childId])) {
                    $stack[] = [$childId, false];
                }
            }
        }

        return $done;
    }

    /** rollup فرزندان مستقیمِ یک گره والد */
    private static function rollup($id, array $children, array $done): array
    {
        $agg = null;
        $count = 0;

        foreach ($children[$id] ?? [] as $childId) {
            $child = $done[$childId] ?? null;

            if ($child === null) {
                continue;
            }

            if ($agg === null) {
                $agg = self::pick($child);
                $count = 1;
                continue;
            }

            $agg = [
                'es' => min($agg['es'], $child['es']),
                'ef' => max($agg['ef'], $child['ef']),
                'ls' => min($agg['ls'], $child['ls']),
                'lf' => max($agg['lf'], $child['lf']),
                'float' => min($agg['float'], $child['float']),
                'free_float' => min($agg['free_float'], $child['free_float']),
                'progress' => $agg['progress'] + ($child['progress'] ?? 0),
            ];
            $count++;
        }

        if ($agg === null) {
            return ScheduleRow::blank(null);
        }

        $agg['duration'] = $agg['ef'] - $agg['es'];
        $agg['is_critical'] = $agg['float'] <= self::EPSILON;
        // میانگینِ ساده‌ی فرزندان — همان قاعده‌ی رول‌آپِ بقیه‌ی ستون‌های مقایسه‌ای
        $agg['progress'] = round($agg['progress'] / max(1, $count), 2);

        return $agg;
    }

    /** فقط ستون‌های مقایسه‌ای rollup را از یک ردیف برمی‌دارد */
    private static function pick(array $row): array
    {
        return [
            'es' => $row['es'],
            'ef' => $row['ef'],
            'ls' => $row['ls'],
            'lf' => $row['lf'],
            'float' => $row['float'],
            'free_float' => $row['free_float'],
            'progress' => $row['progress'] ?? 0.0,
        ];
    }
}
