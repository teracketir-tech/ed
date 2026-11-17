<?php

namespace frontend\modules\hq\services;

/**
 * محاسبات خالص مسیر بحرانی (CPM) روی گرافِ آماده‌شده.
 *
 * هیچ وابستگی به Yii یا دیتابیس ندارد تا با داده‌ی آرایه‌ای قابل تست باشد.
 * ورودی از CpmGraphService::build می‌آید: مرتب‌سازی توپولوژیک + ایندکس یال‌ها.
 *
 * پیچیدگی: عبور رو به جلو/عقب O(V+E) است چون یال‌ها از قبل بر پایه‌ی گره ایندکس شده‌اند
 * (به‌جای مقایسه‌ی همه‌ی یال‌ها با همه‌ی گره‌ها که O(V×E) می‌شد).
 */
class CpmCalculator
{
    /** برای مقایسه‌ی اعشاری هنگام تعیین شناوری و مسیر بحرانی */
    private const EPSILON = 0.0001;

    /**
     * همه‌ی محاسبات در یک فراخوانی.
     *
     * @param array $graph خروجی CpmGraphService::build با order غیر-null
     * @return array{project_duration:float, critical_path:array, results:array<int,array>}
     */
    public static function run(array $graph): array
    {
        $order = $graph['order'];
        $duration = $graph['duration'];

        $es = self::forwardPass($order, $graph['incoming']);
        $projectEnd = self::projectEnd($order, $es, $duration);
        $ls = self::backwardPass($order, $duration, $graph['outgoing'], $projectEnd);
        $free = self::freeSlack($order, $graph['outgoing'], $es, $duration, $projectEnd);

        return [
            'project_duration' => $projectEnd,
            'critical_path' => self::criticalPath($order, $es, $ls),
            'results' => self::buildResults($order, $duration, $es, $ls, $free),
        ];
    }

    /** عبور رو به جلو → زودترین شروع (ES) */
    private static function forwardPass(array $order, array $incoming): array
    {
        $es = array_fill_keys($order, 0.0);

        foreach ($order as $id) {
            foreach ($incoming[$id] ?? [] as $edge) {
                $value = $es[$edge['from']] + $edge['offset'];

                if ($value > $es[$id]) {
                    $es[$id] = $value;
                }
            }
        }

        return $es;
    }

    /** طول پروژه = بیشینه‌ی زودترین پایان */
    private static function projectEnd(array $order, array $es, array $duration): float
    {
        $end = 0.0;

        foreach ($order as $id) {
            $end = max($end, $es[$id] + $duration[$id]);
        }

        return $end;
    }

    /** عبور رو به عقب → دیرترین شروع (LS) */
    private static function backwardPass(array $order, array $duration, array $outgoing, $projectEnd): array
    {
        $ls = [];

        foreach ($order as $id) {
            $ls[$id] = $projectEnd - $duration[$id];
        }

        foreach (array_reverse($order) as $id) {
            foreach ($outgoing[$id] ?? [] as $edge) {
                $value = $ls[$edge['to']] - $edge['offset'];

                if ($value < $ls[$id]) {
                    $ls[$id] = $value;
                }
            }
        }

        return $ls;
    }

    /**
     * شناوری آزاد (Free Slack): بیشینه‌ی تأخیری که در شروع این فعالیت می‌دهیم
     * بدون آنکه زودترین شروعِ هیچ جانشینی جلو بیفتد.
     *
     * با قرارداد offset از CpmGraphService برای همه‌ی انواع رابطه یک فرمول واحد است:
     *   FF = مینیممِ (ES_j − ES_i − offset) روی یال‌های خروجی
     * برای FS همان FS معروف: ES_j − EF_i − lag
     *
     * فعالیت بدون جانشین: تا پایانِ پروژه می‌تواند بلغزد.
     */
    private static function freeSlack(array $order, array $outgoing, array $es, array $duration, $projectEnd): array
    {
        $free = [];

        foreach ($order as $id) {
            $edges = $outgoing[$id] ?? [];

            if (!$edges) {
                $free[$id] = $projectEnd - ($es[$id] + $duration[$id]);
                continue;
            }

            $min = null;

            foreach ($edges as $edge) {
                $value = $es[$edge['to']] - $es[$id] - $edge['offset'];
                $min = ($min === null) ? $value : min($min, $value);
            }

            $free[$id] = $min;
        }

        return $free;
    }

    /** تسک‌هایی که شناوری‌شان صفر (یا منفی) است */
    private static function criticalPath(array $order, array $es, array $ls): array
    {
        $ids = [];

        foreach ($order as $id) {
            if ($ls[$id] - $es[$id] <= self::EPSILON) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * نتیجه‌ی CPM هر فعالیتِ بدون زیرمجموعه، با کلید id.
     * واحد همه‌ی مقادیر زمانی، واحدِ پایه‌ی گراف است (روز یا ساعت).
     *
     * @return array<int,array>
     */
    private static function buildResults(array $order, array $duration, array $es, array $ls, array $free): array
    {
        $results = [];

        foreach ($order as $id) {
            $d = $duration[$id];
            $float = $ls[$id] - $es[$id];

            $results[$id] = [
                'duration' => round($d, 2),
                'es' => round($es[$id], 2),
                'ef' => round($es[$id] + $d, 2),
                'ls' => round($ls[$id], 2),
                'lf' => round($ls[$id] + $d, 2),
                'float' => round($float, 2),
                'free_float' => round($free[$id], 2),
                'is_critical' => $float <= self::EPSILON,
            ];
        }

        return $results;
    }
}
