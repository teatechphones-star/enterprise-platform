<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireLogin();

$db = Database::getInstance();
$msg = '';

// --- Clock in / out for a selected employee (simulates clocking machine) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    $emp = $db->fetch("SELECT * FROM employees WHERE id = :id", ['id' => $emp_id]);
    if (!$emp) { $msg = 'Employee not found.'; }
    else {
        $today = date('Y-m-d');
        $rec = $db->fetch(
            "SELECT * FROM attendance_records WHERE employee_id = :e AND work_date = :d",
            ['e' => $emp_id, 'd' => $today]
        );
        if (!$_POST['clock_out']) {
            // Clock in
            if ($rec) {
                $msg = $emp['full_name'] . ' already clocked in today (at ' . $rec['clock_in'] . ').';
            } else {
                $late = strtotime($today . ' 09:00:00') < time();
                $status = $late ? 'Late' : 'Present';
                $db->query(
                    "INSERT INTO attendance_records (employee_id, work_date, clock_in, status, source)
                     VALUES (:e,:d,NOW(),:s,'App')",
                    ['e'=>$emp_id,'d'=>$today,'s'=>$status]
                );
                Auth::audit('attendance','clock_in',"$emp[full_name] clocked in");
                $msg = $emp['full_name'] . ' clocked IN (' . $status . ') at ' . date('H:i');
            }
        } else {
            // Clock out
            if (!$rec || $rec['clock_out']) {
                $msg = $emp['full_name'] . ' has no open clock-in or already clocked out.';
            } else {
                $db->query(
                    "UPDATE attendance_records SET clock_out = NOW(),
                      overtime_minutes = CASE WHEN HOUR(TIMEDIFF(NOW(), clock_in)) > 9
                        THEN TIMESTAMPDIFF(MINUTE, DATE_ADD(clock_in, INTERVAL 9 HOUR), NOW()) ELSE 0 END
                     WHERE id = :id", ['id'=>$rec['id']]
                );
                Auth::audit('attendance','clock_out',"$emp[full_name] clocked out");
                $msg = $emp['full_name'] . ' clocked OUT at ' . date('H:i');
            }
        }
    }
}

$employees = $db->fetchAll("SELECT * FROM employees ORDER BY full_name");
$records = $db->fetchAll(
    "SELECT a.*, e.full_name FROM attendance_records a
     JOIN employees e ON e.id = a.employee_id
     WHERE a.work_date = CURDATE() ORDER BY a.clock_in DESC"
);
$today = date('Y-m-d');
$present = (int)$db->fetchColumn("SELECT COUNT(*) FROM attendance_records WHERE work_date=:d AND status IN ('Present','Late','Overtime')", ['d'=>$today]);
$totalEmp = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees");
$absent = max(0, $totalEmp - $present);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance - <?= APP_NAME ?></title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}.topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
.wrap{max-width:1100px;margin:22px auto;padding:0 18px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);margin-bottom:18px}
h2{font-size:16px;margin-bottom:14px;color:#0f2027}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px}
.stat{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);text-align:center}
.stat .n{font-size:26px;font-weight:800;color:#0f2027}.stat .l{font-size:12px;color:#7b8a9b;margin-top:4px}
label{display:block;font-size:12px;font-weight:600;margin:8px 0 4px;color:#444}
select,input{width:100%;padding:9px 10px;border:1px solid #d0d0d0;border-radius:7px;font-size:14px}
button{padding:10px 18px;border:none;border-radius:7px;font-weight:600;cursor:pointer;margin:12px 8px 0 0}
.in{background:#2e7d32;color:#fff}.out{background:#c62828;color:#fff}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f0f4f7;color:#0f2027;font-size:12px}
.msg{background:#e6f4ea;color:#1e7e34;padding:10px;border-radius:7px;margin-bottom:14px;font-size:14px}
</style></head><body>
<div class="topbar"><div class="brand">⏱️ Attendance</div><div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div></div>
<div class="wrap">
  <?php if ($msg): ?><div class="msg">✅ <?= e($msg) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="n"><?= $totalEmp ?></div><div class="l">Total Employees</div></div>
    <div class="stat"><div class="n"><?= $present ?></div><div class="l">Present</div></div>
    <div class="stat"><div class="n"><?= $absent ?></div><div class="l">Absent</div></div>
  </div>

  <div class="card"><h2>🕐 Live Clock In / Clock Out</h2>
    <form method="post">
      <label>Employee</label>
      <select name="employee_id" required>
        <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['employee_code']) ?> - <?= e($e['full_name']) ?></option><?php endforeach; ?>
      </select>
      <button class="in" type="submit" name="clock_in" value="1">✅ Clock In</button>
      <button class="out" type="submit" name="clock_out" value="1">🚪 Clock Out</button>
    </form>
  </div>

  <div class="card"><h2>📋 Today's Attendance (<?= $today ?>)</h2>
    <?php if (!$records): ?><p style="color:#7b8a9b;font-size:13px;">No attendance recorded today yet.</p>
    <?php else: ?>
    <table><tr><th>Employee</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Overtime</th></tr>
    <?php foreach ($records as $r): ?>
      <tr><td><?= e($r['full_name']) ?></td><td><?= e($r['clock_in'] ?? '-') ?></td>
      <td><?= e($r['clock_out'] ?? '-') ?></td><td><?= e($r['status']) ?></td>
      <td><?= $r['overtime_minutes'] ? round($r['overtime_minutes']/60,1).'h' : '-' ?></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>
</div></body></html>
