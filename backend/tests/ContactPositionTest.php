<?php

/**
 * Contacts: issue 2 — "type" is always the legal/real-person pair and the
 * job position moves to `contact.position_id`, on the API and on the panel.
 *
 * 2.1 `contact.type` -> always 1 (real person) / 2 (legal entity)
 * 2.2 a new optional "position" field bound to `contact.position_id`
 * 2.3 daily-report person selects filter by position (and by company)
 *
 * Source-inspection only — no DB, no Yii, same style as the rest of the suite.
 *
 * Run:  php frontend/modules/hq/tests/ContactPositionTest.php
 * Exits non-zero when a check fails.
 */

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

function is($actual, $expected, $label)
{
    ok($actual === $expected, "$label (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")");
}

$repo = dirname(__DIR__, 6);
$src = $repo . '/frontend/hq/src';

$read = function ($path) use ($repo) {
    $file = $repo . $path;
    ok(file_exists($file), "file exists: $path");

    return file_exists($file) ? file_get_contents($file) : '';
};

// ---------------------------------------------------------------------
// Backend: `contact.position_id` must be assignable, filterable and
// nullable. An empty select would otherwise reach MySQL as '' and either
// error under strict mode or be stored as 0 ("no position" must be NULL).
// ---------------------------------------------------------------------
$model = $read('/backend/apiv2.payegan.group/frontend/modules/hq/models/Contact.php');

ok(
    preg_match("/\\[\\[[^\\]]*'position_id'[^\\]]*\\], 'integer'\\]/", $model) === 1,
    'Contact rules validate position_id as an integer (so Yii treats it as safe on load)'
);
ok(
    strpos($model, "in_array(\$key, ['id', 'company_id', 'type', 'position_id'])") !== false,
    'get_all() matches position_id exactly — a LIKE "%1%" would also return 10, 11 and 21'
);
ok(
    strpos($model, "\$params['position_id'] = null") !== false,
    "_save() turns an empty position_id into NULL instead of ''"
);

// ---------------------------------------------------------------------
// Frontend: "type" has no company-dependent option list any more.
// ---------------------------------------------------------------------
$positions = $read('/frontend/hq/src/lib/positions.ts');

ok(
    strpos($positions, 'export function typeOptions()') !== false,
    'typeOptions() takes no company argument any more'
);
ok(
    strpos($positions, 'export function positionOptions()') !== false,
    'positionOptions() exposes the position list for the new field and filter'
);
ok(
    strpos($positions, 'COMPANY_LEGAL_PERSON') === false,
    'the company-dependent branch of "type" is gone'
);

// ---------------------------------------------------------------------
// Contact form: the type select is fixed and the new position select is
// optional. Changing the company must not clear "type" any more.
// ---------------------------------------------------------------------
$form = $read('/frontend/hq/src/pages/contact/create/index.tsx');

ok(strpos($form, 'options={typeOptions()}') !== false, 'form type select uses the fixed two-value list');
ok(strpos($form, 'name="position_id"') !== false, 'form has a position_id field');
ok(strpos($form, 'options={positionOptions()}') !== false, 'form position select is built from positionOptions()');
ok(
    strpos($form, 'setFieldValue("type", "")') === false,
    'changing the company no longer clears the type field'
);
ok(
    strpos($form, 'position_id: ""') !== false,
    'position_id starts as an empty value so a new contact saves NULL'
);

// ---------------------------------------------------------------------
// Contact list: the type column lost its company argument and a position
// column was added next to it.
// ---------------------------------------------------------------------
$list = $read('/frontend/hq/src/pages/contact/index.tsx');

ok(strpos($list, 'typeLabel(contact.type)') !== false, 'type label no longer depends on company_id');
ok(strpos($list, 'positionLabel(contact.position_id)') !== false, 'list shows the position column');
ok(
    substr_count($list, 'grid-cols-10') >= 2,
    'header and rows both lay out ten columns'
);

$filter = $read('/frontend/hq/src/components/pages/contact/Filter.tsx');

ok(strpos($filter, 'options={typeOptions()}') !== false, 'type filter uses the fixed two-value list');
ok(strpos($filter, 'name="position_id"') !== false, 'filter gained a position field');
ok(strpos($filter, 'position_id: ""') !== false, 'position filter starts empty');

// ---------------------------------------------------------------------
// Contact options carry the position now: that is what the daily-report
// selects compare against.
// ---------------------------------------------------------------------
$contacts = $read('/frontend/hq/src/lib/contacts.ts');

ok(strpos($contacts, 'position?: string;') !== false, 'ContactOption exposes the position');
ok(strpos($contacts, 'type?: string;') === false, 'ContactOption no longer exposes the type column');
ok(
    strpos($contacts, 'position_id=${position}') !== false,
    'loadContactOptions() can filter the API by position_id'
);

$select = $read('/frontend/hq/src/components/shared/ContactSelect.tsx');

ok(strpos($select, 'position?: number | null') !== false, 'ContactSelect accepts a position filter');
ok(
    strpos($select, 'loadContactOptions(type, formik?.values?.company_id, position)') !== false,
    'ContactSelect forwards the position filter to the API'
);

// ---------------------------------------------------------------------
// Daily report: every person select filters by position, never by type.
// ---------------------------------------------------------------------
$header = $read('/frontend/hq/src/components/pages/daily-report/HeaderPanel.tsx');

ok(strpos($header, 'position={POSITION_MANAGER}') !== false, 'project manager selects by position');
ok(strpos($header, 'position={POSITION_SUPERVISOR}') !== false, 'site supervisor selects by position');
ok(
    strpos($header, 'type={POSITION_') === false,
    'the report header no longer filters the API on contact.type'
);

$activities = $read('/frontend/hq/src/components/pages/daily-report/activities/ActivityFields.tsx');

ok(
    strpos($activities, 'contact.position === String(position)') !== false,
    'executor/supervisor options are cut from the contact position'
);
ok(
    strpos($activities, 'contact.type === String(position)') === false,
    'the activity form no longer compares the type column against a position'
);

$lineForm = $read('/frontend/hq/src/components/pages/daily-report/lines/LineForm.tsx');

ok(
    strpos($lineForm, 'option.position === String(') !== false,
    'line form filterBy compares the contact position'
);
ok(
    strpos($lineForm, 'option.type === String(') === false,
    'line form no longer compares the type column against a position'
);

$dictionaries = $read('/frontend/hq/src/components/shared/dictionaries.ts');

ok(strpos($dictionaries, 'position?: string;') !== false, 'the shared option type carries the position');

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
