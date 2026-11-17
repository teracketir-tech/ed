<?php

namespace frontend\modules\hq\models;

use common\constants\AccountingDataIds;
use frontend\models\AccountingItemPaid;
use frontend\modules\hq\services\AccountingItemService;
use frontend\modules\seal\models\Material;
use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;
use yii\web\UnauthorizedHttpException;

class AccountingItem extends ActiveRecord
{

    public static function tableName()
    {
        return 'accounting_item';
    }

    public function rules()
    {
        return [
            [
                [
                    'id',
                    'company_id',
                    'product_id',
                    'bank_id',
                    'material_id',
                    'accounting_id',
                    'contact_id',
                    'sub_account_id',
                    'accounting_data_id',
                    'paid_or_received',
                ],
                'integer'
            ],
            [
                [
                    'debit',
                    'credit',
                    'usd',
                    'gold',
                    'meter',
                    'aed',
                    'description',
                    'extraFields',
                    'pay_prices'
                ],
                'safe'
            ],
        ];
    }

    public static function get_all()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->get();

        if (!$params)
            $params = Yii::$app->request->post();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = AccountingItem::find()
            ->alias('ai')
            ->joinWith([
                'accounting a',
            ])
            ->with([
                'company',
                'accountingData',
                'accounting',
                'accounting.user',
                'accounting.project',
                'paidItems',
                'contact',
                'bank',
                'material',
                'material.color',
                'product',
                'bank.bankName',
                'subAccount'
            ]);

        unset($params['per-page'], $params['page']);

        if ($params['accounting_data_id']) {

            $ids = explode(",", $params['accounting_data_id']);
            $query->andWhere(['in', 'accounting_data_id', $ids]);
            unset($params['accounting_data_id']);

        }

        if ($params['project_id']) {

            $query->andWhere(['a.project_id' => $params['project_id']]);

            unset($params['project_id']);

        }

        if ($params['date']) {

            $range = explode(',', $params['date']);

            if (count($range) == 2) {

                $start = date('Y-m-d', strtotime($range[0]));
                $end = date('Y-m-d', strtotime($range[1]));

                $query->andWhere(['between', 'a.date', $start, $end]);

            }

            unset($params['date']);

        }

        if ($params['created_at']) {

            $range = explode(',', $params['created_at']);

            if (count($range) == 2) {

                $start = date('Y-m-d', strtotime($range[0])) . ' 00:00:00';
                $end = date('Y-m-d', strtotime($range[1])) . ' 23:59:59';

                $query->andWhere(['between', 'a.created_at', $start, $end]);

            }

            unset($params['created_at']);

        }

        if (isset($params['credit']) && $params['credit'] == 0) {

            $query->andWhere(['ai.credit' => [0, '', null]]);
            unset($params['credit']);

        }

        if (isset($params['debit']) && $params['debit'] == 0) {

            $query->andWhere(['ai.debit' => [0, '', null]]);
            unset($params['debit']);

        }

        $sort = [];

        if ($params['sort_date']) {

            $sort['a.date'] = $params['sort_date'] == 1 ? SORT_ASC : SORT_DESC;
            unset($params['sort_date']);

        }

        if ($params['sort_created']) {

            $sort['a.created_at'] = $params['sort_created']  == 1 ? SORT_ASC : SORT_DESC;
            unset($params['sort_created']);

        }

        if (empty($sort)) {

            $sort['ai.id'] = SORT_DESC;

        }

        foreach ($params as $key => $value) {

            if ($value !== null && $value !== '' && !in_array($key, ['expand', 'excel', 'sum'])) {

                if (in_array($key, ['id', 'company_id', 'bank_id', 'contact_id', 'debit', 'credit'])) {

                    $query->andWhere(["ai.$key" => $value]);

                } else {

                    $query->andWhere(['like', "ai.$key", $value]);

                }
            }

        }

        if ($params['sum']){
            
            $total = 0;

            foreach ($query->all() as $item) {
            
                $total_paid_prices = array_reduce($item->paidItems, function ($acc, $p_item) { return $acc + (float)$p_item->price; }, 0);

                $debit = (float)$item->debit;
                $credit = (float)$item->credit;

                $total += abs($credit - $debit) - $total_paid_prices;

            }
        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy($sort)->asArray(),
            'pagination' => $params['excel'] ? false : ['pageSize' => $perPage, 'page' => $page - 1],
        ]);

        return [
            'total' => $total ? (int)$total : null,
            'totalCount' => $totalCount,
            'data' => $dataProvider->getModels(),
            'pages' => ceil($totalCount / $perPage),
            'accounting_data_list' => AccountingData::get_cache_data(),
            
        ];
    }

    public static function calendar()
    {
        User::checkAccess(502);

        $params = Yii::$app->request->get();

        if (!$params)
            $params = Yii::$app->request->post();

        return [
            'success' => true,
            'data' => self::calendar_items($params),
            'today_report' => self::calendar_today_report($params),
        ];
    }

    public static function calendar_items($params)
    {
        $range = explode(',', $params['date']);

        if (count($range) == 2) {

            $start = date('Y-m-d', strtotime($range[0]));
            $end = date('Y-m-d', strtotime($range[1]));

        }

        $all_query = AccountingItem::find()
            ->joinWith(['accounting'])
            ->with([
                'company',
                'accountingData',
                'accounting',

                'paidItems',
                'paidItems.main',
                'paidItems.main.accounting',

                'accounting.items',
                'accounting.items.accountingData',
                'accounting.items.contact',
                'accounting.items.product',
                'accounting.items.material',
                'accounting.items.bank',
                'accounting.items.bank.bankName',

                'contact'
            ])
            ->where([
                'in',
                'accounting_item.accounting_data_id',
                [
                    AccountingDataIds::PAYABLE,
                    AccountingDataIds::RECEIVABLE
                ]
            ]);

        if ($params['specific_date']) {

            $all_query->andWhere(['accounting.date' => $params['specific_date']]);

        } else {

            if ($start && $end) {

                $all_query->andWhere([
                    'between',
                    'accounting.date',
                    $start,
                    $end
                ]);

            }

        }

        if (!empty($params['description'])) {

            $all_query->andWhere([
                'like',
                'accounting_item.description',
                $params['description']
            ]);

        }

        if (!empty($params['company_id'])) {

            $all_query->andWhere([
                'accounting_item.company_id' => $params['company_id']
            ]);

        }

        if (!empty($params['accounting_data_id'])) {

            $all_query->andWhere([
                'accounting_item.accounting_data_id' => $params['accounting_data_id']
            ]);

        }

        if (!empty($params['contact_id'])) {

            $all_query->andWhere([
                'accounting_item.contact_id' => $params['contact_id']
            ]);

        }

        $all_items = $all_query->all();

        $items = [];

        foreach ($all_items as $item) {

            $is_original = !preg_match('/^پرداخت سند \d+$/', $item->description) && !preg_match('/^دریافت سند \d+$/', $item->description) && (($item->accounting_data_id == AccountingDataIds::PAYABLE && (int)$item->debit == 0) || ($item->accounting_data_id == AccountingDataIds::RECEIVABLE && (int)$item->credit == 0));

            $pay_items = ['total' => 0, 'delay' => 0, 'items' => []];
            $price_key = (int)$item->credit > 0 ? 'debit' : 'credit';

            foreach ($item->paidItems as $ai) {

                $ai_main_item = $ai->main;

                $pay_items['total'] += (int)$ai_main_item->{$price_key};

                $pay_items['items'][] = $ai_main_item;

                $diff_days = (strtotime($ai_main_item->accounting->date) - strtotime($item->accounting->date)) / 86400;

                if ($diff_days > $pay_items['delay']) {

                    $pay_items['delay'] = $diff_days;

                }

            }

            if ($is_original && $item->paid_or_received != 1 && abs((int)$item->credit - (int)$item->debit) != $pay_items['total'] && strtotime($item->accounting->date) <= time()) {

                if ((int)$item->accounting_data_id == AccountingDataIds::PAYABLE) {

                    // اسناد پرداختنی
                    $today_report['credit']['count'] += 1;
                    $today_report['credit']['items'][] = $item;
                    $today_report['credit']['total'] += (int)$item->credit;

                } else {

                    // اسناد دریافتنی
                    $today_report['debit']['count'] += 1;
                    $today_report['debit']['items'][] = $item;
                    $today_report['debit']['total'] += (int)$item->debit;

                }

            }

            $side_accounting_data = '';
            $side_accounting_data_detail = '';

            foreach ($item->accounting->items as $it) {

                if ($it->id === $item->id) {
                    continue;
                }

                $side_accounting_data = $it->accountingData->title ?? '';
                $side_accounting_data_detail =
                    $it->contact->alias
                    ?? $it->product->name
                    ?? $it->material->name
                    ?? $it->bank->name
                    ?? $it->bank->bankName->name
                    ?? '';

                break;

            }

            $items[] = [
                'id' => $item->id,
                'debit' => $item->debit,
                'credit' => $item->credit,
                'company' => $item->company,
                'contact' => $item->contact,
                'is_original' => $is_original,
                'accounting' => $item->accounting,
                'company_id' => $item->company_id,
                'contact_id' => $item->contact_id,
                'description' => $item->description,
                'accounting_id' => $item->accounting_id,
                'accounting_data' => $item->accountingData,
                'side_accounting_data' => $side_accounting_data,
                'side_accounting_data_detail' => $side_accounting_data_detail,
                'accounting_data_id' => $item->accounting_data_id,
                'full_description' => $item->id . '- ' . $item->description,
                'pay_items' => [...$pay_items, 'total_paid' => abs((int)$item->credit - (int)$item->debit) == $pay_items['total']],
            ];
        }

        return $items;
    }

    public static function calendar_today_report($params)
    {
        $today_report = [
            'credit' => [
                'count' => 0,
                'total' => 0,
                'pages' => 0,
                'items' => [],
            ],
            'debit' => [
                'count' => 0,
                'total' => 0,
                'pages' => 0,
                'items' => [],
            ]
        ];

        $today_query = AccountingItem::find()
            ->joinWith(['accounting'])
            ->with([
                'contact',
                'paidItems',
                'accountingData',
            ])
            ->where([
                'in',
                'accounting_item.accounting_data_id',
                [
                    AccountingDataIds::PAYABLE,
                    AccountingDataIds::RECEIVABLE
                ]
            ])
            // is_original
            ->andWhere([
                'not regexp',
                'accounting_item.description',
                '^پرداخت سند [0-9]+$'
            ])
            ->andWhere([
                'not regexp',
                'accounting_item.description',
                '^دریافت سند [0-9]+$'
            ])
            ->andWhere([
                'or',
                [
                    'and',
                    [
                        'accounting_item.accounting_data_id' => AccountingDataIds::PAYABLE
                    ],
                    [
                        'accounting_item.debit' => 0
                    ]
                ],
                [
                    'and',
                    [
                        'accounting_item.accounting_data_id' => AccountingDataIds::RECEIVABLE
                    ],
                    [
                        'accounting_item.credit' => 0
                    ]
                ]
            ])
            // accounting must not be paid
            ->andWhere([
                'accounting_item.paid_or_received' => 0
            ])
            // accounting date <= today
            ->andWhere([
                '<=',
                'accounting.date',
                date('Y-m-d')
            ]);

        if (!empty($params['company_id'])) {

            $today_query->andWhere([
                'accounting_item.company_id' => $params['company_id']
            ]);

        }

        if (!empty($params['accounting_data_id'])) {

            $today_query->andWhere([
                'accounting_item.accounting_data_id' => $params['accounting_data_id']
            ]);

        }

        if (!empty($params['contact_id'])) {

            $today_query->andWhere([
                'accounting_item.contact_id' => $params['contact_id']
            ]);

        }

        $limit = 10;
        $page = ($params['page'] ?? 0) - 1;
        // $offset = $page * $limit;

        $today_items = $today_query
            // ->limit($limit)
            // ->offset($offset)
            ->all();

        $start_offset = $page * $limit;
        $end_offset = $start_offset + 9;

        $credit_index = 0;
        $debit_index = 0;

        foreach ($today_items as &$item) {

            $paid = array_reduce($item->paidItems ?? [], function ($acc, $p_item) { return $acc + (float)$p_item->price; }, 0);

            if ((int)$item->accounting_data_id == AccountingDataIds::PAYABLE) {

                $item->credit = (int)$item->credit - (int)$paid;

                $today_report['credit']['count']++;
                $today_report['credit']['total'] += (int)$item->credit;

                if ($credit_index >= $start_offset && $credit_index <= $end_offset && count($today_report['credit']['items']) < 10) {

                    $today_report['credit']['items'][] = $item;

                }

                $credit_index++;

            } else {

                $item->debit = (int)$item->debit - (int)$paid;

                $today_report['debit']['count']++;
                $today_report['debit']['total'] += (int)$item->debit;

                if ($debit_index >= $start_offset && $debit_index <= $end_offset && count($today_report['debit']['items']) < 10) {

                    $today_report['debit']['items'][] = $item;

                }

                $debit_index++;

            }

            $today_report['credit']['pages'] = ceil($today_report['credit']['count'] / $limit);
            $today_report['debit']['pages'] = ceil($today_report['debit']['count'] / $limit);
        }

        return $today_report;
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new AccountingItem();

        } else {

            User::checkAccess(503);

            $model = AccountingItem::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'آیتم  سند با این شناسه یافت نشد',
                ];

            }
        }

        if ($model->load($params, '') && $model->save()) {

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

        $model = AccountingItem::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'آیتم  سند با این شناسه یافت نشد',
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

        $seal = AccountingItem::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('آیتم  سند با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    public static function _pay()
    {
        $params = Yii::$app->request->post();
        return AccountingItemService::pay($params);
    }

    public static function _receive()
    {
        $params = Yii::$app->request->post();
        return AccountingItemService::receive($params);
    }

    public function getAccountingData()
    {
        return $this->hasOne(AccountingData::class, ['id' => 'accounting_data_id']);
    }

    public function getAccounting()
    {
        return $this->hasOne(Accounting::class, ['id' => 'accounting_id']);
    }

    public function getPaidItems()
    {
        return $this->hasMany(AccountingItemPaid::class, ['accounting_id_main' => 'id']);
    }

    public function getContact()
    {
        return $this->hasOne(Contact::class, ['id' => 'contact_id']);
    }

    public function getBank()
    {
        return $this->hasOne(Bank::class, ['id' => 'bank_id']);
    }

    public function getMaterial()
    {
        return $this->hasOne(Material::class, ['id' => 'material_id']);
    }

    public function getProduct()
    {
        return $this->hasOne(Product::class, ['id' => 'product_id']);
    }

    public function getSubAccount()
    {
        return $this->hasOne(AccountingData::class, ['id' => 'sub_account_id']);
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

}
