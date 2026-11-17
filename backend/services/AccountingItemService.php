<?php

namespace frontend\modules\hq\services;

use common\constants\AccountingDataIds;
use frontend\models\AccountingItemPaid;
use Yii;
use frontend\modules\hq\models\Util;
use frontend\modules\hq\models\Accounting;
use frontend\modules\hq\models\AccountingItem;

class AccountingItemService
{
    public static function pay($params)
    {
        // a refused upload must never create the settlement document
        $validation = AccountingFileService::validate($_FILES['images'] ?? null);

        if (!$validation['ok']) {

            return ['success' => false, 'message' => $validation['message']];
        }

        if ($params['pay_accounting_item_id']) {

            $pay_accounting_item = AccountingItem::findOne($params['pay_accounting_item_id']);

            if (!$pay_accounting_item) {

                return ['success' => false, 'message' => 'شناسه آیتم (پرداختنی / دریافتنی) اصلی اشتباه است'];

            }

        }

        $user = Yii::$app->user->identity;

        $item = AccountingItem::findOne($params['id']);
        $accounting = Accounting::findOne($item->accounting_id);

        $price = $params['price'];

        $flag = date('Y-m-d', strtotime($params['date'])) != date('Y-m-d') ? 1 : 0;

        $accounting_model = new Accounting();
        $accounting_model->load([
            'user_id' => $user->id,
            'user_os' => Util::user_os(),
            'user_ip' => Util::user_ip(),
            'company_id' => $accounting->company_id,
            'project_id' => $accounting->project_id,
            'description' => "پرداخت سند $item->id",
            'total_credit' => (string) $price,
            'total_debit' => (string) $price,
            'price' => (string) $price,
            'date' => $params['date'] ?? date('Y-m-d'),
            'approve_date' => $params['date'] ? ($params['date'] . ' 00:00:00') : date('Y-m-d H:m:i'),
            'status' => 'approved',
            'flag' => $flag,
        ], '');

        $accounting_model->save(false);

        self::store_uploaded_files($accounting_model);

        $accounting_items = [
            [
                'accounting_id' => $accounting_model->id,
                'company_id' => $accounting->company_id,
                "accounting_data_id" => AccountingDataIds::PAYABLE,
                "detail" => $item->contact_id,
                'description' => "پرداخت سند $item->id",
                "debit" => (string) $price,
                "credit" => '0',
                "contact_id" => $item->contact_id,
            ],
            [
                'accounting_id' => $accounting_model->id,
                'company_id' => $accounting->company_id,
                "accounting_data_id" => $params['accounting_data_id'],
                "detail" => $params['detail_id'],
                'description' => "پرداخت سند $item->id",
                "debit" => '0',
                "credit" => (string) $price,
            ]
        ];

        if (!empty($params['detail_name'])) {

            $accounting_items[1][$params['detail_name']] = $params['detail_id'];

        }

        $pay_log_item_id = null;

        foreach ($accounting_items as $accounting_item) {
            
            $accounting_item_model = new AccountingItem();
            $accounting_item_model->load($accounting_item, '');
            $accounting_item_model->save();

            if (in_array($accounting_item_model->accounting_data_id, [AccountingDataIds::PAYABLE, AccountingDataIds::RECEIVABLE])) {

                $pay_log_item_id = $accounting_item_model->id;

            }

        }

        self::set_pay_or_receive_log($item, $pay_log_item_id, $price);

        if ($params['pay_accounting_item_id']) {

            return self::receive([
                'date' => $params['date'],
                'price' => $params['price'],
                'detail_id' => $params['detail_id'],
                'detail_name' => $params['detail_name'],
                'id' => $params['pay_accounting_item_id'],
                'accounting_data_id' => AccountingDataIds::PAYABLE,
            ]);

        }

        return ['success' => true];
    }

    public static function receive($params)
    {
        // a refused upload must never create the settlement document
        $validation = AccountingFileService::validate($_FILES['images'] ?? null);

        if (!$validation['ok']) {

            return ['success' => false, 'message' => $validation['message']];
        }

        if ($params['pay_accounting_item_id']) {

            $pay_accounting_item = AccountingItem::findOne($params['pay_accounting_item_id']);

            if (!$pay_accounting_item) {

                return ['success' => false, 'message' => 'شناسه آیتم (پرداختنی / دریافتنی) اصلی اشتباه است'];

            }

        }

        $user = Yii::$app->user->identity;

        $item = AccountingItem::findOne($params['id']);
        $accounting = Accounting::findOne($item->accounting_id);

        $price = $params['price'];

        $flag = date('Y-m-d', strtotime($params['date'])) != date('Y-m-d') ? 1 : 0;

        $accounting_model = new Accounting();
        $accounting_model->load([
            'user_id' => $user->id,
            'user_os' => Util::user_os(),
            'user_ip' => Util::user_ip(),
            'company_id' => $accounting->company_id,
            'project_id' => $accounting->project_id,
            'description' => "دریافت سند $item->id",
            'total_credit' => (string) $price,
            'total_debit' => (string) $price,
            'price' => (string) $price,
            'date' => $params['date'] ?? date('Y-m-d'),
            'approve_date' => $params['date'] ? ($params['date'] . ' 00:00:00') : date('Y-m-d H:m:i'),
            'status' => 'approved',
            'flag' => $flag,
        ], '');

        $accounting_model->save(false);

        self::store_uploaded_files($accounting_model);

        $accounting_items = [
            [
                'accounting_id' => $accounting_model->id,
                'company_id' => $accounting->company_id,
                "accounting_data_id" => AccountingDataIds::RECEIVABLE,
                "detail" => $item->contact_id,
                'description' => "دریافت سند $item->id",
                "debit" => '0',
                "credit" => (string) $price,
                "contact_id" => $item->contact_id,
            ],
            [
                'accounting_id' => $accounting_model->id,
                'company_id' => $accounting->company_id,
                "accounting_data_id" => $params['accounting_data_id'],
                "detail" => $params['detail_id'],
                'description' => "دریافت سند $item->id",
                "debit" => (string) $price,
                "credit" => '0',
            ]
        ];

        if (!empty($params['detail_name'])) {

            $accounting_items[1][$params['detail_name']] = $params['detail_id'];

        }

        $receive_log_item_id = null;

        foreach ($accounting_items as $accounting_item) {
            
            $accounting_item_model = new AccountingItem();
            $accounting_item_model->load($accounting_item, '');
            $accounting_item_model->save();

            if (in_array($accounting_item_model->accounting_data_id, [AccountingDataIds::PAYABLE, AccountingDataIds::RECEIVABLE])) {

                $receive_log_item_id = $accounting_item_model->id;

            }

        }

        self::set_pay_or_receive_log($item, $receive_log_item_id, $price);

        if ($params['pay_accounting_item_id']) {

            return self::pay([
                'date' => $params['date'],
                'price' => $params['price'],
                'detail_id' => $params['detail_id'],
                'detail_name' => $params['detail_name'],
                'id' => $params['pay_accounting_item_id'],
                'accounting_data_id' => AccountingDataIds::RECEIVABLE,
            ]);

        }

        return ['success' => true];
    }

    public static function set_pay_or_receive_log($main_item, $paid_or_received_id, $price, $description = null, $model = null)
    {
        if ($model) {

            $desc = (int)$main_item->credit > 0 ? 'پرداخت سند ' . $main_item->id : 'دریافت سند ' . $main_item->id;
            $model->description = $desc . '. ' . $description;
            $model->save();

        }

        // create new log
        $pay_model = new AccountingItemPaid();
        $pay_model->accounting_id_main = $main_item->id;
        $pay_model->accounting_id_paid = $paid_or_received_id;
        $pay_model->price = $price;
        $pay_model->save();

        $main_item_price = abs((float)$main_item->credit - (float)$main_item->debit);

        $paid_or_received = AccountingItemPaid::find()->where(['accounting_id_main' => $main_item->id])->sum('price');

        if ((int)$main_item_price == (int)$paid_or_received) {

            $main_item->paid_or_received = 1;
            $main_item->save();

        }
    }

    /**
     * Stores the modal's `images[]` on the settlement document - its
     * registration moment. The batch is consumed here so the pay/receive
     * recursion never uploads the same files twice.
     */
    private static function store_uploaded_files($accounting_model)
    {
        $files = $_FILES['images'] ?? null;

        if (!$files) {

            return;
        }

        $urls = AccountingFileService::store($files);

        unset($_FILES['images']);

        if ($urls) {

            // a brand-new document has nothing to append to
            $accounting_model->image = AccountingFileService::append($accounting_model->image, $urls);
            $accounting_model->save(false, ['image']);
        }
    }
}