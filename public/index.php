<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$number = is_string($_GET['number'] ?? null) ? trim($_GET['number']) : '';

if ($number !== '') {
    $certificate = get_certificate_by_number($db, $number);
    $status = $certificate ? certificate_status($certificate) : 'not_found';

    if ($certificate) {
        log_verification($db, $certificate, $status);
        redirect(app_url($config, 'c/' . $certificate['verification_token']));
    }

    log_verification($db, null, 'not_found');
    $error = 'No certificate was found with that certificate number.';
}

$title = 'Verify a Beckyn Certificate';
require __DIR__ . '/../includes/header.php';
?>
<div class="verify-shell">
    <div class="text-center mb-4">
        <div class="portal-eyebrow">Beckyn Driving &amp; Mechanical School</div>
        <h1 class="fw-bold">Verify a Beckyn Certificate</h1>
        <p class="text-muted">Confirm learner achievements through the official BeckynDS registry.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card p-4">
        <form method="get">
            <label class="form-label fw-semibold">Certificate Number</label>
            <input class="form-control form-control-lg mb-3" name="number" placeholder="e.g. BKDS-2026-000001" required>
            <button class="btn btn-primary btn-lg w-100">Verify Certificate</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
