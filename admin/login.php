<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

if (isAuthed()) {
    header('Location: /admin/offer-board/', true, 302);
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $expectedUser = env('ADMIN_USERNAME', 'admin');
    $expectedPass = env('ADMIN_PASSWORD', '');

    // Require ADMIN_PASSWORD to be set in environment (never hardcode)
    if ($expectedPass === '') {
        $error = 'Admin password is not configured on the server.';
    } else if (hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password)) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        $_SESSION['username'] = $username;
        header('Location: /admin/offer-board/', true, 302);
        exit();
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login - Nellie's BSFL</title>
  <link rel="stylesheet" href="/style.css" />
</head>
<body class="page">
  <main>
    <section class="page-hero">
      <div class="page-hero__container">
        <p class="page-hero__eyebrow">Admin</p>
        <h1 class="page-hero__title">Login</h1>
        <p class="page-hero__subtitle">Sign in to manage Offer Board requests.</p>
      </div>
    </section>

    <section class="page-section page-section--alt">
      <div class="page-section__container">
        <div class="page-card" style="max-width: 520px; margin: 0 auto;">
          <?php if ($error !== ''): ?>
            <p style="color: #ffb4a6; font-weight: 600; margin-bottom: 1rem;"><?php echo safe($error); ?></p>
          <?php endif; ?>

          <form method="POST" action="/admin/login.php" class="contact__form" style="max-width: 100%;">
            <div class="form__group">
              <label for="username">Username</label>
              <input class="form__input" id="username" name="username" type="text" autocomplete="username" required />
            </div>
            <div class="form__group">
              <label for="password">Password</label>
              <input class="form__input" id="password" name="password" type="password" autocomplete="current-password" required />
            </div>
            <button class="btn btn--primary" type="submit">Sign in</button>
          </form>
        </div>
      </div>
    </section>
  </main>
</body>
</html>

