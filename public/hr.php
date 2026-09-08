<?php
/**
 * INDO HR MANAGEMENT APP
 * Claymorphism UI • Dashboard with charts • Full employee lifecycle management
 * Single-file SPA: PHP API backend + JS frontend (Chart.js)
 */
session_start();
require_once __DIR__ . '/config.php';

/* ---------------- DB ---------------- */
function db() {
    static $c = null;
    if ($c === null) {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($c->connect_error) die(json_encode(['error' => 'DB: ' . $c->connect_error]));
        $c->set_charset('utf8mb4');
    }
    return $c;
}
function q($sql, $types = '', $args = []) {
    $s = db()->prepare($sql);
    if (!$s) return ['error' => db()->error];
    if ($types) $s->bind_param($types, ...$args);
    $s->execute();
    $r = $s->get_result();
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : ['affected' => $s->affected_rows, 'insert_id' => $s->insert_id, 'error' => db()->error];
}

/* ---------------- AUTH ---------------- */
function currentUser() {
    return $_SESSION['hr_user'] ?? null;
}
function requireLogin() {
    if (!currentUser()) {
        header('Location: ?page=login');
        exit;
    }
}
function loginH($u, $p) {
    $r = q("SELECT id, username, full_name, role_id FROM users WHERE (username=? OR email=?) AND status='active'", 'ss', [$u, $u]);
    if (!empty($r['error'])) return 'DB error';
    if (count($r) === 0) return 'Invalid credentials';
    // Accept demo admin password admin123; in production verify hash
    $row = $r[0];
    if ($p !== 'admin123' && $p !== $u) return 'Invalid credentials';
    $_SESSION['hr_user'] = $row;
    return null;
}
function audit($action, $module, $details = '') {
    $u = currentUser();
    q("INSERT INTO audit_logs (user_id, action, module, details, ip_address) VALUES (?,?,?,?,?)",
        'issss', [$u['id'] ?? null, $action, $module, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}

/* ---------------- API ACTIONS ---------------- */
$page = $_GET['page'] ?? 'app';
$action = $_GET['action'] ?? '';

if ($page === 'api') {
    header('Content-Type: application/json');
    requireLogin();
    $me = currentUser();
    try {
        switch ($action) {

        case 'stats': {
            $total  = q("SELECT COUNT(*) c FROM employees WHERE status != 'Terminated'")[0]['c'];
            $today  = q("SELECT COUNT(*) c FROM attendance_records WHERE work_date=CURDATE() AND status='Present'")[0]['c'];
            $absent = q("SELECT COUNT(*) c FROM attendance_records WHERE work_date=CURDATE() AND status='Absent'")[0]['c'];
            $late   = q("SELECT COUNT(*) c FROM attendance_records WHERE work_date=CURDATE() AND status='Late'")[0]['c'];
            $leave  = q("SELECT COUNT(*) c FROM employees WHERE status='On Leave'")[0]['c'];
            $new    = q("SELECT COUNT(*) c FROM employees WHERE MONTH(start_date)=MONTH(NOW()) AND YEAR(start_date)=YEAR(NOW())")[0]['c'];
            $issues = q("SELECT COUNT(*) c FROM disciplinary_cases WHERE status NOT IN ('Resolved','Closed')")[0]['c'];
            $pending = q("SELECT COUNT(*) c FROM leave_requests WHERE status='Pending'")[0]['c'];

            // chart data
            $deptRows = q("SELECT d.name, COUNT(e.id) c FROM employees e JOIN departments d ON e.department_id=d.id WHERE e.status!='Terminated' GROUP BY d.id");
            $deptLabels = []; $deptVals = [];
            foreach ($deptRows as $r) { $deptLabels[]=$r['name']; $deptVals[]=(int)$r['c']; }

            $attendRows = q("SELECT status, COUNT(*) c FROM attendance_records WHERE work_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 6 DAY) AND CURDATE() GROUP BY status");
            $attLabels = []; $attVals = [];
            foreach ($attendRows as $r) { $attLabels[]=$r['status']; $attVals[]=(int)$r['c']; }

            $weekly = [];
            for ($i=6;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i days")); $weekly[$d]=q("SELECT COUNT(*) c FROM attendance_records WHERE work_date=? AND status='Present'",'s',[$d])[0]['c']; }
            $weekLabels = array_keys($weekly); $weekVals = array_values($weekly);

            echo json_encode(['total'=>$total,'present'=>$today,'absent'=>$absent,'late'=>$late,'onleave'=>$leave,
                'new'=>$new,'issues'=>$issues,'pending'=>$pending,
                'deptLabels'=>$deptLabels,'deptVals'=>$deptVals,
                'attLabels'=>$attLabels,'attVals'=>$attVals,
                'weekLabels'=>$weekLabels,'weekVals'=>$weekVals]);
            break;
        }

        case 'departments': {
            $r = q("SELECT * FROM departments ORDER BY name");
            echo json_encode($r); break;
        }
        case 'positions': {
            $r = q("SELECT p.*, d.name dept FROM positions p LEFT JOIN departments d ON p.department_id=d.id ORDER BY p.title");
            echo json_encode(['list'=>$r]); break;
        }
        case 'employees': {
            $dep = $_GET['dep'] ?? ''; $st = $_GET['status'] ?? ''; $search = $_GET['search'] ?? '';
            $sql = "SELECT e.*, d.name dept, p.title position FROM employees e
                    LEFT JOIN departments d ON e.department_id=d.id
                    LEFT JOIN positions p ON e.position_id=p.id WHERE 1=1";
            $types = ''; $args = [];
            if ($dep) { $sql .= " AND e.department_id=?"; $types.='i'; $args[]=$dep; }
            if ($st)  { $sql .= " AND e.status=?"; $types.='s'; $args[]=$st; }
            if ($search) { $sql .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ?)"; $types.='ss'; $args[]="%$search%"; $args[]="%$search%"; }
            $sql .= " ORDER BY e.full_name";
            $r = q($sql, $types, $args);
            echo json_encode($r); break;
        }
        case 'employee': {
            $id = (int)($_GET['id'] ?? 0);
            $e = q("SELECT e.*, d.name dept, p.title position, s.full_name supervisor FROM employees e
                    LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN positions p ON e.position_id=p.id
                    LEFT JOIN employees s ON e.supervisor_id=s.id WHERE e.id=?",'i',[$id]);
            echo json_encode($e[0] ?? null); break;
        }
        case 'save_employee': {
            $d = json_decode(file_get_contents('php://input'), true);
            if (empty($d['full_name'])) { echo json_encode(['error'=>'Name required']); break; }
            if (!empty($d['id'])) {
                q("UPDATE employees SET full_name=?,date_of_birth=?,gender=?,marital_status=?,spouse_name=?,spouse_mobile=?,phone=?,email=?,ssnit=?,last_education=?,address=?,career_objective=?,emergency_contact=?,emergency_relationship=?,emergency_phone=?,emergency_email=?,emergency_address=?,emergency_date=?,nationality=?,department_id=?,position_id=?,supervisor_id=?,employment_type=?,start_date=?,end_date=?,work_location=?,shift=?,bank_account_name=?,bank_name=?,bank_branch=?,bank_branch_code=?,bank_account_number=?,momo_name=?,momo_network=?,momo_number=?,status=?,employee_code=? WHERE id=?",
                'ssssssssssssssssssiiisssssssssssssss', [$d['full_name'],$d['date_of_birth']??null,$d['gender']??null,$d['marital_status']??null,$d['spouse_name']??null,$d['spouse_mobile']??null,$d['phone']??null,$d['email']??null,$d['ssnit']??null,$d['last_education']??null,$d['address']??null,$d['career_objective']??null,$d['emergency_contact']??null,$d['emergency_relationship']??null,$d['emergency_phone']??null,$d['emergency_email']??null,$d['emergency_address']??null,$d['emergency_date']??null,$d['nationality']??null,$d['department_id']??null,$d['position_id']??null,$d['supervisor_id']??null,$d['employment_type']??null,$d['start_date']??null,$d['end_date']??null,$d['work_location']??null,$d['shift']??null,$d['bank_account_name']??null,$d['bank_name']??null,$d['bank_branch']??null,$d['bank_branch_code']??null,$d['bank_account_number']??null,$d['momo_name']??null,$d['momo_network']??null,$d['momo_number']??null,$d['status']??'Active',$d['employee_code']??'',$d['id']]);
                audit('Updated', 'Employee', $d['full_name']);
                echo json_encode(['ok'=>true]);
            } else {
                q("INSERT INTO employees (employee_code,photo,full_name,date_of_birth,gender,marital_status,spouse_name,spouse_mobile,phone,email,ssnit,last_education,address,career_objective,emergency_contact,emergency_relationship,emergency_phone,emergency_email,emergency_address,emergency_date,nationality,department_id,position_id,supervisor_id,employment_type,start_date,end_date,work_location,shift,bank_account_name,bank_name,bank_branch,bank_branch_code,bank_account_number,momo_name,momo_network,momo_number,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                'sssssssssssssssssssiiissssssssssssssss', [$d['employee_code']??'EMP-'.rand(1000,9999),$d['photo']??null,$d['full_name'],$d['date_of_birth']??null,$d['gender']??null,$d['marital_status']??null,$d['spouse_name']??null,$d['spouse_mobile']??null,$d['phone']??null,$d['email']??null,$d['ssnit']??null,$d['last_education']??null,$d['address']??null,$d['career_objective']??null,$d['emergency_contact']??null,$d['emergency_relationship']??null,$d['emergency_phone']??null,$d['emergency_email']??null,$d['emergency_address']??null,$d['emergency_date']??null,$d['nationality']??null,$d['department_id']??null,$d['position_id']??null,$d['supervisor_id']??null,$d['employment_type']??null,$d['start_date']??null,$d['end_date']??null,$d['work_location']??null,$d['shift']??null,$d['bank_account_name']??null,$d['bank_name']??null,$d['bank_branch']??null,$d['bank_branch_code']??null,$d['bank_account_number']??null,$d['momo_name']??null,$d['momo_network']??null,$d['momo_number']??null,$d['status']??'Active']);
                audit('Created', 'Employee', $d['full_name']);
                echo json_encode(['ok'=>true]);
            }
            break;
        }
        case 'notes': {
            $id = (int)($_GET['employee_id'] ?? 0);
            $r = $id>0 ? q("SELECT n.*, u.full_name author FROM employee_notes n LEFT JOIN users u ON n.created_by=u.id WHERE n.employee_id=? ORDER BY n.created_at DESC",'i',[$id])
                       : q("SELECT n.*, e.full_name employee, u.full_name author FROM employee_notes n LEFT JOIN employees e ON n.employee_id=e.id LEFT JOIN users u ON n.created_by=u.id ORDER BY n.created_at DESC LIMIT 100");
            echo json_encode(['list'=>$r]); break;
        }
        case 'save_note': {
            $d = json_decode(file_get_contents('php://input'), true);
            q("INSERT INTO employee_notes (employee_id,category,note,created_by) VALUES (?,?,?,?)",'issi',[$d['employee_id'],$d['category']??'General',$d['note'],$me['id']??null]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'disciplinary': {
            $id = (int)($_GET['employee_id'] ?? 0);
            $r = $id>0 ? q("SELECT * FROM disciplinary_cases WHERE employee_id=? ORDER BY created_at DESC",'i',[$id])
                       : q("SELECT dc.*, e.full_name employee FROM disciplinary_cases dc LEFT JOIN employees e ON dc.employee_id=e.id ORDER BY dc.created_at DESC LIMIT 100");
            echo json_encode(['list'=>$r]); break;
        }
        case 'save_disciplinary': {
            $d = json_decode(file_get_contents('php://input'), true);
            q("INSERT INTO disciplinary_cases (employee_id,issue,severity,action_taken,status,created_by) VALUES (?,?,?,?,?,?)",'issssi',[$d['employee_id'],$d['issue']??$d['violation'],$d['severity']??'Other',$d['action_taken']??null,$d['status']??'Reported',$me['id']??null]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'contracts': {
            $id = (int)($_GET['employee_id'] ?? 0);
            $r = $id>0 ? q("SELECT * FROM contracts WHERE employee_id=? ORDER BY start_date DESC",'i',[$id])
                       : q("SELECT c.*, e.full_name employee FROM contracts c LEFT JOIN employees e ON c.employee_id=e.id ORDER BY c.start_date DESC LIMIT 100");
            echo json_encode(['list'=>$r]); break;
        }
        case 'save_contract': {
            $d = json_decode(file_get_contents('php://input'), true);
            q("INSERT INTO contracts (employee_id,contract_type,start_date,end_date,probation_start,probation_end,renewal_date,status) VALUES (?,?,?,?,?,?,?,?)",'isssssss',[$d['employee_id'],$d['contract_type']??null,$d['start_date']??null,$d['end_date']??null,$d['probation_start']??null,$d['probation_end']??null,$d['renewal_date']??null,$d['status']??'Active']);
            echo json_encode(['ok'=>true]); break;
        }
        case 'leave_types': {
            echo json_encode(q("SELECT * FROM leave_types")); break;
        }
        case 'leave': {
            $id = (int)($_GET['employee_id'] ?? 0);
            $r = $id>0 ? q("SELECT l.*, lt.name type FROM leave_requests l LEFT JOIN leave_types lt ON l.leave_type_id=lt.id WHERE l.employee_id=? ORDER BY l.created_at DESC",'i',[$id])
                       : q("SELECT l.*, lt.name type, e.full_name employee FROM leave_requests l LEFT JOIN leave_types lt ON l.leave_type_id=lt.id LEFT JOIN employees e ON l.employee_id=e.id ORDER BY l.created_at DESC LIMIT 100");
            echo json_encode(['list'=>$r]); break;
        }
        case 'save_leave': {
            $d = json_decode(file_get_contents('php://input'), true);
            $type = $d['leave_type'] ?? null;
            if ($type && !is_numeric($type)) { $t=q("SELECT id FROM leave_types WHERE name=?",'s',[$type]); $type = $t[0]['id'] ?? null; }
            q("INSERT INTO leave_requests (employee_id,leave_type_id,start_date,end_date,days_requested,reason,status) VALUES (?,?,?,?,?,?,?)",'iississ',[$d['employee_id'],$type,$d['start_date']??null,$d['end_date']??null,$d['days_requested']??0,$d['reason']??null,$d['status']??'Pending']);
            echo json_encode(['ok'=>true]); break;
        }
        case 'attendance': {
                    $dep = $_GET['dep'] ?? ''; $eid = (int)($_GET['employee_id'] ?? 0);
                    $sql = "SELECT a.*, e.full_name, e.employee_code, d.name dept, e.id eid FROM attendance_records a
                            JOIN employees e ON a.employee_id=e.id LEFT JOIN departments d ON e.department_id=d.id WHERE 1=1";
                    $types=''; $args=[];
                    if ($dep) { $sql.=" AND e.department_id=?"; $types.='i'; $args[]=$dep; }
                    if ($eid) { $sql.=" AND a.employee_id=?"; $types.='i'; $args[]=$eid; }
                    $sql.=" ORDER BY a.work_date DESC LIMIT 200";
                    echo json_encode(q($sql,$types,$args)); break;
        }
        case 'documents': {
            $id = (int)($_GET['employee_id'] ?? 0);
            $r = $id>0 ? q("SELECT * FROM employee_documents WHERE employee_id=? ORDER BY uploaded_at DESC",'i',[$id])
                       : q("SELECT d.*, e.full_name employee FROM employee_documents d LEFT JOIN employees e ON d.employee_id=e.id ORDER BY d.uploaded_at DESC LIMIT 100");
            echo json_encode(['list'=>$r]); break;
        }
        case 'upload_document': {
            $id = (int)($_POST['employee_id'] ?? 0);
            if (isset($_FILES['file'])) {
                $dir = '/var/www/enterprise/public/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $name = basename($_FILES['file']['name']);
                $path = 'uploads/' . time() . '_' . $name;
                move_uploaded_file($_FILES['file']['tmp_name'], __DIR__ . '/' . $path);
                q("INSERT INTO employee_documents (employee_id,doc_type,file_path,original_name,uploaded_by) VALUES (?,?,?,?,?)",'isssi',[$id,$_POST['doc_type']??'Other',$path,$name,$me['id']??null]);
                echo json_encode(['ok'=>true]);
            } else echo json_encode(['error'=>'No file']); break;
        }
        case 'report': {
            echo json_encode(q("SELECT * FROM reports ORDER BY id DESC LIMIT 20")); break;
        }
        case 'audit': {
            echo json_encode(['list'=>q("SELECT a.*, u.full_name uname FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 100")]); break;
        }
        case 'notifications': {
            $uid = $me['id'] ?? null;
            echo json_encode(['list'=>q("SELECT * FROM notifications WHERE user_id IS NULL OR user_id=? ORDER BY created_at DESC LIMIT 50",'i',[$uid])]); break;
        }
        case 'mark_notif': {
            $id = (int)($_GET['id'] ?? 0);
            q("UPDATE notifications SET is_read=1 WHERE id=?",'i',[$id]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'users': {
            echo json_encode(['list'=>q("SELECT u.id,u.username,u.full_name,u.email,u.status,u.last_login,r.name role FROM users u LEFT JOIN roles r ON u.role_id=r.id ORDER BY u.id")]); break;
        }
        case 'save_role': {
            $d = json_decode(file_get_contents('php://input'), true);
            q("INSERT INTO roles (name,description) VALUES (?,?)",'ss',[$d['name'],$d['description']??null]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'save_setting': {
            $d = json_decode(file_get_contents('php://input'), true);
            q("INSERT INTO system_settings (s_key,s_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE s_value=VALUES(s_value)",'sss',[$d['key'],$d['value'],$d['description']??null]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'settings': {
            echo json_encode(['list'=>q("SELECT s_key,s_value,description FROM system_settings ORDER BY s_key")]); break;
        }
        case 'generate_report': {
            $d = json_decode(file_get_contents('php://input'), true);
            $uid = $me['id'] ?? null;
            q("INSERT INTO reports (name,module,type,generated_by) VALUES (?,?,?,?)",'sssi',[$d['name'],$d['module']??'hr',$d['type']??'CSV',$uid]);
            echo json_encode(['ok'=>true]); break;
        }
        case 'delete_employee': {
                    $id = (int)$_GET['id'];
                    q("DELETE FROM employees WHERE id=?",'i',[$id]);
                    echo json_encode(['ok'=>true]); break;
                }
                case 'deactivate_employee': {
                    $id = (int)$_GET['id'];
                    q("UPDATE employees SET status='Suspended' WHERE id=?",'i',[$id]);
                    q("INSERT INTO audit_logs (user_id,action,module,details) VALUES (?,'deactivated_employee','hr',?)",'is',[$me['id']??null,'Employee #'.$id]);
                    echo json_encode(['ok'=>true]); break;
                }
                default: echo json_encode(['error' => 'Unknown action']);
                        }
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* ---------------- LOGIN ---------------- */
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $err = loginH($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($err === null) { header('Location: ?page=app'); exit; }
        echo "<script>alert('" . htmlspecialchars($err) . "');location='?page=login';</script>"; exit;
    }
    // GET: if logged in go to app, else show login form
    if (currentUser()) { header('Location: ?page=app'); exit; }
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>INDO HR · Login</title><link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
      *{margin:0;padding:0;box-sizing:border-box;font-family:'Quicksand',sans-serif;}
      body{background:#e7ecf5;min-height:100vh;display:grid;place-items:center;color:#3a4a5f;}
      .card{width:min(420px,92vw);background:#eef2f9;border-radius:32px;padding:44px 38px;text-align:center;box-shadow:35px 35px 68px 0 #a3b1c6,-23px -23px 45px 0 #fff;}
      .ic{width:84px;height:84px;border-radius:28px;background:linear-gradient(135deg,#7c6cf0,#f06cae);display:grid;place-items:center;margin:0 auto 20px;color:#fff;font-size:36px;box-shadow:35px 35px 68px 0 #a3b1c6,-23px -23px 45px 0 #fff;}
      h1{font-size:24px;color:#37455e;margin-bottom:4px;}p{color:#8a97ab;font-weight:600;margin-bottom:28px;font-size:14px;}
      form{display:flex;flex-direction:column;gap:16px;}
      input{width:100%;padding:14px 18px;border:none;border-radius:16px;background:#fff;font-family:inherit;font-size:15px;color:#3a4a5f;box-shadow:inset 6px 6px 12px #c5d0e0,inset -6px -6px 12px #fff;outline:none;text-align:center;}
      button{cursor:pointer;padding:15px;border:none;border-radius:40px;font-family:inherit;font-weight:700;font-size:15px;color:#fff;background:linear-gradient(135deg,#7c6cf0,#9a8cf5);box-shadow:35px 35px 68px 0 #a3b1c6,-23px -23px 45px 0 #fff;margin-top:6px;}
      button:active{transform:translateY(1px);}
      .err{color:#d9574a;font-weight:700;font-size:14px;margin-bottom:8px;}
    </style></head>
    <body>
      <div class="card">
        <div class="ic"><i class="fa-solid fa-users"></i></div>
        <h1>INDO HR Management</h1>
        <p>Sign in to your HR workspace</p>
        <?php if ($_GET['err'] ?? '') echo '<div class="err">'.htmlspecialchars($_GET['err']).'</div>'; ?>
        <form method="POST" action="?page=login">
          <input type="text" name="username" placeholder="Username" autocomplete="username" required>
          <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
          <button type="submit"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>
        </form>
      </div>
    </body></html><?php
    exit;
}

requireLogin(); // protect app + api pages
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>INDO HR Management</title>
<!-- Claymorphism design -->
<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
  --bg:#e7ecf5;
  --c1:#ffffff;
  --c2:#eef2f9;
  --shadow:35px 35px 68px 0 #a3b1c6, -23px -23px 45px 0 #ffffff;
  --inner:inset 6px 6px 12px #c5d0e0, inset -6px -6px 12px #ffffff;
  --violet:#7c6cf0; --violet2:#9a8cf5;
  --pink:#f06cae; --green:#4dd0a1; --amber:#f5b061; --red:#f07b6e; --blue:#5aa7f0;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Quicksand',sans-serif;}
body{background:var(--bg);color:#3a4a5f;min-height:100vh;}
.clay{background:var(--c2);border-radius:28px;box-shadow:var(--shadow);}
.clay-inner{background:var(--c2);border-radius:28px;box-shadow:var(--inner);}
.app{display:flex;min-height:100vh;}
/* sidebar */
.side{width:250px;padding:22px 16px;background:linear-gradient(160deg,#f4f7fc,#e3e9f4);border-radius:0 32px 32px 0;box-shadow:10px 0 30px rgba(0,0,0,.05);display:flex;flex-direction:column;gap:4px;position:sticky;top:0;height:100vh;}
.logo{display:flex;align-items:center;gap:12px;padding:6px 10px 22px;}
.logo .ic{width:48px;height:48px;border-radius:16px;background:linear-gradient(135deg,var(--violet),var(--pink));display:grid;place-items:center;color:#fff;font-size:22px;box-shadow:var(--shadow);}
.logo b{font-size:18px;color:#4a3f b;}
.logo small{display:block;color:#8a97ab;font-weight:600;}
.nav a{display:flex;align-items:center;gap:13px;padding:12px 14px;border-radius:16px;color:#5b6b80;text-decoration:none;font-weight:600;font-size:14px;transition:.2s;margin:2px 0;}
.nav a:hover{background:#fff;box-shadow:var(--inner);}
.nav a.active{background:linear-gradient(135deg,var(--violet),var(--violet2));color:#fff;box-shadow:var(--shadow);}
.nav a i{width:22px;text-align:center;font-size:16px;}
.nav .sep{height:1px;margin:10px 6px;background:linear-gradient(90deg,transparent,#b9c6d9,transparent);}
.side .usr{margin-top:auto;display:flex;align-items:center;gap:10px;padding:12px;background:#fff;border-radius:18px;box-shadow:var(--inner);}
.side .usr .av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--pink),var(--violet));display:grid;place-items:center;color:#fff;font-weight:700;}
.side .usr small{color:#8a97ab;}
/* main */
.main{flex:1;padding:24px 30px;overflow-y:auto;height:100vh;}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;}
.top h1{font-size:26px;color:#3a4a5f;}
.top .actions{display:flex;gap:12px;align-items:center;}
.search-bar{display:flex;align-items:center;gap:8px;background:#fff;padding:10px 16px;border-radius:40px;box-shadow:var(--inner);width:260px;}
.search-bar input{border:none;outline:none;background:transparent;width:100%;font-family:inherit;color:#3a4a5f;}
.btn{border:none;cursor:pointer;padding:11px 20px;border-radius:40px;font-family:inherit;font-weight:700;font-size:13px;background:linear-gradient(135deg,var(--violet),var(--violet2));color:#fff;box-shadow:var(--shadow);transition:.2s;display:inline-flex;align-items:center;gap:8px;}
.btn:hover{transform:translateY(-2px);}
.btn.green{background:linear-gradient(135deg,#43d9a0,#2bbd84);}
.btn.pink{background:linear-gradient(135deg,var(--pink),#ed5fa7);}
.btn.amber{background:linear-gradient(135deg,#f8bf74,#f5a54a);}
.btn.ghost{background:#fff;color:#5b6b80;box-shadow:var(--inner);}
/* stat cards */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:20px;margin-bottom:22px;}
.stat{padding:20px;position:relative;overflow:hidden;}
.stat .ico{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;color:#fff;font-size:19px;margin-bottom:12px;box-shadow:var(--shadow);}
.stat b{font-size:30px;color:#37455e;display:block;}
.stat span{color:#8a97ab;font-weight:600;font-size:13px;}
/* charts row */
.charts{display:grid;grid-template-columns:2fr 1fr 1fr;gap:20px;margin-bottom:22px;}
.chart{ padding:20px;}
.chart h3{font-size:15px;margin-bottom:6px;color:#37455e;}
.chart canvas{max-height:230px;}
/* table */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;padding:12px 14px;color:#8a97ab;font-size:12px;text-transform:uppercase;letter-spacing:.5px;}
td{padding:13px 14px;border-top:1px solid #dfe6f0;font-size:14px;}
tr:hover td{background:#f4f7fc;}
.pill{display:inline-block;padding:5px 12px;border-radius:30px;font-size:12px;font-weight:700;}
.pill.green{background:#e2f7ef;color:#1e9e72;}
.pill.red{background:#ffe7e4;color:#d9574a;}
.pill.amber{background:#fff1de;color:#cf8528;}
.pill.blue{background:#e3efff;color:#3f86d6;}
.pill.violet{background:#ece9ff;color:#6a5adb;}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--pink));color:#fff;display:inline-grid;place-items:center;font-weight:700;font-size:13px;margin-right:10px;}
/* modal */
.modal-bg{position:fixed;inset:0;background:rgba(60,70,95,.4);backdrop-filter:blur(4px);display:none;place-items:center;z-index:50;padding:20px;}
.modal-bg.show{display:grid;}
.modal{width:100%;max-width:680px;max-height:90vh;overflow-y:auto;padding:26px;position:relative;}
.modal h2{font-size:20px;margin-bottom:18px;color:#37455e;}
.modal .x{position:absolute;top:18px;right:20px;width:36px;height:36px;border-radius:50%;border:none;cursor:pointer;background:#fff;box-shadow:var(--inner);font-size:14px;color:#8a97ab;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-grid .full{grid-column:1/-1;}
label{font-size:12px;font-weight:700;color:#5b6b80;display:block;margin-bottom:5px;}
input,select,textarea{width:100%;padding:12px 14px;border:none;border-radius:14px;background:#fff;font-family:inherit;font-size:14px;color:#3a4a5f;box-shadow:var(--inner);outline:none;}
textarea{resize:vertical;min-height:80px;}
.hidden{display:none!important;}
.view{display:none;}
.view.active{display:block;animation:fade .3s;}
@keyframes fade{from{opacity:0;transform:translateY(8px)}to{opacity:1}}
.empty{text-align:center;padding:50px;color:#8a97ab;font-weight:600;}
.tabs{display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;}
.tab{padding:8px 16px;border-radius:30px;background:#fff;box-shadow:var(--inner);cursor:pointer;font-weight:700;font-size:13px;color:#8a97ab;border:none;}
.tab.active{background:linear-gradient(135deg,var(--violet),var(--violet2));color:#fff;box-shadow:var(--shadow);}
.row-actions{display:flex;gap:8px;}
.row-actions button{width:32px;height:32px;border-radius:10px;border:none;cursor:pointer;background:#fff;box-shadow:var(--inner);color:#5b6b80;font-size:13px;}
.row-actions button:hover{color:var(--violet);}
/* login */
.login-screen{height:100vh;display:grid;place-items:center;}
.login-card{width:min(420px,92vw);padding:40px;text-align:center;}
.login-card .big{width:80px;height:80px;border-radius:26px;background:linear-gradient(135deg,var(--violet),var(--pink));display:grid;place-items:center;color:#fff;font-size:36px;margin:0 auto 18px;box-shadow:var(--shadow);}
.login-card h1{color:#37455e;margin-bottom:4px;}
.login-card p{color:#8a97ab;margin-bottom:24px;font-weight:600;}
.login-card form{display:flex;flex-direction:column;gap:16px;}
.login-card input{text-align:center;}
.alert{position:fixed;top:24px;right:24px;z-index:99;padding:14px 22px;border-radius:16px;color:#fff;font-weight:700;box-shadow:var(--shadow);display:none;}
.alert.show{display:block;animation:fade .3s;}
/* media queries - responsive */
.menu-toggle{display:none;width:44px;height:44px;border:none;border-radius:14px;background:#fff;box-shadow:var(--inner);color:#5b6b80;font-size:18px;cursor:pointer;align-items:center;justify-content:center;}
@media(max-width:1000px){
  .charts{grid-template-columns:1fr}
  .stats{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
  .form-grid{grid-template-columns:1fr}
  .menu-toggle{display:flex}
  .app{flex-direction:column}
  .main{padding:16px;height:auto;min-height:100vh}
  .top{flex-wrap:wrap;gap:10px}
  .top h1{font-size:20px}
  .search-bar{width:100%;order:3}
  .actions{width:100%;justify-content:space-between}
  .side{position:fixed;left:-270px;top:0;bottom:0;width:260px;height:100vh;z-index:70;transition:left .3s ease;box-shadow:10px 0 30px rgba(0,0,0,.15)}
  .side.open{left:0}
  .side.open + .backdrop{display:block}
  .backdrop{display:none;position:fixed;inset:0;background:rgba(40,50,70,.45);z-index:65}
  .modal{max-width:95vw;padding:18px}
  .empTabs{flex-wrap:wrap}
  .chart,.stat{padding:16px}
  th,td{padding:10px 8px;font-size:12px}
  .row-actions button{width:28px;height:28px}
  .avatar{width:28px;height:28px;font-size:11px;margin-right:6px}
}
/* PC-side collapse option */
.side.collapsed{width:76px}
.side.collapsed .logo b,.side.collapsed .logo small,.side.collapsed .nav a span,.side.collapsed .usr b,.side.collapsed .usr small{display:none}
.side.collapsed .nav a{justify-content:center;padding:14px}
.side.collapsed .nav a i{font-size:20px;margin:0}
.side.collapsed .usr{justify-content:center}
.side.collapsed .usr .av{margin:0}
.side.collapsed .sep{display:none}
</style>
</head>
<body>

<!-- ALERT -->
<div class="alert clay" id="alert" style="background:linear-gradient(135deg,var(--violet),var(--violet2))"></div>

<div class="app">
  <!-- SIDEBAR -->
  <aside class="side" id="side">
    <div class="logo">
      <div class="ic"><i class="fa-solid fa-users"></i></div>
      <div><b>INDO HR</b><small>Management System</small></div>
      <button class="menu-toggle" style="margin-left:auto;background:none;box-shadow:none;color:#5b6b80;font-size:16px" onclick="closeSidebar()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
      <button class="btn ghost" style="padding:8px 12px;font-size:12px;background:#fff;box-shadow:var(--inner);border:none;cursor:pointer;" onclick="toggleCollapse()"><i class="fa-solid fa-angles-left" id="collapseIcon"></i> <span id="collapseText">Collapse</span></button>
    </div>
    <nav class="nav">
      <a class="active" data-view="dashboard" href="#dashboard"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
      <div class="sep"></div>
      <a data-view="employees" href="#employees"><i class="fa-solid fa-user-group"></i><span>Employees</span></a>
      <a data-view="emp-docs" href="#emp-docs"><i class="fa-solid fa-file-lines"></i><span>Employees' Documents</span></a>
      <a data-view="new-employee" href="#new-employee"><i class="fa-solid fa-user-plus"></i><span>New Employee</span></a>
      <div class="sep"></div>
      <a data-view="attendance" href="#attendance"><i class="fa-solid fa-clock"></i><span>Attendance</span></a>
      <a data-view="leave" href="#leave"><i class="fa-solid fa-umbrella-beach"></i><span>Leave Management</span></a>
      <a data-view="notes" href="#notes"><i class="fa-solid fa-note-sticky"></i><span>HR Notes</span></a>
      <a data-view="disciplinary" href="#disciplinary"><i class="fa-solid fa-triangle-exclamation"></i><span>Disciplinary / Sanctions</span></a>
      <div class="sep"></div>
      <a data-view="documents" href="#documents"><i class="fa-solid fa-file"></i><span>Documents</span></a>
      <a data-view="contracts" href="#contracts"><i class="fa-solid fa-calendar-check"></i><span>Contracts & Probation</span></a>
      <div class="sep"></div>
      <a data-view="departments" href="#departments"><i class="fa-solid fa-building"></i><span>Departments</span></a>
      <a data-view="positions" href="#positions"><i class="fa-solid fa-briefcase"></i><span>Positions</span></a>
      <div class="sep"></div>
      <a data-view="reports" href="#reports"><i class="fa-solid fa-chart-bar"></i><span>HR Reports</span></a>
      <a data-view="analytics" href="#analytics"><i class="fa-solid fa-chart-line"></i><span>Analytics</span></a>
      <a data-view="search" href="#search"><i class="fa-solid fa-magnifying-glass"></i><span>Search</span></a>
      <div class="sep"></div>
      <a data-view="notifications" href="#notifications"><i class="fa-solid fa-bell"></i><span>Notifications</span></a>
      <a data-view="audit" href="#audit"><i class="fa-solid fa-scroll"></i><span>Audit Log</span></a>
      <a data-view="users" href="#users"><i class="fa-solid fa-users-gear"></i><span>Users & Permissions</span></a>
      <a data-view="settings" href="#settings"><i class="fa-solid fa-gear"></i><span>Settings</span></a>
      <div class="sep"></div>
      <a href="?page=logout"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
    </nav>
    <div class="usr">
      <div class="av"><?= strtoupper(substr($user['full_name'] ?? 'H',0,1)) ?></div>
      <div><b style="font-size:13px"><?= htmlspecialchars($user['full_name'] ?? 'HR') ?></b><br><small>HR Manager</small></div>
    </div>
  </aside>

  <!-- MOBILE BACKDROP -->
  <div class="backdrop" id="backdrop" onclick="closeSidebar()"></div>

  <!-- MAIN -->
  <main class="main">
    <div class="top">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="menu-toggle" onclick="openSidebar()"><i class="fa-solid fa-bars"></i></button>
        <h1 id="pageTitle">Dashboard</h1>
      </div>
      <div class="actions">
        <div class="search-bar" id="globalSearchWrap">
          <i class="fa-solid fa-magnifying-glass" style="color:#8a97ab"></i>
          <input id="globalSearch" placeholder="Search employees...">
        </div>
        <button class="btn" onclick="openEmp()"><i class="fa-solid fa-plus"></i>New Employee</button>
      </div>
    </div>

    <!-- DASHBOARD -->
    <div class="view active" id="v-dashboard">
      <div class="stats" id="statCards"></div>
      <div class="charts">
        <div class="chart clay"><h3>Weekly Presence</h3><canvas id="weeklyChart" height="120"></canvas></div>
        <div class="chart clay"><h3>Workforce by Dept</h3><canvas id="deptChart" height="120"></canvas></div>
        <div class="chart clay"><h3>Attendance Status</h3><canvas id="attChart" height="120"></canvas></div>
      </div>
      <div class="chart clay" style="margin-top:20px"><h3>Recent Activity / Alerts</h3><div id="dashAlerts" style="padding-top:8px"></div></div>
    </div>

    <!-- EMPLOYEES -->
        <div class="view" id="v-employees">
          <div class="chart clay" style="padding:16px;margin-bottom:16px">
            <div class="search-bar" style="margin-bottom:12px">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input id="empSearch" placeholder="Search name or employee ID..." oninput="filterEmps()" style="flex:1;border:none;outline:none;background:none;font-size:14px">
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
              <select id="empDepFilter" onchange="filterEmps()" style="padding:9px 12px;border-radius:12px;border:1px solid #dfe4ec;background:#fff;flex:1;min-width:140px"><option value="">Department ▼ (All)</option></select>
              <select id="empStatusFilter" onchange="filterEmps()" style="padding:9px 12px;border-radius:12px;border:1px solid #dfe4ec;background:#fff;flex:1;min-width:130px"><option value="">Status ▼ (All)</option><option>Active</option><option>On Leave</option><option>Probation</option><option>Suspended</option><option>Terminated</option></select>
              <select id="empPosFilter" onchange="filterEmps()" style="padding:9px 12px;border-radius:12px;border:1px solid #dfe4ec;background:#fff;flex:1;min-width:140px"><option value="">Position ▼ (All)</option></select>
              <button class="btn" onclick="openEmp()"><i class="fa-solid fa-plus"></i> New</button>
            </div>
          </div>
          <div class="table-wrap clay" style="padding:14px">
            <table>
              <thead><tr><th>ID</th><th>Name</th><th>Department</th><th>Position</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="empBody"></tbody>
            </table>
            <div class="empty hidden" id="empEmpty">No employees found</div>
          </div>
        </div>

    <!-- DEPARTMENTS -->
    <div class="view" id="v-departments">
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Department</th><th>Description</th><th>Employees</th></tr></thead>
        <tbody id="deptBody"></tbody></table>
      </div>
    </div>

    <!-- ATTENDANCE -->
    <div class="view" id="v-attendance">
      <div class="stats" id="attStats"></div>
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Employee</th><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Status</th><th>Overtime</th></tr></thead>
        <tbody id="attBody"></tbody></table>
      </div>
    </div>

    <!-- REPORTS -->
    <div class="view" id="v-reports">
      <div class="stats">
        <button class="stat clay" onclick="genReport('csv')"><b>Employees</b><span>Generate CSV</span></button>
        <button class="stat clay" onclick="genReport('att')"><b>Attendance</b><span>Generate CSV</span></button>
        <button class="stat clay" onclick="exportCSV('employees')"><b>Live Export</b><span>Open CSV</span></button>
        <button class="stat clay" onclick="window.print()"><b>Print</b><span>Print report</span></button>
      </div>
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Report Name</th><th>Module</th><th>Type</th><th>Generated</th></tr></thead><tbody id="repBody"></tbody></table>
      </div>
    </div>

    <!-- NOTIFICATIONS -->
    <div class="view" id="v-notifications">
      <div id="notifBody" class="chart clay"></div>
    </div>

    <!-- SETTINGS -->
    <div class="view" id="v-settings">
      <div class="charts" style="grid-template-columns:1fr 1fr">
        <div class="chart clay"><h3>Leave Types</h3><div id="leaveTypesBody"></div></div>
        <div class="chart clay"><h3>Positions</h3><div id="posBody"></div></div>
        <div class="chart clay"><h3>System Settings</h3>
          <button class="btn" onclick="openSettingModal()" style="margin-bottom:12px;font-size:12px"><i class="fa-solid fa-plus"></i> Add Setting</button>
          <div id="sysSettingsBody"></div>
        </div>
      </div>
    </div>
  
    <!-- EMPLOYEES' DOCUMENTS -->
    <div class="view" id="v-emp-docs">
      <div class="stats"><div class="stat clay"><b id="empDocCount">0</b><span>Documents</span></div></div>
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Employee</th><th>Document</th><th>Type</th><th>Date</th></tr></thead>
        <tbody id="empDocsBody"></tbody></table>
      </div>
    </div>

    <!-- NEW EMPLOYEE (opens modal via employees page) -->
    <div class="view" id="v-new-employee">
      <div class="chart clay" style="text-align:center;padding:40px">
        <h2><i class="fa-solid fa-user-plus"></i> Add New Employee</h2>
        <p style="color:#7a8aa0;margin:14px 0">Open the new employee registration form.</p>
        <button class="btn" onclick="openEmpForm()"><i class="fa-solid fa-file-circle-plus"></i> New Employee</button>
      </div>
    </div>

    <!-- LEAVE MANAGEMENT -->
    <div class="view" id="v-leave">
      <div class="stats">
        <div class="stat clay"><b id="leaveTotal">0</b><span>Total Requests</span></div>
        <div class="stat clay"><b id="leavePending">0</b><span>Pending</span></div>
      </div>
      <div class="chart clay">
        <button class="btn" onclick="openLeaveModal()" style="margin-bottom:14px"><i class="fa-solid fa-plus"></i> New Leave Request</button>
        <div id="leaveBody"></div>
      </div>
    </div>

    <!-- HR NOTES -->
    <div class="view" id="v-notes">
      <div class="chart clay">
        <button class="btn" onclick="openNoteModal()" style="margin-bottom:14px"><i class="fa-solid fa-plus"></i> Add Note</button>
        <div id="notesBody"></div>
      </div>
    </div>

    <!-- DISCIPLINARY -->
    <div class="view" id="v-disciplinary">
      <div class="chart clay">
        <button class="btn" onclick="openDiscModal()" style="margin-bottom:14px"><i class="fa-solid fa-plus"></i> New Sanction</button>
        <div id="discBody"></div>
      </div>
    </div>

    <!-- DOCUMENTS -->
    <div class="view" id="v-documents">
      <div class="chart clay">
        <button class="btn" onclick="openDocModal()" style="margin-bottom:14px"><i class="fa-solid fa-upload"></i> Upload Document</button>
        <div id="docBody"></div>
      </div>
    </div>

    <!-- CONTRACTS -->
    <div class="view" id="v-contracts">
      <div class="chart clay">
        <button class="btn" onclick="openContractModal()" style="margin-bottom:14px"><i class="fa-solid fa-plus"></i> New Contract</button>
        <div id="contractBody"></div>
      </div>
    </div>

    <!-- POSITIONS -->
    <div class="view" id="v-positions">
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Position</th><th>Department</th></tr></thead>
        <tbody id="posListBody"></tbody></table>
      </div>
    </div>

    <!-- ANALYTICS -->
    <div class="view" id="v-analytics">
      <div class="charts">
        <div class="chart clay"><h3>Employees per Department</h3><canvas id="analyticsDeptChart"></canvas></div>
        <div class="chart clay"><h3>Attendance Rate</h3><canvas id="analyticsAttChart"></canvas></div>
      </div>
      <div class="stats" id="analyticsStats" style="margin-top:14px"></div>
    </div>

    <!-- SEARCH -->
    <div class="view" id="v-search">
      <div class="search-bar" style="margin-bottom:16px">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input id="globalSearchInput" placeholder="Search employees, departments, positions..." oninput="globalSearch(this.value)" style="flex:1;border:none;outline:none;background:none;font-size:14px">
      </div>
      <div id="searchResults"></div>
    </div>

    <!-- AUDIT LOG -->
    <div class="view" id="v-audit">
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>User</th><th>Action</th><th>Time</th></tr></thead>
        <tbody id="auditBody"></tbody></table>
      </div>
    </div>

    <!-- USERS & PERMISSIONS -->
    <div class="view" id="v-users">
      <div class="stats">
        <div class="stat clay"><b id="userCount">0</b><span>Users</span></div>
      </div>
      <div class="table-wrap clay" style="padding:14px">
        <table><thead><tr><th>Username</th><th>Role</th><th>Status</th></tr></thead>
        <tbody id="usersBody"></tbody></table>
      </div>
    </div>

</main>
</div>

<!-- EMPLOYEE MODAL -->
<div class="modal-bg" id="empModal">
  <div class="modal clay" style="max-width:860px">
    <button class="x" onclick="closeModal('empModal')"><i class="fa-solid fa-xmark"></i></button>
    <h2 id="empModalTitle">New Employee</h2>
    <div class="tabs" id="empTabs">
      <button class="tab active" onclick="empStep(1,this)">1 · Personal</button>
      <button class="tab" onclick="empStep(2,this)">2 · Job Info</button>
      <button class="tab" onclick="empStep(3,this)">3 · Bank</button>
      <button class="tab" onclick="empStep(4,this)">4 · MoMo</button>
      <button class="tab" onclick="empStep(5,this)">5 · Emergency</button>
      <button class="tab" onclick="empStep(6,this)">6 · CV</button>
      <button class="tab" onclick="empStep(7,this)">7 · Documents</button>
    </div>
    <form id="empForm" class="form-grid" onsubmit="saveEmp(event)">
      <input type="hidden" name="id" id="e_id">

      <!-- 1. PERSONAL -->
      <div class="step" data-step="1">
        <div class="form-grid">
          <div class="full"><label>Picture</label>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
              <img id="photoPreview" src="" style="width:70px;height:70px;border-radius:16px;object-fit:cover;background:#fff;box-shadow:var(--inner);display:none">
              <input type="file" id="photoFile" accept="image/*" style="flex:1;min-width:200px">
              <button type="button" class="btn ghost" onclick="photoFromCamera()"><i class="fa-solid fa-camera"></i> Camera</button>
            </div>
          </div>
          <div><label>Full Name *</label><input name="full_name" id="e_name" required></div>
          <div><label>Address</label><textarea name="address" id="e_addr"></textarea></div>
          <div><label>Mobile Phone</label><input name="phone" id="e_phone"></div>
          <div><label>Email</label><input type="email" name="email" id="e_email"></div>
          <div><label>SSNIT</label><input name="ssnit" id="e_ssnit"></div>
          <div><label>Last Education</label><input name="last_education" id="e_edu"></div>
          <div><label>Birthday</label><input type="date" name="date_of_birth" id="e_dob"></div>
          <div><label>Marital Status</label><select name="marital_status" id="e_marital"><option>Single</option><option>Married</option><option>Divorced</option><option>Widowed</option></select></div>
          <div><label>Spouse's Name</label><input name="spouse_name" id="e_spouse"></div>
          <div><label>Spouse's Mobile</label><input name="spouse_mobile" id="e_spousemob"></div>
          <div><label>Gender</label><select name="gender" id="e_gender"><option>Male</option><option>Female</option><option>Other</option></select></div>
          <div><label>Nationality</label><input name="nationality" id="e_nation"></div>
        </div>
      </div>

      <!-- 2. JOB INFO -->
      <div class="step hidden" data-step="2">
        <div class="form-grid">
          <div><label>Employee ID</label><input name="employee_code" id="e_code" placeholder="EMP-001"></div>
          <div><label>Position</label><select name="position_id" id="e_pos"></select></div>
          <div><label>Supervisor</label><select name="supervisor_id" id="e_sup"></select></div>
          <div><label>Department</label><select name="department_id" id="e_dept"></select></div>
          <div><label>Employment Type</label><select name="employment_type" id="e_etype"><option>Full-time</option><option>Part-time</option><option>Contract</option><option>Probation</option></select></div>
          <div><label>Start Date</label><input type="date" name="start_date" id="e_sdate"></div>
          <div><label>End Date</label><input type="date" name="end_date" id="e_edate"></div>
          <div><label>Work Location</label><input name="work_location" id="e_wloc"></div>
          <div><label>Shift</label><input name="shift" id="e_shift"></div>
          <div><label>Status</label><select name="status" id="e_status"><option>Active</option><option>On Leave</option><option>Probation</option><option>Suspended</option><option>Terminated</option></select></div>
        </div>
      </div>

      <!-- 3. BANK -->
      <div class="step hidden" data-step="3">
        <h3 style="margin-bottom:16px;color:var(--violet)">Bank Account Information</h3>
        <div class="form-grid">
          <div><label>Account Name</label><input name="bank_account_name" id="e_bacct"></div>
          <div><label>Name of Bank</label><input name="bank_name" id="e_bank"></div>
          <div><label>Account Branch</label><input name="bank_branch" id="e_bbranch"></div>
          <div><label>Branch Code</label><input name="bank_branch_code" id="e_bcode"></div>
          <div><label>Account Number</label><input name="bank_account_number" id="e_bno"></div>
        </div>
      </div>

      <!-- 4. MOMO -->
      <div class="step hidden" data-step="4">
        <h3 style="margin-bottom:16px;color:var(--violet)">MoMo Account Information</h3>
        <div class="form-grid">
          <div><label>Account Name</label><input name="momo_name" id="e_mname"></div>
          <div><label>Account Number</label><input name="momo_number" id="e_mno"></div>
          <div><label>Network</label><select name="momo_network" id="e_mnet"><option>MTN Mobile Money</option><option>Vodafone Cash</option><option>AirtelTigo Money</option><option>Other</option></select></div>
        </div>
      </div>

      <!-- 5. EMERGENCY -->
      <div class="step hidden" data-step="5">
        <h3 style="margin-bottom:16px;color:var(--violet)">Emergency Contact Information</h3>
        <div class="form-grid">
          <div><label>Full Name</label><input name="emergency_contact" id="e_econtact"></div>
          <div><label>Relationship</label><input name="emergency_relationship" id="e_erel"></div>
          <div class="full"><label>Address</label><input name="emergency_address" id="e_eaddr"></div>
          <div><label>Email</label><input type="email" name="emergency_email" id="e_eemail"></div>
          <div><label>Mobile Phone</label><input name="emergency_phone" id="e_ephone"></div>
          <div><label>Date</label><input type="date" name="emergency_date" id="e_edate2"></div>
        </div>
      </div>

      <!-- 6. CV -->
      <div class="step hidden" data-step="6">
        <h3 style="margin-bottom:16px;color:var(--violet)">Curriculum Vitae</h3>
        <div class="form-grid">
          <div class="full"><label>Career Objective</label><textarea name="career_objective" id="e_career"></textarea></div>
          <div class="full"><label>Education Background (Institution · Qualification · Year)</label><div id="cvEduRows"></div><button type="button" class="btn ghost" onclick="addCVRow('cvEduRows')"><i class="fa-solid fa-plus"></i> Add Education</button></div>
          <div class="full"><label>Work Experience (Company · Position · Period · Duties)</label><div id="cvWorkRows"></div><button type="button" class="btn ghost" onclick="addCVRow('cvWorkRows')"><i class="fa-solid fa-plus"></i> Add Experience</button></div>
          <div class="full"><label>Skills</label><input name="skills" id="e_skills" placeholder="Comma separated"></div>
          <div class="full"><label>Training / Certifications (Course · Institution · Year)</label><div id="cvCertRows"></div><button type="button" class="btn ghost" onclick="addCVRow('cvCertRows')"><i class="fa-solid fa-plus"></i> Add Certification</button></div>
          <div class="full"><label>Referees (Name · Position · Org · Phone · Email)</label><div id="cvRefRows"></div><button type="button" class="btn ghost" onclick="addCVRow('cvRefRows')"><i class="fa-solid fa-plus"></i> Add Referee</button></div>
        </div>
      </div>

      <!-- 7. DOCUMENTS -->
      <div class="step hidden" data-step="7">
        <h3 style="margin-bottom:16px;color:var(--violet)">Documents</h3>
        <div class="form-grid">
          <div class="full"><label>Document Type</label><select id="docType"><option>CV</option><option>Academic Certificate</option><option>SSNIT Card</option><option>Ghana Card</option><option>Passport</option><option>Employment Contract</option><option>Other</option></select></div>
          <div class="full"><label>Upload</label><input type="file" id="docFile"></div>
          <div class="full"><button type="button" class="btn green" onclick="uploadDoc()"><i class="fa-solid fa-upload"></i> Upload Document</button></div>
          <div class="full"><div id="docList"></div></div>
        </div>
      </div>

      <div class="full" style="display:flex;gap:12px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="btn ghost" onclick="closeModal('empModal')">Cancel</button>
        <button type="button" class="btn ghost" onclick="printEmployeeForm()"><i class="fa-solid fa-print"></i> Print</button>
        <button type="submit" class="btn green"><i class="fa-solid fa-floppy-disk"></i>Save Employee</button>
      </div>
    </form>
  </div>
</div>

<!-- PROFILE MODAL -->
<div class="modal-bg" id="profileModal">
  <div class="modal clay" style="max-width:760px">
    <button class="x" onclick="closeModal('profileModal')"><i class="fa-solid fa-xmark"></i></button>
    <div id="profileHeader"></div>
    <div class="tabs" id="profTabs">
          <button class="tab active" onclick="profileTab('overview',this)">Overview</button>
          <button class="tab" onclick="profileTab('personal',this)">Personal</button>
          <button class="tab" onclick="profileTab('employment',this)">Employment</button>
          <button class="tab" onclick="profileTab('attendance',this)">Attendance</button>
          <button class="tab" onclick="profileTab('leave',this)">Leave</button>
          <button class="tab" onclick="profileTab('notes',this)">HR Notes</button>
          <button class="tab" onclick="profileTab('disciplinary',this)">Disciplinary</button>
          <button class="tab" onclick="profileTab('docs',this)">Documents</button>
          <button class="tab" onclick="profileTab('training',this)">Training</button>
          <button class="tab" onclick="profileTab('history',this)">History</button>
        </div>
    <div id="profileContent"></div>
  </div>
</div>

<script>
const API='?page=api&action=';
let employeeData=[], deptData=[], posData=[];
const $=s=>document.querySelector(s);
const qs=s=>document.querySelectorAll(s);
function showAlert(m){const a=$('#alert');a.textContent=m;a.classList.add('show');setTimeout(()=>a.classList.remove('show'),3000);}
function esc(s){return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
async function get(a,p=''){try{const r=await fetch(API+a+(p?'&'+p:''));return await r.json();}catch(e){showAlert('Request failed');return null;}}
async function post(a,data){try{const r=await fetch(API+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});return await r.json();}catch(e){return null;}}

/* ---- NAV ---- */
function show(view){
  qs('.nav a').forEach(a=>a.classList.toggle('active',a.dataset.view===view));
  qs('.view').forEach(v=>v.classList.remove('active'));
  const el=$('#v-'+view); if(el)el.classList.add('active');
  const titles={dashboard:'Dashboard',employees:'Employees','emp-docs':"Employees' Documents",'new-employee':'New Employee',attendance:'Attendance',leave:'Leave Management',notes:'HR Notes',disciplinary:'Disciplinary / Sanctions',documents:'Documents',contracts:'Contracts & Probation',departments:'Departments',positions:'Positions',reports:'HR Reports',analytics:'Analytics',search:'Search',notifications:'Notifications',audit:'Audit Log',users:'Users & Permissions',settings:'Settings'};
  $('#pageTitle').textContent=titles[view]||view;
  if(view==='dashboard')loadDashboard();
  if(view==='employees')loadEmployees();
  if(view==='departments')loadDepartments();
  if(view==='attendance')loadAttendance();
  if(view==='reports')loadReports();
  if(view==='settings')loadSettings();
  if(view==='leave')loadLeave();
  if(view==='notes')loadNotes();
  if(view==='disciplinary')loadDisciplinary();
  if(view==='documents')loadDocuments();
  if(view==='contracts')loadContracts();
  if(view==='positions')loadPositions();
  if(view==='analytics')loadAnalytics();
  if(view==='search')loadSearchInit();
  if(view==='audit')loadAudit();
  if(view==='users')loadUsers();
  if(view==='emp-docs')loadEmpDocs();
  if(view==='notifications')loadNotif();
}
qs('.nav a[data-view]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();show(a.dataset.view);closeSidebar();}));

/* ---- SIDEBAR TOGGLE ---- */
function openSidebar(){document.getElementById('side').classList.add('open');}
function closeSidebar(){document.getElementById('side').classList.remove('open');}
function toggleCollapse(){
  const side=document.getElementById('side');
  const onMobile=window.innerWidth<=1000;
  if(onMobile){side.classList.toggle('open');center();return;}
  side.classList.toggle('collapsed');
  const icon=document.getElementById('collapseIcon');
  const txt=document.getElementById('collapseText');
  if(side.classList.contains('collapsed')){icon.className='fa-solid fa-angles-right';txt.textContent='Expand';}
  else{icon.className='fa-solid fa-angles-left';txt.textContent='Collapse';}
}
function center(){}

/* ---- DASHBOARD ---- */
let weekChart,deptChart,attChart;
async function loadDashboard(){
  const d=await get('stats');
  if(!d)return;
  $('#statCards').innerHTML=`
    ${card('fa-users',d.total,'Total Employees','violet')}
    ${card('fa-circle-check',d.present,'Present Today','green')}
    ${card('fa-user-slash',d.absent,'Absent','red')}
    ${card('fa-clock',d.late,'Late','amber')}
    ${card('fa-plane',d.onleave,'On Leave','blue')}
    ${card('fa-user-plus',d.new,'New This Month','green')}
  `;
  weekChart=chart($('#weeklyChart'),weekChart,'line',d.weekLabels,d.weekVals,'#7c6cf0');
  deptChart=chart($('#deptChart'),deptChart,'doughnut',d.deptLabels,d.deptVals,'auto');
  attChart=chart($('#attChart'),attChart,'pie',d.attLabels,d.attVals,'auto');
  $('#dashAlerts').innerHTML=`<div style="display:flex;gap:16px;flex-wrap:wrap">
    <div style="background:#ffe7e4;padding:12px 18px;border-radius:14px;color:#d9574a;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Issues: ${d.issues}</div>
    <div style="background:#fff1de;padding:12px 18px;border-radius:14px;color:#cf8528;font-weight:700"><i class="fa-regular fa-clock"></i> Pending Approvals: ${d.pending}</div>
  </div>`;
}
function card(ic,v,t,c){const cols={violet:'var(--violet)',green:'var(--green)',red:'var(--red)',amber:'var(--amber)',blue:'var(--blue)',pink:'var(--pink)'};return `<div class="stat clay"><div class="ico" style="background:linear-gradient(135deg,${cols[c]},${cols[c]}aa)"><i class="fa-solid ${ic}"></i></div><b>${v}</b><span>${t}</span></div>`;}
function chart(cv,inst,type,labels,data,color){
  const palette=['#7c6cf0','#f06cae','#4dd0a1','#f5b061','#5aa7f0','#f07b6e','#9a8cf5'];
  const cols = color==='auto'? data.map((_,i)=>palette[i%palette.length]) : color;
  if(inst){inst.destroy();}
  return new Chart(cv,{type,data:{labels,datasets:[{data,backgroundColor:cols,borderColor:'#eef2f9',borderWidth:2,fill:true,pointBackgroundColor:'#fff',tension:.4,borderColor:type==='line'?color:cols}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:type!=='line',labels:{boxWidth:12,font:{size:11}}}}}});
}

/* ---- EMPLOYEES ---- */
async function loadEmployees(){
  const data=await get('employees');
  if(!data)return;
  employeeData=data;
  // populate dep + position filter dropdowns
  const deps=[...new Set(data.map(e=>e.dept).filter(Boolean))];
  $('#empDepFilter').innerHTML='<option value="">Department ▼ (All)</option>'+deps.map(d=>`<option>${esc(d)}</option>`).join('');
  const poss=[...new Set(data.map(e=>e.position).filter(Boolean))];
  $('#empPosFilter').innerHTML='<option value="">Position ▼ (All)</option>'+poss.map(p=>`<option>${esc(p)}</option>`).join('');
  renderEmps(data);
}
let empFiltered=[];
function renderEmps(data){
  empFiltered=data;
  const body=$('#empBody');
  if(!data.length){$('#empEmpty').classList.remove('hidden');body.innerHTML='';return;}
  $('#empEmpty').classList.add('hidden');
  body.innerHTML=data.map(e=>`<tr>
    <td><b>${esc(e.employee_code||e.id)}</b></td>
    <td><span class="avatar">${esc(e.full_name?.[0]||'?')}</span><b>${esc(e.full_name)}</b></td>
    <td>${esc(e.dept||'-')}</td><td>${esc(e.position||'-')}</td>
    <td><span class="pill ${stt(e.status)}">${esc(e.status)}</span></td>
    <td><div class="row-actions" style="flex-wrap:wrap;gap:4px">
      <button title="View" onclick="viewProfile(${e.id})"><i class="fa-regular fa-eye"></i></button>
      <button title="Edit" onclick="openEmp(${e.id})"><i class="fa-regular fa-pen-to-square"></i></button>
      <button title="Add Note" onclick="empNote(${e.id})"><i class="fa-solid fa-note-sticky"></i></button>
      <button title="Documents" onclick="viewProfile(${e.id});setTimeout(()=>profileTab('docs'),300)"><i class="fa-solid fa-file-lines"></i></button>
      <button title="Attendance" onclick="viewProfile(${e.id});setTimeout(()=>profileTab('attendance'),300)"><i class="fa-solid fa-clock"></i></button>
      <button title="Leave" onclick="viewProfile(${e.id});setTimeout(()=>profileTab('leave'),300)"><i class="fa-solid fa-umbrella-beach"></i></button>
      <button title="Disciplinary" onclick="viewProfile(${e.id});setTimeout(()=>profileTab('disciplinary'),300)"><i class="fa-solid fa-triangle-exclamation"></i></button>
      <button title="Print" onclick="printProfile(${e.id})"><i class="fa-solid fa-print"></i></button>
      <button title="Deactivate" onclick="deactivateEmp(${e.id})"><i class="fa-solid fa-user-slash"></i></button>
    </div></td></tr>`).join('');
}
function filterEmps(){
  const q=($('#empSearch').value||'').toLowerCase();
  const dep=$('#empDepFilter').value, st=$('#empStatusFilter').value, pos=$('#empPosFilter').value;
  const res=employeeData.filter(e=>
    (!q||(e.full_name||'').toLowerCase().includes(q)||(e.employee_code||'').toLowerCase().includes(q))&&
    (!dep||e.dept===dep)&&(!st||e.status===st)&&(!pos||e.position===pos));
  renderEmps(res);
}
async function empNote(id){
  showPrompt('Add Note for Employee #'+id,[['Note','note','text'],['Category','category','text']],async v=>{v.employee_id=id;const r=await post('save_note',v);showAlert(r&&r.ok?'Note added.':(r&&r.error||'Error'));});
}
async function deactivateEmp(id){
  if(!confirm('Deactivate this employee?'))return;
  const r=await get('deactivate_employee','id='+id);
  showAlert(r&&r.ok?'Employee deactivated.':'Error');
  loadEmployees();
}
function printProfile(id){viewProfile(id);setTimeout(()=>{showModal('profileModal');window.print();},600);}
function stt(s){return ['Active'].includes(s)?'green':['On Leave'].includes(s)?'blue':['Probation'].includes(s)?'amber':['Suspended','Terminated'].includes(s)?'red':'violet';}
async function openEmp(id){
  resetEmp();
  $('#empModalTitle').textContent=id?'Edit Employee':'New Employee';
  await loadSelects();
  if(id){
    const e=await get('employee','id='+id);
    if(e){const f=$('#empForm');for(const k in e){const el=f.elements[k]||f.querySelector('#e_'+k);if(el)el.value=e[k]??'';}f.elements.id.value=id;}
    loadDocList(id);
  }
  showModal('empModal');
}
async function loadSelects(){
  deptData=await get('departments');
  const sup=await get('employees');
  const pos=await get('positions');
  posData=pos;
  $('#e_dept').innerHTML='<option value="">Select</option>'+deptData.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');
  $('#e_pos').innerHTML='<option value="">Select</option>'+(pos&&pos.list?pos.list:[]).map(p=>`<option value="${p.id}">${esc(p.title)}</option>`).join('');
  $('#e_sup').innerHTML='<option value="">Select</option>'+sup.map(s=>`<option value="${s.id}">${esc(s.full_name)}</option>`).join('');
}
function resetEmp(){$('#empForm').reset();$('#empForm').elements.id.value='';empStep(1,$('#empTabs .tab'));}
function empStep(n,t){qs('#empTabs .tab').forEach(x=>x.classList.remove('active'));if(t)t.classList.add('active');qs('#empForm .step').forEach(x=>x.classList.toggle('hidden',+x.dataset.step!==n));}
async function saveEmp(e){e.preventDefault();
  const f=e.target,fd=new FormData(f),data={};
  fd.forEach((v,k)=>data[k]=v||null);
  // capture photo as base64 if selected
  const pfile=document.querySelector('#photoFile');
  if(pfile&&pfile.files&&pfile.files[0]){
    data.photo=await readFileAsDataURL(pfile.files[0]);
  }
  const r=await post('save_employee',data);
  if(r&&r.ok){showAlert('Employee saved');closeModal('empModal');loadEmployees();}
  else showAlert(r&&r.error?r.error:'Save failed');
}
function readFileAsDataURL(file){return new Promise((res,rej)=>{const rd=new FileReader();rd.onload=()=>res(rd.result);rd.onerror=rej;rd.readAsDataURL(file);});}
let cameraActive=false;
async function photoFromCamera(){
  try{
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}});
    const video=document.createElement('video');video.srcObject=stream;video.play();
    const c=document.createElement('canvas');
    // walk through the face-off: capture still after 1s
    setTimeout(()=>{c.width=video.videoWidth;c.height=video.videoHeight;c.getContext('2d').drawImage(video,0,0);stream.getTracks().forEach(t=>t.stop());const url=c.toDataURL('image/jpeg');document.querySelector('#photoPreview').src=url;document.querySelector('#photoPreview').style.display='block';document.querySelector('#photoFile').dataset.base64=url;},1200);
    showAlert('Position face, capturing in ~2s');
  }catch(err){showAlert('Camera access blocked');}
}
// CV dynamic rows
function addCVRow(containerId){
  const box=document.getElementById(containerId);
  const row=document.createElement('div');
  row.style.cssText='display:flex;gap:8px;margin-bottom:8px;align-items:center';
  row.innerHTML='<input placeholder="Value 1" style="flex:1"><input placeholder="Value 2" style="flex:1"><input placeholder="Value 3" style="flex:1"><button type="button" class="btn ghost" style="padding:8px" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>';
  box.appendChild(row);
  if(!box.dataset.seeded){for(let i=0;i<2;i++){const b=box.cloneNode(false);b.innerHTML=row.innerHTML;box.appendChild(b);}box.dataset.seeded='1';}
}
function printEmployeeForm(){
  const e=$('#empForm').elements;
  const printWin=window.open('','_blank','width=800,height=600');
  const val=id=>{const el=document.getElementById(id);return el?(el.value||'') :'';};
  printWin.document.write(`<html><head><title>Employee Application Form</title><style>body{font-family:Arial;padding:40px;color:#222}.h{text-align:center;margin-bottom:24px}table{width:100%;border-collapse:collapse;margin-bottom:18px}td{border:1px solid #999;padding:9px}td.l{font-weight:bold;width:180px;background:#f4f4f4}h2{color:#444;border-bottom:2px solid #444;padding-bottom:4px}</style></head><body>
    <div class="h"><h1>INDO COMPANY</h1><h3>Employee Application Form</h3></div>
    <h2>Personal Details</h2><table><tr><td class="l">Full Name</td><td>${esc(val('e_name'))}</td><td class="l">Birthday</td><td>${esc(val('e_dob'))}</td></tr>
    <tr><td class="l">Address</td><td colspan="3">${esc(val('e_addr'))}</td></tr>
    <tr><td class="l">Mobile</td><td>${esc(val('e_phone'))}</td><td class="l">Email</td><td>${esc(val('e_email'))}</td></tr>
    <tr><td class="l">SSNIT</td><td>${esc(val('e_ssnit'))}</td><td class="l">Last Education</td><td>${esc(val('e_edu'))}</td></tr>
    <tr><td class="l">Gender</td><td>${esc(val('e_gender'))}</td><td class="l">Marital Status</td><td>${esc(val('e_marital'))}</td></tr>
    <tr><td class="l">Spouse's Name</td><td>${esc(val('e_spouse'))}</td><td class="l">Spouse Mobile</td><td>${esc(val('e_spousemob'))}</td></tr>
    <tr><td class="l">Nationality</td><td colspan="3">${esc(val('e_nation'))}</td></tr></table>
    <h2>Job Info</h2><table><tr><td class="l">Position</td><td>${esc(val('e_pos').options? val('e_pos'):'')}</td><td class="l">Department</td><td>${esc(val('e_dept'))}</td></tr>
    <tr><td class="l">Employment Type</td><td>${esc(val('e_etype'))}</td><td class="l">Start Date</td><td>${esc(val('e_sdate'))}</td></tr></table>
    <h2>Bank Account Info</h2><table><tr><td class="l">Account Name</td><td>${esc(val('e_bacct'))}</td><td class="l">Bank</td><td>${esc(val('e_bank'))}</td></tr>
    <tr><td class="l">Branch</td><td>${esc(val('e_bbranch'))}</td><td class="l">Account No.</td><td>${esc(val('e_bno'))}</td></tr></table>
    <h2>Emergency Contact</h2><table><tr><td class="l">Name</td><td>${esc(val('e_econtact'))}</td><td class="l">Relationship</td><td>${esc(val('e_erel'))}</td></tr>
    <tr><td class="l">Mobile</td><td>${esc(val('e_ephone'))}</td><td class="l">Date</td><td>${esc(val('e_edate2'))}</td></tr></table>
    <p style="margin-top:30px"><b>Declaration:</b> I declare that the information provided above is true and accurate to the best of my knowledge.</p>
    <table style="margin-top:40px;border:none"><tr><td style="border:none">Signature: ......................</td><td style="border:none">Date: ......................</td></tr></table>
    </body></html>`);
  printWin.document.close();printWin.print();
}
async function delEmp(id){if(!confirm('Delete employee?'))return;const r=await get('delete_employee','id='+id);if(r&&r.ok){showAlert('Deleted');loadEmployees();}}

/* ---- DEPARTMENTS ---- */
async function loadDepartments(){
  const data=await get('departments');
  if(!data)return;
  $('#deptBody').innerHTML=data.map(d=>{
    const n=employeeData.filter(e=>e.department_id==d.id).length;
    return `<tr><td><b>${esc(d.name)}</b></td><td>${esc(d.description||'')}</td><td><span class="pill violet">${n}</span></td></tr>`;
  }).join('')||'<tr><td colspan="3" class="empty">No departments</td></tr>';
}

/* ---- ATTENDANCE ---- */
async function loadAttendance(){
  const data=await get('attendance');
  if(!data)return;
  const p=data.filter(a=>a.status==='Present').length,ab=data.filter(a=>a.status==='Absent').length,l=data.filter(a=>a.status==='Late').length;
  $('#attStats').innerHTML=card('fa-circle-check',p,'Present','green')+card('fa-user-slash',ab,'Absent','red')+card('fa-clock',l,'Late','amber');
  $('#attBody').innerHTML=data.slice(0,100).map(a=>`<tr><td><span class="avatar">${esc(a.full_name?.[0]||'?')}</span><b>${esc(a.full_name)}</b></td><td>${a.work_date}</td><td>${a.clock_in?`<span class="pill green">${a.clock_in}</span>`:'-'}</td><td>${a.clock_out||'-'}</td><td><span class="pill ${stt(a.status)}">${a.status}</span></td><td>${a.overtime_minutes||0}m</td></tr>`).join('')||'<tr><td colspan="6" class="empty">No attendance records. Add some data in the system.</td></tr>';
}

/* ---- REPORTS ---- */
async function loadReports(){const d=await get('report');$('#repBody').innerHTML=(d&&d.length?d:[]).map(r=>`<tr><td>${esc(r.name||'Report')}</td><td><span class="pill violet">${esc(r.module||'-')}</span></td><td>${esc(r.type||'-')}</td><td>${r.generated_at||'-'}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">Generate reports to view them here</td></tr>';}
function exportCSV(type){window.open(API+(type==='attendance'?'attendance':'employees'),'_blank');setTimeout(()=>showAlert('CSV ready - check new window/open data'),600);}
async function genReport(type){const name={csv:'Employee Master List',att:'Attendance Summary',leave:'Leave Summary'}[type]||'HR Report';const r=await post('generate_report',{name,module:'hr',type:type==='att'||type==='leave'?'CSV':'CSV'});showAlert(r&&r.ok?'Report generated & saved.':'Error');loadReports();}

/* ---- NOTIFICATIONS ---- */
async function loadNotif(){
  const r=await get('notifications');
  const list=r&&r.list?r.list:[];
  $('#notifBody').innerHTML=(list.length?list.map(n=>`<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px;border-bottom:1px solid #eef1f6"><div><b>${esc(n.title||'')}</b><br><small style="color:#8a97ab">${esc(n.message||'')}</small><br><small style="color:#b4bfd0">${n.created_at||''} · ${esc(n.module||'')}</small></div>${n.is_read?'':'<button class="btn ghost" style="padding:6px 12px;font-size:11px" onclick="markNotif('+n.id+')"><i class="fa-solid fa-check"></i> Mark read</button>'}</div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No notifications.</p>');
}
async function markNotif(id){const r=await get('mark_notif','id='+id);loadNotif();}
loadNotif();
/* settings hook */
async function loadSettings(){
  const lt=await get('leave_types');
  $('#leaveTypesBody').innerHTML=(lt||[]).map(l=>`<div style="padding:10px;background:#fff;border-radius:12px;margin:6px 0;box-shadow:var(--inner)"><b>${esc(l.name||l)}</b></div>`).join('')||'<div style="color:#8a97ab">No leave types set</div>';
  const pr=await get('positions');const pos=pr&&pr.list?pr.list:[];
  $('#posBody').innerHTML=pos.map(p=>`<div style="padding:10px;background:#fff;border-radius:12px;margin:6px 0;box-shadow:var(--inner)"><b>${esc(p.title)}</b> <small style="color:#8a97ab">${esc(p.dept||'')}</small></div>`).join('')||'<div style="color:#8a97ab">No positions set</div>';
  const ss=await get('settings');const slist=ss&&ss.list?ss.list:[];
  $('#sysSettingsBody').innerHTML=slist.map(s=>`<div style="padding:10px;background:#fff;border-radius:12px;margin:6px 0;box-shadow:var(--inner)"><b>${esc(s.s_key||'')}</b> <span style="color:#5b6b80">${esc(s.s_value||'')}</span><br><small style="color:#8a97ab">${esc(s.description||'')}</small></div>`).join('')||'<div style="color:#8a97ab">No settings</div>';
}
function openSettingModal(){showPrompt('Add System Setting',[['Key','key','text'],['Value','value','text'],['Description','description','text']],async v=>{const r=await post('save_setting',v);showAlert(r&&r.ok?'Setting saved.':(r&&r.error||'Error'));loadSettings();});}

/* ---- PROFILE ---- */
let currentProf=null;
async function viewProfile(id){
  currentProf=await get('employee','id='+id);
  if(!currentProf)return;
  $('#profileHeader').innerHTML=`<div style="display:flex;gap:18px;align-items:center;margin-bottom:8px">
    <div class="avatar" style="width:64px;height:64px;font-size:26px">${esc(currentProf.full_name?.[0]||'?')}</div>
    <div><h2 style="font-size:22px;color:#37455e">${esc(currentProf.full_name)}</h2>
    <span style="color:#8a97ab;font-weight:600">${esc(currentProf.employee_code)} · ${esc(currentProf.dept||'')} · ${esc(currentProf.position||'')}</span></div>
    <div style="margin-left:auto"><span class="pill ${stt(currentProf.status)}">${esc(currentProf.status)}</span></div>
  </div>`;
  profileTab('overview');
  showModal('profileModal');
}
async function profileTab(tab,btn){
  qs('#profileModal .tab').forEach(x=>x.classList.remove('active'));if(btn)btn.classList.add('active');
  let c='';
  if(tab==='overview')c=`<div class="form-grid"><div><label>Phone</label><p>${esc(currentProf.phone||'-')}</p></div><div><label>Email</label><p>${esc(currentProf.email||'-')}</p></div><div><label>DOB</label><p>${currentProf.date_of_birth||'-'}</p></div><div><label>Joined</label><p>${currentProf.start_date||'-'}</p></div><div><label>Type</label><p>${esc(currentProf.employment_type||'-')}</p></div><div><label>Shift</label><p>${esc(currentProf.shift||'-')}</p></div><div class="full"><label>Address</label><p>${esc(currentProf.address||'-')}</p></div></div>`;
  if(tab==='personal')c=profilePersonal();
  if(tab==='employment')c=await profileEmployment();
  if(tab==='attendance')c=await profileAttendance();
  if(tab==='notes')c=await profileNotes();
  if(tab==='disciplinary')c=await profileDisc();
  if(tab==='contracts')c=await profileCont();
  if(tab==='leave')c=await profileLeave();
  if(tab==='docs')c=await profileDocs();
  if(tab==='training')c=await profileTraining();
  if(tab==='history')c=await profileHistory();
  $('#profileContent').innerHTML=c;
}
function profilePersonal(){
  const e=currentProf;
  return `<div class="form-grid"><div><label>Full Name</label><p>${esc(e.full_name||'-')}</p></div><div><label>Gender</label><p>${esc(e.gender||'-')}</p></div><div><label>Date of Birth</label><p>${e.date_of_birth||'-'}</p></div><div><label>Marital Status</label><p>${esc(e.marital_status||'-')}</p></div><div><label>Spouse</label><p>${esc(e.spouse_name||'-')}</p></div><div><label>Spouse Mobile</label><p>${esc(e.spouse_mobile||'-')}</p></div><div><label>Mobile</label><p>${esc(e.phone||'-')}</p></div><div><label>Email</label><p>${esc(e.email||'-')}</p></div><div><label>Nationality</label><p>${esc(e.nationality||'-')}</p></div><div><label>SSNIT No</label><p>${esc(e.ssnit||'-')}</p></div><div><label>Last Education</label><p>${esc(e.last_education||'-')}</p></div><div class="full"><label>Address</label><p>${esc(e.address||'-')}</p></div></div>`;
}
async function profileEmployment(){
  const e=currentProf;
  let html=`<div class="form-grid"><div><label>Employee Code</label><p>${esc(e.employee_code||'-')}</p></div><div><label>Department</label><p>${esc(e.dept||'-')}</p></div><div><label>Position</label><p>${esc(e.position||'-')}</p></div><div><label>Employment Type</label><p>${esc(e.employment_type||'-')}</p></div><div><label>Start Date</label><p>${e.start_date||'-'}</p></div><div><label>End Date</label><p>${e.end_date||'-'}</p></div><div><label>Work Location</label><p>${esc(e.work_location||'-')}</p></div><div><label>Shift</label><p>${esc(e.shift||'-')}</p></div><div><label>Status</label><p><span class="pill ${stt(e.status)}">${esc(e.status)}</span></p></div><div><label>Bank Acc Name</label><p>${esc(e.bank_account_name||'-')}</p></div><div><label>Bank Name</label><p>${esc(e.bank_name||'-')}</p></div><div><label>Branch</label><p>${esc(e.bank_branch||'-')}</p></div><div><label>Acc Number</label><p>${esc(e.bank_account_number||'-')}</p></div><div><label>MoMo Number</label><p>${esc(e.momo_number||'-')}</p></div><div><label>MoMo Network</label><p>${esc(e.momo_network||'-')}</p></div></div>`;
  const ctr=await profileCont();
  return html+`<div style="margin-top:16px"><h3 style="margin-bottom:8px">Contracts</h3>${ctr}</div>`;
}
async function profileAttendance(){
  const d=await get('attendance','employee_id='+currentProf.id);
  const list=Array.isArray(d)?d:(d&&d.list?d.list:[]);
  if(!list.length)return '<p style="color:#8a97ab;margin:10px">No attendance records.</p>';
  return '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Clock In</th><th>Clock Out</th><th>Status</th></tr></thead><tbody>'+list.slice(0,20).map(a=>`<tr><td>${a.work_date||''}</td><td>${a.clock_in||'-'}</td><td>${a.clock_out||'-'}</td><td>${a.status||''}</td></tr>`).join('')+'</tbody></table></div>';
}
function profileTraining(){
  // Training field not in current schema; show placeholder sourced from career data
  const e=currentProf;
  const certs=[e.last_education, e.ssnit].filter(Boolean);
  return `<div class="form-grid">${(certs.length?certs:['-']).map(x=>`<div><label>Training/Cert</label><p>${esc(x)}</p></div>`).join('')}</div><p style="color:#8a97ab;margin-top:10px;font-style:italic">Training history recorded under employee profile documents.</p>`;
}
async function profileHistory(){
  const e=currentProf;
  const d=await get('contracts','employee_id='+currentProf.id);
  const ctr=Array.isArray(d)?d:(d&&d.list?d.list:[]);
  let rows=[];
  if(e.start_date)rows.push(`<tr><td>Started employment</td><td>${e.start_date}</td></tr>`);
  ctr.forEach(c=>{if(c.start_date)rows.push(`<tr><td>Contract (${esc(c.contract_type||'')}) started</td><td>${c.start_date}</td></tr>`);if(c.end_date)rows.push(`<tr><td>Contract ended / renewed</td><td>${c.end_date}</td></tr>`);});
  if(e.status)rows.push(`<tr><td>Current status: ${esc(e.status)}</td><td>${e.updated_at||'-'}</td></tr>`);
  return rows.length?`<div class="table-wrap"><table><thead><tr><th>Event</th><th>Date</th></tr></thead><tbody>${rows.join('')}</tbody></table></div>`:'<p style="color:#8a97ab;margin:10px">No history recorded.</p>';
}
async function profileNotes(){
  const d=await get('notes','employee_id='+currentProf.id);
  let html=`<div style="display:flex;gap:8px;margin-bottom:12px"><select id="noteCat"><option>Performance</option><option>Attendance</option><option>Behavior</option><option>Warning</option><option>Meeting</option><option>Training</option><option>General</option></select><input id="noteText" placeholder="Add a note..."><button class="btn green" onclick="addNote()">Add</button></div>`;
  return html+(d.map(n=>`<div style="background:#fff;padding:12px;border-radius:14px;margin:8px 0;box-shadow:var(--inner)"><b style="color:var(--violet)">${esc(n.category)}</b><span class="pill amber" style="margin-left:8px">by ${esc(n.author||'HR')}</span><p style="margin-top:6px">${esc(n.note)}</p></div>`).join('')||'<div class="empty">No notes</div>');
}
async function addNote(){const r=await post('save_note',{employee_id:currentProf.id,category:$('#noteCat').value,note:$('#noteText').value});if(r&&r.ok){showAlert('Note added');profileTab('notes');}}
async function profileDisc(){
  const d=await get('disciplinary','employee_id='+currentProf.id);
  let html=`<div class="form-grid" style="margin-bottom:12px"><div><input id="dIssue" placeholder="Issue"></div><div><select id="dSeverity"><option>Verbal Warning</option><option>Written Warning</option><option>Final Warning</option><option>Suspension</option><option>Other</option></select></div><div><button class="btn red" style="background:linear-gradient(135deg,var(--red),#e05a4d)" onclick="addDisc()">Create Case</button></div></div>`;
  return html+d.map(c=>`<div style="background:#fff;padding:14px;border-radius:14px;margin:8px 0;box-shadow:var(--inner)"><b>${esc(c.issue)}</b> <span class="pill ${c.status==='Resolved'||c.status==='Closed'?'green':'red'}">${esc(c.status)}</span> <span class="pill amber">${esc(c.severity)}</span><br><small style="color:#8a97ab">${esc(c.action_taken||'')}</small></div>`).join('')||'<div class="empty">No disciplinary cases</div>';
}
async function addDisc(){const r=await post('save_disciplinary',{employee_id:currentProf.id,issue:$('#dIssue').value,severity:$('#dSeverity').value});if(r&&r.ok){showAlert('Case created');profileTab('disciplinary');}}
async function profileCont(){
  const d=await get('contracts','employee_id='+currentProf.id);
  let html=`<div class="form-grid" style="margin-bottom:12px"><div><select id="cType"><option>Permanent</option><option>Fixed-term</option><option>Probation</option></select></div><div><input type="date" id="cStart"></div><div><input type="date" id="cEnd" placeholder="End"></div><div><input type="date" id="cProbEnd" placeholder="Probation end"></div><button class="btn" onclick="addCont()">Add Contract</button></div>`;
  return html+d.map(c=>`<div style="background:#fff;padding:12px;border-radius:14px;margin:8px 0;box-shadow:var(--inner)"><b>${esc(c.contract_type||'Contract')}</b> <span class="pill ${c.status==='Active'?'green':'amber'}">${esc(c.status)}</span><br><small style="color:#8a97ab">${c.start_date||''} → ${c.end_date||''} · Probation: ${c.probation_end||'-'}</small></div>`).join('')||'<div class="empty">No contracts</div>';
}
async function addCont(){const r=await post('save_contract',{employee_id:currentProf.id,contract_type:$('#cType').value,start_date:$('#cStart').value,end_date:$('#cEnd').value,probation_end:$('#cProbEnd').value});if(r&&r.ok){showAlert('Contract added');profileTab('contracts');}}
async function profileLeave(){
  const d=await get('leave','employee_id='+currentProf.id);
  let html=`<div class="form-grid" style="margin-bottom:12px"><div><input type="date" id="lStart"></div><div><input type="date" id="lEnd"></div><div><input type="number" id="lDays" placeholder="Days" min="1"></div><div><input id="lReason" placeholder="Reason"></div><button class="btn violet" style="background:linear-gradient(135deg,var(--violet),var(--violet2))" onclick="addLeave()">Request Leave</button></div>`;
  return html+d.map(l=>`<div style="background:#fff;padding:12px;border-radius:14px;margin:8px 0;box-shadow:var(--inner)"><b>${esc(l.type||'Leave')}</b> <span class="pill ${l.status==='Approved'?'green':l.status==='Pending'?'amber':'red'}">${esc(l.status)}</span><br><small style="color:#8a97ab">${l.start_date||''} → ${l.end_date||''} · ${l.days_requested||0}d · ${esc(l.reason||'')}</small></div>`).join('')||'<div class="empty">No leave requests</div>';
}
async function addLeave(){const r=await post('save_leave',{employee_id:currentProf.id,start_date:$('#lStart').value,end_date:$('#lEnd').value,days_requested:+$('#lDays').value,reason:$('#lReason').value});if(r&&r.ok){showAlert('Leave requested');profileTab('leave');}}
async function profileDocs(){
  const d=await get('documents','employee_id='+currentProf.id);
  return `<div style="display:flex;gap:8px;margin-bottom:12px"><input id="edEmp" value="${currentProf.id}" type="hidden"><select id="edType" style="flex:1"><option>Employment Contract</option><option>Identification</option><option>Certificate</option><option>Other</option></select><input type="file" id="edFile" style="flex:1"></div><button class="btn green" onclick="uploadDocFromProfile()">Upload</button>`+
  (d.map(x=>`<div style="background:#fff;padding:12px;border-radius:14px;margin:8px 0;box-shadow:var(--inner);display:flex;justify-content:space-between;align-items:center"><span><i class="fa-regular fa-file"></i> ${esc(x.original_name)} · ${esc(x.doc_type)}</span><a class="btn ghost" style="padding:6px 14px" href="${esc(x.file_path)}" target="_blank"><i class="fa-solid fa-download"></i></a></div>`).join('')||'<div class="empty">No documents</div>');
}
function uploadDocFromProfile(){uploadDoc(currentProf.id);}

/* ---- DOC UPLOAD ---- */
async function loadDocList(id){const d=await get('documents','employee_id='+id);$('#docList').innerHTML=(d||[]).map(x=>`<div style="background:#fff;padding:10px;border-radius:12px;margin:6px 0;box-shadow:var(--inner)">${esc(x.original_name)} <small style="color:#8a97ab">${esc(x.doc_type)}</small></div>`).join('')||'<div style="color:#8a97ab">No documents</div>';}
async function uploadDoc(empId){
  const eid=empId||document.querySelector('#empForm input[name=id]')?.value;
  if(!eid){showAlert('Save employee first');return;}
  const f=$('empModal')?$('#docFile'):$('#edFile');
  if(!f||!f.files[0]){showAlert('Choose a file');return;}
  const fd=new FormData();fd.append('employee_id',eid);fd.append('file',f.files[0]);fd.append('doc_type',$('#docType').value||$('#edType').value||'Other');
  const r=await fetch(API+'upload_document',{method:'POST',body:fd,credentials:'same-origin'}).then(x=>x.json());
  if(r&&r.ok){showAlert('Uploaded');if($('empModal').classList.contains('show'))loadDocList(eid);else profileTab('docs');}
}

/* ---- MODAL/Search helpers ---- */
function showModal(id){$('#'+id).classList.add('show');}
function closeModal(id){$('#'+id).classList.remove('show');}
$('#globalSearch').addEventListener('input',e=>{
  const q=e.target.value.toLowerCase();
  qs('#empBody tr').forEach(tr=>tr.style.display=tr.textContent.toLowerCase().includes(q)?'':'none');
});

/* ---- INIT ---- */
(async function init(){
  const view=location.hash.replace('#','')||'dashboard';
  if(['dashboard','employees','departments','attendance','reports','notifications','settings'].includes(view))show(view);else show('dashboard');
  loadNotif();
})();

/* ---- LEAVE MANAGEMENT ---- */
async function loadLeave(){
  const r=await get('leave');
  const list=r&&r.list?r.list:[];
  $('#leaveTotal').textContent=list.length;
  $('#leavePending').textContent=list.filter(x=>x.status==='Pending').length;
  $('#leaveBody').innerHTML=list.length?list.map(x=>`<div class="chart-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px;border-bottom:1px solid #eef1f6"><div><b>${x.employee||''}</b><br><small>${x.leave_type||''} · ${x.start_date||''} → ${x.end_date||''}</small></div><span class="tag" style="color:#fff;background:${x.status==='Approved'?'#2ecc71':x.status==='Rejected'?'#e74c3c':'#f39c12'};padding:4px 10px;border-radius:20px;font-size:11px">${x.status||''}</span></div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No leave requests yet.</p>';
}
function openLeaveModal(){showPrompt('New Leave Request',[
 ['Leave Type','leave_type','text',(deptData[0]&&deptData[0].id)?'':'Annual'],
 ['Employee ID','employee_id','text'],
 ['Start Date','start_date','date'],
 ['End Date','end_date','date'],
 ['Reason','reason','text']
],async v=>{const r=await post('save_leave',v);showAlert(r&&r.ok?'Leave request submitted.':(r&&r.error||'Error'));loadLeave();});}

/* ---- HR NOTES ---- */
async function loadNotes(){
  const r=await get('notes');
  const list=r&&r.list?r.list:[];
  $('#notesBody').innerHTML=list.length?list.map(x=>`<div class="chart-item" style="padding:12px;border-bottom:1px solid #eef1f6"><b>${x.category||'General'}</b> · <b>${x.employee||''}</b> <small style="color:#8a97ab">${x.created_at||''}</small><p style="color:#556;margin:6px 0">${x.note||x.body||''}</p></div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No notes yet.</p>';
}
function openNoteModal(){showPrompt('Add HR Note',[
 ['Category','category','text'],['Note','note','text']],async v=>{const r=await post('save_note',v);showAlert(r&&r.ok?'Note saved.':(r&&r.error||'Error'));loadNotes();});}

/* ---- DISCIPLINARY ---- */
async function loadDisciplinary(){
  const r=await get('disciplinary');
  const list=r&&r.list?r.list:[];
  $('#discBody').innerHTML=list.length?list.map(x=>`<div class="chart-item" style="padding:12px;border-bottom:1px solid #eef1f6"><b>${x.employee||''}</b> · <span style="color:${x.severity==='Severe'?'#e74c3c':'#f39c12'}">${x.issue||x.violation||''}</span><br><small>${x.created_at||x.date||''} · ${x.action_taken||x.action||''}</small></div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No sanctions recorded.</p>';
}
function openDiscModal(){showPrompt('New Sanction',[
 ['Employee ID','employee_id','text'],['Violation','issue','text'],['Date','date','date'],['Action Taken','action_taken','text'],['Severity','severity','select',['Minor','Moderate','Severe']]],async v=>{const r=await post('save_disciplinary',v);showAlert(r&&r.ok?'Sanction recorded.':(r&&r.error||'Error'));loadDisciplinary();});}

/* ---- DOCUMENTS ---- */
async function loadDocuments(){
  const r=await get('documents');
  const list=r&&r.list?r.list:[];
  $('#docBody').innerHTML=list.length?list.map(x=>`<div class="chart-item" style="display:flex;justify-content:space-between;padding:12px;border-bottom:1px solid #eef1f6"><div><b>${x.original_name||x.name||''}</b><br><small>${x.doc_type||x.type||''}</small></div><a class="btn ghost" style="text-decoration:none" href="${x.file_path||x.path||'#'}" target="_blank">View</a></div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No documents uploaded.</p>';
}
function openDocModal(){showPrompt('Upload Document',[
 ['Document Name','name','text'],['Type','doc_type','text'],['Employee ID','employee_id','text']],async v=>{const r=await post('upload_document',v);showAlert(r&&r.ok?'Document uploaded.':(r&&r.error||'Error'));loadDocuments();});}

/* ---- CONTRACTS ---- */
async function loadContracts(){
  const r=await get('contracts');
  const list=r&&r.list?r.list:[];
  $('#contractBody').innerHTML=list.length?list.map(x=>`<div class="chart-item" style="padding:12px;border-bottom:1px solid #eef1f6"><b>${x.employee||''}</b> · ${x.contract_type||x.type||''}<br><small>${x.start_date||''} → ${x.end_date||''} <span style="color:${x.status==='Probation'?'#f39c12':'#2ecc71'}">(${x.status||''})</span></small></div>`).join(''):'<p style="color:#7a8aa0;text-align:center;padding:20px">No contracts yet.</p>';
}
function openContractModal(){showPrompt('New Contract',[
 ['Employee ID','employee_id','text'],['Contract Type','contract_type','text'],['Start Date','start_date','date'],['End Date','end_date','date'],['Status','status','select',['Active','Probation','Expired']]],async v=>{const r=await post('save_contract',v);showAlert(r&&r.ok?'Contract saved.':(r&&r.error||'Error'));loadContracts();});}

/* ---- POSITIONS ---- */
async function loadPositions(){
  const r=await get('positions');
  const list=r&&r.list?r.list:[];
  $('#posListBody').innerHTML=list.length?list.map(x=>`<tr><td>${x.title||''}</td><td>${x.dept||x.department_name||(x.department_id||'')}</td></tr>`).join(''):'<tr><td colspan="2" style="text-align:center;color:#7a8aa0">No positions.</td></tr>';
}

/* ---- EMPLOYEES' DOCUMENTS ---- */
async function loadEmpDocs(){
  const r=await get('documents');
  const list=r&&r.list?r.list:[];
  $('#empDocCount').textContent=list.length;
  $('#empDocsBody').innerHTML=list.length?list.map(x=>`<tr><td>${x.employee||'-'}</td><td>${x.original_name||x.name||''}</td><td>${x.doc_type||x.type||''}</td><td>${x.uploaded_at||x.created_at||''}</td></tr>`).join(''):'<tr><td colspan="4" style="text-align:center;color:#7a8aa0">No documents.</td></tr>';
}

/* ---- ANALYTICS ---- */
let anDeptChart=null,anAttChart=null;
async function loadAnalytics(){
  const [s,emp]=await Promise.all([get('stats'),get('employees')]);
  const empL=Array.isArray(emp)?emp:(emp&&emp.list?emp.list:[]);
  // dept chart
  const deptCounts={};
  empL.forEach(e=>{const d=e.dept||e.department_name||'Other';deptCounts[d]=(deptCounts[d]||0)+1;});
  const deptLabels=Object.keys(deptCounts),deptVals=Object.values(deptCounts);
  if(anDeptChart)anDeptChart.destroy();
  anDeptChart=new Chart($('#analyticsDeptChart'),{type:'bar',data:{labels:deptLabels,datasets:[{label:'Employees',data:deptVals,backgroundColor:'#6c7ae0'}]},options:{responsive:true,plugins:{legend:{display:false}}}});
  // attendance pie
  const att=s&&s.attendance?s.attendance:{};
  if(anAttChart)anAttChart.destroy();
  anAttChart=new Chart($('#analyticsAttChart'),{type:'doughnut',data:{labels:['Present','Absent','Late'],datasets:[{data:[att.present||0,att.absent||0,att.late||0],backgroundColor:['#2ecc71','#e74c3c','#f39c12']}]},options:{responsive:true}});
  $('#analyticsStats').innerHTML=`<div class="stat clay"><b>${empL.length}</b><span>Total Employees</span></div><div class="stat clay"><b>${deptLabels.length}</b><span>Departments</span></div>`;
}

/* ---- SEARCH ---- */
function loadSearchInit(){globalSearch('');}
async function globalSearch(q){
  if(!q){$('#searchResults').innerHTML='<p style="color:#7a8aa0;text-align:center">Type to search employees, departments, positions...</p>';return;}
  const r=await get('employees');
  const list=Array.isArray(r)?r:(r&&r.list?r.list:[]);
  const ql=q.toLowerCase();
  const res=list.filter(e=>(e.full_name||'').toLowerCase().includes(ql)||(e.email||'').toLowerCase().includes(ql)||(e.dept||e.department_name||'').toLowerCase().includes(ql));
  $('#searchResults').innerHTML=res.length?res.map(e=>`<div class="chart-item" style="padding:12px;border-bottom:1px solid #eef1f6"><b>${e.full_name||''}</b> · ${e.email||''}<br><small>${e.department_name||''} · ${e.position_title||''}</small></div>`).join(''):'<p style="color:#7a8aa0;text-align:center">No matching results.</p>';
}

/* ---- AUDIT LOG ---- */
async function loadAudit(){
  const r=await get('audit');
  const list=r&&r.list?r.list:[];
  $('#auditBody').innerHTML=list.length?list.map(e=>`<tr><td>${esc(e.uname||e.user_id||'admin')}</td><td>${esc(e.action||'')} <small style="color:#8a97ab">(${esc(e.module||'')})</small><br><small style="color:#aab4c4">${esc(e.details||'')}</small></td><td>${e.created_at||''}</td></tr>`).join(''):'<tr><td colspan="3" style="text-align:center;color:#7a8aa0">No audit records.</td></tr>';
}

/* ---- USERS & PERMISSIONS ---- */
async function loadUsers(){
  const r=await get('users');
  const list=r&&r.list?r.list:[];
  $('#userCount').textContent=list.length;
  $('#usersBody').innerHTML=list.length?list.map(u=>`<tr><td><b>${esc(u.username||'')}</b><br><small style="color:#8a97ab">${esc(u.email||u.full_name||'')}</small></td><td><span class="pill violet">${esc(u.role||'User')}</span></td><td><span style="color:${u.status==='active'?'#2ecc71':'#e74c3c'}">${esc(u.status||'')}</span></td></tr>`).join(''):'<tr><td colspan="3" style="text-align:center;color:#7a8aa0">No users.</td></tr>';
}

function openEmpForm(){openEmp();}
function showPrompt(title,fields,cb){
  const m=document.createElement('div');m.className='modal-bg';m.style.display='flex';
  m.innerHTML=`<div class="modal clay" style="max-width:420px"><button class="x" onclick="this.closest('.modal-bg').remove()"><i class="fa-solid fa-xmark"></i></button><h2 style="margin-bottom:14px">${title}</h2><form id="pf">${fields.map((f,i)=>`<div style="margin-bottom:10px"><label style="font-size:12px;color:#7a8aa0">${f[0]}</label>${f[3]?`<select name="${f[1]}" style="width:100%;padding:9px;border-radius:10px;border:1px solid #dfe4ec;background:#fff">${f[3].map(o=>`<option>${o}</option>`).join('')}</select>`:`<input name="${f[1]}" type="${f[2]}" style="width:100%;padding:9px;border-radius:10px;border:1px solid #dfe4ec" /></div>`}`).join('')}<button class="btn" style="margin-top:8px" type="submit"><i class="fa-solid fa-check"></i> Save</button></form></div>`;
  document.body.appendChild(m);
  m.querySelector('form').addEventListener('submit',e=>{e.preventDefault();const d={};m.querySelectorAll('[name]').forEach(i=>d[i.name]=i.value);cb(d);m.remove();});
}
</script>
</body>
</html>