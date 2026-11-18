<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$errors = [];
$form = [
    'prospect_name' => '',
    'phone_number' => '',
    'email' => '',
    'branch_id' => '',
    'course_id' => '',
    'notes' => '',
];
$success = is_string($_SESSION['public_enquiry_success'] ?? null) ? $_SESSION['public_enquiry_success'] : '';
unset($_SESSION['public_enquiry_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($form as $key => $value) {
        $form[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }

    $name = $form['prospect_name'];
    $phone = $form['phone_number'];
    $email = strtolower($form['email']);
    $notes = $form['notes'];
    $branchId = filter_var($form['branch_id'], FILTER_VALIDATE_INT);
    $courseId = filter_var($form['course_id'], FILTER_VALIDATE_INT);
    $honeypot = is_string($_POST['company_website'] ?? null) ? trim($_POST['company_website']) : '';

    if ($name === '' || mb_strlen($name) > 190) {
        $errors[] = 'Enter your name (190 characters or fewer).';
    }
    if (mb_strlen($phone) > 30 || ($phone === '' && $email === '')) {
        $errors[] = 'Enter a phone number or email address; phone numbers must be 30 characters or fewer.';
    }
    if (mb_strlen($email) > 190 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'Enter a valid email address of 190 characters or fewer.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Message must be 500 characters or fewer.';
    }
    if ($branchId === false || $branchId < 1) {
        $errors[] = 'Select a branch.';
    }
    if ($courseId === false || $courseId < 1) {
        $errors[] = 'Select a course.';
    }

    $lastSubmission = (int)($_SESSION['public_enquiry_last_submit'] ?? 0);
    if ($lastSubmission > 0 && time() - $lastSubmission < 30) {
        $errors[] = 'Please wait a moment before submitting another enquiry.';
    }

    if (!$errors && $honeypot === '') {
        $stmt = $db->prepare('SELECT 1 FROM branches WHERE id = ?');
        $stmt->execute([$branchId]);
        $branchExists = (bool)$stmt->fetchColumn();
        $stmt = $db->prepare('SELECT 1 FROM courses WHERE id = ? AND active = 1');
        $stmt->execute([$courseId]);
        $courseExists = (bool)$stmt->fetchColumn();
        if (!$branchExists || !$courseExists) {
            $errors[] = 'Select a valid branch and course.';
        }
    }

    if (!$errors) {
        if ($honeypot === '') {
            $stmt = $db->prepare(
                "INSERT INTO enquiries (prospect_name, phone_number, email, branch_id, course_id, notes, status, source)
                 VALUES (?, ?, ?, ?, ?, ?, 'new', 'public')"
            );
            $stmt->execute([
                $name,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $branchId,
                $courseId,
                $notes !== '' ? $notes : null,
            ]);
        }
        $_SESSION['public_enquiry_last_submit'] = time();
        $_SESSION['public_enquiry_success'] = 'Thank you. Your enquiry has been sent to the BeckynDS team.';
        redirect(app_url($config, '#enquiry'));
    }
}

$branches = $db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll();
$courses = $db->query('SELECT id, name, duration_days FROM courses WHERE active = 1 ORDER BY name')->fetchAll();
$title = $config['app_name'] ?? ($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School');
require __DIR__ . '/../includes/header.php';
?>
<section class="row align-items-center g-4 mb-5">
    <div class="col-lg-7">
        <div class="portal-eyebrow">Welcome to BeckynDS</div>
        <h1 class="display-4 fw-bold mb-3"><?= e($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School') ?></h1>
        <p class="lead text-muted">Practical training for confident drivers, skilled professionals, and safer communities.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <a class="btn btn-primary btn-lg" href="#programs">Explore programs</a>
            <a class="btn btn-outline-primary btn-lg" href="#enquiry">Send an enquiry</a>
            <a class="btn btn-outline-primary btn-lg" href="<?= e(app_url($config, 'admin/login.php')) ?>">Staff sign in</a>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4">
            <div class="portal-eyebrow">Certificate Registry</div>
            <h2 class="h4 fw-bold">Verify a certificate</h2>
            <p class="text-muted">Enter a certificate number to confirm its registry status.</p>
            <form action="<?= e(app_url($config, 'public/index.php')) ?>" method="get">
                <label class="form-label" for="certificate-number">Certificate number</label>
                <div class="input-group">
                    <input id="certificate-number" class="form-control" name="number" placeholder="e.g. BKDS-2026-000001" required>
                    <button class="btn btn-primary">Verify</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section id="programs" class="mb-5">
    <div class="mb-3"><div class="portal-eyebrow">Our training</div><h2 class="fw-bold">Courses &amp; classes</h2><p class="text-muted mb-0">Browse our active courses and choose one when sending an enquiry.</p></div>
    <div class="row g-3">
        <?php foreach ($courses as $course): ?>
            <div class="col-sm-6 col-lg-3"><div class="card p-4 h-100">
                <h3 class="h5 fw-bold"><?= e($course['name']) ?></h3>
                <p class="text-muted mb-0"><?= $course['duration_days'] ? e((string)$course['duration_days']) . ' days' : 'Contact us for course duration and enrollment details.' ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>
</section>

<section id="enquiry" class="card p-4 mb-5">
    <div class="mb-3"><div class="portal-eyebrow">Get started</div><h2 class="fw-bold">Send an enquiry</h2><p class="text-muted mb-0">Choose a branch and program. Our team will follow up using your contact details.</p></div>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <form method="post" class="row g-3">
        <?= csrf_field() ?>
        <div class="visually-hidden" aria-hidden="true"><label for="company-website">Leave this field empty</label><input id="company-website" type="text" name="company_website" tabindex="-1" autocomplete="off"></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-name">Your name</label><input class="form-control" id="enquiry-name" name="prospect_name" maxlength="190" value="<?= e($form['prospect_name']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-phone">Phone number</label><input class="form-control" id="enquiry-phone" name="phone_number" type="tel" maxlength="30" value="<?= e($form['phone_number']) ?>" placeholder="Phone or email is required"></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-email">Email address</label><input class="form-control" id="enquiry-email" name="email" type="email" maxlength="190" value="<?= e($form['email']) ?>" placeholder="Phone or email is required"></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-branch">Preferred branch</label><select class="form-select" id="enquiry-branch" name="branch_id" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $form['branch_id'] === (string)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="enquiry-course">Interested course / class</label><select class="form-select" id="enquiry-course" name="course_id" required><option value="">Select course</option><?php foreach ($courses as $course): ?><option value="<?= (int)$course['id'] ?>" <?= $form['course_id'] === (string)$course['id'] ? 'selected' : '' ?>><?= e($course['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="enquiry-notes">Message <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" id="enquiry-notes" name="notes" rows="3" maxlength="500"><?= e($form['notes']) ?></textarea></div>
        <div class="col-12"><button class="btn btn-primary">Send enquiry</button><span class="small text-muted ms-2">Your details will be used to respond to this enquiry.</span></div>
    </form>
</section>

<section class="card p-4">
    <div class="row g-4 align-items-center">
        <div class="col-md-5"><div class="portal-eyebrow">Find us</div><h2 class="h4 fw-bold mb-0">Our branches</h2></div>
        <div class="col-md-7"><div class="d-flex flex-wrap gap-2">
            <?php foreach ($branches as $branch): ?><span class="badge text-bg-light border px-3 py-2"><?= e($branch['name']) ?></span><?php endforeach; ?>
        </div></div>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
