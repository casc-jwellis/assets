<?php
/** Expects $counts (table => rows now), $pending (migrations), $dirOk, $maxBytes, $iniUpload, $iniPost. */
$labels = [
    'departments' => 'Departments', 'buildings' => 'Buildings', 'asset_types' => 'Asset types', 'users' => 'Users',
    'permissions' => 'Department access rows', 'assets' => 'Assets', 'transfers' => 'Transfers',
];
$mb = static fn(int $b): string => $b >= PHP_INT_MAX / 2 ? 'no limit' : number_format($b / 1048576, 1) . ' MB';
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <h1>Import legacy data</h1>
        <p class="muted">A one-time tool for moving the data from the old application into this one. Remove it (delete <code>modules/Import</code>) once you have gone live.</p>
    </div>
</div>

<?php if ($pending !== []): ?>
    <div class="alert alert-danger" role="alert">
        The database needs updating before an import: apply <?= e(implode(', ', $pending)) ?> on the
        <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.
    </div>
<?php endif; ?>
<?php if (!$dirOk): ?>
    <div class="alert alert-danger" role="alert">The web server cannot write to <code>storage/imports</code>. Make the <code>storage</code> folder writable by the web server.</div>
<?php endif; ?>

<div class="card form-card">
    <h2>What this does</h2>
    <ol class="steps">
        <li>You upload a <code>.sql</code> data dump (the INSERT statements) from the old application.</li>
        <li>You see a <strong>review</strong> of exactly what would be imported, every automatic fix, and every warning. Nothing is written yet.</li>
        <li>After you confirm, the tables below are <strong>replaced</strong> with the dump's data in one transaction. If anything fails, nothing changes.</li>
    </ol>
    <p class="muted small">The file is only read, never run as SQL; anything other than rows for the tables below is ignored (including the retired <code>tassets</code> table).
        Your own account is kept, so you cannot lock yourself out. Take a database backup first.</p>
</div>

<div class="card form-card section">
    <h2>What is in the database now</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Table</th><th class="num">Rows</th></tr></thead>
            <tbody>
            <?php foreach ($counts as $table => $n): ?>
                <tr><td><?= e($labels[$table] ?? $table) ?></td><td class="num"><?= e(number_format($n)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card form-card section">
    <h2>Upload the dump</h2>
    <form method="post" action="<?= e(url('/import/analyze')) ?>" enctype="multipart/form-data" data-upload-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="dump">SQL dump file</label>
            <input type="file" id="dump" name="dump" accept=".sql,text/plain,application/sql" required data-max-bytes="<?= e($maxBytes >= PHP_INT_MAX / 2 ? 0 : $maxBytes) ?>">
            <small class="muted">Largest file this server accepts: <?= e($mb($maxBytes)) ?> (upload_max_filesize <?= e($iniUpload) ?>, post_max_size <?= e($iniPost) ?>).
                Reading and checking a large file can take a little while.</small>
            <small class="upload-warning" data-upload-warning hidden>This file is larger than the server accepts. Raise the PHP limits above (and nginx's client_max_body_size) and try again.</small>
        </div>
        <button type="submit" class="btn btn-primary"<?= $pending !== [] || !$dirOk ? ' disabled' : '' ?>>Upload and review</button>
    </form>
</div>

<div class="card form-card danger-card section">
    <h2>Delete all tables</h2>
    <p class="muted small">Starts completely over. Every table in the database is dropped (including the users and this account), the saved
        settings file <code>config/config.php</code> is removed, and you are sent to the setup wizard to build a fresh database. This cannot be undone.</p>
    <form method="post" action="<?= e(url('/import/reset')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <label class="check">
            <input type="checkbox" name="ack" value="1" required>
            <span>I have a database backup and want every table deleted.</span>
        </label>
        <div class="field">
            <label for="reset-phrase">Type <strong>DELETE ALL</strong> to continue.</label>
            <input type="text" id="reset-phrase" name="phrase" autocomplete="off" required>
        </div>
        <button type="submit" class="btn btn-danger">Delete tables and Config</button>
    </form>
</div>
</div>
