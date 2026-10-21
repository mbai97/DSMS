<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin');

$errors = [];
$success = '';
$formName = '';
$formEmail = '';
$formRole = 'admin';
$formBranchId = '';
$branches = $db->query('SELECT id, name FROM branches ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $role = is_string($_POST['role'] ?? null) ? trim($_POST['role']) : '';
        $branchId = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $formName = $name;
        $formEmail = $email;
        $formRole = $role;
        $formBranchId = is_string($_POST['branch_id'] ?? null) ? $_POST['branch_id'] : '';

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Name is required and must be 120 characters or fewer.';
        }
        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address of 190 characters or fewer.';
        }
        if (strlen($password) < 8 || strlen($password) > 4096) {
            $errors[] = 'Password must be between 8 and 4,096 characters.';
        }
        if (!in_array($role, ['admin', 'manager', 'director', 'branch'], true)) {
            $errors[] = 'Select a valid role.';
        }
        if ($role === 'branch' && ($branchId === false || $branchId === null || $branchId < 1)) {
            $errors[] = 'Select a branch for this user.';
        }
        if ($role !== 'branch') {
            $branchId = null;
        } elseif (!$errors) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            if ((int)$stmt->fetchColumn() !== 1) {
                $errors[] = 'Select a valid branch.';
            }
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('INSERT INTO admins (name, email, password_hash, role, branch_id) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $branchId]);
                $success = 'User created successfully.';
                $formName = '';
                $formEmail = '';
                $formRole = 'admin';
                $formBranchId = '';
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
        $role = is_string($_POST['role'] ?? null) ? trim($_POST['role']) : '';
        $branchId = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($role, ['admin', 'manager', 'director', 'branch'], true)) {
            $errors[] = 'Select a valid role.';
        } elseif ($role === 'branch' && ($branchId === false || $branchId === null || $branchId < 1)) {
            $errors[] = 'Select a branch for this user.';
        } elseif ($role === 'branch') {
            $stmt = $db->prepare('SELECT COUNT(*) FROM branches WHERE id = ?');
            $stmt->execute([$branchId]);
            if ((int)$stmt->fetchColumn() !== 1) {
                $errors[] = 'Select a valid branch.';
            }
        } else {
            $branchId = null;
        }
        if ($id > 0 && $id !== (int)$_SESSION['admin_id']) {
            if (!$errors) {
                $stmt = $db->prepare('UPDATE admins SET role = ?, branch_id = ? WHERE id = ?');
                $stmt->execute([$role, $branchId, $id]);
                $success = 'User role and branch updated.';
            }
        }
    }
}

$users = $db->query('SELECT a.id, a.name, a.email, a.role, a.branch_id, b.name AS branch_name FROM admins a LEFT JOIN branches b ON b.id = a.branch_id ORDER BY a.id DESC')->fetchAll();

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
                    <option value="manager" <?= $formRole === 'manager' ? 'selected' : '' ?>>Manager (read-only)</option>
                    <option value="director" <?= $formRole === 'director' ? 'selected' : '' ?>>Director</option>
                    <option value="branch" <?= $formRole === 'branch' ? 'selected' : '' ?>>Branch</option>
                </select>
            </div>
            <div class="col-md-6 mt-3">
                <label class="form-label">Assigned Branch <span class="text-muted fw-normal">(required for Branch role)</span></label>
                <select name="branch_id" class="form-select">
                    <option value="">No branch</option>
                    <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= $formBranchId === (string)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?>
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
                    <th>Branch</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No users found.</td>
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
                                        <select name="role" class="form-select form-select-sm" style="display:inline; width:auto;">
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            <option value="manager" <?= $user['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                                            <option value="director" <?= $user['role'] === 'director' ? 'selected' : '' ?>>Director</option>
                                            <option value="branch" <?= $user['role'] === 'branch' ? 'selected' : '' ?>>Branch</option>
                                        </select>
                                        <select name="branch_id" class="form-select form-select-sm mt-1" style="width:auto;">
                                            <option value="">No branch</option>
                                            <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>" <?= (int)$user['branch_id'] === (int)$branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary mt-1">Save role</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?= e($user['branch_name'] ?? '—') ?></td>
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
