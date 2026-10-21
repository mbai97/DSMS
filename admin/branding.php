<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_roles('admin');

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_director') {
        $directorName = trim((string)($_POST['director_name'] ?? ''));
        $directorTitle = trim((string)($_POST['director_title'] ?? ''));
        if ($directorName === '' || mb_strlen($directorName) > 120) {
            $errors[] = 'Enter the director name (120 characters or fewer).';
        }
        if ($directorTitle === '' || mb_strlen($directorTitle) > 80) {
            $errors[] = 'Enter the director title (80 characters or fewer).';
        }
        if (!$errors) {
            update_setting($db, 'director_name', $directorName);
            update_setting($db, 'director_title', $directorTitle);
            $success = 'Director details saved.';
        }
    }

    if ($action === 'update_colors') {
        $brandColor = trim((string)($_POST['brand_color'] ?? '#1ca7d9'));
        $accentColor = trim((string)($_POST['accent_color'] ?? '#d7265b'));
        $goldColor = trim((string)($_POST['gold_color'] ?? '#d4af37'));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
            $errors[] = 'Brand color must be a valid hex color.';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
            $errors[] = 'Accent color must be a valid hex color.';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $goldColor)) {
            $errors[] = 'Gold color must be a valid hex color.';
        }

        if (!$errors) {
            update_setting($db, 'brand_color', $brandColor);
            update_setting($db, 'accent_color', $accentColor);
            update_setting($db, 'gold_color', $goldColor);
            $success = 'Certificate colors updated successfully.';
        }
    }

    if ($action === 'update_signature') {
        $label = trim((string)($_POST['signature_label'] ?? 'Authorized Signatory'));
        $file = $_FILES['signature_image'] ?? null;

        if ($label === '') {
            $errors[] = 'Signature label is required.';
        }

        if (!$errors && is_array($file) && $file['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $errors[] = 'Signature must be an image file (PNG, JPG, GIF).';
            } else if ($file['size'] > 1048576) {
                $errors[] = 'Signature image must be less than 1MB.';
            } else {
                $sigDir = storage_path('signatures');
                if (!is_dir($sigDir)) {
                    mkdir($sigDir, 0755, true);
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $safeFile = 'signature_' . time() . '.' . $ext;
                $sigPath = $sigDir . '/' . $safeFile;

                if (move_uploaded_file($file['tmp_name'], $sigPath)) {
                    update_setting($db, 'signature_file', $safeFile);
                    update_setting($db, 'signature_label', $label);
                    $success = 'Signature updated successfully.';
                } else {
                    $errors[] = 'Failed to upload signature image.';
                }
            }
        } else if (!$errors) {
            update_setting($db, 'signature_label', $label);
            $success = 'Signature label updated.';
        }
    }
}

$brandColor = setting($db, 'brand_color', '#1ca7d9');
$accentColor = setting($db, 'accent_color', '#d7265b');
$goldColor = setting($db, 'gold_color', '#d4af37');
$signatureLabel = setting($db, 'signature_label', 'Authorized Signatory');
$signatureFile = setting($db, 'signature_file', '');
$directorName = setting($db, 'director_name', '');
$directorTitle = setting($db, 'director_title', 'School Director');

$title = 'Certificate Branding';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="portal-eyebrow">BeckynDS Registry</div>
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

<div class="row">
    <div class="col-md-6">
        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3">Director Details</h5>
            <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_director">
                <div class="col-md-6"><label class="form-label">Director Name</label><input class="form-control" name="director_name" maxlength="120" value="<?= e($directorName) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Director Title</label><input class="form-control" name="director_title" maxlength="80" value="<?= e($directorTitle) ?>" required></div>
                <div class="col-12"><button class="btn btn-primary">Save Director Details</button></div>
            </form>
        </div>

        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3">Certificate Colors</h5>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_colors">
                <div class="mb-3">
                    <label class="form-label">Brand Color (Primary)</label>
                    <div class="input-group">
                        <input type="color" name="brand_color" class="form-control form-control-color" value="<?= e($brandColor) ?>" style="max-width:60px;">
                        <input type="text" class="form-control" value="<?= e($brandColor) ?>" readonly>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Accent Color (Secondary)</label>
                    <div class="input-group">
                        <input type="color" name="accent_color" class="form-control form-control-color" value="<?= e($accentColor) ?>" style="max-width:60px;">
                        <input type="text" class="form-control" value="<?= e($accentColor) ?>" readonly>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Gold Color (Highlights)</label>
                    <div class="input-group">
                        <input type="color" name="gold_color" class="form-control form-control-color" value="<?= e($goldColor) ?>" style="max-width:60px;">
                        <input type="text" class="form-control" value="<?= e($goldColor) ?>" readonly>
                    </div>
                </div>
                <button class="btn btn-primary">Update Colors</button>
            </form>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card p-4 mb-4">
            <h5 class="fw-bold mb-3">Color Preview</h5>
            <div class="row mb-3">
                <div class="col-4">
                    <div class="p-4 rounded" style="background-color: <?= e($brandColor) ?>; border: 1px solid #ddd;"></div>
                    <p class="text-center small mt-2">Brand</p>
                </div>
                <div class="col-4">
                    <div class="p-4 rounded" style="background-color: <?= e($accentColor) ?>; border: 1px solid #ddd;"></div>
                    <p class="text-center small mt-2">Accent</p>
                </div>
                <div class="col-4">
                    <div class="p-4 rounded" style="background-color: <?= e($goldColor) ?>; border: 1px solid #ddd;"></div>
                    <p class="text-center small mt-2">Gold</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card p-4 mb-4">
    <h5 class="fw-bold mb-3">Certificate Signature</h5>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_signature">
        <div class="row">
            <div class="col-md-6">
                <label class="form-label">Signature Label</label>
                <input type="text" name="signature_label" class="form-control" value="<?= e($signatureLabel) ?>" required>
                <small class="text-muted">E.g., "Authorized Signatory", "Director", etc.</small>
            </div>
            <div class="col-md-6">
                <label class="form-label">Signature Image (Optional)</label>
                <input type="file" name="signature_image" class="form-control" accept="image/*">
                <small class="text-muted">Upload a PNG, JPG, or GIF (max 1MB)</small>
            </div>
        </div>
        <?php if ($signatureFile): ?>
            <div class="alert alert-info mt-3">
                Current signature image: <?= e($signatureFile) ?>
            </div>
        <?php endif; ?>
        <button class="btn btn-primary mt-3">Update Signature</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
