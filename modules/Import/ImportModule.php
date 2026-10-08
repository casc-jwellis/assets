<?php

declare(strict_types=1);

namespace App\Modules\Import;

use App\Core\Config;
use App\Core\Migrator;
use App\Core\Module;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * ONE-TIME CUTOVER TOOL: loads a data dump from the old application into this one.
 * When the cutover is done, delete the whole modules/Import folder; nothing else depends on it.
 *
 * Flow: upload the .sql dump -> review (nothing written; every fix and warning is listed) ->
 * confirm -> the data tables are replaced in one transaction -> report.
 */
final class ImportModule extends Module
{
    private const MAX_FILE = 256 * 1024 * 1024;

    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $router->get('/import', [$this, 'index'], $admin);
        $router->post('/import/analyze', [$this, 'analyze'], $admin);
        $router->post('/import/run', [$this, 'run'], $admin);
        $router->get('/import/report', [$this, 'report'], $admin);
        $router->post('/import/reset', [$this, 'reset'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Import', 'path' => '/import', 'icon' => 'upload', 'order' => 95, 'admin' => true]];
    }

    // ------------------------------------------------------------------ pages

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $counts = [];
        foreach (LegacyImporter::ORDER as $table) {
            $counts[$table] = (int) $db->value("SELECT COUNT(*) FROM `{$table}`");
        }
        $pending = (new Migrator($db, APP_ROOT . '/migrations'))->pending();

        return $this->render('index', [
            'title'    => 'Import legacy data',
            'counts'   => $counts,
            'pending'  => $pending,
            'dirOk'    => $this->importDir() !== null,
            'maxBytes' => min(self::MAX_FILE, $this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size')),
            'iniUpload' => (string) ini_get('upload_max_filesize'),
            'iniPost'   => (string) ini_get('post_max_size'),
        ]);
    }

    /** Step 1: store the upload, read it, and show what an import would do. Writes nothing to the database. */
    public function analyze(Request $req): string
    {
        $this->requireReady();
        $this->moreResources();

        $file = $_FILES['dump'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail($this->uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        if (!is_uploaded_file((string) $file['tmp_name']) || (int) $file['size'] > self::MAX_FILE) {
            $this->fail('That file could not be accepted.');
        }
        $dir = $this->importDir() ?? $this->fail('The folder storage/imports could not be created or written to by the web server.');
        $this->purgeOld($dir);

        $token = bin2hex(random_bytes(16));
        $path = $dir . '/' . $token . '.sql';
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            $this->fail('The uploaded file could not be saved.');
        }

        try {
            $analysis = $this->analyzeFile($path);
        } catch (\RuntimeException $e) {
            @unlink($path);
            $this->fail($e->getMessage());
        }

        $this->app->session->set('import.token', $token);
        $existing = 0;
        foreach (['assets', 'transfers', 'asset_types'] as $t) {
            $existing += (int) $this->app->db->value("SELECT COUNT(*) FROM `{$t}`");
        }
        return $this->render('review', [
            'title'    => 'Review import',
            'token'    => $token,
            'filename' => (string) $file['name'],
            'size'     => (int) $file['size'],
            'report'   => $analysis['plan']['report'],
            'skipped'  => $analysis['skipped'],
            'notes'    => $analysis['notes'],
            'needsPhrase' => $existing > 0,
            'admin'    => $this->app->auth->user(),
        ]);
    }

    /** Step 2: do it. */
    public function run(Request $req): never
    {
        $this->requireReady();
        $this->moreResources();

        $token = (string) $req->post('token');
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !hash_equals((string) $this->app->session->get('import.token'), $token)) {
            $this->fail('This import is no longer valid. Upload the file again.');
        }
        $path = ($this->importDir() ?? '') . '/' . $token . '.sql';
        if (!is_file($path)) {
            $this->fail('The uploaded file is gone. Upload it again.');
        }
        if ($req->post('ack') !== '1') {
            $this->fail('Tick the box to confirm you have a backup and want the existing data replaced.');
        }
        $existing = 0;
        foreach (['assets', 'transfers', 'asset_types'] as $t) {
            $existing += (int) $this->app->db->value("SELECT COUNT(*) FROM `{$t}`");
        }
        if ($existing > 0 && trim((string) $req->post('phrase')) !== 'REPLACE') {
            $this->fail('Type REPLACE to confirm that the existing data should be replaced.');
        }

        try {
            $analysis = $this->analyzeFile($path);
            $admin = $this->app->auth->user();
            $result = (new LegacyImporter($this->app->db))->execute($analysis['plan'], (int) $admin['user_id'], APP_ROOT . '/migrations');
        } catch (\Throwable $e) {
            error_log('import failed: ' . $e->getMessage());
            $this->fail('The import failed and nothing was changed: ' . $e->getMessage());
        }

        @unlink($path);
        $this->app->session->forget('import.token');
        $this->app->session->set('import.report', [
            'when'     => date('Y-m-d H:i:s'),
            'plan'     => $analysis['plan']['report'],
            'result'   => $result,
            'skipped'  => $analysis['skipped'],
        ]);
        $this->app->session->flash('success', 'The data was imported.');
        $this->redirect('/import/report');
    }

    /**
     * Start from nothing: drop every table, remove the settings file so the setup wizard can run again,
     * and sign out (the signed-in account is gone with the tables).
     */
    public function reset(Request $req): never
    {
        if ($req->post('ack') !== '1') {
            $this->fail('Tick the box to confirm you have a backup and want every table deleted.');
        }
        if (trim((string) $req->post('phrase')) !== 'DELETE ALL') {
            $this->fail('Type DELETE ALL to confirm that every table should be deleted.');
        }

        try {
            $dropped = (new LegacyImporter($this->app->db))->dropAllTables();
        } catch (\Throwable $e) {
            error_log('import reset failed: ' . $e->getMessage());
            $this->fail('Deleting the tables failed part-way, so some may be gone: ' . $e->getMessage());
        }

        $dir = $this->importDir();
        foreach ($dir !== null ? (glob($dir . '/*.sql') ?: []) : [] as $f) {
            @unlink($f);
        }

        $removed = @unlink(Config::path());
        $session = $this->app->session;
        $session->forget('auth.uid');
        $session->forget('import.token');
        $session->forget('import.report');
        $session->regenerate();
        $session->flash($removed ? 'success' : 'warning', $removed
            ? "Deleted {$dropped} table" . ($dropped === 1 ? '' : 's') . ' and removed the saved settings. Run setup to build a fresh database.'
            : "Deleted {$dropped} table" . ($dropped === 1 ? '' : 's') . ', but the web server could not remove config/config.php. Delete that file yourself, then run setup.');
        Response::redirect('/setup.php');
    }

    public function report(Request $req): string
    {
        $r = $this->app->session->get('import.report');
        if (!is_array($r)) {
            $this->app->session->flash('info', 'There is no recent import to report on.');
            $this->redirect('/import');
        }
        return $this->render('report', ['title' => 'Import report', 'r' => $r]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{plan: array, skipped: array<string,int>, notes: list<string>}
     * @throws \RuntimeException if the file is not a usable dump
     */
    private function analyzeFile(string $path): array
    {
        $sql = file_get_contents($path);
        if ($sql === false || $sql === '') {
            throw new \RuntimeException('The file is empty or could not be read.');
        }
        $notes = [];
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        if (!mb_check_encoding($sql, 'UTF-8')) {
            $sql = mb_convert_encoding($sql, 'UTF-8', 'Windows-1252');
            $notes[] = 'The file was not valid UTF-8, so it was read as Windows-1252 (Western European). Check accented characters afterwards.';
        }

        $parsed = DumpParser::parse($sql);
        unset($sql);
        $missing = array_values(array_filter(
            ['assets', 'departments', 'buildings', 'asset_types', 'users'],
            static fn(string $t): bool => empty($parsed['rows'][$t])
        ));
        if ($missing !== []) {
            throw new \RuntimeException(
                'This does not look like a complete data dump. It has no rows for: ' . implode(', ', $missing)
                . '. Export the data (INSERT statements) for all tables and try again.'
            );
        }
        $plan = (new LegacyImporter($this->app->db))->plan($parsed['rows'], $this->app->auth->user());
        return ['plan' => $plan, 'skipped' => $parsed['skipped'], 'notes' => $notes];
    }

    /** The database must be up to date (the importer relies on every schema change). */
    private function requireReady(): void
    {
        $pending = (new Migrator($this->app->db, APP_ROOT . '/migrations'))->pending();
        if ($pending !== []) {
            $this->fail('Apply the pending migrations first (' . implode(', ', $pending) . ').');
        }
    }

    private function fail(string $message): never
    {
        $this->app->session->flash('danger', $message);
        $this->redirect('/import');
    }

    private function moreResources(): void
    {
        @set_time_limit(0);
        if ($this->iniBytes('memory_limit') < 512 * 1024 * 1024 && $this->iniBytes('memory_limit') > 0) {
            @ini_set('memory_limit', '512M');
        }
    }

    /** storage/imports (outside the web root); created on demand. */
    private function importDir(): ?string
    {
        $dir = APP_ROOT . '/storage/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /** Remove uploads left behind by earlier, unfinished imports. */
    private function purgeOld(string $dir): void
    {
        foreach (glob($dir . '/*.sql') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    private function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is bigger than the server allows (upload_max_filesize ' . ini_get('upload_max_filesize')
                . ', post_max_size ' . ini_get('post_max_size') . '). Raise those in php.ini (and client_max_body_size in nginx), or split the dump.',
            UPLOAD_ERR_NO_FILE => 'Choose a .sql file to upload.',
            default => 'The upload failed (error ' . $code . '). Try again.',
        };
    }
}
