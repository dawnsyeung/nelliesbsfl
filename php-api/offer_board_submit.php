<?php
declare(strict_types=1);
require_once __DIR__ . '/offer_board_db.php';

// Ensure the endpoint returns JSON for AJAX callers even on fatal errors,
// and log a traceable error_id to /data/offer_board_errors.log.
$__offer_board_error_id = offer_board_uuidv4();
ini_set('display_errors', '0');

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($__offer_board_error_id): bool {
    // Convert PHP warnings/notices into log entries; allow normal flow to continue.
    offer_board_log_error($__offer_board_error_id, 'php_error', [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ]);
    return false;
});

register_shutdown_function(static function () use ($__offer_board_error_id): void {
    $err = error_get_last();
    if (!$err) return;
    $type = (int)($err['type'] ?? 0);
    // Catch fatal-ish errors that would otherwise emit HTML and break JSON parsing.
    if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    offer_board_log_error($__offer_board_error_id, 'php_fatal', $err);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => 'Server error. Please try again later.',
        'error_id' => $__offer_board_error_id,
    ], JSON_UNESCAPED_SLASHES);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    offer_board_json_response(405, ['error' => 'Method not allowed']);
}

function offer_board_normalize_bool($value): int {
    if (is_bool($value)) return $value ? 1 : 0;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function offer_board_parse_int($value, int $default = 0): int {
    if ($value === null) return $default;
    if (is_int($value)) return $value;
    $s = trim((string)$value);
    if ($s === '') return $default;
    if (!preg_match('/^-?\d+$/', $s)) return $default;
    return (int)$s;
}

function offer_board_parse_price($value): float {
    $s = trim((string)$value);
    if ($s === '') return -1.0;
    // Allow "$3.25" or "3.25"
    $s = str_replace(['$', ','], '', $s);
    if (!is_numeric($s)) return -1.0;
    return (float)$s;
}

function offer_board_validate_date_ymd(string $value): bool {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $dt !== false && ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0;
}

// Honeypot spam protection
$gotcha = trim((string)($_POST['_gotcha'] ?? ''));
if ($gotcha !== '') {
    if (offer_board_is_likely_browser_form_post()) {
        offer_board_redirect('/offer-board-thanks.html');
    }
    offer_board_json_response(200, ['success' => true, 'spam' => true]);
}

// Basic rate limiting by IP: max 5 submissions per hour
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$createdAt = $now->format('c');

try {
    $pdo = offer_board_pdo();
    offer_board_init_schema($pdo);

    if ($ip !== '') {
        $limitPerHour = (int)offer_board_env('OFFER_BOARD_RATE_LIMIT_PER_HOUR', '20');
        if ($limitPerHour <= 0) $limitPerHour = 20;
        $cutoff = $now->sub(new DateInterval('PT1H'))->format('c');
        $stmt = $pdo->prepare('SELECT COUNT(1) AS c FROM buyer_requests WHERE ip = :ip AND created_at >= :cutoff');
        $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
        $count = (int)($stmt->fetch()['c'] ?? 0);
        if ($count >= $limitPerHour) {
            $msg = 'Too many submissions. Please wait and try again.';
            if (offer_board_is_likely_browser_form_post()) {
                offer_board_redirect('/orders.html#submit');
            }
            offer_board_json_response(429, ['error' => $msg, 'error_id' => $__offer_board_error_id]);
        }
    }
} catch (Throwable $e) {
    // If DB is unavailable, fail closed (don’t accept requests silently)
    if (offer_board_is_likely_browser_form_post()) {
        offer_board_redirect('/orders.html#submit');
    }
    offer_board_log_error($__offer_board_error_id, 'db_init_failed', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    offer_board_json_response(500, ['error' => 'Server error. Please try again later.', 'error_id' => $__offer_board_error_id]);
}

// Required fields
$companyName = trim((string)($_POST['company_name'] ?? ''));
$contactName = trim((string)($_POST['contact_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$website = trim((string)($_POST['website'] ?? ''));
$shippingRegion = trim((string)($_POST['shipping_region'] ?? ''));
$intendedUse = trim((string)($_POST['intended_use'] ?? ''));

$grade = strtoupper(trim((string)($_POST['grade'] ?? '')));
$format = strtolower(trim((string)($_POST['format'] ?? '')));
$quantityLbs = offer_board_parse_int($_POST['quantity_lbs'] ?? null, 0);
$targetPrice = offer_board_parse_price($_POST['target_price_per_lb'] ?? null);
$deliveryStart = trim((string)($_POST['delivery_start_date'] ?? ''));
$deliveryFrequency = strtolower(trim((string)($_POST['delivery_frequency'] ?? '')));
$frequencyMonths = offer_board_parse_int($_POST['frequency_months'] ?? null, 0);
$privateLabel = offer_board_normalize_bool($_POST['packaging_private_label'] ?? null);
$packagingNotes = trim((string)($_POST['packaging_notes'] ?? ''));
$additionalNotes = trim((string)($_POST['additional_notes'] ?? ''));
$consentMarketing = offer_board_normalize_bool($_POST['consent_marketing'] ?? null);

$fieldErrors = [];

if ($email === '') {
    $fieldErrors['email'] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fieldErrors['email'] = 'Please enter a valid email.';
}

// Only email is required. Everything else is optional, but the DB schema requires non-null values.
// Normalize optional fields into safe defaults.
$allowedRegions = ['TX', 'Gulf Coast', 'Midwest', 'Northeast', 'West', 'Canada', 'Other'];
if ($shippingRegion === '' || !in_array($shippingRegion, $allowedRegions, true)) {
    $shippingRegion = 'Other';
}

if (!in_array($grade, ['A', 'B'], true)) $grade = 'A';
if (!in_array($format, ['bulk', 'packaged'], true)) $format = 'bulk';

if ($quantityLbs < 0) $quantityLbs = 0;
if ($targetPrice < 0) $targetPrice = 0.0;

if ($deliveryStart !== '' && !offer_board_validate_date_ymd($deliveryStart)) {
    $deliveryStart = '';
}

if (!in_array($deliveryFrequency, ['one_time', 'monthly', 'quarterly'], true)) {
    $deliveryFrequency = 'one_time';
}

if (in_array($deliveryFrequency, ['monthly', 'quarterly'], true)) {
    if (!in_array($frequencyMonths, [3, 6, 12], true)) {
        $frequencyMonths = 0;
    }
} else {
    $frequencyMonths = 0;
}

if (!empty($fieldErrors)) {
    if (offer_board_is_likely_browser_form_post()) {
        offer_board_redirect('/orders.html#submit');
    }
    offer_board_error_response(422, 'Please fix the highlighted fields and try again.', $fieldErrors);
}

$requestId = offer_board_uuidv4();
$status = 'submitted';
$productType = 'bsfl_dried';

try {
    $pdo = offer_board_pdo();
    offer_board_init_schema($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO buyer_requests (
            id, created_at, status,
            company_name, contact_name, email, phone, website, shipping_region, intended_use,
            product_type, grade, format, quantity_lbs, target_price_per_lb, delivery_start_date,
            delivery_frequency, frequency_months, packaging_private_label, packaging_notes, additional_notes,
            review_notes_internal, source, consent_marketing, ip, user_agent
        ) VALUES (
            :id, :created_at, :status,
            :company_name, :contact_name, :email, :phone, :website, :shipping_region, :intended_use,
            :product_type, :grade, :format, :quantity_lbs, :target_price_per_lb, :delivery_start_date,
            :delivery_frequency, :frequency_months, :packaging_private_label, :packaging_notes, :additional_notes,
            NULL, :source, :consent_marketing, :ip, :user_agent
        )'
    );

    $stmt->execute([
        ':id' => $requestId,
        ':created_at' => $createdAt,
        ':status' => $status,
        ':company_name' => $companyName,
        ':contact_name' => $contactName,
        ':email' => $email,
        ':phone' => $phone !== '' ? $phone : null,
        ':website' => $website !== '' ? $website : null,
        ':shipping_region' => $shippingRegion,
        ':intended_use' => $intendedUse !== '' ? $intendedUse : null,
        ':product_type' => $productType,
        ':grade' => $grade,
        ':format' => $format,
        ':quantity_lbs' => $quantityLbs,
        ':target_price_per_lb' => $targetPrice,
        ':delivery_start_date' => $deliveryStart,
        ':delivery_frequency' => $deliveryFrequency,
        ':frequency_months' => $frequencyMonths > 0 ? $frequencyMonths : null,
        ':packaging_private_label' => $privateLabel,
        ':packaging_notes' => $packagingNotes !== '' ? $packagingNotes : null,
        ':additional_notes' => $additionalNotes !== '' ? $additionalNotes : null,
        ':source' => 'website_offer_board',
        ':consent_marketing' => $consentMarketing,
        ':ip' => $ip !== '' ? $ip : null,
        ':user_agent' => $userAgent !== '' ? $userAgent : null,
    ]);

    $audit = $pdo->prepare('INSERT INTO request_audit (created_at, actor, request_id, action, meta_json) VALUES (:created_at, :actor, :request_id, :action, :meta_json)');
    $audit->execute([
        ':created_at' => $createdAt,
        ':actor' => 'website',
        ':request_id' => $requestId,
        ':action' => 'buyer_request_submitted',
        ':meta_json' => json_encode(['grade' => $grade, 'format' => $format, 'quantity_lbs' => $quantityLbs], JSON_UNESCAPED_SLASHES),
    ]);
} catch (Throwable $e) {
    if (offer_board_is_likely_browser_form_post()) {
        offer_board_redirect('/orders.html#submit');
    }
    offer_board_log_error($__offer_board_error_id, 'db_insert_failed', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    offer_board_json_response(500, ['error' => 'Server error: unable to save your request right now.', 'error_id' => $__offer_board_error_id]);
}

// Email notification to admin (non-blocking)
$adminEmail = offer_board_env('ADMIN_EMAIL', 'dawn@nelliesbsfl.com');
if ($adminEmail !== '') {
    $subject = "New BSFL Offer Board Request ({$grade} / {$format} / {$quantityLbs} lbs)";
    $lines = [];
    $lines[] = "New buyer request submitted (Offer Board)";
    $lines[] = "";
    $lines[] = "Company: {$companyName}";
    $lines[] = "Contact: {$contactName}";
    $lines[] = "Email: {$email}";
    $lines[] = "Phone: " . ($phone !== '' ? $phone : '—');
    $lines[] = "Website: " . ($website !== '' ? $website : '—');
    $lines[] = "Region: {$shippingRegion}";
    $lines[] = "Intended use: " . ($intendedUse !== '' ? $intendedUse : '—');
    $lines[] = "";
    $lines[] = "Grade: {$grade}";
    $lines[] = "Format: {$format}";
    $lines[] = "Quantity (lbs): {$quantityLbs}";
    $lines[] = "Target price ($/lb): " . number_format($targetPrice, 2, '.', '');
    $lines[] = "Delivery start date: {$deliveryStart}";
    $lines[] = "Delivery frequency: {$deliveryFrequency}" . ($frequencyMonths > 0 ? " ({$frequencyMonths} months)" : "");
    $lines[] = "Private label: " . ($privateLabel === 1 ? "Yes" : "No");
    if ($privateLabel === 1) {
        $lines[] = "Private label notes: " . ($packagingNotes !== '' ? $packagingNotes : '—');
    }
    if ($additionalNotes !== '') {
        $lines[] = "";
        $lines[] = "Additional notes:";
        $lines[] = $additionalNotes;
    }
    $lines[] = "";
    $lines[] = "Request ID: {$requestId}";
    $lines[] = "Admin: https://nelliesbsfl.com/admin/offer-board/";

    $body = implode("\n", $lines);
    $headers = "From: no-reply@nelliesbsfl.com\r\nReply-To: {$email}\r\n";

    try {
        @mail($adminEmail, $subject, $body, $headers);
    } catch (Throwable $e) {
        // no-op
    }
}

if (offer_board_is_likely_browser_form_post()) {
    offer_board_redirect('/offer-board-thanks.html');
}

// Optional forwarding to Formspree (enabled by default to preserve existing workflow)
// To disable, set FORMSPREE_FORWARDING_ENABLED=0
$forwardingDisabled = strtolower(offer_board_env('FORMSPREE_FORWARDING_ENABLED', '1')) === '0'
    || strtolower(offer_board_env('FORMSPREE_FORWARDING_ENABLED', '1')) === 'false'
    || strtolower(offer_board_env('FORMSPREE_FORWARDING_ENABLED', '1')) === 'no';

if (!$forwardingDisabled) {
    $defaultFormspreeEndpoint = 'https://formspree.io/f/xjkeljzv';
    $formspreeEndpoint = offer_board_env('FORMSPREE_ENDPOINT', $defaultFormspreeEndpoint);

    // Forward the original form payload + server-side identifiers.
    $forwardPayload = $_POST;
    $forwardPayload['request_id'] = $requestId;
    $forwardPayload['captured_at_utc'] = $createdAt;
    $forwardPayload['captured_by'] = 'nelliesbsfl_offer_board_submit';

    $body = http_build_query($forwardPayload);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($formspreeEndpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            @curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            @file_get_contents($formspreeEndpoint, false, $context);
        }
    } catch (Throwable $e) {
        // no-op (DB already contains the request)
    }
}

offer_board_json_response(200, ['success' => true, 'id' => $requestId]);

