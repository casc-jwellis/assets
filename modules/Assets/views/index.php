<?php
/**
 * Expects $rows, $search, $page, $pages, $total, $status ('active'|'retired'|'all'), $showStatus,
 * $sort, $dir ('asc'|'desc'), $canAdd, $canBulk, $listQs (current search/page/status/sort/filters, appended to links),
 * $filters (AssetFilter), $filterQuery (its ?f[...] part for links), $chips (one per filter in use), $options (the
 * choices for each pick field and person select: value => label).
 */
use App\Modules\Assets\AssetFilter;

// The sort as it goes in a link: nothing for the default order (asset number, A to Z).
$sortQuery = static fn(string $key, string $d): array => [
    'sort' => $key === 'asset' ? null : $key,
    'dir' => $d === 'desc' ? 'desc' : null,
];
// The search, status and sort every link keeps (the advanced filters are added by the link that needs them).
$stateQuery = ['q' => $search, 'status' => $status === 'active' ? null : $status] + $sortQuery($sort, $dir);
$pageUrl = static fn(int $p): string => url('/assets') . '?' . http_build_query(array_filter(
    $stateQuery + ['page' => $p > 1 ? $p : null] + $filterQuery
));
// A link to a page of the assets module carrying the given query (no "?" when there is nothing to carry).
$withQuery = static function (string $path, array $query): string {
    $qs = http_build_query(array_filter($query));
    return url($path) . ($qs === '' ? '' : '?' . $qs);
};
$exportUrl = $withQuery('/assets/export', $stateQuery + $filterQuery);
$statusLabels = ['active' => 'Active', 'retired' => 'Retired', 'all' => 'All'];
$filterCount = $filters->count();

// Column headers: [sort key, label, direction of the first click, th class]. Cost starts biggest-first.
$columns = [
    ['asset', 'Asset #', 'asc', ''], ['desc', 'Description', 'asc', ''], ['type', 'Type', 'asc', ''], ['serial', 'Serial', 'asc', ''],
    ['dept', 'Dept', 'asc', ''], ['location', 'Location', 'asc', ''], ['cost', 'Cost', 'desc', 'num'], ['verified', 'Verified', 'asc', ''],
];
$sortLink = static function (string $key, string $label, string $first) use ($sort, $dir, $search, $status, $sortQuery, $filterQuery): string {
    $active = $key === $sort;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $first; // clicking the sorted column reverses it
    $href = url('/assets') . '?' . http_build_query(array_filter([
        'q' => $search,
        'status' => $status === 'active' ? null : $status,
    ] + $sortQuery($key, $next) + $filterQuery));
    $arrow = $active ? ' <span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<a class="sort-link" href="' . e($href) . '" title="Sort by ' . e(strtolower($label)) . '">' . e($label) . $arrow . '</a>';
};

// Every filter control belongs to the search form above (the panel sits outside it) and refreshes the list as it changes.
$fa = 'form="asset-search" data-live-filter';
$fv = static fn(string $k): string => (string) $filters->value($k);
?>
<div class="page-head">
    <div data-live-region>
        <h1>Assets</h1>
        <p class="muted">
            <?= e(number_format($total)) ?> <?= $status === 'retired' ? 'retired ' : '' ?><?= $total === 1 ? 'asset' : 'assets' ?><?= $search !== '' || $filterCount > 0 ? ' matching your ' . ($filterCount > 0 && $search !== '' ? 'search and filters' : ($filterCount > 0 ? 'filters' : 'search')) : '' ?>
        </p>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url('/assets')) ?>" class="search-form" id="asset-search" role="search">
            <div class="search">
                <?= icon('search', 16) ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Asset #, serial, description or location" aria-label="Search assets" maxlength="64" data-live-search>
            </div>
            <?php if ($showStatus): ?>
                <select name="status" aria-label="Show" data-autosubmit>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php /* Searching or filtering keeps the current sort. */ ?>
            <?php foreach (array_filter($sortQuery($sort, $dir)) as $k => $v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <button type="button" class="btn" data-toggle="asset-filters" aria-expanded="false" aria-controls="asset-filters">
                <?= icon('filter', 16) ?> Filter <span class="filter-count" data-live-region><?php if ($filterCount > 0): ?><span class="badge badge-accent"><?= e($filterCount) ?></span><?php endif; ?></span>
            </button>
            <a class="btn btn-icon" data-live-region href="<?= e($exportUrl) ?>" title="Export <?= e(number_format($total)) ?> <?= $total === 1 ? 'asset' : 'assets' ?> to CSV" aria-label="Export to CSV"><?= icon('download', 16) ?></a>
        </form>
        <?php if ($canAdd): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/new') . $listQs) ?>">Add asset</a>
        <?php endif; ?>
    </div>
</div>

<section class="card filter-panel" id="asset-filters" aria-label="Filter assets" hidden>
    <fieldset class="filter-group">
        <legend>What and where</legend>
        <div class="filter-grid">
            <?php foreach (AssetFilter::PICKS as $key => [, $label]): ?>
                <?php if (!$filters->has($key)) { continue; } ?>
                <?php $picked = (array) $filters->value($key); ?>
                <div class="field">
                    <span class="field-label"><?= e($label) ?></span>
                    <details class="pick">
                        <summary><span data-pick-text><?= $picked === [] ? 'Any' : e(count($picked)) . ' selected' ?></span></summary>
                        <div class="pick-list">
                            <?php foreach ($options[$key] as $value => $text): ?>
                                <label><input type="checkbox" name="f[<?= e($key) ?>][]" value="<?= e($value) ?>" <?= $fa /* fixed markup */ ?><?= in_array($value, $picked, true) ? ' checked' : '' ?>> <?= e($text) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($showStatus): ?>
            <p class="muted small">Retired assets are hidden until <strong>Show</strong> above is set to Retired or All.</p>
        <?php endif; ?>
    </fieldset>

    <fieldset class="filter-group">
        <legend>Contains</legend>
        <div class="filter-grid">
            <?php foreach (AssetFilter::TEXTS as $key => [, $label]): ?>
                <?php if (!$filters->has($key)) { continue; } ?>
                <div class="field">
                    <label for="f-<?= e($key) ?>"><?= e($label) ?></label>
                    <input type="text" id="f-<?= e($key) ?>" name="f[<?= e($key) ?>]" value="<?= e($fv($key)) ?>" maxlength="100" autocomplete="off" <?= $fa ?>>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="filter-group">
        <legend>Cost and dates</legend>
        <div class="filter-grid wide">
            <div class="field">
                <span class="field-label">Cost</span>
                <div class="range">
                    <input type="text" inputmode="decimal" name="f[cost_min]" value="<?= e($fv('cost_min')) ?>" placeholder="From" aria-label="Cost from" maxlength="11" autocomplete="off" <?= $fa ?>>
                    <input type="text" inputmode="decimal" name="f[cost_max]" value="<?= e($fv('cost_max')) ?>" placeholder="To" aria-label="Cost to" maxlength="11" autocomplete="off" <?= $fa ?>>
                </div>
            </div>
            <?php foreach (AssetFilter::DATES as $key => [, $label]): ?>
                <?php if (!$filters->has($key)) { continue; } ?>
                <div class="field">
                    <span class="field-label"><?= e($label) ?></span>
                    <div class="range">
                        <input type="date" name="f[<?= e($key) ?>_from]" value="<?= e($fv($key . '_from')) ?>" aria-label="<?= e($label) ?> from" <?= $fa ?>>
                        <input type="date" name="f[<?= e($key) ?>_to]" value="<?= e($fv($key . '_to')) ?>" aria-label="<?= e($label) ?> to" <?= $fa ?>>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="filter-group">
        <legend>Verification and people</legend>
        <div class="filter-grid wide">
            <div class="field">
                <label for="f-ver">Verification</label>
                <div class="range">
                    <select id="f-ver" name="f[ver]" <?= $fa ?>>
                        <?php foreach (AssetFilter::VERIFICATION as $value => $text): ?>
                            <option value="<?= e($value) ?>"<?= $fv('ver') === (string) $value ? ' selected' : '' ?>><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="f[ver_before]" value="<?= e($fv('ver_before')) ?>" aria-label="Not verified since (used when “Not verified since…” is chosen)" <?= $fa ?>>
                </div>
            </div>
            <?php foreach (AssetFilter::PEOPLE as $key => [, $label]): ?>
                <?php if (!$filters->has($key)) { continue; } ?>
                <div class="field">
                    <label for="f-<?= e($key) ?>"><?= e($label) ?></label>
                    <select id="f-<?= e($key) ?>" name="f[<?= e($key) ?>]" <?= $fa ?>>
                        <option value="">Anyone</option>
                        <?php foreach ($options[$key] as $value => $text): ?>
                            <option value="<?= e($value) ?>"<?= $fv($key) === (string) $value ? ' selected' : '' ?>><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="filter-foot">
        <button type="button" class="btn-link" data-filter-clear>Clear filters</button>
        <span class="muted small">Filters combine with each other and with the search box.</span>
    </div>
</section>

<div data-live-region<?= $chips !== [] ? ' class="filter-chips"' : '' ?>>
    <?php foreach ($chips as $chip): ?>
        <a class="chip" href="<?= e($withQuery('/assets', $stateQuery + $chip['query'])) ?>" title="Remove this filter"><?= e($chip['label']) ?> <span aria-hidden="true">×</span><span class="sr-only"> (remove)</span></a>
    <?php endforeach; ?>
    <?php if (count($chips) > 1): ?>
        <a class="chip-clear" href="<?= e($withQuery('/assets', $stateQuery)) ?>">Clear all filters</a>
    <?php endif; ?>
</div>

<div class="card" data-live-region>
    <?php if ($rows === []): ?>
        <p class="empty"><?= $search !== '' || $filterCount > 0 ? 'No assets match your search.' : ($status === 'retired' ? 'No retired assets.' : 'No assets to show.') ?></p>
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
                            <?php foreach ($columns as [$key, $label, $first, $class]): ?>
                                <th scope="col"<?= $class !== '' ? ' class="' . e($class) . '"' : '' ?> aria-sort="<?= $key === $sort ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><?= $sortLink($key, $label, $first) /* built and escaped above */ ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $isRetired = !empty($r['retired']); ?>
                        <tr<?= $isRetired ? ' class="is-retired"' : '' ?>>
                            <?php if ($canBulk): ?>
                                <td class="check-col"><input type="checkbox" name="ids[]" value="<?= e((int) $r['asset_id']) ?>" aria-label="Select <?= e($r['asset_number']) ?>"></td>
                            <?php endif; ?>
                            <td class="nowrap"><a href="<?= e(url('/assets/' . (int) $r['asset_id']) . $listQs) ?>"><?= e($r['asset_number']) ?></a><?php if (!empty($r['attachment_count'])): ?><a class="paperclip" href="<?= e(url('/assets/' . (int) $r['asset_id']) . $listQs . '#attachments') ?>" title="<?= e((int) $r['attachment_count']) ?> attachment<?= (int) $r['attachment_count'] === 1 ? '' : 's' ?>"><?= icon('paperclip', 14) ?><?= e((int) $r['attachment_count']) ?></a><?php endif; ?></td>
                            <td><?= e($r['description']) ?><?php if ($isRetired): ?> <span class="badge badge-warn">Retired</span><?php endif; ?></td>
                            <td><?= e($r['type_name']) ?></td>
                            <td class="nowrap"><?= e($r['serial_number']) ?></td>
                            <td><span class="badge"><?= e($r['dept']) ?></span></td>
                            <td class="nowrap"><?= e($r['building'] . $r['room']) ?></td>
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
