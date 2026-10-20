<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$errors = [];
$success = '';
$formName = '';
$formEmail = '';
$formRole = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $role = is_string($_POST['role'] ?? null) ? trim($_POST['role']) : '';
        $formName = $name;
        $formEmail = $email;
        $formRole = $role;

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Name is required and must be 120 characters or fewer.';
        }
        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address of 190 characters or fewer.';
        }
        if (strlen($password) < 8 || strlen($password) > 4096) {
            $errors[] = 'Password must be between 8 and 4,096 characters.';
        }
        if (!in_array($role, ['admin', 'director', 'manager', 'editor'], true)) {
            $errors[] = 'Select a valid role.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                $success = 'User created successfully.';
                $formName = '';
                $formEmail = '';
                $formRole = 'admin';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errors[] = 'An admin with this email already exists.';
                } else {
                    error_log('Admin user creation failed: ' . $exception->getMessage());
                    $errors[] = 'Unable to create the user. Please try again.';
                }
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
                <input type="text" name="name" maxlength="120" class="form-control" value="<?= e($formName) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" maxlength="190" class="form-control" value="<?= e($formEmail) ?>" required>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <input type="password" name="password" minlength="8" maxlength="4096" class="form-control" required autocomplete="new-password">
            </div>
            <div class="col-md-6">
                <label class="form-label">Role</label>
                <select name="role" class="form-select" required>
                    <option value="admin" <?= $formRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="director" <?= $formRole === 'director' ? 'selected' : '' ?>>Director</option>
                    <option value="manager" <?= $formRole === 'manager' ? 'selected' : '' ?>>Manager</option>
                    <option value="editor" <?= $formRole === 'editor' ? 'selected' : '' ?>>Editor</option>
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
