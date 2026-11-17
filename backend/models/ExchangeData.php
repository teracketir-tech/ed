<?php

namespace frontend\modules\hq\models;

use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class ExchangeData extends ActiveRecord
{

    public static function tableName()
    {
        return 'exchange_data';
    } 

    public function rules()
    {
        return [
            [['id', 'state_id'], 'integer'],
            [['name'], 'safe'],
        ];
    }

    public static function get_all()
    {
        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = ExchangeData::find();

        unset($params['per-page'], $params['page']);

        // The rates are one shared set: the axios wrapper appends the caller's
        // company to every request, and this table holds a single global list
        // (every row carries company_id 1), so filtering on it showed an empty
        // price list to every other company. Only the field filters below apply.
        unset($params['company_id']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if ($key == 'id') {

                    $query->andWhere(['id' => $params['id']]);

                } else {

                    $query->andWhere(['like', $key, $value]);

                }
            }

        }

        $totalCount = $query->count();
        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['date' => SORT_DESC])->asArray(),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        $materials = $dataProvider->getModels();

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => $materials,
            
        ];
    }

    public static function import_by_excel()
    {
        $excel = $_FILES['file']['tmp_name'];

        if (!$excel) {
            return ['success' => false, 'message' => 'هیچ فایلی دریافت نشد'];
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excel);

        $sheet = $spreadsheet->getSheetByName('Sheet1');
        $data = $sheet->toArray(null, true, true, true);

        foreach ($data as $index => $row) {
            
            if ($index == 1)
                continue;

            $date = str_replace('/', '-', $row['A']);
            $usd = str_replace(',', '', $row['B']);
            $gold = str_replace(',', '', $row['C']);
            $aed = str_replace(',', '', $row['D']);

            if (!$date)
                continue;

            $ex = ExchangeData::find()->where(['fa_date' => $date])->one();

            if (!$ex) {

                $ex = new ExchangeData();
                $ex->date = Persian::convert_date_to_en($row['A']);
                $ex->fa_date = $date;

            }

            $ex->usd = (string)$usd;
            $ex->gold = (string)$gold ?? null;
            $ex->aed = (string)$aed ?? null;
            $ex->save(false);

        }

        return ['success' => true, 'message' => 'با موفقیت انجام شد'];
    }
}