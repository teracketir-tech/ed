<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

class BankName extends ActiveRecord
{

    public static function tableName()
    {
        return 'bank_name';
    }

    public function rules()
    {
        return [
            [['id'], 'integer'],
            [['name', 'img'], 'safe'],
        ];
    }
}
