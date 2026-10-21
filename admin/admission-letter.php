<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin', 'manager', 'director', 'branch');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('A valid student ID is required.');
}

$stmt = $db->prepare(
    'SELECT s.*, b.name AS branch_name, c.name AS program_name,
            COALESCE(p.paid, 0) AS amount_paid
     FROM students s
     LEFT JOIN branches b ON b.id = s.branch_id
     LEFT JOIN courses c ON c.id = s.course_id
     LEFT JOIN (SELECT student_id, SUM(amount) AS paid FROM student_payments GROUP BY student_id) p ON p.student_id = s.id
     WHERE s.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}
require_branch_record($student['branch_id'] === null ? null : (int)$student['branch_id']);

$directorName = setting($db, 'director_name');
$directorTitle = setting($db, 'director_title', 'School Director');
$signatureFile = setting($db, 'signature_file');
$signatureUrl = $signatureFile !== '' && is_file(__DIR__ . '/../storage/signatures/' . basename($signatureFile))
    ? app_url($config, 'storage/signatures/' . basename($signatureFile))
    : '';
$expectedCompletion = $student['course_duration_days']
    ? date('Y-m-d', strtotime($student['enrollment_date'] . ' +' . (int)$student['course_duration_days'] . ' days'))
    : null;

$title = 'Admission Letter ' . ($student['admission_number'] ?? '');
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-end gap-2 mb-3 d-print-none">
    <a class="btn btn-outline-secondary" href="students.php">Back to students</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Print admission letter</button>
</div>
<article class="card p-5 mx-auto" style="max-width:900px; min-height:900px">
    <header class="text-center border-bottom pb-3 mb-4">
        <div class="portal-eyebrow"><?= e($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School') ?></div>
        <h1 class="fw-bold">ADMISSION LETTER</h1>
        <p class="mb-0">Training • Safety • Excellence</p>
    </header>
    <div class="d-flex justify-content-between mb-4">
        <span><strong>Admission No:</strong> <?= e($student['admission_number'] ?? $student['learner_id'] ?? 'Pending') ?></span>
        <span><strong>Date:</strong> <?= e(format_date(date('Y-m-d'))) ?></span>
    </div>
    <p>Dear <strong><?= e($student['holder_name']) ?></strong>,</p>
    <p>We are pleased to confirm your admission to <strong><?= e($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School') ?></strong>. Your enrollment details are as follows:</p>
    <div class="table-responsive"><table class="table table-bordered">
        <tbody>
            <tr><th>Student</th><td><?= e($student['holder_name']) ?></td></tr>
            <tr><th>Admission number</th><td><?= e($student['admission_number'] ?? $student['learner_id'] ?? 'Pending') ?></td></tr>
            <tr><th>Program</th><td><?= e($student['program_name'] ?? $student['course_name']) ?></td></tr>
            <tr><th>Branch</th><td><?= e($student['branch_name'] ?? 'Not assigned') ?></td></tr>
            <tr><th>Enrollment date</th><td><?= e(format_date($student['enrollment_date'])) ?></td></tr>
            <tr><th>Course duration</th><td><?= $student['course_duration_days'] ? (int)$student['course_duration_days'] . ' days' : 'To be confirmed' ?></td></tr>
            <tr><th>Expected completion</th><td><?= $expectedCompletion ? e(format_date($expectedCompletion)) : 'To be confirmed' ?></td></tr>
            <tr><th>Course fee</th><td>KES <?= number_format((float)$student['total_fee'], 2) ?></td></tr>
            <tr><th>Balance due</th><td>KES <?= number_format(max(0, (float)$student['total_fee'] - (float)$student['amount_paid']), 2) ?></td></tr>
        </tbody>
    </table></div>
    <p>Please report to your selected branch and contact the school administration if you need assistance with your training schedule or fee balance. We look forward to supporting your learning. Welcome!.</p>
    <div class="mt-auto pt-5">
        <?php if ($signatureUrl): ?><img src="<?= e($signatureUrl) ?>" alt="Director signature" style="max-width:220px; max-height:80px; display:block"><?php else: ?><div style="height:80px"></div><?php endif; ?>
        <div class="border-top pt-2" style="max-width:320px"><strong><?= e($directorName ?: 'Director') ?></strong><br><?= e($directorTitle) ?></div>
    </div>
</article>
<?php require __DIR__ . '/../includes/footer.php'; ?>
