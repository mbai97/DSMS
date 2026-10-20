<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = trim((string)($_POST['role'] ?? 'admin'));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!in_array($role, ['admin', 'director', 'manager', 'editor'], true)) {
            $role = 'admin';
        }

        if (!$errors) {
            $stmt = $db->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'An admin with this email already exists.';
            } else {
                $stmt = $db->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password), $role]);
                $success = 'User created successfully.';
            }
        }
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id > 0 && $id !== (int)$_SESSION['admin_id']) {
            $stmt = $db->prepare('DELETE FROM admins WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'User deleted.';
        }
    }

    if ($action === 'update_role') {
        $id = (int)($_POST['user_id'] ?? 0);
        $role = trim((string)($_POST['role'] ?? 'admin'));
        if (!in_array($role, ['admin', 'director', 'manager', 'editor'], true)) {
            $role = 'admin';
        }
        if ($id > 0 && $id !== (int)$_SESSION['admin_id']) {
            $stmt = $db->prepare('UPDATE admins SET role = ? WHERE id = ?');
            $stmt->execute([$role, $id]);
            $success = 'User role updated.';
        }
    }
}

$users = $db->query('SELECT id, name, email, role FROM admins ORDER BY id DESC')->fetchAll();

$title = 'User Management';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Registry</div>
        <h1 class="fw-bold mb-1">User Management</h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <h5 class="fw-bold mb-3">Create New User</h5>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <div class="row">
            <div class="col-md-6">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Role</label>
                <select name="role" class="form-select" required>
                    <option value="admin">Admin</option>
                    <option value="director">Director</option>
                    <option value="manager">Manager</option>
                    <option value="editor">Editor</option>
                </select>
            </div>
        </div>
        <button class="btn btn-primary mt-3">Create User</button>
    </form>
</div>

<div class="card p-4">
    <h5 class="fw-bold mb-3">Users</h5>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= e($user['name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td>
                                <?php if ((int)$user['id'] === (int)$_SESSION['admin_id']): ?>
                                    <span class="badge text-bg-primary"><?= e(strtoupper($user['role'])) ?></span>
                                <?php else: ?>
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <select name="role" class="form-select form-select-sm" style="display:inline; width:auto;" onchange="this.form.submit();">
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            <option value="director" <?= $user['role'] === 'director' ? 'selected' : '' ?>>Director</option>
                                            <option value="manager" <?= $user['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                            <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$user['id'] !== (int)$_SESSION['admin_id']): ?>
                                    <form method="post" onsubmit="return confirm('Delete this user?');" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
