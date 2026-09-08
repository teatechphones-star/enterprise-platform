<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';

if (Auth::check()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } elseif (Auth::login($username, $password)) {
        Auth::audit('auth', 'login', "User logged in: $username");
        redirect('index.php');
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= APP_NAME ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: linear-gradient(135deg,#0f2027,#203a43,#2c5364); min-height:100vh; display:flex; align-items:center; justify-content:center; color:#fff; }
  .card { background:#fff; color:#333; border-radius:12px; padding:40px; width:360px; box-shadow:0 20px 40px rgba(0,0,0,.4); }
  .card h1 { font-size:20px; margin-bottom:4px; color:#0f2027; }
  .card p.sub { color:#777; margin-bottom:24px; font-size:13px; }
  label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#444; }
  input { width:100%; padding:11px 12px; border:1px solid #d0d0d0; border-radius:8px; margin-bottom:16px; font-size:14px; }
  input:focus { outline:none; border-color:#2c5364; }
  button { width:100%; padding:12px; background:#2c5364; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { background:#1f3a47; }
  .error { background:#fdecea; color:#b71c1c; padding:10px; border-radius:8px; margin-bottom:16px; font-size:13px; }
  a { color:#2c5364; }
</style>
</head>
<body>
  <div class="card">
    <h1><?= APP_NAME ?></h1>
    <p class="sub">Sign in to manage your company platform</p>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="login.php">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
      <button type="submit">Sign In</button>
    </form>
  </div>
</body>
</html>
