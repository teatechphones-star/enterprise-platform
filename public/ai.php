<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireLogin();

$db = Database::getInstance();
$answer = '';
$question = '';

// ---- Server-side "AI" interpreter: maps natural-language questions
// to SQL queries over the central database. This is the logic the
// external AI (Jarvis on the PC) would invoke over SSH/API.
function ai_answer($q) {
    $db = Database::getInstance();
    $q = strtolower($q);
    $today = date('Y-m-d');

    // 1. Who is absent today?
    if (strpos($q,'absent') !== false && strpos($q,'today') !== false) {
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE status != 'On Leave'");
        $present = (int)$db->fetchColumn("SELECT COUNT(*) FROM attendance_records WHERE work_date=:d AND status IN ('Present','Late','Overtime')", ['d'=>$today]);
        $absent = max(0,$total-$present);
        $names = $db->fetchAll("SELECT e.full_name FROM employees e WHERE e.status!='On Leave' AND e.id NOT IN (SELECT employee_id FROM attendance_records WHERE work_date=:d)", ['d'=>$today]);
        $list = '';
        foreach($names as $n){ $list .= $n['full_name'].', '; }
        return "📊 $absent employee(s) absent today" . ($list ? ": ".rtrim($list,', ') : "") . ". ($present present.)";
    }
    // 2. Yesterday's production
    if (strpos($q,'production') !== false && (strpos($q,'yesterday')!==false || strpos($q,'yesterday')) ) {
        $y = date('Y-m-d', strtotime('-1 day'));
        $units = (int)$db->fetchColumn("SELECT IFNULL(SUM(production_units),0) FROM plantlog_entries WHERE work_date=:d", ['d'=>$y]);
        $n = (int)$db->fetchColumn("SELECT COUNT(*) FROM plantlog_entries WHERE work_date=:d", ['d'=>$y]);
        return "🏭 Yesterday's ($y) total production: $units units across $n log entry/entries.";
    }
    // 3. Machines with incidents this week
    if (strpos($q,'incident') !== false) {
        $rows = $db->fetchAll("SELECT machine, COUNT(*) c FROM plantlog_entries WHERE incident_report IS NOT NULL AND incident_report != '' AND work_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY machine");
        if(!$rows) return "✅ No machine incidents recorded in the last 7 days.";
        $s=''; foreach($rows as $r){ $s.="{$r['machine']} ({$r['c']}), "; }
        return "⚠️ Machines with incidents this week: ".rtrim($s,', ');
    }
    // 4. Employees with late arrivals this month
    if (strpos($q,'late') !== false) {
        $rows = $db->fetchAll("SELECT e.full_name, COUNT(*) c FROM attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.status='Late' AND a.work_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY e.id ORDER BY c DESC LIMIT 5");
        if(!$rows) return "✅ No late arrivals recorded this month.";
        $s=''; foreach($rows as $r){ $s.="{$r['full_name']} ({$r['c']}), "; }
        return "⏱️ Employees with late arrivals this month: ".rtrim($s,', ');
    }
    // 5. CCTV alerts after 8pm
    if (strpos($q,'cctv') !== false || strpos($q,'alert') !== false || strpos($q,'security') !== false) {
        $n = (int)$db->fetchColumn("SELECT COUNT(*) FROM cctv_alerts WHERE HOUR(created_at) >= 20 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $crit = (int)$db->fetchColumn("SELECT COUNT(*) FROM cctv_alerts WHERE alert_level='Critical'");
        return "📹 In the last 7 days: $n alert(s) after 8pm, $crit critical. See CCTV module for details.";
    }
    // 6. How many employees / total workforce
    if (strpos($q,'how many') !== false && (strpos($q,'employee')!==false || strpos($q,'staff')!==false)) {
        $n = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees");
        return "👥 Current workforce: $n employees.";
    }
    // 7. On leave
    if (strpos($q,'on leave') !== false) {
        $n = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees WHERE status='On Leave'");
        return "🏖 Currently on leave: $n employee(s).";
    }
    // 8. Pending leave requests
    if (strpos($q,'pending') !== false || (strpos($q,'leave')!==false && strpos($q,'request')!==false)) {
        $n = (int)$db->fetchColumn("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'");
        return "🏖 Pending leave requests: $n.";
    }
    // Fallback
    $t = (int)$db->fetchColumn("SELECT COUNT(*) FROM employees");
    $p = (int)$db->fetchColumn("SELECT COUNT(*) FROM attendance_records WHERE work_date=:d", ['d'=>$today]);
    return "🤖 I can answer questions like: 'Who is absent today?', 'Yesterday's production?', 'Machines with incidents?', 'Who arrived late this month?', 'CCTV alerts?', 'How many employees?', 'Who is on leave?'. (Current: $t employees, $p attendance records today.)";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['question'] ?? '');
    if ($question !== '') {
        $answer = ai_answer($question);
        Auth::audit('ai','query', "AI asked: $question");
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Assistant - <?= APP_NAME ?></title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}.topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
.wrap{max-width:760px;margin:30px auto;padding:0 18px}
.card{background:#fff;border-radius:12px;padding:26px;box-shadow:0 4px 14px rgba(0,0,0,.08);margin-bottom:18px}
.card h1{font-size:22px;color:#0f2027;margin-bottom:6px}
.card p.sub{color:#7b8a9b;font-size:13px;margin-bottom:20px}
input[type=text]{width:100%;padding:13px 14px;border:1px solid #d0d0d0;border-radius:9px;font-size:15px}
button{padding:13px 22px;background:linear-gradient(135deg,#0f2027,#2c5364);color:#fff;border:none;border-radius:9px;font-weight:700;cursor:pointer;margin-top:12px}
.answer{background:#eaf3f9;border-left:4px solid #2c5364;border-radius:8px;padding:18px;margin-top:18px;font-size:15px;line-height:1.5}
.examples{margin-top:16px;font-size:12px;color:#7b8a9b}
.examples span{display:inline-block;background:#f0f4f7;padding:4px 10px;border-radius:14px;margin:3px 3px 0 0}
</style></head><body>
<div class="topbar"><div class="brand">🤖 AI Assistant (Jarvis)</div><div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div></div>
<div class="wrap">
  <div class="card">
    <h1>🤖 Enterprise AI Assistant</h1>
    <p class="sub">Ask about attendance, production, HR, or security. Answers are generated live from the central database.</p>
    <form method="post">
      <input type="text" name="question" value="<?= e($question) ?>" placeholder='e.g. "Who is absent today?"' autofocus>
      <button type="submit">Ask AI ➤</button>
    </form>
    <?php if ($answer): ?><div class="answer"><?= $answer ?></div><?php endif; ?>
    <div class="examples">
      Try: <span>Who is absent today?</span><span>Yesterday's production</span><span>Machines with incidents this week</span><span>Who arrived late this month?</span><span>Summarize CCTV alerts after 8pm</span><span>How many employees?</span><span>Who is on leave?</span>
    </div>
  </div>
</div></body></html>
