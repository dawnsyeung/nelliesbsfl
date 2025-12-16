<?php
declare(strict_types=1);

// Simple server-side admin auth using PHP sessions.
// Configure via environment variables in your hosting platform:
// - ADMIN_USERNAME (default: admin)
// - ADMIN_PASSWORD (required; if missing, login will always fail)

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

session_name('nellies_admin');
session_start();

function env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

function isAuthed(): bool {
    return isset($_SESSION['authed']) && $_SESSION['authed'] === true;
}

function requireAuth(): void {
    if (!isAuthed()) {
        header('Location: /admin/login.php', true, 302);
        exit();
    }
}

function safe(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

