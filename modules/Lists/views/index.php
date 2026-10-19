<?php
/**
 * Overview, one list per tab. Expects $tab ('departments'|'buildings'|'types'), $totals (kind => count), $rows (the active
 * list's rows, with usage counts) and $kinds (kind => table/wording).
 */
$plural = static fn(int $n, string $word): string => number_format($n) . ' ' . $word . ($n === 1 ? '' : 's');
$tabs = ['departments' => 'Departments', 'buildings' => 'Buildings', 'types' => 'Asset types'];
$add = ['departments' => 'Add department', 'buildings' => 'Add building', 'types' => 'Add asset type'];
?>
<div class="page-head">
    <div>
        <h1>Reference data</h1>
        <p class="muted">The departments, buildings and asset types that assets are sorted into. Changes show up everywhere straight away.</p>
    </div>
</div>

<nav class="tabs" aria-label="Reference data lists">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('/lists') . '?tab=' . $key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
            <?= e($label) ?> <span class="tab-count"><?= e(number_format($totals[$key])) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="card">
    <div class="card-head">
        <h2><?= e($tabs[$tab]) ?></h2>
        <a class="btn btn-primary" href="<?= e(url('/lists/' . $tab . '/new')) ?>"><?= e($add[$tab]) ?></a>
    </div>

    <?php if ($rows === []): ?>
        <p class="empty">No <?= e(strtolower($tabs[$tab])) ?> yet.</p>

    <?php elseif ($tab === 'departments'): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Abbreviation</th><th>Name</th><th class="num">Assets</th><th class="num">Users (home)</th><th class="num">With access</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $d): ?>
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

    <?php elseif ($tab === 'buildings'): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Abbreviation</th><th>Name</th><th class="num">Assets</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $b): ?>
                    <tr>
                        <td class="nowrap"><span class="badge"><?= e($b['abbr']) ?></span></td>
                        <td><a href="<?= e(url('/lists/buildings/' . (int) $b['building_id'])) ?>"><?= e($b['name']) ?></a></td>
                        <td class="num"><?= e(number_format($b['assets'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Depreciation</th><th class="num">Assets</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $t): ?>
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
