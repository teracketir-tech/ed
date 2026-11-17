<?php

namespace frontend\modules\hq\models;

use common\behaviors\AuditBehavior;
use frontend\modules\dash\models\Sms;
use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use yii\data\ActiveDataProvider;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

const KEY = 'F118XbpY1sqw1v5Pg6yN5VFoezPvTKuA/NkUvAZ3TIg=';

class User extends ActiveRecord implements IdentityInterface
{

    /**
     * The permission that lets one user sign into the panel as another.
     *
     * It is separate from every read of the list on purpose: seeing a row is
     * not the same as taking over its session.
     */

    public const roles = [
        [
            'key' => 'company',
            'name' => 'شرکت',
            'children' => [
                ['id' => 101, 'key' => 101, 'name' => 'شرکت جدید'],
                ['id' => 102, 'key' => 102, 'name' => 'لیست شرکت ها'],
                ['id' => 103, 'key' => 103, 'name' => 'ویرایش'],
                ['id' => 104, 'key' => 104, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'project',
            'name' => 'پروژه',
            'children' => [
                ['id' => 201, 'key' => 201, 'name' => 'پروژه جدید'],
                ['id' => 202, 'key' => 202, 'name' => 'لیست پروژه ها'],
                ['id' => 203, 'key' => 203, 'name' => 'ویرایش'],
                ['id' => 204, 'key' => 204, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'scheduling',
            'name' => 'تسک',
            'children' => [
                ['id' => 2401, 'key' => 2401, 'name' => 'تسک جدید'],
                ['id' => 2402, 'key' => 2402, 'name' => 'لیست تسک ها'],
                ['id' => 2403, 'key' => 2403, 'name' => 'ویرایش'],
                ['id' => 2404, 'key' => 2404, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'task_relation',
            'name' => 'رابطه تسک ها',
            'children' => [
                ['id' => 2405, 'key' => 2405, 'name' => 'رابطه جدید'],
                ['id' => 2406, 'key' => 2406, 'name' => 'لیست رابطه ها'],
                ['id' => 2407, 'key' => 2407, 'name' => 'ویرایش'],
                ['id' => 2408, 'key' => 2408, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'daily_report',
            'name' => 'گزارش روزانه',
            'children' => [
                ['id' => 2411, 'key' => 2411, 'name' => 'گزارش جدید'],
                ['id' => 2412, 'key' => 2412, 'name' => 'لیست گزارش‌ها'],
                ['id' => 2413, 'key' => 2413, 'name' => 'ویرایش'],
                ['id' => 2414, 'key' => 2414, 'name' => 'حذف'],
            ]
        ],
        // 2026-10-27: four groups the panel's routes already used but this
        // tree never listed. Without them the roles screen cannot grant the
        // codes at all — the sidebar stays hidden for everyone unless an admin
        // edits `group.roles` by hand, which is exactly what had to be done.
        // (The backend-only codes 1003/1004, 1201-1204 and 2422 are still
        // absent here: they collide with existing entries and belong to the
        // known backend/tree mismatch, not to this fix.)
        [
            'key' => 'equipment',
            'name' => 'ماشین‌آلات',
            'children' => [
                ['id' => 2501, 'key' => 2501, 'name' => 'ماشین‌آلات جدید'],
                ['id' => 2502, 'key' => 2502, 'name' => 'لیست ماشین‌آلات'],
                ['id' => 2503, 'key' => 2503, 'name' => 'ویرایش'],
                ['id' => 2504, 'key' => 2504, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'deviation_reason',
            'name' => 'عوامل انحراف',
            'children' => [
                ['id' => 2601, 'key' => 2601, 'name' => 'عامل انحراف جدید'],
                ['id' => 2602, 'key' => 2602, 'name' => 'لیست عوامل انحراف'],
                ['id' => 2603, 'key' => 2603, 'name' => 'ویرایش'],
                ['id' => 2604, 'key' => 2604, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'bank',
            'name' => 'بانک',
            'children' => [
                ['id' => 301, 'key' => 301, 'name' => 'بانک جدید'],
                ['id' => 302, 'key' => 302, 'name' => 'لیست بانک ها'],
                ['id' => 303, 'key' => 303, 'name' => 'ویرایش'],
                ['id' => 304, 'key' => 304, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'contact',
            'name' => 'اشخاص',
            'children' => [
                ['id' => 401, 'key' => 401, 'name' => 'شخص جدید'],
                ['id' => 402, 'key' => 402, 'name' => 'لیست اشخاص'],
                ['id' => 405, 'key' => 405, 'name' => 'لیست اشخاص کاربر'],
                ['id' => 403, 'key' => 403, 'name' => 'ویرایش'],
                ['id' => 404, 'key' => 404, 'name' => 'حذف'],
            ]
        ],
        // [
        //     'key' => 'contact_position',
        //     'name' => 'سمت اشخاص',
        //     'children' => [
        //         ['id' => 2701, 'key' => 2701, 'name' => 'سمت اشخاص جدید'],
        //         ['id' => 2702, 'key' => 2702, 'name' => 'لیست سمت اشخاص'],
        //         ['id' => 2703, 'key' => 2703, 'name' => 'ویرایش'],
        //         ['id' => 2704, 'key' => 2704, 'name' => 'حذف'],
        //     ]
        // ],
        [
            'key' => 'product',
            'name' => 'کالا',
            'children' => [
                ['id' => 901, 'key' => 901, 'name' => 'کالا جدید'],
                ['id' => 902, 'key' => 902, 'name' => 'لیست کالاها'],
                ['id' => 903, 'key' => 903, 'name' => 'ویرایش'],
                ['id' => 904, 'key' => 904, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'unit',
            'name' => 'واحد',
            'children' => [
                ['id' => 911, 'key' => 911, 'name' => 'واحد جدید'],
                ['id' => 912, 'key' => 912, 'name' => 'لیست واحدها'],
                ['id' => 913, 'key' => 913, 'name' => 'ویرایش'],
                ['id' => 914, 'key' => 914, 'name' => 'حذف'],
            ]
        ],
        // [
        //     'key' => 'category',
        //     'name' => 'دسته‌بندی کالاها',
        //     'children' => [
        //         ['id' => 1101, 'key' => 1101, 'name' => 'دسته‌بندی جدید'],
        //         ['id' => 1102, 'key' => 1102, 'name' => 'لیست دسته‌بندی ها'],
        //         ['id' => 1103, 'key' => 1103, 'name' => 'ویرایش'],
        //         ['id' => 1104, 'key' => 1104, 'name' => 'حذف'],
        //     ]
        // ],
        [
            'key' => 'accounting_data',
            'name' => 'حساب ها',
            'children' => [
                ['id' => 501, 'key' => 501, 'name' => 'حساب جدید'],
                ['id' => 502, 'key' => 502, 'name' => 'لیست حساب ها'],
                ['id' => 505, 'key' => 505, 'name' => 'گزارش حساب ها'],
                ['id' => 503, 'key' => 503, 'name' => 'ویرایش'],
                ['id' => 504, 'key' => 504, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'accounting',
            'name' => 'سند ها',
            'children' => [
                ['id' => 601, 'key' => 601, 'name' => 'سند جدید'],
                ['id' => 602, 'key' => 602, 'name' => 'لیست سند ها'],
                ['id' => 606, 'key' => 606, 'name' => 'لیست سند های کاربر'],
                ['id' => 603, 'key' => 603, 'name' => 'تایید'],
                ['id' => 614, 'key' => 614, 'name' => 'ویرایش'],
                ['id' => 612, 'key' => 612, 'name' => 'مشاهده جزییات'],
                ['id' => 613, 'key' => 613, 'name' => 'مشاهده فایل'],
                ['id' => 604, 'key' => 604, 'name' => 'حذف'],
                ['id' => 605, 'key' => 605, 'name' => 'لیست آیتم های حسابداری'],
                ['id' => 607, 'key' => 607, 'name' => 'لیست آیتم های حسابداری کاربر'],
                ['id' => 608, 'key' => 608, 'name' => 'لیست پرداخت ها'],
                ['id' => 609, 'key' => 609, 'name' => 'لیست دریافت ها'],
                ['id' => 610, 'key' => 610, 'name' => 'افزودن سند با اکسل'],
                ['id' => 611, 'key' => 611, 'name' => 'تقویم'],
            ]
        ],
        [
            'key' => 'user',
            'name' => 'کاربران',
            'children' => [
                ['id' => 701, 'key' => 701, 'name' => 'کاربر جدید'],
                ['id' => 702, 'key' => 702, 'name' => 'لیست کاربران'],
                ['id' => 703, 'key' => 703, 'name' => 'ویرایش'],
                ['id' => 704, 'key' => 704, 'name' => 'حذف'],
                ['id' => 705, 'key' => 705, 'name' => 'لاگ ورود'],
                ['id' => 706, 'key' => 706, 'name' => 'دستگاه فعال'],
                ['id' => 707, 'key' => 707, 'name' => 'افزودن به دستگاه انگشت زنی'],
                ['id' => 708, 'key' => 708, 'name' => 'حذف از دستگاه انگشت زنی'],
                ['id' => 709, 'key' => 709, 'name' => 'لیست ورود و خروج'],
                ['id' => 710, 'key' => 710, 'name' => 'ورود با کاربر'],
            ]
        ],
        [
            'key' => 'group',
            'name' => 'گروه کاربران',
            'children' => [
                ['id' => 801, 'key' => 801, 'name' => 'گروه جدید'],
                ['id' => 802, 'key' => 802, 'name' => 'لیست گروه ها'],
                ['id' => 803, 'key' => 803, 'name' => 'ویرایش'],
                ['id' => 804, 'key' => 804, 'name' => 'حذف'],
            ]
        ],
        [
            'key' => 'group',
            'name' => 'لیست قیمت ها',
            'children' => [
                ['id' => 1001, 'key' => 1001, 'name' => 'لیست قیمت ها'],
                ['id' => 1002, 'key' => 1002, 'name' => 'بروزرسانی قیمت ها با اکسل'],
            ]
        ],
    ];

    public static function tableName()
    {
        return 'user';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'approved'], 'integer'],
            // Fingerprint columns a request may fill. `fingerprint_id` is out
            // on purpose: it is only ever assigned by the queue actions
            // (fingerprint_add), never from a create/update request, so `load()`
            // drops it. `fingerprint_company_id` is obsolete now that numbering
            // is global, so it is no longer writable either.
            [['del_fingerid', 'add_fingerid'], 'integer'],
            [[
                'first_name',
                'last_name',
                'phone_number',
                'password_hash',
                'auth_token',
                'email',
                'verification_code',
                'group_id',
                'personnel_code'
            ], 'safe'],
        ];
    }

    public function behaviors()
    {
        return [
            'audit' => [
                'class' => AuditBehavior::class,
            ],
        ];
    }

    // ===== متد جدید: همگام‌سازی کد پرسنلی =====
    public static function syncPersonnelCode($userId)
    {
        $user = self::findOne($userId);
        if (!$user) {
            return false;
        }

        // اگر کد پرسنلی در User وجود دارد، نیازی به همگام‌سازی نیست
        if (!empty($user->personnel_code)) {
            return true;
        }

        // ===== جستجو در HrmPersonnelBasic =====
        $personnelBasic = HrmPersonnelBasic::find()
            ->where(['user_id' => $userId])
            ->andWhere(['is not', 'personnel_code', null])
            ->one();

        if ($personnelBasic && !empty($personnelBasic->personnel_code)) {
            $user->personnel_code = $personnelBasic->personnel_code;
            $user->save(false);
            return true;
        }

        // ===== جستجو در HrmContract =====
        $contract = HrmContract::find()
            ->where(['user_id' => $userId])
            ->andWhere(['is not', 'personnel_code', null])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($contract && !empty($contract->personnel_code)) {
            $user->personnel_code = $contract->personnel_code;
            $user->save(false);
            return true;
        }

        return false;
    }

    // ===== متد جدید: به‌روزرسانی کد پرسنلی در User =====
    public static function updatePersonnelCode($userId, $personnelCode)
    {
        if (empty($personnelCode)) {
            return false;
        }

        $user = self::findOne($userId);
        if (!$user) {
            return false;
        }

        // فقط اگر خالی باشد یا با مقدار جدید متفاوت باشد
        if ($user->personnel_code !== $personnelCode) {
            $user->personnel_code = $personnelCode;
            $user->save(false);
            return true;
        }

        return false;
    }

    // ===== متد جدید: دریافت کد پرسنلی از منابع مختلف =====
    public static function getPersonnelCode($userId)
    {
        $user = self::findOne($userId);
        if (!$user) {
            return null;
        }

        // اگر در User وجود دارد
        if (!empty($user->personnel_code)) {
            return $user->personnel_code;
        }

        // جستجو در HrmPersonnelBasic
        $personnelBasic = HrmPersonnelBasic::find()
            ->where(['user_id' => $userId])
            ->andWhere(['is not', 'personnel_code', null])
            ->one();

        if ($personnelBasic && !empty($personnelBasic->personnel_code)) {
            return $personnelBasic->personnel_code;
        }

        // جستجو در HrmContract
        $contract = HrmContract::find()
            ->where(['user_id' => $userId])
            ->andWhere(['is not', 'personnel_code', null])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($contract && !empty($contract->personnel_code)) {
            return $contract->personnel_code;
        }

        return null;
    }
    

    public static function findIdentity($id)
    {
        return self::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        try {
    
            return self::findOne(['auth_token' => $token]);

        } catch (\Exception $e) {

            return null;

        }
    }

    public static function checkTokenExpiration()
    {
        try {

            $tokens = UserLoginLog::find()->where(['active' => 1])->all();

            foreach ($tokens as $token) {
                
                $diff = abs(time() - strtotime($token->created_at));

                $is_expired = round($diff / (60 * 60)) > 4;

                if ($is_expired) {

                    $token->active = 0;
                    $token->save();

                }

            }
            
        } catch (\Exception $e) {
            echo "An error occurred: " . $e->getMessage() . "\n";
        }
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return $this->auth_token;
    }

    public function validateAuthKey($authKey)
    {
        try {

            $decoded_token = JWT::decode($authKey, new Key(KEY, 'HS256'));

            return $decoded_token->exp > time();

        } catch (\Exception $e) {
            
            return false;

        }
    }

    public static function checkAccess($role_id, $error = true)
    {
        if ($error)
            return true;

        $allowed = false;

        try {
            $user = Yii::$app->user->identity;

            // `group_id` is a JSON array in the real database, so only the
            // shared resolver finds the row - and it finds the very group the
            // gate admitted, which is the one its `roles` have to come from.
            $group = $user ? self::resolve_group($user->group_id) : null;
            $roles = ($group && $group->roles !== null && $group->roles !== '')
                ? json_decode($group->roles, true)
                : [];

            // `roles` ممکن است رشته‌ی خراب یا object باشد → نبودِ دسترسی
            $allowed = is_array($roles) && in_array($role_id, $roles, false);
        } catch (\Throwable $e) {
            // امنیتِ پیش‌فرض: هر خطای غیرمنتظره یعنی دسترسی داده نشود
            $allowed = false;
        }

        if ($allowed) {
            return true;
        }

        if ($error) {
            throw new UnauthorizedHttpException('شما به این بخش دسترسی ندارید');
        }

        return false;
    }

    /**
     * Every group id behind a raw `user.group_id`.
     *
     * The column is TEXT and the real database stores a JSON array in it -
     * `[21]`, `[1,23,15]` - one id per group the user belongs to. A plain
     * integer is accepted too, because that is what this panel writes when it
     * creates an account. Anything unusable answers no id at all, and every
     * caller below refuses an empty list.
     *
     * @param mixed $groupId raw `user.group_id`
     */
    private static function group_ids($groupId): array
    {
        if (!is_scalar($groupId)) {
            return [];
        }

        $raw = trim((string) $groupId);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        $candidates = is_array($decoded) ? $decoded : [$raw];

        $ids = [];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && preg_match('/^\d+$/', (string) $candidate) === 1) {
                $ids[] = (int) $candidate;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a user's group may open the HQ panel.
     *
     * Two things have to hold. The id must resolve to a group that exists -
     * `login()` reads `roles` off it, and a missing row would both grant
     * nothing and crash the read. And that group must be one this panel
     * created: `created_in_hq` is set by `Group::_save()` on a create only,
     * and the backfill in `sql/group-created-in-hq.sql` marks every group the
     * HQ panel already used while leaving companies 1 (dash) and 2 (seal) at
     * 0, because they create their groups in their own panels.
     *
     * Which company owns the group stays out of it - the picker lets a caller
     * pick any of a phone's rows, so the provenance of the group is the only
     * thing left to ask. Fails closed: a row that does not carry the column
     * yet (SQL not deployed) is treated as not this panel's own.
     *
     * @param mixed $groupId raw `user.group_id`
     * @param mixed $group resolved group row, or null when there is none
     */
    public static function hq_group_is_allowed($groupId, $group): bool
    {
        if (self::group_ids($groupId) === []) {
            return false;
        }

        if ($group === null) {
            return false;
        }

        return isset($group->created_in_hq) && (bool) $group->created_in_hq;
    }

    /**
     * Safe wrapper around Yii's password check.
     *
     * `Security::validatePassword()` throws `InvalidArgumentException` for a
     * missing or malformed hash instead of returning false, and several hundred
     * rows here have no hash at all (OTP accounts) - one throw would turn the
     * whole login into a 500. An unusable hash simply never matches.
     *
     * @param mixed $hash raw `user.password_hash`
     */
    public static function password_matches($password, $hash): bool
    {
        try {
            return Yii::$app->security->validatePassword($password, $hash);
        } catch (\yii\base\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Whether any of the rows accepts the password.
     *
     * A phone may hold several accounts this panel admits and the caller has
     * typed one password for the phone before ever seeing a row, so a single
     * match proves the caller holds a secret of this phone. Which row it was
     * belongs to the picker, not to this check.
     *
     * @param mixed $password raw `password` from the request, already a string
     * @param array $rows every row of the phone the panel admits
     */
    private static function password_matches_any($password, array $rows): bool
    {
        foreach ($rows as $row) {
            if (self::password_matches($password, $row->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The group a raw `group_id` signs in and acts through, or null when it
     * names none this panel created.
     *
     * A user can belong to several groups, so the first one this panel created
     * wins: that is the very row the gate accepts, and reading `roles` off the
     * same row keeps the token, the company picker and `checkAccess()` telling
     * one story. A group row that has been deleted simply never matches.
     *
     * @param mixed $groupId raw `user.group_id`
     */
    public static function resolve_group($groupId)
    {
        foreach (self::group_ids($groupId) as $id) {
            $group = Group::findOne(['id' => $id]);

            if ($group !== null && isset($group->created_in_hq) && (bool) $group->created_in_hq) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The row a login attempt is checked against.
     *
     * A phone can carry one row per panel, so the caller has to say which one
     * it means whenever more than one is on offer. With no `user_id` nothing
     * is settled here and `login()` decides what the answer is - a single
     * admitted row is signed into directly, several go to the picker - but the
     * row itself is never guessed. Guessing was the bug: the lowest id came
     * back first, so the row of another panel was tested and the HQ row behind
     * it never got the chance.
     *
     * An id that is not one of the rows offered here never widens the attempt
     * to another account; it is answered like a wrong credential.
     *
     * @param array $rows every row of the phone the panel admits, at least one
     * @param mixed $requestedUserId raw `user_id` from the request, or null
     * @return static|null one row, or null while the phone is still unsettled
     * @throws UnauthorizedHttpException when `user_id` cannot be honoured
     */
    public static function select_login_row(array $rows, $requestedUserId)
    {
        if (is_array($requestedUserId) || is_object($requestedUserId)) {
            throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
        }

        $requestedId = $requestedUserId === null ? '' : trim((string) $requestedUserId);

        if ($requestedId === '') {
            return null;
        }

        foreach ($rows as $row) {
            if ((string) $row->id === $requestedId) {
                return $row;
            }
        }

        throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
    }

    /**
     * `id => name` for every company, one plain query in this module's own
     * style (no join, no expression).
     */
    private static function company_names(): array
    {
        $names = [];

        foreach (Company::find()->asArray()->all() as $company) {
            $names[$company['id']] = $company['name'];
        }

        return $names;
    }

    /**
     * Name behind a raw `company_id`, or null when the account belongs to
     * this very panel.
     *
     * The null is handed over as a null on purpose: which name a company-less
     * account should show is a question only the front end can answer (the
     * picker and the sidebar both call it HQ), so the API never picks a label.
     *
     * @param mixed $companyId raw `user.company_id`
     * @param array|null $names already loaded map, to keep a list of rows to one query
     */
    public static function login_company_name($companyId, ?array $names = null): ?string
    {
        if ($companyId === null || $companyId === '') {
            return null;
        }

        $names = $names ?? self::company_names();

        return $names[(int) $companyId] ?? null;
    }

    /**
     * The company list a phone can sign in through.
     *
     * Only what the picker needs goes out: which row it is and what that row
     * is called. A row without a `company_id` belongs to this very panel, so
     * its name stays null and the caller labels it.
     *
     * A row this panel would refuse one step later is left out entirely -
     * offering it here only to turn it down at the password field looks
     * broken. The filter is the very same `hq_group_is_allowed()` the login
     * itself uses, so the list can never promise more than the next call will
     * honour.
     *
     * @param array $rows every row holding the phone
     */
    public static function login_company_options(array $rows): array
    {
        $names = self::company_names();

        $options = [];

        foreach ($rows as $row) {
            $group = self::resolve_group($row->group_id);

            if (!self::hq_group_is_allowed($row->group_id, $group)) {
                continue;
            }

            $companyId = $row->company_id;

            $options[] = [
                'user_id' => (int) $row->id,
                'company_id' => $companyId === null ? null : (int) $companyId,
                'company_name' => self::login_company_name($companyId, $names),
            ];
        }

        return $options;
    }

    /**
     * What `user/login` puts inside the token and hands to the front end to
     * store as `auth`.
     *
     * Free of any write on purpose: the success path saves an auth token and
     * a login log, so this half of the contract (roles stripped of the secret,
     * company name for the sidebar badge) has to stay checkable without
     * logging anybody in.
     *
     * @param static $user the row whose password just matched
     * @param Group|null $group resolved group row
     */
    public static function login_user_data($user, $group): array
    {
        $userData = $user->toArray();

        $userData['roles'] = $group->roles ?? '[]';
        $userData['company_name'] = self::login_company_name($user->company_id);

        unset($userData['password_hash'], $userData['created_at'], $userData['updated_at'], $userData['auth_token']);

        return $userData;
    }

    public static function login()
    {
        $post = Yii::$app->request->post();

        // The username and its password travel together from the very first
        // step: there is one form for both, and whichever row ends up being
        // signed into proves the same password again.
        $phone = $post['phone_number'] ?? null;
        $password = $post['password'] ?? null;

        if (!is_string($phone) || $phone === '' || !is_string($password)) {
            throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
        }

        $rows = User::findAll(['phone_number' => $phone]);

        if ($rows === []) {
            throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
        }

        // Only rows this panel admits take part in the attempt: a row the
        // gate would turn down one step later is not a row this phone can
        // sign in through. Dropping them first also means a phone with
        // nothing left is refused instead of being shown an empty picker.
        $eligible = [];

        foreach ($rows as $row) {
            if (self::hq_group_is_allowed($row->group_id, self::resolve_group($row->group_id))) {
                $eligible[] = $row;
            }
        }

        if ($eligible === []) {
            throw new UnauthorizedHttpException('شما عضو گروه مجاز برای این پنل نیستید');
        }

        // Step 2 - the caller named the row it wants out of the picker. Its
        // password is the very same one already typed, so it is proved again
        // here rather than kept between two requests.
        $user = self::select_login_row($eligible, $post['user_id'] ?? null);

        if ($user !== null) {
            if (!self::password_matches($password, $user->password_hash)) {
                throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
            }

            return self::login_issue_token($user);
        }

        // One company this phone can enter means no choice to make, so the
        // attempt goes straight to a token.
        if (count($eligible) === 1) {
            if (!self::password_matches($password, $eligible[0]->password_hash)) {
                throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
            }

            return self::login_issue_token($eligible[0]);
        }

        // Several companies: from where the caller stands the password
        // belongs to the phone, not to a row it has not seen yet. It is
        // proved against every admitted row first, and a password that
        // matches none of them never reaches the picker - the list of a
        // phone's companies is an answer for somebody already known to hold
        // one of its secrets.
        if (!self::password_matches_any($password, $eligible)) {
            throw new UnauthorizedHttpException('نام کاربری یا رمز عبور نادرست است');
        }

        return [
            'success' => false,
            'requires_company_selection' => true,
            'message' => 'این شماره در چند شرکت عضو است',
            'companies' => self::login_company_options($eligible),
        ];
    }

    /**
     * The answer to a login attempt that has already proved its password.
     *
     * This half is the only part of the login that writes - an auth token and
     * a login log - so all three ways in (one company, the named row of the
     * picker, the row picked right after it) end in the same place and cannot
     * drift apart.
     *
     * @param static $user the row the password was proved against
     * @param static|null $group the group to enter through, when the caller
     *     named one; a plain login has no choice to make and lets the shared
     *     resolver pick
     */
    private static function login_issue_token($user, $group = null): array
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + (4 * 3600);

        // Resolved once: the roles the token carries and the group the login
        // log records have to be the same group, or the log would name a row
        // the session never acted through.
        $resolved = $group ?? self::resolve_group($user->group_id);
        $userData = self::login_user_data($user, $resolved);

        $payload = [
            'iss' => 'http://hq.payegan.group',
            'aud' => 'http://hq.payegan.group',
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'user' => $userData
        ];

        $jwt = JWT::encode($payload, KEY, 'HS256');

        $user->auth_token = $jwt;
        $user->save(false);

        self::add_login_log($user, $jwt, $resolved);

        return [
            'success' => true,
            'token' => $jwt,
            'user' => $userData,
        ];
    }

    /**
     * Signs the caller into the panel as another user.
     *
     * The caller holds its own permission for this - seeing a row in the list
     * is not the same as taking over its session - and the row it names has
     * to be enterable the way a login is: through a group this panel created.
     * When several such groups fit, the caller has to say which one and is
     * never guessed a row, the same way the company picker never picks for
     * itself.
     *
     * @param mixed $id the row to sign in as
     * @param mixed $groupId the group to enter through, when one was named
     */
    public static function login_as($id, $groupId = null): array
    {
        if (!self::checkAccess(710, false)) {
            throw new UnauthorizedHttpException('شما به این بخش دسترسی ندارید');
        }

        $user = self::find()->where(['id' => $id])->one();

        if (!$user) {
            throw new NotFoundHttpException('کاربر با این شناسه یافت نشد');
        }

        $allowed = self::hq_groups($user->group_id);

        if ($allowed === []) {
            throw new ForbiddenHttpException('این کاربر گروهی برای این پنل ندارد');
        }

        $group = self::login_as_group($allowed, $groupId);

        return self::login_issue_token($user, Group::findOne($group['id']));
    }

    /**
     * The group a "sign in as" request enters through.
     *
     * A caller that named no group is answered only when exactly one fits;
     * naming one outside the row's own admissible groups is turned down
     * rather than treated as a request for the first available.
     *
     * @param array $allowed the row's admissible groups
     * @param mixed $groupId the group the caller named, when it named one
     */
    private static function login_as_group(array $allowed, $groupId): array
    {
        if ($groupId === null || $groupId === '') {

            if (count($allowed) === 1) {
                return $allowed[0];
            }

            throw new BadRequestHttpException('این کاربر چند گروه مجاز دارد؛ گروه موردنظر را انتخاب کنید');
        }

        foreach ($allowed as $group) {
            if ((string) $group['id'] === (string) $groupId) {
                return $group;
            }
        }

        throw new ForbiddenHttpException('این کاربر به گروه انتخاب‌شده دسترسی ندارد');
    }

    public static function forgot_password()
    {
        $post = Yii::$app->request->post();
        $user = User::find()->where(['phone_number' => $post['phone_number']])->andWhere(['is', 'company_id', null])->one();

        if (!$user)
            throw new UnauthorizedHttpException('کاربر یافت نشد');

        if (!$post['otp']) {

            $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);

            $user->verification_code = $code;
            $user->save();

            $body = "فراموشی رمز عبور\nکد : $code\nگروه پایگان";
            Sms::_send_sms($user->phone_number, $body);

            return ['success' => true, 'message' => 'کد با موفقیت ارسال شد'];

        }

        if ($user->verification_code != $post['otp'])
            throw new UnauthorizedHttpException('کد تایید اشتباه است');

        $user->verification_code = null;
        $user->password_hash = Yii::$app->security->generatePasswordHash($post['password']);
        $user->save();

        return ['success' => true, 'message' => 'رمز شما با موففیت تغییر کرد. میتوانید وارد شوید'];
    }
public static function get_all()
{
    self::checkAccess(1102);

    $params = Yii::$app->request->getQueryParams();
    $query = User::find()->with(['company', 'group', 'logs'])->orderBy(['id' => SORT_DESC]);

    $page = isset($params['page']) ? (int) $params['page'] : 1;
    $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
    $isSelect=$params['is_select']==true;
    unset($params['per-page'], $params['page'],$params['is_select']);

    if( $isSelect){
        $perPage =9999;
    }
    // ===== شرط ویژه: جستجو فقط بر اساس personnel_code در قراردادها =====
    // اگر تنها فیلتر ارسالی personnel_code بود، اول در HrmContract بگرد
    $filterKeys = array_diff(array_keys($params), ['expand']);
    $onlyPersonnelCode = count($filterKeys) === 1 && !empty($params['personnel_code']);

    if ($onlyPersonnelCode) {
        $contract = HrmContract::find()
            ->where(['personnel_code' => $params['personnel_code']])
            ->orderBy(['id' => SORT_DESC])
            ->asArray()
            ->one();

        if ($contract && !empty($contract['user_id']) && (int) $contract['user_id'] > 0) {
            $userQuery = User::find()
                ->with(['company', 'group', 'logs'])
                ->where(['id' => $contract['user_id']])
                ->asArray();

            $users = $userQuery->all();

            if (!empty($users)) {
                return [
                    'pages' => 1,
                    'totalCount' => count($users),
                    'data' => self::withGroupNames($users),
                ];
            }
        }
        else{
             $userQuery = User::find()
                ->with(['company', 'group', 'logs'])
                ->where(['fingerprint_id' => $params['personnel_code']])
                ->asArray();

            $users = $userQuery->all();

            if (!empty($users)) {
                return [
                    'pages' => 1,
                    'totalCount' => count($users),
                    'data' => self::withGroupNames($users),
                ];
            }
        }
        // اگر پیدا نشد → ادامه جستجوی عادی
    }

    // ===== جستجوی عادی =====
    if (!empty($params['name'])) {

        $query->andWhere(['or',
            ['like', 'first_name', $params['name']],
            ['like', 'last_name', $params['name']],
        ]);
        unset($params['name']);
    }

    if (!empty($params['active']) || $params['active'] === '0') {

        $user_ids = UserLoginLog::find()->select('user_id')->where(['active' => 1])->column();

        if ($params['active'] == 1) {
            $query->andWhere(['in', 'id', $user_ids]);
        } else {
            $query->andWhere(['not in', 'id', $user_ids]);
        }

        unset($params['active']);
    }

    if (!empty($params['fingerprint']) || $params['fingerprint'] === '0') {

        if ($params['fingerprint'] == 1) {
            $query->andWhere(['not', ['fingerprint_id' => null]]);
        } else {
            $query->andWhere(['fingerprint_id' => null]);
        }

        unset($params['fingerprint']);
    }

    if (!empty($params['device_status'])) {

        $queued_del = ['del_fingerid' => 1];
        $not_queued_del = ['or', ['del_fingerid' => null], ['<>', 'del_fingerid', 1]];
        $queued_add = ['add_fingerid' => 2];
        $not_queued_add = ['or', ['add_fingerid' => null], ['<>', 'add_fingerid', 2]];

        switch ($params['device_status']) {
            case 1: // در انتظار ثبت
                $query->andWhere($not_queued_del)
                    ->andWhere($queued_add)
                    ->andWhere(['not', ['fingerprint_id' => null]]);
                break;
            case 2: // ثبت شده
                $query->andWhere($not_queued_del)
                    ->andWhere($not_queued_add)
                    ->andWhere(['not', ['fingerprint_id' => null]]);
                break;
            case 3: // در انتظار حذف
                $query->andWhere($queued_del);
                break;
            case 4: // عدم ثبت
                $query->andWhere(['fingerprint_id' => null])
                    ->andWhere($not_queued_add)
                    ->andWhere($not_queued_del);
                break;
        }

        unset($params['device_status']);
    }

    foreach ((array) ($params['group_id'] ?? []) as $groupId) {

        if ($groupId === null || $groupId === '') {
            continue;
        }

        $query->andWhere(new yii\db\Expression(
            "JSON_CONTAINS(IF(JSON_VALID(group_id), group_id, CONCAT('[', group_id, ']')), '" . (int) $groupId . "')"
        ));
    }

    unset($params['group_id']);

    foreach ($params as $key => $value) {

        if ($value != null && $key != 'expand') {
            $query->andWhere(['like', $key, $value]);
        }
    }

$query->select([
    '*',
    new \yii\db\Expression(
        'COALESCE(NULLIF(personnel_code, ""), fingerprint_id) AS personnel_code'
    ),
]);
    $totalCount = $query->count();
    $dataProvider = new ActiveDataProvider([
        'query' => $query->orderBy(['fingerprint_id' => SORT_DESC,'company_id' => SORT_DESC])->asArray(),
        'pagination' => [
            'pageSize' => $perPage,
            'page' => $page - 1,
        ],
    ]);

    $users = $dataProvider->getModels();

    return [
        'pages' => ceil($totalCount / $perPage),
        'totalCount' => $totalCount,
        'data' => self::withGroupNames($users),
    ];
}

    /**
     * Every group name behind a row's `group_id`, joined for the list column.
     *
     * `group_id` is a JSON array for the users the dash panel created, and the
     * `group` relation matches a single id, so those rows come back carrying
     * no group at all. This walks the ids the column actually stores, keeps
     * their order, and spends one extra query for the whole page instead of
     * one per row.
     *
     * @param array $users one page of `get_all()` rows, already arrays
     */
    private static function withGroupNames(array $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            foreach (self::group_ids($user['group_id'] ?? null) as $id) {
                $ids[$id] = $id;
            }
        }

        $groups = $ids === []
            ? []
            : Group::find()->where(['id' => array_values($ids)])->indexBy('id')->asArray()->all();

        foreach ($users as $index => $user) {
            $names = [];

            foreach (self::group_ids($user['group_id'] ?? null) as $id) {
                if (isset($groups[$id])) {
                    $names[] = $groups[$id]['name'];
                }
            }

            $users[$index]['group_names'] = $names === [] ? null : implode(' - ', $names);
            $users[$index]['hq_groups'] = self::hq_groups($user['group_id'] ?? null, $groups);
        }

        return $users;
    }

    /**
     * Every group of a raw `group_id` that this panel created.
     *
     * This is the admission rule in one place: a row is enterable through any
     * group it belongs to that was born here. It is what the login gate lets
     * in, what the list draws its "sign in as" button for, and what that
     * action offers to pick - so all three can never disagree about a row.
     *
     * `$groups` lets a caller that already loaded the page's groups spend no
     * more than the one query it has already paid for.
     *
     * @param mixed $groupId raw `user.group_id`
     * @param array|null $groups id => group row, when the caller already has them
     */
    public static function hq_groups($groupId, ?array $groups = null): array
    {
        $ids = self::group_ids($groupId);

        if ($ids === []) {
            return [];
        }

        if ($groups === null) {
            $groups = Group::find()->where(['id' => $ids])->indexBy('id')->asArray()->all();
        }

        $allowed = [];

        foreach ($ids as $id) {
            if (isset($groups[$id]) && !empty($groups[$id]['created_in_hq'])) {
                $allowed[] = [
                    'id' => (int) $id,
                    'name' => $groups[$id]['name'] ?? null,
                ];
            }
        }

        return $allowed;
    }

    public static function options()
    {
        $params = Yii::$app->request->getQueryParams();

        $query = User::find()
            ->select(['id', 'first_name', 'last_name', 'company_id'])
            ->orderBy(['id' => SORT_DESC]);

        if (!empty($params['company_id'])) {
            $query->andWhere(['company_id' => (int) $params['company_id']]);
        }

        return ['success' => true, 'data' => $query->asArray()->limit(5000)->all()];
    }

    public static function _view($id)
    {
        self::checkAccess(1102);

        $model = User::find()->with('company')->where(['id' => $id])->asArray()->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'کاربر با این شناسه یافت نشد',
            ];

        }

        unset($model['auth_token'], $model['created_at'], $model['password_hash'], $model['updated_at']);

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        $isCreate = !$id && !$params['id'];

        if ($isCreate) {

            self::checkAccess(1101);

            $model = new User();

        } else {

            self::checkAccess(1103);

            $model = User::findOne($id ?? $params['id']);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'کاربر با این شناسه یافت نشد',
                ];

            }
        }

        if (empty($params['password_hash'])) {

            unset($params['password_hash']);

        }

        // A multi-group user - the shape the dash panel writes - arrives as an
        // array of ids, while `group_id` is a TEXT column storing that array as
        // JSON. The ids are encoded here rather than left for the driver, which
        // cannot bind an array.
        if (is_array($params['group_id'] ?? null)) {

            $params['group_id'] = json_encode(array_values(array_map('intval', $params['group_id'])));

        }

        if ($model->load($params, '')) {

            if (isset($params['password_hash']) && !empty($params['password_hash'])) {

                $model->password_hash = Yii::$app->security->generatePasswordHash($params['password_hash']);

            }

            if (!$params['company_id']) {

                $model->company_id = null;

            }

            $model->normalizeFingerprintAttributes();

            if ($model->save()) {

                // A create/update never touches the fingerprint. Enrolment now
                // happens only through the queue actions (fingerprint_add /
                // fingerprint_del) opened from the user list, so a saved row
                // keeps whatever fingerprint state it already had.
                return [
                    'success' => true,
                    'data' => $model,
                ];

            }

        }

        return [
            'success' => false,
            'errors' => $model->errors,
        ];
    }

    /**
     * Store an empty fingerprint queue input as NULL.
     *
     * The two queue columns are `INT NULL`, but an empty field arrives as ''
     * and the database would coerce that to 0. Normalising keeps the columns
     * down to NULL or their own flag (del_fingerid = 1, add_fingerid = 2),
     * which is what every queue test compares against.
     */
    private function normalizeFingerprintAttributes()
    {
        foreach (['del_fingerid', 'add_fingerid'] as $attribute) {
            if ($this->$attribute === '') {
                $this->$attribute = null;
            }
        }
    }

    /**
     * Give an un-enrolled user the lowest free fingerprint id, globally.
     *
     * WHY: the fingerprint device matches on one integer per enrolled finger,
     * and the sequence is no longer bucketed by company (user ruling
     * 2026-10-15) - every finger shares ONE global count starting at 1. A new
     * row takes the LOWEST free id across all users, so a number freed by a
     * removed finger is refilled instead of skipped (the walk only grows past
     * MAX when 1..MAX is dense). Persisted with validation skipped: the row was
     * already validated and nothing else on it changes here. Once assigned the
     * id must stay stable, because the device keeps matching on that integer.
     */
    private function assignFingerprintId()
    {
        $used = User::find()
            ->select('fingerprint_id')
            ->where(['not', ['fingerprint_id' => null]])
            ->orderBy(['fingerprint_id' => SORT_ASC])
            ->column();

        $this->fingerprint_id = 1;
        foreach ($used as $id) {
            if ((int) $id !== $this->fingerprint_id) {
                break;
            }
            $this->fingerprint_id++;
        }

        $this->save(false, ['fingerprint_id']);
    }

    /**
     * Queue a user for enrolment on the fingerprint device.
     *
     * The list shows this only for a user with no id, so the happy path hands
     * them the lowest free id (assignFingerprintId) and flags `add_fingerid`
     * with its own number, 2, meaning "pending write to the device". At most
     * ONE user may be pending at a time: when somebody is already queued the
     * caller has to confirm a replacement, which clears that row's
     * `fingerprint_id` and `add_fingerid` before the new row takes its place
     * (the vacated id then falls free again for the gap-fill).
     *
     * @param int $id the user being queued
     * @param bool $replace whether the caller already confirmed replacing whoever is pending
     * @return array success, or a `conflict` payload naming the pending user
     */
    public static function fingerprint_add($id, $replace = false)
    {
        if (!self::checkAccess(707, false)) {
            throw new UnauthorizedHttpException('شما به این بخش دسترسی ندارید');
        }

        $model = User::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'کاربر با این شناسه یافت نشد'];
        }

        if ($model->fingerprint_id !== null) {
            return ['success' => false, 'message' => 'این کاربر هم‌اکنون اثر انگشت دارد'];
        }

        $pending = User::find()
            ->where(['add_fingerid' => 2])
            ->andWhere(['<>', 'id', $model->id])
            ->one();

        if ($pending !== null && !$replace) {
            return ['success' => false, 'conflict' => true, 'pending_user' => self::pending_label($pending)];
        }

        if ($pending !== null) {
            $pending->fingerprint_id = null;
            $pending->add_fingerid = null;
            $pending->save(false, ['fingerprint_id', 'add_fingerid']);
        }

        // the device numbers its enrolled fingers 1..100, and a removal only
        // frees its slot once the device has actually dropped the finger - so
        // the count is taken AFTER a confirmed replacement, which frees one
        $used = User::find()->where(['not', ['fingerprint_id' => null]])->count();

        if ((int) $used >= 100) {
            return ['success' => false, 'message' => 'ظرفیت شماره دهی پر شده'];
        }

        $model->assignFingerprintId();
        $model->add_fingerid = 2;
        $model->save(false, ['add_fingerid']);

        return ['success' => true, 'data' => $model];
    }

    /**
     * Queue a user for removal from the fingerprint device.
     *
     * The mirror of fingerprint_add, with one difference: the row KEEPS its
     * `fingerprint_id` (the finger is only flagged for removal; the number is
     * not freed until the device actually drops it). At most one user may be
     * pending removal at a time; a second one replaces the first, clearing
     * only that row's `del_fingerid`.
     *
     * @param int $id the user being queued
     * @param bool $replace whether the caller already confirmed replacing whoever is pending
     * @return array success, or a `conflict` payload naming the pending user
     */
    public static function fingerprint_del($id, $replace = false)
    {
        if (!self::checkAccess(708, false)) {
            throw new UnauthorizedHttpException('شما به این بخش دسترسی ندارید');
        }

        $model = User::findOne($id);

        if (!$model) {
            return ['success' => false, 'message' => 'کاربر با این شناسه یافت نشد'];
        }

        if ($model->fingerprint_id === null) {
            return ['success' => false, 'message' => 'این کاربر اثر انگشت ندارد'];
        }

        $pending = User::find()
            ->where(['del_fingerid' => 1])
            ->andWhere(['<>', 'id', $model->id])
            ->one();

        if ($pending !== null && !$replace) {
            return ['success' => false, 'conflict' => true, 'pending_user' => self::pending_label($pending)];
        }

        if ($pending !== null) {
            $pending->del_fingerid = null;
            $pending->save(false, ['del_fingerid']);
        }

        $model->del_fingerid = 1;
        $model->save(false, ['del_fingerid']);

        return ['success' => true, 'data' => $model];
    }

    /**
     * The id and full name of a user waiting in a fingerprint queue, for the
     * replace-confirmation modal.
     *
     * @param static $user the pending row
     */
    private static function pending_label($user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
        ];
    }

    public static function _delete($id)
    {
        self::checkAccess(1104);

        $seal = User::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('کاربر با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    /**
     * @param mixed $group the group this session acts through, when the caller
     *     already resolved one; a plain login has no choice to make and lets
     *     the shared resolver pick
     */
    public static function add_login_log($user, $token, $group = null)
    {
        $user_log = UserLoginLog::find()->where(['user_id' => $user->id, 'active' => 1])->one();

        if ($user_log) {

            $user_log->active = 0;
            $user_log->save();

        }

        // create user login log
        $log = new UserLoginLog();
        $log->user_id = $user->id;
        $log->active = 1;

        // `user.group_id` is a JSON array while this column is an integer with
        // an `integer` rule, so the raw value fails validation, the row is
        // never written, and every later request answers "invalid token" - the
        // request half looks this very row up by its token.
        $resolved = $group ?? self::resolve_group($user->group_id);

        $log->group_id = $resolved !== null ? (int) $resolved->id : 0;
        $log->token = $token;
        $log->ip = Util::user_ip();
        $log->os = Util::user_os();
        $log->save();

        return $log;
    }

    public static function form_data()
    {
        return User::find()->select(['id AS value', 'CONCAT(COALESCE(first_name,"")," ",COALESCE(last_name,"")) AS label'])->asArray()->all();
    }

    public function getGroup()
    {
        return $this->hasOne(Group::class, ['id' => 'group_id']);
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getLogs()
    {
        return $this->hasMany(UserLoginLog::class, ['user_id' => 'id']);
    }
 
}
