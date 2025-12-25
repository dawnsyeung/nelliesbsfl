<?php
declare(strict_types=1);

// Generic form capture endpoint:
// - Accepts POSTed form data (multipart/form-data or urlencoded)
// - Stores submission in SQLite for admin record keeping
// - Forwards submission to Formspree (so existing email workflows keep working)
//
// Storage: /data/form_submissions.sqlite (protected by /data/.htaccess)

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

function fc_env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') return $default;
    return $value;
}

function fc_is_likely_browser_form_post(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strpos($accept, 'text/html') !== false || strpos($accept, 'application/xhtml+xml') !== false;
}

function fc_json_response(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function fc_redirect(string $location): void {
    header('Location: ' . $location, true, 302);
    exit();
}

function fc_normalize_value($value) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = fc_normalize_value($v);
        }
        return $out;
    }
    if (is_string($value)) {
        return trim($value);
    }
    return $value;
}

function fc_safe_formspree_endpoint(string $endpoint): string {
    $endpoint = trim($endpoint);
    if ($endpoint === '') return '';
    // Only allow posting to Formspree "f" endpoints.
    if (!preg_match('#^https://formspree\.io/f/[A-Za-z0-9]+$#', $endpoint)) return '';
    return $endpoint;
}

function fc_http_post_form(string $url, array $fields, int &$httpCode, string &$errorText): bool {
    $httpCode = 0;
    $errorText = '';
    $body = http_build_query($fields);

    // Prefer cURL when available.
    if (function_exists('curl_init')) {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($resp === false) {
                $errorText = (string)curl_error($ch);
            }
            curl_close($ch);
            return $resp !== false && $httpCode >= 200 && $httpCode < 300;
        } catch (Throwable $e) {
            $errorText = $e->getMessage();
            return false;
        }
    }

    // Fallback: stream context.
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 12,
            'ignore_errors' => true, // still read response body for status
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $httpCode = 0;
    $errorText = $result === false ? 'HTTP request failed' : '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $httpCode = (int)$m[1];
                break;
            }
        }
    }
    return $result !== false && $httpCode >= 200 && $httpCode < 300;
}

// Honeypot: if a hidden field is filled, pretend success.
$gotcha = trim((string)($_POST['_gotcha'] ?? ''));
if ($gotcha !== '') {
    if (fc_is_likely_browser_form_post()) {
        $next = trim((string)($_POST['_next'] ?? ''));
        fc_redirect($next !== '' ? $next : '/');
    }
    fc_json_response(200, ['success' => true, 'spam' => true]);
}

// Normalize payload (keep full fidelity).
$payload = fc_normalize_value($_POST);
$formName = trim((string)($payload['form_name'] ?? ''));
if ($formName === '') {
    // Best-effort labeling so admin can filter.
    $formName = trim((string)($payload['request_type'] ?? '')) ?: 'Website form submission';
}

$primaryEmail = trim((string)($payload['email'] ?? ''));
if ($primaryEmail === '' && isset($payload['primary_contact_email'])) {
    $primaryEmail = trim((string)$payload['primary_contact_email']);
}
$primaryName = trim((string)($payload['name'] ?? ''));
if ($primaryName === '' && isset($payload['primary_contact_name'])) {
    $primaryName = trim((string)$payload['primary_contact_name']);
}

$createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

$dataJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($dataJson === false) {
    fc_json_response(500, ['error' => 'Server error: unable to process submission.']);
}

// Store in SQLite
$dbDir = realpath(__DIR__ . '/../data');
if ($dbDir === false) {
    $targetDir = __DIR__ . '/../data';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
        fc_json_response(500, ['error' => 'Server error: unable to save submission right now.']);
    }
    $dbDir = realpath($targetDir);
}
if ($dbDir === false) {
    fc_json_response(500, ['error' => 'Server error: unable to save submission right now.']);
}

$dbFile = $dbDir . '/form_submissions.sqlite';
$insertId = 0;

try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            ip TEXT,
            user_agent TEXT,
            referer TEXT,
            form_name TEXT,
            name TEXT,
            email TEXT,
            forwarded INTEGER NOT NULL DEFAULT 0,
            forward_http_code INTEGER,
            forward_error TEXT,
            data_json TEXT NOT NULL
        )'
    );

    $stmt = $pdo->prepare(
        'INSERT INTO form_submissions (created_at, ip, user_agent, referer, form_name, name, email, forwarded, forward_http_code, forward_error, data_json)
         VALUES (:created_at, :ip, :user_agent, :referer, :form_name, :name, :email, 0, NULL, NULL, :data_json)'
    );
    $stmt->execute([
        ':created_at' => $createdAt,
        ':ip' => $ip !== '' ? $ip : null,
        ':user_agent' => $userAgent !== '' ? $userAgent : null,
        ':referer' => $referer !== '' ? $referer : null,
        ':form_name' => $formName !== '' ? $formName : null,
        ':name' => $primaryName !== '' ? $primaryName : null,
        ':email' => $primaryEmail !== '' ? $primaryEmail : null,
        ':data_json' => $dataJson,
    ]);
    $insertId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    if (fc_is_likely_browser_form_post()) {
        fc_redirect($referer !== '' ? $referer : '/');
    }
    fc_json_response(500, ['error' => 'Server error: unable to save submission right now.']);
}

// Forward to Formspree (enabled by default to preserve existing email workflow)
$forwardingDisabled = strtolower(fc_env('FORMSPREE_FORWARDING_ENABLED', '1')) === '0'
    || strtolower(fc_env('FORMSPREE_FORWARDING_ENABLED', '1')) === 'false'
    || strtolower(fc_env('FORMSPREE_FORWARDING_ENABLED', '1')) === 'no';

$defaultFormspree = 'https://formspree.io/f/xjkeljzv';
$endpoint = fc_safe_formspree_endpoint(fc_env('FORMSPREE_ENDPOINT', '')) ?: $defaultFormspree;

$okForward = true;
$httpCode = 0;
$forwardError = '';

if (!$forwardingDisabled) {
    // Forward all fields, plus a stable submission id for reconciliation.
    $forwardFields = $payload;
    $forwardFields['submission_id'] = (string)$insertId;
    $forwardFields['captured_at_utc'] = $createdAt;
    $forwardFields['captured_by'] = 'nelliesbsfl_form_capture';

    $okForward = fc_http_post_form($endpoint, $forwardFields, $httpCode, $forwardError);

    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $pdo->prepare('UPDATE form_submissions SET forwarded = :forwarded, forward_http_code = :code, forward_error = :err WHERE id = :id');
        $stmt->execute([
            ':forwarded' => $okForward ? 1 : 0,
            ':code' => $httpCode > 0 ? $httpCode : null,
            ':err' => $okForward ? null : ($forwardError !== '' ? $forwardError : 'Forwarding failed'),
            ':id' => $insertId,
        ]);
    } catch (Throwable $e) {
        // no-op
    }
}

if (!$okForward) {
    // Record is safely stored, but preserve the existing UX: show an error so the user knows email may not have been delivered.
    if (fc_is_likely_browser_form_post()) {
        fc_redirect($referer !== '' ? $referer : '/');
    }
    fc_json_response(502, [
        'error' => 'We saved your submission, but failed to notify the team. Please try again or email us directly.',
        'id' => $insertId,
    ]);
}

// Success
if (fc_is_likely_browser_form_post()) {
    $next = trim((string)($_POST['_next'] ?? ''));
    fc_redirect($next !== '' ? $next : '/');
}

fc_json_response(200, ['success' => true, 'id' => $insertId]);

