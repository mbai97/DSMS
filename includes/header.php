<?php
$title = $title ?? $config['app_name'];
$mainWebsiteUrl = $config['main_website_url'] ?? 'https://beckynds.co.ke';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="BeckynDS institute administration, course enquiries, and certificate portal">
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
                <span class="brand-caption">Institute Admin Portal</span>
            </span>
        </a>
        <?php if (admin_logged_in()): ?>
            <div class="d-flex align-items-center gap-2 app-nav-actions">
                <?php $navRole = current_admin_role(); ?>
                <?php if (in_array($navRole, ['hr_manager', 'hr_officer'], true)): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/hr-dashboard.php')) ?>">People dashboard</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/staff.php')) ?>">Staff</a>
                <?php elseif ($navRole === 'accountant'): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/accounting-dashboard.php')) ?>">Accounting dashboard</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/reports.php')) ?>">Reports</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/fees.php')) ?>">Student fees</a>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/dashboard.php')) ?>">Dashboard</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/reports.php')) ?>">Reports</a>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Operations</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/certificates.php')) ?>">Certificates</a></li>
                            <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/students.php')) ?>">Students</a></li>
                            <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/fees.php')) ?>">Fees &amp; payments</a></li>
                            <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/fleet.php')) ?>">Fleet</a></li>
                            <?php if ($navRole === 'admin'): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/courses.php')) ?>">Course catalog</a></li><?php endif; ?>
                        </ul>
                    </div>
                    <?php if ($navRole === 'admin'): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Administration</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/hr-dashboard.php')) ?>">HR dashboard</a></li>
                                <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/staff.php')) ?>">Staff</a></li>
                                <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/report-settings.php')) ?>">Report settings</a></li>
                                <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/branding.php')) ?>">Branding</a></li>
                                <li><a class="dropdown-item" href="<?= e(app_url($config, 'admin/users.php')) ?>">Users &amp; access</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (in_array($navRole, ['admin', 'director'], true)): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, 'admin/accounting-dashboard.php')) ?>">Accounting</a>
                    <?php endif; ?>
                    <?php if (in_array($navRole, ['admin', 'manager', 'director'], true)): ?>
                        <a class="btn btn-sm btn-primary" href="<?= e(app_url($config, 'admin/create-certificate.php')) ?>">Issue certificate</a>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="badge text-bg-light"><?= e(strtoupper($navRole)) ?></span>
                <form method="post" action="<?= e(app_url($config, 'admin/logout.php')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Sign out</button>
                </form>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($mainWebsiteUrl) ?>" target="_blank" rel="noopener noreferrer">Main website</a>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center gap-2 app-nav-actions">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, '#programs')) ?>">Courses</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url($config, '#enquiry')) ?>">Enquiries</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($mainWebsiteUrl) ?>" target="_blank" rel="noopener noreferrer">Main website</a>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-5">
