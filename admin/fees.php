<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$errors = [];
$success = '';
$branchId = filter_var($_GET['branch'] ?? null, FILTER_VALIDATE_INT);
$branchFilter = $branchId && $branchId > 0 ? (int)$branchId : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);

    if ($studentId === false || $studentId === null || $studentId < 1) {
        $errors[] = 'Select a valid student.';
    } elseif ($action === 'update_fee') {
        $totalFee = is_string($_POST['total_fee'] ?? null) ? trim($_POST['total_fee']) : '';
        if (!is_numeric($totalFee) || (float)$totalFee < 0 || (float)$totalFee > 99999999.99) {
            $errors[] = 'Enter a valid non-negative fee amount.';
        }
        if (!$errors) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('SELECT total_fee FROM students WHERE id = ? FOR UPDATE');
                $stmt->execute([$studentId]);
                if ($stmt->fetchColumn() === false) {
                    throw new RuntimeException('The selected student could not be found.');
                }
                $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM student_payments WHERE student_id = ?');
                $stmt->execute([$studentId]);
                if ((float)$totalFee < (float)$stmt->fetchColumn()) {
                    throw new RuntimeException('Total fee cannot be lower than payments already received.');
                }
                $stmt = $db->prepare('UPDATE students SET total_fee = ? WHERE id = ?');
                $stmt->execute([number_format((float)$totalFee, 2, '.', ''), $studentId]);
                $db->commit();
                $success = 'Student fee updated.';
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to update the student fee.';
                if (!($exception instanceof RuntimeException)) {
                    error_log('Student fee update failed: ' . $exception->getMessage());
                }
            }
        }
    } elseif ($action === 'record_payment') {
        $amount = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
        $method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
        $reference = is_string($_POST['reference'] ?? null) ? trim($_POST['reference']) : '';
        $notes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';

        if (!is_numeric($amount) || (float)$amount <= 0 || (float)$amount > 99999999.99) {
            $errors[] = 'Payment must be a positive amount.';
        }
        if (!in_array($method, ['cash', 'mpesa', 'airtel_money', 'bank', 'other'], true)) {
            $errors[] = 'Select a valid payment method.';
        }
        if (mb_strlen($reference) > 100 || mb_strlen($notes) > 255) {
            $errors[] = 'Reference or notes are too long.';
        }

        if (!$errors) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('SELECT total_fee FROM students WHERE id = ? FOR UPDATE');
                $stmt->execute([$studentId]);
                $totalFee = $stmt->fetchColumn();
                if ($totalFee === false) {
                    throw new RuntimeException('The selected student could not be found.');
                }
                $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM student_payments WHERE student_id = ?');
                $stmt->execute([$studentId]);
                $paid = (float)$stmt->fetchColumn();
                if ((float)$amount > max(0, (float)$totalFee - $paid)) {
                    throw new RuntimeException('Payment exceeds the remaining fee balance.');
                }
                $stmt = $db->prepare(
                    'INSERT INTO student_payments (student_id, amount, payment_method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $studentId,
                    number_format((float)$amount, 2, '.', ''),
                    $method,
                    $reference !== '' ? $reference : null,
                    $notes !== '' ? $notes : null,
                    (int)$_SESSION['admin_id'],
                ]);
                $db->commit();
                $success = 'Payment recorded successfully.';
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to record the payment.';
                if (!($exception instanceof RuntimeException)) {
                    error_log('Student payment recording failed: ' . $exception->getMessage());
                }
            }
        }
    } else {
        $errors[] = 'Invalid fee action.';
    }
}

$branches = $db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll();
$sql = 'SELECT s.id, s.admission_number, s.holder_name, s.course_name, s.total_fee, b.name AS branch_name,
               COALESCE(p.paid, 0) AS paid
        FROM students s
        LEFT JOIN branches b ON b.id = s.branch_id
        LEFT JOIN (SELECT student_id, SUM(amount) AS paid FROM student_payments GROUP BY student_id) p ON p.student_id = s.id';
if ($branchFilter !== null) {
    $sql .= ' WHERE s.branch_id = ?';
}
$sql .= ' ORDER BY s.holder_name LIMIT 300';
$stmt = $db->prepare($sql);
$stmt->execute($branchFilter !== null ? [$branchFilter] : []);
$students = $stmt->fetchAll();
$paymentSql = 'SELECT p.amount, p.payment_method, p.reference, p.paid_at, s.holder_name, s.admission_number, b.name AS branch_name, a.name AS received_by
               FROM student_payments p
               JOIN students s ON s.id = p.student_id
               LEFT JOIN branches b ON b.id = s.branch_id
               LEFT JOIN admins a ON a.id = p.received_by';
if ($branchFilter !== null) {
    $paymentSql .= ' WHERE s.branch_id = ?';
}
$paymentSql .= ' ORDER BY p.paid_at DESC LIMIT 100';
$stmt = $db->prepare($paymentSql);
$stmt->execute($branchFilter !== null ? [$branchFilter] : []);
$payments = $stmt->fetchAll();

$title = 'Student Fees';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><div class="portal-eyebrow">Finance</div><h1 class="fw-bold mb-1">Student Fees</h1><p class="text-muted mb-0">Update fee totals and record payments against student balances.</p></div>
    <form method="get" class="d-flex gap-2">
        <select name="branch" class="form-select" aria-label="Filter by branch">
            <option value="">All branches</option>
            <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary">Filter</button>
    </form>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
<div class="alert alert-info">M-Pesa and Airtel Money can be recorded manually here. Automated STK push is not configured because payment-provider credentials and callback URLs are not yet available.</div>
<div class="card p-3">
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Admission</th><th>Student / Branch</th><th>Program</th><th>Total fee</th><th>Paid</th><th>Balance</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($students as $student): $balance = max(0, (float)$student['total_fee'] - (float)$student['paid']); ?>
            <tr>
                <td><?= e($student['admission_number'] ?? 'Legacy record') ?></td>
                <td><?= e($student['holder_name']) ?><div class="small text-muted"><?= e($student['branch_name'] ?? 'Branch not assigned') ?></div></td>
                <td><?= e($student['course_name']) ?></td>
                <td>KES <?= number_format((float)$student['total_fee'], 2) ?></td>
                <td>KES <?= number_format((float)$student['paid'], 2) ?></td>
                <td class="<?= $balance > 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold' ?>">KES <?= number_format($balance, 2) ?></td>
                <td>
                    <details><summary class="btn btn-sm btn-outline-primary">Manage</summary>
                        <div class="border rounded p-3 mt-2" style="min-width:300px">
                            <form method="post" class="mb-3">
                                <?= csrf_field() ?><input type="hidden" name="action" value="update_fee"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                <label class="form-label">Total course fee (KES)</label>
                                <div class="input-group"><input type="number" min="0" step="0.01" name="total_fee" value="<?= e($student['total_fee']) ?>" class="form-control" required><button class="btn btn-outline-primary">Update</button></div>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                <label class="form-label">Record payment (KES)</label><input type="number" name="amount" min="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" step="0.01" class="form-control mb-2" required>
                                <select name="payment_method" class="form-select mb-2" required><option value="">Payment method</option><option value="cash">Cash</option><option value="mpesa">M-Pesa (manual)</option><option value="airtel_money">Airtel Money (manual)</option><option value="bank">Bank</option><option value="other">Other</option></select>
                                <input name="reference" maxlength="100" class="form-control mb-2" placeholder="Receipt / transaction reference">
                                <input name="notes" maxlength="255" class="form-control mb-2" placeholder="Notes (optional)">
                                <button class="btn btn-primary w-100" <?= $balance <= 0 ? 'disabled' : '' ?>>Save Payment</button>
                            </form>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?><tr><td colspan="7" class="text-center text-muted py-4">No students match this branch.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<div class="card p-3 mt-4"><h5 class="fw-bold mb-3">Recent Payments</h5><div class="table-responsive"><table class="table">
    <thead><tr><th>Date</th><th>Student / Admission</th><th>Branch</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received by</th></tr></thead>
    <tbody><?php foreach ($payments as $payment): ?>
        <tr><td><?= e($payment['paid_at']) ?></td><td><?= e($payment['holder_name']) ?><div class="small text-muted"><?= e($payment['admission_number'] ?? '') ?></div></td><td><?= e($payment['branch_name'] ?? '—') ?></td><td>KES <?= number_format((float)$payment['amount'], 2) ?></td><td><?= e(strtoupper(str_replace('_', ' ', $payment['payment_method']))) ?></td><td><?= e($payment['reference'] ?? '—') ?></td><td><?= e($payment['received_by'] ?? '—') ?></td></tr>
    <?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payments recorded yet.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
