<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$branches = $db->query('SELECT name FROM branches ORDER BY name')->fetchAll();
$courses = $db->query('SELECT name, duration_days FROM courses WHERE active = 1 ORDER BY name')->fetchAll();
$title = $config['issuer_name'] ?? 'Beckyn Driving & Mechanical School';
require __DIR__ . '/../includes/header.php';
?>
<section class="row align-items-center g-4 mb-5">
    <div class="col-lg-7">
        <div class="portal-eyebrow">Welcome to BeckynDS</div>
        <h1 class="display-4 fw-bold mb-3"><?= e($config['issuer_name'] ?? 'Beckyn Driving & Mechanical School') ?></h1>
        <p class="lead text-muted">Practical training for confident drivers, skilled professionals, and safer communities.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <a class="btn btn-primary btn-lg" href="#programs">Explore programs</a>
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
    <div class="mb-3"><div class="portal-eyebrow">Our training</div><h2 class="fw-bold">Programs</h2></div>
    <div class="row g-3">
        <?php foreach ($courses as $course): ?>
            <div class="col-sm-6 col-lg-3"><div class="card p-4 h-100">
                <h3 class="h5 fw-bold"><?= e($course['name']) ?></h3>
                <p class="text-muted mb-0"><?= $course['duration_days'] ? e((string)$course['duration_days']) . ' days' : 'Contact us for course duration and enrollment details.' ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>
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
