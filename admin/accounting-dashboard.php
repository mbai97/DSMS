<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'director', 'accountant');

$canManagePayroll = in_array(current_admin_role(), ['admin', 'accountant'], true);
$errors = [];
$success = '';
$periodInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['period'] ?? date('Y-m'))
    : ($_GET['period'] ?? date('Y-m'));
$periodValid = is_string($periodInput) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $periodInput) === 1;
$period = $periodValid ? $periodInput : date('Y-m');
if (!$periodValid) {
    $errors[] = 'Select a valid accounting month.';
    $period = date('Y-m');
}
$periodStart = $period . '-01';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManagePayroll) {
        http_response_code(403);
        exit('Directors have read-only access to accounting insights.');
    }
    verify_csrf();
    $action = $periodValid && is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $actorId = (int)$_SESSION['admin_id'];

    if ($action === 'save_salary') {
        $staffIdInput = $_POST['staff_id'] ?? null;
        $staffId = is_scalar($staffIdInput) ? filter_var($staffIdInput, FILTER_VALIDATE_INT) : false;
        $salary = is_string($_POST['monthly_salary'] ?? null) ? trim($_POST['monthly_salary']) : '';
        $effectiveFrom = is_string($_POST['effective_from'] ?? null) ? trim($_POST['effective_from']) : '';
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $validDate = $effectiveFrom !== ''
            && $parsedDate !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $parsedDate->format('Y-m-d') === $effectiveFrom;

        if ($staffId === false || $staffId === null || $staffId < 1) {
            $errors[] = 'Select a valid staff member.';
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $salary) || (float)$salary <= 0) {
            $errors[] = 'Enter a positive monthly salary in KES.';
        }
        if (!$validDate) {
            $errors[] = 'Enter a valid salary effective date.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare("SELECT id FROM staff WHERE id = ? AND status IN ('active', 'on_leave')");
                $stmt->execute([$staffId]);
                if (!$stmt->fetchColumn()) {
                    $errors[] = 'Salary can only be assigned to an active or on-leave staff member.';
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO staff_salaries (staff_id, monthly_salary, effective_from, updated_by)
                         VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$staffId, number_format((float)$salary, 2, '.', ''), $effectiveFrom, $actorId]);
                    $success = 'Salary record saved. Previously recorded salary rates are preserved.';
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = 'A salary record already exists for this staff member and effective date.';
                } else {
                    error_log('Staff salary save failed: ' . $exception->getMessage());
                    $errors[] = 'Unable to save the salary record. Please try again.';
                }
            }
        }
    } elseif ($action === 'generate_payroll') {
        try {
            $stmt = $db->prepare(
                "INSERT INTO payroll_records
                    (staff_id, payroll_month, gross_salary, deductions, net_salary, status, created_by)
                 SELECT s.id, ?, effective.monthly_salary, 0.00, effective.monthly_salary, 'pending', ?
                 FROM staff s
                 JOIN staff_salaries effective
                   ON effective.staff_id = s.id
                  AND effective.effective_from = (
                      SELECT MAX(rate.effective_from)
                      FROM staff_salaries rate
                      WHERE rate.staff_id = s.id AND rate.effective_from <= LAST_DAY(?)
                  )
                 WHERE s.status IN ('active', 'on_leave')
                   AND NOT EXISTS (
                       SELECT 1 FROM payroll_records existing
                       WHERE existing.staff_id = s.id AND existing.payroll_month = ?
                   )"
            );
            $stmt->execute([$periodStart, $actorId, $periodStart, $periodStart]);
            $created = $stmt->rowCount();
            $success = $created > 0
                ? sprintf('Generated %d payroll record(s) for %s. Existing records were left unchanged.', $created, $period)
                : 'No new payroll records were generated. Check salary effective dates or existing records.';
        } catch (Throwable $exception) {
            error_log('Payroll generation failed: ' . $exception->getMessage());
            $errors[] = 'Unable to generate payroll. Please try again.';
        }
    } elseif ($action === 'update_payroll') {
        $recordIdInput = $_POST['payroll_id'] ?? null;
        $recordId = is_scalar($recordIdInput) ? filter_var($recordIdInput, FILTER_VALIDATE_INT) : false;
        $gross = is_string($_POST['gross_salary'] ?? null) ? trim($_POST['gross_salary']) : '';
        $deductions = is_string($_POST['deductions'] ?? null) ? trim($_POST['deductions']) : '';

        if ($recordId === false || $recordId === null || $recordId < 1) {
            $errors[] = 'Select a valid payroll record.';
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $gross) || (float)$gross <= 0) {
            $errors[] = 'Enter a positive gross salary in KES.';
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $deductions) || (float)$deductions < 0) {
            $errors[] = 'Enter valid non-negative deductions in KES.';
        } elseif ((float)$deductions > (float)$gross) {
            $errors[] = 'Deductions cannot exceed gross salary.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare("UPDATE payroll_records
                    SET gross_salary = ?, deductions = ?, net_salary = ?, updated_by = ?
                    WHERE id = ? AND payroll_month = ? AND status = 'pending'");
                $grossValue = number_format((float)$gross, 2, '.', '');
                $deductionValue = number_format((float)$deductions, 2, '.', '');
                $netValue = number_format((float)$gross - (float)$deductions, 2, '.', '');
                $stmt->execute([$grossValue, $deductionValue, $netValue, $actorId, $recordId, $periodStart]);
                if ($stmt->rowCount() > 0) {
                    $success = 'Pending payroll record updated.';
                } else {
                    $stmt = $db->prepare("SELECT status FROM payroll_records WHERE id = ? AND payroll_month = ?");
                    $stmt->execute([$recordId, $periodStart]);
                    $recordStatus = $stmt->fetchColumn();
                    if ($recordStatus === false) {
                        $errors[] = 'The payroll record was not found for the selected month.';
                    } elseif ($recordStatus !== 'pending') {
                        $errors[] = 'Paid payroll records cannot be changed.';
                    } else {
                        $success = 'Pending payroll record saved.';
                    }
                }
            } catch (Throwable $exception) {
                error_log('Payroll record update failed: ' . $exception->getMessage());
                $errors[] = 'Unable to update the payroll record. Please try again.';
            }
        }
    } elseif ($action === 'mark_paid') {
        $recordIdInput = $_POST['payroll_id'] ?? null;
        $recordId = is_scalar($recordIdInput) ? filter_var($recordIdInput, FILTER_VALIDATE_INT) : false;
        $method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
        $reference = is_string($_POST['payment_reference'] ?? null) ? trim($_POST['payment_reference']) : '';

        if ($recordId === false || $recordId === null || $recordId < 1) {
            $errors[] = 'Select a valid payroll record.';
        }
        if (!in_array($method, ['cash', 'mpesa', 'airtel_money', 'bank', 'other'], true)) {
            $errors[] = 'Select a valid payroll payment method.';
        }
        if (mb_strlen($reference) > 100) {
            $errors[] = 'Payment reference must be 100 characters or fewer.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare(
                    "UPDATE payroll_records
                     SET status = 'paid', paid_at = NOW(), payment_method = ?, payment_reference = ?, updated_by = ?
                     WHERE id = ? AND payroll_month = ? AND status = 'pending'"
                );
                $stmt->execute([$method, $reference !== '' ? $reference : null, $actorId, $recordId, $periodStart]);
                if ($stmt->rowCount() > 0) {
                    $success = 'Payroll payment recorded.';
                } else {
                    $stmt = $db->prepare('SELECT status FROM payroll_records WHERE id = ? AND payroll_month = ?');
                    $stmt->execute([$recordId, $periodStart]);
                    $recordStatus = $stmt->fetchColumn();
                    if ($recordStatus === false) {
                        $errors[] = 'The payroll record was not found for the selected month.';
                    } else {
                        $errors[] = 'This payroll record has already been paid.';
                    }
                }
            } catch (Throwable $exception) {
                error_log('Payroll payment recording failed: ' . $exception->getMessage());
                $errors[] = 'Unable to record the payroll payment. Please try again.';
            }
        }
    } else {
        $errors[] = 'Select a valid accounting operation.';
    }
}

$stmt = $db->prepare(
    "SELECT COUNT(*) AS entries,
            COALESCE(SUM(gross_salary), 0) AS gross,
            COALESCE(SUM(deductions), 0) AS deductions,
            COALESCE(SUM(net_salary), 0) AS net,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN net_salary ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN net_salary ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(status = 'pending'), 0) AS pending_count
     FROM payroll_records WHERE payroll_month = ?"
);
$stmt->execute([$periodStart]);
$payrollSummary = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT DATE_FORMAT(payroll_month, '%Y-%m') AS period,
            COALESCE(SUM(net_salary), 0) AS net,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN net_salary ELSE 0 END), 0) AS paid
     FROM payroll_records
     WHERE payroll_month >= DATE_SUB(?, INTERVAL 5 MONTH) AND payroll_month <= ?
     GROUP BY payroll_month ORDER BY payroll_month"
);
$stmt->execute([$periodStart, $periodStart]);
$payrollTrendRows = $stmt->fetchAll();
$payrollTrend = [];
foreach ($payrollTrendRows as $row) {
    $payrollTrend[$row['period']] = $row;
}
$periodDate = new DateTimeImmutable($periodStart);
$trend = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $month = $periodDate->modify('-' . $offset . ' months')->format('Y-m');
    $trend[] = $payrollTrend[$month] ?? ['period' => $month, 'net' => 0, 'paid' => 0];
}
$maxTrend = max(1.0, ...array_map(static fn(array $row): float => (float)$row['net'], $trend));

$feeSummary = $db->query(
    "SELECT
        (SELECT COALESCE(SUM(total_fee), 0) FROM students) AS billed,
        (SELECT COALESCE(SUM(amount), 0) FROM student_payments) AS received,
        (SELECT COALESCE(SUM(amount), 0) FROM student_payments
         WHERE paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND paid_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)) AS received_this_month"
)->fetch();
$stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM student_payments WHERE paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 MONTH)');
$stmt->execute([$periodStart, $periodStart]);
$feeSummary['received_selected_month'] = $stmt->fetchColumn();
$feeSummary['balance'] = max(0, (float)$feeSummary['billed'] - (float)$feeSummary['received']);

$staffOptions = [];
$salaryHistory = [];
$payrollRecords = [];
$monthlySalaryTotal = 0.0;
if ($canManagePayroll) {
    $staffOptions = $db->query(
        "SELECT s.id, s.name, s.department, s.job_title
         FROM staff s WHERE s.status IN ('active', 'on_leave') ORDER BY s.name"
    )->fetchAll();
    $monthlySalaryTotal = (float)$db->query(
        "SELECT COALESCE(SUM(current_rate.monthly_salary), 0)
         FROM staff s
         JOIN staff_salaries current_rate
           ON current_rate.staff_id = s.id
          AND current_rate.effective_from = (
              SELECT MAX(rate.effective_from)
              FROM staff_salaries rate
              WHERE rate.staff_id = s.id AND rate.effective_from <= CURDATE()
          )
         WHERE s.status IN ('active', 'on_leave')"
    )->fetchColumn();
    $salaryHistory = $db->query(
        'SELECT s.name, s.department, s.job_title, rate.monthly_salary, rate.effective_from
         FROM staff_salaries rate JOIN staff s ON s.id = rate.staff_id
         ORDER BY rate.effective_from DESC, s.name LIMIT 100'
    )->fetchAll();
    $stmt = $db->prepare(
        'SELECT p.id, p.staff_id, p.gross_salary, p.deductions, p.net_salary, p.status,
                p.paid_at, p.payment_method, p.payment_reference, s.name, s.department, s.job_title,
                payer.name AS recorded_by
         FROM payroll_records p JOIN staff s ON s.id = p.staff_id
         LEFT JOIN admins payer ON payer.id = p.updated_by
         WHERE p.payroll_month = ? ORDER BY s.name'
    );
    $stmt->execute([$periodStart]);
    $payrollRecords = $stmt->fetchAll();
}

$title = 'Accounting Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="portal-eyebrow">Finance &amp; Accounting</div>
        <h1 class="fw-bold mb-1">Accounting Dashboard</h1>
        <p class="text-muted mb-0">Student fee performance, salary costs, and monthly payroll.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canManagePayroll): ?><a class="btn btn-outline-primary" href="fees.php">Student fees &amp; receipts</a><?php endif; ?>
        <?php if (is_role('admin')): ?><a class="btn btn-outline-primary" href="dashboard.php">Admin dashboard</a><?php endif; ?>
        <form method="get" class="d-flex gap-2">
            <input type="month" name="period" class="form-control" value="<?= e($period) ?>" aria-label="Accounting month">
            <button class="btn btn-outline-primary">View month</button>
        </form>
    </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Student fees billed', 'KES ' . number_format((float)$feeSummary['billed'], 2), 'primary'],
        ['Fees received (' . $period . ')', 'KES ' . number_format((float)$feeSummary['received_selected_month'], 2), 'success'],
        ['Outstanding student fees', 'KES ' . number_format((float)$feeSummary['balance'], 2), 'warning'],
        ['Payroll net (' . $period . ')', 'KES ' . number_format((float)$payrollSummary['net'], 2), 'dark'],
        ['Payroll paid', 'KES ' . number_format((float)$payrollSummary['paid'], 2), 'success'],
        ['Payroll outstanding', 'KES ' . number_format((float)$payrollSummary['outstanding'], 2), 'warning'],
    ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-4"><div class="card p-3 border-start border-4 border-<?= $color ?>"><div class="text-muted"><?= e($label) ?></div><div class="stat-number"><?= e($value) ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3">Six-month payroll trend</h5>
            <?php foreach ($trend as $month): ?>
                <?php $width = (int)round((float)$month['net'] / $maxTrend * 100); ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="small text-muted" style="width: 4.5rem"><?= e(date('M Y', strtotime($month['period'] . '-01'))) ?></span>
                    <div class="progress flex-grow-1" role="progressbar" aria-label="Payroll total for <?= e($month['period']) ?>" aria-valuenow="<?= $width ?>" aria-valuemin="0" aria-valuemax="100" style="height: 12px">
                        <div class="progress-bar bg-primary" style="width: <?= $width ?>%"></div>
                    </div>
                    <span class="small fw-semibold text-nowrap">KES <?= number_format((float)$month['net'], 2) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3">Finance snapshot</h5>
            <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted">Fees collected this month</span><strong>KES <?= number_format((float)$feeSummary['received_this_month'], 2) ?></strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted">Payroll records this month</span><strong><?= (int)$payrollSummary['entries'] ?></strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted">Awaiting payment</span><strong><?= (int)$payrollSummary['pending_count'] ?></strong></div>
            <?php if ($canManagePayroll): ?><div class="d-flex justify-content-between py-2"><span class="text-muted">Current monthly salary total</span><strong>KES <?= number_format($monthlySalaryTotal, 2) ?></strong></div><?php endif; ?>
            <?php if (!$canManagePayroll): ?><p class="small text-muted mt-3 mb-0">Director access is limited to aggregate accounting insights; employee salary and payment details are restricted.</p><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canManagePayroll): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3">Set salary rate</h5>
            <p class="small text-muted">Salary changes are saved as dated records; existing rates and generated payroll remain unchanged.</p>
            <?php if ($staffOptions): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_salary">
                    <input type="hidden" name="period" value="<?= e($period) ?>">
                    <div class="mb-3"><label class="form-label" for="salary-staff">Staff member</label><select class="form-select" id="salary-staff" name="staff_id" required>
                        <option value="">Select staff member</option>
                        <?php foreach ($staffOptions as $member): ?><option value="<?= (int)$member['id'] ?>"><?= e($member['name'] . ' — ' . $member['department'] . ' / ' . $member['job_title']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="monthly-salary">Monthly salary (KES)</label><input class="form-control" id="monthly-salary" name="monthly_salary" type="number" min="0.01" max="9999999999.99" step="0.01" required></div>
                        <div class="col-md-6"><label class="form-label" for="effective-from">Effective from</label><input class="form-control" id="effective-from" name="effective_from" type="date" value="<?= e(date('Y-m-d')) ?>" required></div>
                    </div>
                    <button class="btn btn-primary mt-3">Save salary rate</button>
                </form>
            <?php else: ?>
                <p class="text-muted mb-0">Add active staff members in HR before assigning salaries.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-2">Generate monthly payroll</h5>
            <p class="text-muted">Creates one pending payment record per eligible active or on-leave employee using the salary rate effective for the selected month. Existing records are not overwritten.</p>
            <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted">Selected month</span><strong><?= e($period) ?></strong></div>
            <div class="d-flex justify-content-between py-2"><span class="text-muted">Eligible employees</span><strong><?= count($staffOptions) ?></strong></div>
            <form method="post" class="mt-3" onsubmit="return confirm('Generate missing payroll records for <?= e($period) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate_payroll">
                <input type="hidden" name="period" value="<?= e($period) ?>">
                <button class="btn btn-primary">Generate payroll for <?= e($period) ?></button>
            </form>
        </div>
    </div>
</div>

<div class="card p-4 mb-4">
    <h5 class="fw-bold mb-3">Salary history</h5>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Staff member</th><th>Department / title</th><th>Monthly salary</th><th>Effective from</th></tr></thead>
        <tbody>
        <?php if (!$salaryHistory): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No salary rates have been recorded.</td></tr>
        <?php else: foreach ($salaryHistory as $rate): ?>
            <tr><td><?= e($rate['name']) ?></td><td><?= e($rate['department']) ?><div class="small text-muted"><?= e($rate['job_title']) ?></div></td><td>KES <?= number_format((float)$rate['monthly_salary'], 2) ?></td><td><?= e(format_date($rate['effective_from'])) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h5 class="fw-bold mb-1">Payroll records</h5><p class="text-muted mb-0">Gross salary less manual deductions equals net salary. No tax or statutory deduction rules are applied automatically.</p></div>
        <span class="badge text-bg-light"><?= e($period) ?> · <?= (int)$payrollSummary['entries'] ?> records · <?= (int)$payrollSummary['pending_count'] ?> pending</span>
    </div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Staff member</th><th>Gross (KES)</th><th>Deductions (KES)</th><th>Net (KES)</th><th>Status / payment</th><th>Operations</th></tr></thead>
        <tbody>
        <?php if (!$payrollRecords): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No payroll records for this month. Generate payroll after setting salary rates.</td></tr>
        <?php else: foreach ($payrollRecords as $record): ?>
            <tr>
                <td><?= e($record['name']) ?><div class="small text-muted"><?= e($record['department']) ?> · <?= e($record['job_title']) ?></div></td>
                <td><?= number_format((float)$record['gross_salary'], 2) ?></td>
                <td><?= number_format((float)$record['deductions'], 2) ?></td>
                <td class="fw-semibold"><?= number_format((float)$record['net_salary'], 2) ?></td>
                <td>
                    <?php if ($record['status'] === 'paid'): ?>
                        <span class="badge text-bg-success">Paid</span>
                        <div class="small text-muted"><?= e(format_date($record['paid_at'])) ?> · <?= e(strtoupper((string)$record['payment_method'])) ?></div>
                        <div class="small text-muted">Recorded by <?= e($record['recorded_by'] ?: '—') ?></div>
                        <?php if ($record['payment_reference']): ?><div class="small text-muted"><?= e($record['payment_reference']) ?></div><?php endif; ?>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Pending</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($record['status'] === 'pending'): ?>
                        <form method="post" class="d-flex flex-wrap gap-1 mb-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_payroll">
                            <input type="hidden" name="period" value="<?= e($period) ?>">
                            <input type="hidden" name="payroll_id" value="<?= (int)$record['id'] ?>">
                            <label class="visually-hidden" for="gross-<?= (int)$record['id'] ?>">Gross salary</label>
                            <input id="gross-<?= (int)$record['id'] ?>" class="form-control form-control-sm" style="width: 105px" type="number" name="gross_salary" min="0.01" step="0.01" value="<?= e($record['gross_salary']) ?>" required>
                            <label class="visually-hidden" for="deductions-<?= (int)$record['id'] ?>">Deductions</label>
                            <input id="deductions-<?= (int)$record['id'] ?>" class="form-control form-control-sm" style="width: 105px" type="number" name="deductions" min="0" step="0.01" value="<?= e($record['deductions']) ?>" required>
                            <button class="btn btn-sm btn-outline-primary">Save draft</button>
                        </form>
                        <form method="post" class="d-flex flex-wrap gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="period" value="<?= e($period) ?>">
                            <input type="hidden" name="payroll_id" value="<?= (int)$record['id'] ?>">
                            <select class="form-select form-select-sm" name="payment_method" aria-label="Payroll payment method" required>
                                <option value="">Payment method</option>
                                <option value="bank">Bank</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="cash">Cash</option>
                                <option value="other">Other</option>
                            </select>
                            <input class="form-control form-control-sm" name="payment_reference" maxlength="100" placeholder="Reference (optional)" aria-label="Payment reference">
                            <button class="btn btn-sm btn-primary">Record payment</button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">Payment recorded</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
