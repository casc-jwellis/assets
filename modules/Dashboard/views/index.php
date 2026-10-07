<?php /** Expects $stats (array), $recent (list of asset rows). */ ?>
<div class="page-head">
    <div>
        <h1>Welcome back, <?= e(current_user()['firstname'] ?: current_user()['username']) ?></h1>
        <p class="muted">Here's a snapshot of the assets you have access to.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="card stat">
        <div class="stat-label">Total assets</div>
        <div class="stat-value"><?= e(number_format((int) $stats['total'])) ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Total cost</div>
        <div class="stat-value">$<?= e(number_format((float) $stats['total_cost'], 2)) ?></div>
    </div>
    <div class="card stat">
        <div class="stat-label">Never verified</div>
        <div class="stat-value"><?= e(number_format((int) $stats['unverified'])) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Recently added</h2>
        <a href="<?= e(url('/assets')) ?>">View all</a>
    </div>
    <?php if ($recent === []): ?>
        <p class="empty">No assets to show yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Asset #</th><th>Description</th><th>Type</th><th>Dept</th><th>Added</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= e($row['asset_number']) ?></td>
                        <td><?= e($row['description']) ?></td>
                        <td><?= e($row['type_name']) ?></td>
                        <td><span class="badge"><?= e($row['dept']) ?></span></td>
                        <td class="nowrap"><?= e(substr((string) $row['created_date'], 0, 10)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
