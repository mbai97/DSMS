<?php

declare(strict_types=1);

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_date(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('d M Y', $timestamp);
}

function storage_path(string $path = ''): string
{
    $base = __DIR__ . '/../storage';
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

function app_url(array $config, string $path = ''): string
{
    $base = $config['app_url'] ?? 'http://localhost';
    if ($path === '') {
        return $base;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
}

function csrf_field(): string
{
    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['_csrf']) . '">';
}

function setting(PDO $db, string $key, string $default = ''): string
{
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['setting_value'] : $default;
}

function update_setting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
    $stmt->execute([$key, $value, $value]);
}

function save_setting(PDO $db, string $key, string $value): void
{
    update_setting($db, $key, $value);
}

function ensure_storage(): void
{
    $dirs = ['storage', 'storage/certificates', 'storage/qrcodes', 'storage/signatures', 'storage/logs'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/../' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

function admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
}

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    if (!admin_logged_in()) {
        return $admin = null;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db instanceof PDO) {
        return $admin = null;
    }
    $stmt = $db->prepare('SELECT id, name, email, role, branch_id FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        unset($_SESSION['admin_id'], $_SESSION['admin_email']);
        return $admin = null;
    }
    return $admin = $row;
}

function current_admin_role(): string
{
    return (string)(current_admin()['role'] ?? '');
}

function current_admin_branch_id(): ?int
{
    $branchId = current_admin()['branch_id'] ?? null;
    return $branchId === null ? null : (int)$branchId;
}

function is_role(string $role): bool
{
    return current_admin_role() === $role;
}

function require_roles(string ...$roles): void
{
    require_admin();
    if (!in_array(current_admin_role(), $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function require_certificate_issuer(): void
{
    require_admin(true);
    if (!in_array(current_admin_role(), ['admin', 'manager', 'director'], true)) {
        http_response_code(403);
        exit('You do not have permission to issue certificates.');
    }
}

function require_branch_record(?int $branchId): void
{
    if (is_role('branch') && ($branchId === null || $branchId !== current_admin_branch_id())) {
        http_response_code(404);
        exit('Record not found.');
    }
}

function require_student_branch(PDO $db, int $studentId): void
{
    if (!is_role('branch')) {
        return;
    }
    $stmt = $db->prepare('SELECT branch_id FROM students WHERE id = ? LIMIT 1');
    $stmt->execute([$studentId]);
    $branchId = $stmt->fetchColumn();
    require_branch_record($branchId === false || $branchId === null ? null : (int)$branchId);
}

function require_admin(bool $allowCertificateIssue = false): void
{
    if (!admin_logged_in()) {
        redirect(app_url($GLOBALS['config'], 'admin/login.php'));
    }
    if (current_admin() === null) {
        redirect(app_url($GLOBALS['config'], 'admin/login.php'));
    }
    if (is_role('branch') && current_admin_branch_id() === null) {
        http_response_code(403);
        exit('This Branch account has no assigned branch. Ask an administrator to assign one.');
    }
    if (
        in_array(current_admin_role(), ['manager', 'director'], true)
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && !$allowCertificateIssue
    ) {
        http_response_code(403);
        exit('This account has read-only access.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function random_token(int $length = 64): string
{
    $bytes = random_bytes($length / 2);
    return bin2hex($bytes);
}

function next_certificate_number(PDO $db, string $prefix): string
{
    $year = date('Y');
    $stmt = $db->prepare(
        'SELECT certificate_number FROM certificates WHERE certificate_number LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$prefix . '-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $parts = explode('-', $last);
        $number = (int)end($parts) + 1;
    } else {
        $number = 1;
    }

    return sprintf('%s-%s-%06d', $prefix, $year, $number);
}

function next_admission_number(PDO $db, int $branchId, string $branchCode, int $courseId, string $courseCode): string
{
    if (!$db->inTransaction()) {
        throw new LogicException('Admission numbers must be generated inside the enrollment transaction.');
    }

    $year = date('Y');
    $sequence = $db->prepare(
        'INSERT INTO admission_sequences (branch_id, course_id, admission_year, last_number) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE last_number = last_number'
    );
    $sequence->execute([$branchId, $courseId, $year]);
    $sequence = $db->prepare(
        'SELECT last_number FROM admission_sequences WHERE branch_id = ? AND course_id = ? AND admission_year = ? FOR UPDATE'
    );
    $sequence->execute([$branchId, $courseId, $year]);
    $number = (int)$sequence->fetchColumn() + 1;
    $update = $db->prepare(
        'UPDATE admission_sequences SET last_number = ? WHERE branch_id = ? AND course_id = ? AND admission_year = ?'
    );
    $update->execute([$number, $branchId, $courseId, $year]);
    return sprintf('BKDS-%s-%s-%s-%04d', $branchCode, $courseCode, $year, $number);
}

function get_certificate_by_number(PDO $db, string $number): ?array
{
    $stmt = $db->prepare('SELECT * FROM certificates WHERE certificate_number = ? LIMIT 1');
    $stmt->execute([$number]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function get_certificate(PDO $db, string $token): ?array
{
    $stmt = $db->prepare('SELECT * FROM certificates WHERE verification_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function certificate_status(array $certificate): string
{
    if ($certificate['status'] === 'revoked') {
        return 'revoked';
    }
    if ($certificate['expiry_date'] && $certificate['expiry_date'] < date('Y-m-d')) {
        return 'expired';
    }
    return 'valid';
}

function status_badge(string $status): string
{
    $colors = ['valid' => 'success', 'expired' => 'warning', 'revoked' => 'danger'];
    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . e($color) . '">' . e(strtoupper($status)) . '</span>';
}

function log_verification(PDO $db, ?array $certificate, string $result): void
{
    $stmt = $db->prepare(
        'INSERT INTO verification_logs (certificate_id, certificate_number, result, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $certificate['id'] ?? null,
        $certificate['certificate_number'] ?? null,
        $result,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
