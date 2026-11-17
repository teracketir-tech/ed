<?php

namespace frontend\modules\hq\models;

use common\constants\AccountingDataIds;
use Yii;
use yii\db\ActiveRecord;
use yii\web\BadRequestHttpException;

class AccountingData extends ActiveRecord
{

    public static function tableName()
    {
        return 'accounting_data';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'parent_id', 'disabled', 'selectable'], 'integer'],
            [[
                'title',
                'value ',
                'color',
                'detail_name',
                'accounting_group',
                'user_ip'
            ], 'safe'],
        ];
    }

    public static function get_all()
    {
        $params = Yii::$app->request->get();

        unset($params['per-page'], $params['page']);

        /*
        * -----------------------------------------
        * Base Query
        * -----------------------------------------
        */

        $query = AccountingData::find()
            ->select([
                'id',
                'company_id',
                'parent_id',
                'title',
                'detail_name'
            ])
            ->with(['parent', 'company']);


        /*
        * -----------------------------------------
        * Company Filter
        * -----------------------------------------
        */

        $companyId = $params['company_id'] ?? null;

        if ($companyId) {

            $query->andWhere([
                'or',
                ['is', 'parent_id', null],
                ['is', 'company_id', null],
                ['company_id' => $companyId]
            ]);
        }


        /*
        * -----------------------------------------
        * Find Selected Items
        * -----------------------------------------
        */

        $selectedList = [];


        /*
        * Search By ID
        */

        if (!empty($params['id'])) {

            $selectedList = AccountingData::find()
                ->select([
                    'id',
                    'company_id',
                    'parent_id',
                    'title'
                ])
                ->where(['id' => $params['id']])
                ->all();

            unset($params['id']);
        }


        /*
        * Search By Title
        */

        if (!empty($params['title'])) {

            $selectedList = AccountingData::find()
                ->select([
                    'id',
                    'company_id',
                    'parent_id',
                    'title'
                ])
                ->where([
                    'like',
                    'title',
                    $params['title']
                ]);

            if ($companyId) {

                $selectedList->andWhere([
                    'or',
                    ['is', 'company_id', null],
                    ['company_id' => $companyId]
                ]);

            }
            
            $selectedList = $selectedList->all();

            unset($params['title']);
        }


        /*
        * -----------------------------------------
        * Find Related Items
        * -----------------------------------------
        */

        $relatedIds = [];


        if (!empty($selectedList)) {

            /*
            * تمام AccountingData های قابل دسترس
            *
            * فقط یکبار می‌گیریم تا بتوانیم
            * parent / child ها را در PHP پیدا کنیم.
            */

            $allData = AccountingData::find()
                ->select([
                    'id',
                    'company_id',
                    'parent_id',
                    'title'
                ]);

            if ($companyId) {

                $allData->where([
                    'or',
                    ['is', 'company_id', null],
                    ['company_id' => $companyId]
                ]);

            }
                
            $allData = $allData->asArray()->all();


            /*
            * -----------------------------------------
            * Index By ID
            * -----------------------------------------
            */

            $byId = [];

            /*
            * -----------------------------------------
            * Index Children By Parent ID
            * -----------------------------------------
            */

            $children = [];


            foreach ($allData as $data) {

                $id = (int) $data['id'];

                $byId[$id] = $data;

                if ($data['parent_id'] !== null) {

                    $parentId = (int) $data['parent_id'];

                    $children[$parentId][] = $id;
                }
            }


            /*
            * -----------------------------------------
            * Selected IDs
            * -----------------------------------------
            */

            $selectedIds = [];

            foreach ($selectedList as $item) {

                $id = (int) $item->id;

                $selectedIds[] = $id;

                $relatedIds[$id] = true;
            }


            /*
            * -----------------------------------------
            * Find ALL PARENTS
            *
            * Selected
            *    ↑
            * Parent
            *    ↑
            * Parent
            *    ↑
            * Root
            * -----------------------------------------
            */

            $currentIds = $selectedIds;

            while (!empty($currentIds)) {

                $nextIds = [];

                foreach ($currentIds as $currentId) {

                    if (!isset($byId[$currentId])) {
                        continue;
                    }

                    $parentId = $byId[$currentId]['parent_id'];

                    if ($parentId === null) {
                        continue;
                    }

                    $parentId = (int) $parentId;

                    /*
                    * اگر قبلاً اضافه نشده
                    */

                    if (!isset($relatedIds[$parentId])) {

                        $relatedIds[$parentId] = true;

                        $nextIds[] = $parentId;
                    }
                }

                $currentIds = $nextIds;
            }


            /*
            * -----------------------------------------
            * Find ALL CHILDREN
            *
            * Selected
            *    ↓
            * Child
            *    ↓
            * Grandchild
            *    ↓
            * ...
            *
            * مهم:
            *
            * از selectedIds شروع می‌کنیم،
            * نه relatedIds.
            *
            * بنابراین child های parent هایی که
            * صرفاً برای مسیر بالا پیدا شده‌اند
            * وارد نتیجه نمی‌شوند.
            * -----------------------------------------
            */

            $currentIds = $selectedIds;

            while (!empty($currentIds)) {

                $nextIds = [];

                foreach ($currentIds as $parentId) {

                    if (empty($children[$parentId])) {
                        continue;
                    }

                    foreach ($children[$parentId] as $childId) {

                        if (!isset($relatedIds[$childId])) {

                            $relatedIds[$childId] = true;

                            $nextIds[] = $childId;
                        }
                    }
                }

                $currentIds = $nextIds;
            }


            /*
            * تبدیل associative array به ID list
            */

            $relatedIds = array_keys($relatedIds);


            /*
            * فقط آیتم‌های مرتبط
            */

            if (!empty($relatedIds)) {

                $query->andWhere([
                    'in',
                    'id',
                    $relatedIds
                ]);
            }
        }


        /*
        * -----------------------------------------
        * Find Parent IDs
        * -----------------------------------------
        */

        $parentIdsQuery = AccountingData::find()
            ->select([
                'parent_id',
                'company_id'
            ])
            ->where([
                'not',
                ['parent_id' => null]
            ]);

        if ($companyId) {

            $parentIdsQuery->andWhere([
                'or',
                ['is', 'company_id', null],
                ['company_id' => $companyId]
            ]);
        }

        $parentRows = $parentIdsQuery
            ->asArray()
            ->all();

        $parentIds = [];

        foreach ($parentRows as $row) {
            $parentIds[(int) $row['parent_id']] = true;
        }


        /*
        * -----------------------------------------
        * Build Response
        * -----------------------------------------
        */

        $accountings = [];

        foreach (
            $query
                ->orderBy(['id' => SORT_ASC])
                ->all()
            as $account
        ) {

            $accountings[] = [
                'id' => $account->id,
                'company' => $account->company,
                'company_id' => $account->company_id,
                'parent' => $account->parent,
                'parent_id' => $account->parent_id,
                'title' => $account->title,
                'detail_name' => $account->detail_name,
                'is_parent' => isset($parentIds[$account->id]),
            ];
        }

        /*
        * -----------------------------------------
        * Return
        * -----------------------------------------
        */

        return [
            'data' => $accountings,
            'related' => $relatedIds
        ];
    }

    public static function _report()
    {
        $params = Yii::$app->request->get();

        if (
            empty($params['accounting_data_id']) &&
            empty($params['bank_id']) &&
            empty($params['contact_id']) &&
            empty($params['date'])
        ) {
            return [];
        }

        /*
        * Accounting Data
        */

        $accountingDataList = AccountingData::find()
            ->where([
                'or',
                ['is', 'company_id', null],
                ['company_id' => $params['company_id']]
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $accountingDataById = [];

        foreach ($accountingDataList as $account) {
            $accountingDataById[$account->id] = $account;
        }

        /*
        * Children Map
        */

        $childrenMap = [];

        foreach ($accountingDataList as $account) {
            if ($account->parent_id !== null) {
                $childrenMap[$account->parent_id][] = $account->id;
            }
        }

        /*
        * Selected Accounting Data IDs
        */

        $accountingDataIds = [];

        if (!empty($params['accounting_data_id'])) {
            $accountingDataIds = array_filter(
                array_map(
                    'intval',
                    explode(',', $params['accounting_data_id'])
                )
            );
        }

        /*
        * Visible IDs
        * Selected nodes + all parents
        */

        $visibleIds = [];

        if (!empty($accountingDataIds)) {
            foreach ($accountingDataIds as $id) {

                if (!isset($accountingDataById[$id])) {
                    continue;
                }

                $currentId = $id;

                while (
                    $currentId !== null &&
                    isset($accountingDataById[$currentId])
                ) {
                    if (isset($visibleIds[$currentId])) {
                        break;
                    }

                    $visibleIds[$currentId] = true;

                    $currentId = $accountingDataById[$currentId]->parent_id;
                }
            }

            $accountingDataList = array_values(
                array_filter(
                    $accountingDataList,
                    function ($account) use ($visibleIds) {
                        return isset($visibleIds[$account->id]);
                    }
                )
            );
        }

        /*
        * Memoized Descendants
        */

        $descendantsCache = [];

        $getDescendants = function ($id) use (
            &$getDescendants,
            &$descendantsCache,
            &$childrenMap
        ) {
            if (isset($descendantsCache[$id])) {
                return $descendantsCache[$id];
            }

            $result = [$id];

            foreach ($childrenMap[$id] ?? [] as $childId) {
                $result = array_merge(
                    $result,
                    $getDescendants($childId)
                );
            }

            return $descendantsCache[$id] = $result;
        };

        /*
        * Selected Subtree IDs
        * The account filter means a whole subtree, not only the picked node -
        * picking a parent would otherwise match no document line at all.
        */

        $selectedSubtreeIds = [];

        if (!empty($accountingDataIds)) {
            foreach ($accountingDataIds as $id) {

                if (!isset($accountingDataById[$id])) {
                    continue;
                }

                foreach ($getDescendants($id) as $descendantId) {
                    $selectedSubtreeIds[$descendantId] = true;
                }
            }
        }

        /*
        * Accounting IDs
        */

        $accountingQuery = Accounting::find()
            ->select(['accounting.id'])
            ->joinWith('items', false)
            ->where([
                'accounting.company_id' => $params['company_id']
            ])
            ->distinct();

        /*
        * Project
        */

        if (!empty($params['project_id'])) {

            $projectIds = array_filter(
                array_map(
                    'intval',
                    explode(',', $params['project_id'])
                )
            );

            if (!empty($projectIds)) {
                $accountingQuery->andWhere([
                    'in',
                    'accounting.project_id',
                    $projectIds
                ]);
            }
        }

        /*
        * Date
        */

        if (!empty($params['date'])) {

            $dateRange = explode(',', $params['date']);

            if (count($dateRange) === 2) {

                $start = date(
                    'Y-m-d',
                    strtotime($dateRange[0])
                );

                $end = date(
                    'Y-m-d',
                    strtotime($dateRange[1])
                );

                $accountingQuery->andWhere([
                    'between',
                    'accounting.date',
                    $start,
                    $end
                ]);
            }
        }

        /*
        * Accounting Data Filter
        */

        if (!empty($selectedSubtreeIds)) {
            $accountingQuery->andWhere([
                'in',
                'accounting_item.accounting_data_id',
                array_keys($selectedSubtreeIds)
            ]);
        }

        $accountingIds = $accountingQuery->column();

        if (empty($accountingIds)) {
            return [
                'data' => []
            ];
        }

        /*
        * Accounting Items
        */

        $itemsQuery = AccountingItem::find()
            ->select([
                'accounting_data_id',
                'bank_id',
                'contact_id',
                'debit',
                'credit',
            ])
            ->where([
                'in',
                'accounting_id',
                $accountingIds
            ]);

        /*
        * Accounting Data Filter
        * Without it every sibling line of the matched documents (bank, cash,
        * furniture, ...) would be summed into their parent rows.
        */

        if (!empty($selectedSubtreeIds)) {
            $itemsQuery->andWhere([
                'in',
                'accounting_data_id',
                array_keys($selectedSubtreeIds)
            ]);
        }

        /*
        * Contact Filter
        */

        if (!empty($params['contact_id'])) {
            $itemsQuery->andWhere([
                'contact_id' => $params['contact_id']
            ]);
        }

        /*
        * Bank Filter
        */

        if (!empty($params['bank_id'])) {
            $itemsQuery->andWhere([
                'bank_id' => $params['bank_id']
            ]);
        }

        $items = $itemsQuery
            ->asArray()
            ->all();

        /*
        * Direct Report By Accounting Data
        */

        $directReport = [];

        /*
        * Direct Items
        */

        $directItems = [];

        foreach ($items as $item) {

            $accountingDataId = (int) $item['accounting_data_id'];

            $debit = (float) $item['debit'];
            $credit = (float) $item['credit'];

            if (!isset($directReport[$accountingDataId])) {
                $directReport[$accountingDataId] = [
                    'debit' => 0,
                    'credit' => 0,
                ];
            }

            $directReport[$accountingDataId]['debit'] += $debit;
            $directReport[$accountingDataId]['credit'] += $credit;

            /*
            * Bank Items
            */

            if ($accountingDataId == AccountingDataIds::BANK) {

                if ($item['bank_id'] === null) {
                    continue;
                }

                $bankId = (int) $item['bank_id'];

                if (!isset($directItems[$accountingDataId][$bankId])) {
                    $directItems[$accountingDataId][$bankId] = [
                        'bank_id' => $bankId,
                        'total_debit' => 0,
                        'total_credit' => 0,
                    ];
                }

                $directItems[$accountingDataId][$bankId]['total_debit'] += $debit;
                $directItems[$accountingDataId][$bankId]['total_credit'] += $credit;

            } else {

                /*
                * Contact Items
                */

                if ($item['contact_id'] === null) {
                    continue;
                }

                $contactId = (int) $item['contact_id'];

                if (!isset($directItems[$accountingDataId][$contactId])) {
                    $directItems[$accountingDataId][$contactId] = [
                        'contact_id' => $contactId,
                        'total_debit' => 0,
                        'total_credit' => 0,
                    ];
                }

                $directItems[$accountingDataId][$contactId]['total_debit'] += $debit;
                $directItems[$accountingDataId][$contactId]['total_credit'] += $credit;
            }
        }

        /*
        * Report
        */

        $report = [];

        /*
        * Items
        */

        $accountingItemList = [];

        foreach ($accountingDataList as $account) {

            $relatedIds = $getDescendants($account->id);

            $totalDebit = 0;
            $totalCredit = 0;

            $itemsById = [];

            foreach ($relatedIds as $relatedId) {

                if (isset($directReport[$relatedId])) {

                    $totalDebit += $directReport[$relatedId]['debit'];
                    $totalCredit += $directReport[$relatedId]['credit'];
                }

                if (!empty($directItems[$relatedId])) {

                    foreach ($directItems[$relatedId] as $itemId => $item) {

                        if (!isset($itemsById[$itemId])) {

                            $itemsById[$itemId] = $item;

                        } else {

                            $itemsById[$itemId]['total_debit'] += $item['total_debit'];
                            $itemsById[$itemId]['total_credit'] += $item['total_credit'];
                        }
                    }
                }
            }

            $report[$account->id] = [
                'debit' => round($totalDebit),
                'credit' => round($totalCredit),
                'total' => round($totalCredit - $totalDebit),
            ];

            $accountingItemList[$account->id] = array_values(
                array_map(
                    function ($item) {
                        $item['total_debit'] = round($item['total_debit']);
                        $item['total_credit'] = round($item['total_credit']);

                        return $item;
                    },
                    $itemsById
                )
            );
        }

        /*
        * Parent IDs
        */

        $parentIds = [];

        foreach ($accountingDataById as $account) {

            if ($account->parent_id !== null) {
                $parentIds[$account->parent_id] = true;
            }
        }

        /*
        * Load Bank / Contact relations
        */

        $bankIds = [];
        $contactIds = [];

        foreach ($accountingItemList as $accountItems) {

            foreach ($accountItems as $item) {

                if (isset($item['bank_id'])) {
                    $bankIds[] = $item['bank_id'];
                }

                if (isset($item['contact_id'])) {
                    $contactIds[] = $item['contact_id'];
                }
            }
        }

        $bankIds = array_unique($bankIds);
        $contactIds = array_unique($contactIds);

        /*
        * Banks
        */

        $banks = [];

        if (!empty($bankIds)) {

            $banks = Bank::find()
                ->with('bankName')
                ->where([
                    'in',
                    'id',
                    $bankIds
                ])
                ->indexBy('id')
                ->asArray()
                ->all();
        }

        /*
        * Contacts
        */

        $contacts = [];

        if (!empty($contactIds)) {

            $contacts = Contact::find()
                ->where([
                    'in',
                    'id',
                    $contactIds
                ])
                ->indexBy('id')
                ->asArray()
                ->all();
        }

        /*
        * Attach Relations
        */

        foreach ($accountingItemList as $accountId => &$accountItems) {

            foreach ($accountItems as &$item) {

                if (isset($item['bank_id'])) {

                    $item['bank'] = $banks[$item['bank_id']] ?? null;
                }

                if (isset($item['contact_id'])) {

                    $item['contact'] = $contacts[$item['contact_id']] ?? null;
                }
            }
        }

        unset($accountItems, $item);

        /*
        * Final Data
        */

        $data = [];

        foreach ($accountingDataList as $account) {

            $data[] = [
                'id' => $account->id,
                'company' => $account->company,
                'company_id' => $account->company_id,
                'parent' => $account->parent,
                'parent_id' => $account->parent_id,
                'title' => $account->title,
                'detail_name' => $account->detail_name,
                'is_parent' => isset($parentIds[$account->id]),
                'items' => $accountingItemList[$account->id] ?? [],
                'report' => $report[$account->id] ?? [
                    'debit' => 0,
                    'credit' => 0,
                    'total' => 0,
                ],
            ];
        }

        return [
            'data' => array_values($data)
        ];
    }
    
    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new AccountingData();

        } else {

            User::checkAccess(503);

            $model = AccountingData::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'حساب با این شناسه یافت نشد',
                ];

            }

            $has_accounting_items = AccountingItem::find()
                ->where(['accounting_data_id' => $model->id])
                ->exists();

            if ($has_accounting_items) {

                throw new BadRequestHttpException('برای این حساب تراکنش مالی ثبت شده است. امکان ویرایش وجود ندارد');

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

        $model = AccountingData::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'حساب با این شناسه یافت نشد',
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

        $model = AccountingData::findOne($id);

        if (!$model) {
            throw new UnauthorizedHttpException('حساب با این شناسه یافت نشد');
        }

        $related_ids = [$model->id];
        $accounting_data = AccountingData::find()->select(['id', 'parent_id'])->orderBy(['id' => SORT_ASC])->all();

        foreach ($accounting_data as $data) {
            if (in_array($data->parent_id, $related_ids)) {
                $related_ids[] = $data->id;
            }
        }

        $has_accounting = AccountingItem::find()->where(['in', 'accounting_data_id', $related_ids])->count();

        if ($has_accounting > 0)
            return ['success' => false, 'message' => "برای این حساب و یا حساب های زیرمجموعه تراکنش مالی ثبت شده است. امکان حذف وجود ندارد"];

        if ($model->delete()) {

            AccountingData::deleteAll(['in', 'id', $related_ids]);
            return ['success' => true];

        }

        return ['success' => false, 'message' => 'خطایی رخ داده است'];
    }

    public static function get_cache_data()
    {
        return Yii::$app->cache->getOrSet('accounting_data_list', function () {

            return AccountingData::find()->select(['id', 'title', 'detail_name', 'parent_id'])->all();

        }, 86400);
    }

    public function getParent()
    {
        return $this->hasOne(AccountingData::class, ['id' => 'parent_id']);
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

}
