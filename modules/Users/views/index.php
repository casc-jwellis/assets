<?php
/** Expects $rows, $search, $page, $pages, $total, $canDisable. */
$pageUrl = static fn(int $p): string => url('/users') . '?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null]));
$never = static fn($v): bool => $v === null || $v === '' || str_starts_with((string) $v, '0000') || str_starts_with((string) $v, '1970-01-01');
?>
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p class="muted"><?= e(number_format($total)) ?> <?= $total === 1 ? 'user' : 'users' ?><?= $search !== '' ? ' matching your search' : '' ?></p>
    </div>
    <div class="page-actions">
        <form method="get" action="<?= e(url('/users')) ?>" class="search" role="search">
            <?= icon('search', 16) ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, username, ID or email" aria-label="Search users" maxlength="64" data-submit-on-clear>
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

<div class="card">
    <?php if ($rows === []): ?>
        <p class="empty"><?= $search !== '' ? 'No users match your search.' : 'No users yet.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th><th>Username</th><th>User ID</th><th>Email</th><th>Home dept</th>
                        <th>Role</th><th>Last sign-in</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $name = trim($r['lastname'] . ($r['lastname'] !== '' && $r['firstname'] !== '' ? ', ' : '') . $r['firstname']); ?>
                    <tr>
                        <td><a href="<?= e(url('/users/' . rawurlencode((string) $r['user_id'])) . $listQs) ?>"><?= e($name !== '' ? $name : $r['username']) ?></a></td>
                        <td><?= e($r['username']) ?></td>
                        <td class="nowrap"><?= e($r['user_id']) ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td><?php if (!empty($r['dept'])): ?><span class="badge"><?= e($r['dept']) ?></span><?php endif; ?></td>
                        <td>
                            <?php if ($r['admin']): ?>
                                <span class="badge badge-accent">Admin</span>
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
