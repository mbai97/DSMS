<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch', 'accountant');

$role = current_admin_role();
$branchRole = $role === 'branch';
$canManageEnquiries = in_array($role, ['admin', 'branch'], true);
$assignedBranchId = current_admin_branch_id();
if ($branchRole && $assignedBranchId === null) {
    http_response_code(403);
    exit('This Branch account has no assigned branch. Ask an administrator to assign one.');
}

$errors = [];
$success = is_string($_SESSION['report_success'] ?? null) ? $_SESSION['report_success'] : '';
unset($_SESSION['report_success']);
$filterSource = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$branchesStmt = $branchRole
    ? $db->prepare('SELECT id, name FROM branches WHERE id = ? ORDER BY name')
    : $db->query('SELECT id, name FROM branches ORDER BY name');
if ($branchRole) {
    $branchesStmt->execute([$assignedBranchId]);
}
$branches = $branchesStmt->fetchAll();
$courses = $db->query('SELECT id, name FROM courses ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!$canManageEnquiries) {
        http_response_code(403);
        exit('You have read-only access to enquiry records.');
    }

    if ($action === 'add_enquiry') {
        $name = is_string($_POST['prospect_name'] ?? null) ? trim($_POST['prospect_name']) : '';
        $phone = is_string($_POST['phone_number'] ?? null) ? trim($_POST['phone_number']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $notes = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
        $branchInput = $branchRole ? $assignedBranchId : filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $courseInput = ($_POST['course_id'] ?? '') === '' ? null : filter_var($_POST['course_id'], FILTER_VALIDATE_INT);

        if ($name === '' || mb_strlen($name) > 190) {
            $errors[] = 'Enter a prospect name of 1 to 190 characters.';
        }
        if (mb_strlen($phone) > 30) {
            $errors[] = 'Phone number must be 30 characters or fewer.';
        }
        if (mb_strlen($email) > 190 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address of 190 characters or fewer.';
        }
        if (mb_strlen($notes) > 500) {
            $errors[] = 'Notes must be 500 characters or fewer.';
        }
        if ($branchInput === false || $branchInput === null || $branchInput < 1) {
            $errors[] = 'Select a valid branch.';
        }
        if ($courseInput === false || ($courseInput !== null && $courseInput < 1)) {
            $errors[] = 'Select a valid course.';
        }
        if (!$errors) {
            $stmt = $db->prepare('SELECT 1 FROM branches WHERE id = ?');
            $stmt->execute([$branchInput]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'Select a valid branch.';
            }
            if ($courseInput !== null) {
                $stmt = $db->prepare('SELECT 1 FROM courses WHERE id = ?');
                $stmt->execute([$courseInput]);
                if (!$stmt->fetchColumn()) {
                    $errors[] = 'Select a valid course.';
                }
            }
        }
        if (!$errors) {
            $stmt = $db->prepare(
                'INSERT INTO enquiries (prospect_name, phone_number, email, branch_id, course_id, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $branchInput,
                $courseInput,
                $notes !== '' ? $notes : null,
                (int)$_SESSION['admin_id'],
            ]);
            $success = 'Enquiry recorded.';
        }
    } elseif ($action === 'update_enquiry_status') {
        $enquiryId = filter_var($_POST['enquiry_id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($enquiryId === false || $enquiryId === null || $enquiryId < 1) {
            $errors[] = 'Select a valid enquiry.';
        }
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            $errors[] = 'Select a valid enquiry status.';
        }
        if (!$errors) {
            $sql = 'UPDATE enquiries SET status = ? WHERE id = ?';
            $params = [$status, $enquiryId];
            if ($branchRole) {
                $sql .= ' AND branch_id = ?';
                $params[] = $assignedBranchId;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $success = 'Enquiry status updated.';
            } else {
                $checkSql = 'SELECT 1 FROM enquiries WHERE id = ?';
                $checkParams = [$enquiryId];
                if ($branchRole) {
                    $checkSql .= ' AND branch_id = ?';
                    $checkParams[] = $assignedBranchId;
                }
                $check = $db->prepare($checkSql);
                $check->execute($checkParams);
                $success = $check->fetchColumn() ? 'Enquiry status was already set.' : 'The enquiry was not found in your branch.';
            }
        }
    } else {
        $errors[] = 'Select a valid enquiry action.';
    }
}

$periodOptions = [
    'day' => 'Daily',
    'week' => 'Weekly',
    'month' => 'Monthly',
    'quarter' => 'Quarterly',
    'half_year' => 'Semi-annual',
    'year' => 'Annual',
];
$period = is_string($filterSource['period'] ?? null) ? $filterSource['period'] : 'month';
if (!isset($periodOptions[$period])) {
    $errors[] = 'Select a valid reporting period.';
    $period = 'month';
}
$dateInput = is_string($filterSource['date'] ?? null) ? $filterSource['date'] : date('Y-m-d');
$anchorDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
$dateErrors = DateTimeImmutable::getLastErrors();
$validDate = $dateInput !== ''
    && $anchorDate !== false
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
    && $anchorDate->format('Y-m-d') === $dateInput;
if (!$validDate) {
    $errors[] = 'Select a valid reporting date.';
    $anchorDate = new DateTimeImmutable('today');
}

switch ($period) {
    case 'day':
        $start = $anchorDate;
        $nextStart = $start->modify('+1 day');
        break;
    case 'week':
        $start = $anchorDate->modify('monday this week');
        $nextStart = $start->modify('+1 week');
        break;
    case 'quarter':
        $quarterMonth = (int)(floor(((int)$anchorDate->format('n') - 1) / 3) * 3 + 1);
        $start = $anchorDate->setDate((int)$anchorDate->format('Y'), $quarterMonth, 1);
        $nextStart = $start->modify('+3 months');
        break;
    case 'half_year':
        $halfYearMonth = (int)$anchorDate->format('n') <= 6 ? 1 : 7;
        $start = $anchorDate->setDate((int)$anchorDate->format('Y'), $halfYearMonth, 1);
        $nextStart = $start->modify('+6 months');
        break;
    case 'year':
        $start = $anchorDate->setDate((int)$anchorDate->format('Y'), 1, 1);
        $nextStart = $start->modify('+1 year');
        break;
    default:
        $start = $anchorDate->modify('first day of this month')->setTime(0, 0);
        $nextStart = $start->modify('+1 month');
        break;
}
$startDate = $start->format('Y-m-d');
$endExclusive = $nextStart->format('Y-m-d');
$endDate = $nextStart->modify('-1 day')->format('Y-m-d');

$branchInput = filter_var($filterSource['branch'] ?? null, FILTER_VALIDATE_INT);
$branchFilter = $branchInput !== false && $branchInput !== null && $branchInput > 0 ? (int)$branchInput : null;
if ($branchRole) {
    $branchFilter = $assignedBranchId;
} elseif (isset($filterSource['branch']) && $filterSource['branch'] !== '' && $branchFilter === null) {
    $errors[] = 'Select a valid branch.';
    $branchFilter = -1;
}
$courseInput = filter_var($filterSource['course'] ?? null, FILTER_VALIDATE_INT);
$courseFilter = $courseInput !== false && $courseInput !== null && $courseInput > 0 ? (int)$courseInput : null;
if (isset($filterSource['course']) && $filterSource['course'] !== '' && $courseFilter === null) {
    $errors[] = 'Select a valid course.';
    $courseFilter = -1;
}
$methodOptions = [
    'cash' => 'Cash',
    'mpesa' => 'M-Pesa',
    'airtel_money' => 'Airtel Money',
    'bank' => 'Bank',
    'other' => 'Other',
];
$methodFilter = is_string($filterSource['method'] ?? null) ? $filterSource['method'] : '';
if ($methodFilter !== '' && !isset($methodOptions[$methodFilter])) {
    $errors[] = 'Select a valid payment method.';
    $methodFilter = '__invalid__';
}

$studentScope = ['s.enrollment_date < ?'];
$studentScopeParams = [$endExclusive];
if ($branchFilter !== null) {
    $studentScope[] = 's.branch_id = ?';
    $studentScopeParams[] = $branchFilter;
}
$selectedCourseName = null;
if ($courseFilter !== null) {
    foreach ($courses as $course) {
        if ((int)$course['id'] === $courseFilter) {
            $selectedCourseName = $course['name'];
            break;
        }
    }
    if ($selectedCourseName === null) {
        $errors[] = 'Select a valid course.';
        $studentScope[] = 's.course_id = ?';
        $studentScopeParams[] = $courseFilter;
    } else {
        $studentScope[] = '(s.course_id = ? OR (s.course_id IS NULL AND s.course_name = ?))';
        $studentScopeParams[] = $courseFilter;
        $studentScopeParams[] = $selectedCourseName;
    }
}
$studentScopeSql = implode(' AND ', $studentScope);
$enrollmentWhere = $studentScope;
$enrollmentWhereParams = $studentScopeParams;
$enrollmentWhere[] = 's.enrollment_date >= ?';
$enrollmentWhere[] = 's.enrollment_date < ?';
$enrollmentWhereParams[] = $startDate;
$enrollmentWhereParams[] = $endExclusive;

$stmt = $db->prepare(
    'SELECT COUNT(*) AS registrations, COALESCE(SUM(s.total_fee), 0) AS fees_billed
     FROM students s WHERE ' . implode(' AND ', $enrollmentWhere)
);
$stmt->execute($enrollmentWhereParams);
$registrationSummary = $stmt->fetch();

$stmt = $db->prepare(
    'SELECT COUNT(*) AS students_to_date, COALESCE(SUM(s.total_fee), 0) AS billed,
            COALESCE(SUM(GREATEST(s.total_fee - COALESCE(p.paid, 0), 0)), 0) AS balance
     FROM students s
     LEFT JOIN (
         SELECT student_id, SUM(amount) AS paid FROM student_payments WHERE paid_at < ? GROUP BY student_id
     ) p ON p.student_id = s.id
     WHERE ' . $studentScopeSql
);
$stmt->execute(array_merge([$endExclusive], $studentScopeParams));
$balanceSummary = $stmt->fetch();

$paymentWhere = ['p.paid_at >= ?', 'p.paid_at < ?'];
$paymentWhereParams = [$startDate, $endExclusive];
if ($branchFilter !== null) {
    $paymentWhere[] = 's.branch_id = ?';
    $paymentWhereParams[] = $branchFilter;
}
if ($courseFilter !== null) {
    $paymentWhere[] = '(s.course_id = ? OR (s.course_id IS NULL AND s.course_name = ?))';
    $paymentWhereParams[] = $courseFilter;
    $paymentWhereParams[] = $selectedCourseName;
}
if ($methodFilter !== '') {
    $paymentWhere[] = 'p.payment_method = ?';
    $paymentWhereParams[] = $methodFilter;
}
$paymentWhereSql = implode(' AND ', $paymentWhere);
$paymentFrom = ' FROM student_payments p JOIN students s ON s.id = p.student_id
                 LEFT JOIN branches b ON b.id = s.branch_id ';
$stmt = $db->prepare(
    'SELECT COUNT(*) AS transactions, COUNT(DISTINCT p.student_id) AS students_paid,
            COALESCE(SUM(p.amount), 0) AS collected' . $paymentFrom . ' WHERE ' . $paymentWhereSql
);
$stmt->execute($paymentWhereParams);
$paymentSummary = $stmt->fetch();

$stmt = $db->prepare(
    'SELECT COALESCE(SUM(CASE WHEN s.enrollment_date >= ? THEN p.amount ELSE 0 END), 0) AS new_enrollment_collections,
            COALESCE(SUM(CASE WHEN s.enrollment_date < ? THEN p.amount ELSE 0 END), 0) AS topups
     ' . $paymentFrom . ' WHERE ' . $paymentWhereSql
);
$stmt->execute(array_merge([$startDate, $startDate], $paymentWhereParams));
$collectionSplit = $stmt->fetch();

$selectedBranchName = 'All branches';
foreach ($branches as $branch) {
    if ($branchFilter !== null && (int)$branch['id'] === $branchFilter) {
        $selectedBranchName = $branch['name'];
        break;
    }
}

$stmt = $db->prepare(
    'SELECT p.payment_method, COUNT(*) AS transactions, COALESCE(SUM(p.amount), 0) AS amount
     ' . $paymentFrom . ' WHERE ' . $paymentWhereSql . '
     GROUP BY p.payment_method ORDER BY p.payment_method'
);
$stmt->execute($paymentWhereParams);
$methodRows = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT s.course_name, COUNT(*) AS registrations, COALESCE(SUM(s.total_fee), 0) AS fees_billed
     FROM students s WHERE ' . implode(' AND ', $enrollmentWhere) . '
     GROUP BY s.course_name ORDER BY s.course_name'
);
$stmt->execute($enrollmentWhereParams);
$courseRows = [];
foreach ($stmt->fetchAll() as $row) {
    $courseRows[$row['course_name']] = [
        'name' => $row['course_name'],
        'registrations' => (int)$row['registrations'],
        'fees_billed' => (float)$row['fees_billed'],
        'collected' => 0.0,
    ];
}
$stmt = $db->prepare(
    'SELECT s.course_name, COALESCE(SUM(p.amount), 0) AS collected
     ' . $paymentFrom . ' WHERE ' . $paymentWhereSql . '
     GROUP BY s.course_name'
);
$stmt->execute($paymentWhereParams);
foreach ($stmt->fetchAll() as $row) {
    if (!isset($courseRows[$row['course_name']])) {
        $courseRows[$row['course_name']] = [
            'name' => $row['course_name'],
            'registrations' => 0,
            'fees_billed' => 0.0,
            'collected' => 0.0,
        ];
    }
    $courseRows[$row['course_name']]['collected'] = (float)$row['collected'];
}
ksort($courseRows);
$courseRows = array_values($courseRows);

$enquiryWhere = ['e.created_at >= ?', 'e.created_at < ?'];
$enquiryWhereParams = [$startDate, $endExclusive];
if ($branchFilter !== null) {
    $enquiryWhere[] = 'e.branch_id = ?';
    $enquiryWhereParams[] = $branchFilter;
}
if ($courseFilter !== null) {
    $enquiryWhere[] = 'e.course_id = ?';
    $enquiryWhereParams[] = $courseFilter;
}
$enquiryFrom = ' FROM enquiries e ';
$stmt = $db->prepare(
    'SELECT COUNT(*) AS enquiries,
            COALESCE(SUM(e.status = \'new\'), 0) AS new_count,
            COALESCE(SUM(e.status = \'contacted\'), 0) AS contacted_count,
            COALESCE(SUM(e.status = \'closed\'), 0) AS closed_count
     ' . $enquiryFrom . ' WHERE ' . implode(' AND ', $enquiryWhere)
);
$stmt->execute($enquiryWhereParams);
$enquirySummary = $stmt->fetch();

$stmt = $db->prepare(
    'SELECT s.id, s.holder_name, s.admission_number, s.course_name, s.enrollment_date, s.total_fee, b.name AS branch_name
     FROM students s LEFT JOIN branches b ON b.id = s.branch_id
     WHERE ' . implode(' AND ', $enrollmentWhere) . '
     ORDER BY s.enrollment_date, s.holder_name'
);
$stmt->execute($enrollmentWhereParams);
$enrollmentRows = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT p.id, p.paid_at, p.amount, p.payment_method, p.reference, s.holder_name, s.admission_number,
            s.course_name, b.name AS branch_name
     ' . $paymentFrom . ' WHERE ' . $paymentWhereSql . '
     ORDER BY p.paid_at, p.id'
);
$stmt->execute($paymentWhereParams);
$paymentRows = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT e.id, e.prospect_name, e.phone_number, e.email, e.status, e.source, e.created_at, e.notes,
            b.name AS branch_name, c.name AS course_name
     FROM enquiries e
     LEFT JOIN branches b ON b.id = e.branch_id
     LEFT JOIN courses c ON c.id = e.course_id
     WHERE ' . implode(' AND ', $enquiryWhere) . '
     ORDER BY e.created_at, e.id'
);
$stmt->execute($enquiryWhereParams);
$enquiryRows = $stmt->fetchAll();

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beckyn-report-' . $startDate . '-to-' . $endDate . '.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to create the CSV report.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    $writeRow = static function (array $values) use ($output): void {
        $safeValues = array_map(static function ($value): string {
            $value = (string)$value;
            return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
        }, $values);
        fputcsv($output, $safeValues);
    };
    $writeRow(['BeckynDS report', $periodOptions[$period], $startDate, $endDate]);
    $writeRow(['Filters', 'Branch', $selectedBranchName, 'Course', $selectedCourseName ?? ($courseFilter === null ? 'All courses' : (string)$courseFilter), 'Payment method', $methodFilter === '' ? 'All methods' : ($methodOptions[$methodFilter] ?? $methodFilter)]);
    $writeRow([]);
    $writeRow(['Summary', 'Value']);
    $writeRow(['New registrations', $registrationSummary['registrations']]);
    $writeRow(['Fees billed to new registrations (KES)', $registrationSummary['fees_billed']]);
    $writeRow(['Enquiries', $enquirySummary['enquiries']]);
    $writeRow(['New enquiries (current status)', $enquirySummary['new_count']]);
    $writeRow(['Contacted enquiries (current status)', $enquirySummary['contacted_count']]);
    $writeRow(['Closed enquiries (current status)', $enquirySummary['closed_count']]);
    $writeRow(['Collected in period (KES)', $paymentSummary['collected']]);
    $writeRow(['Collected from new enrollments (KES)', $collectionSplit['new_enrollment_collections']]);
    $writeRow(['Top-ups from previously enrolled students (KES)', $collectionSplit['topups']]);
    $writeRow(['Payment transactions', $paymentSummary['transactions']]);
    $writeRow(['Students who paid', $paymentSummary['students_paid']]);
    $writeRow(['Students enrolled by period end', $balanceSummary['students_to_date']]);
    $writeRow(['Fees billed by period end (KES)', $balanceSummary['billed']]);
    $writeRow(['Outstanding balance as of period end (KES)', $balanceSummary['balance']]);
    $writeRow([]);
    $writeRow(['Payment method', 'Transactions', 'Collected (KES)']);
    foreach ($methodRows as $row) {
        $writeRow([$methodOptions[$row['payment_method']] ?? $row['payment_method'], $row['transactions'], $row['amount']]);
    }
    $writeRow([]);
    $writeRow(['Course', 'New registrations', 'Fees billed (KES)', 'Collected in period (KES)']);
    foreach ($courseRows as $row) {
        $writeRow([$row['name'], $row['registrations'], $row['fees_billed'], $row['collected']]);
    }
    if ($role !== 'director') {
        $writeRow([]);
        $writeRow(['Enquiries', 'Prospect', 'Phone', 'Email', 'Branch', 'Course', 'Source', 'Status', 'Recorded']);
        foreach ($enquiryRows as $row) {
            $writeRow([$row['id'], $row['prospect_name'], $row['phone_number'], $row['email'], $row['branch_name'], $row['course_name'], $row['source'], $row['status'], $row['created_at']]);
        }
        $writeRow([]);
        $writeRow(['Enrollments', 'Student', 'Admission', 'Course', 'Branch', 'Date', 'Fees billed (KES)']);
        foreach ($enrollmentRows as $row) {
            $writeRow([$row['id'], $row['holder_name'], $row['admission_number'], $row['course_name'], $row['branch_name'], $row['enrollment_date'], $row['total_fee']]);
        }
        $writeRow([]);
        $writeRow(['Payments', 'Date', 'Student', 'Admission', 'Course', 'Branch', 'Method', 'Amount (KES)', 'Reference']);
        foreach ($paymentRows as $row) {
            $writeRow([$row['id'], $row['paid_at'], $row['holder_name'], $row['admission_number'], $row['course_name'], $row['branch_name'], $methodOptions[$row['payment_method']] ?? $row['payment_method'], $row['amount'], $row['reference']]);
        }
    }
    fclose($output);
    exit;
}

$queryFilters = [
    'period' => $period,
    'date' => $anchorDate->format('Y-m-d'),
];
if ($branchFilter !== null) {
    $queryFilters['branch'] = $branchFilter;
}
if ($courseFilter !== null) {
    $queryFilters['course'] = $courseFilter;
}
if ($methodFilter !== '') {
    $queryFilters['method'] = $methodFilter;
}
$csvUrl = 'reports.php?' . http_build_query($queryFilters + ['format' => 'csv']);
$resetUrl = 'reports.php?' . http_build_query([
    'period' => $period,
    'date' => $anchorDate->format('Y-m-d'),
]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && $success !== '') {
    $_SESSION['report_success'] = $success;
    redirect(app_url($config, 'admin/reports.php?' . http_build_query($queryFilters)));
}

$headlineStats = [
    ['Collections this period', 'KES ' . number_format((float)$paymentSummary['collected'], 2), 'collections'],
    ['Outstanding at period end', 'KES ' . number_format((float)$balanceSummary['balance'], 2), 'balance'],
    ['New registrations', number_format((int)$registrationSummary['registrations']), 'registrations'],
    ['Enquiries received', number_format((int)$enquirySummary['enquiries']), 'enquiries'],
];
$supportingStats = [
    ['New fees billed', 'KES ' . number_format((float)$registrationSummary['fees_billed'], 2)],
    ['Collected from new enrollments', 'KES ' . number_format((float)$collectionSplit['new_enrollment_collections'], 2)],
    ['Top-ups from earlier enrollments', 'KES ' . number_format((float)$collectionSplit['topups'], 2)],
    ['Payment transactions', number_format((int)$paymentSummary['transactions'])],
    ['Students who paid', number_format((int)$paymentSummary['students_paid'])],
    ['Students enrolled by period end', number_format((int)$balanceSummary['students_to_date'])],
    ['Fees billed by period end', 'KES ' . number_format((float)$balanceSummary['billed'], 2)],
];
$title = 'Filtered Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="report-page">
<header class="report-hero mb-4">
    <div>
        <div class="portal-eyebrow">Institute performance</div>
        <h1 class="fw-bold mb-2">Reports &amp; analytics</h1>
        <p class="text-muted mb-3">Enrollment, enquiries, collections, and period-end fee position in one view.</p>
        <div class="report-meta">
            <span class="report-meta-period"><?= e($periodOptions[$period]) ?></span>
            <span><?= e($startDate) ?> to <?= e($endDate) ?></span>
            <span><?= e($selectedBranchName) ?></span>
            <?php if ($selectedCourseName !== null): ?><span><?= e($selectedCourseName) ?></span><?php endif; ?>
            <?php if ($methodFilter !== ''): ?><span><?= e($methodOptions[$methodFilter] ?? $methodFilter) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="report-actions no-print">
        <a class="btn btn-primary" href="<?= e($csvUrl) ?>">Download CSV</a>
        <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print report</button>
    </div>
</header>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<section class="card report-filter-card mb-4" aria-labelledby="report-filter-heading">
    <div class="report-section-heading no-print">
        <div>
            <div class="portal-eyebrow">Refine your view</div>
            <h2 class="h5 mb-0" id="report-filter-heading">Report filters</h2>
        </div>
        <a class="report-reset-link" href="<?= e($resetUrl) ?>">Reset filters</a>
    </div>
    <form method="get" class="row g-3 align-items-end no-print">
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="report-period">Reporting period</label><select class="form-select" id="report-period" name="period"><?php foreach ($periodOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="report-date">Date within period</label><input class="form-control" id="report-date" type="date" name="date" value="<?= e($anchorDate->format('Y-m-d')) ?>" required></div>
        <?php if (!$branchRole): ?><div class="col-sm-6 col-lg-2"><label class="form-label" for="report-branch">Branch</label><select class="form-select" id="report-branch" name="branch"><option value="">All branches</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="report-course">Course / class</label><select class="form-select" id="report-course" name="course"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>" <?= $courseFilter === (int)$course['id'] ? 'selected' : '' ?>><?= e($course['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="report-method">Payment method</label><select class="form-select" id="report-method" name="method"><option value="">All methods</option><?php foreach ($methodOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $methodFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><button class="btn btn-primary w-100">Apply filters</button></div>
    </form>
    <p class="report-filter-note mb-0">Payment method filters apply to collections and payment details; period-end balances include all methods. Balances use current student fee totals and payments through the period end because historical fee changes are not recorded.</p>
</section>

<section class="mb-4" aria-labelledby="report-highlights-heading">
    <div class="report-section-heading">
        <div>
            <div class="portal-eyebrow">At a glance</div>
            <h2 class="h4 mb-0" id="report-highlights-heading">Key results</h2>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($headlineStats as [$label, $value, $tone]): ?>
            <div class="col-sm-6 col-xl-3">
                <article class="card report-stat-card report-stat-card--<?= e($tone) ?>">
                    <div class="report-stat-label"><?= e($label) ?></div>
                    <div class="report-stat-value"><?= e($value) ?></div>
                    <div class="report-stat-period"><?= e($periodOptions[$period]) ?> report</div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card report-support-card mb-4" aria-labelledby="report-support-heading">
    <div class="report-section-heading">
        <div>
            <div class="portal-eyebrow">Supporting metrics</div>
            <h2 class="h5 mb-0" id="report-support-heading">Enrollment &amp; payment details</h2>
        </div>
    </div>
    <div class="row g-3">
        <?php foreach ($supportingStats as [$label, $value]): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="report-support-stat">
                    <div class="report-stat-label"><?= e($label) ?></div>
                    <div class="report-support-value"><?= e($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <section class="card report-panel h-100" aria-labelledby="collections-method-heading">
            <div class="report-panel-heading"><div><div class="portal-eyebrow">Collections</div><h2 class="h5 mb-0" id="collections-method-heading">By payment method</h2></div></div>
            <div class="table-responsive"><table class="table report-table"><thead><tr><th>Method</th><th>Transactions</th><th>Collected (KES)</th></tr></thead><tbody><?php foreach ($methodRows as $row): ?><tr><td><?= e($methodOptions[$row['payment_method']] ?? $row['payment_method']) ?></td><td><?= (int)$row['transactions'] ?></td><td><?= number_format((float)$row['amount'], 2) ?></td></tr><?php endforeach; ?><?php if (!$methodRows): ?><tr><td colspan="3" class="report-empty-state">No payments in this period.</td></tr><?php endif; ?></tbody></table></div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="card report-panel h-100" aria-labelledby="course-performance-heading">
            <div class="report-panel-heading"><div><div class="portal-eyebrow">Enrollment mix</div><h2 class="h5 mb-0" id="course-performance-heading">By course / class</h2></div></div>
            <div class="table-responsive"><table class="table report-table"><thead><tr><th>Course</th><th>Registrations</th><th>New fees (KES)</th><th>Collected (KES)</th></tr></thead><tbody><?php foreach ($courseRows as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= (int)$row['registrations'] ?></td><td><?= number_format($row['fees_billed'], 2) ?></td><td><?= number_format($row['collected'], 2) ?></td></tr><?php endforeach; ?><?php if (!$courseRows): ?><tr><td colspan="4" class="report-empty-state">No course activity in this period.</td></tr><?php endif; ?></tbody></table></div>
        </section>
    </div>
</div>

<section class="card report-status-panel mb-4" aria-labelledby="enquiry-status-heading">
    <div class="report-panel-heading">
        <div><div class="portal-eyebrow">Enquiries</div><h2 class="h5 mb-0" id="enquiry-status-heading">Current status</h2></div>
        <span class="report-count"><?= number_format((int)$enquirySummary['enquiries']) ?> total</span>
    </div>
    <p class="small text-muted mb-3">Enquiries created in this period, grouped by their current status.</p>
    <div class="row g-3">
        <?php foreach ([['New', (int)$enquirySummary['new_count'], 'new'], ['Contacted', (int)$enquirySummary['contacted_count'], 'contacted'], ['Closed', (int)$enquirySummary['closed_count'], 'closed']] as [$label, $count, $status]): ?>
            <div class="col-4"><div class="report-status-card report-status-card--<?= e($status) ?>"><span><?= e($label) ?></span><strong><?= $count ?></strong></div></div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($canManageEnquiries): ?>
<details class="card report-enquiry-entry mb-4 no-print">
    <summary class="fw-bold">Record a new enquiry</summary>
    <form method="post" class="row g-3 mt-1">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_enquiry">
        <input type="hidden" name="period" value="<?= e($period) ?>"><input type="hidden" name="date" value="<?= e($anchorDate->format('Y-m-d')) ?>">
        <?php if ($branchFilter !== null): ?><input type="hidden" name="branch" value="<?= (int)$branchFilter ?>"><?php endif; ?>
        <?php if ($courseFilter !== null): ?><input type="hidden" name="course" value="<?= (int)$courseFilter ?>"><?php endif; ?>
        <?php if ($methodFilter !== ''): ?><input type="hidden" name="method" value="<?= e($methodFilter) ?>"><?php endif; ?>
        <div class="col-md-4"><label class="form-label" for="prospect-name">Prospect name</label><input class="form-control" id="prospect-name" name="prospect_name" maxlength="190" required></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-phone">Phone</label><input class="form-control" id="enquiry-phone" name="phone_number" maxlength="30"></div>
        <div class="col-md-4"><label class="form-label" for="enquiry-email">Email</label><input class="form-control" id="enquiry-email" name="email" type="email" maxlength="190"></div>
        <?php if (!$branchRole): ?><div class="col-md-4"><label class="form-label" for="enquiry-branch">Branch</label><select class="form-select" id="enquiry-branch" name="branch_id" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"><?= e($branch['name']) ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="branch_id" value="<?= (int)$assignedBranchId ?>"><?php endif; ?>
        <div class="col-md-4"><label class="form-label" for="enquiry-course">Interested course</label><select class="form-select" id="enquiry-course" name="course_id"><option value="">Not specified</option><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= e($course['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label" for="enquiry-notes">Notes</label><input class="form-control" id="enquiry-notes" name="notes" maxlength="500"></div>
        <div class="col-12"><button class="btn btn-primary">Save enquiry</button></div>
    </form>
</details>
<?php endif; ?>

<?php if ($role !== 'director'): ?>
<div class="card report-panel mb-4"><div class="report-panel-heading"><div><div class="portal-eyebrow">Lead management</div><h2 class="h5 mb-0">Enquiries in period</h2></div><span class="report-count"><?= count($enquiryRows) ?> records</span></div><div class="table-responsive"><table class="table report-table"><thead><tr><th>Date</th><th>Prospect</th><th>Contact</th><th>Branch</th><th>Course</th><th>Source</th><th>Status</th><th>Notes</th><?php if ($canManageEnquiries): ?><th class="no-print">Update</th><?php endif; ?></tr></thead><tbody><?php foreach ($enquiryRows as $row): ?><tr><td><?= e($row['created_at']) ?></td><td class="fw-semibold"><?= e($row['prospect_name']) ?></td><td><?= e($row['phone_number'] ?? '—') ?><div class="small text-muted"><?= e($row['email'] ?? '') ?></div></td><td><?= e($row['branch_name'] ?? '—') ?></td><td><?= e($row['course_name'] ?? '—') ?></td><td><span class="report-source report-source--<?= e($row['source']) ?>"><?= e(ucfirst($row['source'])) ?></span></td><td><span class="report-status report-status--<?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td><td><?= e($row['notes'] ?? '—') ?></td><?php if ($canManageEnquiries): ?><td class="no-print"><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="update_enquiry_status"><input type="hidden" name="enquiry_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="period" value="<?= e($period) ?>"><input type="hidden" name="date" value="<?= e($anchorDate->format('Y-m-d')) ?>"><?php if ($branchFilter !== null): ?><input type="hidden" name="branch" value="<?= (int)$branchFilter ?>"><?php endif; ?><?php if ($courseFilter !== null): ?><input type="hidden" name="course" value="<?= (int)$courseFilter ?>"><?php endif; ?><?php if ($methodFilter !== ''): ?><input type="hidden" name="method" value="<?= e($methodFilter) ?>"><?php endif; ?><select class="form-select form-select-sm" name="status" aria-label="Enquiry status"><?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $key => $label): ?><option value="<?= e($key) ?>" <?= $row['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Save</button></form></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$enquiryRows): ?><tr><td colspan="<?= $canManageEnquiries ? '9' : '8' ?>" class="report-empty-state">No enquiries in this period.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($role !== 'director'): ?>
<div class="card report-panel mb-4"><div class="report-panel-heading"><div><div class="portal-eyebrow">Admissions</div><h2 class="h5 mb-0">Enrollments in period</h2></div><span class="report-count"><?= count($enrollmentRows) ?> records</span></div><div class="table-responsive"><table class="table report-table"><thead><tr><th>Date</th><th>Admission</th><th>Student</th><th>Course</th><th>Branch</th><th>Fees billed (KES)</th></tr></thead><tbody><?php foreach ($enrollmentRows as $row): ?><tr><td><?= e($row['enrollment_date']) ?></td><td><?= e($row['admission_number'] ?? '—') ?></td><td class="fw-semibold"><?= e($row['holder_name']) ?></td><td><?= e($row['course_name']) ?></td><td><?= e($row['branch_name'] ?? '—') ?></td><td><?= number_format((float)$row['total_fee'], 2) ?></td></tr><?php endforeach; ?><?php if (!$enrollmentRows): ?><tr><td colspan="6" class="report-empty-state">No enrollments in this period.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card report-panel"><div class="report-panel-heading"><div><div class="portal-eyebrow">Cash flow</div><h2 class="h5 mb-0">Payments in period</h2></div><span class="report-count"><?= count($paymentRows) ?> records</span></div><div class="table-responsive"><table class="table report-table"><thead><tr><th>Date</th><th>Student / admission</th><th>Course</th><th>Branch</th><th>Method</th><th>Reference</th><th>Amount (KES)</th><th class="no-print">Receipt</th></tr></thead><tbody><?php foreach ($paymentRows as $row): ?><tr><td><?= e($row['paid_at']) ?></td><td class="fw-semibold"><?= e($row['holder_name']) ?><div class="small text-muted fw-normal"><?= e($row['admission_number'] ?? '—') ?></div></td><td><?= e($row['course_name']) ?></td><td><?= e($row['branch_name'] ?? '—') ?></td><td><?= e($methodOptions[$row['payment_method']] ?? $row['payment_method']) ?></td><td><?= e($row['reference'] ?? '—') ?></td><td><?= number_format((float)$row['amount'], 2) ?></td><td class="no-print"><a class="btn btn-sm btn-outline-primary" href="receipt.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">Receipt</a></td></tr><?php endforeach; ?><?php if (!$paymentRows): ?><tr><td colspan="8" class="report-empty-state">No payments in this period.</td></tr><?php endif; ?></tbody></table></div></div>
<?php else: ?>
<div class="alert alert-info">Individual enquiry, enrollment, and payment details are restricted for director accounts; the CSV export contains aggregate summaries only.</div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
