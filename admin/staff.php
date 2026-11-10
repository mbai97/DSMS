<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'hr_manager', 'hr_officer');

$canManageStaff = in_array(current_admin_role(), ['admin', 'hr_manager'], true);
$errors = [];
$success = '';
$form = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'department' => '',
    'job_title' => '',
    'hire_date' => '',
    'status' => 'active',
];
$editInput = $_GET['edit'] ?? null;
$editingId = is_scalar($editInput) ? filter_var($editInput, FILTER_VALIDATE_INT) : false;
$editingId = $editingId !== false && $editingId !== null && $editingId > 0 ? $editingId : null;

if ($editingId !== null) {
    $stmt = $db->prepare('SELECT id, name, email, phone, department, job_title, hire_date, status FROM staff WHERE id = ?');
    $stmt->execute([$editingId]);
    $record = $stmt->fetch();
    if ($record) {
        $form = $record;
    } else {
        $errors[] = 'The requested staff record could not be found.';
        $editingId = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'save_staff' && $canManageStaff) {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $phone = is_string($_POST['phone'] ?? null) ? trim($_POST['phone']) : '';
        $department = is_string($_POST['department'] ?? null) ? trim($_POST['department']) : '';
        $jobTitle = is_string($_POST['job_title'] ?? null) ? trim($_POST['job_title']) : '';
        $hireDate = is_string($_POST['hire_date'] ?? null) ? trim($_POST['hire_date']) : '';
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        $postedStaffId = $_POST['staff_id'] ?? null;
        $postedId = is_scalar($postedStaffId) ? filter_var($postedStaffId, FILTER_VALIDATE_INT) : false;
        $editingId = $postedId !== false && $postedId !== null && $postedId > 0 ? $postedId : null;

        $form = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'department' => $department,
            'job_title' => $jobTitle,
            'hire_date' => $hireDate,
            'status' => $status,
        ];

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Name is required and must be 120 characters or fewer.';
        }
        if ($email !== '' && (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address of 190 characters or fewer.';
        }
        if (mb_strlen($phone) > 30) {
            $errors[] = 'Phone number must be 30 characters or fewer.';
        }
        if ($department === '' || mb_strlen($department) > 100) {
            $errors[] = 'Department is required and must be 100 characters or fewer.';
        }
        if ($jobTitle === '' || mb_strlen($jobTitle) > 120) {
            $errors[] = 'Job title is required and must be 120 characters or fewer.';
        }
        if (!in_array($status, ['active', 'on_leave', 'inactive'], true)) {
            $errors[] = 'Select a valid staff status.';
        }
        if ($hireDate !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $hireDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                $parsedDate === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $parsedDate->format('Y-m-d') !== $hireDate
            ) {
                $errors[] = 'Enter a valid hire date.';
            }
        }
        if (
            isset($_POST['staff_id'])
            && (!is_scalar($postedStaffId) || $editingId === null || (string)$editingId !== (string)$postedStaffId)
        ) {
            $errors[] = 'Select a valid staff record.';
        }

        if (!$errors) {
            try {
                if ($editingId === null) {
                    $stmt = $db->prepare(
                        'INSERT INTO staff (name, email, phone, department, job_title, hire_date, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $department,
                        $jobTitle,
                        $hireDate !== '' ? $hireDate : null,
                        $status,
                        (int)$_SESSION['admin_id'],
                    ]);
                    $success = 'Staff member added.';
                } else {
                    $stmt = $db->prepare(
                        'UPDATE staff SET name = ?, email = ?, phone = ?, department = ?, job_title = ?, hire_date = ?, status = ?
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $department,
                        $jobTitle,
                        $hireDate !== '' ? $hireDate : null,
                        $status,
                        $editingId,
                    ]);
                    $success = 'Staff record updated.';
                }
                $form = [
                    'name' => '',
                    'email' => '',
                    'phone' => '',
                    'department' => '',
                    'job_title' => '',
                    'hire_date' => '',
                    'status' => 'active',
                ];
                $editingId = null;
            } catch (Throwable $exception) {
                error_log('Staff record save failed: ' . $exception->getMessage());
                $errors[] = 'Unable to save the staff record. Please try again.';
            }
        }
    } elseif ($action === 'change_status' && $canManageStaff) {
        $staffIdInput = $_POST['staff_id'] ?? null;
        $staffId = is_scalar($staffIdInput) ? filter_var($staffIdInput, FILTER_VALIDATE_INT) : false;
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($staffId === false || $staffId === null || $staffId < 1 || !in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Select a valid staff record and status.';
        } else {
            try {
                $stmt = $db->prepare('UPDATE staff SET status = ? WHERE id = ?');
                $stmt->execute([$status, $staffId]);
                if ($stmt->rowCount() === 0) {
                    $stmt = $db->prepare('SELECT 1 FROM staff WHERE id = ?');
                    $stmt->execute([$staffId]);
                    if (!$stmt->fetchColumn()) {
                        $errors[] = 'The requested staff record could not be found.';
                    }
                }
                if (!$errors) {
                    $success = $status === 'active' ? 'Staff member reactivated.' : 'Staff member deactivated.';
                }
            } catch (Throwable $exception) {
                error_log('Staff status update failed: ' . $exception->getMessage());
                $errors[] = 'Unable to update the staff status. Please try again.';
            }
        }
    } else {
        http_response_code(403);
        exit('You do not have permission to perform this staff operation.');
    }
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, ['', 'active', 'on_leave', 'inactive'], true)) {
    $statusFilter = '';
}
$sql = 'SELECT id, name, email, phone, department, job_title, hire_date, status FROM staff';
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(name LIKE ? OR email LIKE ? OR department LIKE ? OR job_title LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($statusFilter !== '') {
    $conditions[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY department, name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

$summary = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'on_leave') AS on_leave,
            SUM(status = 'inactive') AS inactive
     FROM staff"
)->fetch();
$departments = $db->query(
    'SELECT department, COUNT(*) AS total, SUM(status = \'active\') AS active
     FROM staff GROUP BY department ORDER BY department'
)->fetchAll();

$title = 'Staff Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">People &amp; HR</div>
        <h1 class="fw-bold mb-1">Staff Management</h1>
        <p class="text-muted mb-0"><?= $canManageStaff ? 'Manage employee profiles, departments, and employment status.' : 'View employee profiles, departments, and employment status.' ?></p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Team members', (int)$summary['total'], 'primary'],
        ['Active', (int)$summary['active'], 'success'],
        ['On leave', (int)$summary['on_leave'], 'warning'],
        ['Inactive', (int)$summary['inactive'], 'dark'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= $value ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <?php if ($canManageStaff): ?>
        <div class="col-lg-7">
            <div class="card p-4 h-100">
                <h5 class="fw-bold mb-3"><?= $editingId === null ? 'Add staff member' : 'Edit staff member' ?></h5>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_staff">
                    <?php if ($editingId !== null): ?><input type="hidden" name="staff_id" value="<?= $editingId ?>"><?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="staff-name">Full name</label><input id="staff-name" name="name" maxlength="120" class="form-control" value="<?= e($form['name']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="staff-email">Work email</label><input id="staff-email" type="email" name="email" maxlength="190" class="form-control" value="<?= e($form['email']) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="staff-phone">Phone</label><input id="staff-phone" name="phone" maxlength="30" class="form-control" value="<?= e($form['phone']) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="staff-department">Department</label><input id="staff-department" name="department" maxlength="100" class="form-control" value="<?= e($form['department']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label" for="staff-title">Job title</label><input id="staff-title" name="job_title" maxlength="120" class="form-control" value="<?= e($form['job_title']) ?>" required></div>
                        <div class="col-md-3"><label class="form-label" for="staff-hire-date">Hire date</label><input id="staff-hire-date" type="date" name="hire_date" class="form-control" value="<?= e($form['hire_date']) ?>"></div>
                        <div class="col-md-3"><label class="form-label" for="staff-status">Status</label><select id="staff-status" name="status" class="form-select" required>
                            <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="on_leave" <?= $form['status'] === 'on_leave' ? 'selected' : '' ?>>On leave</option>
                            <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select></div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary"><?= $editingId === null ? 'Add staff member' : 'Save changes' ?></button>
                        <?php if ($editingId !== null): ?><a class="btn btn-outline-secondary" href="staff.php">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <div class="<?= $canManageStaff ? 'col-lg-5' : 'col-12' ?>">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3">Department overview</h5>
            <?php if ($departments): ?>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Department</th><th>Team</th><th>Active</th></tr></thead>
                    <tbody><?php foreach ($departments as $department): ?><tr><td><?= e($department['department']) ?></td><td><?= (int)$department['total'] ?></td><td><?= (int)$department['active'] ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="text-muted mb-0">No staff records are available yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <h5 class="fw-bold mb-0">Employee directory</h5>
        <form method="get" class="d-flex flex-wrap gap-2">
            <input type="search" name="q" class="form-control" placeholder="Search people or departments" value="<?= e($search) ?>" aria-label="Search staff directory">
            <select name="status" class="form-select" aria-label="Filter by staff status">
                <option value="">All statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="on_leave" <?= $statusFilter === 'on_leave' ? 'selected' : '' ?>>On leave</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Name</th><th>Department / title</th><th>Contact</th><th>Hire date</th><th>Status</th><?php if ($canManageStaff): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$staff): ?>
            <tr><td colspan="<?= $canManageStaff ? 6 : 5 ?>" class="text-center text-muted py-4">No staff records match this view.</td></tr>
        <?php else: foreach ($staff as $member): ?>
            <tr>
                <td class="fw-semibold"><?= e($member['name']) ?></td>
                <td><?= e($member['department']) ?><div class="small text-muted"><?= e($member['job_title']) ?></div></td>
                <td><?= e($member['email'] ?: '—') ?><div class="small text-muted"><?= e($member['phone'] ?: '') ?></div></td>
                <td><?= e(format_date($member['hire_date'])) ?></td>
                <td><span class="badge text-bg-<?= $member['status'] === 'active' ? 'success' : ($member['status'] === 'on_leave' ? 'warning' : 'secondary') ?>"><?= e(str_replace('_', ' ', ucfirst($member['status']))) ?></span></td>
                <?php if ($canManageStaff): ?>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$member['id'] ?>">Edit</a>
                        <?php if ($member['status'] !== 'on_leave'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('<?= $member['status'] === 'inactive' ? 'Reactivate' : 'Deactivate' ?> this staff member?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="staff_id" value="<?= (int)$member['id'] ?>">
                                <input type="hidden" name="status" value="<?= $member['status'] === 'inactive' ? 'active' : 'inactive' ?>">
                                <button class="btn btn-sm btn-outline-secondary"><?= $member['status'] === 'inactive' ? 'Reactivate' : 'Deactivate' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
