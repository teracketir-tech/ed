<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Product extends ActiveRecord
{

    public static function tableName()
    {
        return 'product';
    }

    public function rules()
    {
        return [
            [['id', 'company_id', 'accounting_data_id'], 'integer'],
            // ایشوی ۸: واحدِ کالا — از فهرست واحدها (واحدِ سلکتِ مصالح هم از همین‌جا می‌آید)
            [['unit_id'], 'integer'],
            [['unit_id'], 'exist', 'targetClass' => Unit::class, 'targetAttribute' => 'id', 'message' => 'واحد انتخاب‌شده یافت نشد'],
            [['name'], 'safe'],
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

        $query = Product::find()->with('company', 'accountingData', 'unit');

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if (in_array($key, ['id', 'company_id'])) {

                    $query->andWhere([$key => $value]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['id' => SORT_DESC])->asArray(),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        $data = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $data,
            
        ];
    }

    public static function _save($id = null)
    {
        $params = Yii::$app->request->post();

        if (!$id) {

            User::checkAccess(501);

            $model = new Product();

        } else {

            User::checkAccess(503);

            $model = Product::findOne($id);

            if (!$model) {

                return [
                    'success' => false,
                    'message' => 'کالا با این شناسه یافت نشد',
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

        $model = Product::find()->where(['id' => $id])->one();

        if (!$model) {

            return [
                'success' => false,
                'message' => 'کالا با این شناسه یافت نشد',
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

        $seal = Product::findOne($id);

        if (!$seal) {
            throw new UnauthorizedHttpException('کالا با این شناسه یافت نشد');
        }

        $seal->delete();

        return ['success' => true];
    }

    public function getCompany()
    {
        return $this->hasOne(Company::class, ['id' => 'company_id']);
    }

    public function getAccountingData()
    {
        return $this->hasOne(AccountingData::class, ['id' => 'accounting_data_id']);
    }

    /** واحدِ کالا (ایشوی ۸) — همان واحدی که «افزودن مصالح» جلوی «مقدار» نشان می‌دهد */
    public function getUnit()
    {
        return $this->hasOne(Unit::class, ['id' => 'unit_id']);
    }
}
