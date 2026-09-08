<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getInstance();

// KPI counts for the dashboard
$totalEmployees = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees");
$totalDepartments = (int)$db->fetchColumn("SELECT COUNT(*) FROM departments");
$today = date('Y-m-d');
$presentToday = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM attendance_records WHERE work_date = :d AND status IN ('Present','Late','Overtime')",
    ['d' => $today]
);
$openCases = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM disciplinary_cases WHERE status NOT IN ('Resolved','Closed')"
);
$pendingLeave = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'"
);
$cctvAlerts = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM cctv_alerts WHERE is_read = 0"
);
$camerasOnline = (int)$db->fetchColumn("SELECT COUNT(*) FROM cameras WHERE status = 'Online'");
$plantEntries = (int)$db->fetchColumn("SELECT COUNT(*) FROM plantlog_entries WHERE work_date = :d", ['d' => $today]);
$unreadNotifs = (int)$db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0", ['u' => Auth::id()]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - <?= APP_NAME ?></title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:-apple-system,Segoe UI,Roboto,sans-serif; background:#f4f6f9; color:#1f2d3d; }
  .topbar { background:#0f2027; color:#fff; padding:14px 22px; display:flex; align-items:center; justify-content:space-between; }
  .topbar .brand { font-size:16px; font-weight:700; }
  .topbar .user { display:flex; align-items:center; gap:14px; font-size:13px; }
  .topbar a { color:#9fd3c7; text-decoration:none; }
  .wrap { max-width:1200px; margin:22px auto; padding:0 18px; }
  .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
  .card { background:#fff; border-radius:10px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,.06); }
  .card .num { font-size:26px; font-weight:800; color:#0f2027; }
  .card .lbl { font-size:12px; color:#7b8a9b; margin-top:4px; }
  .section { background:#fff; border-radius:10px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,.06); margin-bottom:18px; }
  .section h2 { font-size:15px; margin-bottom:12px; color:#0f2027; }
  .modules { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:22px; }
  .mod { background:#fff; border-radius:10px; padding:18px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.07); text-decoration:none; color:#1f2d3d; display:block; }
  .mod:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(0,0,0,.12); }
  .mod .ico { font-size:30px; }
  .mod .t { font-weight:700; margin-top:10px; font-size:14px; }
  .mod .d { font-size:11px; color:#7b8a9b; margin-top:4px; }
  .badge { background:#e53935; color:#fff; border-radius:50%; padding:2px 7px; font-size:11px; }
</style>
</head>
<body>
  <div class="topbar">
    <div class="brand">🛡️ <?= APP_NAME ?></div>
    <div class="user">
      <span>👤 <?= e($user['full_name']) ?> (<?= e($user['role']) ?>)</span>
      <a href="logout.php">Logout</a>
    </div>
  </div>
  <div class="wrap">
    <h2 style="margin-bottom:16px;">Welcome back, <?= e($user['full_name']) ?> 👋</h2>

    <div class="modules">
      <a class="mod" href="plantlog.php"><div class="ico">🏭</div><div class="t">Plant Log</div><div class="d">Daily production &amp; incidents</div></a>
      <a class="mod" href="hr.php"><div class="ico">👥</div><div class="t">HR Management</div><div class="d">Employees, leave &amp; notes</div></a>
      <a class="mod" href="attendance.php"><div class="ico">⏱️</div><div class="t">Attendance</div><div class="d">Clock in/out &amp; reports</div></a>
      <a class="mod" href="cctv.php"><div class="ico">📹</div><div class="t">CCTV Monitoring</div><div class="d">Cameras &amp; security events</div></a>
      <a class="mod" href="index.php"><div class="ico">📊</div><div class="t">Admin Dashboard</div><div class="d">Company-wide overview</div></a>
      <a class="mod" href="users.php"><div class="ico">🔐</div><div class="t">User Management</div><div class="d">Users &amp; permissions</div></a>
    </div>

    <div class="cards">
      <div class="card"><div class="num"><?= $totalEmployees ?></div><div class="lbl">Total Employees</div></div>
      <div class="card"><div class="num"><?= $presentToday ?></div><div class="lbl">Present Today</div></div>
      <div class="card"><div class="num"><?= $totalDepartments ?></div><div class="lbl">Departments</div></div>
      <div class="card"><div class="num"><?= $openCases ?></div><div class="lbl">Open HR Cases</div></div>
      <div class="card"><div class="num"><?= $pendingLeave ?></div><div class="lbl">Pending Leave</div></div>
      <div class="card"><div class="num"><?= $camerasOnline ?></div><div class="lbl">Cameras Online</div></div>
      <div class="card"><div class="num"><?= $cctvAlerts ?></div><div class="lbl">Unread CCTV Alerts</div></div>
      <div class="card"><div class="num"><?= $plantEntries ?></div><div class="lbl">Plant Entries Today</div></div>
    </div>

    <div class="section">
      <h2>🔔 Recent Notifications <?php if ($unreadNotifs): ?><span class="badge"><?= $unreadNotifs ?></span><?php endif; ?></h2>
      <?php
      $notifs = $db->fetchAll("SELECT * FROM notifications WHERE user_id = :u ORDER BY created_at DESC LIMIT 5", ['u' => Auth::id()]);
      if (!$notifs): ?><p style="color:#7b8a9b;font-size:13px;">No notifications yet.</p><?php
      else: foreach ($notifs as $n): ?>
        <p style="font-size:13px;padding:6px 0;border-bottom:1px solid #eee;"><strong><?= e($n['title']) ?></strong> — <?= e($n['message']) ?></p>
      <?php endforeach; endif; ?>
    </div>
  </div>
</body>
</html>
