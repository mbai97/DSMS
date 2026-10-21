<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch');

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$branchRole = is_role('branch');

if ($q !== '') {
    $sql = 'SELECT c.* FROM certificates c';
    if ($branchRole) {
        $sql .= ' JOIN students s ON s.certificate_id = c.id WHERE s.branch_id = ? AND';
    } else {
        $sql .= ' WHERE';
    }
    $sql .= ' (c.certificate_number LIKE ? OR c.holder_name LIKE ? OR c.certificate_type LIKE ? OR c.course_name LIKE ?) ORDER BY c.id DESC LIMIT 100';
    $stmt = $db->prepare($sql);
    $like = '%' . $q . '%';
    $stmt->execute($branchRole
        ? [current_admin_branch_id(), $like, $like, $like, $like]
        : [$like, $like, $like, $like]);
    $certificates = $stmt->fetchAll();
} else {
    if ($branchRole) {
        $stmt = $db->prepare('SELECT c.* FROM certificates c JOIN students s ON s.certificate_id = c.id WHERE s.branch_id = ? ORDER BY c.id DESC LIMIT 100');
        $stmt->execute([current_admin_branch_id()]);
        $certificates = $stmt->fetchAll();
    } else {
        $certificates = $db->query('SELECT * FROM certificates ORDER BY id DESC LIMIT 100')->fetchAll();
    }
}

$title = 'Certificates';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="fw-bold">Certificates</h1>
        <p class="text-muted mb-0">Search and manage issued certificates.</p>
    </div>
    <?php if (in_array(current_admin_role(), ['admin', 'manager', 'director'], true)): ?><a class="btn btn-primary" href="create-certificate.php">+ Issue Certificate</a><?php endif; ?>
</div>

<div class="card p-3">
    <form class="row g-2 mb-3">
        <div class="col-md-10"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search number, holder, type or course" aria-label="Search certificates"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100">Search</button></div>
    </form>

    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Certificate</th><th>Holder</th><th>Type</th><th>Course</th><th>Issued</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($certificates as $cert): ?>
                <tr>
                    <td><?= e($cert['certificate_number']) ?></td>
                    <td><?= e($cert['holder_name']) ?></td>
                    <td><?= e($cert['certificate_type']) ?></td>
                    <td><?= e($cert['course_name']) ?></td>
                    <td><?= e(format_date($cert['issue_date'])) ?></td>
                    <td><?= status_badge(certificate_status($cert)) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="view-certificate.php?id=<?= (int)$cert['id'] ?>"><?= $branchRole ? 'View / Print' : (is_role('admin') ? 'Manage' : 'View / Print') ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$certificates): ?><tr><td colspan="7" class="text-center text-muted py-4">No certificates match this search.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
