<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (admin_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $stmt = $db->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        login_admin((int)$admin['id']);
        redirect('dashboard.php');
    }

    $error = 'Invalid email or password.';
}

$title = 'Admin Login';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card p-4 portal-login-card">
            <div class="portal-eyebrow">BeckynDS Admin Panel</div>
            <h2 class="fw-bold mb-1">Admin Login</h2>
            <p class="text-muted">Manage all administrative functions.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary w-100">Sign In</button>
                <button class="btn btn-secondary w-100"> Forgot Password</button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
