<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('A valid payment receipt ID is required.');
}

$stmt = $db->prepare(
    'SELECT p.*, s.holder_name, s.admission_number, s.course_name, s.total_fee, s.branch_id,
            b.name AS branch_name, a.name AS received_by,
            (SELECT COALESCE(SUM(p2.amount), 0) FROM student_payments p2 WHERE p2.student_id = s.id) AS total_paid
     FROM student_payments p
     JOIN students s ON s.id = p.student_id
     LEFT JOIN branches b ON b.id = s.branch_id
     LEFT JOIN admins a ON a.id = p.received_by
     WHERE p.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$payment = $stmt->fetch();
if ($payment) {
    require_branch_record($payment['branch_id'] === null ? null : (int)$payment['branch_id']);
}
if (!$payment) {
    http_response_code(404);
    exit('Payment receipt not found.');
}

$title = 'Payment Receipt ' . $payment['id'];
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-end gap-2 mb-3 d-print-none">
    <a class="btn btn-outline-secondary" href="fees.php">Back to fees</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Print receipt</button>
</div>
<article class="card p-4 mx-auto" style="max-width:800px">
    <header class="text-center border-bottom pb-3 mb-4">
        <div class="portal-eyebrow"><?= e($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School') ?></div>
        <h1 class="fw-bold">OFFICIAL PAYMENT RECEIPT</h1>
        <div>Receipt No. BKDS-R-<?= str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT) ?></div>
    </header>
    <div class="row g-3">
        <div class="col-sm-6"><div class="text-muted small">Received from</div><strong><?= e($payment['holder_name']) ?></strong></div>
        <div class="col-sm-6"><div class="text-muted small">Admission number</div><strong><?= e($payment['admission_number'] ?? '—') ?></strong></div>
        <div class="col-sm-6"><div class="text-muted small">Program</div><?= e($payment['course_name']) ?></div>
        <div class="col-sm-6"><div class="text-muted small">Branch</div><?= e($payment['branch_name'] ?? '—') ?></div>
        <div class="col-sm-6"><div class="text-muted small">Payment date</div><?= e(format_date(substr((string)$payment['paid_at'], 0, 10))) ?> <?= e(substr((string)$payment['paid_at'], 11, 5)) ?></div>
        <div class="col-sm-6"><div class="text-muted small">Payment method</div><?= e(strtoupper(str_replace('_', ' ', $payment['payment_method']))) ?></div>
        <div class="col-sm-6"><div class="text-muted small">Transaction / receipt reference</div><?= e($payment['reference'] ?: '—') ?></div>
        <div class="col-sm-6"><div class="text-muted small">Received by</div><?= e($payment['received_by'] ?? '—') ?></div>
    </div>
    <?php if (!empty($payment['notes'])): ?><p class="mt-3 mb-0"><strong>Notes:</strong> <?= e($payment['notes']) ?></p><?php endif; ?>
    <div class="table-responsive mt-4"><table class="table table-bordered">
        <tbody>
            <tr><th>Course fee</th><td class="text-end">KES <?= number_format((float)$payment['total_fee'], 2) ?></td></tr>
            <tr><th>Total paid to date</th><td class="text-end">KES <?= number_format((float)$payment['total_paid'], 2) ?></td></tr>
            <tr><th>Outstanding balance</th><td class="text-end fw-bold">KES <?= number_format(max(0, (float)$payment['total_fee'] - (float)$payment['total_paid']), 2) ?></td></tr>
            <tr class="table-light"><th>This payment</th><td class="text-end fw-bold fs-5">KES <?= number_format((float)$payment['amount'], 2) ?></td></tr>
        </tbody>
    </table></div>
    <div class="row mt-5">
        <div class="col-6 text-center"><div class="border-top pt-2">Student / payer</div></div>
        <div class="col-6 text-center"><div class="border-top pt-2">Authorized school representative</div></div>
    </div>
    <p class="small text-muted text-center mt-4 mb-0">This receipt confirms the payment recorded in the BeckynDS student fee ledger.</p>
</article>
<?php require __DIR__ . '/../includes/footer.php'; ?>
