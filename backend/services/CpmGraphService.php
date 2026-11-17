<?php

namespace frontend\modules\hq\services;

/**
 * آماده‌سازی ورودی محاسبه CPM: تبدیل واحدها، ساخت یال‌ها و مرتب‌سازی توپولوژیک
 *
 * قرارداد روابط (L مقدار lag است؛ منفی یعنی Lead):
 *   FS: ES_s >= ES_p + d_p + L
 *   SS: ES_s >= ES_p + L
 *   FF: ES_s >= ES_p + d_p + L - d_s
 *   SF: ES_s >= ES_p + L - d_s
 */
class CpmGraphService
{
    /**
     * ساخت گراف از روی همه‌ی تسک‌ها و روابطِ پروژه.
     *
     * فقط فعالیت‌های برگ (بدون زیرمجموعه) گره می‌شوند؛ تسکِ والد (summary) طبق استاندارد
     * MS Project در شبکه شرکت نمی‌کند و روابطی که یک سرشان تسکِ والد باشد حذف می‌شوند
     * (ساخت چنین رابطه‌ای از همین حالا در RelationValidator رد می‌شود).
     *
     * @return array{unit:string, duration:array<int,float>, edges:array,
     *               incoming:array<int,array>, outgoing:array<int,array>, order:array|null}
     *         order === null یعنی گراف حلقه دارد.
     *         incoming/outgoing ایندکس یال‌ها بر پایه‌ی گره‌اند تا عبور رو به جلو/عقب
     *         O(V+E) باشد به‌جای O(V×E).
     */
    public static function build($taskRows, $relationRows)
    {
        $leafRows = self::leafRows($taskRows);
        $leafIds = [];

        foreach ($leafRows as $row) {
            $leafIds[(int) $row['id']] = true;
        }

        $relationRows = self::leafRelations($relationRows, $leafIds);

        $unit = DurationUnits::detectBase($leafRows, $relationRows);

        $duration = [];
        foreach ($leafRows as $row) {
            $duration[(int) $row['id']] = DurationUnits::toBase($row['duration'], $row['duration_unit'], $unit);
        }

        $edges = self::buildEdges($relationRows, $duration, $unit);
        list($incoming, $outgoing) = self::indexEdges($edges);

        return [
            'unit' => $unit,
            'duration' => $duration,
            'edges' => $edges,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'order' => self::topologicalSort(array_keys($duration), $edges),
        ];
    }

    /**
     * فقط فعالیت‌های برگ (بدون زیرمجموعه) وارد شبکه می‌شوند.
     *
     * @return array آرایه‌ای از ردیف‌هایی که id آن‌ها در هیچ parent_id دیگری نیامده
     */
    private static function leafRows(array $taskRows): array
    {
        $parentIds = [];

        foreach ($taskRows as $row) {
            if (!empty($row['parent_id'])) {
                $parentIds[(int) $row['parent_id']] = true;
            }
        }

        $leaves = [];

        foreach ($taskRows as $row) {
            if (!isset($parentIds[(int) $row['id']])) {
                $leaves[] = $row;
            }
        }

        return $leaves;
    }

    /**
     * حذف روابطی که یک طرفشان تسکِ والد است (یا اصلاً در این پروژه نیست) تا یالِ بی‌معنا
     * ساخته نشود و تشخیص واحد پایه هم فقط بر اساس واحدهای واقعیِ گراف باشد.
     */
    private static function leafRelations(array $relationRows, array $leafIds): array
    {
        $kept = [];

        foreach ($relationRows as $row) {
            if (isset($leafIds[(int) $row['predecessor_task_id']], $leafIds[(int) $row['successor_task_id']])) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * گروه‌بندی یال‌ها بر اساس گره‌ی مقصد و مبدأ.
     *
     * @return array{0:array<int,array>,1:array<int,array>} [incoming, outgoing]
     */
    private static function indexEdges(array $edges): array
    {
        $incoming = [];
        $outgoing = [];

        foreach ($edges as $edge) {
            $incoming[$edge['to']][] = $edge;
            $outgoing[$edge['from']][] = $edge;
        }

        return [$incoming, $outgoing];
    }

    /** ساخت یال‌های گراف؛ روابط خارج از این پروژه نادیده گرفته می‌شوند */
    private static function buildEdges($relationRows, $duration, $baseUnit)
    {
        $edges = [];

        foreach ($relationRows as $row) {
            $from = (int) $row['predecessor_task_id'];
            $to = (int) $row['successor_task_id'];

            if (!isset($duration[$from], $duration[$to])) {
                continue;
            }

            $edges[] = [
                'from' => $from,
                'to' => $to,
                'offset' => self::offset(
                    $row['relation_type'],
                    $duration[$from],
                    $duration[$to],
                    DurationUnits::toBase($row['lag'], $row['lag_unit'], $baseUnit)
                ),
            ];
        }

        return $edges;
    }

    /** آفست شروع وابسته نسبت به شروع مقدم */
    private static function offset($type, $durPred, $durSucc, $lag)
    {
        switch ($type) {
            case 'SS':
                return $lag;
            case 'FF':
                return $durPred + $lag - $durSucc;
            case 'SF':
                return $lag - $durSucc;
            default: // FS
                return $durPred + $lag;
        }
    }

    /** مرتب‌سازی توپولوژیک (Kahn) — خروجی null یعنی گراف حلقه دارد */
    private static function topologicalSort($nodeIds, $edges)
    {
        $inDegree = array_fill_keys(array_map('intval', $nodeIds), 0);
        $adjacency = [];

        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
            if (isset($inDegree[$edge['to']])) {
                $inDegree[$edge['to']]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, function ($d) { return $d === 0; }));
        sort($queue);
        $order = [];

        while ($queue) {
            $current = array_shift($queue);
            $order[] = $current;

            foreach ($adjacency[$current] ?? [] as $next) {
                if (--$inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return count($order) === count($inDegree) ? $order : null;
    }
}
