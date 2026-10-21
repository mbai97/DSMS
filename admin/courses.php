<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin');

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
    $duration = filter_var($_POST['duration_days'] ?? null, FILTER_VALIDATE_INT);
    $fee = is_string($_POST['default_fee'] ?? null) ? trim($_POST['default_fee']) : '';

    if ($id === false || $id === null) {
        $errors[] = 'Select a valid course.';
    }
    if ($duration === false || $duration < 1 || $duration > 3650) {
        $errors[] = 'Course duration must be between 1 and 3,650 days.';
    }
    if ($fee !== '' && (!is_numeric($fee) || (float)$fee < 0 || (float)$fee > 99999999.99)) {
        $errors[] = 'Default fee must be a non-negative amount.';
    }

    if (!$errors) {
        $stmt = $db->prepare('UPDATE courses SET duration_days = ?, default_fee = ? WHERE id = ?');
        $stmt->execute([$duration, $fee === '' ? null : number_format((float)$fee, 2, '.', ''), $id]);
        if ($stmt->rowCount() === 0) {
            $check = $db->prepare('SELECT id FROM courses WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                $errors[] = 'The selected course could not be found.';
            }
        }
        if (!$errors) {
            $success = 'Course settings saved.';
        }
    }
}

$courses = $db->query('SELECT * FROM courses ORDER BY name')->fetchAll();
$title = 'Course Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4">
    <div class="portal-eyebrow">Programs</div>
    <h1 class="fw-bold mb-1">Course Settings</h1>
    <p class="text-muted mb-0">Set the duration and default fee for each program before enrolling students.</p>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<div class="row g-3">
    <?php foreach ($courses as $course): ?>
        <div class="col-lg-6">
            <div class="card p-4 h-100">
                <h5 class="fw-bold"><?= e($course['name']) ?></h5>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="course_id" value="<?= (int)$course['id'] ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Duration (days)</label>
                            <input class="form-control" type="number" name="duration_days" min="1" max="3650" value="<?= e($course['duration_days']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Default fee (KES)</label>
                            <input class="form-control" type="number" name="default_fee" min="0" step="0.01" value="<?= e($course['default_fee']) ?>" placeholder="Set when known">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3">Save Course</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
