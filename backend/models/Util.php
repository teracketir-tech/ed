<?php

namespace frontend\modules\hq\models;

use yii\db\ActiveRecord;

class Util extends ActiveRecord
{
    public static function user_os()
    {
        $agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

        $os_patterns = [
            'Android'      => '/android/',
            'iOS'          => '/iphone|ipad|ipod/',
            'Windows 11'   => '/windows nt 10\.0/',
            'Windows'      => '/windows/',
            'Chrome OS'    => '/cros/',
            'macOS'        => '/macintosh|mac os x/',
            'Linux'        => '/linux/',
            'Ubuntu'       => '/ubuntu/',
            'FreeBSD'      => '/freebsd/',
            'OpenBSD'      => '/openbsd/',
            'NetBSD'       => '/netbsd/',
            'BlackBerry'   => '/blackberry|bb10/',
            'KaiOS'        => '/kaios/',
        ];

        foreach ($os_patterns as $os => $pattern) {
            if (preg_match($pattern, $agent)) {
                return $os;
            }
        }

        return 'Unknown OS';
    }

    public static function user_ip()
    {
        return $_SERVER['REMOTE_ADDR'];
    }
}