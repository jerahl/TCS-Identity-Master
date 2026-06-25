<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Helpers for validating an uploaded feed file. The metadata checks are pure
 * (unit tested); the controller still does the is_uploaded_file() check, which
 * depends on the live request.
 */
final class Upload
{
    /**
     * Validate a PHP upload error code + name + size. Returns an error message,
     * or null if the file is acceptable.
     */
    public static function validateMeta(int $errorCode, string $name, int $size, int $maxBytes): ?string
    {
        if ($errorCode !== UPLOAD_ERR_OK) {
            return self::errorMessage($errorCode);
        }
        if ($size <= 0) {
            return 'The uploaded file is empty.';
        }
        if ($size > $maxBytes) {
            return sprintf('File is too large (max %d MB).', (int) ($maxBytes / 1048576));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return 'Only .csv files are accepted.';
        }
        return null;
    }

    /** Safe basename for display/storage (no paths, conservative charset). */
    public static function sanitizeName(string $name): string
    {
        $base = basename($name);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'upload.csv';
        $base = ltrim($base, '.');
        return $base === '' ? 'upload.csv' : mb_substr($base, 0, 200);
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded — please retry.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp directory for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            default => 'The upload failed.',
        };
    }
}
