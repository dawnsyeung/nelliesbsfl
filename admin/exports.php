<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Exports - Admin</title>
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
        <h1 class="page-hero__title">Exports</h1>
        <p class="page-hero__subtitle">Download a snapshot of your internal submission databases.</p>
        <div class="page-hero__meta">
          <a class="btn btn--secondary" href="/admin/offer-board.php">Offer board</a>
          <a class="btn btn--secondary" href="/admin/submissions.php">Customer registrations</a>
          <a class="btn btn--secondary" href="/admin/form-submissions.php">Form submissions</a>
          <a class="btn btn--secondary" href="/admin/logout.php">Logout</a>
        </div>
      </div>
    </section>

    <section class="page-section page-section--alt">
      <div class="page-section__container">
        <div class="page-card">
          <h2 style="margin-bottom: 0.75rem;">Download database snapshots</h2>
          <p style="color: rgba(255,255,255,0.78); margin-bottom: 1.25rem;">
            These links generate a fresh SQLite snapshot (when supported) and download it as a file.
          </p>

          <div class="page-section__grid page-section__grid--two-fixed">
            <div class="page-card">
              <h3 style="margin-bottom: 0.5rem;">Generic form submissions</h3>
              <p style="color: rgba(255,255,255,0.75); margin-bottom: 1rem;">Captured by <code>/api/form_capture.php</code>.</p>
              <a class="btn btn--primary" href="/admin/download-db.php?db=form_submissions">Download</a>
            </div>

            <div class="page-card">
              <h3 style="margin-bottom: 0.5rem;">Customer registrations</h3>
              <p style="color: rgba(255,255,255,0.75); margin-bottom: 1rem;">Captured by <code>/api/customer_registration.php</code>.</p>
              <a class="btn btn--primary" href="/admin/download-db.php?db=customer_registrations">Download</a>
            </div>

            <div class="page-card">
              <h3 style="margin-bottom: 0.5rem;">Offer board database</h3>
              <p style="color: rgba(255,255,255,0.75); margin-bottom: 1rem;">Requests + fulfillments + capacity.</p>
              <a class="btn btn--primary" href="/admin/download-db.php?db=offer_board">Download</a>
            </div>
          </div>

          <p style="color: rgba(255,255,255,0.7); margin-top: 1.25rem;">
            Tip: if your host uses SQLite WAL mode, the snapshot download avoids inconsistency.
          </p>
        </div>
      </div>
    </section>
  </main>
</body>
</html>

