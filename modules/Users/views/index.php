<?php
/** Expects $rows, $search, $page, $pages, $total, $status ('active'|'disabled'|'all'), $sort, $dir ('asc'|'desc'), $canDisable, $listQs. */
// The sort as it goes in a link: nothing for the default order (name, A to Z).
$sortQuery = static fn(string $key, string $d): array => [
    'sort' => $key === 'name' ? null : $key,
    'dir' => $d === 'desc' ? 'desc' : null,
];
$pageUrl = static fn(int $p): string => url('/users') . '?' . http_build_query(array_filter([
    'q' => $search,
    'status' => $status === 'active' ? null : $status,
    'page' => $p > 1 ? $p : null,
] + $sortQuery($sort, $dir)));
$allUrl = url('/users') . '?' . http_build_query(array_filter(['q' => $search, 'status' => 'all'] + $sortQuery($sort, $dir)));

// Column headers: [sort key, label, direction of the first click]. Last sign-in starts newest-first.
$columns = [
    ['name', 'Name', 'asc'], ['username', 'Username', 'asc'], ['id', 'User ID', 'asc'], ['email', 'Email', 'asc'],
    ['dept', 'Home dept', 'asc'], ['role', 'Role', 'asc'], ['login', 'Last sign-in', 'desc'],
];
if ($canDisable) {
    $columns[] = ['status', 'Status', 'asc'];
}
$sortLink = static function (string $key, string $label, string $first) use ($sort, $dir, $search, $status, $sortQuery): string {
    $active = $key === $sort;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $first; // clicking the sorted column reverses it
    $href = url('/users') . '?' . http_build_query(array_filter([
        'q' => $search,
        'status' => $status === 'active' ? null : $status,
    ] + $sortQuery($key, $next)));
    $arrow = $active ? ' <span class="sort-arrow" aria-hidden="true">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<a class="sort-link" href="' . e($href) . '" title="Sort by ' . e(strtolower($label)) . '">' . e($label) . $arrow . '</a>';
};
$statusLabels = ['active' => 'Active', 'disabled' => 'Disabled', 'all' => 'All'];
$never = static fn($v): bool => $v === null || $v === '' || str_starts_with((string) $v, '0000') || str_starts_with((string) $v, '1970-01-01');
?>
<div class="page-head">
    <div data-live-region>
        <h1>Users</h1>
        <p class="muted"><?= e(number_format($total)) ?> <?= $status === 'active' && $canDisable ? 'active ' : ($status === 'disabled' ? 'disabled ' : '') ?><?= $total === 1 ? 'user' : 'users' ?><?= $search !== '' ? ' matching your search' : '' ?></p>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url('/users')) ?>" class="search-form" role="search">
            <div class="search">
                <?= icon('search', 16) ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, username, ID or email" aria-label="Search users" maxlength="64" data-live-search>
            </div>
            <?php if ($canDisable): ?>
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
        </form>
        <a class="btn btn-primary" href="<?= e(url('/users/new') . $listQs) ?>">Add user</a>
    </div>
</div>

<?php if (!$canDisable): ?>
    <div class="alert alert-info" role="status">
        Disabling users needs a database update. An administrator can apply it on the
        <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.
    </div>
<?php endif; ?>

<div class="card" data-live-region>
    <?php if ($rows === []): ?>
        <p class="empty">
            <?php if ($status === 'active' && $canDisable): ?>
                No active users<?= $search !== '' ? ' match your search' : '' ?>.
                <a href="<?= e($allUrl) ?>">Include disabled users</a>
            <?php elseif ($status === 'disabled'): ?>
                No disabled users<?= $search !== '' ? ' match your search' : '' ?>.
            <?php else: ?>
                <?= $search !== '' ? 'No users match your search.' : 'No users yet.' ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <?php foreach ($columns as [$key, $label, $first]): ?>
                            <th scope="col" aria-sort="<?= $key === $sort ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><?= $sortLink($key, $label, $first) /* built and escaped above */ ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $name = trim($r['lastname'] . ($r['lastname'] !== '' && $r['firstname'] !== '' ? ', ' : '') . $r['firstname']); ?>
                    <tr>
                        <td>
                            <?php if ($r['username'] === 'missing-user'): ?>
                                <?= e($name) ?> <span class="badge" title="Stands in for people who no longer exist, so old assets and history stay readable">System placeholder</span>
                            <?php else: ?>
                                <a href="<?= e(url('/users/' . (int) $r['user_id']) . $listQs) ?>"><?= e($name !== '' ? $name : $r['username']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['username']) ?></td>
                        <td class="nowrap"><?= e($r['user_id']) ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td><?php if (!empty($r['dept'])): ?><span class="badge"><?= e($r['dept']) ?></span><?php endif; ?></td>
                        <td>
                            <?php if ($r['admin']): ?>
                                <span class="badge badge-accent">Admin</span>
                            <?php elseif (!empty($r['manager'])): ?>
                                <span class="badge badge-accent">Asset manager</span>
                            <?php else: ?>
                                <span class="muted"><?= e((int) $r['dept_count']) ?> <?= (int) $r['dept_count'] === 1 ? 'dept' : 'depts' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= $never($r['lastlogin']) ? '<span class="muted">Never</span>' : e(fmt_date($r['lastlogin'])) ?></td>
                        <td>
                            <?php if (!empty($r['disabled'])): ?>
                                <span class="badge badge-danger">Disabled</span>
                            <?php else: ?>
                                <span class="badge badge-ok">Active</span>
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
