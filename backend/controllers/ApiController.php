<?php

namespace frontend\controllers;

use frontend\models\City;
use Yii;
use frontend\models\User;
use frontend\models\Project;
use frontend\models\Contact;
use yii\rest\Controller;
use frontend\models\Accounting;
use frontend\models\CompanyBank;
use frontend\models\AccountingData;
use frontend\models\AccountingItem;

class ApiController extends Controller
{
    public function actionContact($id = null)
    {
        $method = Yii::$app->request->method;

        // create or edit
        if ($method == 'POST') {

            return Contact::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return Contact::_delete($id);

        }
    }

    public function actionUser($id = null)
    {
        $method = Yii::$app->request->method;

        // create or edit
        if ($method == 'POST') {

            return User::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return User::_delete($id);

        }
    }

    public function actionProject($id = null)
    {
        $method = Yii::$app->request->method;

        // create or edit
        if ($method == 'POST') {

            return Project::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return Project::_delete($id);

        }
    }

    public function actionBank($id = null)
    {
        $method = Yii::$app->request->method;

        // create or edit
        if ($method == 'POST') {

            return CompanyBank::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return CompanyBank::_delete($id);

        }
    }

    public function actionCompanyBank($id = null)
    {
        $method = Yii::$app->request->method;

        // get all
        if ($method == 'GET') {

            return CompanyBank::get_all();

        }

        // create or edit
        if ($method == 'POST') {

            return CompanyBank::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return CompanyBank::_delete($id);

        }
    }

    public function actionAccountingData($id = null)
    {
        $method = Yii::$app->request->method;

        // get
        if ($method == 'GET') {

            if ($id) {

                return AccountingData::_view($id);

            } else {

                return AccountingData::get_all();

            }

        }

        // create or edit
        if ($method == 'POST') {

            return AccountingData::_save($id);

        }

        // delete
        if ($method == 'DELETE') {

            return AccountingData::_delete($id);

        }
    }

    public function actionAccounting($id = null)
    {
        $method = Yii::$app->request->method;

        // get all
        if ($method == 'GET') {

            return Accounting::get_all();

        }

        // create
        if ($method == 'POST') {

            $params = Yii::$app->request->post();

            if ($params['approve'])
                return Accounting::_approve($id);

            if ($params['is_sync']) {

                $accounting = Accounting::findOne($params['id']);

                if ($accounting) {

                    $accounting->load($params, '');
                    $accounting->save();

                    foreach ($params['items'] as $item) {
                        
                        $accounting_item = AccountingItem::findOne($item['id']);

                        if (!$accounting_item) {

                            $accounting_item = new AccountingItem();
                            $accounting_item->accounting_id = $params['id'];

                        }

                        $accounting_item->load($item, '');
                        $accounting_item->save();

                    }

                    return ['success' => true, 'data' => $accounting];

                }

            }

            return Accounting::_save();

        }

        // delete
        if ($method == 'DELETE') {

            return Accounting::_delete($id);

        }
    }

    public function actionAccountingItem()
    {
        $method = Yii::$app->request->method;

        // get all
        if ($method == 'GET') {

            return AccountingItem::get_all();

        }

    }

    public function actionCity($q)
    {
        return City::_view($q);
    }
}
