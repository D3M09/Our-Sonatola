<?php
declare(strict_types=1);

/**
 * Safe file uploads: MIME + magic-byte validation, size limits, private storage.
 * Files are stored OUTSIDE the public docroot (storage/uploads). Served via controller after authz.
 */
final class UploadService
{
    /** @var array<string,array<string>> */
    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    public static function maxBytes(): int
    {
        return Config::getInt('UPLOAD_MAX_MB', 5) * 1024 * 1024;
    }

    /**
     * @param array<string,mixed> $file $_FILES entry
     * @return array<string,mixed> metadata for media_files
     */
    public static function store(PDO $pdo, array $file, int $uploaderId, string $relatedType = 'complaint', int $relatedId = 0): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw AppException::badRequest('File upload failed.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::maxBytes()) {
            throw AppException::badRequest('File too large. Max ' . Config::getInt('UPLOAD_MAX_MB', 5) . 'MB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            throw AppException::badRequest('Invalid upload.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        if (!isset(self::ALLOWED[$mime])) {
            throw AppException::unprocessable('File type not allowed. Allowed: JPG, PNG, WEBP, PDF.');
        }
        // Magic-byte double check
        $head = @file_get_contents($tmp, false, null, 0, 12) ?: '';
        if ($mime === 'application/pdf' && !str_starts_with($head, '%PDF')) {
            throw AppException::unprocessable('Invalid PDF file.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
            throw AppException::unprocessable('Invalid image file.');
        }
        $ext = self::ALLOWED[$mime][0];
        $root = dirname(__DIR__, 3);
        $dir = $root . '/storage/uploads/' . gmdate('Y/m');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!@move_uploaded_file($tmp, $dest)) {
            // CLI/test fallback
            if (!@rename($tmp, $dest)) {
                throw new AppException('Could not store file.', 500);
            }
        }
        @chmod($dest, 0640);
        $rel = 'storage/uploads/' . gmdate('Y/m') . '/' . $stored;
        $sha = hash_file('sha256', $dest) ?: '';
        $st = $pdo->prepare(
            'INSERT INTO media_files (uploaded_by, related_type, related_id, file_path, original_name, mime_type, file_size, sha256, is_public)
             VALUES (:u,:rt,:rid,:p,:o,:m,:s,:h,:pub)'
        );
        $st->execute([
            ':u' => $uploaderId, ':rt' => substr($relatedType, 0, 50), ':rid' => $relatedId,
            ':p' => $rel, ':o' => substr((string) ($file['name'] ?? 'file'), 0, 255),
            ':m' => $mime, ':s' => $size, ':h' => $sha, ':pub' => 0,
        ]);
        return [
            'id' => (int) $pdo->lastInsertId(),
            'path' => $rel, 'mime' => $mime, 'size' => $size, 'sha256' => $sha,
        ];
    }
}
