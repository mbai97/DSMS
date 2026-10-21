<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'branch');

$page_title = 'Student Enrollment';
$branchRole = is_role('branch');
$assignedBranchId = current_admin_branch_id();
if ($branchRole && $assignedBranchId === null) {
    http_response_code(403);
    exit('This Branch account has no assigned branch. Ask an administrator to assign one.');
}

$errors = [];
$success = '';
$branchesStmt = $branchRole
    ? $db->prepare('SELECT id, name FROM branches WHERE id = ? ORDER BY name')
    : $db->query('SELECT id, name FROM branches ORDER BY name');
if ($branchRole) {
    $branchesStmt->execute([$assignedBranchId]);
}
$branches = $branchesStmt->fetchAll();
$courses = $db->query('SELECT id, name, code, duration_days, default_fee FROM courses WHERE active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $holder = trim((string)($_POST['holder_name'] ?? ''));
        $courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
        $branchId = $branchRole ? $assignedBranchId : filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $feeInput = trim((string)($_POST['total_fee'] ?? ''));
        $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
        $idNumber = trim((string)($_POST['id_number'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $nextOfKinName = trim((string)($_POST['next_of_kin_name'] ?? ''));
        $nextOfKinPhone = trim((string)($_POST['next_of_kin_phone'] ?? ''));
        $nextOfKinRelationship = trim((string)($_POST['next_of_kin_relationship'] ?? ''));

        if ($holder === '') {
            $errors[] = 'Student name is required.';
        }
        if (mb_strlen($holder) > 190) {
            $errors[] = 'Student name must be 190 characters or fewer.';
        }
        if ($courseId === false || $courseId === null) {
            $errors[] = 'Select a program.';
        }
        if ($branchId === false || $branchId === null) {
            $errors[] = 'Select a branch.';
        }
        if (mb_strlen($phoneNumber) > 30) {
            $errors[] = 'Phone number must be 30 characters or fewer.';
        }
        if (mb_strlen($idNumber) > 50) {
            $errors[] = 'ID number must be 50 characters or fewer.';
        }
        if (mb_strlen($email) > 190 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address of 190 characters or fewer.';
        }
        if (mb_strlen($nextOfKinName) > 190) {
            $errors[] = 'Next-of-kin name must be 190 characters or fewer.';
        }
        if (mb_strlen($nextOfKinPhone) > 30) {
            $errors[] = 'Next-of-kin phone number must be 30 characters or fewer.';
        }
        if (mb_strlen($nextOfKinRelationship) > 100) {
            $errors[] = 'Next-of-kin relationship must be 100 characters or fewer.';
        }

        if (!$errors) {
            $stmt = $db->prepare('SELECT * FROM courses WHERE id = ? AND active = 1');
            $stmt->execute([$courseId]);
            $course = $stmt->fetch();
            $stmt = $db->prepare('SELECT id, code FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            $branch = $stmt->fetch();
            if (!$course || !$branch) {
                $errors[] = 'Select an active course and valid branch.';
            } elseif (!$course['duration_days']) {
                $errors[] = 'Set this program duration in Course Settings before enrolling students.';
            } elseif ($feeInput !== '' && (!is_numeric($feeInput) || (float)$feeInput < 0 || (float)$feeInput > 99999999.99)) {
                $errors[] = 'Enter a valid non-negative course fee.';
            } elseif ($feeInput === '' && $course['default_fee'] === null) {
                $errors[] = 'Enter the student fee or set a default fee in Course Settings.';
            } else {
                $db->beginTransaction();
                try {
                    $admissionNumber = next_admission_number($db, (int)$branch['id'], $branch['code'], (int)$course['id'], $course['code']);
                    $stmt = $db->prepare(
                        'INSERT INTO students (learner_id, admission_number, holder_name, course_id, course_name, branch_id, course_duration_days, total_fee, phone_number, id_number, email, next_of_kin_name, next_of_kin_phone, next_of_kin_relationship, enrollment_date, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)'
                    );
                    $stmt->execute([
                        $admissionNumber,
                        $admissionNumber,
                        $holder,
                        $course['id'],
                        $course['name'],
                        $branch['id'],
                        $course['duration_days'],
                        number_format((float)($feeInput !== '' ? $feeInput : $course['default_fee']), 2, '.', ''),
                        $phoneNumber !== '' ? $phoneNumber : null,
                        $idNumber !== '' ? $idNumber : null,
                        $email !== '' ? $email : null,
                        $nextOfKinName !== '' ? $nextOfKinName : null,
                        $nextOfKinPhone !== '' ? $nextOfKinPhone : null,
                        $nextOfKinRelationship !== '' ? $nextOfKinRelationship : null,
                        'active',
                    ]);
                    $db->commit();
                    $success = 'Student enrolled. Admission number: ' . $admissionNumber;
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Student enrollment failed: ' . $exception->getMessage());
                    $errors[] = 'Unable to enroll student. Please check the details and try again.';
                }
            }
        }
    }

    if ($action === 'complete_student') {
        $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            $errors[] = 'Select a valid student.';
        } else {
            $sql = 'SELECT course_duration_days, enrollment_date, status FROM students WHERE id = ?';
            if ($branchRole) {
                $sql .= ' AND branch_id = ?';
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($branchRole ? [$id, $assignedBranchId] : [$id]);
            $student = $stmt->fetch();
            if (!$student || !$student['course_duration_days']) {
                $errors[] = 'This student does not have a configured course duration.';
            } elseif ($student['status'] !== 'active') {
                $errors[] = 'Only active students can be marked complete.';
            } elseif (date('Y-m-d') < date('Y-m-d', strtotime($student['enrollment_date'] . ' +' . (int)$student['course_duration_days'] . ' days'))) {
                $errors[] = 'The course duration has not elapsed yet.';
            } else {
                $sql = "UPDATE students SET status = 'completed', completed_on = CURDATE() WHERE id = ?";
                if ($branchRole) {
                    $sql .= ' AND branch_id = ?';
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($branchRole ? [$id, $assignedBranchId] : [$id]);
                $success = 'Student marked complete. Their certificate can now be issued.';
            }
        }
    }

    if ($action === 'bulk_upload') {
        $file = $_FILES['csv_file'] ?? null;
        if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] > 5242880) {
            $errors[] = 'Please upload a valid CSV file.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $errors[] = 'Unable to read uploaded CSV.';
            } else {
                $count = 0;
                $header = fgetcsv($handle);
                if ($header === false) {
                    $errors[] = 'CSV file is empty.';
                } else {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
                    $columns = array_flip(array_map(static fn($column): string => strtolower(trim((string)$column)), $header));
                    $requiredColumns = ['student_name', 'course_name', 'branch_name'];
                    if (array_diff($requiredColumns, array_keys($columns))) {
                        $errors[] = 'CSV header must include student_name, course_name, and branch_name. total_fee is optional.';
                    } else {
                        $db->beginTransaction();
                        try {
                            $rowNumber = 1;
                            $insert = $db->prepare(
                                'INSERT INTO students (learner_id, admission_number, holder_name, course_id, course_name, branch_id, course_duration_days, total_fee, phone_number, id_number, email, next_of_kin_name, next_of_kin_phone, next_of_kin_relationship, enrollment_date, status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)'
                            );
                            while (($row = fgetcsv($handle)) !== false) {
                                $rowNumber++;
                                if (count($row) === 1 && trim((string)$row[0]) === '') {
                                    continue;
                                }
                                $studentName = trim((string)($row[$columns['student_name']] ?? ''));
                                $courseName = trim((string)($row[$columns['course_name']] ?? ''));
                                $branchName = trim((string)($row[$columns['branch_name']] ?? ''));
                                $feeInput = isset($columns['total_fee']) ? trim((string)($row[$columns['total_fee']] ?? '')) : '';
                                $phoneNumber = isset($columns['phone_number']) ? trim((string)($row[$columns['phone_number']] ?? '')) : '';
                                $idNumber = isset($columns['id_number']) ? trim((string)($row[$columns['id_number']] ?? '')) : '';
                                $email = isset($columns['email']) ? strtolower(trim((string)($row[$columns['email']] ?? ''))) : '';
                                $nextOfKinName = isset($columns['next_of_kin_name']) ? trim((string)($row[$columns['next_of_kin_name']] ?? '')) : '';
                                $nextOfKinPhone = isset($columns['next_of_kin_phone']) ? trim((string)($row[$columns['next_of_kin_phone']] ?? '')) : '';
                                $nextOfKinRelationship = isset($columns['next_of_kin_relationship']) ? trim((string)($row[$columns['next_of_kin_relationship']] ?? '')) : '';
                                if ($studentName === '' || mb_strlen($studentName) > 190 || $courseName === '' || $branchName === '') {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has a missing or invalid student, course, or branch.');
                                }
                                $stmt = $db->prepare('SELECT * FROM courses WHERE name = ? AND active = 1 LIMIT 1');
                                $stmt->execute([$courseName]);
                                $course = $stmt->fetch();
                                if ($branchRole) {
                                    $stmt = $db->prepare('SELECT id, code, name FROM branches WHERE id = ? LIMIT 1');
                                    $stmt->execute([$assignedBranchId]);
                                } else {
                                    $stmt = $db->prepare('SELECT id, code, name FROM branches WHERE name = ? LIMIT 1');
                                    $stmt->execute([$branchName]);
                                }
                                $branch = $stmt->fetch();
                                if (!$course || !$branch || !$course['duration_days'] || ($branchRole && strcasecmp($branchName, $branch['name']) !== 0)) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' uses an unknown branch/course or a course without a configured duration.');
                                }
                                if ($feeInput !== '' && (!is_numeric($feeInput) || (float)$feeInput < 0 || (float)$feeInput > 99999999.99)) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has an invalid total_fee.');
                                }
                                if ($feeInput === '' && $course['default_fee'] === null) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' needs total_fee because the course has no default fee.');
                                }
                                if (mb_strlen($phoneNumber) > 30) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has an invalid phone number.');
                                }
                                if (mb_strlen($idNumber) > 50) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has an invalid ID number.');
                                }
                                if (mb_strlen($email) > 190 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has an invalid email address.');
                                }
                                if (mb_strlen($nextOfKinName) > 190 || mb_strlen($nextOfKinPhone) > 30 || mb_strlen($nextOfKinRelationship) > 100) {
                                    throw new RuntimeException('CSV row ' . $rowNumber . ' has invalid next-of-kin details.');
                                }
                                $admissionNumber = next_admission_number($db, (int)$branch['id'], $branch['code'], (int)$course['id'], $course['code']);
                                $insert->execute([
                                    $admissionNumber,
                                    $admissionNumber,
                                    $studentName,
                                    $course['id'],
                                    $course['name'],
                                    $branch['id'],
                                    $course['duration_days'],
                                    number_format((float)($feeInput !== '' ? $feeInput : $course['default_fee']), 2, '.', ''),
                                    $phoneNumber !== '' ? $phoneNumber : null,
                                    $idNumber !== '' ? $idNumber : null,
                                    $email !== '' ? $email : null,
                                    $nextOfKinName !== '' ? $nextOfKinName : null,
                                    $nextOfKinPhone !== '' ? $nextOfKinPhone : null,
                                    $nextOfKinRelationship !== '' ? $nextOfKinRelationship : null,
                                    'active',
                                ]);
                                $count++;
                            }
                            if ($count === 0) {
                                throw new RuntimeException('CSV contains no student rows.');
                            }
                            $db->commit();
                            $success = $count . ' students enrolled from CSV.';
                        } catch (Throwable $exception) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to import the student CSV.';
                            if (!($exception instanceof RuntimeException)) {
                                error_log('Student CSV import failed: ' . $exception->getMessage());
                            }
                        }
                    }
                }
                fclose($handle);
            }
        }
    }

    if ($action === 'delete_student') {
        $id = (int)($_POST['student_id'] ?? 0);
        if ($id > 0) {
            $sql = 'DELETE FROM students WHERE id = ?';
            if ($branchRole) {
                $sql .= ' AND branch_id = ?';
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($branchRole ? [$id, $assignedBranchId] : [$id]);
            if ($stmt->rowCount() === 1) {
                $success = 'Student record deleted.';
            } else {
                $errors[] = 'Student record not found in your assigned branch.';
            }
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalSql = 'SELECT COUNT(*) FROM students';
if ($branchRole) {
    $totalSql .= ' WHERE branch_id = ?';
    $stmt = $db->prepare($totalSql);
    $stmt->execute([$assignedBranchId]);
    $total = (int)$stmt->fetchColumn();
} else {
    $total = (int)$db->query($totalSql)->fetchColumn();
}
$stmt = $db->prepare(
    'SELECT s.*, b.name AS branch_name, c.name AS program_name,
            DATE_ADD(s.enrollment_date, INTERVAL s.course_duration_days DAY) AS expected_completion,
            COALESCE(p.paid, 0) AS amount_paid
     FROM students s
     LEFT JOIN branches b ON b.id = s.branch_id
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN (SELECT student_id, SUM(amount) AS paid FROM student_payments GROUP BY student_id) p ON p.student_id = s.id
     ' . ($branchRole ? 'WHERE s.branch_id = ?' : '') . '
     ORDER BY s.enrollment_date DESC LIMIT ? OFFSET ?'
);
$bindIndex = 1;
if ($branchRole) {
    $stmt->bindValue($bindIndex++, $assignedBranchId, PDO::PARAM_INT);
}
$stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();

$title = 'Student Enrollment';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Registry</div>
        <h1 class="fw-bold mb-1">Student Enrollment</h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<?php if (!is_role('manager')): ?>
<div class="card p-4 mb-4">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#single">Single Student</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#bulk">Bulk CSV</a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="single">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_student">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Student Name</label>
                        <input type="text" name="holder_name" class="form-control" required>
                    </div>
                    <?php if ($branchRole): ?>
                    <div class="col-md-6">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-control" value="<?= e($branches[0]['name'] ?? '') ?>" readonly>
                    </div>
                    <?php else: ?>
                    <div class="col-md-6">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select branch</option>
                            <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"><?= e($branch['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <label class="form-label">Program</label>
                        <select name="course_id" id="course-id" class="form-select" required>
                            <option value="">Select program</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= (int)$course['id'] ?>" data-duration="<?= e($course['duration_days'] ?? '') ?>" data-fee="<?= e($course['default_fee'] ?? '') ?>"><?= e($course['name']) ?><?= $course['duration_days'] ? ' — ' . (int)$course['duration_days'] . ' days' : ' — configure duration first' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="course-info">Program duration is configured under Course Settings.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Total Fee (KES) <span class="text-muted fw-normal">optional if a course default is set</span></label>
                        <input type="number" name="total_fee" id="student-fee" min="0" step="0.01" class="form-control" placeholder="Use course default">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Student Phone <span class="text-muted fw-normal">(for WhatsApp payment requests)</span></label>
                        <input type="tel" name="phone_number" maxlength="30" class="form-control" placeholder="e.g. 0712345678">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID / Passport Number</label>
                        <input type="text" name="id_number" maxlength="50" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" maxlength="190" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Next-of-Kin Name <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="next_of_kin_name" maxlength="190" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Next-of-Kin Phone <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="tel" name="next_of_kin_phone" maxlength="30" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Relationship to Student <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="next_of_kin_relationship" maxlength="100" class="form-control" placeholder="e.g. Parent, spouse">
                    </div>
                </div>
                <button class="btn btn-primary mt-3">Enroll Student</button>
            </form>
        </div>

        <div class="tab-pane fade show" id="bulk">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_upload">
                <div class="mb-3">
                    <label class="form-label">CSV file</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>
                <div class="form-text mb-3">Required CSV columns: student_name, course_name, branch_name. Optional columns: total_fee, phone_number, id_number, email, next_of_kin_name, next_of_kin_phone, next_of_kin_relationship. Course names and branches must match the configured options; all rows receive generated admission numbers.</div>
                <button class="btn btn-primary">Upload Students</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Enrolled Students</h5>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Contact / ID</th>
                    <th>Admission</th>
                    <th>Program / Branch</th>
                    <th>Enrollment Date</th>
                    <th>Fee balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= e($student['holder_name']) ?></td>
                            <td>
                                <?php if (!empty($student['phone_number'])): ?><div><?= e($student['phone_number']) ?></div><?php endif; ?>
                                <?php if (!empty($student['id_number'])): ?><div class="small text-muted">ID: <?= e($student['id_number']) ?></div><?php endif; ?>
                                <?php if (!empty($student['email'])): ?><div class="small text-muted"><?= e($student['email']) ?></div><?php endif; ?>
                                <?php if (!empty($student['next_of_kin_name']) || !empty($student['next_of_kin_phone']) || !empty($student['next_of_kin_relationship'])): ?>
                                    <div class="small text-muted">Next of kin: <?= e($student['next_of_kin_name'] ?: '—') ?><?= !empty($student['next_of_kin_relationship']) ? ' (' . e($student['next_of_kin_relationship']) . ')' : '' ?><?= !empty($student['next_of_kin_phone']) ? ' · ' . e($student['next_of_kin_phone']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($student['admission_number'] ?? $student['learner_id'] ?? '—') ?></td>
                            <td><?= e($student['program_name'] ?? $student['course_name']) ?><div class="small text-muted"><?= e($student['branch_name'] ?? 'Branch not assigned') ?></div></td>
                            <td><?= e($student['enrollment_date']) ?></td>
                            <td>KES <?= number_format(max(0, (float)$student['total_fee'] - (float)$student['amount_paid']), 2) ?></td>
                            <td>
                                <span class="badge text-bg-<?= $student['status'] === 'active' ? 'success' : ($student['status'] === 'completed' ? 'primary' : 'secondary') ?>">
                                    <?= e(strtoupper($student['status'])) ?>
                                </span>
                            </td>
                            <td class="d-flex gap-1 flex-wrap">
                                <?php if (!is_role('manager') && $student['status'] === 'active' && $student['course_duration_days'] && $student['expected_completion'] <= date('Y-m-d')): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="complete_student"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="btn btn-sm btn-outline-success">Mark Complete</button></form>
                                <?php elseif ($student['status'] === 'active' && $student['expected_completion']): ?>
                                    <span class="small text-muted align-self-center">Completes <?= e($student['expected_completion']) ?></span>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-primary" href="admission-letter.php?id=<?= (int)$student['id'] ?>">Admission Letter</a>
                                <?php if ($student['status'] === 'completed' && empty($student['certificate_id']) && !is_role('manager')): ?><a class="btn btn-sm btn-outline-primary" href="create-certificate.php?student_id=<?= (int)$student['id'] ?>">Award Certificate</a><?php elseif (!empty($student['certificate_id'])): ?><a class="btn btn-sm btn-outline-primary" href="view-certificate.php?id=<?= (int)$student['certificate_id'] ?>">View Certificate</a><?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary" href="fees.php">Fees</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $totalPages = (int)ceil($total / $perPage); if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= (int)$i ?>"><?= (int)$i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
const courseSelect = document.getElementById('course-id');
const feeInput = document.getElementById('student-fee');
const courseInfo = document.getElementById('course-info');
courseSelect.addEventListener('change', () => {
    const option = courseSelect.selectedOptions[0];
    feeInput.value = option.dataset.fee || '';
    courseInfo.textContent = option.dataset.duration
        ? `Course duration: ${option.dataset.duration} days. Expected completion is calculated from enrollment.`
        : 'Set this program duration in Course Settings before enrolling students.';
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
