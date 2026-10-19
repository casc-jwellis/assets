<?php
/** Overview. Expects $departments (with assets, users, access, locked) and $types (with assets). */
$plural = static fn(int $n, string $word): string => number_format($n) . ' ' . $word . ($n === 1 ? '' : 's');
?>
<div class="page-head">
    <div>
        <h1>Departments &amp; types</h1>
        <p class="muted">The lists that assets are sorted into. Changes show up everywhere straight away.</p>
    </div>
</div>

<div class="card section">
    <div class="card-head">
        <h2>Departments <span class="muted">(<?= e(count($departments)) ?>)</span></h2>
        <a class="btn btn-primary" href="<?= e(url('/lists/departments/new')) ?>">Add department</a>
    </div>
    <?php if ($departments === []): ?>
        <p class="empty">No departments yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Abbreviation</th><th>Name</th><th class="num">Assets</th><th class="num">Users (home)</th><th class="num">With access</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr>
                        <td class="nowrap"><span class="badge"><?= e($d['abbr']) ?></span></td>
                        <td>
                            <?php if ($d['locked']): ?>
                                <?= e($d['name']) ?> <span class="badge" title="Retiring and restoring assets is recorded against this department, so it cannot be changed or deleted">System</span>
                            <?php else: ?>
                                <a href="<?= e(url('/lists/departments/' . (int) $d['department_id'])) ?>"><?= e($d['name']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(number_format($d['assets'])) ?></td>
                        <td class="num"><?= e(number_format($d['users'])) ?></td>
                        <td class="num"><?= e(number_format($d['access'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card section">
    <div class="card-head">
        <h2>Asset types <span class="muted">(<?= e(count($types)) ?>)</span></h2>
        <a class="btn btn-primary" href="<?= e(url('/lists/types/new')) ?>">Add asset type</a>
    </div>
    <?php if ($types === []): ?>
        <p class="empty">No asset types yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Depreciation</th><th class="num">Assets</th></tr></thead>
                <tbody>
                <?php foreach ($types as $t): ?>
                    <tr>
                        <td><a href="<?= e(url('/lists/types/' . (int) $t['type_id'])) ?>"><?= e($t['name']) ?></a></td>
                        <td><?= (int) $t['depreciation_years'] > 0 ? e($plural((int) $t['depreciation_years'], 'year')) : '<span class="muted">None</span>' ?></td>
                        <td class="num"><?= e(number_format($t['assets'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
