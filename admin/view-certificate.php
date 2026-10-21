<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch');
ensure_storage();

$id = (int)($_GET['id'] ?? 0);
$sql = 'SELECT c.* FROM certificates c';
if (is_role('branch')) {
    $sql .= ' JOIN students s ON s.certificate_id = c.id WHERE c.id = ? AND s.branch_id = ? LIMIT 1';
} else {
    $sql .= ' WHERE c.id = ? LIMIT 1';
}
$stmt = $db->prepare($sql);
if (is_role('branch')) {
    $stmt->execute([$id, current_admin_branch_id()]);
} else {
    $stmt->execute([$id]);
}
$certificate = $stmt->fetch();

if (!$certificate) {
    http_response_code(404);
    exit('Certificate not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    if (!is_role('admin')) {
        http_response_code(403);
        exit('Only administrators can revoke certificates.');
    }
    verify_csrf();

    $reason = is_string($_POST['reason'] ?? null)
        ? trim($_POST['reason'])
        : 'Revoked by issuing organization.';
    if ($reason === '') {
        $reason = 'Revoked by issuing organization.';
    }
    if (mb_strlen($reason) > 255) {
        http_response_code(422);
        exit('Revocation reason must be 255 characters or fewer.');
    }

    $sql = "UPDATE certificates SET status='revoked', revoked_at=NOW(), revoked_reason=? WHERE id=?";
    if (is_role('branch')) {
        $sql .= ' AND EXISTS (SELECT 1 FROM students WHERE students.certificate_id = certificates.id AND students.branch_id = ?)';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute(is_role('branch') ? [$reason, $id, current_admin_branch_id()] : [$reason, $id]);
    if ($stmt->rowCount() !== 1) {
        http_response_code(404);
        exit('Certificate not found.');
    }

    redirect('view-certificate.php?id=' . $id);
}

$status = certificate_status($certificate);
$verifyUrl = app_url($config, 'c/' . $certificate['verification_token']);

$title = 'Certificate ' . $certificate['certificate_number'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="fw-bold"><?= e($certificate['certificate_number']) ?></h1>
        <p class="text-muted mb-0"><?= e($certificate['holder_name']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="../generate.php?id=<?= (int)$certificate['id'] ?>" target="_blank">Generate / Download PDF</a>
        <a class="btn btn-outline-secondary" href="<?= e($verifyUrl) ?>" target="_blank">Open Verification</a>
    </div>
</div>

<div class="card p-4 mb-4">
    <div class="row g-3">
        <div class="col-md-6"><div class="text-muted small">Holder</div><strong><?= e($certificate['holder_name']) ?></strong></div>
        <div class="col-md-6"><div class="text-muted small">Status</div><?= status_badge($status) ?></div>
        <div class="col-md-6"><div class="text-muted small">Type</div><?= e($certificate['certificate_type']) ?></div>
        <div class="col-md-6"><div class="text-muted small">Course</div><?= e($certificate['course_name']) ?></div>
        <?php if (!empty($certificate['learner_id'])): ?>
            <div class="col-md-6"><div class="text-muted small">Learner ID</div><?= e($certificate['learner_id']) ?></div>
        <?php endif; ?>
        <?php if (!empty($certificate['course_topics'])): ?>
            <div class="col-12"><div class="text-muted small">Topics Covered</div><?= nl2br(e($certificate['course_topics'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($certificate['training_duration'])): ?>
            <div class="col-md-6"><div class="text-muted small">Training Duration</div><?= e($certificate['training_duration']) ?></div>
        <?php endif; ?>
        <div class="col-md-6"><div class="text-muted small">Issue Date</div><?= e(format_date($certificate['issue_date'])) ?></div>
        <?php if (!empty($certificate['completion_date'])): ?>
            <div class="col-md-6"><div class="text-muted small">Completion Date</div><?= e(format_date($certificate['completion_date'])) ?></div>
        <?php endif; ?>
        <div class="col-md-6"><div class="text-muted small">Expiry</div><?= e(format_date($certificate['expiry_date'])) ?></div>
        <?php if (!empty($certificate['director_name'])): ?>
            <div class="col-md-6"><div class="text-muted small">Director</div><?= e($certificate['director_name']) ?></div>
        <?php endif; ?>
    </div>
    <hr>
    <div class="small text-muted">Verification URL</div>
    <code class="d-block text-break"><?= e($verifyUrl) ?></code>
</div>

<?php if ($status !== 'revoked' && is_role('admin')): ?>
<div class="card p-4 border border-danger">
    <h5 class="text-danger fw-bold">Revoke Certificate</h5>
    <p class="text-muted">Revocation immediately changes the public verification status.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revoke">
        <div class="input-group">
            <input class="form-control" name="reason" value="Revoked by issuing organization." maxlength="255">
            <button class="btn btn-danger" onclick="return confirm('Revoke this certificate?')">Revoke</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
