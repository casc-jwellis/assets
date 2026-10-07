<?php
/** Expects $migrations: list of [name, applied_at|null]. */
$pending = count(array_filter($migrations, static fn(array $m): bool => $m['applied_at'] === null));
?>
<div class="page-head">
    <div>
        <h1>Database migrations</h1>
        <p class="muted">
            <?= $pending === 0
                ? 'The database is up to date.'
                : e($pending) . ' pending ' . ($pending === 1 ? 'migration' : 'migrations') . '.' ?>
        </p>
    </div>
    <?php if ($pending > 0): ?>
        <form method="post" action="<?= e(url('/migrate')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Apply <?= e($pending) ?> pending</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <?php if ($migrations === []): ?>
        <p class="empty">No migration files found in the migrations folder.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Migration</th><th>Status</th><th>Applied</th></tr></thead>
                <tbody>
                <?php foreach ($migrations as $m): ?>
                    <tr>
                        <td><code><?= e($m['name']) ?></code></td>
                        <td>
                            <?php if ($m['applied_at'] !== null): ?>
                                <span class="badge badge-ok">Applied</span>
                            <?php else: ?>
                                <span class="badge badge-warn">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e($m['applied_at'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<p class="muted small note">Migrations run once each, in filename order. Back up the database before applying changes.
    To add one, put a numbered <code>.sql</code> file in the <code>migrations</code> folder.</p>
