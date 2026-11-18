<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAuth();

$dbDir = realpath(__DIR__ . '/../data');
$dbFile = $dbDir ? ($dbDir . '/form_submissions.sqlite') : '';

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
        $stmt = $pdo->query(
            'SELECT id, created_at, form_name, name, email, forwarded, forward_http_code, forward_error, referer, data_json
             FROM form_submissions
             ORDER BY id DESC
             LIMIT 250'
        );
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
  <title>Form Submissions - Admin</title>
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
        <h1 class="page-hero__title">Form submissions</h1>
        <p class="page-hero__subtitle">Showing the 250 most recent captured submissions (forwarded to Formspree when enabled).</p>
        <div class="page-hero__meta">
          <a class="btn btn--secondary" href="/admin/offer-board.php">Offer board</a>
          <a class="btn btn--secondary" href="/admin/submissions.php">Customer registrations</a>
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
          <table class="table" aria-label="Captured form submissions">
            <thead>
              <tr>
                <th>ID</th>
                <th>Submitted (UTC)</th>
                <th>Form</th>
                <th>Name</th>
                <th>Email</th>
                <th>Forwarded</th>
                <th>Referer</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr><td colspan="8">No submissions yet.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $forwarded = (int)($row['forwarded'] ?? 0) === 1;
                    $forwardMeta = $forwarded
                      ? 'Yes'
                      : ('No' . ((string)($row['forward_http_code'] ?? '') !== '' ? ' (' . (int)$row['forward_http_code'] . ')' : ''));
                    $formName = (string)($row['form_name'] ?? '');
                    $referer = (string)($row['referer'] ?? '');
                    $detailsPreview = '';
                    $truncate = static function(string $text, int $limit): string {
                      $text = (string)$text;
                      if ($limit <= 0) return '';
                      if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                        return mb_substr($text, 0, $limit) . (mb_strlen($text) > $limit ? '…' : '');
                      }
                      return substr($text, 0, $limit) . (strlen($text) > $limit ? '…' : '');
                    };
                    try {
                      $payload = json_decode((string)$row['data_json'], true, 512, JSON_THROW_ON_ERROR);
                      if (is_array($payload)) {
                        $msg = '';
                        if (isset($payload['message'])) $msg = (string)$payload['message'];
                        if ($msg === '' && isset($payload['additional_notes'])) $msg = (string)$payload['additional_notes'];
                        $msg = trim($msg);
                        if ($msg !== '') {
                          $detailsPreview = $truncate($msg, 240);
                        }
                      }
                    } catch (Throwable $e) {
                      $detailsPreview = '';
                    }
                  ?>
                  <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo safe((string)$row['created_at']); ?></td>
                    <td><?php echo safe($formName); ?></td>
                    <td><?php echo safe((string)($row['name'] ?? '')); ?></td>
                    <td><?php echo safe((string)($row['email'] ?? '')); ?></td>
                    <td>
                      <strong style="color: <?php echo $forwarded ? 'rgba(16,185,129,0.95)' : 'rgba(239,68,68,0.95)'; ?>">
                        <?php echo safe($forwardMeta); ?>
                      </strong>
                      <?php if (!$forwarded && (string)($row['forward_error'] ?? '') !== ''): ?>
                        <div style="color: rgba(255,255,255,0.75); font-size: 0.9rem; margin-top: 0.25rem;">
                          <?php echo safe((string)$row['forward_error']); ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td style="max-width: 280px;">
                      <?php echo $referer !== '' ? safe($referer) : '—'; ?>
                    </td>
                    <td>
                      <details>
                        <summary>View</summary>
                        <?php if ($detailsPreview !== ''): ?>
                          <pre style="white-space: pre-wrap; color: rgba(255,255,255,0.85); margin-top: 0.75rem;"><?php echo safe($detailsPreview); ?></pre>
                        <?php endif; ?>
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

