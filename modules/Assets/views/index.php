<?php
/**
 * Expects $rows, $search, $page, $pages, $total, $status ('active'|'retired'|'all'), $showStatus,
 * $canAdd, $canBulk, $listQs (current search/page/status, appended to links).
 */
$pageUrl = static fn(int $p): string => url('/assets') . '?' . http_build_query(array_filter([
    'q' => $search,
    'status' => $status === 'active' ? null : $status,
    'page' => $p > 1 ? $p : null,
]));
$statusLabels = ['active' => 'Active', 'retired' => 'Retired', 'all' => 'All'];
?>
<div class="page-head">
    <div>
        <h1>Assets</h1>
        <p class="muted">
            <?= e(number_format($total)) ?> <?= $status === 'retired' ? 'retired ' : '' ?><?= $total === 1 ? 'asset' : 'assets' ?><?= $search !== '' ? ' matching your search' : '' ?>
        </p>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url('/assets')) ?>" class="search-form" role="search">
            <div class="search">
                <?= icon('search', 16) ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Asset #, serial or description" aria-label="Search assets" maxlength="64" data-submit-on-clear>
            </div>
            <?php if ($showStatus): ?>
                <select name="status" aria-label="Show" data-autosubmit>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </form>
        <?php if ($canAdd): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/new') . $listQs) ?>">Add asset</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <p class="empty"><?= $search !== '' ? 'No assets match your search.' : ($status === 'retired' ? 'No retired assets.' : 'No assets to show.') ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(url('/assets/bulk') . $listQs) ?>" data-bulk-form data-total="<?= e($total) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="select_all" value="">
            <?php if ($canBulk): ?>
                <div class="bulk-bar" data-bulk-bar hidden>
                    <span class="bulk-count" data-bulk-count></span>
                    <button type="button" class="btn-link" data-bulk-all hidden>Select all <?= e(number_format($total)) ?> matching</button>
                    <span class="bulk-buttons">
                        <button type="submit" class="btn" name="action" value="transfer">Transfer…</button>
                        <button type="submit" class="btn" name="action" value="retire">Retire…</button>
                    </span>
                </div>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if ($canBulk): ?>
                                <th class="check-col"><input type="checkbox" data-select-all aria-label="Select all assets on this page"></th>
                            <?php endif; ?>
                            <th>Asset #</th><th>Description</th><th>Type</th><th>Serial</th>
                            <th>Dept</th><th>Location</th><th class="num">Cost</th><th>Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $isRetired = !empty($r['retired']); ?>
                        <tr<?= $isRetired ? ' class="is-retired"' : '' ?>>
                            <?php if ($canBulk): ?>
                                <td class="check-col"><input type="checkbox" name="ids[]" value="<?= e((int) $r['asset_id']) ?>" aria-label="Select <?= e($r['asset_number']) ?>"></td>
                            <?php endif; ?>
                            <td class="nowrap"><a href="<?= e(url('/assets/' . (int) $r['asset_id']) . $listQs) ?>"><?= e($r['asset_number']) ?></a></td>
                            <td><?= e($r['description']) ?><?php if ($isRetired): ?> <span class="badge badge-warn">Retired</span><?php endif; ?></td>
                            <td><?= e($r['type_name']) ?></td>
                            <td class="nowrap"><?= e($r['serial_number']) ?></td>
                            <td><span class="badge"><?= e($r['dept']) ?></span></td>
                            <td class="nowrap"><?= e(trim($r['building'] . ' ' . $r['room'])) ?></td>
                            <td class="num"><?= e(fmt_money($r['cost'])) ?></td>
                            <td class="nowrap">
                                <?php if ($isRetired): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($r['verified_date']): ?>
                                    <?= e(fmt_date($r['verified_date'])) ?>
                                <?php else: ?>
                                    <span class="badge badge-warn">Never</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?><a class="btn" href="<?= e($pageUrl($page - 1)) ?>">&larr; Previous</a><?php endif; ?>
                <span class="muted">Page <?= e($page) ?> of <?= e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
