<?php

/**
 * تست مستقیم ریاضیات CPM / rollup / تاریخ — بدون دیتابیس و بدون Yii.
 *
 * اجرا:  php frontend/modules/hq/tests/ScheduleMathTest.php
 * در صورت خطا exit code غیر صفر برمی‌گرداند.
 */

$services = dirname(__DIR__) . '/services';

require $services . '/DurationUnits.php';
require $services . '/CpmGraphService.php';
require $services . '/CpmCalculator.php';
require $services . '/ScheduleRow.php';
require $services . '/ScheduleService.php';

use frontend\modules\hq\services\CpmCalculator;
use frontend\modules\hq\services\CpmGraphService;
use frontend\modules\hq\services\ScheduleService;

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $failures, $checks;
    $checks++;

    if (!$condition) {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

function near($actual, $expected, $label)
{
    ok(abs(((float) $actual) - ((float) $expected)) < 0.001, "$label (expected $expected, got $actual)");
}

/** آماده‌سازی گراف + محاسبه + rollup در یک فراخوانی */
function schedule(array $taskRows, array $relationRows, $createdAt = null)
{
    $graph = CpmGraphService::build($taskRows, $relationRows);
    ok($graph['order'] !== null, 'گراف نباید حلقه داشته باشد');

    $calculated = CpmCalculator::run($graph);

    return [
        'calculated' => $calculated,
        'tasks' => ScheduleService::build($taskRows, $calculated['results'], $graph['unit'], $createdAt),
    ];
}

function task($id, $duration, $parentId = null, $unit = 1)
{
    return [
        'id' => $id, 'parent_id' => $parentId, 'wbs_code' => (string) $id,
        'name' => "T$id", 'duration' => $duration, 'duration_unit' => $unit,
    ];
}

function edge($from, $to, $type = 'FS', $lag = 0, $lagUnit = 1)
{
    return [
        'predecessor_task_id' => $from, 'successor_task_id' => $to,
        'relation_type' => $type, 'lag' => $lag, 'lag_unit' => $lagUnit,
    ];
}


// ---------------------------------------------------------------- زنجیره‌ی FS ساده
echo "test: زنجیره FS ساده (3 → 5 → 2)\n";

$result = schedule(
    [task(1, 3), task(2, 5), task(3, 2)],
    [edge(1, 2), edge(2, 3)],
    '2026-10-03 17:52:29'
);

near($result['calculated']['project_duration'], 10, 'طول پروژه = 10');
ok($result['calculated']['critical_path'] === [1, 2, 3], 'مسیر بحرانی = همه (زنجیره‌ی بدون شناوری)');

$tasks = $result['tasks'];
near($tasks[1]['es'], 0, 'ES1 = 0');
near($tasks[1]['ef'], 3, 'EF1 = 3');
near($tasks[2]['es'], 3, 'ES2 = 3');
near($tasks[3]['ef'], 10, 'EF3 = 10');
near($tasks[3]['lf'], 10, 'LF3 = 10');

foreach ([1, 2, 3] as $id) {
    near($tasks[$id]['float'], 0, "شناوری کل T$id = 0");
    near($tasks[$id]['free_float'], 0, "شناوری آزاد T$id = 0");
    ok($tasks[$id]['is_critical'] === true, "T$id بحرانی است");
    ok($tasks[$id]['is_summary'] === false, "T$id خلاصه نیست");
}

ok($tasks[1]['duration'] == 3 && $tasks[1]['unit'] == 1, 'مدت/واحد leaf همان ورودی کاربر است');
ok($tasks[1]['start_date'] === '2026-10-03', 'شروع T1 = 2026-10-03');
ok($tasks[1]['finish_date'] === '2026-10-06', 'پایان T1 = 2026-10-06 (شروع + 3)');

// قرارداد smaple.xlsx: پایان − شروع = مدت  (پایان = شروع + مدت)
foreach ([1, 2, 3] as $id) {
    $gap = (strtotime($tasks[$id]['finish_date']) - strtotime($tasks[$id]['start_date'])) / 86400;
    ok(abs($gap - $tasks[$id]['duration']) < 0.001, "T$id: پایان − شروع = مدت");
}
ok($tasks[3]['finish_date'] === '2026-10-13', 'پایان T3 = 2026-10-13 (شروع + 10)');


// ---------------------------------------------------------------- rollup والد
echo "test: rollup ردیف والد (مدت والد نادیده گرفته می‌شود)\n";

$result = schedule(
    [task(10, 99), task(11, 3, 10), task(12, 5, 10), task(13, 2)],
    [edge(11, 12), edge(12, 13)],
    '2026-10-03 17:52:29'
);

$tasks = $result['tasks'];
$parent = $tasks[10];

ok($parent['is_summary'] === true, 'تسک 10 summary است');
near($parent['duration'], 8, 'مدت والد = rollup فرزندان (3+5 پیوسته) نه مقدار ذخیره‌شده 99');
near($parent['es'], 0, 'شروع والد = کمینه‌ی شروع فرزندان');
near($parent['ef'], 8, 'پایان والد = بیشینه‌ی پایان فرزندان');
near($parent['ls'], 0, 'دیرترین شروع والد = کمینه‌ی دیرترین شروع فرزندان');
near($parent['lf'], 8, 'دیرترین پایان والد = بیشینه‌ی دیرترین پایان فرزندان');
ok($parent['unit'] == 1, 'واحد والد از واحد پایه‌ی CPM می‌آید');
ok($parent['start_date'] === '2026-10-03', 'شروع والد');
ok($parent['finish_date'] === '2026-10-11', 'پایان والد = شروع + 8');

ok($tasks[11]['is_summary'] === false, 'فرزند 11 خلاصه نیست');
near($tasks[11]['duration'], 3, 'مدت فرزند 11 دست‌نخورده');
ok($tasks[13]['is_summary'] === false, 'تسک 13 خلاصه نیست');


// ---------------------------------------------------------------- رابطه روی والد حذف می‌شود
echo "test: رابطه‌ای که یک سرش تسکِ والد است حذف می‌شود\n";

$result = schedule(
    [task(1, 2), task(2, 99), task(3, 4, 2)],
    [edge(1, 2)],
    '2026-10-03 17:52:29'
);

$tasks = $result['tasks'];

ok($tasks[2]['is_summary'] === true, 'تسک 2 والد است');
near($tasks[2]['duration'], 4, 'مدت والد 2 = rollup فرزندش');
ok(!in_array(2, $result['calculated']['critical_path'], true), 'والد در مسیر بحرانی نیست');

// اگر رابطه حذف نمی‌شد، T1 به زیردرختِ T2 گره می‌خورد و شناوری‌اش صفر می‌شد
near($tasks[1]['float'], 2, 'شناوری T1 = 2 چون رابطه‌اش با والد نادیده گرفته شد');
ok($tasks[1]['is_critical'] === false, 'T1 بحرانی نیست');
ok($result['calculated']['critical_path'] === [3], 'مسیر بحرانی فقط T3 است');
near($result['calculated']['project_duration'], 4, 'طول پروژه = 4 (والد گره نیست)');


// ---------------------------------------------------------------- شناوری مثبت + شناوری آزاد
echo "test: شناوری کل و آزاد با مسیر جایگزین\n";

$result = schedule(
    [task(1, 3), task(2, 2), task(4, 1)],
    [edge(1, 2), edge(4, 2)]
);

$tasks = $result['tasks'];

ok($result['calculated']['critical_path'] === [1, 2], 'مسیر بحرانی = 1 → 2');
near($tasks[4]['es'], 0, 'ES مسیر کوتاه = 0');
near($tasks[4]['float'], 2, 'شناوری کل فعالیت کوتاه = 2');
near($tasks[4]['free_float'], 2, 'شناوری آزاد فعالیت کوتاه = 2');
ok($tasks[4]['is_critical'] === false, 'فعالیت کوتاه بحرانی نیست');
near($result['calculated']['project_duration'], 5, 'طول پروژه = 5');


// ---------------------------------------------------------------- انواع رابطه
echo "test: قرارداد SS / FF / SF\n";

$result = schedule([task(1, 3), task(2, 5)], [edge(1, 2, 'SS')]);
near($result['tasks'][2]['es'], 0, 'SS: هر دو همزمان شروع می‌شوند');
near($result['calculated']['project_duration'], 5, 'SS: طول پروژه از فعالیت طولانی‌تر');

// FF فقط وقتی واقعاً قیدِ حاکم است که فعالیت اول طولانی‌تر از حدِ شروعِ دومی باشد
$result = schedule(
    [task(1, 3), task(3, 10), task(2, 4)],
    [edge(1, 2, 'FS'), edge(3, 2, 'FF')]
);
near($result['tasks'][2]['es'], 6, 'FF: ES دومی = ES اولی + d1 - d2 = 10 - 4');
near($result['tasks'][2]['ef'], 10, 'FF: هر دو همزمان تمام می‌شوند');
near($result['tasks'][3]['ef'], 10, 'FF: فعالیت مقدم هم ساعت 10 تمام می‌شود');

// SF: شروعِ فعالیت اول باید از کفِ صفر جلوتر باشد تا قید دیده شود
$result = schedule(
    [task(5, 5), task(1, 3), task(2, 4)],
    [edge(5, 1, 'FS'), edge(1, 2, 'SF')]
);
near($result['tasks'][1]['es'], 5, 'SF: شروع T1 = 5');
near($result['tasks'][2]['ef'], 5, 'SF: پایان دومی = شروع اولی');
near($result['tasks'][2]['es'], 1, 'SF: ES دومی = 5 - 4');

$result = schedule([task(1, 3), task(2, 5)], [edge(1, 2, 'FS', 2)]);
near($result['tasks'][2]['es'], 5, 'FS + lag2: شروع دومی 3+2 = 5');

$result = schedule([task(1, 3), task(2, 5)], [edge(1, 2, 'FS', -1)]);
near($result['tasks'][2]['es'], 2, 'FS + lead1: شروع دومی 3-1 = 2');


// ---------------------------------------------------------------- واحدهای مختلط
echo "test: تشخیص واحد پایه\n";

$mixed = schedule([task(1, 8, null, 2), task(2, 1, null, 1)], [edge(1, 2)]);
near($mixed['tasks'][2]['es'], 1, '۸ ساعت = ۱ روز وقتی واحد پایه روز است');

$allHours = schedule([task(1, 8, null, 2), task(2, 8, null, 2)], [edge(1, 2, 'FS', 0, 2)]);
near($allHours['tasks'][2]['es'], 8, 'واحد پایه ساعت وقتی همه ساعت‌اند');


// ---------------------------------------------------------------- گراف حلقه‌دار
echo "test: تشخیص حلقه\n";

$graph = CpmGraphService::build(
    [task(1, 3), task(2, 5)],
    [edge(1, 2), edge(2, 1)]
);
ok($graph['order'] === null, 'گراف حلقه‌دار باید order === null بدهد');


echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
