<?php
declare(strict_types=1);

// Customer Registration submission endpoint:
// - Accepts POSTed form data
// - Stores submission in SQLite (JSON payload)
// - Forwards submission to Formspree only when explicitly enabled (optional)
// - Redirects to thank-you page on success

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

function getEnvOrDefault(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function normalizeArrayField($value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
    }
    if (is_string($value) && trim($value) !== '') {
        return [trim($value)];
    }
    return [];
}

function jsonResponse(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function errorResponse(int $status, string $message, array $fieldErrors = []): void {
    $payload = ['error' => $message];
    if (!empty($fieldErrors)) {
        $payload['errors'] = $fieldErrors;
    }
    jsonResponse($status, $payload);
}

function isLikelyBrowserFormPost(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
}

function redirect(string $location): void {
    header('Location: ' . $location, true, 302);
    exit();
}

// Honeypot spam protection: if a hidden field is filled, pretend success but do nothing.
$gotcha = trim((string)($_POST['_gotcha'] ?? ''));
if ($gotcha !== '') {
    if (isLikelyBrowserFormPost()) {
        redirect('/customer-registration-thank-you.html');
    }
    jsonResponse(200, ['success' => true, 'spam' => true]);
}

// Required fields
$legalBusinessName = trim((string)($_POST['legal_business_name'] ?? ''));
$headOfficeStreet = trim((string)($_POST['head_office_street'] ?? ''));
$headOfficeCity = trim((string)($_POST['head_office_city'] ?? ''));
$headOfficeState = trim((string)($_POST['head_office_state'] ?? ''));
$headOfficeZip = trim((string)($_POST['head_office_zip'] ?? ''));
$headOfficeCountry = trim((string)($_POST['head_office_country'] ?? ''));
$primaryContactName = trim((string)($_POST['primary_contact_name'] ?? ''));
$primaryContactEmail = trim((string)($_POST['primary_contact_email'] ?? ''));
$signatoryName = trim((string)($_POST['signatory_name'] ?? ''));
$signatureDate = trim((string)($_POST['signature_date'] ?? ''));
$certifyAccuracy = (string)($_POST['certify_accuracy'] ?? '');

// Field-level validation (so the UI can show the exact issue)
$fieldErrors = [];
if ($legalBusinessName === '') {
    $fieldErrors['legal_business_name'] = 'Legal Business Name is required.';
}
if ($headOfficeStreet === '') {
    $fieldErrors['head_office_street'] = 'Head Office Address (Street) is required.';
}
if ($headOfficeCity === '') {
    $fieldErrors['head_office_city'] = 'Head Office City is required.';
}
if ($headOfficeState === '') {
    $fieldErrors['head_office_state'] = 'Head Office State/Province is required.';
}
if ($headOfficeZip === '') {
    $fieldErrors['head_office_zip'] = 'Head Office Zip/Postal is required.';
}
if ($headOfficeCountry === '') {
    $fieldErrors['head_office_country'] = 'Head Office Country is required.';
}
if ($primaryContactName === '') {
    $fieldErrors['primary_contact_name'] = 'Primary Contact Name is required.';
}
if ($primaryContactEmail === '') {
    $fieldErrors['primary_contact_email'] = 'Primary Contact Email is required.';
} elseif (!filter_var($primaryContactEmail, FILTER_VALIDATE_EMAIL)) {
    $fieldErrors['primary_contact_email'] = 'Primary Contact Email must be a valid email address.';
}
if ($signatoryName === '') {
    $fieldErrors['signatory_name'] = 'Authorized Signatory Name is required.';
}
if ($signatureDate === '') {
    $fieldErrors['signature_date'] = 'Signature Date is required.';
} else {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $signatureDate);
    $errors = DateTimeImmutable::getLastErrors();
    if ($dt === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        $fieldErrors['signature_date'] = 'Signature Date must be a valid date.';
    }
}
if ($certifyAccuracy !== 'Yes') {
    $fieldErrors['certify_accuracy'] = 'You must certify accuracy before submitting.';
}

if (!empty($fieldErrors)) {
    if (isLikelyBrowserFormPost()) {
        // Basic fallback: go back if required fields missing
        redirect('/customer-registration.html');
    }
    errorResponse(422, 'Please fix the highlighted fields and try again.', $fieldErrors);
}

// Build normalized submission payload (keep full fidelity in JSON)
$submission = [
    'legal_business_name' => $legalBusinessName,
    'operating_trade_name' => trim((string)($_POST['operating_trade_name'] ?? '')),
    'business_type' => normalizeArrayField($_POST['business_type'] ?? []),
    'business_type_other' => trim((string)($_POST['business_type_other'] ?? '')),
    'industry_sector' => normalizeArrayField($_POST['industry_sector'] ?? []),
    'industry_sector_other' => trim((string)($_POST['industry_sector_other'] ?? '')),
    'head_office_street' => $headOfficeStreet,
    'head_office_city' => $headOfficeCity,
    'head_office_state' => $headOfficeState,
    'head_office_zip' => $headOfficeZip,
    'head_office_country' => $headOfficeCountry,
    'website' => trim((string)($_POST['website'] ?? '')),
    'general_email' => trim((string)($_POST['general_email'] ?? '')),
    'main_phone' => trim((string)($_POST['main_phone'] ?? '')),

    'primary_contact_name' => $primaryContactName,
    'primary_contact_title' => trim((string)($_POST['primary_contact_title'] ?? '')),
    'primary_contact_email' => $primaryContactEmail,
    'primary_contact_phone' => trim((string)($_POST['primary_contact_phone'] ?? '')),
    'primary_contact_authorizations' => normalizeArrayField($_POST['primary_contact_authorizations'] ?? []),

    'locations' => [
        [
            'name' => trim((string)($_POST['location_1_name'] ?? '')),
            'address' => trim((string)($_POST['location_1_address'] ?? '')),
            'contact' => trim((string)($_POST['location_1_contact'] ?? '')),
            'phone_email' => trim((string)($_POST['location_1_phone_email'] ?? '')),
        ],
        [
            'name' => trim((string)($_POST['location_2_name'] ?? '')),
            'address' => trim((string)($_POST['location_2_address'] ?? '')),
            'contact' => trim((string)($_POST['location_2_contact'] ?? '')),
            'phone_email' => trim((string)($_POST['location_2_phone_email'] ?? '')),
        ],
        [
            'name' => trim((string)($_POST['location_3_name'] ?? '')),
            'address' => trim((string)($_POST['location_3_address'] ?? '')),
            'contact' => trim((string)($_POST['location_3_contact'] ?? '')),
            'phone_email' => trim((string)($_POST['location_3_phone_email'] ?? '')),
        ],
    ],

    'delivery_street' => trim((string)($_POST['delivery_street'] ?? '')),
    'delivery_city' => trim((string)($_POST['delivery_city'] ?? '')),
    'delivery_state' => trim((string)($_POST['delivery_state'] ?? '')),
    'delivery_zip' => trim((string)($_POST['delivery_zip'] ?? '')),
    'receiving_hours' => trim((string)($_POST['receiving_hours'] ?? '')),
    'dock_forklift_available' => trim((string)($_POST['dock_forklift_available'] ?? '')),
    'receiving_requirements' => trim((string)($_POST['receiving_requirements'] ?? '')),
    'preferred_shipment_size' => trim((string)($_POST['preferred_shipment_size'] ?? '')),
    'preferred_shipment_size_bulk_details' => trim((string)($_POST['preferred_shipment_size_bulk_details'] ?? '')),

    'billing_contact_name' => trim((string)($_POST['billing_contact_name'] ?? '')),
    'billing_email' => trim((string)($_POST['billing_email'] ?? '')),
    'billing_phone' => trim((string)($_POST['billing_phone'] ?? '')),
    'preferred_invoice_method' => trim((string)($_POST['preferred_invoice_method'] ?? '')),
    'preferred_invoice_method_other' => trim((string)($_POST['preferred_invoice_method_other'] ?? '')),
    'billing_address_different' => trim((string)($_POST['billing_address_different'] ?? '')),

    'ein' => trim((string)($_POST['ein'] ?? '')),
    'years_in_business' => trim((string)($_POST['years_in_business'] ?? '')),
    'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
    'bank_contact' => trim((string)($_POST['bank_contact'] ?? '')),
    'bank_phone' => trim((string)($_POST['bank_phone'] ?? '')),
    'trade_ref_1_company' => trim((string)($_POST['trade_ref_1_company'] ?? '')),
    'trade_ref_1_contact' => trim((string)($_POST['trade_ref_1_contact'] ?? '')),
    'trade_ref_1_phone' => trim((string)($_POST['trade_ref_1_phone'] ?? '')),
    'trade_ref_2_company' => trim((string)($_POST['trade_ref_2_company'] ?? '')),
    'trade_ref_2_contact' => trim((string)($_POST['trade_ref_2_contact'] ?? '')),
    'trade_ref_2_phone' => trim((string)($_POST['trade_ref_2_phone'] ?? '')),

    'intended_use' => normalizeArrayField($_POST['intended_use'] ?? []),
    'intended_use_other' => trim((string)($_POST['intended_use_other'] ?? '')),
    'require_lab_certs' => trim((string)($_POST['require_lab_certs'] ?? '')),
    'require_compliance_docs' => trim((string)($_POST['require_compliance_docs'] ?? '')),
    'compliance_docs_specify' => trim((string)($_POST['compliance_docs_specify'] ?? '')),

    'signatory_name' => $signatoryName,
    'signatory_title' => trim((string)($_POST['signatory_title'] ?? '')),
    'signature_date' => $signatureDate,
    'certify_accuracy' => $certifyAccuracy,
];

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
$dataJson = json_encode($submission, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($dataJson === false) {
    errorResponse(500, 'Server error: unable to process your submission. Please try again.');
}

// Store in SQLite
$dbPath = realpath(__DIR__ . '/../data');
if ($dbPath === false) {
    // Attempt to create if missing
    $targetDir = __DIR__ . '/../data';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) {
        errorResponse(500, 'Server error: unable to save your submission right now. Please try again later.');
    }
    $dbPath = realpath($targetDir);
}

$dbFile = $dbPath . '/customer_registrations.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_registrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            ip TEXT,
            user_agent TEXT,
            legal_business_name TEXT,
            primary_contact_email TEXT,
            data_json TEXT NOT NULL
        )'
    );

    $stmt = $pdo->prepare(
        'INSERT INTO customer_registrations (created_at, ip, user_agent, legal_business_name, primary_contact_email, data_json)
         VALUES (:created_at, :ip, :user_agent, :legal_business_name, :primary_contact_email, :data_json)'
    );
    $stmt->execute([
        ':created_at' => $createdAt,
        ':ip' => $ip,
        ':user_agent' => $userAgent,
        ':legal_business_name' => $legalBusinessName,
        ':primary_contact_email' => $primaryContactEmail,
        ':data_json' => $dataJson,
    ]);

    $insertId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    // Avoid leaking server internals to client
    if (isLikelyBrowserFormPost()) {
        redirect('/customer-registration.html');
    }
    errorResponse(500, 'Server error: unable to save your submission right now. Please try again later.');
}

// Forward to Formspree (optional, explicitly enabled)
// To enable, set:
// - FORMSPREE_FORWARDING_ENABLED=1
// - FORMSPREE_ENDPOINT="https://formspree.io/f/xxxxxx"
$forwardingEnabled = strtolower(getEnvOrDefault('FORMSPREE_FORWARDING_ENABLED', '')) === '1'
    || strtolower(getEnvOrDefault('FORMSPREE_FORWARDING_ENABLED', '')) === 'true'
    || strtolower(getEnvOrDefault('FORMSPREE_FORWARDING_ENABLED', '')) === 'yes';

$formspreeEndpoint = getEnvOrDefault('FORMSPREE_ENDPOINT', '');
if ($forwardingEnabled && $formspreeEndpoint !== '') {
    $emailPayload = [
        'form_name' => 'First-time Customer Registration',
        'submission_id' => (string)$insertId,
        'legal_business_name' => $legalBusinessName,
        'primary_contact_name' => $primaryContactName,
        'primary_contact_email' => $primaryContactEmail,
        'submitted_at_utc' => $createdAt,
        'data_json' => $dataJson,
    ];

    try {
        $ch = curl_init($formspreeEndpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($emailPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If Formspree fails, we still keep DB record (do not block onboarding)
        if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
            // no-op
        }
    } catch (Throwable $e) {
        // no-op
    }
}

// Success response
if (isLikelyBrowserFormPost()) {
    redirect('/customer-registration-thank-you.html');
}

jsonResponse(200, ['success' => true, 'id' => $insertId]);

