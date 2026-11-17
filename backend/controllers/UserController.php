<?php
namespace frontend\modules\hq\controllers;

use frontend\modules\hq\models\User;
use yii\rest\ActiveController;
use frontend\components\JwtAuthBehavior;
use Yii;

class UserController extends ActiveController
{

    public $modelClass = 'frontend\modules\hq\models\User';

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['jwtAuth'] = [
            'class' => JwtAuthBehavior::class,
            'actions' => ['index', 'view', 'create', 'update', 'delete', 'is-login', 'options', 'fingerprint-add', 'fingerprint-del', 'login-as'],
        ];
        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();
        // «options» باید برداشته شود وگرنه `yii\rest\OptionsAction` (که بر
        // متدِ `actionOptions` مقدم است) اجرا می‌شود و هر GET را 405 می‌کند
        unset($actions['index'], $actions['view'], $actions['create'], $actions['update'], $actions['delete'], $actions['options']);
        return $actions;
    }

    public function actionLogin()
    {
        return User::login();
    }

    public function actionForgotPassword()
    {
        return User::forgot_password();
    }

    public function actionIndex()
    { 
        return User::get_all();
    }

    /** فهرستِ کوتاهِ کاربران برای سلکت‌ها — ایشوی ۱۸، بدونِ نقشِ 1102 */
    public function actionOptions()
    {
        return User::options();
    }

    public function actionView($id)
    {
        return User::_view($id);
    }

    public function actionCreate()
    {
        return User::_save();
    }

    public function actionUpdate($id)
    {
        return User::_save($id);
    }

    public function actionDelete($id)
    {
        return User::_delete($id);
    }

    /** افزودن کاربر به صف دستگاه انگشت زنی — POST body: {replace?: true} */
    public function actionFingerprintAdd($id)
    {
        return User::fingerprint_add($id, (bool) Yii::$app->request->post('replace', false));
    }

    /** حذف کاربر از صف دستگاه انگشت زنی — POST body: {replace?: true} */
    public function actionFingerprintDel($id)
    {
        return User::fingerprint_del($id, (bool) Yii::$app->request->post('replace', false));
    }

    /** Signs the caller in as another user - POST body: {group_id?: int} */
    public function actionLoginAs($id)
    {
        return User::login_as($id, Yii::$app->request->post('group_id', null));
    }

    public function actionRoles()
    {
        return User::roles;
    }

    public function actionIsLogin()
    {
        return ['success' => true];
    }

}
