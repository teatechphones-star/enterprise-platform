<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getInstance();
$today = date('Y-m-d');

// ---- KPI counts ----
$totalEmployees = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees");
$presentToday    = (int)$db->fetchColumn("SELECT COUNT(*) FROM attendance_records WHERE work_date=:d AND status IN ('Present','Late','Overtime')", ['d'=>$today]);
$onLeave         = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE status='On Leave'");
$plantEntries    = (int)$db->fetchColumn("SELECT COUNT(*) FROM plantlog_entries WHERE work_date=:d", ['d'=>$today]);
$pendingLeave    = (int)$db->fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'");
$openCases       = (int)$db->fetchColumn("SELECT COUNT(*) FROM disciplinary_cases WHERE status NOT IN ('Resolved','Closed')");
$camerasOnline   = (int)$db->fetchColumn("SELECT COUNT(*) FROM cameras WHERE status='Online'");
$unreadAlerts    = (int)$db->fetchColumn("SELECT COUNT(*) FROM cctv_alerts WHERE is_read=0");
$totalDepts      = (int)$db->fetchColumn("SELECT COUNT(*) FROM departments");
$absent          = max(0, $totalEmployees - $presentToday - $onLeave);

// ---- Recent module data ----
$recentPlant = $db->fetchAll("SELECT e.*, d.name dept FROM plantlog_entries e LEFT JOIN departments d ON d.id=e.department_id ORDER BY e.created_at DESC LIMIT 5");
$recentAttendance = $db->fetchAll("SELECT a.*, e.full_name FROM attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d ORDER BY a.clock_in DESC LIMIT 5", ['d'=>$today]);
$recentAlerts = $db->fetchAll("SELECT * FROM cctv_alerts ORDER BY created_at DESC LIMIT 5");
$pendingList = $db->fetchAll("SELECT lr.*, e.full_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.status='Pending' ORDER BY lr.created_at DESC LIMIT 5");
$auditRecent = $db->fetchAll("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 6");
$unreadNotifs = (int)$db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0", ['u'=>Auth::id()]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard - <?= APP_NAME ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}
.topbar .user{display:flex;align-items:center;gap:14px;font-size:13px}
.topbar a{color:#9fd3c7;text-decoration:none}
.wrap{max-width:1200px;margin:22px auto;padding:0 18px}
h2.welcome{font-size:20px;margin-bottom:16px;color:#0f2027}
.modules{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px}
.mod{background:#fff;border-radius:10px;padding:18px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.07);text-decoration:none;color:#1f2d3d;display:block}
.mod:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(0,0,0,.12)}
.mod .ico{font-size:30px}.mod .t{font-weight:700;margin-top:10px;font-size:14px}
.mod .d{font-size:11px;color:#7b8a9b;margin-top:4px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:22px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.card .num{font-size:26px;font-weight:800;color:#0f2027}
.card .lbl{font-size:12px;color:#7b8a9b;margin-top:4px}
.panel{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.section{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.section h2{font-size:14px;margin-bottom:12px;color:#0f2027;border-bottom:1px solid #eef2f5;padding-bottom:8px}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:7px 8px;text-align:left;border-bottom:1px solid #f0f3f6}
th{color:#7b8a9b;font-weight:600;font-size:11px;text-transform:uppercase}
.lv{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.Info{background:#e3f2fd;color:#1565c0}.Warning{background:#fff3e0;color:#e65100}.Critical{background:#fdecea;color:#b71c1c}
.health{display:flex;gap:20px;flex-wrap:wrap;font-size:13px}
.hitem{background:#edf3f7;padding:8px 14px;border-radius:8px}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
.g{background:#2e7d32}.r{background:#c62828}.o{background:#e65100}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">🛡️ <?= APP_NAME ?></div>
  <div class="user"><span>👤 <?= e($user['full_name']) ?> (<?= e($user['role']) ?>)</span><a href="logout.php">Logout</a></div>
</div>
<div class="wrap">
  <h2 class="welcome">Welcome back, <?= e($user['full_name']) ?> 👋</h2>

  <div class="modules">
    <a class="mod" href="plantlog.php"><div class="ico">🏭</div><div class="t">Plant Log</div><div class="d">Production &amp; incidents</div></a>
    <a class="mod" href="hr.php"><div class="ico">👥</div><div class="t">HR Management</div><div class="d">Employees &amp; leave</div></a>
    <a class="mod" href="attendance.php"><div class="ico">⏱️</div><div class="t">Attendance</div><div class="d">Clock in/out &amp; status</div></a>
    <a class="mod" href="cctv.php"><div class="ico">📹</div><div class="t">CCTV Monitoring</div><div class="d">Cameras &amp; alerts</div></a>
    <a class="mod" href="ai.php"><div class="ico">🤖</div><div class="t">AI Assistant</div><div class="d">Ask about the company</div></a>
    <a class="mod" href="users.php"><div class="ico">🔐</div><div class="t">User Management</div><div class="d">Users &amp; permissions</div></a>
  </div>

  <div class="cards">
    <div class="card"><div class="num"><?= $totalEmployees ?></div><div class="lbl">Total Employees</div></div>
    <div class="card"><div class="num" style="color:#2e7d32"><?= $presentToday ?></div><div class="lbl">Present Today</div></div>
    <div class="card"><div class="num" style="color:#c62828"><?= $absent ?></div><div class="lbl">Absent</div></div>
    <div class="card"><div class="num"><?= $onLeave ?></div><div class="lbl">On Leave</div></div>
    <div class="card"><div class="num"><?= $plantEntries ?></div><div class="lbl">Plant Entries Today</div></div>
    <div class="card"><div class="num"><?= $pendingLeave ?></div><div class="lbl">Pending Leave</div></div>
    <div class="card"><div class="num"><?= $camerasOnline ?></div><div class="lbl">Cameras Online</div></div>
    <div class="card"><div class="num" style="color:#c62828"><?= $unreadAlerts ?></div><div class="lbl">Unread Alerts</div></div>
  </div>

  <div class="panel">
    <div class="section"><h2>🏭 Today's Plant Log</h2>
      <?php if (!$recentPlant): ?><p style="color:#7b8a9b;font-size:12px;">No entries today.</p>
      <?php else: ?><table><tr><th>Machine</th><th>Shift</th><th>Units</th><th>Incident</th></tr>
      <?php foreach($recentPlant as $p): ?><tr><td><?= e($p['machine']) ?></td><td><?= e($p['shift']) ?></td><td><?= (int)$p['production_units'] ?></td><td><?= e($p['incident_report'] ?: '-') ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
    <div class="section"><h2>⏱️ Today's Attendance</h2>
      <?php if (!$recentAttendance): ?><p style="color:#7b8a9b;font-size:12px;">No records today.</p>
      <?php else: ?><table><tr><th>Employee</th><th>In</th><th>Out</th><th>Status</th></tr>
      <?php foreach($recentAttendance as $a): ?><tr><td><?= e($a['full_name']) ?></td><td><?= e($a['clock_in'] ?? '-') ?></td><td><?= e($a['clock_out'] ?? '-') ?></td><td><?= e($a['status']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
    <div class="section"><h2>🚨 Recent CCTV Alerts</h2>
      <?php if (!$recentAlerts): ?><p style="color:#7b8a9b;font-size:12px;">No alerts.</p>
      <?php else: ?><table><tr><th>Level</th><th>Message</th><th>Time</th></tr>
      <?php foreach($recentAlerts as $al): ?><tr><td><span class="lv <?= $al['alert_level'] ?>"><?= e($al['alert_level']) ?></span></td><td><?= e($al['message']) ?></td><td><?= e($al['created_at']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
    <div class="section"><h2>🏖 Pending Leave Requests</h2>
      <?php if (!$pendingList): ?><p style="color:#7b8a9b;font-size:12px;">No pending requests.</p>
      <?php else: ?><table><tr><th>Employee</th><th>From</th><th>To</th><th>Days</th></tr>
      <?php foreach($pendingList as $pl): ?><tr><td><?= e($pl['full_name']) ?></td><td><?= e($pl['start_date']) ?></td><td><?= e($pl['end_date']) ?></td><td><?= (int)$pl['days_requested'] ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="section"><h2>📜 Recent Activity</h2>
      <?php if (!$auditRecent): ?><p style="color:#7b8a9b;font-size:12px;">No activity yet.</p>
      <?php else: ?><table><tr><th>Action</th><th>Module</th><th>When</th></tr>
      <?php foreach($auditRecent as $ad): ?><tr><td><?= e($ad['action']) ?></td><td><?= e($ad['module']) ?></td><td><?= e($ad['created_at']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
    <div class="section"><h2>🩺 System Health</h2>
      <div class="health">
        <span class="hitem"><span class="dot g"></span>Apache: Running</span>
        <span class="hitem"><span class="dot g"></span>MySQL: Running</span>
        <span class="hitem"><span class="dot g"></span>PHP 8.5.4</span>
        <span class="hitem"><span class="dot g"></span>Cameras: <?= $camerasOnline ?> online</span>
        <span class="hitem"><span class="dot o"></span>Departments: <?= $totalDepts ?></span>
      </div>
      <p style="margin-top:14px;font-size:12px;color:#7b8a9b;">✓ All core services operational. <?= APP_NAME ?> v1.0</p>
    </div>
  </div>
</div>
</body></html>
