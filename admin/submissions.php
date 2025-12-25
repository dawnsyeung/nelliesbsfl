<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAuth();

$dbDir = realpath(__DIR__ . '/../data');
$dbFile = $dbDir ? ($dbDir . '/customer_registrations.sqlite') : '';

$rows = [];
$error = '';

try {
    if ($dbFile === '' || !file_exists($dbFile)) {
        $rows = [];
    } else {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $pdo->query('SELECT id, created_at, legal_business_name, primary_contact_email, data_json FROM customer_registrations ORDER BY id DESC LIMIT 250');
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error = 'Failed to load submissions.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Customer Registrations - Admin</title>
  <link rel="stylesheet" href="/style.css" />
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-17813054995"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'AW-17813054995');
</script>
</head>
<body class="page">
  <main>
    <section class="page-hero">
      <div class="page-hero__container">
        <p class="page-hero__eyebrow">Admin</p>
        <h1 class="page-hero__title">Customer registrations</h1>
        <p class="page-hero__subtitle">Showing the 250 most recent submissions.</p>
        <div class="page-hero__meta">
          <a class="btn btn--secondary" href="/admin/offer-board.php">Offer board</a>
          <a class="btn btn--secondary" href="/admin/form-submissions.php">Form submissions</a>
          <a class="btn btn--secondary" href="/admin/logout.php">Logout</a>
        </div>
      </div>
    </section>

    <section class="page-section page-section--alt">
      <div class="page-section__container">
        <?php if ($error !== ''): ?>
          <div class="page-card">
            <p style="color: #ffb4a6; font-weight: 600;"><?php echo safe($error); ?></p>
          </div>
        <?php endif; ?>

        <div class="page-card" style="overflow-x: auto;">
          <table class="table" aria-label="Customer registration submissions">
            <thead>
              <tr>
                <th>ID</th>
                <th>Submitted (UTC)</th>
                <th>Legal Business Name</th>
                <th>Primary Contact Email</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr>
                  <td colspan="5">No submissions yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $payload = [];
                    try {
                      $payload = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) {
                      $payload = [];
                    }
                    $summary = '';
                    if (is_array($payload)) {
                      $parts = [];
                      $parts[] = 'Primary contact: ' . (($payload['primary_contact_name'] ?? '') ?: '—');
                      $parts[] = 'Phone: ' . (($payload['primary_contact_phone'] ?? '') ?: '—');
                      $parts[] = 'Main phone: ' . (($payload['main_phone'] ?? '') ?: '—');
                      $parts[] = 'Shipping city: ' . (($payload['delivery_city'] ?? '') ?: '—');
                      $parts[] = 'Intended use: ' . (is_array($payload['intended_use'] ?? null) ? implode(', ', $payload['intended_use']) : (($payload['intended_use'] ?? '') ?: '—'));
                      $summary = implode("\n", $parts);
                    }
                  ?>
                  <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo safe((string)$row['created_at']); ?></td>
                    <td><?php echo safe((string)$row['legal_business_name']); ?></td>
                    <td><?php echo safe((string)$row['primary_contact_email']); ?></td>
                    <td>
                      <details>
                        <summary>View</summary>
                        <pre style="white-space: pre-wrap; color: rgba(255,255,255,0.85); margin-top: 0.75rem;"><?php echo safe($summary); ?></pre>
                        <details style="margin-top: 0.75rem;">
                          <summary>Raw JSON</summary>
                          <pre style="white-space: pre-wrap; color: rgba(255,255,255,0.85); margin-top: 0.75rem;"><?php echo safe((string)$row['data_json']); ?></pre>
                        </details>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</body>
</html>

