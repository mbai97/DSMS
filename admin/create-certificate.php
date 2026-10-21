<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_certificate_issuer();
ensure_storage();

$errors = [];
$studentId = filter_var($_POST['student_id'] ?? $_GET['student_id'] ?? null, FILTER_VALIDATE_INT);
$studentId = $studentId === false || $studentId === null || $studentId < 1 ? null : $studentId;
$enrolledStudent = null;
if ($studentId !== null) {
    $sql = 'SELECT s.*, c.certificate_type FROM students s LEFT JOIN courses c ON c.id = s.course_id WHERE s.id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$studentId]);
    $enrolledStudent = $stmt->fetch() ?: null;
    if (!$enrolledStudent || $enrolledStudent['status'] !== 'completed') {
        $errors[] = 'Certificates can only be awarded to students whose course is complete.';
        $enrolledStudent = null;
    } elseif (!empty($enrolledStudent['certificate_id'])) {
        redirect('view-certificate.php?id=' . (int)$enrolledStudent['certificate_id']);
    } else {
        $_POST['holder_name'] = $enrolledStudent['holder_name'];
        $_POST['learner_id'] = $enrolledStudent['admission_number'] ?: $enrolledStudent['learner_id'];
        $_POST['course_name'] = $enrolledStudent['course_name'];
        $_POST['completion_date'] = $enrolledStudent['completed_on'];
        $_POST['training_duration'] = $enrolledStudent['course_duration_days'] . ' days';
        $_POST['director_name'] = setting($db, 'director_name');
        $_POST['certificate_type'] = $enrolledStudent['certificate_type'] ?: 'Certificate of Completion';
    }
}
$certificateTypes = [
    'Certificate of Competence',
    'Defensive Driving',
    'Computer Packages',
    'Mechanical Artisan',
];
$selectedType = is_string($_POST['certificate_type'] ?? null)
    ? trim($_POST['certificate_type'])
    : 'Certificate of Competence';
$customType = is_string($_POST['custom_certificate_type'] ?? null)
    ? trim($_POST['custom_certificate_type'])
    : '';
$holder = is_string($_POST['holder_name'] ?? null) ? trim($_POST['holder_name']) : '';
$learnerId = is_string($_POST['learner_id'] ?? null) ? trim($_POST['learner_id']) : '';
$course = is_string($_POST['course_name'] ?? null) ? trim($_POST['course_name']) : '';
$courseTopicsInput = is_string($_POST['course_topics'] ?? null) ? trim($_POST['course_topics']) : '';
$trainingDuration = is_string($_POST['training_duration'] ?? null) ? trim($_POST['training_duration']) : '';
$issueDate = is_string($_POST['issue_date'] ?? null) ? trim($_POST['issue_date']) : date('Y-m-d');
$completionDate = is_string($_POST['completion_date'] ?? null) ? trim($_POST['completion_date']) : '';
$expiryDate = is_string($_POST['expiry_date'] ?? null) ? trim($_POST['expiry_date']) : '';
$directorName = is_string($_POST['director_name'] ?? null) ? trim($_POST['director_name']) : '';
$courseTopics = array_values(array_filter(
    array_map('trim', preg_split('/\R/', $courseTopicsInput) ?: []),
    static fn(string $topic): bool => $topic !== ''
));
$isValidDate = static function (string $date): bool {
    $parts = explode('-', $date);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = $selectedType === 'custom' ? $customType : $selectedType;

    $signatureFile = setting($db, 'signature_file');
    if ($studentId !== null && (
        !$enrolledStudent
        || setting($db, 'director_name') === ''
        || $signatureFile === ''
        || !is_file(__DIR__ . '/../storage/signatures/' . basename($signatureFile))
    )) {
        $errors[] = 'Configure the Director name and signature in Branding before awarding a student certificate.';
    }

    if ($holder === '') $errors[] = 'Student/holder name is required.';
    if (mb_strlen($holder) > 190) $errors[] = 'Student/holder name must be 190 characters or fewer.';
    if (mb_strlen($learnerId) > 80) $errors[] = 'Learner ID must be 80 characters or fewer.';
    if ($selectedType !== 'custom' && !in_array($selectedType, $certificateTypes, true)) $errors[] = 'Select a valid certificate type.';
    if ($type === '') $errors[] = 'Certificate type is required.';
    if (mb_strlen($type) > 150) $errors[] = 'Certificate type must be 150 characters or fewer.';
    if ($course === '') $errors[] = 'Course/training name is required.';
    if (mb_strlen($course) > 190) $errors[] = 'Course/training name must be 190 characters or fewer.';
    if (mb_strlen($courseTopicsInput) > 5000) $errors[] = 'Course topics must be 5,000 characters or fewer.';
    if (count($courseTopics) > 20) $errors[] = 'Enter no more than 20 course topics.';
    foreach ($courseTopics as $topic) {
        if (mb_strlen($topic) > 250) {
            $errors[] = 'Each course topic must be 250 characters or fewer.';
            break;
        }
    }
    if (mb_strlen($trainingDuration) > 100) $errors[] = 'Training duration must be 100 characters or fewer.';
    if (mb_strlen($directorName) > 120) $errors[] = 'Director name must be 120 characters or fewer.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) || !$isValidDate($issueDate)) $errors[] = 'Invalid issue date.';
    if ($completionDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completionDate) || !$isValidDate($completionDate))) $errors[] = 'Invalid completion date.';
    if ($expiryDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate) || !$isValidDate($expiryDate))) $errors[] = 'Invalid expiry date.';
    if ($expiryDate !== '' && $expiryDate < $issueDate) $errors[] = 'Expiry date cannot be before issue date.';
    if ($completionDate !== '' && $completionDate > $issueDate) $errors[] = 'Completion date cannot be after the issue date.';

    if (!$errors) {
        $prefix = $config['certificate_prefix'] ?? 'BKDS';
        $lockName = 'certificate_number_' . substr(hash('sha256', $prefix), 0, 32) . '_' . date('Y');
        $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);

        if ((int)$lock->fetchColumn() !== 1) {
            $errors[] = 'Certificate numbering is busy. Please try again.';
        } else {
            try {
                $db->beginTransaction();
                try {
                    if ($studentId !== null) {
                        $stmt = $db->prepare('SELECT status, certificate_id FROM students WHERE id = ? FOR UPDATE');
                        $stmt->execute([$studentId]);
                        $lockedStudent = $stmt->fetch();
                        if (!$lockedStudent || $lockedStudent['status'] !== 'completed' || $lockedStudent['certificate_id']) {
                            throw new RuntimeException('This student is no longer eligible for a new certificate.');
                        }
                    }

                    $certificateNumber = next_certificate_number($db, $prefix);
                    $token = random_token();
                    $stmt = $db->prepare(
                        'INSERT INTO certificates
                        (certificate_number, verification_token, holder_name, learner_id, certificate_type, course_name, course_topics, training_duration, issue_date, completion_date, expiry_date, director_name, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $certificateNumber,
                        $token,
                        $holder,
                        $learnerId !== '' ? $learnerId : null,
                        $type,
                        $course,
                        $courseTopics ? implode("\n", $courseTopics) : null,
                        $trainingDuration !== '' ? $trainingDuration : null,
                        $issueDate,
                        $completionDate !== '' ? $completionDate : null,
                        $expiryDate !== '' ? $expiryDate : null,
                        $directorName !== '' ? $directorName : null,
                        $_SESSION['admin_id'],
                    ]);

                    $id = (int)$db->lastInsertId();
                    if ($studentId !== null) {
                        $stmt = $db->prepare('UPDATE students SET certificate_id = ? WHERE id = ?');
                        $stmt->execute([$id, $studentId]);
                    }
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    if ($exception instanceof RuntimeException) {
                        $errors[] = $exception->getMessage();
                    } else {
                        error_log('Certificate creation failed: ' . $exception->getMessage());
                        $errors[] = 'Unable to create the certificate. Please try again.';
                    }
                }
            } finally {
                $releaseLock = $db->prepare('SELECT RELEASE_LOCK(?)');
                $releaseLock->execute([$lockName]);
            }

            if (!$errors) {
                redirect('view-certificate.php?id=' . $id . '&generated=1');
            }
        }
    }
}

$title = 'Issue Certificate';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-4">
            <h1 class="fw-bold">Issue Certificate</h1>
            <p class="text-muted">Create a certificate and generate its unique QR verification code.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="card p-4">
            <form method="post">
                <?= csrf_field() ?>
                <?php if ($studentId !== null): ?><input type="hidden" name="student_id" value="<?= (int)$studentId ?>"><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Student / Holder Name</label>
                    <input class="form-control form-control-lg" name="holder_name" value="<?= e($holder) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Learner ID <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control" name="learner_id" value="<?= e($learnerId) ?>" maxlength="80" placeholder="e.g. IDL LTF092">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Certificate Type</label>
                    <select class="form-select" name="certificate_type" id="certificate-type" required>
                        <?php foreach ($certificateTypes as $certificateType): ?>
                            <option value="<?= e($certificateType) ?>" <?= $selectedType === $certificateType ? 'selected' : '' ?>><?= e($certificateType) ?></option>
                        <?php endforeach; ?>
                        <option value="custom" <?= $selectedType === 'custom' ? 'selected' : '' ?>>Other / Custom</option>
                    </select>
                </div>
                <div class="mb-3" id="custom-certificate-type" <?= $selectedType === 'custom' ? '' : 'hidden' ?>>
                    <label class="form-label fw-semibold" for="custom-certificate-type-input">Custom Certificate Type</label>
                    <input class="form-control" id="custom-certificate-type-input" name="custom_certificate_type" value="<?= e($customType) ?>" maxlength="150" <?= $selectedType === 'custom' ? 'required' : '' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Course / Training</label>
                    <input class="form-control" name="course_name" value="<?= e($course) ?>" placeholder="e.g. Defensive Driver Training" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Topics Covered <span class="text-muted fw-normal">(optional, one per line)</span></label>
                    <textarea class="form-control" name="course_topics" rows="5" maxlength="5000" placeholder="Driving behaviours and collision prevention&#10;Speeding and distractions&#10;Safe driving habits"><?= e($courseTopicsInput) ?></textarea>
                    <div class="form-text">Up to 20 topics; these appear as a concise list on the certificate.</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Issue Date</label>
                        <input type="date" class="form-control" name="issue_date" value="<?= e($issueDate) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Completion Date <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="date" class="form-control" name="completion_date" value="<?= e($completionDate) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Training Duration <span class="text-muted fw-normal">(optional)</span></label>
                        <input class="form-control" name="training_duration" value="<?= e($trainingDuration) ?>" maxlength="100" placeholder="e.g. 5 days">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <input type="date" class="form-control" name="expiry_date" value="<?= e($expiryDate) ?>">
                        <div class="form-text">Leave blank for no expiry.</div>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Director <span class="text-muted fw-normal">(optional)</span></label>
                        <input class="form-control" name="director_name" value="<?= e($directorName) ?>" maxlength="120">
                    </div>
                </div>
                <button class="btn btn-primary btn-lg mt-4 w-100">Create Certificate</button>
            </form>
        </div>
    </div>
</div>
<script>
const certificateType = document.getElementById('certificate-type');
const customTypeContainer = document.getElementById('custom-certificate-type');
const customTypeInput = document.getElementById('custom-certificate-type-input');

certificateType.addEventListener('change', () => {
    const isCustom = certificateType.value === 'custom';
    customTypeContainer.hidden = !isCustom;
    customTypeInput.disabled = !isCustom;
    customTypeInput.required = isCustom;
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
