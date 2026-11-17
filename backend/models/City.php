<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

class City extends ActiveRecord
{

    public static function tableName()
    {
        return 'city';
    } 

    public function rules()
    {
        return [
            [['id', 'state_id'], 'integer'],
            [['name'], 'safe'],
        ];
    }

    public static function _view($q)
    {
        return City::find()->where(['or', ['id' => $q], ['like', 'name', $q]])->one();
    }

}