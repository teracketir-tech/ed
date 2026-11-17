<?php

namespace frontend\modules\hq\models;

use frontend\modules\seal\models\Color;
use frontend\modules\seal\models\Unit;
use frontend\modules\seal\models\Wire;
use Yii;
use yii\db\ActiveRecord;
use yii\data\ActiveDataProvider;

class Material extends ActiveRecord
{
    public static function tableName()
    {
        return 'material';
    }

    public function rules()
    {
        return [
            [[
                'id',
                'company_id',
                'unit_id',
                'color_id',
                'wire_id',
                'size_id',
                'type',
                'material_type',
                'in_order',
                'required_quantity_in_make',
                'use_qty_type',
                'final_weight',
                'minimum_purchase_request_balance',
        ], 'integer'],
            [['name', 'ingredient'], 'safe'],
        ];
    }

    public static function get_all()
    {
        $params = Yii::$app->request->getQueryParams();

        $perPage = isset($params['per-page']) ? (int) $params['per-page'] : 10;
        $page = isset($params['page']) ? (int) $params['page'] : 1;

        $query = Material::find()->with(['color', 'wire', 'unit']);

        unset($params['per-page'], $params['page']);

        foreach ($params as $key => $value) {

            if (!empty($value) && $key != 'expand') {

                if (in_array($key, ['id', 'in_order', 'color_id', 'wire_id'])) {

                    $query->andWhere(["material.$key" => $value]);

                } else {

                    $query->andWhere(['like', "material.$key", $value]);

                }
            }
        }

        $totalCount = $query->count();

        $dataProvider = new ActiveDataProvider([
            'query' => $query->orderBy(['material.id' => SORT_DESC]),
            'pagination' => [
                'pageSize' => $perPage,
                'page' => $page - 1,
            ],
        ]);

        return [
            'pages' => ceil($totalCount / $perPage),
            'totalCount' => $totalCount,
            'data' => self::format_data($dataProvider->getModels()),            
        ];
    }

    public static function format_data($data )
    {
        $formated_data = [];

        foreach ($data as $item) {

            if ($item->color) {

                $relation = $item->color;
                
            } else {

                $relation = null;

            }

            $relation_name = $relation ? ' (' . $relation->name . ')' : '';

            $formated_data[] = [
                'id' => $item->id,
                'company_id' => $item->company_id,
                'value' => $item->id,
                'relation_name' => $relation->name ?? '',
                'original_name' => $item->name,
                'name' => $item->name . $relation_name,
                'label' => $item->name . $relation_name,
                'wire_id' => $item->wire_id,
                'wire' => $item->wire,
                'color_id' => $item->color_id,
                'color' => $item->color,
                'type' => $item->type,
                'unit' => $item->unit,
                'unit_id' => $item->unit_id,
            ];

        }

        return $formated_data;
    }

    public function getUnit()
    {
        return $this->hasOne(Unit::class, ['id' => 'unit_id']);
    }

    public function getColor()
    {
        return $this->hasOne(Color::class, ['id' => 'color_id']);
    }

    public function getWire()
    {
        return $this->hasOne(Wire::class, ['id' => 'wire_id']);
    }
}
