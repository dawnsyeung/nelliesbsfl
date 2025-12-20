<?php
declare(strict_types=1);

// Shared DB utilities for Nellie's BSFL Offer Board
// Storage: SQLite in /data/offer_board.sqlite (web-inaccessible via /data/.htaccess)

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function offer_board_env(string $key, string $default = ''): string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

function offer_board_uuidv4(): string {
    $data = random_bytes(16);
    // Set version to 0100
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function offer_board_db_path(): string {
    $dbPath = realpath(__DIR__ . '/../data');
    if ($dbPath === false) {
        $targetDir = __DIR__ . '/../data';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
            throw new RuntimeException('Unable to create data directory.');
        }
        $dbPath = realpath($targetDir);
        if ($dbPath === false) {
            throw new RuntimeException('Unable to resolve data directory.');
        }
    }
    return $dbPath . '/offer_board.sqlite';
}

function offer_board_pdo(): PDO {
    $pdo = new PDO('sqlite:' . offer_board_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

function offer_board_init_schema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS buyer_requests (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            status TEXT NOT NULL,

            company_name TEXT NOT NULL,
            contact_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            website TEXT,
            shipping_region TEXT NOT NULL,
            intended_use TEXT,

            product_type TEXT NOT NULL,
            grade TEXT NOT NULL,
            format TEXT NOT NULL,
            quantity_lbs INTEGER NOT NULL,
            target_price_per_lb REAL NOT NULL,
            delivery_start_date TEXT NOT NULL,
            delivery_frequency TEXT NOT NULL,
            frequency_months INTEGER,
            packaging_private_label INTEGER NOT NULL DEFAULT 0,
            packaging_notes TEXT,
            additional_notes TEXT,

            review_notes_internal TEXT,
            source TEXT NOT NULL DEFAULT "website_offer_board",
            consent_marketing INTEGER NOT NULL DEFAULT 0,

            ip TEXT,
            user_agent TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS market_fulfillments (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            fulfilled_date TEXT NOT NULL,
            month_bucket TEXT NOT NULL,
            grade TEXT NOT NULL,
            format TEXT NOT NULL,
            quantity_lbs INTEGER NOT NULL,
            delivery_window TEXT NOT NULL,
            region TEXT NOT NULL,
            note_public TEXT,
            linked_request_id TEXT,
            FOREIGN KEY(linked_request_id) REFERENCES buyer_requests(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS monthly_capacity (
            month_bucket TEXT PRIMARY KEY,
            capacity_lbs INTEGER NOT NULL,
            reserved_lbs INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS request_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            actor TEXT,
            request_id TEXT,
            action TEXT NOT NULL,
            meta_json TEXT
        )'
    );
}

function offer_board_month_bucket_from_date(string $dateYmd): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd, new DateTimeZone('UTC'));
    if ($dt === false) return '';
    return $dt->format('Y-m');
}

function offer_board_is_likely_browser_form_post(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
}

function offer_board_json_response(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function offer_board_error_response(int $status, string $message, array $fieldErrors = []): void {
    $payload = ['error' => $message];
    if (!empty($fieldErrors)) $payload['errors'] = $fieldErrors;
    offer_board_json_response($status, $payload);
}

function offer_board_redirect(string $location): void {
    header('Location: ' . $location, true, 302);
    exit();
}

