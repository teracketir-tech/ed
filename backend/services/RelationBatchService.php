<?php

namespace frontend\modules\hq\services;

use Yii;
use frontend\modules\hq\models\TaskRelation;
use frontend\modules\hq\models\User;

/**
 * Multi-successor write for the task-relation form — used by **both** pages.
 *
 * The form may post `successor_task_ids` (a real array or a comma separated
 * string — both shapes are used for multi fields in this codebase) instead of
 * a single `successor_task_id`; one `task_relation` row is then written per
 * successor inside a single DB transaction.
 *
 *  - `create()` — plain POST: every successor becomes a new row.
 *  - `replace()` — PUT on one stored row: the whole multi-successor group it
 *    belongs to (same predecessor / type / lag) is dropped and rebuilt from
 *    the chosen successor list. Feedback 3 asked for multi-select on the edit
 *    page too; a stored row only ever holds ONE successor, so "editing into
 *    several" necessarily means "rewrite the rows into these rows".
 *
 * Every row goes through TaskRelation::validate(), which runs
 * RelationValidator::pairErrors() (via validatePair) and
 * RelationValidator::cycleError() (via validateNoCycle), so a batch row is
 * held to exactly the same standard as a single create.
 *
 * A pair that is already stored (same predecessor / successor / relation_type,
 * i.e. the table's unique key) is skipped instead of failing the request.
 */
class RelationBatchService
{
    /**
     * Create one relation row per successor.
     *
     * @param array         $params        raw POST body, loaded into every model
     * @param array|string  $successorIds  array of ids, or a comma separated string
     * @return array API response: success / data / created / skipped, or errors
     */
    public static function create(array $params, $successorIds): array
    {
        $ids = self::successorIds($successorIds);

        if (!$ids) {
            return [
                'success' => false,
                'errors' => ['successor_task_ids' => 'لطفا حداقل یک تسک وابسته را انتخاب کنید'],
            ];
        }

        $transaction = Yii::$app->db->beginTransaction();
        $created = [];
        $skipped = 0;
        $errors = [];

        try {
            self::insertRows($params, $ids, $created, $skipped, $errors);

            // One bad pair rolls the whole batch back — all or nothing.
            if ($errors) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => ['successor_task_ids' => implode('؛ ', $errors)]];
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'data' => $created,
            'created' => count($created),
            'skipped' => $skipped,
        ];
    }

    /**
     * Rewrite the stored group into one row per chosen successor (feedback 3).
     *
     * The old rows go first, on purpose: `validateNoCycle` would see their own
     * edge as a cycle for the successor that is kept, and the unique key would
     * reject the very pair the user just confirmed. Everything happens inside
     * one transaction, so any rejected row restores the originals.
     *
     * Creating rows is what `create` is for, so the edit permission alone is
     * not enough here — the caller is about to mint new relations.
     *
     * @param TaskRelation  $relation      the row being edited
     * @param array         $params        raw POST body, loaded into every new model
     * @param array|string  $successorIds  array of ids, or a comma separated string
     * @return array API response: success / data / created / skipped, or errors
     */
    public static function replace(TaskRelation $relation, array $params, $successorIds): array
    {
        User::checkAccess(2405);

        $ids = self::successorIds($successorIds);

        if (!$ids) {
            return [
                'success' => false,
                'errors' => ['successor_task_ids' => 'لطفا حداقل یک تسک وابسته را انتخاب کنید'],
            ];
        }

        $transaction = Yii::$app->db->beginTransaction();
        $created = [];
        $skipped = 0;
        $errors = [];

        try {
            // ایشوی ۶: سرور حالا کلِ گروه (همهٔ وابسته‌های همان مقدم/نوع/تاخیر)
            // را بازنویسی می‌کند، نه فقط سطری که فرم از آن باز شد؛ وگرنه
            // وابستهٔ حذف‌شده از فرم، در دیتابیس می‌ماند.
            foreach (TaskRelation::groupRows($relation) as $row) {
                $row->delete();
            }

            self::insertRows($params, $ids, $created, $skipped, $errors);

            if ($errors) {
                $transaction->rollBack();

                return ['success' => false, 'errors' => ['successor_task_ids' => implode('؛ ', $errors)]];
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'data' => $created,
            'created' => count($created),
            'skipped' => $skipped,
        ];
    }

    /**
     * The shared write loop of both entry points (DRY).
     *
     * Already-stored pairs are skipped on purpose, never an error — that is
     * what makes "add three successors of which one exists" a success.
     */
    private static function insertRows(array $params, array $ids, array &$created, int &$skipped, array &$errors): void
    {
        foreach ($ids as $successorId) {
            $relation = new TaskRelation();

            $relation->load($params, '');
            $relation->successor_task_id = $successorId;

            $valid = $relation->validate();

            if (self::isDuplicate($relation)) {
                $skipped++;
                continue;
            }

            if (!$valid) {
                $errors = array_merge($errors, self::messages($relation));
                continue;
            }

            if ($relation->save(false)) {
                $created[] = $relation;
            } else {
                $errors = array_merge($errors, self::messages($relation));
            }
        }
    }

    /**
     * Normalise the posted value into a list of unique positive ids.
     * Accepts a real array or a comma separated string.
     *
     * @return int[] empty when nothing usable is posted
     */
    private static function successorIds($raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part !== '' && ctype_digit($part) && !in_array((int) $part, $ids, true)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    /** Does this exact pair already exist? (the unique key of `task_relation`) */
    private static function isDuplicate(TaskRelation $relation): bool
    {
        return TaskRelation::find()->where([
            'predecessor_task_id' => $relation->predecessor_task_id,
            'successor_task_id' => $relation->successor_task_id,
            'relation_type' => $relation->relation_type,
        ])->exists();
    }

    /** Every validation message of one row, flattened for the API response */
    private static function messages(TaskRelation $relation): array
    {
        $messages = [];

        foreach ($relation->errors as $list) {
            $messages = array_merge($messages, $list);
        }

        return $messages;
    }
}
