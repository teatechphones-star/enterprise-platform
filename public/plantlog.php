<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireLogin();

$db = Database::getInstance();
$user = Auth::user();

// Handle form submission (create plant log entry)
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $template_id = (int)($_POST['template_id'] ?? 0);
    $work_date   = $_POST['work_date'] ?? date('Y-m-d');
    $shift       = $_POST['shift'] ?? '';
    $machine     = $_POST['machine'] ?? '';
    $production  = (int)($_POST['production_units'] ?? 0);
    $readings    = $_POST['readings'] ?? '';
    $incident    = $_POST['incident_report'] ?? '';
    $notes       = $_POST['notes'] ?? '';
    $dept_id     = (int)($_POST['department_id'] ?? 0);

    $db->query(
        "INSERT INTO plantlog_entries
         (template_id, operator_id, work_date, shift, machine, readings_json, production_units,
          incident_report, notes, submitted_by, submitted_at, department_id)
         VALUES (:t, :op, :d, :s, :m, :r, :p, :inc, :n, :sb, NOW(), :dept)",
        [
            't'   => $template_id ?: null,
            'op'  => null,
            'd'   => $work_date,
            's'   => $shift,
            'm'   => $machine,
            'r'   => $readings ? json_encode(['readings' => $readings]) : null,
            'p'   => $production,
            'inc' => $incident,
            'n'   => $notes,
            'sb'  => Auth::id(),
            'dept'=> $dept_id ?: null,
        ]
    );
    Auth::audit('plantlog', 'create_entry', "Created plant log entry for $work_date");
    $saved = true;
}

$entries = $db->fetchAll(
    "SELECT e.*, t.name AS template_name, d.name AS department_name
     FROM plantlog_entries e
     LEFT JOIN plantlog_templates t ON t.id = e.template_id
     LEFT JOIN departments d ON d.id = e.department_id
     ORDER BY e.created_at DESC LIMIT 25"
);
$templates = $db->fetchAll("SELECT * FROM plantlog_templates ORDER BY name");
$departments = $db->fetchAll("SELECT * FROM departments ORDER BY name");
$todayCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM plantlog_entries WHERE work_date = CURDATE()");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Plant Log - <?= APP_NAME ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
  .topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
  .topbar .brand{font-size:16px;font-weight:700}
  .topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
  .wrap{max-width:1100px;margin:22px auto;padding:0 18px}
  .card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);margin-bottom:18px}
  h2{font-size:16px;margin-bottom:14px;color:#0f2027}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  label{display:block;font-size:12px;font-weight:600;margin:8px 0 4px;color:#444}
  input,select,textarea{width:100%;padding:9px 10px;border:1px solid #d0d0d0;border-radius:7px;font-size:14px}
  button{padding:10px 18px;background:#2c5364;color:#fff;border:none;border-radius:7px;font-weight:600;cursor:pointer;margin-top:12px}
  button:hover{background:#1f3a47}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{padding:9px 10px;text-align:left;border-bottom:1px solid #eee}
  th{background:#f0f4f7;color:#0f2027;font-size:12px}
  .success{background:#e6f4ea;color:#1e7e34;padding:10px;border-radius:7px;margin-bottom:14px;font-size:14px}
  .stat{display:inline-block;background:#edf3f7;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;color:#0f2027}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">🏭 Plant Log</div>
  <div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div>
</div>
<div class="wrap">
  <p style="margin-bottom:16px;"><span class="stat">📝 Entries today: <?= $todayCount ?></span></p>

  <?php if ($saved): ?><div class="success">✅ Plant log entry saved successfully.</div><?php endif; ?>

  <div class="card">
    <h2>➕ New Plant Log Entry</h2>
    <form method="post">
      <div class="grid">
        <div>
          <label>Template</label>
          <select name="template_id">
            <option value="0">— General / None —</option>
            <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select>
          <label>Date</label>
          <input type="date" name="work_date" value="<?= date('Y-m-d') ?>" required>
          <label>Shift</label>
          <select name="shift"><option>Morning</option><option>Afternoon</option><option>Night</option></select>
          <label>Machine</label>
          <input type="text" name="machine" placeholder="e.g. Press Machine 01">
        </div>
        <div>
          <label>Department</label>
          <select name="department_id">
            <option value="0">— Select —</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
          </select>
          <label>Production Units</label>
          <input type="number" name="production_units" value="0" min="0">
          <label>Readings (JSON/key:value)</label>
          <textarea name="readings" rows="2" placeholder='e.g. {"temperature":"180C","pressure":"5bar"}'></textarea>
          <label>Incident Report</label>
          <textarea name="incident_report" rows="2"></textarea>
          <label>Notes</label>
          <textarea name="notes" rows="2"></textarea>
        </div>
      </div>
      <button type="submit">💾 Save Entry</button>
    </form>
  </div>

  <div class="card">
    <h2>📋 Recent Plant Log Entries</h2>
    <?php if (!$entries): ?><p style="color:#7b8a9b;font-size:13px;">No entries yet.</p>
    <?php else: ?>
    <table>
      <tr><th>Date</th><th>Shift</th><th>Machine</th><th>Dept</th><th>Units</th><th>Incident</th></tr>
      <?php foreach ($entries as $e): ?>
      <tr>
        <td><?= e($e['work_date']) ?></td>
        <td><?= e($e['shift']) ?></td>
        <td><?= e($e['machine']) ?></td>
        <td><?= e($e['department_name'] ?? '-') ?></td>
        <td><?= (int)$e['production_units'] ?></td>
        <td><?= e($e['incident_report'] ?: '-') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
