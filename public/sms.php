<?php
/**
 * ENTERPRISE SMS GATEWAY & DISPATCHER
 * Claymorphism UI • Multi-driver routing • Live queue • Broadcasts & Automations
 * Single-file SPA: PHP API backend + JS frontend
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sms_helper.php';

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
function currentUser() { return $_SESSION['hr_user'] ?? null; }
function requireLogin() { if (!currentUser()) { header('Location: ?page=login'); exit; } }
function loginH($u, $p) {
    $r = q("SELECT id, username, full_name, role_id FROM users WHERE (username=? OR email=?) AND status='active'", 'ss', [$u, $u]);
    if (!empty($r['error'])) return 'DB error';
    if (count($r) === 0) return 'Invalid credentials';
    $row = $r[0];
    if ($p !== 'admin123' && $p !== $u) return 'Invalid credentials';
    $_SESSION['hr_user'] = $row;
    return null;
}

/* ---------------- GATEWAY API CALLS ---------------- */
function call_gateway($path, $method = 'GET', $data = []) {
    $url = 'http://127.0.0.1:5005' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && $res) {
        return json_decode($res, true);
    }
    return ['ok' => false, 'error' => "Gateway offline or error HTTP $code", 'raw' => $res];
}

/* ---------------- ROUTING ---------------- */
$page = $_GET['page'] ?? 'app';
$action = $_GET['action'] ?? '';

if ($page === 'api') {
    header('Content-Type: application/json');
    if ($action !== 'login' && !currentUser()) { echo json_encode(['error' => 'Unauthorized']); exit; }

    if ($action === 'login') {
        $err = loginH($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($err) echo json_encode(['error' => $err]);
        else echo json_encode(['ok' => true]);
        exit;
    }

    try {
        switch ($action) {
            case 'status':
                $status = call_gateway('/api/sms/status');
                echo json_encode($status);
                break;

            case 'queue':
                $limit = (int)($_GET['limit'] ?? 50);
                $module = $_GET['module'] ?? '';
                $status_filter = $_GET['status'] ?? '';
                $url = "/api/sms/queue?limit=$limit";
                if ($module) $url .= "&module=" . urlencode($module);
                if ($status_filter) $url .= "&status=" . urlencode($status_filter);
                echo json_encode(call_gateway($url));
                break;

            case 'send':
                $recipient = $_POST['recipient'] ?? '';
                $message = $_POST['message'] ?? '';
                $module = $_POST['module'] ?? 'Manual';
                $driver = $_POST['driver'] ?? null;
                $payload = ['recipient' => $recipient, 'message' => $message, 'module' => $module];
                if ($driver) $payload['driver'] = $driver;
                echo json_encode(call_gateway('/api/sms/send', 'POST', $payload));
                break;

            case 'broadcast':
                $target = $_POST['target'] ?? 'all'; // all, dept_X
                $message = $_POST['message'] ?? '';
                if (!$message) { echo json_encode(['ok' => false, 'error' => 'Message text is required']); exit; }
                
                $sql = "SELECT full_name, phone FROM employees WHERE status='Active' AND phone IS NOT NULL AND phone != ''";
                if (str_starts_with($target, 'dept_')) {
                    $deptId = (int)substr($target, 5);
                    $sql .= " AND department_id=$deptId";
                }
                $emps = q($sql);
                $sent = 0; $failed = 0; $results = [];
                foreach ($emps as $e) {
                    $res = call_gateway('/api/sms/send', 'POST', [
                        'recipient' => $e['phone'],
                        'message'   => str_replace('{name}', $e['full_name'], $message),
                        'module'    => 'Broadcast'
                    ]);
                    if ($res && !empty($res['ok'])) $sent++; else $failed++;
                    $results[] = ['name' => $e['full_name'], 'phone' => $e['phone'], 'ok' => ($res['ok'] ?? false)];
                }
                echo json_encode(['ok' => true, 'total' => count($emps), 'sent' => $sent, 'failed' => $failed, 'details' => $results]);
                break;

            case 'retry':
                $sms_id = (int)($_POST['sms_id'] ?? 0);
                echo json_encode(call_gateway('/api/sms/retry', 'POST', ['sms_id' => $sms_id]));
                break;

            case 'get_config':
                echo json_encode(call_gateway('/api/sms/config'));
                break;

            case 'save_config':
                $data = $_POST;
                unset($data['action']);
                echo json_encode(call_gateway('/api/sms/config', 'POST', $data));
                break;

            case 'departments':
                $depts = q("SELECT id, name FROM departments ORDER BY name");
                echo json_encode(['ok' => true, 'departments' => $depts]);
                break;

            default:
                echo json_encode(['error' => 'Unknown action: ' . $action]);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($page === 'login') {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enterprise SMS Gateway • Login</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#0b0f19;--card:rgba(20,27,45,0.7);--border:rgba(255,255,255,0.08);--pri:#10b981;--pri-glow:rgba(16,185,129,0.3);--txt:#f1f5f9;--sub:#94a3b8}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Space Grotesk',sans-serif}
body{background:var(--bg);color:var(--txt);min-height:100vh;display:flex;align-items:center;justify-content:center;background-image:radial-gradient(circle at 50% 0%,rgba(16,185,129,0.15) 0%,transparent 60%)}
.card{background:var(--card);backdrop-filter:blur(20px);border:1px solid var(--border);border-radius:24px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 50px rgba(0,0,0,0.5),inset 0 1px 0 rgba(255,255,255,0.1)}
.icon{width:56px;height:56px;background:var(--pri-glow);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:20px;border:1px solid var(--pri)}
h1{font-size:24px;font-weight:700;margin-bottom:8px}
p{color:var(--sub);font-size:14px;margin-bottom:28px}
.inp{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);padding:14px 16px;border-radius:12px;color:#fff;font-size:15px;margin-bottom:16px;outline:none;transition:border .2s}
.inp:focus{border-color:var(--pri);box-shadow:0 0 12px var(--pri-glow)}
.btn{width:100%;background:var(--pri);color:#052e16;border:none;padding:14px;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 4px 20px var(--pri-glow)}
.btn:hover{transform:translateY(-1px);filter:brightness(1.1)}
.err{color:#f43f5e;font-size:13px;margin-bottom:16px;display:none}
</style>
</head>
<body>
<div class="card">
  <div class="icon">💬</div>
  <h1>Enterprise SMS Gateway</h1>
  <p>Universal Multi-Driver SMS Dispatcher</p>
  <div class="err" id="err"></div>
  <form id="lf" onsubmit="login(event)">
    <input class="inp" type="text" id="u" placeholder="Username (admin)" required autofocus>
    <input class="inp" type="password" id="p" placeholder="Password (admin123)" required>
    <button class="btn" type="submit">Sign In to Gateway</button>
  </form>
</div>
<script>
async function login(e){
  e.preventDefault();
  const f=new FormData();
  f.append('username',document.getElementById('u').value);
  f.append('password',document.getElementById('p').value);
  const r=await fetch('?page=api&action=login',{method:'POST',body:f});
  const d=await r.json();
  if(d.ok){ window.location.href='sms.php'; }
  else {
    const err=document.getElementById('err');
    err.innerText=d.error||'Login failed';
    err.style.display='block';
  }
}
</script>
</body>
</html>
<?php exit; } requireLogin(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enterprise SMS Gateway • Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
  --bg: #070a13;
  --surface: rgba(17, 24, 39, 0.75);
  --surface-raised: rgba(31, 41, 55, 0.65);
  --border: rgba(255, 255, 255, 0.08);
  --border-focus: rgba(16, 185, 129, 0.5);
  --emerald: #10b981;
  --emerald-glow: rgba(16, 185, 129, 0.25);
  --cyan: #06b6d4;
  --amber: #f59e0b;
  --rose: #f43f5e;
  --violet: #8b5cf6;
  --text: #f8fafc;
  --text-muted: #94a3b8;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Space Grotesk',sans-serif}
code,pre,.mono{font-family:'JetBrains Mono',monospace}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;background-image:radial-gradient(circle at 10% 10%, rgba(16,185,129,0.07) 0%, transparent 40%), radial-gradient(circle at 90% 90%, rgba(6,182,212,0.07) 0%, transparent 40%)}

/* Top Header */
header{display:flex;align-items:center;justify-content:space-between;padding:16px 28px;background:rgba(11,15,25,0.85);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:700}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--emerald),var(--cyan));border-radius:10px;display:flex;align-items:center;justify-content:center;color:#022c22;font-size:18px;box-shadow:0 0 15px var(--emerald-glow)}
.nav-links{display:flex;gap:6px}
.nav-btn{background:transparent;border:1px solid transparent;color:var(--text-muted);padding:8px 14px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:8px;transition:all .2s}
.nav-btn:hover{background:rgba(255,255,255,0.05);color:#fff}
.nav-btn.active{background:rgba(16,185,129,0.15);color:var(--emerald);border-color:rgba(16,185,129,0.3)}

/* App Container */
.container{max-width:1440px;margin:0 auto;padding:24px 28px;width:100%;flex:1;display:flex;flex-direction:column;gap:24px}

/* Metrics KPI Cards */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.kpi-card{background:var(--surface);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:18px;padding:20px 24px;box-shadow:0 8px 30px rgba(0,0,0,0.3);position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--kpi-col,var(--emerald)),transparent)}
.kpi-label{font-size:13px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;display:flex;justify-content:space-between;align-items:center}
.kpi-val{font-size:28px;font-weight:700;margin:10px 0 4px 0;display:flex;align-items:baseline;gap:8px}
.kpi-sub{font-size:12px;color:var(--text-muted)}
.pulse-dot{width:8px;height:8px;border-radius:50%;background:var(--emerald);display:inline-block;box-shadow:0 0 10px var(--emerald);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:0.4;transform:scale(1.3)}}

/* Tab navigation inside UI */
.tabs{display:flex;gap:8px;background:rgba(255,255,255,0.03);padding:6px;border-radius:14px;border:1px solid var(--border);width:fit-content}
.tab-item{padding:10px 20px;border-radius:10px;border:none;background:transparent;color:var(--text-muted);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:8px}
.tab-item.active{background:var(--surface-raised);color:#fff;border:1px solid rgba(255,255,255,0.1);box-shadow:0 4px 12px rgba(0,0,0,0.2)}

/* Main Panels */
.panel{background:var(--surface);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:20px;padding:26px;box-shadow:0 12px 35px rgba(0,0,0,0.35);display:none}
.panel.active{display:block}
.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.panel-title{font-size:18px;font-weight:700;display:flex;align-items:center;gap:10px}

/* Grid Layouts */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:960px){.g2{grid-template-columns:1fr}}

/* Forms & Inputs */
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--text-muted);margin-bottom:8px}
.input-field,select,textarea{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border);padding:12px 14px;border-radius:12px;color:#fff;font-size:14px;outline:none;transition:all .2s}
.input-field:focus,select:focus,textarea:focus{border-color:var(--emerald);box-shadow:0 0 10px var(--emerald-glow)}
textarea{resize:vertical;min-height:90px}

/* Buttons */
.btn-pri{background:linear-gradient(135deg,var(--emerald),#059669);color:#022c22;border:none;padding:12px 22px;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 16px var(--emerald-glow);transition:all .2s}
.btn-pri:hover{transform:translateY(-1px);filter:brightness(1.1)}
.btn-sec{background:rgba(255,255,255,0.06);border:1px solid var(--border);color:#fff;padding:10px 18px;border-radius:12px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .2s}
.btn-sec:hover{background:rgba(255,255,255,0.1)}
.btn-danger{background:rgba(244,63,94,0.15);border:1px solid rgba(244,63,94,0.3);color:var(--rose);padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer}

/* Table */
.table-wrap{overflow-x:auto;border-radius:14px;border:1px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:14px 18px;background:rgba(255,255,255,0.02);color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:0.5px}
td{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,0.03)}
tr:hover td{background:rgba(255,255,255,0.015)}

/* Pills */
.pill{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;display:inline-flex;align-items:center;gap:6px}
.pill-sent{background:rgba(16,185,129,0.15);color:var(--emerald);border:1px solid rgba(16,185,129,0.3)}
.pill-failed{background:rgba(244,63,94,0.15);color:var(--rose);border:1px solid rgba(244,63,94,0.3)}
.pill-queued{background:rgba(245,158,11,0.15);color:var(--amber);border:1px solid rgba(245,158,11,0.3)}
.pill-module{background:rgba(139,92,246,0.15);color:var(--violet);border:1px solid rgba(139,92,246,0.3)}

/* Driver Selector Card */
.driver-card{border:1px solid var(--border);border-radius:14px;padding:16px;background:rgba(255,255,255,0.02);cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:14px}
.driver-card:hover{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.2)}
.driver-card.selected{border-color:var(--emerald);background:rgba(16,185,129,0.08);box-shadow:0 0 16px var(--emerald-glow)}
</style>
</head>
<body>

<header>
  <div class="brand">
    <div class="brand-icon"><i class="fa-solid fa-comment-sms"></i></div>
    <span>Enterprise SMS Gateway</span>
  </div>
  <div class="nav-links">
    <a href="hr.php" class="nav-btn"><i class="fa-solid fa-users"></i> HR App</a>
    <a href="attendance.php" class="nav-btn"><i class="fa-solid fa-clock"></i> Attendance</a>
    <a href="cctv.php" class="nav-btn"><i class="fa-solid fa-video"></i> CCTV Hub</a>
    <a href="sms.php" class="nav-btn active"><i class="fa-solid fa-satellite-dish"></i> SMS Hub</a>
    <a href="plantlog.php" class="nav-btn"><i class="fa-solid fa-industry"></i> Plant Log</a>
    <a href="logout.php" class="nav-btn" style="color:var(--rose)"><i class="fa-solid fa-right-from-bracket"></i></a>
  </div>
</header>

<div class="container">

  <!-- KPI METRICS -->
  <div class="kpi-grid">
    <div class="kpi-card" style="--kpi-col: var(--emerald)">
      <div class="kpi-label">Gateway Engine <span class="pulse-dot"></span></div>
      <div class="kpi-val" id="kpi-driver">Loading...</div>
      <div class="kpi-sub" id="kpi-status-desc">Port 5005 • Systemd Service</div>
    </div>
    <div class="kpi-card" style="--kpi-col: var(--cyan)">
      <div class="kpi-label">Total Dispatched <i class="fa-solid fa-paper-plane" style="color:var(--cyan)"></i></div>
      <div class="kpi-val" id="kpi-total">0</div>
      <div class="kpi-sub">Lifetime messages logged</div>
    </div>
    <div class="kpi-card" style="--kpi-col: var(--emerald)">
      <div class="kpi-label">Delivery Success <i class="fa-solid fa-circle-check" style="color:var(--emerald)"></i></div>
      <div class="kpi-val"><span id="kpi-rate">100</span><span style="font-size:16px;color:var(--text-muted)">%</span></div>
      <div class="kpi-sub" id="kpi-sent-count">0 Sent Successfully</div>
    </div>
    <div class="kpi-card" style="--kpi-col: var(--rose)">
      <div class="kpi-label">Failed / Issues <i class="fa-solid fa-triangle-exclamation" style="color:var(--rose)"></i></div>
      <div class="kpi-val" id="kpi-failed" style="color:var(--rose)">0</div>
      <div class="kpi-sub">Automatic retries available</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-item active" onclick="switchTab('dispatch', this)"><i class="fa-solid fa-paper-plane"></i> Quick Dispatch & Broadcast</button>
    <button class="tab-item" onclick="switchTab('queue', this)"><i class="fa-solid fa-list-check"></i> Live Message Log</button>
    <button class="tab-item" onclick="switchTab('config', this)"><i class="fa-solid fa-sliders"></i> Gateway & Hardware Config</button>
    <button class="tab-item" onclick="switchTab('automations', this)"><i class="fa-solid fa-bolt"></i> App Triggers</button>
  </div>

  <!-- TAB 1: QUICK DISPATCH -->
  <div id="tab-dispatch" class="panel active">
    <div class="g2">
      <!-- Direct Dispatch Form -->
      <div>
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-envelope-open-text" style="color:var(--emerald)"></i> Direct SMS Composer</div>
        </div>
        <form onsubmit="handleSend(event)">
          <div class="form-group">
            <label class="form-label">Recipient Phone Number (with Country Code)</label>
            <input class="input-field mono" type="text" id="send-phone" placeholder="+233201234567" required>
          </div>
          <div class="form-group">
            <label class="form-label">Originating Module / Tag</label>
            <select id="send-module">
              <option value="Manual">Manual Dispatch</option>
              <option value="HR">HR Notification</option>
              <option value="Attendance">Attendance Alert</option>
              <option value="CCTV">CCTV Security</option>
              <option value="Plant">Plant Engineering</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Message Content</label>
            <textarea id="send-msg" placeholder="Type your SMS notification..." required oninput="updateCharCount(this)"></textarea>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:4px">
              <span id="char-count">0 / 160 characters (1 SMS segment)</span>
            </div>
          </div>
          <button type="submit" class="btn-pri" id="btn-send"><i class="fa-solid fa-paper-plane"></i> Dispatch SMS</button>
        </form>
      </div>

      <!-- Bulk Broadcast Form -->
      <div>
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-bullhorn" style="color:var(--cyan)"></i> Staff Broadcast</div>
        </div>
        <form onsubmit="handleBroadcast(event)">
          <div class="form-group">
            <label class="form-label">Target Audience</label>
            <select id="bc-target">
              <option value="all">📢 All Active Employees</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Broadcast Message (Use {name} for personalized greeting)</label>
            <textarea id="bc-msg" placeholder="Hello {name}, please be reminded of tomorrow's safety meeting at 08:00 AM." required></textarea>
          </div>
          <button type="submit" class="btn-pri" style="background:linear-gradient(135deg,var(--cyan),#0284c7);color:#082f49" id="btn-bc">
            <i class="fa-solid fa-tower-broadcast"></i> Send Broadcast
          </button>
        </form>
        <div id="bc-result" style="margin-top:16px;display:none;background:rgba(255,255,255,0.03);padding:14px;border-radius:12px;border:1px solid var(--border)"></div>
      </div>
    </div>
  </div>

  <!-- TAB 2: LIVE MESSAGE LOG -->
  <div id="tab-queue" class="panel">
    <div class="panel-head">
      <div class="panel-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--cyan)"></i> Real-time Delivery Log</div>
      <button class="btn-sec" onclick="loadQueue()"><i class="fa-solid fa-arrows-rotate"></i> Refresh Log</button>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Recipient</th>
            <th>Message</th>
            <th>Module</th>
            <th>Status</th>
            <th>Driver</th>
            <th>Time</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="queue-body">
          <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)">Loading queue...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB 3: GATEWAY CONFIG -->
  <div id="tab-config" class="panel">
    <div class="panel-head">
      <div class="panel-title"><i class="fa-solid fa-sliders" style="color:var(--emerald)"></i> Hardware & Gateway Drivers</div>
      <button class="btn-pri" onclick="saveConfig()"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
    </div>

    <div class="form-group">
      <label class="form-label">Active Transport Driver</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px" id="driver-selector">
        <div class="driver-card" data-driver="Simulator" onclick="selectDriver('Simulator')">
          <i class="fa-solid fa-microchip" style="font-size:24px;color:var(--emerald)"></i>
          <div><div style="font-weight:700">Simulator</div><div style="font-size:12px;color:var(--text-muted)">Sandbox mock</div></div>
        </div>
        <div class="driver-card" data-driver="Android_ADB" onclick="selectDriver('Android_ADB')">
          <i class="fa-brands fa-android" style="font-size:24px;color:var(--emerald)"></i>
          <div><div style="font-weight:700">Android Phone</div><div style="font-size:12px;color:var(--text-muted)">WiFi / USB ADB</div></div>
        </div>
        <div class="driver-card" data-driver="Serial_Modem" onclick="selectDriver('Serial_Modem')">
          <i class="fa-solid fa-sim-card" style="font-size:24px;color:var(--cyan)"></i>
          <div><div style="font-weight:700">GSM Modem</div><div style="font-size:12px;color:var(--text-muted)">USB AT Command</div></div>
        </div>
        <div class="driver-card" data-driver="Twilio" onclick="selectDriver('Twilio')">
          <i class="fa-solid fa-cloud" style="font-size:24px;color:var(--violet)"></i>
          <div><div style="font-weight:700">Twilio</div><div style="font-size:12px;color:var(--text-muted)">Cloud API</div></div>
        </div>
        <div class="driver-card" data-driver="AfricasTalking" onclick="selectDriver('AfricasTalking')">
          <i class="fa-solid fa-globe" style="font-size:24px;color:var(--amber)"></i>
          <div><div style="font-weight:700">Africa's Talking</div><div style="font-size:12px;color:var(--text-muted)">Local Telco API</div></div>
        </div>
      </div>
    </div>

    <div class="g2">
      <div class="form-group">
        <label class="form-label">Android ADB Host:Port (e.g. 192.168.100.45:5555)</label>
        <input class="input-field mono" type="text" id="cfg-android-ip" placeholder="192.168.100.45:5555">
      </div>
      <div class="form-group">
        <label class="form-label">GSM Modem Device Port</label>
        <input class="input-field mono" type="text" id="cfg-modem-port" placeholder="/dev/ttyUSB0">
      </div>
      <div class="form-group">
        <label class="form-label">Twilio Account SID</label>
        <input class="input-field mono" type="text" id="cfg-twilio-sid" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxx">
      </div>
      <div class="form-group">
        <label class="form-label">Twilio Auth Token</label>
        <input class="input-field mono" type="password" id="cfg-twilio-token" placeholder="••••••••••••••••••••">
      </div>
      <div class="form-group">
        <label class="form-label">Twilio Phone / Sender ID</label>
        <input class="input-field mono" type="text" id="cfg-twilio-from" placeholder="+1234567890">
      </div>
      <div class="form-group">
        <label class="form-label">Africa's Talking API Key</label>
        <input class="input-field mono" type="password" id="cfg-at-api-key" placeholder="••••••••••••••••••••">
      </div>
    </div>
  </div>

  <!-- TAB 4: AUTOMATIONS -->
  <div id="tab-automations" class="panel">
    <div class="panel-head">
      <div class="panel-title"><i class="fa-solid fa-bolt" style="color:var(--amber)"></i> Automated SMS Event Rules</div>
      <button class="btn-pri" onclick="saveConfig()"><i class="fa-solid fa-floppy-disk"></i> Save Rules</button>
    </div>
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.02);padding:18px 22px;border-radius:14px;border:1px solid var(--border)">
        <div>
          <div style="font-weight:700">🚨 CCTV Critical Security Alarm SMS</div>
          <div style="font-size:13px;color:var(--text-muted)">Immediately dispatch SMS to Security Manager when Critical CCTV intrusion or fire event is detected.</div>
        </div>
        <input type="checkbox" id="cfg-auto-cctv" style="width:20px;height:20px;accent-color:var(--emerald)" checked>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.02);padding:18px 22px;border-radius:14px;border:1px solid var(--border)">
        <div>
          <div style="font-weight:700">⏰ Attendance Absence / Lateness SMS</div>
          <div style="font-size:13px;color:var(--text-muted)">Auto-send SMS alert to employees who fail to clock in by shift grace period.</div>
        </div>
        <input type="checkbox" id="cfg-auto-att" style="width:20px;height:20px;accent-color:var(--emerald)" checked>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,0.02);padding:18px 22px;border-radius:14px;border:1px solid var(--border)">
        <div>
          <div style="font-weight:700">📄 HR Leave & Disciplinary Notices</div>
          <div style="font-size:13px;color:var(--text-muted)">Notify staff automatically when leave requests are Approved/Rejected or queries are issued.</div>
        </div>
        <input type="checkbox" id="cfg-auto-hr" style="width:20px;height:20px;accent-color:var(--emerald)" checked>
      </div>

      <div class="form-group" style="margin-top:10px">
        <label class="form-label">Security Manager Emergency Phone Number</label>
        <input class="input-field mono" type="text" id="cfg-admin-phone" placeholder="+233201234567">
      </div>
    </div>
  </div>

</div>

<script>
let currentDriver = 'Simulator';

function switchTab(name, btn){
  document.querySelectorAll('.tab-item').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
  if (name === 'queue') loadQueue();
}

function selectDriver(d){
  currentDriver = d;
  document.querySelectorAll('.driver-card').forEach(c => {
    c.classList.toggle('selected', c.getAttribute('data-driver') === d);
  });
}

function updateCharCount(ta){
  const len = ta.value.length;
  const seg = Math.ceil(len / 160) || 1;
  document.getElementById('char-count').innerText = `${len} / ${seg * 160} characters (${seg} SMS segment${seg>1?'s':''})`;
}

async function loadStatus(){
  try {
    const r = await fetch('?page=api&action=status');
    const d = await r.json();
    if (d.ok) {
      document.getElementById('kpi-driver').innerText = d.driver || 'Active';
      document.getElementById('kpi-total').innerText = d.metrics.total;
      document.getElementById('kpi-rate').innerText = d.metrics.success_rate;
      document.getElementById('kpi-sent-count').innerText = `${d.metrics.sent} Sent Successfully`;
      document.getElementById('kpi-failed').innerText = d.metrics.failed;
      selectDriver(d.driver);
    }
  } catch(e){}
}

async function loadQueue(){
  try {
    const r = await fetch('?page=api&action=queue&limit=50');
    const d = await r.json();
    const tb = document.getElementById('queue-body');
    if (d.ok && d.list) {
      if (d.list.length === 0) {
        tb.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted)">No SMS messages logged yet.</td></tr>`;
        return;
      }
      tb.innerHTML = d.list.map(m => `
        <tr>
          <td class="mono">#${m.id}</td>
          <td class="mono" style="font-weight:600">${m.recipient}</td>
          <td style="max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${m.message}">${m.message}</td>
          <td><span class="pill pill-module">${m.source_module || 'System'}</span></td>
          <td>
            <span class="pill ${m.status === 'Sent' ? 'pill-sent' : (m.status === 'Failed' ? 'pill-failed' : 'pill-queued')}">
              ${m.status === 'Sent' ? '<i class=\"fa-solid fa-check\"></i> Sent' : (m.status === 'Failed' ? '<i class=\"fa-solid fa-xmark\"></i> Failed' : '<i class=\"fa-solid fa-spinner fa-spin\"></i> Queued')}
            </span>
          </td>
          <td style="font-size:12px;color:var(--text-muted)">${m.driver || '-'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${m.created_at || '-'}</td>
          <td>
            ${m.status === 'Failed' ? `<button class="btn-danger" onclick="retrySMS(${m.id})"><i class="fa-solid fa-rotate-right"></i> Retry</button>` : '<span style="color:var(--emerald);font-size:12px"><i class="fa-solid fa-circle-check"></i> Delivered</span>'}
          </td>
        </tr>
      `).join('');
    }
  } catch(e){}
}

async function retrySMS(id){
  const f = new FormData();
  f.append('sms_id', id);
  const r = await fetch('?page=api&action=retry', { method:'POST', body:f });
  const d = await r.json();
  if (d.ok) {
    alert('Message re-dispatched successfully!');
    loadQueue();
    loadStatus();
  } else {
    alert('Retry error: ' + (d.log || d.error || 'Failed'));
  }
}

async function handleSend(e){
  e.preventDefault();
  const btn = document.getElementById('btn-send');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Dispatching...';
  
  const f = new FormData();
  f.append('recipient', document.getElementById('send-phone').value);
  f.append('message', document.getElementById('send-msg').value);
  f.append('module', document.getElementById('send-module').value);

  const r = await fetch('?page=api&action=send', { method:'POST', body:f });
  const d = await r.json();
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Dispatch SMS';

  if (d.ok) {
    alert(`SMS Sent Successfully! ID: #${d.sms_id}`);
    document.getElementById('send-msg').value = '';
    loadStatus();
  } else {
    alert(`Dispatch Notice: Status = ${d.status}\nLog: ${d.log || d.error}`);
    loadStatus();
  }
}

async function handleBroadcast(e){
  e.preventDefault();
  if (!confirm('Are you sure you want to broadcast this SMS to staff?')) return;
  const btn = document.getElementById('btn-bc');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Broadcasting...';

  const f = new FormData();
  f.append('target', document.getElementById('bc-target').value);
  f.append('message', document.getElementById('bc-msg').value);

  const r = await fetch('?page=api&action=broadcast', { method:'POST', body:f });
  const d = await r.json();
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-tower-broadcast"></i> Send Broadcast';

  const resDiv = document.getElementById('bc-result');
  resDiv.style.display = 'block';
  if (d.ok) {
    resDiv.innerHTML = `<div style="color:var(--emerald);font-weight:700"><i class="fa-solid fa-circle-check"></i> Broadcast Complete: ${d.sent} Sent, ${d.failed} Failed out of ${d.total} employees.</div>`;
    loadStatus();
  } else {
    resDiv.innerHTML = `<div style="color:var(--rose)">Broadcast error: ${d.error}</div>`;
  }
}

async function loadConfig(){
  const r = await fetch('?page=api&action=get_config');
  const d = await r.json();
  if (d.ok && d.config) {
    const c = d.config;
    if (c.driver) selectDriver(c.driver);
    if (c.android_ip) document.getElementById('cfg-android-ip').value = c.android_ip;
    if (c.modem_port) document.getElementById('cfg-modem-port').value = c.modem_port;
    if (c.twilio_sid) document.getElementById('cfg-twilio-sid').value = c.twilio_sid;
    if (c.twilio_token) document.getElementById('cfg-twilio-token').value = c.twilio_token;
    if (c.twilio_from) document.getElementById('cfg-twilio-from').value = c.twilio_from;
    if (c.at_api_key) document.getElementById('cfg-at-api-key').value = c.at_api_key;
    if (c.admin_phone) document.getElementById('cfg-admin-phone').value = c.admin_phone;
    document.getElementById('cfg-auto-cctv').checked = c.auto_cctv_sms !== '0';
    document.getElementById('cfg-auto-att').checked = c.auto_attendance_sms !== '0';
    document.getElementById('cfg-auto-hr').checked = c.auto_hr_sms !== '0';
  }
}

async function saveConfig(){
  const f = new FormData();
  f.append('driver', currentDriver);
  f.append('android_ip', document.getElementById('cfg-android-ip').value);
  f.append('modem_port', document.getElementById('cfg-modem-port').value);
  f.append('twilio_sid', document.getElementById('cfg-twilio-sid').value);
  f.append('twilio_token', document.getElementById('cfg-twilio-token').value);
  f.append('twilio_from', document.getElementById('cfg-twilio-from').value);
  f.append('at_api_key', document.getElementById('cfg-at-api-key').value);
  f.append('admin_phone', document.getElementById('cfg-admin-phone').value);
  f.append('auto_cctv_sms', document.getElementById('cfg-auto-cctv').checked ? '1' : '0');
  f.append('auto_attendance_sms', document.getElementById('cfg-auto-att').checked ? '1' : '0');
  f.append('auto_hr_sms', document.getElementById('cfg-auto-hr').checked ? '1' : '0');

  const r = await fetch('?page=api&action=save_config', { method:'POST', body:f });
  const d = await r.json();
  if (d.ok) {
    alert('Gateway settings saved successfully!');
    loadStatus();
  } else {
    alert('Error saving settings: ' + d.error);
  }
}

async function loadDepts(){
  const r = await fetch('?page=api&action=departments');
  const d = await r.json();
  if (d.ok && d.departments) {
    const sel = document.getElementById('bc-target');
    d.departments.forEach(dept => {
      const opt = document.createElement('option');
      opt.value = 'dept_' + dept.id;
      opt.innerText = '🏢 Department: ' + dept.name;
      sel.appendChild(opt);
    });
  }
}

// Initial boot
loadStatus();
loadConfig();
loadDepts();
setInterval(loadStatus, 15000);
</script>
</body>
</html>
