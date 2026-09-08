<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireLogin();

$db = Database::getInstance();
$action = $_GET['action'] ?? 'list';

// ---------- Create employee ----------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['do'] ?? '') === 'add_employee') {
    $code = trim($_POST['employee_code'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    if ($code === '' || $name === '') {
        $msg = 'Employee code and full name are required.';
    } else {
        try {
            $db->query(
                "INSERT INTO employees (employee_code, full_name, date_of_birth, phone, email,
                 department_id, position_id, employment_type, start_date, status)
                 VALUES (:c,:n,:d,:p,:e,:dept,:pos,:et,:sd,'Active')",
                [
                    'c' => $code, 'n' => $name,
                    'd' => $_POST['date_of_birth'] ?: null,
                    'p' => $_POST['phone'] ?: null,
                    'e' => $_POST['email'] ?: null,
                    'dept' => (int)($_POST['department_id'] ?? 0) ?: null,
                    'pos' => (int)($_POST['position_id'] ?? 0) ?: null,
                    'et' => $_POST['employment_type'] ?? 'Full-time',
                    'sd' => $_POST['start_date'] ?: null,
                ]
            );
            Auth::audit('hr', 'add_employee', "Added employee $code - $name");
            $msg = 'Employee added successfully.';
        } catch (Exception $ex) {
            $msg = 'Error: ' . $ex->getMessage();
        }
    }
}

// ---------- Add HR note ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['do'] ?? '') === 'add_note') {
    $db->query(
        "INSERT INTO employee_notes (employee_id, category, note, created_by)
         VALUES (:e,:cat,:n,:u)",
        [
            'e' => (int)$_POST['employee_id'],
            'cat' => $_POST['category'] ?? 'General',
            'n' => $_POST['note'] ?? '',
            'u' => Auth::id(),
        ]
    );
    Auth::audit('hr', 'add_note', "Added HR note to employee #{$_POST['employee_id']}");
    $msg = 'HR note added.';
}

// ---------- Approve/reject leave ----------
if (isset($_GET['leave']) && in_array($_GET['leave'], ['approved','rejected']) && isset($_GET['id'])) {
    $db->query("UPDATE leave_requests SET status = :s, approved_by = :u, approved_at = NOW()
                WHERE id = :id",
        ['s' => ucfirst($_GET['leave']), 'u' => Auth::id(), 'id' => (int)$_GET['id']]);
    Auth::audit('hr', 'approve_leave', "Leave #{$_GET['id']} {$_GET['leave']}");
    $msg = "Leave request {$_GET['leave']}.";
}

$employees  = $db->fetchAll("SELECT * FROM employees ORDER BY full_name");
$departments = $db->fetchAll("SELECT * FROM departments ORDER BY name");
$leaveRequests = $db->fetchAll(
    "SELECT lr.*, e.full_name FROM leave_requests lr
     JOIN employees e ON e.id = lr.employee_id ORDER BY lr.created_at DESC LIMIT 20"
);
$notes = $db->fetchAll(
    "SELECT n.*, e.full_name FROM employee_notes n
     JOIN employees e ON e.id = n.employee_id ORDER BY n.created_at DESC LIMIT 15"
);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HR Management - <?= APP_NAME ?></title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}.topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
.wrap{max-width:1100px;margin:22px auto;padding:0 18px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);margin-bottom:18px}
h2{font-size:16px;margin-bottom:14px;color:#0f2027}
.tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.tab{background:#e8eef3;padding:8px 16px;border-radius:7px;text-decoration:none;color:#1f2d3d;font-size:13px;font-weight:600}
.tab.on{background:#2c5364;color:#fff}
label{display:block;font-size:12px;font-weight:600;margin:8px 0 4px;color:#444}
input,select,textarea{width:100%;padding:9px 10px;border:1px solid #d0d0d0;border-radius:7px;font-size:14px}
button{padding:10px 18px;background:#2c5364;color:#fff;border:none;border-radius:7px;font-weight:600;cursor:pointer;margin-top:12px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f0f4f7;color:#0f2027;font-size:12px}
.msg{background:#e6f4ea;color:#1e7e34;padding:10px;border-radius:7px;margin-bottom:14px;font-size:14px}
.btn{background:#2c5364;color:#fff;padding:4px 9px;border-radius:5px;text-decoration:none;font-size:12px}
.green{background:#2e7d32}.red{background:#c62828}
</style></head><body>
<div class="topbar"><div class="brand">👥 HR Management</div><div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div></div>
<div class="wrap">
  <?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; ?>
  <div class="tabs">
    <a class="tab <?= $action==='list'?'on':'' ?>" href="hr.php">Employees</a>
    <a class="tab <?= $action==='add'?'on':'' ?>" href="hr.php?action=add">+ New Employee</a>
    <a class="tab <?= $action==='leave'?'on':'' ?>" href="hr.php?action=leave">Leave</a>
    <a class="tab <?= $action==='notes'?'on':'' ?>" href="hr.php?action=notes">HR Notes</a>
  </div>

<?php if ($action === 'add'): ?>
  <div class="card"><h2>➕ New Employee</h2>
    <form method="post" action="hr.php?do=add_employee">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
        <div><label>Employee ID</label><input name="employee_code" required placeholder="EMP-101"></div>
        <div><label>Full Name</label><input name="full_name" required></div>
        <div><label>Date of Birth</label><input type="date" name="date_of_birth"></div>
        <div><label>Phone</label><input name="phone"></div>
        <div><label>Email</label><input name="email"></div>
        <div><label>Department</label><select name="department_id"><option value="0">—</option>
          <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Employment Type</label><select name="employment_type">
          <option>Full-time</option><option>Part-time</option><option>Contract</option><option>Probation</option></select></div>
        <div><label>Start Date</label><input type="date" name="start_date"></div>
      </div>
      <button type="submit">💾 Save Employee</button>
    </form>
  </div>

<?php elseif ($action === 'leave'): ?>
  <div class="card"><h2>🏖 Leave Requests</h2>
    <?php if (!$leaveRequests): ?><p style="color:#7b8a9b;font-size:13px;">No leave requests yet.</p>
    <?php else: ?>
    <table><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Action</th></tr>
    <?php foreach ($leaveRequests as $l): ?>
      <tr><td><?= e($l['full_name']) ?></td><td><?= e($l['leave_type_id']) ?></td>
      <td><?= e($l['start_date']) ?></td><td><?= e($l['end_date']) ?></td><td><?= (int)$l['days_requested'] ?></td>
      <td><?= e($l['status']) ?></td><td>
        <?php if ($l['status']==='Pending'): ?>
        <a class="btn green" href="hr.php?action=leave&leave=approved&id=<?= $l['id'] ?>">Approve</a>
        <a class="btn red" href="hr.php?action=leave&leave=rejected&id=<?= $l['id'] ?>">Reject</a>
        <?php else: ?>—<?php endif; ?>
      </td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>

<?php elseif ($action === 'notes'): ?>
  <div class="card"><h2>➕ Add HR Note</h2>
    <form method="post" action="hr.php?do=add_note">
      <label>Employee</label>
      <select name="employee_id" required><?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['employee_code']) ?> - <?= e($e['full_name']) ?></option><?php endforeach; ?></select>
      <label>Category</label>
      <select name="category"><option>Performance</option><option>Attendance</option><option>Behavior</option><option>Warning</option><option>Meeting</option><option>Training</option><option>General</option><option>Other</option></select>
      <label>Note</label><textarea name="note" rows="3" required></textarea>
      <button type="submit">💾 Save Note</button>
    </form>
  </div>
  <div class="card"><h2>📝 Recent HR Notes</h2>
    <?php if (!$notes): ?><p style="color:#7b8a9b;font-size:13px;">No notes yet.</p>
    <?php else: foreach ($notes as $n): ?>
      <p style="font-size:13px;padding:8px 0;border-bottom:1px solid #eee;"><strong><?= e($n['full_name']) ?></strong> · <span style="color:#2c5364"><?= e($n['category']) ?></span><br><span style="color:#555"><?= e($n['note']) ?></span></p>
    <?php endforeach; endif; ?>
  </div>

<?php else: ?>
  <div class="card"><h2>👥 Employees (<?= count($employees) ?>)</h2>
    <?php if (!$employees): ?><p style="color:#7b8a9b;font-size:13px;">No employees yet. Add one via "+ New Employee".</p>
    <?php else: ?>
    <table><tr><th>ID</th><th>Name</th><th>Department</th><th>Type</th><th>Status</th><th>Start</th></tr>
    <?php foreach ($employees as $e): ?>
      <tr><td><?= e($e['employee_code']) ?></td><td><?= e($e['full_name']) ?></td>
      <td><?= e($e['department_id']) ?></td><td><?= e($e['employment_type']) ?></td>
      <td><?= e($e['status']) ?></td><td><?= e($e['start_date'] ?? '-') ?></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div></body></html>
