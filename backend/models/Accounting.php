<?php

namespace frontend\modules\hq\models;

use common\constants\AccountingDataIds;
use frontend\models\AccountingItemPaid;
use frontend\modules\hq\services\AccountingFileService;
use frontend\modules\hq\services\AccountingItemService;
use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;
use frontend\modules\hq\models\Util;
use yii\web\NotFoundHttpException;

class Accounting extends ActiveRecord
{

    public static function tableName()
    {
        return 'accounting';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'user_id', 'number', 'autoNumber', 'business_id', 'project_id', 'rent_id', 'base_currency', 'type', 'flag'], 'integer'],
            [['type'], 'in', 'range' => [null, 1, 2]],
            [['imageFile'], 'file', 'extensions' => 'png, jpg, jpeg, gif', 'maxSize' => 1024 * 1024 * 2, 'skipOnEmpty' => true, 'on' => 'update'],
            [[
                'status',
                'date',
                'reference',
                'price',
                'total_debit',
                'total_credit',
                'prices',
                'usd',
                'gold',
                'meter',
                'aed',
                'description',
                'extraForContact',
                'approve_date',
                'vendor_product_price_id',
                'user_os',
                'user_ip',
                'image',
                'approve_image'
            ], 'safe'],
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

        $query = Accounting::find()->with([
            'company',
            'user',
            'project',
            'items',
            'items.accountingData',
            'items.contact',
            'items.product',
            'items.material',
            'items.material.color',
            'items.bank',
            'items.bank.bankName',
            'items.subAccount'
        ]);

        unset($params['per-page'], $params['page']);

        if ($params['accounting_data_id']) {

            $ids = explode(",", $params['accounting_data_id']);
            $accounting_ids = AccountingItem::find()
                ->select('accounting_id')
                ->where(['in', 'accounting_data_id', $ids])
                ->distinct()
                ->column();

            $query->andWhere(['in', 'id', $accounting_ids]);
            unset($params['accounting_data_id']);

        }

        if ($params['date']) {

            $range = explode(',', $params['date']);

            if (count($range) == 2) {

                $start = date('Y-m-d', strtotime($range[0]));
                $end = date('Y-m-d', strtotime($range[1]));

                $query->andWhere(['between', 'date', $start, $end]);

            }

            unset($params['date']);

        }

        if ($params['created_at']) {

            $range = explode(',', $params['created_at']);

            if (count($range) == 2) {

                $start = date('Y-m-d', strtotime($range[0])) . ' 00:00:00';
                $end = date('Y-m-d', strtotime($range[1])) . ' 23:59:59';

                $query->andWhere(['between', 'created_at', $start, $end]);

            }

            unset($params['created_at']);

        }

        // isset, not truthiness: the "no files" choice travels as a value
        // the truthy check would skip and silently drop the filter
        if (isset($params['image']) && $params['image'] !== '') {

            // "has files" spans both moments: registration and approval
            if ($params['image'] == 1)
                $query->andWhere(['or', ['is not', 'image', null], ['is not', 'approve_image', null]]);

            else
                $query->andWhere(['and', ['is', 'image', null], ['is', 'approve_image', null]]);

            unset($params['image']);

        }

        foreach ($params as $key => $value) {

            if (!empty($value) && !in_array($key, ['expand', 'excel'])) {

                if (in_array($key, ['id', 'company_id', 'user_id', 'project_id'])) {

                    $query->andWhere([$key => $value]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC])->asArray(),
            'pagination' => $params['excel'] ? false : ['pageSize' => $perPage, 'page' => $page - 1],
        ]);

        return [
            'totalCount' => $totalCount,
            'data' => $dataProvider->getModels(),
            'pages' => ceil($totalCount / $perPage),
            'accounting_data_list' => AccountingData::get_cache_data(),
            
        ];
    }

    public static function _save()
    {
        $user = \Yii::$app->user->identity;

        $params = Yii::$app->request->post();

        // a refused upload must never leave a half-created document behind
        $files = $_FILES['images'] ?? null;

        $validation = AccountingFileService::validate($files);

        if (!$validation['ok']) {

            return ['success' => false, 'message' => $validation['message']];
        }

        if ($params['pay_accounting_item_id']) {

            $pay_accounting_item = AccountingItem::findOne($params['pay_accounting_item_id']);

            if (!$pay_accounting_item) {

                return ['success' => false, 'message' => 'شناسه آیتم (پرداختنی / دریافتنی) اصلی اشتباه است'];

            }

        }

        if ($params['project_id']) {

            $bank_data = [];
            $project = Project::findOne($params['project_id']);

            foreach ($params['items'] as $item) {

                if ($item['accounting_data_id'] == AccountingDataIds::BANK && (int) $item['credit'] > 0) {

                    $bank_data[$item['detail_id']] = (int) $item['credit'];

                }
                
                if ($project->status != 1 && $item['accounting_data_id'] == AccountingDataIds::INITIAL_CAPITAL) {

                    return ['success' => false, 'message' => 'امکان ثبت سند سرمایه گذاری برای این پروژه وجود ندارد'];

                }

            }

            if (!empty($bank_data)) {

                $related_banks = self::get_related_banks(
                    $params['project_id'],
                    $params['company_id'] ?? null
                )['data'] ?? [];

                $has_balance = true;

                $related_banks_by_id = [];

                foreach ($related_banks as $r_bank) {

                    $related_banks_by_id[$r_bank['id']] = $r_bank;

                }

                foreach ($bank_data as $bankId => $requiredAmount) {

                    // بانک موردنیاز اصلاً در لیست بانک‌های مرتبط نیست
                    if (!isset($related_banks_by_id[$bankId])) {

                        $has_balance = false;
                        break;

                    }

                    $balance = abs($related_banks_by_id[$bankId]['balance']);

                    // موجودی کافی نیست
                    if ($balance < $requiredAmount) {

                        $has_balance = false;
                        break;

                    }
                }

                if (!$has_balance) {

                    return ['success' => false, 'message' => 'بانک مورد نظر برای این پروژه موجودی ندارد'];

                }

            }

        }

        User::checkAccess(501);

        $model = new Accounting();

        $total = ['debit' => 0, 'credit' => 0];

        if ($model->load($params, '') && $model->save()) {

            if (!$params['company_id']) {

                $model->company_id = null;

            }

            $pay_log_item = null;

            foreach ($params['items'] as $item) {

                unset($item['id']);

                $total['debit'] += (int)$item['debit'];
                $total['credit'] += (int)$item['credit'];

                $item_model = new AccountingItem();
                $item_model->load($item, '');
                $item_model->accounting_id = $model->id;

                if (!$item['description'])
                    $item_model->description = $params['description'];

                if ($params['company_id'])
                    $item_model->company_id = $params['company_id'];

                if (!$params['company_id']) {

                    $item_model->company_id = null;

                }

                // the same detail resolution an edit needs
                self::apply_detail($item_model, $item);

                $item_model->save(false);

                if (in_array($item_model->accounting_data_id, [AccountingDataIds::PAYABLE, AccountingDataIds::RECEIVABLE])) {

                    $pay_log_item = $item_model;

                }
            }

            $now = date('Y-m-d');
            $requestDate = date('Y-m-d', strtotime($params['date']));

            if ($now != $requestDate) {

                $model->flag = 1;

            }

            if ($params['status'] == 1) {

                $model->status = 'draft';

            } else {

                $model->status = 'approved';
                $model->approve_date = date('Y-m-d H:m:i');

            }

            $model->price = $total['debit'];
            $model->total_debit = $total['debit'];
            $model->total_credit = $total['credit'];

            if (isset($user->id)) {

                $model->user_id = $user->id ?? null;

            }

            if (!$params['user_ip']) {

                $model->user_ip = Util::user_ip();
                $model->user_os = Util::user_os();

            }

            if ($params['pay_accounting_item_id']) {

                AccountingItemService::set_pay_or_receive_log(
                    $pay_accounting_item,
                    $pay_log_item->id,
                    $params['price'],
                    $params['description'],
                    $model
                );

            }

            $model->save();

            if ($files) {

                // the registration moment owns `image`; failures fall back to a local url
                $urls = AccountingFileService::store($files);

                if ($urls) {

                    $model->image = AccountingFileService::append($model->image, $urls);
                    $model->save(false, ['image']);
                }
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

    public static function _approve ($id) 
    {
        $model = Accounting::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'سند با این شناسه یافت نشد',
            ];

        }

        // a refused upload must never approve the document behind it
        $files = $_FILES['images'] ?? null;

        $validation = AccountingFileService::validate($files);

        if (!$validation['ok']) {

            return ['success' => false, 'message' => $validation['message']];
        }

        $model->status = 'approved';
        $model->approve_date = date('Y-m-d H:m:i');

        if ($files) {

            // the approval moment owns `approve_image`; `image` is registration-only
            $urls = AccountingFileService::store($files);

            if ($urls) {

                $model->approve_image = AccountingFileService::append($model->approve_image, $urls);
            }
        }

        $model->save();

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    /**
     * Edits a document that is still a draft.
     *
     * Only drafts are editable: approving is _approve()'s job, so an edit can
     * never change the status. The items are rewritten wholesale - a row keeps
     * its id only when it belongs to this very document, so a payload can never
     * adopt another document's line. No bank balance has to be undone here:
     * unlike dash, hq derives it from accounting_item when it is read
     * (get_related_banks()), so rewriting the rows is enough.
     *
     * The registration files follow the same rule as dash's edit: the form
     * lists the stored urls that stay (kept_images) and sends only the files
     * just picked, so removing one in the form actually removes it. A client
     * that sends neither keeps what the document already held.
     */
    public static function _update($id)
    {
        $model = Accounting::find()->where(['id' => $id])->one();

        if (!$model) {

            return ['success' => false, 'message' => 'سند با این شناسه یافت نشد'];
        }

        if ($model->status !== 'draft') {

            return ['success' => false, 'message' => 'فقط سندهای پیش‌نویس قابل ویرایش هستند'];
        }

        $params = Yii::$app->request->post();
        $itemsData = $params['items'] ?? [];

        // the form lists the stored urls that stay; it goes before unset()
        $keptImages = $params['kept_images'] ?? null;

        if (empty($itemsData) || !is_array($itemsData)) {

            return ['success' => false, 'message' => 'اقلام سند ارسال نشده است'];
        }

        // a refused upload must never leave a half-edited document behind
        $files = $_FILES['images'] ?? null;

        $validation = AccountingFileService::validate($files);

        if (!$validation['ok']) {

            return ['success' => false, 'message' => $validation['message']];
        }

        // an edit may not re-point the row, rewrite who created it, or approve it
        unset(
            $params['id'],
            $params['user_id'],
            $params['user_os'],
            $params['user_ip'],
            $params['created_at'],
            $params['updated_at'],
            $params['approve_date'],
            $params['status'],
            $params['price'],
            $params['total_debit'],
            $params['total_credit'],
            $params['approve_image'],
            $params['items'],
            $params['images'],
            $params['kept_images'],
            $params['pay_accounting_item_id']
        );

        $transaction = Yii::$app->db->beginTransaction();

        try {

            $model->load($params, '');

            // an edit never approves the document; only _approve() may do that
            $model->status = 'draft';

            if (!$model->save()) {

                throw new \yii\web\BadRequestHttpException('خطا در ویرایش سند: ' . json_encode($model->getErrors()));
            }

            $total = ['debit' => 0, 'credit' => 0];

            // every row this edit writes - kept or brand new - survives it
            $writtenItemIds = [];

            foreach ($itemsData as $item) {

                // an id is only adopted when it is already one of this document's rows
                if (!empty($item['id']) && AccountingItem::find()->where(['id' => $item['id'], 'accounting_id' => $model->id])->exists()) {

                    $itemModel = AccountingItem::findOne($item['id']);

                } else {

                    $itemModel = new AccountingItem();
                }

                unset($item['id']);

                $total['debit'] += (int) ($item['debit'] ?? 0);
                $total['credit'] += (int) ($item['credit'] ?? 0);

                $itemModel->load($item, '');
                $itemModel->accounting_id = $model->id;

                if (empty($item['description'])) {

                    $itemModel->description = $model->description;
                }

                if (!isset($item['company_id'])) {

                    $itemModel->company_id = $model->company_id;
                }

                self::apply_detail($itemModel, $item);

                if (!$itemModel->save(false)) {

                    throw new \yii\web\BadRequestHttpException('خطا در ذخیره قلم سند: ' . json_encode($itemModel->getErrors()));
                }

                $writtenItemIds[] = $itemModel->id;
            }

            // the rows the payload no longer lists are gone
            AccountingItem::deleteAll([
                'and',
                ['accounting_id' => $model->id],
                ['not in', 'id', $writtenItemIds],
            ]);

            // the mismatch flag follows the date the edit actually saved
            $model->flag = (date('Y-m-d') !== date('Y-m-d', strtotime($model->date))) ? 1 : 0;

            $model->price = $total['debit'];
            $model->total_debit = $total['debit'];
            $model->total_credit = $total['credit'];

            $model->save();

            self::store_edit_files($model, $files, $keptImages);

            $transaction->commit();

            return [
                'success' => true,
                'data' => $model,
            ];

        } catch (\Exception $e) {

            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Turns a posted detail_id into the column the account books against.
     * A detail_id of 1 on an account_id account means "the parent account
     * itself", which books through sub_account_id instead.
     */
    private static function apply_detail($itemModel, $item)
    {
        if (empty($item['detail_id'])) {

            return;
        }

        $accounting_data = AccountingData::findOne($item['accounting_data_id'] ?? null);

        if (!$accounting_data || !$accounting_data->detail_name) {

            return;
        }

        if ($item['detail_id'] == 1 && $accounting_data->detail_name == 'account_id') {

            $itemModel->accounting_data_id = $accounting_data->parent_id;
            $itemModel->sub_account_id = $item['accounting_data_id'];

            return;
        }

        $itemModel->{$accounting_data->detail_name} = $item['detail_id'];
    }

    /** Rewrites the registration files after an edit; see _update() for the rule. */
    private static function store_edit_files($model, $files, $keptImages)
    {
        if ($keptImages === null && empty($files)) {

            return;
        }

        $kept = $keptImages !== null
            ? AccountingFileService::read($keptImages)
            : AccountingFileService::read($model->image);

        $new = $files ? AccountingFileService::store($files) : [];

        $model->image = json_encode(array_values(array_unique(array_merge($kept, $new))));
        $model->save(false, ['image']);
    }

    public static function _view($id)
    {
        User::checkAccess(502);

        $model = Accounting::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'سند با این شناسه یافت نشد',
            ];

        }

        // the edit form needs the rows. A relation is not part of toArray(),
        // and an AR object json_encodes to {}, so the rows come as arrays -
        // the same shape get_all() already hands the list page.
        $data = $model->toArray();
        $data['items'] = AccountingItem::find()
            ->with(['accountingData', 'contact', 'product', 'material', 'bank', 'subAccount'])
            ->where(['accounting_id' => $id])
            ->asArray()
            ->all();

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    public static function _delete($id)
    {
        $accounting = Accounting::findOne($id);

        if (!$accounting) {
            throw new NotFoundHttpException('سند با این شناسه یافت نشد');
        }

        $accounting_item_ids = AccountingItem::find()->select('id')->where(['accounting_id' => $id])->column();

        AccountingItem::deleteAll(['accounting_id' => $id]);

        $accounting->delete();

        $paid_or_received_list = AccountingItemPaid::find()->where(['in', 'accounting_id_paid', $accounting_item_ids])->all();

        $cancel_paid_or_received = [];

        foreach ($paid_or_received_list as $prl) {
            
            $cancel_paid_or_received[] = $prl->accounting_id_main;
            $prl->delete();

        }

        AccountingItem::updateAll(
            ['paid_or_received' => 0],
            ['id' => $cancel_paid_or_received]
        );

        return ['success' => true];
    }

    public static function import_by_excel()
    {
        ini_set('precision', 15);

        $user = Yii::$app->user->identity;

        if (empty($_FILES['file'])) {

            return ['success' => false, 'message' => 'فایل اکسل ارسال نشده است'];

        }

        $file = $_FILES['file']['tmp_name'];

        try {

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);

            $accountingSheet = $spreadsheet->getSheetByName('Accounting');

            if (!$accountingSheet) {

                return ['success' => false, 'message' => "شیت 'Accounting' یافت نشد."];

            }

            $accountingData = $accountingSheet->toArray(null, true, true, true);

            $itemsSheet = $spreadsheet->getSheetByName('Accounting Items');

            if (!$itemsSheet) {

                return ['success' => false, 'message' => "شیت 'Accounting Items' یافت نشد."];

            }

            $itemsData = $itemsSheet->toArray(null, true, true, true);

            // قبل از هر ذخیره‌سازی، بالانس بودن کل آیتم‌ها بررسی می‌شود
            $balance_check = self::check_items_balance($itemsData);

            if (!$balance_check['success']) {

                return $balance_check;

            }

            $accountingMap = [];

            $acc_obj = [];

            $result_info = [
                'success_accounting' => [],
                'failed_accounting' => [],
                'success_accounting_item' => [],
                'failed_accounting_item' => [],
            ];

            foreach ($accountingData as $index => $row) {
                if ($index === 1)
                    continue;

                $code = $row['A'] ?? null;

                if (!$code)
                    continue;

                $company_name = $row['F'] ?? null;
                $description = $row['B'] ?? null;
                $account_date = Persian::convert_date_to_en($row['C']) ?? '';
                $status = $row['D'] ?? 'draft';

                if ($company_name) {

                    $company = Company::find()->where(['like', 'name', $company_name])->one();

                }

                if ($user->company_id && $user->company_id != ($company->id ?? null)) {

                    $result_info['failed_accounting'][] = ['row' => $index, 'message' => 'شرکت مغایرت دارد'];
                    continue;

                }

                $import_data = [
                    'user_id' => $user->id,
                    'company_id' => $company ? $company->id : null,
                    'user_os' => Util::user_os(),
                    'user_ip' => Util::user_ip(),
                    'description' => $description,
                    'date' => $account_date,
                    'status' => $status,
                    'business_id' => 1,
                    'project_id' => $row['E'] ?? null,
                ];

                if ($status == 2)
                    $import_data['approve_date'] = date('Y-m-d H:m:i');

                $accounting = new Accounting();
                $accounting->attributes = $import_data;

                $now = date('Y-m-d');
                $requestDate = date('Y-m-d', strtotime($account_date));

                if ($now !== $requestDate) {

                    $accounting->flag = 1;

                }

                if (!$accounting->save()) {

                    $result_info['failed_accounting'][] = ['row' => $index, 'message' => implode(', ', array_merge(...array_values($accounting->getErrors())))];
                    continue;
                }

                $acc_obj[$accounting->id] = $accounting;
                $accountingMap[$code] = $accounting->id;

                $result_info['success_accounting'][] = ['row' => $index, 'id' => $accounting->id, 'message' => 'ثبت شد'];

            }

            $balance = [];

            foreach ($itemsData as $index => $row) {

                if ($index === 1)
                    continue;

                $code = $row['A'] ?? null;

                if (!$code)
                    continue;

                $company_name = $row['J'] ?? null;

                if ($company_name) {

                    $company = Company::find()->where(['like', 'name', $company_name])->one();

                }

                if ($user->company_id && $user->company_id != ($company->id ?? null)) {

                    $result_info['failed_accounting_item'][] = ['row' => $index, 'message' => 'شرکت مغایرت دارد'];
                    continue;

                }

                $item = new AccountingItem();
                $item->attributes = [
                    'company_id' => $company ? $company->id : null,
                    'accounting_id' => $accountingMap[$code],
                    'accounting_data_id' => $row['B'] ?? null,
                    'bank_id' => $row['H'] ?? null,
                    'contact_id' => $row['I'] ?? null,
                    'description' => $row['D'] ?? '',
                    'debit' => $row['E'] ?? 0,
                    'credit' => $row['F'] ?? 0,
                ];

                $balance[$accountingMap[$code]]['credit'] += (float)$item->credit;
                $balance[$accountingMap[$code]]['debit'] -= (float)$item->debit;

                if (!$item->save()) {

                    $result_info['failed_accounting_item'][] = ['row' => $index, 'message' => implode(', ', array_merge(...array_values($item->getErrors())))];
                    continue;

                }

                if (!empty($row['K'])) {

                    $pay_accounting_item = AccountingItem::findOne($row['K']);

                    if ($pay_accounting_item) {

                        $item_price = abs((float)$item->credit - (float)$item->debit);

                        AccountingItemService::set_pay_or_receive_log(
                            $pay_accounting_item,
                            $item->id,
                            $item_price,
                            $row['D'],
                            $accounting
                        );

                    }

                }

                $result_info['success_accounting_item'][] = ['row' => $index, 'id' => $item->id, 'message' => 'ثبت شد'];

            }

            foreach ($acc_obj as $acc__) {

                $acc__->total_debit = abs($balance[$acc__->id]['debit']);
                $acc__->total_credit = abs($balance[$acc__->id]['credit']);
                $acc__->save();

            }

            return ['success' => true, 'message' => 'با موفقیت انجام شد', 'result' => $result_info];

        } catch (\Exception $e) {

            return ['success' => false, 'message' => 'خطایی رخ داده است', 'result' => $result_info];
        }
    }

    /**
     * جمع debit و credit همه آیتم‌ها را قبل از ایمپورت مقایسه می‌کند
     * اگر برابر نباشند هیچ رکوردی نباید ذخیره شود
     *
     * جمع‌زنی و مقایسه با bcmath (ارقام ده‌دهی) انجام می‌شود چون جمع float
     * خطای انباشته دارد: 0.1 + 0.2 دقیقاً 0.3 نیست بلکه 0.30000000000000004
     * است. این روش بدون تلورانس و تا scale رقم اعشار دقیق مقایسه می‌کند.
     */
    private static function check_items_balance($itemsData)
    {
        $scale = 12; // حداکثر رقم اعشار موجود در فایل اکسل

        $use_bc = function_exists('bcadd') && function_exists('bccomp');

        $total_debit = $use_bc ? '0' : 0.0;
        $total_credit = $use_bc ? '0' : 0.0;

        foreach ($itemsData as $index => $row) {

            if ($index === 1)
                continue;

            $code = $row['A'] ?? null;

            if (!$code)
                continue;

            // نرمال‌سازی هر مقدار به scale رقم اعشار تا نویز اعشاری float وارد جمع نشود
            $debit = sprintf('%.' . $scale . 'F', (float)($row['E'] ?? 0));
            $credit = sprintf('%.' . $scale . 'F', (float)($row['F'] ?? 0));

            $total_debit = $use_bc ? bcadd($total_debit, $debit, $scale) : $total_debit + (float)$debit;
            $total_credit = $use_bc ? bcadd($total_credit, $credit, $scale) : $total_credit + (float)$credit;

        }

        $is_balanced = $use_bc
            ? bccomp($total_credit, $total_debit, $scale) === 0 // مقایسه دقیق، بدون تلورانس
            : abs($total_credit - $total_debit) <= 1e-9 * max(abs($total_credit), abs($total_debit), 1);

        if (!$is_balanced) {

            return [
                'success' => false,
                'message' => 'سند‌ها بالانس نیستند، مجموع بدهکار (' . $total_debit . ') با مجموع بستانکار (' . $total_credit . ') برابر نیست. هیچ رکوردی ذخیره نشد',
            ];

        }

        return ['success' => true];
    }

    public static function get_related_banks($project_id = null, $company_id = null, $show_all = 0)
    {
        $query = AccountingItem::find()
            ->with(['bank', 'bank.bankName'])
            ->where(['is not', 'bank_id', null])
            ->joinWith('accounting');

        if ($project_id)
            $query->andWhere(['accounting.project_id' => $project_id]);

        if ($company_id)
            $query->andWhere(['accounting.company_id' => $company_id]);

        $items = $query->asArray()->all();

        // Group balances by bank_id
        $bank_balances = [];

        foreach ($items as $item) {

            $bankId = $item['bank_id'];
            $debit = (float) $item['debit'];
            $credit = (float) $item['credit'];

            $bank_balances[$bankId] = ($bank_balances[$bankId] ?? 0) + $credit  - $debit;

        }

        // Filter and build the result array
        $banks = [];

        foreach ($items as $item) {

            if (!isset($item['bank']))
                continue;

            $bank_id = $item['bank_id'];

            if ($show_all == 1 || (isset($bank_balances[$bank_id]) && $bank_balances[$bank_id] < 0)) {

                $banks[$bank_id] = [...$item['bank'], 'balance' => $bank_balances[$bank_id]];

            }

        }

        return ['success' => true, 'data' => array_values($banks)];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getItems()
    {
        return $this->hasMany(AccountingItem::class, ['accounting_id' => 'id']);
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getProject()
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }

}
