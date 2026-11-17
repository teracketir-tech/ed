<?php

namespace frontend\modules\hq\services;

use Yii;

/**
 * ابزار مشترک سرویس‌های لیست: خواندن پارامترها، اعمال فیلتر و صفحه‌بندی
 */
class ListService
{
    /** پارامترهای GET و در نبودشان POST — الگوی لیست پروژه‌ها */
    public static function params(): array
    {
        return Yii::$app->request->get() ?: Yii::$app->request->post();
    }

    /**
     * اعمال فیلترهای «مساوی بودن» روی کوئری؛ مقدار خالی یعنی بدون فیلتر.
     * کوئریِ برگشتی زنجیره‌پذیر است تا فراخواننده ادامه‌اش دهد.
     *
     * @param \yii\db\ActiveQuery $query
     * @return \yii\db\ActiveQuery
     */
    public static function filter($query, array $params, array $keys)
    {
        foreach ($keys as $key) {
            if (!empty($params[$key])) {
                $query->andWhere([$key => $params[$key]]);
            }
        }

        return $query;
    }

    /**
     * برش صفحه‌ی جاری از روی ردیف‌های مرتب‌شده.
     * $perPage = 0 یعنی بدون صفحه‌بندی (برای انتخاب‌گرهای فرم‌ها).
     *
     * @return array{pages:int, totalCount:int, data:array}
     */
    public static function paginate(array $rows, $perPage, $page): array
    {
        $totalCount = count($rows);

        return [
            'pages' => $perPage > 0 ? max(1, (int) ceil($totalCount / $perPage)) : 1,
            'totalCount' => $totalCount,
            'data' => $perPage > 0 ? array_slice($rows, (max(1, (int) $page) - 1) * (int) $perPage, (int) $perPage) : $rows,
        ];
    }
}
