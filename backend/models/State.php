<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

class State extends ActiveRecord
{

    public static function tableName()
    {
        return 'state'; 
    }

    public function rules()
    {
        return [
            [['id'], 'integer'],
            [['name'], 'safe'],
        ];
    }

    public static function get_all()
    {
        $states = State::find()->all();
        return ['data' => $states];
    }

    public static function get_cities($id)
    {
        $cities = City::find()->where(['state_id' => $id])->all();
        return ['data' =>  $cities];
    }

}