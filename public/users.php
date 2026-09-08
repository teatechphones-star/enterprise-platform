<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireRole(['Super Admin','Administrator']);

$db = Database::getInstance();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['do'] ?? '') === 'add_user') {
    $db->query(
        "INSERT INTO users (username, password_hash, full_name, email, role_id, is_active)
         VALUES (:u,:h,:f,:e,:r,1)",
        [
            'u' => $_POST['username'] ?? '',
            'h' => password_hash($_POST['password'] ?? 'password123', PASSWORD_BCRYPT),
            'f' => $_POST['full_name'] ?? '',
            'e' => $_POST['email'] ?? null,
            'r' => (int)($_POST['role_id'] ?? 0) ?: null,
        ]
    );
    Auth::audit('users','add_user',"Created user {$_POST['username']}");
    $msg = 'User created.';
}
$users = $db->fetchAll("SELECT u.*, r.name role FROM users u LEFT JOIN roles r ON r.id=u.role_id ORDER BY u.username");
$roles = $db->fetchAll("SELECT * FROM roles ORDER BY name");
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>User Management - <?= APP_NAME ?></title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}.topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
.wrap{max-width:1000px;margin:22px auto;padding:0 18px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);margin-bottom:18px}
h2{font-size:16px;margin-bottom:14px;color:#0f2027}
label{display:block;font-size:12px;font-weight:600;margin:8px 0 4px;color:#444}
input,select{width:100%;padding:9px 10px;border:1px solid #d0d0d0;border-radius:7px;font-size:14px}
button{padding:10px 18px;background:#2c5364;color:#fff;border:none;border-radius:7px;font-weight:600;cursor:pointer;margin-top:12px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f0f4f7;font-size:12px;color:#0f2027}
.msg{background:#e6f4ea;color:#1e7e34;padding:10px;border-radius:7px;margin-bottom:14px;font-size:14px}
</style></head><body>
<div class="topbar"><div class="brand">👥 User Management</div><div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div></div>
<div class="wrap">
  <?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; ?>
  <div class="card"><h2>➕ Create User</h2>
    <form method="post" action="users.php?do=add_user" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0 14px">
      <div><label>Username</label><input name="username" required></div>
      <div><label>Full Name</label><input name="full_name" required></div>
      <div><label>Email</label><input name="email"></div>
      <div><label>Role</label><select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Default Password</label><input name="password" value="password123"></div>
      <div style="align-self:end"><button type="submit">💾 Create</button></div>
    </form>
  </div>
  <div class="card"><h2>👤 System Users (<?= count($users) ?>)</h2>
    <table><tr><th>Username</th><th>Full Name</th><th>Role</th><th>Last Login</th><th>Active</th></tr>
    <?php foreach ($users as $u): ?>
      <tr><td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td>
      <td><?= e($u['role'] ?? '-') ?></td><td><?= e($u['last_login'] ?? '-') ?></td>
      <td><?= $u['is_active'] ? '✅' : '❌' ?></td></tr>
    <?php endforeach; ?></table>
  </div>
</div></body></html>
