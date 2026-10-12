<?php

declare(strict_types=1);

namespace App\Modules\Attachments;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Files attached to assets: photos (JPG, PNG, WebP), PDFs, and plain text / CSV.
 *
 * The files live in storage/attachments/ (outside the web root) under random names and are only ever
 * delivered through this module, after the usual department check. What a file is comes from its content
 * (never from the name or the browser's claim); anything that is not one of the allowed types is refused.
 *
 * Reading follows the asset's department access; adding and deleting need write access to that department
 * (retired assets included, so a disposal certificate can still be attached). The Assets page shows the
 * Attachments card by calling card(); remove this folder and the card simply disappears.
 */
final class AttachmentsModule extends Module
{
    public const MIGRATION = '014_asset_attachments.sql';

    private const THUMB_SIZE = 320;
    private const CAPTION_MAX = 255;
    private const NAME_MAX = 120;

    public function routes(Router $router): void
    {
        $router->post('/assets/{id:\d+}/attachments', [$this, 'upload']);
        $router->get('/attachments/{aid:\d+}', [$this, 'file']);
        $router->get('/attachments/{aid:\d+}/thumb', [$this, 'thumb']);
        $router->get('/attachments/{aid:\d+}/delete', [$this, 'showDelete']);
        $router->post('/attachments/{aid:\d+}/delete', [$this, 'delete']);
    }

    /** Come back to the same assets list (search, page, status) the user started from. */
    protected function rememberList(Request $req): void
    {
        $status = (string) $req->query('status');
        $this->listQs = $this->listState(
            mb_substr(trim((string) $req->query('q')), 0, 64),
            max(1, (int) $req->query('page', '1')),
            ['status' => in_array($status, ['retired', 'all'], true) ? $status : null]
        );
    }

    // ------------------------------------------------------------ the card on the asset page

    /** The Attachments card for an asset the caller has already checked the user may read. */
    public function card(array $asset, bool $canWrite, string $listQs): string
    {
        $this->listQs = $listQs;
        if (!$this->ready()) {
            return $this->app->auth->isAdmin()
                ? $this->render('card', ['ready' => false, 'items' => [], 'asset' => $asset, 'canWrite' => false] + $this->limits(), null)
                : '';
        }
        $items = $this->app->db->all(
            'SELECT t.*, u.firstname, u.lastname, u.username
               FROM asset_attachments t
               LEFT JOIN users u ON u.user_id = t.uploaded_by
              WHERE t.asset_id = ?
              ORDER BY t.uploaded_date DESC, t.attachment_id DESC',
            [(int) $asset['asset_id']]
        );
        return $this->render('card', ['ready' => true, 'items' => $items, 'asset' => $asset, 'canWrite' => $canWrite] + $this->limits(), null);
    }

    /** What the server will accept, for the upload form. */
    private function limits(): array
    {
        $file = ini_bytes('upload_max_filesize');
        $post = ini_bytes('post_max_size');
        return [
            'maxFile'   => min($file, $post),
            'maxTotal'  => $post,
            'maxCount'  => max(1, (int) ini_get('max_file_uploads')),
            'iniUpload' => (string) ini_get('upload_max_filesize'),
            'iniPost'   => (string) ini_get('post_max_size'),
        ];
    }

    // ------------------------------------------------------------ upload

    public function upload(Request $req, array $params): never
    {
        $this->rememberList($req);
        $this->requireReady();
        $asset = $this->assetFor((int) $params['id'], true);
        $back = '/assets/' . (int) $asset['asset_id'] . $this->listQs;

        @set_time_limit(0);
        if (ini_bytes('memory_limit') < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M'); // decoding a large photo to make its thumbnail
        }

        $files = $this->postedFiles();
        $caption = $this->cleanCaption((string) $req->post('caption'));
        $dir = $this->dir();
        if ($dir === null) {
            $this->app->session->flash('danger', 'The web server cannot write to storage/attachments. Make the storage folder writable by the web server.');
            $this->redirect($back);
        }

        $added = 0;
        $problems = [];
        foreach ($files as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $label = $this->cleanName($f['name'], null);
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $problems[] = $label . ': ' . $this->uploadError($f['error']);
                continue;
            }
            if (!is_uploaded_file($f['tmp'])) {
                $problems[] = $label . ': the upload was not accepted.';
                continue;
            }
            if ($f['size'] === 0) {
                $problems[] = $label . ': the file is empty.';
                continue;
            }
            $type = $this->detect($f['tmp'], $f['name']);
            if ($type === null) {
                $problems[] = $label . ': only JPG, PNG, WebP, PDF, TXT and CSV files can be attached.';
                continue;
            }

            $stored = bin2hex(random_bytes(16)) . '.' . $type['ext'];
            $path = $dir . '/' . $stored;
            if (!move_uploaded_file($f['tmp'], $path)) {
                $problems[] = $label . ': the file could not be saved.';
                continue;
            }
            @chmod($path, 0640);
            $thumb = $type['image'] && $this->makeThumb($path, $type['ext']);

            try {
                $this->app->db->execute(
                    'INSERT INTO asset_attachments (asset_id, original_name, stored_name, mime, size_bytes, has_thumb, caption, uploaded_by, uploaded_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [(int) $asset['asset_id'], $this->cleanName($f['name'], $type['ext']), $stored, $type['mime'],
                     (int) filesize($path), (int) $thumb, $caption, (int) $this->app->auth->user()['user_id'], date('Y-m-d H:i:s')]
                );
                $added++;
            } catch (\PDOException $e) {
                error_log('attachment insert failed: ' . $e->getMessage());
                @unlink($path);
                @unlink($path . '.thumb.jpg');
                $problems[] = $label . ': it could not be recorded because of a database error.';
            }
        }

        if ($added === 0 && $problems === []) {
            $problems[] = 'Choose at least one file to attach.';
        }
        if ($added > 0) {
            $this->app->session->flash('success', $added === 1 ? 'Attached 1 file.' : "Attached {$added} files.");
        }
        foreach ($problems as $p) {
            $this->app->session->flash('danger', $p);
        }
        $this->redirect($back . '#attachments');
    }

    // ------------------------------------------------------------ viewing

    /** The file itself: images, PDFs and text open in the browser; everything is also available as a download. */
    public function file(Request $req, array $params): never
    {
        $this->requireReady();
        $row = $this->attachmentFor((int) $params['aid'], false);
        $mime = (string) $row['mime'];
        $inline = $req->query('download') !== '1' && !str_starts_with($mime, 'text/csv');
        $this->send($this->dir() . '/' . $row['stored_name'], $mime, $inline, (string) $row['original_name']);
    }

    public function thumb(Request $req, array $params): never
    {
        $this->requireReady();
        $row = $this->attachmentFor((int) $params['aid'], false);
        $path = $this->dir() . '/' . $row['stored_name'] . '.thumb.jpg';
        if (empty($row['has_thumb']) || !is_file($path)) {
            $this->redirect('/attachments/' . (int) $row['attachment_id']);
        }
        $this->send($path, 'image/jpeg', true, 'thumbnail.jpg');
    }

    private function send(string $path, string $mime, bool $inline, string $name): never
    {
        if (!is_file($path)) {
            $this->app->error(404, 'File not found', 'The file is missing from the server\'s storage/attachments folder.');
        }
        session_write_close(); // do not hold the session lock while a big file streams
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $ascii = str_replace(['"', '\\', '%'], '_', preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'file');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        // Files never change once stored, so a short private cache is safe (the page default is no-store).
        header_remove('Pragma');
        header('Cache-Control: private, max-age=300');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
        if ($mime === 'application/pdf') {
            // The browser's PDF viewer does not work under the page-wide script policy; the PDF is not an HTML page.
            header_remove('Content-Security-Policy');
        } else {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------ delete

    public function showDelete(Request $req, array $params): string
    {
        $this->rememberList($req);
        $this->requireReady();
        $row = $this->attachmentFor((int) $params['aid'], true);
        return $this->render('delete', ['title' => 'Delete attachment', 'a' => $row]);
    }

    public function delete(Request $req, array $params): never
    {
        $this->rememberList($req);
        $this->requireReady();
        $row = $this->attachmentFor((int) $params['aid'], true);
        $dir = $this->dir();

        $this->app->db->execute('DELETE FROM asset_attachments WHERE attachment_id = ?', [(int) $row['attachment_id']]);
        if ($dir !== null) {
            foreach ([$row['stored_name'], $row['stored_name'] . '.thumb.jpg'] as $f) {
                if (is_file($dir . '/' . $f) && !@unlink($dir . '/' . $f)) {
                    error_log('attachment file could not be removed: ' . $f);
                }
            }
        }
        $this->app->session->flash('success', 'Deleted "' . $row['original_name'] . '".');
        $this->redirect('/assets/' . (int) $row['asset_id'] . $this->listQs . '#attachments');
    }

    // ------------------------------------------------------------ access

    private function ready(): bool
    {
        return $this->app->migrationApplied(self::MIGRATION);
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            $this->app->error(503, 'Attachments are not available yet', 'The database needs updating first: an administrator must apply migration ' . self::MIGRATION . ' on the Migrations page.');
        }
    }

    /** The asset, if the user may read it (404 otherwise) and, when asked, write it (403 otherwise). */
    private function assetFor(int $id, bool $write): array
    {
        [$scope, $scopeParams] = $this->app->auth->departmentScope('a.department_id');
        $a = $this->app->db->one("SELECT a.asset_id, a.asset_number, a.department_id FROM assets a WHERE a.asset_id = ? AND {$scope}", [$id, ...$scopeParams]);
        if ($a === null) {
            $this->app->error(404, 'Asset not found'); // same answer for "missing" and "not yours"
        }
        if ($write && !$this->app->auth->canWrite((int) $a['department_id'])) {
            $this->app->error(403, 'Access denied', 'You have read-only access to this asset\'s department.');
        }
        return $a;
    }

    private function attachmentFor(int $id, bool $write): array
    {
        [$scope, $scopeParams] = $this->app->auth->departmentScope('a.department_id');
        $row = $this->app->db->one(
            "SELECT t.*, a.asset_number, a.department_id
               FROM asset_attachments t JOIN assets a ON a.asset_id = t.asset_id
              WHERE t.attachment_id = ? AND {$scope}",
            [$id, ...$scopeParams]
        );
        if ($row === null) {
            $this->app->error(404, 'File not found');
        }
        if ($write && !$this->app->auth->canWrite((int) $row['department_id'])) {
            $this->app->error(403, 'Access denied', 'You have read-only access to this asset\'s department.');
        }
        return $row;
    }

    // ------------------------------------------------------------ files

    /** storage/attachments (outside the web root), created on demand; null if it cannot be written. */
    private function dir(): ?string
    {
        $dir = APP_ROOT . '/storage/attachments';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /** @return list<array{name:string, tmp:string, error:int, size:int}> the files of the "files[]" field */
    private function postedFiles(): array
    {
        $f = $_FILES['files'] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $names = (array) $f['name'];
        $out = [];
        foreach (array_keys($names) as $i) {
            $out[] = [
                'name'  => (string) $names[$i],
                'tmp'   => (string) ((array) $f['tmp_name'])[$i],
                'error' => (int) ((array) $f['error'])[$i],
                'size'  => (int) ((array) $f['size'])[$i],
            ];
        }
        return $out;
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'the file is bigger than the server allows (upload_max_filesize ' . ini_get('upload_max_filesize')
                . ', post_max_size ' . ini_get('post_max_size') . ').',
            UPLOAD_ERR_PARTIAL => 'the upload was interrupted. Try again.',
            default => 'the upload failed (error ' . $code . '). Try again.',
        };
    }

    /**
     * Work out what a file really is from its content.
     *
     * @return array{ext:string, mime:string, image:bool}|null null if it is not an allowed type
     */
    private function detect(string $path, string $originalName): ?array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 1024);

        $kind = @getimagesize($path);
        $imageType = is_array($kind) ? $kind[2] : null;
        if (str_starts_with($head, "\xFF\xD8\xFF") && $imageType === IMAGETYPE_JPEG) {
            fclose($fh);
            return ['ext' => 'jpg', 'mime' => 'image/jpeg', 'image' => true];
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n") && $imageType === IMAGETYPE_PNG) {
            fclose($fh);
            return ['ext' => 'png', 'mime' => 'image/png', 'image' => true];
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' && $imageType === IMAGETYPE_WEBP) {
            fclose($fh);
            return ['ext' => 'webp', 'mime' => 'image/webp', 'image' => true];
        }
        if (str_contains($head, '%PDF-')) {
            fclose($fh);
            return ['ext' => 'pdf', 'mime' => 'application/pdf', 'image' => false];
        }

        // Plain text: only by name (.txt / .csv), and only if it is really text (no NUL bytes anywhere).
        $ext = strtolower(pathinfo(str_replace('\\', '/', $originalName), PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt', 'csv'], true)) {
            fclose($fh);
            return null;
        }
        rewind($fh);
        $sample = null;
        while (!feof($fh)) {
            $chunk = (string) fread($fh, 1048576);
            if (str_contains($chunk, "\0")) {
                fclose($fh);
                return null;
            }
            $sample ??= $chunk;
        }
        fclose($fh);

        // UTF-8 unless the start of the file is not valid UTF-8 (then assume Windows-1252, as Excel writes).
        $sample = (string) $sample;
        for ($i = 0; $i < 4 && !mb_check_encoding($sample, 'UTF-8') && $sample !== ''; $i++) {
            $sample = substr($sample, 0, -1); // the sample may end part-way through a character
        }
        $charset = $sample !== '' && mb_check_encoding($sample, 'UTF-8') && $i < 4 ? 'utf-8' : 'windows-1252';
        return ['ext' => $ext, 'mime' => ($ext === 'csv' ? 'text/csv' : 'text/plain') . '; charset=' . $charset, 'image' => false];
    }

    /** The name to show and offer for download: the original base name with the real extension. */
    private function cleanName(string $name, ?string $ext): string
    {
        $name = mb_scrub(str_replace('\\', '/', $name));
        $base = basename($name);
        $dot = strrpos($base, '.');
        $stem = $dot === false || $dot === 0 ? $base : substr($base, 0, $dot);
        $stem = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', ' ', $stem));
        $stem = mb_substr($stem === '' ? 'file' : $stem, 0, self::NAME_MAX);
        if ($ext === null) { // for messages only
            return $stem . ($dot !== false && $dot > 0 ? mb_substr(substr($base, $dot), 0, 12) : '');
        }
        return $stem . '.' . $ext;
    }

    private function cleanCaption(string $caption): string
    {
        $caption = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', mb_scrub($caption)));
        return mb_substr($caption, 0, self::CAPTION_MAX);
    }

    // ------------------------------------------------------------ thumbnails

    /** A small JPEG next to the stored file, if the GD extension is available and the picture is not too big. */
    private function makeThumb(string $path, string $ext): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        $info = @getimagesize($path);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return false;
        }
        [$w, $h] = $info;
        $limit = ini_bytes('memory_limit');
        if ($limit !== PHP_INT_MAX && $w * $h * 4 + memory_get_usage() + 16 * 1048576 > $limit) {
            return false; // too large to decode here: the page falls back to the original image
        }
        $create = match ($ext) {
            'jpg'  => 'imagecreatefromjpeg',
            'png'  => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
            default => null,
        };
        if ($create === null || !function_exists($create)) {
            return false;
        }
        $src = @$create($path);
        if ($src === false) {
            return false;
        }

        $scale = min(1.0, self::THUMB_SIZE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagefilledrectangle($thumb, 0, 0, $tw, $th, (int) imagecolorallocate($thumb, 255, 255, 255)); // transparency -> white
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        unset($src);

        // Phone photos are stored sideways with a note saying how to turn them; the browser follows it, so must we.
        if ($ext === 'jpg') {
            $thumb = $this->orient($thumb, $this->jpegOrientation($path));
        }
        $ok = @imagejpeg($thumb, $path . '.thumb.jpg', 82);
        if ($ok) {
            @chmod($path . '.thumb.jpg', 0640);
        }
        return $ok;
    }

    /** @param \GdImage $im */
    private function orient(\GdImage $im, int $orientation): \GdImage
    {
        $rotated = static fn(\GdImage $i, int $deg): \GdImage => imagerotate($i, $deg, 0) ?: $i; // GD turns counter-clockwise
        return match ($orientation) {
            2 => $this->flipped($im, IMG_FLIP_HORIZONTAL),
            3 => $rotated($im, 180),
            4 => $this->flipped($im, IMG_FLIP_VERTICAL),
            5 => $this->flipped($rotated($im, 270), IMG_FLIP_HORIZONTAL),
            6 => $rotated($im, 270),
            7 => $this->flipped($rotated($im, 90), IMG_FLIP_HORIZONTAL),
            8 => $rotated($im, 90),
            default => $im,
        };
    }

    private function flipped(\GdImage $im, int $mode): \GdImage
    {
        imageflip($im, $mode);
        return $im;
    }

    /** The EXIF "Orientation" (1-8) of a JPEG, read straight from the file; 1 when there is none. */
    private function jpegOrientation(string $path): int
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 1;
        }
        $data = (string) fread($fh, 65536); // EXIF lives in the first segments
        fclose($fh);
        $pos = 2;
        $len = strlen($data);
        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = ord($data[$pos + 1]);
            $size = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($data, $pos + 10, $size - 8);
                $big = substr($tiff, 0, 2) === 'MM';
                $u16 = static fn(string $s, int $o): int => strlen($s) < $o + 2 ? 0 : ($big ? (ord($s[$o]) << 8) | ord($s[$o + 1]) : (ord($s[$o + 1]) << 8) | ord($s[$o]));
                $u32 = static fn(string $s, int $o): int => strlen($s) < $o + 4 ? 0 : ($big
                    ? (ord($s[$o]) << 24) | (ord($s[$o + 1]) << 16) | (ord($s[$o + 2]) << 8) | ord($s[$o + 3])
                    : (ord($s[$o + 3]) << 24) | (ord($s[$o + 2]) << 16) | (ord($s[$o + 1]) << 8) | ord($s[$o]));
                $ifd = $u32($tiff, 4);
                $count = min($u16($tiff, $ifd), 64);
                for ($i = 0; $i < $count; $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($u16($tiff, $entry) === 0x0112) {
                        $value = $u16($tiff, $entry + 8);
                        return $value >= 1 && $value <= 8 ? $value : 1;
                    }
                }
                return 1;
            }
            if ($marker === 0xDA || $size < 2) { // start of image data: no EXIF before it
                break;
            }
            $pos += 2 + $size;
        }
        return 1;
    }
}
