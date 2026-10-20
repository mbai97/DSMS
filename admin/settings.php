<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $upload = $_FILES['signature_file'] ?? null;
    if (is_array($upload) && $upload['error'] === UPLOAD_ERR_OK && $upload['name'] !== '') {
        $targetDir = __DIR__ . '/../storage/signatures';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Only PNG, JPG, JPEG, GIF, or SVG signature files are allowed.';
        } else {
            $filename = 'signature-' . time() . '.' . $ext;
            $target = $targetDir . '/' . $filename;
            if (move_uploaded_file($upload['tmp_name'], $target)) {
                update_setting($db, 'signature_file', $filename);
                $success = 'Signature uploaded successfully.';
            } else {
                $errors[] = 'Unable to save signature image.';
            }
        }
    }

    foreach (['brand_color', 'accent_color', 'gold_color', 'signature_label'] as $key) {
        if (isset($_POST[$key])) {
            update_setting($db, $key, trim((string)$_POST[$key]));
        }
    }

    if (!$errors && $success === '') {
        $success = 'Settings saved.';
    }
}

$settings = [
    'brand_color' => setting($db, 'brand_color', '#1ca7d9'),
    'accent_color' => setting($db, 'accent_color', '#d7265b'),
    'gold_color' => setting($db, 'gold_color', '#d4af37'),
    'signature_label' => setting($db, 'signature_label', 'Head of Department'),
    'signature_file' => setting($db, 'signature_file', ''),
];

$title = 'Branding Settings';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">Branding</div>
        <h1 class="fw-bold mb-1">Certificate Branding</h1>
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

<div class="card p-4">
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="row">
            <div class="col-md-4">
                <label class="form-label">Brand Color</label>
                <input type="color" name="brand_color" class="form-control form-control-color" value="<?= e($settings['brand_color']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Accent Color</label>
                <input type="color" name="accent_color" class="form-control form-control-color" value="<?= e($settings['accent_color']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Gold Color</label>
                <input type="color" name="gold_color" class="form-control form-control-color" value="<?= e($settings['gold_color']) ?>">
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label">Signature Label</label>
                <input type="text" name="signature_label" class="form-control" value="<?= e($settings['signature_label']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Signature Image</label>
                <input type="file" name="signature_file" class="form-control" accept=".png,.jpg,.jpeg,.gif,.svg">
            </div>
        </div>

        <button class="btn btn-primary mt-3">Save Settings</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
