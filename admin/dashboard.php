<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch', 'hr_manager', 'hr_officer');

$branchRole = is_role('branch');
$hrRole = in_array(current_admin_role(), ['hr_manager', 'hr_officer'], true);
$staffAccess = in_array(current_admin_role(), ['admin', 'hr_manager', 'hr_officer'], true);
$assignedBranchId = current_admin_branch_id();
$branches = $branchRole
    ? $db->prepare('SELECT id, name FROM branches WHERE id = ? ORDER BY name')
    : $db->query('SELECT id, name FROM branches ORDER BY name');
if ($branchRole) {
    $branches->execute([$assignedBranchId]);
}
$branches = $branches->fetchAll();
$branchId = filter_var($_GET['branch'] ?? null, FILTER_VALIDATE_INT);
$branchId = $branchId !== false && $branchId !== null && $branchId > 0 ? $branchId : null;
if ($branchRole) {
    $branchId = $assignedBranchId;
}
$branchCondition = $branchId !== null ? ' WHERE s.branch_id = ?' : '';
$branchConditionV = $branchId !== null ? ' WHERE v.branch_id = ?' : '';

if ($branchId === null) {
    $stats = [
        'total' => (int)$db->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
        'valid' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'valid' AND (expiry_date IS NULL OR expiry_date >= CURDATE())")->fetchColumn(),
        'expired' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'valid' AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn(),
        'revoked' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE status = 'revoked'")->fetchColumn(),
    ];
    $recent = $db->query('SELECT * FROM certificates ORDER BY id DESC LIMIT 10')->fetchAll();
} else {
    $stats = [];
    foreach ([
        'total' => '',
        'valid' => " AND c.status = 'valid' AND (c.expiry_date IS NULL OR c.expiry_date >= CURDATE())",
        'expired' => " AND c.status = 'valid' AND c.expiry_date IS NOT NULL AND c.expiry_date < CURDATE()",
        'revoked' => " AND c.status = 'revoked'",
    ] as $key => $condition) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM certificates c JOIN students s ON s.certificate_id = c.id WHERE s.branch_id = ?' . $condition);
        $stmt->execute([$branchId]);
        $stats[$key] = (int)$stmt->fetchColumn();
    }
    $stmt = $db->prepare('SELECT c.* FROM certificates c JOIN students s ON s.certificate_id = c.id WHERE s.branch_id = ? ORDER BY c.id DESC LIMIT 10');
    $stmt->execute([$branchId]);
    $recent = $stmt->fetchAll();
}
$studentSql = "SELECT COUNT(*) AS students,
                      SUM(s.status = 'active') AS active,
                      SUM(s.status = 'completed') AS completed,
                      COALESCE(SUM(s.total_fee), 0) AS billed,
                      COALESCE(SUM(COALESCE(p.paid, 0)), 0) AS paid
               FROM students s
               LEFT JOIN (SELECT student_id, SUM(amount) AS paid FROM student_payments GROUP BY student_id) p ON p.student_id = s.id"
               . $branchCondition;
$stmt = $db->prepare($studentSql);
$stmt->execute($branchId !== null ? [$branchId] : []);
$studentStats = $stmt->fetch();
$vehicleSql = "SELECT COUNT(*) AS vehicles,
                      SUM(v.status = 'available' AND fa.id IS NULL) AS available,
                      SUM(fa.id IS NOT NULL) AS assigned
               FROM vehicles v
               LEFT JOIN fleet_assignments fa ON fa.vehicle_id = v.id AND fa.returned_on IS NULL"
               . $branchConditionV;
$stmt = $db->prepare($vehicleSql);
$stmt->execute($branchId !== null ? [$branchId] : []);
$fleetStats = $stmt->fetch();
$branchRowsSql =
    "SELECT b.id, b.name, COALESCE(s.students, 0) AS students, COALESCE(s.billed, 0) AS billed,
            COALESCE(s.paid, 0) AS paid, COALESCE(v.vehicles, 0) AS vehicles,
            COALESCE(v.assigned, 0) AS assigned, COALESCE(v.available, 0) AS available
     FROM branches b
     LEFT JOIN (
         SELECT x.branch_id, COUNT(*) AS students, SUM(x.total_fee) AS billed,
                SUM(COALESCE(p.paid, 0)) AS paid
         FROM students x
         LEFT JOIN (SELECT student_id, SUM(amount) AS paid FROM student_payments GROUP BY student_id) p ON p.student_id = x.id
         GROUP BY x.branch_id
     ) s ON s.branch_id = b.id
     LEFT JOIN (
         SELECT x.branch_id, COUNT(*) AS vehicles,
                    SUM(fa.id IS NOT NULL) AS assigned,
                    SUM(x.status = 'available' AND fa.id IS NULL) AS available
         FROM vehicles x
         LEFT JOIN fleet_assignments fa ON fa.vehicle_id = x.id AND fa.returned_on IS NULL
         GROUP BY x.branch_id
     ) v ON v.branch_id = b.id
    ";
if ($branchRole) {
    $branchRowsSql .= ' WHERE b.id = ?';
}
$branchRowsSql .= ' ORDER BY b.name';
$branchRowsStmt = $db->prepare($branchRowsSql);
$branchRowsStmt->execute($branchRole ? [$assignedBranchId] : []);
$branchRows = $branchRowsStmt->fetchAll();
$staffStats = $staffAccess ? $db->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'on_leave') AS on_leave,
            SUM(hire_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS joined_this_month
     FROM staff"
)->fetch() : null;
$staffDepartments = $staffAccess ? $db->query(
    "SELECT department, COUNT(*) AS total, SUM(status = 'active') AS active
     FROM staff GROUP BY department ORDER BY department"
)->fetchAll() : [];
$recentStaff = $staffAccess ? $db->query(
    'SELECT name, department, job_title, status, created_at FROM staff ORDER BY created_at DESC, id DESC LIMIT 5'
)->fetchAll() : [];

$title = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Certificate Registry</div>
        <h1 class="fw-bold mb-1"><?= $hrRole ? 'People Dashboard' : 'Dashboard' ?></h1>
        <p class="text-muted mb-0"><?= $hrRole ? 'Monitor workforce size, department coverage, and recent staff activity.' : 'Monitor enrollment, fees, branch operations, fleet and certificates.' ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!$branchRole && !$hrRole): ?>
        <form method="get" class="d-flex gap-2">
            <select name="branch" class="form-select" aria-label="Filter dashboard by branch">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
        <?php endif; ?>
        <?php if ($hrRole): ?>
            <?php if (current_admin_role() === 'hr_manager'): ?><a class="btn btn-primary" href="staff.php">+ Add staff</a><?php endif; ?>
            <a class="btn btn-outline-primary" href="staff.php">Staff directory</a>
        <?php elseif (!$branchRole): ?>
            <?php if ($staffAccess): ?><a class="btn btn-outline-primary" href="staff.php">Staff</a><?php endif; ?>
            <a class="btn btn-primary" href="create-certificate.php">+ Issue Certificate</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php if ($hrRole): ?>
        <?php foreach ([
            ['Team members', (int)$staffStats['total'], 'primary'],
            ['Active', (int)$staffStats['active'], 'success'],
            ['On leave', (int)$staffStats['on_leave'], 'warning'],
            ['Joined this month', (int)$staffStats['joined_this_month'], 'info'],
        ] as [$label, $value, $color]): ?>
            <div class="col-6 col-lg-3"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= $value ?></div></div></div>
        <?php endforeach; ?>
    <?php else: ?>
    <?php foreach ([
        ['Students', (int)$studentStats['students'], 'primary'],
        ['Active', (int)$studentStats['active'], 'success'],
        ['Completed', (int)$studentStats['completed'], 'info'],
        ['Fees billed', 'KES ' . number_format((float)$studentStats['billed'], 2), 'dark'],
        ['Fees received', 'KES ' . number_format((float)$studentStats['paid'], 2), 'success'],
        ['Fee balance', 'KES ' . number_format(max(0, (float)$studentStats['billed'] - (float)$studentStats['paid']), 2), 'warning'],
        ['Fleet vehicles', (int)$fleetStats['vehicles'], 'dark'],
        ['Cars assigned', (int)$fleetStats['assigned'], 'primary'],
        ['Cars available', (int)$fleetStats['available'], 'success'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= e($value) ?></div></div></div>
    <?php endforeach; ?>
    <?php foreach ([
        ['Certificates', $stats['total'], 'dark'],
        ['Valid', $stats['valid'], 'success'],
        ['Expired', $stats['expired'], 'warning'],
        ['Revoked', $stats['revoked'], 'danger'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3">
            <div class="card p-3 border-start border-4 border-<?= $color ?>">
                <div class="text-muted"><?= $label ?></div>
                <div class="stat-number"><?= $value ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($staffAccess): foreach ([
        ['Team members', (int)$staffStats['total'], 'primary'],
        ['Staff active', (int)$staffStats['active'], 'success'],
        ['Staff on leave', (int)$staffStats['on_leave'], 'warning'],
        ['Joined this month', (int)$staffStats['joined_this_month'], 'info'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= $value ?></div></div></div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($hrRole || is_role('admin')): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0">Department headcount</h5><a href="staff.php">View directory</a></div>
            <?php if ($staffDepartments): ?>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Department</th><th>Total staff</th><th>Active</th></tr></thead>
                    <tbody><?php foreach ($staffDepartments as $department): ?><tr><td><?= e($department['department']) ?></td><td><?= (int)$department['total'] ?></td><td><?= (int)$department['active'] ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="text-muted mb-0">No staff records are available yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0">Recently added staff</h5><a href="staff.php"><?= current_admin_role() === 'hr_officer' ? 'View directory' : 'Manage team' ?></a></div>
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
<?php endif; ?>
<?php if (!$hrRole): ?>
<div class="card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="fw-bold mb-0">Branch Overview</h5><a href="fleet.php"><?= $branchRole || is_role('manager') || is_role('director') ? 'View fleet' : 'Manage fleet' ?></a></div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Branch</th><th>Students</th><th>Fees billed</th><th>Fees received</th><th>Balance</th><th>Vehicles</th><th>Assigned</th><th>Available</th></tr></thead>
        <tbody><?php foreach ($branchRows as $row): ?>
            <tr><td><a href="?branch=<?= (int)$row['id'] ?>"><?= e($row['name']) ?></a></td><td><?= (int)$row['students'] ?></td><td>KES <?= number_format((float)$row['billed'], 2) ?></td><td>KES <?= number_format((float)$row['paid'], 2) ?></td><td>KES <?= number_format(max(0, (float)$row['billed'] - (float)$row['paid']), 2) ?></td><td><?= (int)$row['vehicles'] ?></td><td><?= (int)$row['assigned'] ?></td><td><?= (int)$row['available'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>

<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Recent Certificates</h5>
        <a href="certificates.php">View all</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Number</th><th>Holder</th><th>Type</th><th>Course</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent as $cert): ?>
                <tr>
                    <td><?= e($cert['certificate_number']) ?></td>
                    <td><?= e($cert['holder_name']) ?></td>
                    <td><?= e($cert['certificate_type']) ?></td>
                    <td><?= e($cert['course_name']) ?></td>
                    <td><?= status_badge(certificate_status($cert)) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="view-certificate.php?id=<?= (int)$cert['id'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-4">No certificates have been issued yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
