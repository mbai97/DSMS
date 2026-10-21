<?php

declare(strict_types=1);

/**
 * Authentication helpers. Loaded by includes/bootstrap.php, which has already
 * started the session, loaded the config and created $db.
 */

function login_admin(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $id;

    $db = $GLOBALS['db'] ?? null;
    if ($db instanceof PDO) {
        $stmt = $db->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $email = $stmt->fetchColumn();
        if (is_string($email) && $email !== '') {
            $_SESSION['admin_email'] = $email;
        }
    }
}

function logout_admin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
    }

    session_destroy();
}
