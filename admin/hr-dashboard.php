<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'hr_manager', 'hr_officer');

$canManageStaff = in_array(current_admin_role(), ['admin', 'hr_manager'], true);
$staffStats = $db->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'active'), 0) AS active,
            COALESCE(SUM(status = 'on_leave'), 0) AS on_leave,
            COALESCE(SUM(status = 'inactive'), 0) AS inactive,
            COALESCE(SUM(hire_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS joined_this_month
     FROM staff"
)->fetch();
$departments = $db->query(
    "SELECT department, COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active
     FROM staff GROUP BY department ORDER BY department"
)->fetchAll();
$recentStaff = $db->query(
    'SELECT name, department, job_title, status, created_at
     FROM staff ORDER BY created_at DESC, id DESC LIMIT 8'
)->fetchAll();

$title = 'People Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="portal-eyebrow">People &amp; HR</div>
        <h1 class="fw-bold mb-1">People Dashboard</h1>
        <p class="text-muted mb-0">Workforce headcount, department coverage, and recent staff activity.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="staff.php">Staff directory</a>
        <?php if ($canManageStaff): ?><a class="btn btn-primary" href="staff.php">+ Add staff</a><?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Team members', (int)$staffStats['total'], 'primary'],
        ['Active', (int)$staffStats['active'], 'success'],
        ['On leave', (int)$staffStats['on_leave'], 'warning'],
        ['Joined this month', (int)$staffStats['joined_this_month'], 'info'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= $value ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3">Department headcount</h5>
            <?php if ($departments): ?>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Department</th><th>Total staff</th><th>Active</th></tr></thead>
                    <tbody><?php foreach ($departments as $department): ?><tr><td><?= e($department['department']) ?></td><td><?= (int)$department['total'] ?></td><td><?= (int)$department['active'] ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="text-muted mb-0">No staff records are available yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0">Recently added staff</h5><a href="staff.php"><?= $canManageStaff ? 'Manage team' : 'View directory' ?></a></div>
            <?php if ($recentStaff): ?>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Name</th><th>Department</th><th>Added</th></tr></thead>
                    <tbody><?php foreach ($recentStaff as $member): ?><tr><td><?= e($member['name']) ?><div class="small text-muted"><?= e($member['job_title']) ?></div></td><td><?= e($member['department']) ?></td><td><?= e(format_date($member['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="text-muted mb-0">Staff profiles added to the directory will appear here.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
