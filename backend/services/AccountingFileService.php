<?php

namespace frontend\modules\hq\services;

use frontend\components\Storage;
use Yii;

/**
 * The two attachment moments of an accounting document.
 *
 * `image` holds the files uploaded when the document was registered,
 * `approve_image` the ones uploaded when the draft was approved
 * (sql/accounting-approve-image.sql added the second column). Both hold a
 * JSON array of urls; rows written before the JSON shape hold one bare url,
 * which read() still understands.
 */
class AccountingFileService
{
    public const MAX_FILES = 10;
    public const MAX_SIZE = 10485760; // 10MB per file
    public const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'xls', 'xlsx', 'zip'];

    /**
     * The publish step, swapped out by the harness so a test never talks to
     * the real FTP server. Production code never sets it.
     */
    public static $publisher = null;

    /**
     * Checks an `images[]` batch before anything is written: count cap,
     * extension whitelist, size cap and PHP upload errors.
     *
     * @return array{ok: bool, message: ?string}
     */
    public static function validate($files)
    {
        if (empty($files['name']) || !is_array($files['name'])) {

            return ['ok' => true, 'message' => null];
        }

        $count = 0;

        foreach ($files['name'] as $index => $name) {

            if ($name === '' || ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {

                continue;
            }

            $count++;

            if (($files['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {

                return ['ok' => false, 'message' => 'آپلود فایل «' . $name . '» ناقص بود؛ دوباره تلاش کنید'];
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($extension, self::EXTENSIONS, true)) {

                return ['ok' => false, 'message' => 'پسوند فایل «' . $name . '» مجاز نیست'];
            }

            if (($files['size'][$index] ?? 0) > self::MAX_SIZE) {

                return ['ok' => false, 'message' => 'حجم فایل «' . $name . '» بیش از 10 مگابایت است'];
            }
        }

        if ($count > self::MAX_FILES) {

            return ['ok' => false, 'message' => 'هر سند حداکثر ' . self::MAX_FILES . ' فایل می‌تواند داشته باشد'];
        }

        return ['ok' => true, 'message' => null];
    }

    /**
     * Moves an `images[]` batch into uploads/accounting and publishes each
     * file, returning the stored urls. A file that cannot be published keeps
     * its locally served url - an upload is never dropped silently.
     *
     * @return string[]
     */
    public static function store($files)
    {
        $urls = [];
        $dir = Yii::getAlias('@frontend/web/uploads/accounting/');

        if (!is_dir($dir)) {

            mkdir($dir, 0777, true);
        }

        foreach ($files['name'] as $index => $name) {

            if ($name === '') {

                continue;
            }

            $fileName = time() . '_' . uniqid() . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = $dir . $fileName;
            $tmp = $files['tmp_name'][$index];

            $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $path) : @copy($tmp, $path);

            if (!$moved) {

                continue;
            }

            try {

                // Windows local paths carry backslashes, and Storage::upload()
                // derives the remote filename by exploding on a forward-slash
                // needle - normalizing keeps that extraction working locally
                $url = self::$publisher
                    ? (self::$publisher)($path, 'accounting')
                    : Storage::upload(str_replace('\\', '/', $path), 'accounting');

            } catch (\Throwable $e) {

                // an FTP outage must not cost the whole document
                $url = null;
            }

            if ($url) {

                $urls[] = $url;

                unlink($path);

            } else {

                $urls[] = Yii::$app->request->hostInfo . '/uploads/accounting/' . $fileName;
            }
        }

        return $urls;
    }

    /**
     * Reads a file column back as a url list; legacy rows hold one bare url.
     *
     * @return string[]
     */
    public static function read($value)
    {
        if ($value === null || $value === '') {

            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {

            // not JSON at all - the legacy single url
            return [$value];
        }

        if (is_string($decoded)) {

            return [$decoded];   // a JSON-quoted single url
        }

        if (!is_array($decoded)) {

            return [];   // json null / number / bool mean no files, never a crash
        }

        $urls = [];

        foreach ($decoded as $one) {

            if (is_string($one)) {

                $urls[] = $one;   // junk entries are dropped, not rendered as links
            }
        }

        return $urls;
    }

    /** The column value after new urls are appended to what it already held. */
    public static function append($value, array $urls)
    {
        return json_encode(array_merge(self::read($value), $urls));
    }
}
