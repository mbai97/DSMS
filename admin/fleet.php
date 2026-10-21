<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch');

$errors = [];
$success = '';
$branchRole = is_role('branch');
$assignedBranchId = current_admin_branch_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($branchRole) {
        http_response_code(403);
        exit('Branch accounts have read-only access to the fleet.');
    }
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'add_vehicle') {
        $registration = strtoupper(trim((string)($_POST['registration_no'] ?? '')));
        $makeModel = trim((string)($_POST['make_model'] ?? ''));
        $branchId = $branchRole ? $assignedBranchId : filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($registration === '' || mb_strlen($registration) > 30) $errors[] = 'Enter a valid vehicle registration (30 characters or fewer).';
        if ($makeModel === '' || mb_strlen($makeModel) > 120) $errors[] = 'Enter the vehicle make/model (120 characters or fewer).';
        if ($branchId === false || $branchId === null) $errors[] = 'Select a valid branch.';

        if (!$errors) {
            try {
                $stmt = $db->prepare('INSERT INTO vehicles (registration_no, make_model, branch_id) VALUES (?, ?, ?)');
                $stmt->execute([$registration, $makeModel, $branchId]);
                $success = 'Vehicle added to the fleet.';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = 'A vehicle with that registration already exists.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'assign_vehicle') {
        $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
        $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($vehicleId === false || $vehicleId === null || $studentId === false || $studentId === null) {
            $errors[] = 'Select a valid vehicle and student.';
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('SELECT id, branch_id, status FROM vehicles WHERE id = ? FOR UPDATE');
                $stmt->execute([$vehicleId]);
                $vehicle = $stmt->fetch();
                $stmt = $db->prepare('SELECT id, branch_id, status FROM students WHERE id = ? FOR UPDATE');
                $stmt->execute([$studentId]);
                $student = $stmt->fetch();
                if (!$vehicle || !$student || $vehicle['status'] !== 'available' || (int)$vehicle['branch_id'] !== (int)$student['branch_id'] || $student['status'] !== 'active' || ($branchRole && (int)$vehicle['branch_id'] !== $assignedBranchId)) {
                    throw new RuntimeException('The vehicle and active student must belong to the same branch, and the vehicle must be available.');
                }
                $stmt = $db->prepare('SELECT COUNT(*) FROM fleet_assignments WHERE vehicle_id = ? AND returned_on IS NULL');
                $stmt->execute([$vehicleId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RuntimeException('This vehicle already has an active assignment.');
                }
                $stmt = $db->prepare('SELECT COUNT(*) FROM fleet_assignments WHERE student_id = ? AND returned_on IS NULL');
                $stmt->execute([$studentId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RuntimeException('This student already has an active vehicle assignment.');
                }
                $stmt = $db->prepare('INSERT INTO fleet_assignments (vehicle_id, student_id, branch_id, assigned_on, created_by) VALUES (?, ?, ?, CURDATE(), ?)');
                $stmt->execute([$vehicleId, $studentId, $vehicle['branch_id'], (int)$_SESSION['admin_id']]);
                $db->commit();
                $success = 'Vehicle assigned to the student.';
            } catch (Throwable $exception) {
                if ($db->inTransaction()) $db->rollBack();
                if ($exception instanceof RuntimeException) {
                    $errors[] = $exception->getMessage();
                } else {
                    error_log('Fleet assignment failed: ' . $exception->getMessage());
                    $errors[] = 'Unable to assign the vehicle.';
                }
            }
        }
    } elseif ($action === 'return_vehicle') {
        $assignmentId = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($assignmentId === false || $assignmentId === null) {
            $errors[] = 'Select a valid vehicle assignment.';
        } else {
            $sql = 'UPDATE fleet_assignments SET returned_on = CURDATE() WHERE id = ? AND returned_on IS NULL';
            if ($branchRole) {
                $sql .= ' AND branch_id = ?';
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($branchRole ? [$assignmentId, $assignedBranchId] : [$assignmentId]);
            if ($stmt->rowCount() !== 1) {
                $errors[] = 'The assignment is no longer active.';
            } else {
                $success = 'Vehicle returned to the available fleet.';
            }
        }
    } elseif ($action === 'update_vehicle_status') {
        $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($vehicleId === false || $vehicleId === null || !in_array($status, ['available', 'maintenance', 'retired'], true)) {
            $errors[] = 'Select a valid vehicle status.';
        } else {
            $scopeSql = 'SELECT branch_id FROM vehicles WHERE id = ?';
            $stmt = $db->prepare($scopeSql);
            $stmt->execute([$vehicleId]);
            $vehicleBranchId = $stmt->fetchColumn();
            if ($branchRole && ($vehicleBranchId === false || (int)$vehicleBranchId !== $assignedBranchId)) {
                http_response_code(404);
                exit('Vehicle not found.');
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM fleet_assignments WHERE vehicle_id = ? AND returned_on IS NULL');
            $stmt->execute([$vehicleId]);
            if ($status !== 'available' && (int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Return the vehicle before placing it in maintenance or retiring it.';
            } else {
                $sql = 'UPDATE vehicles SET status = ? WHERE id = ?';
                if ($branchRole) {
                    $sql .= ' AND branch_id = ?';
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($branchRole ? [$status, $vehicleId, $assignedBranchId] : [$status, $vehicleId]);
                $success = 'Vehicle status updated.';
            }
        }
    } else {
        $errors[] = 'Invalid fleet action.';
    }
}

$branchesStmt = $branchRole
    ? $db->prepare('SELECT id, name FROM branches WHERE id = ? ORDER BY name')
    : $db->query('SELECT id, name FROM branches ORDER BY name');
if ($branchRole) {
    $branchesStmt->execute([$assignedBranchId]);
}
$branches = $branchesStmt->fetchAll();
$vehiclesSql =
    'SELECT v.*, b.name AS branch_name, fa.id AS assignment_id, s.holder_name AS student_name, s.admission_number
     FROM vehicles v
     JOIN branches b ON b.id = v.branch_id
     LEFT JOIN fleet_assignments fa ON fa.vehicle_id = v.id AND fa.returned_on IS NULL
     LEFT JOIN students s ON s.id = fa.student_id
    ';
if ($branchRole) {
    $vehiclesSql .= ' WHERE v.branch_id = ?';
}
$vehiclesSql .= ' ORDER BY b.name, v.registration_no';
$vehiclesStmt = $db->prepare($vehiclesSql);
$vehiclesStmt->execute($branchRole ? [$assignedBranchId] : []);
$vehicles = $vehiclesStmt->fetchAll();
$availableVehiclesSql =
    "SELECT v.id, v.registration_no, v.branch_id, b.name AS branch_name FROM vehicles v
     JOIN branches b ON b.id = v.branch_id
     LEFT JOIN fleet_assignments fa ON fa.vehicle_id = v.id AND fa.returned_on IS NULL
     WHERE v.status = 'available' AND fa.id IS NULL";
if ($branchRole) {
    $availableVehiclesSql .= ' AND v.branch_id = ?';
}
$availableVehiclesSql .= ' ORDER BY b.name, v.registration_no';
$availableStmt = $db->prepare($availableVehiclesSql);
$availableStmt->execute($branchRole ? [$assignedBranchId] : []);
$availableVehicles = $availableStmt->fetchAll();
$studentsSql =
    "SELECT id, holder_name, admission_number, branch_id, course_name FROM students
     WHERE status = 'active' AND branch_id IS NOT NULL";
if ($branchRole) {
    $studentsSql .= ' AND branch_id = ?';
}
$studentsSql .= ' ORDER BY holder_name';
$studentsStmt = $db->prepare($studentsSql);
$studentsStmt->execute($branchRole ? [$assignedBranchId] : []);
$students = $studentsStmt->fetchAll();

$title = 'Driving School Fleet';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><div class="portal-eyebrow">Operations</div><h1 class="fw-bold mb-1">Driving School Fleet</h1><p class="text-muted mb-0">Register vehicles, track branch availability and assign cars to active driving students.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<div class="row g-3 mb-4">
    <?php if (!$branchRole && !is_role('manager') && !is_role('director')): ?><div class="col-lg-5"><div class="card p-4 h-100"><h5 class="fw-bold">Add Vehicle</h5>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_vehicle">
            <label class="form-label">Registration number</label><input class="form-control mb-3" name="registration_no" maxlength="30" required>
            <label class="form-label">Make / model</label><input class="form-control mb-3" name="make_model" maxlength="120" required>
            <?php if ($branchRole): ?><label class="form-label">Branch</label><input class="form-control mb-3" value="<?= e($branches[0]['name'] ?? '') ?>" readonly><?php else: ?><label class="form-label">Branch</label><select class="form-select mb-3" name="branch_id" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"><?= e($branch['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <button class="btn btn-primary">Add to Fleet</button>
        </form>
    </div></div><?php endif; ?>
    <div class="col-lg-7"><div class="card p-4 h-100"><h5 class="fw-bold">Assign a Vehicle</h5>
        <?php if ($branchRole): ?><p class="text-muted mb-0">Branch accounts have read-only access to the fleet.</p><?php elseif (is_role('manager') || is_role('director')): ?><p class="text-muted mb-0">This account has read-only access to the fleet.</p><?php elseif (!$availableVehicles || !$students): ?><p class="text-muted mb-0">Add an available vehicle and enroll an active student at the same branch to create an assignment.</p><?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign_vehicle">
            <label class="form-label">Available vehicle</label><select class="form-select mb-3" name="vehicle_id" required><option value="">Select vehicle</option><?php foreach ($availableVehicles as $vehicle): ?><option value="<?= (int)$vehicle['id'] ?>" data-branch="<?= (int)$vehicle['branch_id'] ?>"><?= e($vehicle['registration_no'] . ' — ' . $vehicle['branch_name']) ?></option><?php endforeach; ?></select>
            <label class="form-label">Active student</label><select class="form-select mb-3" name="student_id" required><option value="">Select student from the vehicle branch</option><?php foreach ($students as $student): ?><option value="<?= (int)$student['id'] ?>" data-branch="<?= (int)$student['branch_id'] ?>"><?= e(($student['admission_number'] ?: 'No admission') . ' — ' . $student['holder_name'] . ' (' . $student['course_name'] . ')') ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary">Assign Vehicle</button>
        </form>
        <?php endif; ?>
    </div></div>
</div>
<div class="card p-3"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Registration</th><th>Make / model</th><th>Branch</th><th>Status</th><th>Assigned student</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($vehicles as $vehicle): ?>
        <tr><td class="fw-semibold"><?= e($vehicle['registration_no']) ?></td><td><?= e($vehicle['make_model']) ?></td><td><?= e($vehicle['branch_name']) ?></td>
            <td><span class="badge text-bg-<?= $vehicle['assignment_id'] ? 'primary' : ($vehicle['status'] === 'available' ? 'success' : 'secondary') ?>"><?= e($vehicle['assignment_id'] ? 'ASSIGNED' : strtoupper($vehicle['status'])) ?></span></td>
            <td><?= e($vehicle['student_name'] ?? '—') ?><?php if ($vehicle['admission_number']): ?><div class="small text-muted"><?= e($vehicle['admission_number']) ?></div><?php endif; ?></td>
            <td class="d-flex gap-2">
                <?php if ($branchRole || is_role('manager') || is_role('director')): ?><span class="text-muted">Read only</span><?php else: ?>
                <?php if ($vehicle['assignment_id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="return_vehicle"><input type="hidden" name="assignment_id" value="<?= (int)$vehicle['assignment_id'] ?>"><button class="btn btn-sm btn-outline-primary">Return</button></form><?php else: ?>
                <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="update_vehicle_status"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><select name="status" class="form-select form-select-sm"><option value="available" <?= $vehicle['status'] === 'available' ? 'selected' : '' ?>>Available</option><option value="maintenance" <?= $vehicle['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option><option value="retired" <?= $vehicle['status'] === 'retired' ? 'selected' : '' ?>>Retired</option></select><button class="btn btn-sm btn-outline-secondary">Update</button></form><?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?><?php if (!$vehicles): ?><tr><td colspan="6" class="text-center text-muted py-4">No fleet vehicles have been registered.</td></tr><?php endif; ?></tbody>
</table></div></div>
<script>
const vehicleSelect = document.querySelector('[name="vehicle_id"]');
const studentSelect = document.querySelector('[name="student_id"]');
if (vehicleSelect && studentSelect) {
    const filterStudents = () => {
        const branch = vehicleSelect.selectedOptions[0]?.dataset.branch || '';
        for (const option of studentSelect.options) {
            option.hidden = option.value !== '' && option.dataset.branch !== branch;
        }
        if (studentSelect.selectedOptions[0]?.hidden) studentSelect.value = '';
    };
    vehicleSelect.addEventListener('change', filterStudents);
    filterStudents();
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
