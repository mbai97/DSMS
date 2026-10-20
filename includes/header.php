<?php
$title = $title ?? $config['app_name'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="Beckyn certificate verification portal">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(app_url($config, 'assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg app-navbar">
    <div class="container app-navbar-inner">
        <a class="navbar-brand fw-bold" href="<?= e(app_url($config, admin_logged_in() ? 'admin/dashboard.php' : '')) ?>">
            <span class="brand-mark" aria-hidden="true">B</span>
            <span class="brand-copy">
                <span class="brand-name">BeckynDS</span>
                <span class="brand-caption">Certificate Portal</span>
            </span>
        </a>
        <?php if (admin_logged_in()): ?>
            <div class="d-flex align-items-center gap-2 app-nav-actions">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/dashboard.php')) ?>">Dashboard</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/certificates.php')) ?>">Certificates</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/students.php')) ?>">Students</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/courses.php')) ?>">Courses</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/fees.php')) ?>">Fees</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/fleet.php')) ?>">Fleet</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/branding.php')) ?>">Branding</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/users.php')) ?>">Users</a>
                <a class="btn btn-sm btn-primary" href="<?= e(app_url($config, 'admin/create-certificate.php')) ?>">Issue Certificate</a>
                <form method="post" action="<?= e(app_url($config, 'admin/logout.php')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Sign out</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-5">
