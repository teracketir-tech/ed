<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Bank extends ActiveRecord
{

    public static function tableName()
    {
        return 'bank';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'active', 'is_default', 'bank_name_id'], 'integer'],
            [[
                'branch',
                'name',
                'accounting_code',
                'account_number',
                'card_number',
                'iban',
                'pos_number',
                'account_owner_name',
                'currency',
                'balance',
                'registered_mobile_number',
                'payment_switch_number',
                'payment_terminal_number',
                'merchant_number',
                'description',
            ], 'safe'],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->get();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        // Project IDs
        $projectIds = [];

        if (!empty($params['project_id'])) {

            $projectIds = array_filter(
                array_map('intval', explode(',', $params['project_id']))
            );

            unset($params['project_id']);
        }

        // Query
        $query = Bank::find()
            ->with(['company', 'bankName'])

            ->leftJoin(
                'accounting_item ai',
                'ai.bank_id = bank.id'
            )

            ->leftJoin(
                'accounting a',
                'a.id = ai.accounting_id'
                . (!empty($projectIds)
                    ? ' AND a.project_id IN (' . implode(',', $projectIds) . ')'
                    : '')
            )

            ->select([
                'bank.*',

                'debit' => new \yii\db\Expression(
                    'COALESCE(SUM(
                        CASE
                            WHEN a.id IS NOT NULL THEN ai.debit
                            ELSE 0
                        END
                    ), 0)'
                ),

                'credit' => new \yii\db\Expression(
                    'COALESCE(SUM(
                        CASE
                            WHEN a.id IS NOT NULL THEN ai.credit
                            ELSE 0
                        END
                    ), 0)'
                ),

                'total_balance' => new \yii\db\Expression(
                    'COALESCE(SUM(
                        CASE
                            WHEN a.id IS NOT NULL
                            THEN ai.credit - ai.debit
                            ELSE 0
                        END
                    ), 0)'
                ),
            ])

            ->groupBy(['bank.id']);

        /*
        * اگر project_id ارسال شده باشد،
        * بانک‌هایی که debit و credit هر دو صفر هستند حذف می‌شوند.
        */
        if (!empty($projectIds)) {

            $query->having(new \yii\db\Expression(
                'COALESCE(SUM(
                    CASE
                        WHEN a.id IS NOT NULL THEN ai.debit
                        ELSE 0
                    END
                ), 0) != 0
                OR
                COALESCE(SUM(
                    CASE
                        WHEN a.id IS NOT NULL THEN ai.credit
                        ELSE 0
                    END
                ), 0) != 0'
            ));
        }

        // Pagination params
        unset($params['per-page'], $params['page']);

        // Filters
        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if (in_array($key, ['id', 'company_id'])) {

                    $query->andWhere([
                        "bank.$key" => $value
                    ]);

                } else {

                    $query->andWhere([
                        'like',
                        "bank.$key",
                        $value
                    ]);
                }
            }
        }

        $totalCount = $query->count();

        $dataProvider = new ActiveDataProvider([
            'query' => $query
                ->orderBy(['bank.id' => SORT_DESC])
                ->asArray(),

            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        return [
            'totalCount' => $totalCount,
            'data' => $dataProvider->getModels(),
            'pages' => ceil($totalCount / $perPage),
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new Bank();

        } else {

            User::checkAccess(503);

            $model = Bank::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'بانک با این شناسه یافت نشد',
                ];

            }
        }

        if ($model->load($params, '') && $model->save()) {

            if (!$params['company_id']) {

                $model->company_id = null;
                $model->save();

            }

            return [
                'success' => true,
                'data' => $model,
            ];

        }

        return [
            'success' => false,
            'errors' => $model->errors,
        ];
    }

    public static function _view($id)
    {
        User::checkAccess(502);

        $model = Bank::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'بانک با این شناسه یافت نشد',
            ];

        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    public static function _delete($id)
    {
        User::checkAccess(504);

        $seal = Bank::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('بانک با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getBankName()
    {
        return $this->hasOne(BankName::class, ['id' => 'bank_name_id']);
    }

    public function getAccountingItems()
    {
        return $this->hasMany(AccountingItem::class, ['bank_id' => 'id']);
    }
}
