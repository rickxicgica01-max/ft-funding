<?php
require_once __DIR__ . '/init.php';

// Already in? Go straight to the dashboard.
if (is_logged_in()) { header('Location: dashboard.php'); exit; }

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wait = login_locked_seconds($ip);
    if ($wait > 0) {
        $error = 'Too many failed attempts. Please wait ' . ceil($wait / 60) . ' minute(s) and try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (password_verify($password, ADMIN_HASH)) {
            login_clear($ip);                // reset the counter on success
            session_regenerate_id(true);     // prevent session fixation
            $_SESSION['admin'] = true;
            header('Location: dashboard.php');
            exit;
        }
        login_register_failure($ip);
        usleep(400000);                      // brief delay slows automated guessing
        $error = 'Incorrect password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Sign in</title>
  <link rel="stylesheet" href="admin.css">
</head>
<body>
  <div class="login-shell">
    <div class="panel login-card">
      <h1>Blog Admin Sign In</h1>
      <?php if ($error): ?><div class="notice"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="login.php">
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Sign in</button>
      </form>
    </div>
  </div>
</body>
</html>
