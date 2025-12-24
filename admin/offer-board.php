<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAuth();

require_once __DIR__ . '/../api/offer_board_db.php';

function ob_safe(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ob_now_utc(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function ob_month_bucket_from_start(string $deliveryStart): string {
    $mb = offer_board_month_bucket_from_date($deliveryStart);
    return $mb !== '' ? $mb : ob_now_utc()->format('Y-m');
}

function ob_month_label_for_bucket(string $mb): string {
    $dt = DateTimeImmutable::createFromFormat('Y-m', $mb, new DateTimeZone('UTC'));
    return $dt ? $dt->format('M Y') : $mb;
}

$pdo = offer_board_pdo();
offer_board_init_schema($pdo);

$flash = '';
$flashType = 'success';

// Handle admin actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $actor = (string)($_SESSION['username'] ?? 'admin');
    $createdAt = ob_now_utc()->format('c');

    try {
        if ($action === 'update_request') {
            $requestId = trim((string)($_POST['request_id'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $reviewNotes = trim((string)($_POST['review_notes_internal'] ?? ''));

            $allowedStatus = ['submitted', 'under_review', 'accepted', 'rejected', 'fulfilled', 'expired'];
            if ($requestId === '' || !in_array($status, $allowedStatus, true)) {
                throw new RuntimeException('Invalid request update.');
            }

            $stmt = $pdo->prepare('UPDATE buyer_requests SET status = :status, review_notes_internal = :notes WHERE id = :id');
            $stmt->execute([
                ':status' => $status,
                ':notes' => ($reviewNotes !== '' ? $reviewNotes : null),
                ':id' => $requestId,
            ]);

            $audit = $pdo->prepare('INSERT INTO request_audit (created_at, actor, request_id, action, meta_json) VALUES (:created_at, :actor, :request_id, :action, :meta_json)');
            $audit->execute([
                ':created_at' => $createdAt,
                ':actor' => $actor,
                ':request_id' => $requestId,
                ':action' => 'status_updated',
                ':meta_json' => json_encode(['status' => $status], JSON_UNESCAPED_SLASHES),
            ]);

            $flash = 'Request updated.';
        } elseif ($action === 'publish_fulfillment') {
            $requestId = trim((string)($_POST['request_id'] ?? ''));
            $fulfilledDate = trim((string)($_POST['fulfilled_date'] ?? ''));
            $monthBucket = trim((string)($_POST['month_bucket'] ?? ''));
            $grade = strtoupper(trim((string)($_POST['grade'] ?? '')));
            $format = strtolower(trim((string)($_POST['format'] ?? '')));
            $quantity = (int)($_POST['quantity_lbs'] ?? 0);
            $deliveryWindow = trim((string)($_POST['delivery_window'] ?? ''));
            $region = trim((string)($_POST['region'] ?? ''));
            $notePublic = trim((string)($_POST['note_public'] ?? ''));

            if ($requestId === '') throw new RuntimeException('Missing request id.');
            if ($fulfilledDate === '' || DateTimeImmutable::createFromFormat('Y-m-d', $fulfilledDate) === false) {
                throw new RuntimeException('Invalid fulfilled date.');
            }
            if (!preg_match('/^\d{4}-\d{2}$/', $monthBucket)) throw new RuntimeException('Invalid month bucket.');
            if (!in_array($grade, ['A', 'B'], true)) throw new RuntimeException('Invalid grade.');
            if (!in_array($format, ['bulk', 'packaged'], true)) throw new RuntimeException('Invalid format.');
            if ($quantity <= 0) throw new RuntimeException('Quantity must be > 0.');
            if ($deliveryWindow === '') throw new RuntimeException('Delivery window is required.');
            if ($region === '') throw new RuntimeException('Region is required.');

            $id = offer_board_uuidv4();
            $stmt = $pdo->prepare(
                'INSERT INTO market_fulfillments (
                    id, created_at, fulfilled_date, month_bucket, grade, format, quantity_lbs, delivery_window, region, note_public, linked_request_id
                 ) VALUES (
                    :id, :created_at, :fulfilled_date, :month_bucket, :grade, :format, :quantity_lbs, :delivery_window, :region, :note_public, :linked_request_id
                 )'
            );
            $stmt->execute([
                ':id' => $id,
                ':created_at' => $createdAt,
                ':fulfilled_date' => $fulfilledDate,
                ':month_bucket' => $monthBucket,
                ':grade' => $grade,
                ':format' => $format,
                ':quantity_lbs' => $quantity,
                ':delivery_window' => $deliveryWindow,
                ':region' => $region,
                ':note_public' => ($notePublic !== '' ? $notePublic : null),
                ':linked_request_id' => $requestId,
            ]);

            $pdo->prepare('UPDATE buyer_requests SET status = :status WHERE id = :id')->execute([
                ':status' => 'fulfilled',
                ':id' => $requestId,
            ]);

            $audit = $pdo->prepare('INSERT INTO request_audit (created_at, actor, request_id, action, meta_json) VALUES (:created_at, :actor, :request_id, :action, :meta_json)');
            $audit->execute([
                ':created_at' => $createdAt,
                ':actor' => $actor,
                ':request_id' => $requestId,
                ':action' => 'fulfillment_published',
                ':meta_json' => json_encode(['month_bucket' => $monthBucket, 'quantity_lbs' => $quantity], JSON_UNESCAPED_SLASHES),
            ]);

            $flash = 'Fulfillment published to market feed and request marked fulfilled.';
        } elseif ($action === 'update_capacity') {
            // Accept multiple month_bucket/capacity_lbs pairs
            $months = $_POST['month_bucket'] ?? [];
            $caps = $_POST['capacity_lbs'] ?? [];
            if (!is_array($months) || !is_array($caps)) throw new RuntimeException('Invalid payload.');

            $updated = 0;
            foreach ($months as $idx => $mb) {
                $mb = trim((string)$mb);
                $cap = isset($caps[$idx]) ? (int)$caps[$idx] : 0;
                if ($mb === '' || !preg_match('/^\d{4}-\d{2}$/', $mb)) continue;
                if ($cap < 0) $cap = 0;

                $stmt = $pdo->prepare(
                    'INSERT INTO monthly_capacity (month_bucket, capacity_lbs, reserved_lbs, updated_at)
                     VALUES (:month_bucket, :capacity_lbs, 0, :updated_at)
                     ON CONFLICT(month_bucket) DO UPDATE SET capacity_lbs = excluded.capacity_lbs, updated_at = excluded.updated_at'
                );
                $stmt->execute([
                    ':month_bucket' => $mb,
                    ':capacity_lbs' => $cap,
                    ':updated_at' => $createdAt,
                ]);
                $updated++;
            }

            $audit = $pdo->prepare('INSERT INTO request_audit (created_at, actor, request_id, action, meta_json) VALUES (:created_at, :actor, NULL, :action, :meta_json)');
            $audit->execute([
                ':created_at' => $createdAt,
                ':actor' => $actor,
                ':action' => 'capacity_updated',
                ':meta_json' => json_encode(['updated_rows' => $updated], JSON_UNESCAPED_SLASHES),
            ]);

            $flash = 'Monthly capacity updated.';
        }
    } catch (Throwable $e) {
        $flashType = 'error';
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

// Filters
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterGrade = strtoupper(trim((string)($_GET['grade'] ?? '')));
$filterFormat = strtolower(trim((string)($_GET['format'] ?? '')));
$filterMonth = trim((string)($_GET['month'] ?? '')); // YYYY-MM (delivery start month)
$sort = trim((string)($_GET['sort'] ?? 'created_at_desc'));

$where = [];
$params = [];

if ($filterStatus !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterGrade !== '') {
    $where[] = 'grade = :grade';
    $params[':grade'] = $filterGrade;
}
if ($filterFormat !== '') {
    $where[] = 'format = :format';
    $params[':format'] = $filterFormat;
}
if ($filterMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $where[] = 'substr(delivery_start_date, 1, 7) = :month';
    $params[':month'] = $filterMonth;
}

$orderBy = 'created_at DESC';
if ($sort === 'price_asc') $orderBy = 'target_price_per_lb ASC';
if ($sort === 'price_desc') $orderBy = 'target_price_per_lb DESC';
if ($sort === 'qty_asc') $orderBy = 'quantity_lbs ASC';
if ($sort === 'qty_desc') $orderBy = 'quantity_lbs DESC';
if ($sort === 'created_at_asc') $orderBy = 'created_at ASC';

$sql = 'SELECT * FROM buyer_requests';
if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ' . $orderBy . ' LIMIT 250';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Capacity editor data (next 12 months)
$months = [];
$base = ob_now_utc()->modify('first day of this month');
for ($i = 0; $i < 12; $i++) {
    $mb = $base->modify("+{$i} month")->format('Y-m');
    $months[] = $mb;
}
$capStmt = $pdo->prepare('SELECT month_bucket, capacity_lbs FROM monthly_capacity WHERE month_bucket IN (' . implode(',', array_fill(0, count($months), '?')) . ')');
$capStmt->execute($months);
$capRows = $capStmt->fetchAll();
$capMap = [];
foreach ($capRows as $r) {
    $capMap[(string)$r['month_bucket']] = (int)$r['capacity_lbs'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Offer Board - Admin | Nellie's BSFL</title>
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
        <h1 class="page-hero__title">Offer board</h1>
        <p class="page-hero__subtitle">Review requests, publish anonymized fulfillments, and manage monthly capacity.</p>
        <div class="page-hero__meta">
          <a class="btn btn--secondary" href="/admin/submissions.php">Customer registrations</a>
          <a class="btn btn--secondary" href="/admin/logout.php">Logout</a>
        </div>
      </div>
    </section>

    <section class="page-section page-section--alt">
      <div class="page-section__container">
        <?php if ($flash !== ''): ?>
          <div class="page-card" style="border-color: <?php echo $flashType === 'error' ? 'rgba(239, 68, 68, 0.55)' : 'rgba(16, 185, 129, 0.45)'; ?>;">
            <p style="color: <?php echo $flashType === 'error' ? 'rgba(255, 180, 180, 0.95)' : 'rgba(208, 255, 236, 0.92)'; ?>; font-weight: 600;">
              <?php echo ob_safe($flash); ?>
            </p>
          </div>
        <?php endif; ?>

        <div class="page-card" style="margin-bottom: 2rem;">
          <h2 style="margin-bottom: 0.75rem;">Filters</h2>
          <form method="GET" action="/admin/offer-board.php" class="onboarding-grid" style="margin-top: 0.5rem;">
            <div class="form__group">
              <label for="status">Status</label>
              <select class="form__input" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['submitted','under_review','accepted','rejected','fulfilled','expired'] as $s): ?>
                  <option value="<?php echo ob_safe($s); ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo ob_safe($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form__group">
              <label for="grade">Grade</label>
              <select class="form__input" id="grade" name="grade">
                <option value="">All</option>
                <option value="A" <?php echo $filterGrade === 'A' ? 'selected' : ''; ?>>A</option>
                <option value="B" <?php echo $filterGrade === 'B' ? 'selected' : ''; ?>>B</option>
              </select>
            </div>
            <div class="form__group">
              <label for="format">Format</label>
              <select class="form__input" id="format" name="format">
                <option value="">All</option>
                <option value="bulk" <?php echo $filterFormat === 'bulk' ? 'selected' : ''; ?>>bulk</option>
                <option value="packaged" <?php echo $filterFormat === 'packaged' ? 'selected' : ''; ?>>packaged</option>
              </select>
            </div>
            <div class="form__group">
              <label for="month">Delivery month</label>
              <input class="form__input" id="month" name="month" type="text" placeholder="YYYY-MM" value="<?php echo ob_safe($filterMonth); ?>" />
            </div>
            <div class="form__group">
              <label for="sort">Sort</label>
              <select class="form__input" id="sort" name="sort">
                <option value="created_at_desc" <?php echo $sort === 'created_at_desc' ? 'selected' : ''; ?>>Newest</option>
                <option value="created_at_asc" <?php echo $sort === 'created_at_asc' ? 'selected' : ''; ?>>Oldest</option>
                <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price (high→low)</option>
                <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price (low→high)</option>
                <option value="qty_desc" <?php echo $sort === 'qty_desc' ? 'selected' : ''; ?>>Quantity (high→low)</option>
                <option value="qty_asc" <?php echo $sort === 'qty_asc' ? 'selected' : ''; ?>>Quantity (low→high)</option>
              </select>
            </div>
            <div class="form__group" style="align-self: end;">
              <button class="btn btn--primary" type="submit">Apply</button>
            </div>
          </form>
        </div>

        <div class="page-card" style="overflow-x: auto; margin-bottom: 2rem;">
          <h2 style="margin-bottom: 0.75rem;">Buyer requests</h2>
          <p style="color: rgba(255,255,255,0.75); margin-bottom: 1rem;">Showing up to 250. Pricing is private (admin-only).</p>

          <table class="table" aria-label="Offer board buyer requests">
            <thead>
              <tr>
                <th>Submitted (UTC)</th>
                <th>Status</th>
                <th>Company</th>
                <th>Region</th>
                <th>Grade / Format</th>
                <th>Qty (lbs)</th>
                <th>Target $/lb</th>
                <th>Delivery start</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($requests) === 0): ?>
                <tr><td colspan="9">No requests found.</td></tr>
              <?php else: ?>
                <?php foreach ($requests as $r): ?>
                  <?php
                    $rid = (string)$r['id'];
                    $deliveryStart = (string)$r['delivery_start_date'];
                    $mb = ob_month_bucket_from_start($deliveryStart);
                    $defaultFulfilled = ob_now_utc()->format('Y-m-d');
                    $defaultWindow = ob_month_label_for_bucket($mb);
                  ?>
                  <tr>
                    <td><?php echo ob_safe((string)$r['created_at']); ?></td>
                    <td><strong><?php echo ob_safe((string)$r['status']); ?></strong></td>
                    <td>
                      <?php echo ob_safe((string)$r['company_name']); ?><br/>
                      <span style="color: rgba(255,255,255,0.72); font-size: 0.9rem;"><?php echo ob_safe((string)$r['contact_name']); ?> · <?php echo ob_safe((string)$r['email']); ?></span>
                    </td>
                    <td><?php echo ob_safe((string)$r['shipping_region']); ?></td>
                    <td><?php echo ob_safe((string)$r['grade']); ?> / <?php echo ob_safe((string)$r['format']); ?></td>
                    <td><?php echo (int)$r['quantity_lbs']; ?></td>
                    <td><?php echo number_format((float)$r['target_price_per_lb'], 2); ?></td>
                    <td><?php echo ob_safe($deliveryStart); ?></td>
                    <td>
                      <details>
                        <summary>Manage</summary>

                        <div style="margin-top: 0.75rem; display: grid; gap: 1rem;">
                          <div>
                            <strong>Notes</strong>
                            <div style="color: rgba(255,255,255,0.82); margin-top: 0.25rem;">
                              <?php echo ob_safe((string)($r['additional_notes'] ?? '')); ?>
                            </div>
                            <?php if ((int)$r['packaging_private_label'] === 1): ?>
                              <div style="margin-top: 0.5rem; color: rgba(255,255,255,0.82);">
                                <strong>Private label notes:</strong> <?php echo ob_safe((string)($r['packaging_notes'] ?? '')); ?>
                              </div>
                            <?php endif; ?>
                          </div>

                          <form method="POST" action="/admin/offer-board.php">
                            <input type="hidden" name="action" value="update_request" />
                            <input type="hidden" name="request_id" value="<?php echo ob_safe($rid); ?>" />
                            <div class="onboarding-grid">
                              <div class="form__group">
                                <label>Status</label>
                                <select class="form__input" name="status">
                                  <?php foreach (['submitted','under_review','accepted','rejected','fulfilled','expired'] as $s): ?>
                                    <option value="<?php echo ob_safe($s); ?>" <?php echo ((string)$r['status'] === $s) ? 'selected' : ''; ?>><?php echo ob_safe($s); ?></option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div class="form__group">
                                <label>Internal review notes (admin only)</label>
                                <textarea class="form__textarea" name="review_notes_internal" rows="3" placeholder="Notes for internal use..."><?php echo ob_safe((string)($r['review_notes_internal'] ?? '')); ?></textarea>
                              </div>
                            </div>
                            <button class="btn btn--primary" type="submit">Save</button>
                          </form>

                          <details>
                            <summary>Publish fulfillment to market feed</summary>
                            <form method="POST" action="/admin/offer-board.php" style="margin-top: 0.75rem;">
                              <input type="hidden" name="action" value="publish_fulfillment" />
                              <input type="hidden" name="request_id" value="<?php echo ob_safe($rid); ?>" />

                              <div class="onboarding-grid">
                                <div class="form__group">
                                  <label>Fulfilled date</label>
                                  <input class="form__input" type="date" name="fulfilled_date" value="<?php echo ob_safe($defaultFulfilled); ?>" required />
                                </div>
                                <div class="form__group">
                                  <label>Month bucket (YYYY-MM)</label>
                                  <input class="form__input" type="text" name="month_bucket" value="<?php echo ob_safe($mb); ?>" required />
                                </div>
                                <div class="form__group">
                                  <label>Grade</label>
                                  <select class="form__input" name="grade" required>
                                    <option value="A" <?php echo ((string)$r['grade'] === 'A') ? 'selected' : ''; ?>>A</option>
                                    <option value="B" <?php echo ((string)$r['grade'] === 'B') ? 'selected' : ''; ?>>B</option>
                                  </select>
                                </div>
                                <div class="form__group">
                                  <label>Format</label>
                                  <select class="form__input" name="format" required>
                                    <option value="bulk" <?php echo ((string)$r['format'] === 'bulk') ? 'selected' : ''; ?>>bulk</option>
                                    <option value="packaged" <?php echo ((string)$r['format'] === 'packaged') ? 'selected' : ''; ?>>packaged</option>
                                  </select>
                                </div>
                                <div class="form__group">
                                  <label>Quantity (lbs)</label>
                                  <input class="form__input" type="number" name="quantity_lbs" min="1" step="1" value="<?php echo (int)$r['quantity_lbs']; ?>" required />
                                </div>
                                <div class="form__group">
                                  <label>Delivery window</label>
                                  <input class="form__input" type="text" name="delivery_window" value="<?php echo ob_safe($defaultWindow); ?>" placeholder="Jan 2026 / Q1 2026" required />
                                </div>
                                <div class="form__group">
                                  <label>Region (broad)</label>
                                  <input class="form__input" type="text" name="region" value="<?php echo ob_safe((string)$r['shipping_region']); ?>" required />
                                </div>
                                <div class="form__group">
                                  <label>Public note (optional)</label>
                                  <input class="form__input" type="text" name="note_public" placeholder="Private label onboarding started" />
                                </div>
                              </div>
                              <button class="btn btn--primary" type="submit">Publish fulfillment</button>
                              <p style="margin-top: 0.5rem; color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                                This posts an anonymized fulfillment to the public feed. No buyer identity or pricing is shown publicly.
                              </p>
                            </form>
                          </details>
                        </div>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="page-card">
          <h2 style="margin-bottom: 0.75rem;">Monthly capacity (next 12 months)</h2>
          <p style="color: rgba(255,255,255,0.75); margin-bottom: 1rem;">Public “remaining capacity” is computed as capacity minus published fulfillments for that month.</p>

          <form method="POST" action="/admin/offer-board.php">
            <input type="hidden" name="action" value="update_capacity" />
            <div class="onboarding-table">
              <div class="onboarding-table__head">
                <div>Month</div>
                <div>Label</div>
                <div>Capacity (lbs)</div>
                <div>—</div>
              </div>
              <?php foreach ($months as $idx => $mb): ?>
                <div class="onboarding-table__row">
                  <div>
                    <input class="form__input" type="text" name="month_bucket[]" value="<?php echo ob_safe($mb); ?>" readonly />
                  </div>
                  <div style="color: rgba(255,255,255,0.85); padding-top: 0.6rem;">
                    <?php echo ob_safe(ob_month_label_for_bucket($mb)); ?>
                  </div>
                  <div>
                    <input class="form__input" type="number" name="capacity_lbs[]" min="0" step="1" value="<?php echo (int)($capMap[$mb] ?? 0); ?>" />
                  </div>
                  <div style="color: rgba(255,255,255,0.72); padding-top: 0.6rem;">
                    lbs
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="onboarding-actions">
              <button class="btn btn--primary" type="submit">Save capacity</button>
              <p class="onboarding-actions__note">Tip: set this month and next month first to create urgency on the public Offer Board.</p>
            </div>
          </form>
        </div>
      </div>
    </section>
  </main>
</body>
</html>

