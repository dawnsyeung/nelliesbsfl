<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAuth();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function dd_fail(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit();
}

function dd_db_map(string $key): array {
    $dataDir = realpath(__DIR__ . '/../data');
    if ($dataDir === false) {
        $dataDir = __DIR__ . '/../data';
    }

    $map = [
        'form_submissions' => [
            'path' => $dataDir . '/form_submissions.sqlite',
            'label' => 'form_submissions',
        ],
        'customer_registrations' => [
            'path' => $dataDir . '/customer_registrations.sqlite',
            'label' => 'customer_registrations',
        ],
        'offer_board' => [
            'path' => $dataDir . '/offer_board.sqlite',
            'label' => 'offer_board',
        ],
    ];

    return $map[$key] ?? [];
}

function dd_stream_file(string $path, string $downloadName): void {
    if (!is_file($path)) {
        dd_fail(404, 'Database not found (no submissions yet).');
    }

    $size = filesize($path);
    if ($size === false) $size = 0;

    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: no-store');
    header('Pragma: no-cache');

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        dd_fail(500, 'Unable to read database.');
    }
    fpassthru($fp);
    fclose($fp);
    exit();
}

function dd_try_snapshot(string $dbPath, string $label): ?string {
    if (!is_file($dbPath)) {
        return null;
    }

    $tmpDir = realpath(__DIR__ . '/../data');
    if ($tmpDir === false) {
        $tmpDir = sys_get_temp_dir();
    }

    $tmpFile = rtrim($tmpDir, '/') . '/export_' . $label . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sqlite';

    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // SQLite >= 3.27 supports VACUUM INTO.
        $pdo->exec("VACUUM INTO " . $pdo->quote($tmpFile));
        if (is_file($tmpFile)) {
            return $tmpFile;
        }
    } catch (Throwable $e) {
        // Fall back to raw download.
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
        return null;
    }

    return null;
}

$dbKey = (string)($_GET['db'] ?? '');
$info = dd_db_map($dbKey);
if (empty($info)) {
    dd_fail(400, 'Invalid db.');
}

$dbPath = (string)$info['path'];
$label = (string)$info['label'];
$downloadName = $label . '_' . gmdate('Y-m-d_H-i-s') . '.sqlite';

// Prefer a consistent snapshot; fallback to raw file if unsupported.
$snapshot = dd_try_snapshot($dbPath, $label);
if ($snapshot !== null) {
    try {
        dd_stream_file($snapshot, $downloadName);
    } finally {
        @unlink($snapshot);
    }
}

dd_stream_file($dbPath, $downloadName);

