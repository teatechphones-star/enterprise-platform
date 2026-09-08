<?php
require_once __DIR__ . '/../config/config.php';
require_once APP_PATH . '/core/helpers.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Auth.php';
Auth::requireLogin();

$db = Database::getInstance();
$msg = '';

// Add a camera
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['do'] ?? '') === 'add_camera') {
    $db->query(
        "INSERT INTO cameras (name, location, stream_url, status)
         VALUES (:n,:l,:s,'Offline')",
        ['n'=>$_POST['name']??'', 'l'=>$_POST['location']??'', 's'=>$_POST['stream_url']??'']
    );
    Auth::audit('cctv','add_camera',"Added camera {$_POST['name']}");
    $msg = 'Camera added.';
}

// Simulate a CCTV detection event (demo of AI detection flow)
if (isset($_GET['simulate']) && isset($_GET['camera'])) {
    $cam_id = (int)$_GET['camera'];
    $types = ['Person Detected','After Hours Entry','Gathering','Vehicle','Intrusion','Motion'];
    $type = $types[array_rand($types)];
    $conf = round(0.72 + (rand(0,25)/100), 2);
    $db->query(
        "INSERT INTO cctv_events (camera_id, event_type, confidence, detected_at)
         VALUES (:c,:t,:f,NOW())",
        ['c'=>$cam_id, 't'=>$type, 'f'=>$conf]
    );
    $event_id = $db->lastInsertId();
    $level = $conf > 0.9 ? 'Critical' : ($conf > 0.8 ? 'Warning' : 'Info');
    $db->query(
        "INSERT INTO cctv_alerts (event_id, alert_level, message)
         VALUES (:e,:l,:m)",
        ['e'=>$event_id, 'l'=>$level, 'm'=>"$type detected (confidence $conf)"]
    );
    Auth::audit('cctv','event_detected',"$type on camera #$cam_id");
    $msg = "Simulated detection: $type (conf $conf) on camera #$cam_id.";
}

$cameras = $db->fetchAll("SELECT * FROM cameras ORDER BY name");
$events = $db->fetchAll(
    "SELECT e.*, c.name AS cam_name FROM cctv_events e
     JOIN cameras c ON c.id = e.camera_id ORDER BY e.detected_at DESC LIMIT 25"
);
$alerts = $db->fetchAll("SELECT * FROM cctv_alerts ORDER BY created_at DESC LIMIT 15");
$online = (int)$db->fetchColumn("SELECT COUNT(*) FROM cameras WHERE status='Online'");
$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM cameras");
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CCTV Monitoring - <?= APP_NAME ?></title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f9;color:#1f2d3d}
.topbar{background:#0f2027;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between}
.topbar .brand{font-size:16px;font-weight:700}.topbar a{color:#9fd3c7;text-decoration:none;font-size:13px}
.wrap{max-width:1100px;margin:22px auto;padding:0 18px}
.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.06);margin-bottom:18px}
h2{font-size:16px;margin-bottom:14px;color:#0f2027}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px}
.cam{background:#111;color:#dfe6ee;border-radius:10px;padding:20px;text-align:center}
.cam .name{font-weight:700}.cam .loc{font-size:12px;color:#8fa3b8;margin:4px 0}
.cam .st{font-size:12px;font-weight:700;padding:3px 10px;border-radius:12px;display:inline-block;margin-top:8px}
.on{background:#1b5e20;color:#c8e6c9}.off{background:#5d4037;color:#ffe0b2}
label{display:block;font-size:12px;font-weight:600;margin:8px 0 4px;color:#444}
input{width:100%;padding:9px 10px;border:1px solid #d0d0d0;border-radius:7px;font-size:14px}
button{padding:10px 18px;background:#2c5364;color:#fff;border:none;border-radius:7px;font-weight:600;cursor:pointer;margin-top:10px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #eee}
th{background:#f0f4f7;font-size:12px;color:#0f2027}
.sim{color:#fff;padding:4px 9px;border-radius:5px;text-decoration:none;font-size:12px;background:#2c5364}
.lv{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.Info{background:#e3f2fd;color:#1565c0}.Warning{background:#fff3e0;color:#e65100}.Critical{background:#fdecea;color:#b71c1c}
</style></head><body>
<div class="topbar"><div class="brand">📹 CCTV Monitoring</div><div><a href="index.php">← Dashboard</a> &nbsp; <a href="logout.php">Logout</a></div></div>
<div class="wrap">
  <?php if ($msg): ?><div class="msg" style="background:#e6f4ea;color:#1e7e34;padding:10px;border-radius:7px;margin-bottom:14px;font-size:14px;">✅ <?= e($msg) ?></div><?php endif; ?>

  <div class="grid">
    <div class="card" style="text-align:center"><h2>Cameras</h2><div style="font-size:34px;font-weight:800;color:#0f2027"><?= $online ?>/<?= $total ?></div><div style="font-size:12px;color:#7b8a9b">online</div></div>
    <div class="card" style="text-align:center"><h2>Events</h2><div style="font-size:34px;font-weight:800;color:#0f2027"><?= count($events) ?></div><div style="font-size:12px;color:#7b8a9b">recent detections</div></div>
  </div>

  <div class="card"><h2>➕ Add Camera</h2>
    <form method="post" action="cctv.php?do=add_camera" style="display:grid;grid-template-columns:1fr 1fr 2fr auto;gap:14px;align-items:end">
      <div><label>Name</label><input name="name" required placeholder="Main Gate"></div>
      <div><label>Location</label><input name="location" placeholder="Entrance"></div>
      <div><label>Stream URL</label><input name="stream_url" placeholder="rtsp://..."></div>
      <button type="submit">Add</button>
    </form>
  </div>

  <div class="card"><h2>🎥 Camera Status &amp; Detection Simulation</h2>
    <?php if (!$cameras): ?><p style="color:#7b8a9b;font-size:13px;">No cameras yet. Add one above, then simulate detections.</p>
    <?php else: ?>
    <table><tr><th>Camera</th><th>Location</th><th>Status</th><th>Action</th></tr>
    <?php foreach ($cameras as $cm): ?>
      <tr><td><?= e($cm['name']) ?></td><td><?= e($cm['location']) ?></td>
      <td><span class="st <?= $cm['status']==='Online'?'on':'off' ?>"><?= e($cm['status']) ?></span></td>
      <td><a class="sim" href="cctv.php?simulate=1&camera=<?= $cm['id'] ?>">🎯 Simulate Detection</a></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>

  <div class="card"><h2>🚨 Recent CCTV Alerts</h2>
    <?php if (!$alerts): ?><p style="color:#7b8a9b;font-size:13px;">No alerts yet.</p>
    <?php else: ?>
    <table><tr><th>Level</th><th>Message</th><th>Time</th></tr>
    <?php foreach ($alerts as $a): ?>
      <tr><td><span class="lv <?= $a['alert_level'] ?>"><?= e($a['alert_level']) ?></span></td>
      <td><?= e($a['message']) ?></td><td><?= e($a['created_at']) ?></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>

  <div class="card"><h2>📡 Recent Detection Events</h2>
    <?php if (!$events): ?><p style="color:#7b8a9b;font-size:13px;">No events yet.</p>
    <?php else: ?>
    <table><tr><th>Camera</th><th>Event Type</th><th>Confidence</th><th>Detected At</th></tr>
    <?php foreach ($events as $ev): ?>
      <tr><td><?= e($ev['cam_name']) ?></td><td><?= e($ev['event_type']) ?></td>
      <td><?= $ev['confidence'] ?>%</td><td><?= e($ev['detected_at']) ?></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>
</div></body></html>
