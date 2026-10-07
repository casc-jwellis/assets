<?php
/** Expects $rows, $search, $page, $pages, $total. */
$pageUrl = static fn(int $p): string => url('/assets') . '?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null]));
?>
<div class="page-head">
    <div>
        <h1>Assets</h1>
        <p class="muted"><?= e(number_format($total)) ?> <?= $total === 1 ? 'asset' : 'assets' ?><?= $search !== '' ? ' matching your search' : '' ?></p>
    </div>
    <form method="get" action="<?= e(url('/assets')) ?>" class="search" role="search">
        <?= icon('search', 16) ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Asset #, serial or description" aria-label="Search assets" maxlength="64">
    </form>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <p class="empty"><?= $search !== '' ? 'No assets match your search.' : 'No assets to show.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset #</th><th>Description</th><th>Type</th><th>Serial</th>
                        <th>Dept</th><th>Location</th><th class="num">Cost</th><th>Verified</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap"><?= e($r['asset_number']) ?></td>
                        <td><?= e($r['description']) ?></td>
                        <td><?= e($r['type_name']) ?></td>
                        <td class="nowrap"><?= e($r['serial_number']) ?></td>
                        <td><span class="badge"><?= e($r['dept']) ?></span></td>
                        <td class="nowrap"><?= e(trim($r['building'] . ' ' . $r['room'])) ?></td>
                        <td class="num">$<?= e(number_format((float) $r['cost'], 2)) ?></td>
                        <td class="nowrap">
                            <?php if ($r['verified_date']): ?>
                                <?= e(substr((string) $r['verified_date'], 0, 10)) ?>
                            <?php else: ?>
                                <span class="badge badge-warn">Never</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pagination">
                <?php if ($page > 1): ?><a class="btn" href="<?= e($pageUrl($page - 1)) ?>">&larr; Previous</a><?php endif; ?>
                <span class="muted">Page <?= e($page) ?> of <?= e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="btn" href="<?= e($pageUrl($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
