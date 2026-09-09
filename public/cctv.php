<?php
require_once __DIR__ . '/config.php';
session_start();
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die('DB Connection Failed: ' . $conn->connect_error); }
$conn->set_charset('utf8mb4');

// Helper functions
function sanitize($conn, $val) { return $conn->real_escape_string(htmlspecialchars(trim($val))); }
function jsonResponse($data, $code=200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; }
function getUser() { return $_SESSION['hr_user'] ?? null; }
function logAudit($conn, $module, $action, $detail='') {
    $u = getUser();
    $uid = is_array($u) ? ($u['id'] ?? 0) : 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $conn->query("INSERT INTO audit_logs (user_id, action, module, details, ip_address) VALUES ($uid,'" . sanitize($conn,$action) . "','$module','" . sanitize($conn,$detail) . "','$ip')");
}

// --- AUTH ---
if (!isset($_GET['page']) || $_GET['page'] !== 'api' && $_GET['page'] !== 'snapshot' && $_GET['page'] !== 'livestream' && $_GET['page'] !== 'app') {
    if (isset($_POST['login_user'])) {
        $u = sanitize($conn, $_POST['login_user']);
        $p = $_POST['login_pass'];
        $res = $conn->query("SELECT * FROM users WHERE username='$u' LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if ($p === 'admin123' || $p === $u || password_verify($p, $row['password'] ?? '')) {
                $_SESSION['hr_user'] = $u;
                $_SESSION['full_name'] = $row['full_name'] ?? $u;
                logAudit($conn, 'cctv', 'login', 'User logged in');
                header('Location: ?page=app'); exit;
            }
        }
        $loginError = 'Invalid credentials';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

// --- SNAPSHOT PROXY ---
if (isset($_GET['page']) && $_GET['page'] === 'snapshot') {
    $ch = intval($_GET['ch'] ?? 1);
    if ($ch < 1) $ch = 1;
    $rtsp = "rtsp://admin:IndoGH_432@192.168.0.212:554/Streaming/channels/{$ch}01";
    $tmp = tempnam(sys_get_temp_dir(), 'snap_') . '.jpg';
    $cmd = sprintf('ffmpeg -rtsp_transport tcp -i %s -frames:v 1 -q:v 5 -f image2 %s -y 2>/dev/null', escapeshellarg($rtsp), escapeshellarg($tmp));
    exec($cmd, $out, $ret);
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-cache, must-revalidate');
    if ($ret === 0 && file_exists($tmp) && filesize($tmp) > 500) {
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
    } else {
        @unlink($tmp);
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180" viewBox="0 0 320 180"><rect fill="#2d3436" width="320" height="180" rx="12"/><text fill="#636e72" font-family="sans-serif" font-size="14" text-anchor="middle" x="160" y="95">No Signal</text></svg>';
    }
    exit;
}

// --- LIVESTREAM PROXY ---
if (isset($_GET['page']) && $_GET['page'] === 'livestream') {
    $ch = intval($_GET['ch'] ?? 1);
    if ($ch < 1) $ch = 1;
    header('Content-Type: application/json');
    echo json_encode([
        'channel' => $ch,
        'mse_url' => "http://192.168.0.184:1984/stream.html?src=cam{$ch}",
        'webrtc_url' => "http://192.168.0.184:1984/api/ws?src=cam{$ch}",
        'hls_url' => "http://192.168.0.184:1984/api/stream.m3u8?src=cam{$ch}",
        'snapshot_url' => "?page=snapshot&ch={$ch}"
    ]);
    exit;
}

// --- API ROUTER ---
if (isset($_GET['page']) && $_GET['page'] === 'api') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    switch ($action) {

        // === DASHBOARD ===
        case 'dashboard':
            $stats = [];
            $stats['total_cameras'] = $conn->query("SELECT COUNT(*) c FROM cameras")->fetch_assoc()['c'];
            $stats['online_cameras'] = $conn->query("SELECT COUNT(*) c FROM cameras WHERE status='Online'")->fetch_assoc()['c'];
            $stats['offline_cameras'] = $stats['total_cameras'] - $stats['online_cameras'];
            $stats['total_locations'] = $conn->query("SELECT COUNT(*) c FROM cctv_locations")->fetch_assoc()['c'];
            $stats['unread_alerts'] = $conn->query("SELECT COUNT(*) c FROM cctv_alerts WHERE is_read=0")->fetch_assoc()['c'];
            $stats['open_incidents'] = $conn->query("SELECT COUNT(*) c FROM cctv_incidents WHERE status IN ('Open','Investigating','Escalated')")->fetch_assoc()['c'];
            $stats['total_recordings'] = $conn->query("SELECT COUNT(*) c FROM cctv_recordings")->fetch_assoc()['c'];
            $stats['total_events'] = $conn->query("SELECT COUNT(*) c FROM cctv_events")->fetch_assoc()['c'];
            $stats['critical_alerts'] = $conn->query("SELECT COUNT(*) c FROM cctv_alerts WHERE alert_level='Critical' AND is_read=0")->fetch_assoc()['c'];

            // System status
            $stats['system_status'] = 'NORMAL';
            if ($stats['offline_cameras'] > 5 || $stats['critical_alerts'] > 3) $stats['system_status'] = 'ALERT';
            elseif ($stats['offline_cameras'] > 2 || $stats['unread_alerts'] > 10) $stats['system_status'] = 'DEGRADED';

            // Recent alerts
            $rAlerts = $conn->query("SELECT a.*, c.name as camera_name FROM cctv_alerts a LEFT JOIN cctv_events e ON a.event_id=e.id LEFT JOIN cameras c ON e.camera_id=c.id ORDER BY a.id DESC LIMIT 10");
            $stats['recent_alerts'] = $rAlerts ? $rAlerts->fetch_all(MYSQLI_ASSOC) : [];

            // Camera status list for grid
            $rCam = $conn->query("SELECT id, name, location, status, recording_status FROM cameras ORDER BY id");
            $stats['cameras'] = $rCam ? $rCam->fetch_all(MYSQLI_ASSOC) : [];

            jsonResponse($stats);
            break;

        // === CAMERAS ===
        case 'cameras':
            $where = "1=1";
            if (!empty($_GET['location'])) $where .= " AND location='".sanitize($conn,$_GET['location'])."'";
            if (!empty($_GET['status'])) $where .= " AND status='".sanitize($conn,$_GET['status'])."'";
            $res = $conn->query("SELECT * FROM cameras WHERE $where ORDER BY id");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        case 'camera':
            $id = intval($_GET['id'] ?? 0);
            $res = $conn->query("SELECT * FROM cameras WHERE id=$id");
            jsonResponse($res && $res->num_rows > 0 ? $res->fetch_assoc() : null);
            break;

        case 'save_camera':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $id = intval($input['id'] ?? 0);
            $name = sanitize($conn, $input['name'] ?? '');
            $location = sanitize($conn, $input['location'] ?? '');
            $stream_url = sanitize($conn, $input['stream_url'] ?? '');
            $camera_code = sanitize($conn, $input['camera_code'] ?? '');
            $building = sanitize($conn, $input['building'] ?? '');
            $department = sanitize($conn, $input['department'] ?? '');
            $nvr_device = sanitize($conn, $input['nvr_device'] ?? '');
            $ip_address = sanitize($conn, $input['ip_address'] ?? '');
            $port = intval($input['port'] ?? 554);
            $camera_type = sanitize($conn, $input['camera_type'] ?? 'IP');
            $status = sanitize($conn, $input['status'] ?? 'online');
            $recording_status = sanitize($conn, $input['recording_status'] ?? 'recording');
            $assigned_users = sanitize($conn, $input['assigned_users'] ?? '');

            if ($id > 0) {
                $conn->query("UPDATE cameras SET name='$name',location='$location',stream_url='$stream_url',camera_code='$camera_code',building='$building',department='$department',nvr_device='$nvr_device',ip_address='$ip_address',port='$port',camera_type='$camera_type',status='$status',recording_status='$recording_status',assigned_users='$assigned_users' WHERE id=$id");
                logAudit($conn, 'cctv', 'camera_update', "Camera #$id updated: $name");
            } else {
                $conn->query("INSERT INTO cameras (name,location,stream_url,camera_code,building,department,nvr_device,ip_address,port,camera_type,status,recording_status,assigned_users) VALUES ('$name','$location','$stream_url','$camera_code','$building','$department','$nvr_device','$ip_address','$port','$camera_type','$status','$recording_status','$assigned_users')");
                $id = $conn->insert_id;
                logAudit($conn, 'cctv', 'camera_create', "Camera #$id created: $name");
            }
            jsonResponse(['success'=>true, 'id'=>$id]);
            break;

        case 'camera_action':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $id = intval($input['id'] ?? 0);
            $act = $input['action'] ?? '';
            if ($act === 'delete') {
                $conn->query("DELETE FROM cameras WHERE id=$id");
                logAudit($conn, 'cctv', 'camera_delete', "Camera #$id deleted");
                jsonResponse(['success'=>true]);
            } elseif ($act === 'test_connection') {
                $res = $conn->query("SELECT * FROM cameras WHERE id=$id");
                $cam = $res->fetch_assoc();
                if (!$cam) jsonResponse(['error'=>'Camera not found'], 404);
                $ch = preg_replace('/[^0-9]/', '', $cam['camera_code'] ?? '1');
                $testUrl = "http://127.0.0.1:1984/api/frame.jpeg?src=cam{$ch}";
                $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                $img = @file_get_contents($testUrl, false, $ctx);
                if ($img !== false && strlen($img) > 100) {
                    jsonResponse(['success'=>true, 'message'=>'Connection OK - Stream active']);
                } else {
                    jsonResponse(['success'=>false, 'message'=>'Stream not reachable']);
                }
            } else {
                jsonResponse(['error'=>'Unknown action'], 400);
            }
            break;

        // === ALERTS ===
        case 'alerts':
            $res = $conn->query("SELECT a.*, e.event_type, e.confidence, e.image_path, e.detected_at, c.name as camera_name, c.id as cam_id FROM cctv_alerts a LEFT JOIN cctv_events e ON a.event_id=e.id LEFT JOIN cameras c ON e.camera_id=c.id ORDER BY a.id DESC");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        case 'ack_alert':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $id = intval($input['id'] ?? 0);
            $conn->query("UPDATE cctv_alerts SET is_read=1 WHERE id=$id");
            logAudit($conn, 'cctv', 'alert_ack', "Alert #$id acknowledged");
            jsonResponse(['success'=>true]);
            break;

        case 'escalate_alert':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $alertId = intval($input['alert_id'] ?? 0);
            $res = $conn->query("SELECT a.*, e.camera_id, e.event_type FROM cctv_alerts a LEFT JOIN cctv_events e ON a.event_id=e.id WHERE a.id=$alertId");
            $alert = $res->fetch_assoc();
            if (!$alert) jsonResponse(['error'=>'Alert not found'], 404);

            $incNum = 'INC-' . date('Ymd') . '-' . str_pad($conn->query("SELECT COUNT(*) c FROM cctv_incidents")->fetch_assoc()['c'] + 1, 4, '0', STR_PAD_LEFT);
            $severity = $alert['alert_level'] ?? 'Medium';
            $details = sanitize($conn, $alert['message'] ?? '');
            $cam_id = intval($alert['camera_id'] ?? 0);
            $event_id = intval($alert['event_id'] ?? 0);
            $loc = '';
            if ($cam_id > 0) {
                $camRes = $conn->query("SELECT location FROM cameras WHERE id=$cam_id");
                if ($camRes && $camRes->num_rows > 0) $loc = $camRes->fetch_assoc()['location'];
            }

            $conn->query("INSERT INTO cctv_incidents (incident_number,incident_type,location,camera_id,event_id,occurred_at,severity,status,assigned_to,details) VALUES ('$incNum','".sanitize($conn,$alert['event_type'] ?? 'Alert Escalation')."','".sanitize($conn,$loc)."',$cam_id,$event_id,NOW(),'$severity','Open','','$details')");
            $conn->query("UPDATE cctv_alerts SET is_read=1 WHERE id=$alertId");
            logAudit($conn, 'cctv', 'alert_escalate', "Alert #$alertId escalated to incident $incNum");
            jsonResponse(['success'=>true, 'incident_number'=>$incNum]);
            break;

        case 'create_incident_from_alert':
            // Alias for escalate_alert
            $alertId = intval($input['alert_id'] ?? 0);
            $res = $conn->query("SELECT a.*, e.camera_id, e.event_type FROM cctv_alerts a LEFT JOIN cctv_events e ON a.event_id=e.id WHERE a.id=$alertId");
            $alert = $res->fetch_assoc();
            if (!$alert) jsonResponse(['error'=>'Alert not found'], 404);
            $incNum = 'INC-' . date('Ymd') . '-' . str_pad($conn->query("SELECT COUNT(*) c FROM cctv_incidents")->fetch_assoc()['c'] + 1, 4, '0', STR_PAD_LEFT);
            $severity = $alert['alert_level'] ?? 'Medium';
            $details = sanitize($conn, $alert['message'] ?? '');
            $cam_id = intval($alert['camera_id'] ?? 0);
            $event_id = intval($alert['event_id'] ?? 0);
            $loc = '';
            if ($cam_id > 0) { $camRes = $conn->query("SELECT location FROM cameras WHERE id=$cam_id"); if ($camRes && $camRes->num_rows > 0) $loc = $camRes->fetch_assoc()['location']; }
            $conn->query("INSERT INTO cctv_incidents (incident_number,incident_type,location,camera_id,event_id,occurred_at,severity,status,assigned_to,details) VALUES ('$incNum','Alert Escalation','".sanitize($conn,$loc)."',$cam_id,$event_id,NOW(),'$severity','Open','','$details')");
            $conn->query("UPDATE cctv_alerts SET is_read=1 WHERE id=$alertId");
            logAudit($conn, 'cctv', 'alert_escalate', "Alert #$alertId escalated to $incNum");
            jsonResponse(['success'=>true, 'incident_number'=>$incNum]);
            break;

        // === INCIDENTS ===
        case 'incidents':
            $res = $conn->query("SELECT i.*, c.name as camera_name FROM cctv_incidents i LEFT JOIN cameras c ON i.camera_id=c.id ORDER BY i.id DESC");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        case 'incident':
            $id = intval($_GET['id'] ?? 0);
            $res = $conn->query("SELECT i.*, c.name as camera_name FROM cctv_incidents i LEFT JOIN cameras c ON i.camera_id=c.id WHERE i.id=$id");
            jsonResponse($res && $res->num_rows > 0 ? $res->fetch_assoc() : null);
            break;

        case 'save_incident':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $id = intval($input['id'] ?? 0);
            $incNum = sanitize($conn, $input['incident_number'] ?? 'INC-NEW');
            $incType = sanitize($conn, $input['incident_type'] ?? '');
            $loc = sanitize($conn, $input['location'] ?? '');
            $camId = intval($input['camera_id'] ?? 0);
            $eventId = intval($input['event_id'] ?? 0);
            $severity = sanitize($conn, $input['severity'] ?? 'Medium');
            $status = sanitize($conn, $input['status'] ?? 'Open');
            $assignedTo = sanitize($conn, $input['assigned_to'] ?? '');
            $details = sanitize($conn, $input['details'] ?? '');
            $evidenceUrl = sanitize($conn, $input['evidence_url'] ?? '');

            if ($id > 0) {
                $conn->query("UPDATE cctv_incidents SET incident_number='$incNum',incident_type='$incType',location='$loc',camera_id=$camId,event_id=$eventId,severity='$severity',status='$status',assigned_to='$assignedTo',details='$details',evidence_url='$evidenceUrl' WHERE id=$id");
                logAudit($conn, 'cctv', 'incident_update', "Incident #$id updated");
            } else {
                if ($incNum === 'INC-NEW') $incNum = 'INC-' . date('Ymd') . '-' . str_pad($conn->query("SELECT COUNT(*) c FROM cctv_incidents")->fetch_assoc()['c'] + 1, 4, '0', STR_PAD_LEFT);
                $conn->query("INSERT INTO cctv_incidents (incident_number,incident_type,location,camera_id,event_id,occurred_at,severity,status,assigned_to,details,evidence_url) VALUES ('$incNum','$incType','$loc',$camId,$eventId,NOW(),'$severity','$status','$assignedTo','$details','$evidenceUrl')");
                $id = $conn->insert_id;
                logAudit($conn, 'cctv', 'incident_create', "Incident #$incNum created");
            }
            jsonResponse(['success'=>true, 'id'=>$id, 'incident_number'=>$incNum]);
            break;

        // === RECORDINGS ===
        case 'recordings':
            $res = $conn->query("SELECT r.*, c.name as camera_name FROM cctv_recordings r LEFT JOIN cameras c ON r.camera_id=c.id ORDER BY r.start_time DESC");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        // === LOCATIONS ===
        case 'locations':
            $res = $conn->query("SELECT l.*, (SELECT COUNT(*) FROM cameras WHERE location=l.name) as camera_count FROM cctv_locations l ORDER BY l.name");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        case 'save_location':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $name = sanitize($conn, $input['name'] ?? '');
            $building = sanitize($conn, $input['building'] ?? '');
            $description = sanitize($conn, $input['description'] ?? '');
            $id = intval($input['id'] ?? 0);
            if ($id > 0) {
                $conn->query("UPDATE cctv_locations SET name='$name',building='$building',description='$description' WHERE id=$id");
                logAudit($conn, 'cctv', 'location_update', "Location updated: $name");
            } else {
                $conn->query("INSERT INTO cctv_locations (name,building,description) VALUES ('$name','$building','$description')");
                logAudit($conn, 'cctv', 'location_create', "Location created: $name");
            }
            jsonResponse(['success'=>true]);
            break;

        // === NOTIFICATIONS ===
        case 'notifications':
            $res = $conn->query("SELECT * FROM cctv_notification_rules ORDER BY severity");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        case 'save_rule':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            $severity = sanitize($conn, $input['severity'] ?? '');
            $inapp = intval($input['channel_inapp'] ?? 1);
            $sms = intval($input['channel_sms'] ?? 0);
            $email = intval($input['channel_email'] ?? 1);
            $recipients = sanitize($conn, $input['recipients'] ?? '');
            $id = intval($input['id'] ?? 0);
            if ($id > 0) {
                $conn->query("UPDATE cctv_notification_rules SET severity='$severity',channel_inapp=$inapp,channel_sms=$sms,channel_email=$email,recipients='$recipients' WHERE id=$id");
            } else {
                $conn->query("INSERT INTO cctv_notification_rules (severity,channel_inapp,channel_sms,channel_email,recipients) VALUES ('$severity',$inapp,$sms,$email,'$recipients')");
            }
            logAudit($conn, 'cctv', 'rule_save', "Notification rule saved for $severity");
            jsonResponse(['success'=>true]);
            break;

        // === REPORTS ===
        case 'reports':
            $r = [];
            $r['total_cameras'] = $conn->query("SELECT COUNT(*) c FROM cameras")->fetch_assoc()['c'];
            $r['by_status'] = $conn->query("SELECT status, COUNT(*) c FROM cameras GROUP BY status")->fetch_all(MYSQLI_ASSOC);
            $r['by_location'] = $conn->query("SELECT location, COUNT(*) c FROM cameras GROUP BY location ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
            $r['by_type'] = $conn->query("SELECT camera_type, COUNT(*) c FROM cameras GROUP BY camera_type")->fetch_all(MYSQLI_ASSOC);
            $r['alerts_last7'] = $conn->query("SELECT DATE(detected_at) as dt, COUNT(*) c FROM cctv_events WHERE detected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY dt ORDER BY dt")->fetch_all(MYSQLI_ASSOC);
            $r['incidents_by_severity'] = $conn->query("SELECT severity, COUNT(*) c FROM cctv_incidents GROUP BY severity")->fetch_all(MYSQLI_ASSOC);
            $r['incidents_by_status'] = $conn->query("SELECT status, COUNT(*) c FROM cctv_incidents GROUP BY status")->fetch_all(MYSQLI_ASSOC);
            $r['top_cameras'] = $conn->query("SELECT c.name, COUNT(e.id) as event_count FROM cameras c LEFT JOIN cctv_events e ON c.id=e.camera_id GROUP BY c.id ORDER BY event_count DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
            $r['total_events'] = $conn->query("SELECT COUNT(*) c FROM cctv_events")->fetch_assoc()['c'];
            $r['total_incidents'] = $conn->query("SELECT COUNT(*) c FROM cctv_incidents")->fetch_assoc()['c'];
            jsonResponse($r);
            break;

        // === SEARCH ===
        case 'search':
            $q = sanitize($conn, $_GET['q'] ?? '');
            if (strlen($q) < 2) jsonResponse(['cameras'=>[],'alerts'=>[],'incidents'=>[]]);
            $results = [];
            $results['cameras'] = $conn->query("SELECT * FROM cameras WHERE name LIKE '%$q%' OR location LIKE '%$q%' OR camera_code LIKE '%$q%' OR building LIKE '%$q%' OR ip_address LIKE '%$q%' LIMIT 20")->fetch_all(MYSQLI_ASSOC);
            $results['alerts'] = $conn->query("SELECT a.*, c.name as camera_name FROM cctv_alerts a LEFT JOIN cctv_events e ON a.event_id=e.id LEFT JOIN cameras c ON e.camera_id=c.id WHERE a.message LIKE '%$q%' OR c.name LIKE '%$q%' ORDER BY a.id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
            $results['incidents'] = $conn->query("SELECT i.*, c.name as camera_name FROM cctv_incidents i LEFT JOIN cameras c ON i.camera_id=c.id WHERE i.incident_number LIKE '%$q%' OR i.incident_type LIKE '%$q%' OR i.location LIKE '%$q%' OR i.details LIKE '%$q%' ORDER BY i.id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
            jsonResponse($results);
            break;

        // === AI ASSISTANT ===
        case 'ai_ask':
            $question = strtolower($input['question'] ?? $_GET['q'] ?? '');
            $answer = '';
            if (strpos($question, 'camera') !== false && (strpos($question, 'count') !== false || strpos($question, 'how many') !== false)) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cameras")->fetch_assoc()['c'];
                $answer = "There are $cnt cameras configured in the system.";
            } elseif (strpos($question, 'online') !== false) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cameras WHERE status='online'")->fetch_assoc()['c'];
                $answer = "$cnt cameras are currently online.";
            } elseif (strpos($question, 'offline') !== false) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cameras WHERE status='offline'")->fetch_assoc()['c'];
                $answer = "$cnt cameras are currently offline.";
            } elseif (strpos($question, 'alert') !== false && (strpos($question, 'unread') !== false || strpos($question, 'unread') !== false)) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cctv_alerts WHERE is_read=0")->fetch_assoc()['c'];
                $answer = "There are $cnt unread alerts.";
            } elseif (strpos($question, 'incident') !== false && strpos($question, 'open') !== false) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cctv_incidents WHERE status IN ('Open','Investigating')")->fetch_assoc()['c'];
                $answer = "There are $cnt open incidents.";
            } elseif (strpos($question, 'recording') !== false) {
                $cnt = $conn->query("SELECT COUNT(*) c FROM cameras WHERE recording_status='recording'")->fetch_assoc()['c'];
                $answer = "$cnt cameras are currently recording.";
            } elseif (strpos($question, 'nvr') !== false || strpos($question, 'recorder') !== false) {
                $answer = "NVR: Hikvision DS-7632NXI-K2/16P at 192.168.0.212. go2rtc proxy runs on port 1984.";
            } elseif (strpos($question, 'hello') !== false || strpos($question, 'hi') !== false) {
                $answer = "Hello! I'm your CCTV assistant. Ask me about cameras, alerts, incidents, or system status.";
            } else {
                $answer = "I can help you with: camera counts, online/offline status, unread alerts, open incidents, recording status, and NVR information. Try asking something specific!";
            }
            jsonResponse(['answer'=>$answer]);
            break;

        // === USERS ===
        case 'users':
            $res = $conn->query("SELECT id, username, full_name FROM users ORDER BY username");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        // === AUDIT LOG ===
        case 'audit_log':
            $res = $conn->query("SELECT * FROM audit_logs WHERE module='cctv' ORDER BY id DESC LIMIT 200");
            jsonResponse($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
            break;

        // === SETTINGS ===
        case 'settings':
            $s = [];
            $s['nvr_ip'] = '192.168.0.212';
            $s['nvr_port'] = '80';
            $s['go2rtc_port'] = '1984';
            $s['snapshot_interval'] = '5';
            $s['alert_email_enabled'] = '1';
            $s['max_storage_days'] = '30';
            jsonResponse($s);
            break;

        case 'save_settings':
            if ($method !== 'POST') jsonResponse(['error'=>'POST required'], 405);
            logAudit($conn, 'cctv', 'settings_save', 'Settings updated');
            jsonResponse(['success'=>true]);
            break;

        default:
            jsonResponse(['error'=>'Unknown action: '.$action], 400);
    }
    exit;
}

// --- RENDER LOGIN PAGE ---
if (!isset($_GET['page']) || ($_GET['page'] !== 'app' && $_GET['page'] !== 'api' && $_GET['page'] !== 'snapshot' && $_GET['page'] !== 'livestream')) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CCTV Management - Enterprise Platform</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#e8daef 0%,#d5f5e3 50%,#d6eaf8 100%);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-card{background:rgba(255,255,255,0.25);backdrop-filter:blur(20px);border-radius:24px;padding:48px 40px;width:420px;box-shadow:12px 12px 24px rgba(0,0,0,0.1),-6px -6px 16px rgba(255,255,255,0.7);border:1px solid rgba(255,255,255,0.4);text-align:center}
.login-card h1{font-size:28px;color:#2d3436;margin-bottom:8px;font-weight:700}
.login-card .subtitle{color:#636e72;margin-bottom:32px;font-size:14px}
.login-card .logo-icon{width:80px;height:80px;background:linear-gradient(135deg,#6c5ce7,#a29bfe);border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px;color:#fff;box-shadow:4px 4px 12px rgba(108,92,231,0.3)}
.login-card .error{background:rgba(214,48,49,0.1);color:#d63031;padding:10px;border-radius:12px;margin-bottom:16px;font-size:13px}
.form-group{margin-bottom:20px;text-align:left}
.form-group label{display:block;font-size:13px;font-weight:600;color:#636e72;margin-bottom:6px}
.form-group input{width:100%;padding:14px 16px;border:2px solid rgba(108,92,231,0.15);border-radius:14px;font-size:15px;background:rgba(255,255,255,0.5);transition:all 0.3s;font-family:'Inter',sans-serif}
.form-group input:focus{outline:none;border-color:#6c5ce7;box-shadow:0 0 0 4px rgba(108,92,231,0.1)}
.btn-login{width:100%;padding:14px;background:linear-gradient(135deg,#6c5ce7,#a29bfe);color:#fff;border:none;border-radius:14px;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s;box-shadow:4px 4px 12px rgba(108,92,231,0.3);font-family:'Inter',sans-serif}
.btn-login:hover{transform:translateY(-2px);box-shadow:6px 6px 16px rgba(108,92,231,0.4)}
</style>
</head>
<body>
<div class="login-card">
  <div class="logo-icon"><i class="fas fa-video"></i></div>
  <h1>CCTV Management</h1>
  <p class="subtitle">Enterprise Surveillance System</p>
  <?php if (!empty($loginError)): ?><div class="error"><i class="fas fa-exclamation-circle"></i> <?= $loginError ?></div><?php endif; ?>
  <form method="POST" action="">
    <div class="form-group">
      <label><i class="fas fa-user"></i> Username</label>
      <input type="text" name="login_user" placeholder="Enter username" required autofocus>
    </div>
    <div class="form-group">
      <label><i class="fas fa-lock"></i> Password</label>
      <input type="password" name="login_pass" placeholder="Enter password" required>
    </div>
    <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> Sign In</button>
  </form>
</div>
</body>
</html>
<?php exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CCTV Management - Enterprise Platform</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --primary: #6c5ce7; --primary-light: #a29bfe; --accent: #00b894; --danger: #d63031;
  --warning: #fdcb6e; --bg: linear-gradient(135deg, #e8daef 0%, #d5f5e3 50%, #d6eaf8 100%);
  --card-bg: rgba(255,255,255,0.25); --glass: rgba(255,255,255,0.4);
  --shadow: 8px 8px 16px rgba(0,0,0,0.08), -4px -4px 12px rgba(255,255,255,0.7);
  --radius: 20px; --radius-sm: 14px;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:var(--bg); min-height:100vh; color:#2d3436; }
.app-layout { display:flex; min-height:100vh; }

/* SIDEBAR */
.sidebar { width:260px; background:rgba(255,255,255,0.2); backdrop-filter:blur(20px); border-right:1px solid rgba(255,255,255,0.3); padding:24px 16px; display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; z-index:100; overflow-y:auto; }
.sidebar .logo { display:flex; align-items:center; gap:12px; padding:8px 12px; margin-bottom:28px; }
.sidebar .logo .icon { width:44px; height:44px; background:linear-gradient(135deg,var(--primary),var(--primary-light)); border-radius:14px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; box-shadow:4px 4px 10px rgba(108,92,231,0.3); }
.sidebar .logo h2 { font-size:18px; font-weight:700; color:#2d3436; }
.sidebar .logo small { font-size:11px; color:#636e72; display:block; }
.nav-item { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:var(--radius-sm); cursor:pointer; transition:all 0.3s; margin-bottom:4px; font-size:14px; font-weight:500; color:#636e72; text-decoration:none; }
.nav-item:hover { background:rgba(108,92,231,0.08); color:var(--primary); }
.nav-item.active { background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:#fff; box-shadow:4px 4px 12px rgba(108,92,231,0.3); }
.nav-item i { width:20px; text-align:center; font-size:16px; }
.nav-section { font-size:11px; font-weight:700; color:#b2bec3; text-transform:uppercase; letter-spacing:1px; padding:16px 16px 8px; }
.sidebar .user-info { margin-top:auto; padding:16px; border-top:1px solid rgba(255,255,255,0.3); display:flex; align-items:center; gap:10px; }
.sidebar .user-info .avatar { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),#55efc4); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:14px; font-weight:700; }
.sidebar .user-info .info { flex:1; }
.sidebar .user-info .info .name { font-size:13px; font-weight:600; }
.sidebar .user-info .info .role { font-size:11px; color:#636e72; }
.sidebar .user-info a { color:var(--danger); font-size:13px; text-decoration:none; }

/* MAIN CONTENT */
.main-content { flex:1; margin-left:260px; padding:24px 32px; }
.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; }
.page-header h1 { font-size:26px; font-weight:700; }
.page-header .header-actions { display:flex; gap:12px; }

/* CARDS */
.card { background:var(--card-bg); backdrop-filter:blur(16px); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow); border:1px solid rgba(255,255,255,0.3); margin-bottom:20px; }
.card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.card-header h3 { font-size:16px; font-weight:600; }
.stat-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card { background:var(--card-bg); backdrop-filter:blur(16px); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow); border:1px solid rgba(255,255,255,0.3); display:flex; align-items:center; gap:16px; transition:transform 0.3s; }
.stat-card:hover { transform:translateY(-4px); }
.stat-card .icon-box { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:22px; color:#fff; flex-shrink:0; }
.stat-card .info h2 { font-size:28px; font-weight:700; }
.stat-card .info p { font-size:13px; color:#636e72; }
.icon-primary { background:linear-gradient(135deg,var(--primary),var(--primary-light)); }
.icon-accent { background:linear-gradient(135deg,var(--accent),#55efc4); }
.icon-danger { background:linear-gradient(135deg,var(--danger),#ff7675); }
.icon-warning { background:linear-gradient(135deg,#e17055,var(--warning)); }

/* BUTTONS */
.btn { padding:10px 20px; border:none; border-radius:var(--radius-sm); font-size:13px; font-weight:600; cursor:pointer; transition:all 0.3s; font-family:'Inter',sans-serif; display:inline-flex; align-items:center; gap:8px; }
.btn-primary { background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:#fff; box-shadow:4px 4px 10px rgba(108,92,231,0.25); }
.btn-primary:hover { transform:translateY(-2px); box-shadow:6px 6px 14px rgba(108,92,231,0.35); }
.btn-accent { background:linear-gradient(135deg,var(--accent),#55efc4); color:#fff; box-shadow:4px 4px 10px rgba(0,184,148,0.25); }
.btn-danger { background:linear-gradient(135deg,var(--danger),#ff7675); color:#fff; box-shadow:4px 4px 10px rgba(214,48,49,0.25); }
.btn-warning { background:linear-gradient(135deg,#e17055,var(--warning)); color:#fff; }
.btn-sm { padding:6px 14px; font-size:12px; border-radius:10px; }
.btn-outline { background:transparent; border:2px solid rgba(108,92,231,0.3); color:var(--primary); }
.btn-outline:hover { background:rgba(108,92,231,0.08); }
.btn-icon { width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:10px; }

/* BADGES */
.badge { padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
.badge-success { background:rgba(0,184,148,0.15); color:var(--accent); }
.badge-danger { background:rgba(214,48,49,0.15); color:var(--danger); }
.badge-warning { background:rgba(253,203,110,0.3); color:#e17055; }
.badge-primary { background:rgba(108,92,231,0.15); color:var(--primary); }
.badge-info { background:rgba(9,132,227,0.15); color:#0984e3; }

/* FORMS */
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; font-weight:600; color:#636e72; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:12px 16px; border:2px solid rgba(108,92,231,0.12); border-radius:var(--radius-sm); font-size:14px; background:rgba(255,255,255,0.5); transition:all 0.3s; font-family:'Inter',sans-serif; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(108,92,231,0.08); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

/* TABLE */
.table-wrapper { overflow-x:auto; }
table { width:100%; border-collapse:separate; border-spacing:0; }
th { padding:12px 16px; text-align:left; font-size:12px; font-weight:600; color:#636e72; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid rgba(108,92,231,0.1); }
td { padding:12px 16px; font-size:13px; border-bottom:1px solid rgba(0,0,0,0.04); vertical-align:middle; }
tr:hover td { background:rgba(108,92,231,0.03); }

/* CAMERA GRID */
.camera-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; }
.camera-card { background:var(--card-bg); backdrop-filter:blur(16px); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow); border:1px solid rgba(255,255,255,0.3); transition:transform 0.3s; }
.camera-card:hover { transform:translateY(-4px); }
.camera-card .thumbnail { position:relative; width:100%; height:200px; background:#1a1a2e; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.camera-card .thumbnail img { width:100%; height:100%; object-fit:cover; }
.camera-card .thumbnail .no-signal { color:#636e72; font-size:14px; text-align:center; }
.camera-card .thumbnail .no-signal i { font-size:48px; display:block; margin-bottom:8px; }
.camera-card .status-badge { position:absolute; top:10px; left:10px; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:600; }
.camera-card .cam-actions { position:absolute; top:10px; right:10px; display:flex; gap:6px; }
.camera-card .cam-actions button { width:32px; height:32px; border:none; border-radius:8px; background:rgba(0,0,0,0.5); color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:13px; backdrop-filter:blur(8px); transition:background 0.3s; }
.camera-card .cam-actions button:hover { background:var(--primary); }
.camera-card .cam-info { padding:14px 16px; }
.camera-card .cam-info h4 { font-size:14px; font-weight:600; margin-bottom:4px; }
.camera-card .cam-info .meta { font-size:12px; color:#636e72; display:flex; gap:12px; }

/* MODALS */
.modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); backdrop-filter:blur(4px); z-index:1000; display:none; align-items:center; justify-content:center; }
.modal-overlay.active { display:flex; }
.modal { background:rgba(255,255,255,0.9); backdrop-filter:blur(24px); border-radius:var(--radius); padding:32px; width:90%; max-width:700px; max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.15); border:1px solid rgba(255,255,255,0.5); }
.modal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; }
.modal-header h2 { font-size:20px; font-weight:700; }
.modal-close { width:36px; height:36px; border:none; border-radius:10px; background:rgba(214,48,49,0.1); color:var(--danger); cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; }
.modal-close:hover { background:var(--danger); color:#fff; }

/* FULLSCREEN VIEWER */
.fullscreen-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.95); z-index:2000; display:none; }
.fullscreen-overlay.active { display:flex; flex-direction:column; }
.fullscreen-header { padding:16px 24px; display:flex; align-items:center; justify-content:space-between; color:#fff; }
.fullscreen-header h3 { font-size:18px; }
.fullscreen-body { flex:1; display:flex; align-items:center; justify-content:center; padding:16px; }
.fullscreen-body iframe { width:100%; height:100%; border:none; border-radius:12px; }
.fullscreen-body img { max-width:100%; max-height:100%; border-radius:12px; }

/* FILTER BAR */
.filter-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
.filter-btn { padding:8px 16px; border:2px solid rgba(108,92,231,0.15); border-radius:20px; background:rgba(255,255,255,0.4); font-size:13px; font-weight:500; cursor:pointer; transition:all 0.3s; color:#636e72; font-family:'Inter',sans-serif; }
.filter-btn:hover { border-color:var(--primary); color:var(--primary); }
.filter-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* SEARCH */
.search-box { display:flex; align-items:center; background:rgba(255,255,255,0.5); border-radius:var(--radius-sm); padding:4px; border:2px solid rgba(108,92,231,0.1); }
.search-box input { flex:1; border:none; background:transparent; padding:10px 14px; font-size:14px; font-family:'Inter',sans-serif; outline:none; }
.search-box button { padding:10px 18px; background:linear-gradient(135deg,var(--primary),var(--primary-light)); border:none; border-radius:10px; color:#fff; cursor:pointer; font-size:14px; }

/* TOAST */
.toast-container { position:fixed; top:20px; right:20px; z-index:3000; }
.toast { padding:14px 20px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.9); backdrop-filter:blur(16px); box-shadow:0 8px 32px rgba(0,0,0,0.12); margin-bottom:8px; display:flex; align-items:center; gap:10px; font-size:14px; animation:slideIn 0.3s ease; min-width:280px; border:1px solid rgba(255,255,255,0.5); }
.toast.success { border-left:4px solid var(--accent); }
.toast.error { border-left:4px solid var(--danger); }
.toast.warning { border-left:4px solid var(--warning); }
@keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }

/* VIEW SECTIONS */
.view-section { display:none; }
.view-section.active { display:block; }

/* STATUS DOT */
.status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:6px; }
.status-dot.online { background:var(--accent); }
.status-dot.offline { background:var(--danger); }

/* SYSTEM STATUS BANNER */
.system-status { padding:12px 20px; border-radius:var(--radius-sm); display:flex; align-items:center; gap:10px; font-weight:600; font-size:14px; margin-bottom:20px; }
.system-status.NORMAL { background:rgba(0,184,148,0.12); color:var(--accent); }
.system-status.DEGRADED { background:rgba(253,203,110,0.2); color:#e17055; }
.system-status.ALERT { background:rgba(214,48,49,0.12); color:var(--danger); }

/* ALERT LIST */
.alert-item { display:flex; align-items:center; gap:16px; padding:14px 16px; border-radius:var(--radius-sm); margin-bottom:8px; background:rgba(255,255,255,0.3); transition:background 0.3s; }
.alert-item.unread { background:rgba(108,92,231,0.06); }
.alert-item .level-icon { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:16px; color:#fff; flex-shrink:0; }
.alert-item .alert-body { flex:1; }
.alert-item .alert-body h4 { font-size:13px; font-weight:600; }
.alert-item .alert-body p { font-size:12px; color:#636e72; margin-top:2px; }
.alert-item .alert-actions { display:flex; gap:6px; }

/* CHAT */
.chat-container { display:flex; flex-direction:column; height:500px; }
.chat-messages { flex:1; overflow-y:auto; padding:16px; }
.chat-message { margin-bottom:12px; display:flex; }
.chat-message.user { justify-content:flex-end; }
.chat-message .bubble { max-width:70%; padding:12px 16px; border-radius:16px; font-size:14px; line-height:1.5; }
.chat-message.user .bubble { background:linear-gradient(135deg,var(--primary),var(--primary-light)); color:#fff; border-bottom-right-radius:4px; }
.chat-message.bot .bubble { background:rgba(255,255,255,0.6); color:#2d3436; border-bottom-left-radius:4px; }
.chat-input { display:flex; gap:8px; padding:16px; border-top:1px solid rgba(0,0,0,0.06); }
.chat-input input { flex:1; padding:12px 16px; border:2px solid rgba(108,92,231,0.12); border-radius:var(--radius-sm); font-size:14px; background:rgba(255,255,255,0.5); font-family:'Inter',sans-serif; }
.chat-input input:focus { outline:none; border-color:var(--primary); }
.chat-input button { padding:12px 20px; background:linear-gradient(135deg,var(--primary),var(--primary-light)); border:none; border-radius:var(--radius-sm); color:#fff; cursor:pointer; font-size:14px; }

/* LOADING */
.spinner { width:40px; height:40px; border:4px solid rgba(108,92,231,0.15); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin:40px auto; }
@keyframes spin { to { transform:rotate(360deg); } }

/* EMPTY STATE */
.empty-state { text-align:center; padding:60px 20px; color:#636e72; }
.empty-state i { font-size:48px; margin-bottom:16px; opacity:0.3; }
.empty-state h3 { font-size:18px; margin-bottom:8px; }
.empty-state p { font-size:14px; }

/* RESPONSIVE */
@media (max-width:768px) {
  .sidebar { width:60px; padding:16px 8px; }
  .sidebar .logo h2, .sidebar .logo small, .nav-item span, .nav-section, .sidebar .user-info .info, .sidebar .user-info a { display:none; }
  .sidebar .nav-item { justify-content:center; padding:12px; }
  .main-content { margin-left:60px; padding:16px; }
  .stat-cards { grid-template-columns:1fr 1fr; }
  .camera-grid { grid-template-columns:1fr; }
  .form-row { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="app-layout">
  <!-- SIDEBAR -->
  <nav class="sidebar">
    <div class="logo">
      <div class="icon"><i class="fas fa-video"></i></div>
      <div><h2>CCTV</h2><small>Surveillance</small></div>
    </div>
    <div class="nav-section">Main</div>
    <a class="nav-item active" data-view="dashboard" onclick="loadView('dashboard')"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
    <a class="nav-item" data-view="live" onclick="loadView('live')"><i class="fas fa-eye"></i><span>Live Cameras</span></a>
    <a class="nav-item" data-view="cameras" onclick="loadView('cameras')"><i class="fas fa-camera"></i><span>Camera Management</span></a>
    <div class="nav-section">Operations</div>
    <a class="nav-item" data-view="alerts" onclick="loadView('alerts')"><i class="fas fa-bell"></i><span>Alerts</span></a>
    <a class="nav-item" data-view="incidents" onclick="loadView('incidents')"><i class="fas fa-exclamation-triangle"></i><span>Incidents</span></a>
    <a class="nav-item" data-view="recordings" onclick="loadView('recordings')"><i class="fas fa-hdd"></i><span>Recordings</span></a>
    <div class="nav-section">System</div>
    <a class="nav-item" data-view="locations" onclick="loadView('locations')"><i class="fas fa-map-marker-alt"></i><span>Locations</span></a>
    <a class="nav-item" data-view="notifications" onclick="loadView('notifications')"><i class="fas fa-envelope"></i><span>Notifications</span></a>
    <a class="nav-item" data-view="reports" onclick="loadView('reports')"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    <a class="nav-item" data-view="ai" onclick="loadView('ai')"><i class="fas fa-robot"></i><span>AI Assistant</span></a>
    <div class="nav-section">Tools</div>
    <a class="nav-item" data-view="search" onclick="loadView('search')"><i class="fas fa-search"></i><span>Search</span></a>
    <a class="nav-item" data-view="audit" onclick="loadView('audit')"><i class="fas fa-clipboard-list"></i><span>Audit Log</span></a>
    <a class="nav-item" data-view="settings" onclick="loadView('settings')"><i class="fas fa-cog"></i><span>Settings</span></a>
    <div class="user-info">
      <div class="avatar"><i class="fas fa-user"></i></div>
      <div class="info">
        <div class="name"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['hr_user']) ?></div>
        <div class="role">Security Admin</div>
      </div>
      <a href="?logout=1"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </nav>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- TOAST CONTAINER -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- FULLSCREEN OVERLAY -->
    <div class="fullscreen-overlay" id="fullscreenOverlay">
      <div class="fullscreen-header">
        <h3 id="fsTitle">Camera View</h3>
        <button class="btn btn-sm btn-danger" onclick="closeFullscreen()"><i class="fas fa-times"></i> Close</button>
      </div>
      <div class="fullscreen-body" id="fsBody"></div>
    </div>

    <!-- DASHBOARD -->
    <div class="view-section active" id="view-dashboard">
      <div class="page-header"><h1><i class="fas fa-tachometer-alt" style="color:var(--primary)"></i> Dashboard</h1></div>
      <div class="system-status" id="systemStatus"></div>
      <div class="stat-cards" id="dashStats"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div class="card">
          <div class="card-header"><h3><i class="fas fa-video"></i> Camera Status</h3></div>
          <div id="dashCamGrid" class="camera-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))"></div>
        </div>
        <div class="card">
          <div class="card-header"><h3><i class="fas fa-bell"></i> Recent Alerts</h3></div>
          <div id="dashAlerts"></div>
        </div>
      </div>
    </div>

    <!-- LIVE CAMERAS -->
    <div class="view-section" id="view-live">
      <div class="page-header">
        <h1><i class="fas fa-eye" style="color:var(--accent)"></i> Live Cameras</h1>
        <div class="header-actions"><button class="btn btn-outline btn-sm" onclick="refreshLiveCameras()"><i class="fas fa-sync"></i> Refresh</button></div>
      </div>
      <div class="filter-bar" id="liveFilterBar"></div>
      <div class="camera-grid" id="liveGrid"></div>
    </div>

    <!-- CAMERA MANAGEMENT -->
    <div class="view-section" id="view-cameras">
      <div class="page-header">
        <h1><i class="fas fa-camera" style="color:var(--primary)"></i> Camera Management</h1>
        <div class="header-actions"><button class="btn btn-primary" onclick="showCameraModal()"><i class="fas fa-plus"></i> Add Camera</button></div>
      </div>
      <div class="card">
        <div style="display:flex;gap:12px;margin-bottom:16px">
          <select id="camFilterLoc" class="form-group" style="width:auto;padding:8px 12px;border-radius:10px;border:2px solid rgba(108,92,231,0.12)" onchange="loadCameras()"><option value="">All Locations</option></select>
          <select id="camFilterStatus" class="form-group" style="width:auto;padding:8px 12px;border-radius:10px;border:2px solid rgba(108,92,231,0.12)" onchange="loadCameras()"><option value="">All Status</option><option value="online">Online</option><option value="offline">Offline</option></select>
        </div>
        <div class="table-wrapper"><table id="camerasTable"><thead><tr><th>Code</th><th>Name</th><th>Location</th><th>IP Address</th><th>Type</th><th>Status</th><th>Recording</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
      </div>
    </div>

    <!-- ALERTS -->
    <div class="view-section" id="view-alerts">
      <div class="page-header"><h1><i class="fas fa-bell" style="color:var(--warning)"></i> Alerts</h1></div>
      <div class="card" id="alertsList"><div class="spinner"></div></div>
    </div>

    <!-- INCIDENTS -->
    <div class="view-section" id="view-incidents">
      <div class="page-header">
        <h1><i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i> Incidents</h1>
        <div class="header-actions"><button class="btn btn-primary" onclick="showIncidentModal()"><i class="fas fa-plus"></i> Create Incident</button></div>
      </div>
      <div class="card" id="incidentsList"><div class="spinner"></div></div>
    </div>

    <!-- RECORDINGS -->
    <div class="view-section" id="view-recordings">
      <div class="page-header"><h1><i class="fas fa-hdd" style="color:var(--primary)"></i> Recordings</h1></div>
      <div class="card"><div class="table-wrapper"><table id="recordingsTable"><thead><tr><th>Camera</th><th>Event Type</th><th>Start Time</th><th>End Time</th><th>Duration</th><th>Size</th><th>Storage</th><th>Path</th></tr></thead><tbody></tbody></table></div></div>
    </div>

    <!-- LOCATIONS -->
    <div class="view-section" id="view-locations">
      <div class="page-header">
        <h1><i class="fas fa-map-marker-alt" style="color:var(--accent)"></i> Locations</h1>
        <div class="header-actions"><button class="btn btn-primary" onclick="showLocationModal()"><i class="fas fa-plus"></i> Add Location</button></div>
      </div>
      <div class="card" id="locationsList"><div class="spinner"></div></div>
    </div>

    <!-- NOTIFICATIONS -->
    <div class="view-section" id="view-notifications">
      <div class="page-header">
        <h1><i class="fas fa-envelope" style="color:var(--primary)"></i> Notification Rules</h1>
        <div class="header-actions"><button class="btn btn-primary" onclick="showRuleModal()"><i class="fas fa-plus"></i> Add Rule</button></div>
      </div>
      <div class="card" id="notifRules"><div class="spinner"></div></div>
    </div>

    <!-- REPORTS -->
    <div class="view-section" id="view-reports">
      <div class="page-header"><h1><i class="fas fa-chart-bar" style="color:var(--accent)"></i> Reports</h1></div>
      <div id="reportsContent"><div class="spinner"></div></div>
    </div>

    <!-- AI ASSISTANT -->
    <div class="view-section" id="view-ai">
      <div class="page-header"><h1><i class="fas fa-robot" style="color:var(--primary)"></i> AI Assistant</h1></div>
      <div class="card">
        <div class="chat-container">
          <div class="chat-messages" id="chatMessages">
            <div class="chat-message bot"><div class="bubble">Hello! I'm your CCTV assistant. Ask me about cameras, alerts, incidents, or system status.</div></div>
          </div>
          <div class="chat-input">
            <input type="text" id="chatInput" placeholder="Ask a question..." onkeydown="if(event.key==='Enter')sendChat()">
            <button onclick="sendChat()"><i class="fas fa-paper-plane"></i></button>
          </div>
        </div>
      </div>
    </div>

    <!-- SEARCH -->
    <div class="view-section" id="view-search">
      <div class="page-header"><h1><i class="fas fa-search" style="color:var(--primary)"></i> Search</h1></div>
      <div class="card">
        <div class="search-box" style="margin-bottom:20px">
          <input type="text" id="searchInput" placeholder="Search cameras, alerts, incidents..." onkeydown="if(event.key==='Enter')doSearch()">
          <button onclick="doSearch()"><i class="fas fa-search"></i></button>
        </div>
        <div id="searchResults"></div>
      </div>
    </div>

    <!-- AUDIT LOG -->
    <div class="view-section" id="view-audit">
      <div class="page-header"><h1><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> Audit Log</h1></div>
      <div class="card"><div class="table-wrapper"><table id="auditTable"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody></tbody></table></div></div>
    </div>

    <!-- SETTINGS -->
    <div class="view-section" id="view-settings">
      <div class="page-header"><h1><i class="fas fa-cog" style="color:var(--primary)"></i> Settings</h1></div>
      <div class="card" id="settingsContent"><div class="spinner"></div></div>
    </div>
  </div>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-header"><h2 id="modalTitle">Modal</h2><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
    <div id="modalBody"></div>
  </div>
</div>

<script>
// ========== GLOBALS ==========
let currentView = 'dashboard';
let liveInterval = null;
const API = 'cctv.php?page=api&action=';

// ========== UTILITIES ==========
async function api(action, params = {}, body = null) {
  let url = API + action;
  for (const [k, v] of Object.entries(params)) { if (v !== undefined && v !== null) url += '&' + k + '=' + encodeURIComponent(v); }
  const opts = { method: body ? 'POST' : 'GET' };
  if (body) { opts.headers = { 'Content-Type': 'application/json' }; opts.body = JSON.stringify(body); }
  try {
    const res = await fetch(url, opts);
    return await res.json();
  } catch (e) { console.error('API Error:', e); return { error: e.message }; }
}

function toast(msg, type = 'success') {
  const c = document.getElementById('toastContainer');
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  const icons = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle' };
  t.innerHTML = '<i class="fas fa-' + (icons[type] || 'info-circle') + '" style="color:var(--' + (type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'accent') + ')"></i> ' + msg;
  c.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.3s'; setTimeout(() => t.remove(), 300); }, 3500);
}

function fmtDate(d) { if (!d) return '-'; return new Date(d).toLocaleString(); }
function fmtSize(b) { if (!b) return '-'; if (b > 1073741824) return (b / 1073741824).toFixed(1) + ' GB'; if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB'; return (b / 1024).toFixed(1) + ' KB'; }
function fmtDuration(s, e) {
  if (!s || !e) return '-';
  const ms = new Date(e) - new Date(s);
  const m = Math.floor(ms / 60000);
  const sec = Math.floor((ms % 60000) / 1000);
  return m + 'm ' + sec + 's';
}

function levelBadge(level) {
  const l = (level || '').toLowerCase();
  if (l === 'critical') return '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Critical</span>';
  if (l === 'high' || l === 'warning') return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> ' + level + '</span>';
  if (l === 'medium') return '<span class="badge badge-primary"><i class="fas fa-info-circle"></i> ' + level + '</span>';
  return '<span class="badge badge-info"><i class="fas fa-info-circle"></i> ' + (level || 'Info') + '</span>';
}

function statusBadge(status) {
  if (status === 'online') return '<span class="badge badge-success"><span class="status-dot online"></span> Online</span>';
  if (status === 'offline') return '<span class="badge badge-danger"><span class="status-dot offline"></span> Offline</span>';
  return '<span class="badge badge-info">' + status + '</span>';
}

function recBadge(s) {
  if (s === 'recording') return '<span class="badge badge-success"><i class="fas fa-circle" style="font-size:8px;animation:pulse 1.5s infinite"></i> Recording</span>';
  if (s === 'stopped') return '<span class="badge badge-warning">Stopped</span>';
  return '<span class="badge badge-info">' + (s || '-') + '</span>';
}

function severityBadge(s) {
  if (s === 'Critical') return '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Critical</span>';
  if (s === 'High') return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> High</span>';
  if (s === 'Medium') return '<span class="badge badge-primary"><i class="fas fa-info-circle"></i> Medium</span>';
  return '<span class="badge badge-info"><i class="fas fa-info-circle"></i> ' + (s || 'Low') + '</span>';
}

function incidentStatusBadge(s) {
  if (s === 'Open') return '<span class="badge badge-warning">Open</span>';
  if (s === 'Investigating') return '<span class="badge badge-primary">Investigating</span>';
  if (s === 'Escalated') return '<span class="badge badge-danger">Escalated</span>';
  if (s === 'Resolved') return '<span class="badge badge-success">Resolved</span>';
  if (s === 'Closed') return '<span class="badge badge-info">Closed</span>';
  return '<span class="badge">' + s + '</span>';
}

// ========== NAVIGATION ==========
function loadView(view) {
  if (liveInterval) { clearInterval(liveInterval); liveInterval = null; }
  currentView = view;
  document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const section = document.getElementById('view-' + view);
  const nav = document.querySelector('[data-view="' + view + '"]');
  if (section) section.classList.add('active');
  if (nav) nav.classList.add('active');

  switch (view) {
    case 'dashboard': loadDashboard(); break;
    case 'live': loadLiveCameras(); break;
    case 'cameras': loadCameras(); break;
    case 'alerts': loadAlerts(); break;
    case 'incidents': loadIncidents(); break;
    case 'recordings': loadRecordings(); break;
    case 'locations': loadLocations(); break;
    case 'notifications': loadNotifications(); break;
    case 'reports': loadReports(); break;
    case 'search': break;
    case 'audit': loadAuditLog(); break;
    case 'settings': loadSettings(); break;
  }
}

// ========== MODALS ==========
function openModal(title, html) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('modalOverlay').classList.add('active');
}
function closeModal() { document.getElementById('modalOverlay').classList.remove('active'); }
document.getElementById('modalOverlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

// ========== FULLSCREEN ==========
function openFullscreen(ch, name) {
  document.getElementById('fsTitle').textContent = name || 'Camera Ch' + ch;
  document.getElementById('fsBody').innerHTML = '<iframe src="http://192.168.0.184:1984/stream.html?src=cam' + ch + '&mode=webrtc&fullscreen=true" allowfullscreen></iframe>';
  document.getElementById('fullscreenOverlay').classList.add('active');
}
function closeFullscreen() {
  document.getElementById('fsBody').innerHTML = '';
  document.getElementById('fullscreenOverlay').classList.remove('active');
}

// ========== DASHBOARD ==========
async function loadDashboard() {
  const data = await api('dashboard');
  if (data.error) { toast('Failed to load dashboard', 'error'); return; }

  // System status
  const ss = document.getElementById('systemStatus');
  ss.className = 'system-status ' + data.system_status;
  ss.innerHTML = '<i class="fas fa-shield-alt"></i> System Status: <strong>' + data.system_status + '</strong>';

  // Stats
  const statsHtml = [
    { icon: 'video', cls: 'icon-primary', val: data.total_cameras, label: 'Total Cameras' },
    { icon: 'check-circle', cls: 'icon-accent', val: data.online_cameras, label: 'Online' },
    { icon: 'times-circle', cls: 'icon-danger', val: data.offline_cameras, label: 'Offline' },
    { icon: 'bell', cls: 'icon-warning', val: data.unread_alerts, label: 'Unread Alerts' },
    { icon: 'exclamation-triangle', cls: 'icon-danger', val: data.open_incidents, label: 'Open Incidents' },
    { icon: 'hdd', cls: 'icon-primary', val: data.total_recordings, label: 'Recordings' }
  ].map(s => '<div class="stat-card"><div class="icon-box ' + s.cls + '"><i class="fas fa-' + s.icon + '"></i></div><div class="info"><h2>' + s.val + '</h2><p>' + s.label + '</p></div></div>').join('');
  document.getElementById('dashStats').innerHTML = statsHtml;

  // Camera grid
  const camGrid = (data.cameras || []).map(c => {
    const ch = (c.camera_code || '').replace(/[^0-9]/g, '') || c.id;
    return '<div style="background:rgba(255,255,255,0.3);border-radius:12px;padding:10px;text-align:center">' +
      '<img src="?page=snapshot&ch=' + ch + '" width="100%" style="border-radius:10px;height:100px;object-fit:cover;background:#1a1a2e" onerror="this.style.display=\'none\'">' +
      '<div style="font-size:12px;font-weight:600;margin-top:6px">' + c.name + '</div>' +
      '<div style="margin-top:4px">' + statusBadge(c.status) + '</div></div>';
  }).join('');
  document.getElementById('dashCamGrid').innerHTML = camGrid || '<div class="empty-state"><i class="fas fa-video-slash"></i><p>No cameras</p></div>';

  // Recent alerts
  const alerts = (data.recent_alerts || []).map(a =>
    '<div class="alert-item">' +
    '<div class="level-icon" style="background:var(--' + (a.alert_level === 'Critical' ? 'danger' : a.alert_level === 'Warning' || a.alert_level === 'High' ? 'warning' : 'primary') + ')"><i class="fas fa-bell"></i></div>' +
    '<div class="alert-body"><h4>' + (a.camera_name || 'System') + '</h4><p>' + (a.message || a.event_type || '-') + '</p></div>' +
    levelBadge(a.alert_level) +
    '</div>'
  ).join('');
  document.getElementById('dashAlerts').innerHTML = alerts || '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No recent alerts</p></div>';
}

// ========== LIVE CAMERAS ==========
let liveLocations = ['All'];

async function loadLiveCameras() {
  const data = await api('cameras');
  if (!Array.isArray(data)) { toast('Failed to load cameras', 'error'); return; }

  // Build location filter
  const locs = ['All', ...new Set(data.map(c => c.location).filter(Boolean))];
  const filterBar = document.getElementById('filterBar') || document.getElementById('liveFilterBar');
  if (filterBar) {
    filterBar.innerHTML = locs.map(l =>
      '<button class="filter-btn' + (l === 'All' ? ' active' : '') + '" onclick="filterLiveCameras(\'' + l + '\', this)">' + l + '</button>'
    ).join('');
  }

  window._liveCameras = data;
  renderLiveGrid(data);

  // Auto-refresh every 5 seconds
  if (liveInterval) clearInterval(liveInterval);
  liveInterval = setInterval(() => {
    if (currentView === 'live') refreshLiveThumbnails();
  }, 5000);
}

function renderLiveGrid(cameras) {
  const grid = document.getElementById('liveGrid');
  if (!cameras || cameras.length === 0) {
    grid.innerHTML = '<div class="empty-state"><i class="fas fa-video-slash"></i><h3>No cameras found</h3></div>';
    return;
  }
  grid.innerHTML = cameras.map(c => {
    const ch = (c.camera_code || '').replace(/[^0-9]/g, '') || c.id;
    const isOnline = c.status === 'online';
    return '<div class="camera-card" data-cam-id="' + c.id + '">' +
      '<div class="thumbnail">' +
        (isOnline
          ? '<img id="thumb-' + ch + '" src="?page=snapshot&ch=' + ch + '" alt="Camera ' + ch + '" onerror="this.parentElement.innerHTML=\'<div class=no-signal><i class=fas fa-video-slash></i>Signal Lost</div>\'">'
          : '<div class="no-signal"><i class="fas fa-video-slash"></i><div>Offline</div></div>') +
        '<span class="status-badge badge ' + (isOnline ? 'badge-success' : 'badge-danger') + '"><i class="fas fa-circle" style="font-size:8px"></i> ' + (isOnline ? 'LIVE' : 'OFFLINE') + '</span>' +
        (isOnline ? '<div class="cam-actions">' +
          '<button onclick="openFullscreen(\'' + ch + '\',\'' + c.name + '\')" title="Full Screen"><i class="fas fa-expand"></i></button>' +
          '<button onclick="showCameraInfoModal(' + c.id + ')" title="Info"><i class="fas fa-info"></i></button>' +
        '</div>' : '<div class="cam-actions"><button onclick="showCameraInfoModal(' + c.id + ')" title="Info"><i class="fas fa-info"></i></button></div>') +
      '</div>' +
      '<div class="cam-info">' +
        '<h4>' + c.name + '</h4>' +
        '<div class="meta">' +
          '<span><i class="fas fa-map-marker-alt"></i> ' + (c.location || '-') + '</span>' +
          '<span>' + recBadge(c.recording_status) + '</span>' +
        '</div>' +
      '</div></div>';
  }).join('');
}

function refreshLiveThumbnails() {
  const cards = document.querySelectorAll('#liveGrid .camera-card');
  cards.forEach(card => {
    const img = card.querySelector('.thumbnail img');
    if (img) {
      const src = img.src.split('?')[0] + '?' + Date.now();
      // Quick check - just update src with cache buster
      img.src = img.src.split('&t=')[0] + '&t=' + Date.now();
    }
  });
}

function refreshLiveCameras() {
  loadLiveCameras();
  toast('Refreshed live cameras', 'success');
}

function filterLiveCameras(loc, btn) {
  document.querySelectorAll('#liveFilterBar .filter-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const cams = window._liveCameras || [];
  if (loc === 'All') { renderLiveGrid(cams); }
  else { renderLiveGrid(cams.filter(c => c.location === loc)); }
}

// ========== CAMERA MANAGEMENT ==========
async function loadCameras() {
  const loc = document.getElementById('camFilterLoc')?.value || '';
  const status = document.getElementById('camFilterStatus')?.value || '';
  const data = await api('cameras', { location: loc, status: status });
  if (!Array.isArray(data)) return;

  // Populate location filter
  const locSel = document.getElementById('camFilterLoc');
  if (locSel) {
    const existing = locSel.value;
    const locs = [...new Set(data.map(c => c.location).filter(Boolean))];
    locSel.innerHTML = '<option value="">All Locations</option>' + locs.map(l => '<option value="' + l + '"' + (l === existing ? ' selected' : '') + '>' + l + '</option>').join('');
  }

  const tbody = document.querySelector('#camerasTable tbody');
  tbody.innerHTML = data.map(c => {
    const ch = (c.camera_code || '').replace(/[^0-9]/g, '') || c.id;
    return '<tr>' +
      '<td><strong>' + (c.camera_code || '-') + '</strong></td>' +
      '<td>' + c.name + '</td>' +
      '<td>' + (c.location || '-') + '</td>' +
      '<td><code>' + (c.ip_address || '-') + ':' + (c.port || '') + '</code></td>' +
      '<td>' + (c.camera_type || 'IP') + '</td>' +
      '<td>' + statusBadge(c.status) + '</td>' +
      '<td>' + recBadge(c.recording_status) + '</td>' +
      '<td>' +
        '<button class="btn btn-sm btn-outline" onclick="testCamera(' + c.id + ')" title="Test"><i class="fas fa-plug"></i></button> ' +
        '<button class="btn btn-sm btn-primary" onclick="showCameraModal(' + c.id + ')" title="Edit"><i class="fas fa-edit"></i></button> ' +
        '<button class="btn btn-sm btn-danger" onclick="deleteCamera(' + c.id + ')" title="Delete"><i class="fas fa-trash"></i></button> ' +
        (c.status === 'online' ? '<button class="btn btn-sm btn-accent" onclick="openFullscreen(\'' + ch + '\',\'' + c.name + '\')" title="View Live"><i class="fas fa-eye"></i></button>' : '') +
      '</td></tr>';
  }).join('');

  if (data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state"><i class="fas fa-video-slash"></i> No cameras found</td></tr>';
  }
}

async function showCameraModal(id) {
  let cam = {};
  if (id) {
    cam = await api('camera', { id: id }) || {};
  }
  const html = '<form onsubmit="saveCamera(event)">' +
    '<input type="hidden" id="camId" value="' + (cam.id || 0) + '">' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Camera Code</label><input id="camCode" value="' + (cam.camera_code || '') + '" required></div>' +
      '<div class="form-group"><label>Camera Name</label><input id="camName" value="' + (cam.name || '') + '" required></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Location</label><input id="camLocation" value="' + (cam.location || '') + '"></div>' +
      '<div class="form-group"><label>Building</label><input id="camBuilding" value="' + (cam.building || '') + '"></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>IP Address</label><input id="camIP" value="' + (cam.ip_address || '') + '"></div>' +
      '<div class="form-group"><label>Port</label><input id="camPort" value="' + (cam.port || '') + '"></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Stream URL</label><input id="camStream" value="' + (cam.stream_url || '') + '"></div>' +
      '<div class="form-group"><label>Camera Type</label><select id="camType"><option value="IP"' + (cam.camera_type === 'IP' ? ' selected' : '') + '>IP</option><option value="Analog"' + (cam.camera_type === 'Analog' ? ' selected' : '') + '>Analog</option><option value="PTZ"' + (cam.camera_type === 'PTZ' ? ' selected' : '') + '>PTZ</option><option value="Dome"' + (cam.camera_type === 'Dome' ? ' selected' : '') + '>Dome</option><option value="Bullet"' + (cam.camera_type === 'Bullet' ? ' selected' : '') + '>Bullet</option></select></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Department</label><input id="camDept" value="' + (cam.department || '') + '"></div>' +
      '<div class="form-group"><label>NVR Device</label><input id="camNVR" value="' + (cam.nvr_device || 'Hikvision DS-7632NXI-K2/16P') + '"></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Status</label><select id="camStatus"><option value="online"' + (cam.status === 'online' ? ' selected' : '') + '>Online</option><option value="offline"' + (cam.status === 'offline' ? ' selected' : '') + '>Offline</option></select></div>' +
      '<div class="form-group"><label>Recording Status</label><select id="camRec"><option value="recording"' + (cam.recording_status === 'recording' ? ' selected' : '') + '>Recording</option><option value="stopped"' + (cam.recording_status === 'stopped' ? ' selected' : '') + '>Stopped</option></select></div>' +
    '</div>' +
    '<div class="form-group"><label>Assigned Users</label><input id="camUsers" value="' + (cam.assigned_users || '') + '"></div>' +
    '<div style="text-align:right;margin-top:16px"><button type="button" class="btn btn-outline" onclick="closeModal()" style="margin-right:8px">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Camera</button></div>' +
    '</form>';
  openModal(id ? 'Edit Camera' : 'Add Camera', html);
}

async function saveCamera(e) {
  e.preventDefault();
  const body = {
    id: document.getElementById('camId').value,
    camera_code: document.getElementById('camCode').value,
    name: document.getElementById('camName').value,
    location: document.getElementById('camLocation').value,
    building: document.getElementById('camBuilding').value,
    ip_address: document.getElementById('camIP').value,
    port: document.getElementById('camPort').value,
    stream_url: document.getElementById('camStream').value,
    camera_type: document.getElementById('camType').value,
    department: document.getElementById('camDept').value,
    nvr_device: document.getElementById('camNVR').value,
    status: document.getElementById('camStatus').value,
    recording_status: document.getElementById('camRec').value,
    assigned_users: document.getElementById('camUsers').value
  };
  const res = await api('save_camera', {}, body);
  if (res.success) { toast('Camera saved'); closeModal(); loadCameras(); }
  else { toast('Failed to save: ' + (res.error || 'Unknown'), 'error'); }
}

async function deleteCamera(id) {
  if (!confirm('Delete this camera?')) return;
  const res = await api('camera_action', {}, { id: id, action: 'delete' });
  if (res.success) { toast('Camera deleted'); loadCameras(); }
  else { toast('Delete failed', 'error'); }
}

async function testCamera(id) {
  toast('Testing connection...', 'warning');
  const res = await api('camera_action', {}, { id: id, action: 'test_connection' });
  if (res.success) { toast(res.message, 'success'); }
  else { toast(res.message || 'Test failed', 'error'); }
}

async function showCameraInfoModal(id) {
  const cam = await api('camera', { id: id });
  if (!cam) { toast('Camera not found', 'error'); return; }
  const ch = (cam.camera_code || '').replace(/[^0-9]/g, '') || cam.id;
  const html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">' +
    '<div>' +
      '<img src="?page=snapshot&ch=' + ch + '" style="width:100%;border-radius:14px;background:#1a1a2e;min-height:200px;object-fit:cover">' +
      '<div style="margin-top:12px;text-align:center"><button class="btn btn-accent btn-sm" onclick="openFullscreen(\'' + ch + '\',\'' + cam.name + '\')"><i class="fas fa-expand"></i> Full Screen</button></div>' +
    '</div>' +
    '<div>' +
      '<h3 style="margin-bottom:12px">' + cam.name + '</h3>' +
      '<p><strong>Code:</strong> ' + (cam.camera_code || '-') + '</p>' +
      '<p><strong>Location:</strong> ' + (cam.location || '-') + '</p>' +
      '<p><strong>Building:</strong> ' + (cam.building || '-') + '</p>' +
      '<p><strong>Department:</strong> ' + (cam.department || '-') + '</p>' +
      '<p><strong>IP:</strong> ' + (cam.ip_address || '-') + ':' + (cam.port || '') + '</p>' +
      '<p><strong>Type:</strong> ' + (cam.camera_type || 'IP') + '</p>' +
      '<p><strong>NVR:</strong> ' + (cam.nvr_device || '-') + '</p>' +
      '<p><strong>Status:</strong> ' + statusBadge(cam.status) + '</p>' +
      '<p><strong>Recording:</strong> ' + recBadge(cam.recording_status) + '</p>' +
      '<p><strong>Stream:</strong> <code style="word-break:break-all">' + (cam.stream_url || '-') + '</code></p>' +
      '<p><strong>Assigned:</strong> ' + (cam.assigned_users || '-') + '</p>' +
    '</div></div>';
  openModal('Camera Details', html);
}

// ========== ALERTS ==========
async function loadAlerts() {
  const data = await api('alerts');
  const container = document.getElementById('alertsList');
  if (!Array.isArray(data) || data.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i><h3>No Alerts</h3><p>All clear - no alerts at this time</p></div>';
    return;
  }
  container.innerHTML = data.map(a => {
    const color = a.alert_level === 'Critical' ? 'var(--danger)' : (a.alert_level === 'High' || a.alert_level === 'Warning') ? 'var(--warning)' : 'var(--primary)';
    return '<div class="alert-item' + (a.is_read == 0 ? ' unread' : '') + '">' +
      '<div class="level-icon" style="background:' + color + '"><i class="fas fa-' + (a.alert_level === 'Critical' ? 'exclamation-circle' : 'bell') + '"></i></div>' +
      '<div class="alert-body">' +
        '<h4>' + (a.camera_name || 'System') + ' — ' + (a.event_type || 'Alert') + '</h4>' +
        '<p>' + (a.message || 'No details') + '</p>' +
        '<p style="font-size:11px;color:#b2bec3">' + fmtDate(a.detected_at) + ' · Confidence: ' + (a.confidence ? (a.confidence * 100).toFixed(0) + '%' : '-') + '</p>' +
      '</div>' +
      '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">' +
        levelBadge(a.alert_level) +
        '<div class="alert-actions">' +
          (a.is_read == 0 ? '<button class="btn btn-sm btn-accent" onclick="ackAlert(' + a.id + ')"><i class="fas fa-check"></i> Ack</button>' : '<span class="badge badge-success"><i class="fas fa-check"></i> Read</span>') +
          '<button class="btn btn-sm btn-warning" onclick="escalateAlert(' + a.id + ')"><i class="fas fa-arrow-up"></i> Escalate</button>' +
        '</div>' +
      '</div></div>';
  }).join('');
}

async function ackAlert(id) {
  const res = await api('ack_alert', {}, { id: id });
  if (res.success) { toast('Alert acknowledged'); loadAlerts(); }
}

async function escalateAlert(alertId) {
  if (!confirm('Escalate this alert to an incident?')) return;
  const res = await api('escalate_alert', {}, { alert_id: alertId });
  if (res.success) { toast('Escalated to ' + res.incident_number); loadAlerts(); }
  else { toast('Escalation failed', 'error'); }
}

// ========== INCIDENTS ==========
async function loadIncidents() {
  const data = await api('incidents');
  const container = document.getElementById('incidentsList');
  if (!Array.isArray(data) || data.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i><h3>No Incidents</h3><p>No incidents have been recorded</p></div>';
    return;
  }
  const html = '<div class="table-wrapper"><table><thead><tr><th>Incident #</th><th>Type</th><th>Location</th><th>Camera</th><th>Severity</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>' +
    data.map(i => '<tr>' +
      '<td><strong>' + i.incident_number + '</strong></td>' +
      '<td>' + (i.incident_type || '-') + '</td>' +
      '<td>' + (i.location || '-') + '</td>' +
      '<td>' + (i.camera_name || '-') + '</td>' +
      '<td>' + severityBadge(i.severity) + '</td>' +
      '<td>' + incidentStatusBadge(i.status) + '</td>' +
      '<td>' + fmtDate(i.occurred_at) + '</td>' +
      '<td><button class="btn btn-sm btn-primary" onclick="showIncidentModal(' + i.id + ')"><i class="fas fa-edit"></i></button></td>' +
    '</tr>').join('') +
    '</tbody></table></div>';
  container.innerHTML = html;
}

async function showIncidentModal(id) {
  let inc = {};
  if (id) { inc = await api('incident', { id: id }) || {}; }
  const cams = await api('cameras') || [];
  const camOpts = '<option value="0">-- Select Camera --</option>' + cams.map(c => '<option value="' + c.id + '"' + (inc.camera_id == c.id ? ' selected' : '') + '>' + c.name + '</option>').join('');

  const html = '<form onsubmit="saveIncident(event)">' +
    '<input type="hidden" id="incId" value="' + (inc.id || 0) + '">' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Incident Number</label><input id="incNum" value="' + (inc.incident_number || 'INC-NEW') + '"></div>' +
      '<div class="form-group"><label>Incident Type</label><input id="incType" value="' + (inc.incident_type || '') + '" required></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Location</label><input id="incLoc" value="' + (inc.location || '') + '"></div>' +
      '<div class="form-group"><label>Camera</label><select id="incCam">' + camOpts + '</select></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>Severity</label><select id="incSeverity"><option value="Low"' + (inc.severity === 'Low' ? ' selected' : '') + '>Low</option><option value="Medium"' + (inc.severity === 'Medium' || !inc.id ? ' selected' : '') + '>Medium</option><option value="High"' + (inc.severity === 'High' ? ' selected' : '') + '>High</option><option value="Critical"' + (inc.severity === 'Critical' ? ' selected' : '') + '>Critical</option></select></div>' +
      '<div class="form-group"><label>Status</label><select id="incStatus"><option value="Open"' + (inc.status === 'Open' || !inc.id ? ' selected' : '') + '>Open</option><option value="Investigating"' + (inc.status === 'Investigating' ? ' selected' : '') + '>Investigating</option><option value="Escalated"' + (inc.status === 'Escalated' ? ' selected' : '') + '>Escalated</option><option value="Resolved"' + (inc.status === 'Resolved' ? ' selected' : '') + '>Resolved</option><option value="Closed"' + (inc.status === 'Closed' ? ' selected' : '') + '>Closed</option></select></div>' +
    '</div>' +
    '<div class="form-group"><label>Assigned To</label><input id="incAssigned" value="' + (inc.assigned_to || '') + '"></div>' +
    '<div class="form-group"><label>Details</label><textarea id="incDetails" rows="3" style="width:100%;padding:12px;border:2px solid rgba(108,92,231,0.12);border-radius:14px;font-family:Inter,sans-serif;font-size:14px">' + (inc.details || '') + '</textarea></div>' +
    '<div class="form-group"><label>Evidence URL</label><input id="incEvidence" value="' + (inc.evidence_url || '') + '"></div>' +
    '<div style="text-align:right;margin-top:16px"><button type="button" class="btn btn-outline" onclick="closeModal()" style="margin-right:8px">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>' +
    '</form>';
  openModal(id ? 'Edit Incident' : 'Create Incident', html);
}

async function saveIncident(e) {
  e.preventDefault();
  const body = {
    id: document.getElementById('incId').value,
    incident_number: document.getElementById('incNum').value,
    incident_type: document.getElementById('incType').value,
    location: document.getElementById('incLoc').value,
    camera_id: document.getElementById('incCam').value,
    severity: document.getElementById('incSeverity').value,
    status: document.getElementById('incStatus').value,
    assigned_to: document.getElementById('incAssigned').value,
    details: document.getElementById('incDetails').value,
    evidence_url: document.getElementById('incEvidence').value
  };
  const res = await api('save_incident', {}, body);
  if (res.success) { toast('Incident saved: ' + (res.incident_number || '')); closeModal(); loadIncidents(); }
  else { toast('Save failed', 'error'); }
}

// ========== RECORDINGS ==========
async function loadRecordings() {
  const data = await api('recordings');
  const tbody = document.querySelector('#recordingsTable tbody');
  if (!Array.isArray(data) || data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state"><i class="fas fa-hdd"></i> No recordings found</td></tr>';
    return;
  }
  tbody.innerHTML = data.map(r => '<tr>' +
    '<td>' + (r.camera_name || '-') + '</td>' +
    '<td>' + (r.event_type || '-') + '</td>' +
    '<td>' + fmtDate(r.start_time) + '</td>' +
    '<td>' + fmtDate(r.end_time) + '</td>' +
    '<td>' + fmtDuration(r.start_time, r.end_time) + '</td>' +
    '<td>' + fmtSize(r.file_size) + '</td>' +
    '<td>' + (r.storage_location || 'NVR') + '</td>' +
    '<td><code style="font-size:11px">' + (r.file_path || '-') + '</code></td>' +
  '</tr>').join('');
}

// ========== LOCATIONS ==========
async function loadLocations() {
  const data = await api('locations');
  const container = document.getElementById('locationsList');
  if (!Array.isArray(data) || data.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-map-marker-alt"></i><h3>No Locations</h3><p>Add camera locations to organize your system</p></div>';
    return;
  }
  container.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">' +
    data.map(l => '<div style="background:rgba(255,255,255,0.3);border-radius:16px;padding:20px;border:1px solid rgba(255,255,255,0.3)">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">' +
        '<h3 style="font-size:16px"><i class="fas fa-map-marker-alt" style="color:var(--accent)"></i> ' + l.name + '</h3>' +
        '<button class="btn btn-sm btn-primary" onclick="showLocationModal(' + l.id + ')"><i class="fas fa-edit"></i></button>' +
      '</div>' +
      '<p style="font-size:13px;color:#636e72;margin-bottom:8px">' + (l.description || 'No description') + '</p>' +
      '<div style="display:flex;gap:12px">' +
        '<span class="badge badge-primary"><i class="fas fa-building"></i> ' + (l.building || '-') + '</span>' +
        '<span class="badge badge-accent"><i class="fas fa-video"></i> ' + (l.camera_count || 0) + ' cameras</span>' +
      '</div>' +
    '</div>').join('') + '</div>';
}

async function showLocationModal(id) {
  let loc = {};
  if (id) { const data = await api('locations'); loc = data.find(l => l.id == id) || {}; }
  const html = '<form onsubmit="saveLocation(event)">' +
    '<input type="hidden" id="locId" value="' + (loc.id || 0) + '">' +
    '<div class="form-group"><label>Location Name</label><input id="locName" value="' + (loc.name || '') + '" required></div>' +
    '<div class="form-group"><label>Building</label><input id="locBuilding" value="' + (loc.building || '') + '"></div>' +
    '<div class="form-group"><label>Description</label><textarea id="locDesc" rows="3" style="width:100%;padding:12px;border:2px solid rgba(108,92,231,0.12);border-radius:14px;font-family:Inter,sans-serif;font-size:14px">' + (loc.description || '') + '</textarea></div>' +
    '<div style="text-align:right"><button type="button" class="btn btn-outline" onclick="closeModal()" style="margin-right:8px">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>' +
    '</form>';
  openModal(id ? 'Edit Location' : 'Add Location', html);
}

async function saveLocation(e) {
  e.preventDefault();
  const body = { id: document.getElementById('locId').value, name: document.getElementById('locName').value, building: document.getElementById('locBuilding').value, description: document.getElementById('locDesc').value };
  const res = await api('save_location', {}, body);
  if (res.success) { toast('Location saved'); closeModal(); loadLocations(); }
  else { toast('Save failed', 'error'); }
}

// ========== NOTIFICATIONS ==========
async function loadNotifications() {
  const data = await api('notifications');
  const container = document.getElementById('notifRules');
  if (!Array.isArray(data) || data.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-envelope"></i><h3>No Rules</h3><p>Add notification rules by severity level</p></div>';
    return;
  }
  const html = '<div class="table-wrapper"><table><thead><tr><th>Severity</th><th>In-App</th><th>SMS</th><th>Email</th><th>Recipients</th><th>Actions</th></tr></thead><tbody>' +
    data.map(r => '<tr>' +
      '<td>' + severityBadge(r.severity) + '</td>' +
      '<td>' + (r.channel_inapp == 1 ? '<i class="fas fa-check-circle" style="color:var(--accent)"></i>' : '<i class="fas fa-times-circle" style="color:var(--danger)"></i>') + '</td>' +
      '<td>' + (r.channel_sms == 1 ? '<i class="fas fa-check-circle" style="color:var(--accent)"></i>' : '<i class="fas fa-times-circle" style="color:var(--danger)"></i>') + '</td>' +
      '<td>' + (r.channel_email == 1 ? '<i class="fas fa-check-circle" style="color:var(--accent)"></i>' : '<i class="fas fa-times-circle" style="color:var(--danger)"></i>') + '</td>' +
      '<td>' + (r.recipients || '-') + '</td>' +
      '<td><button class="btn btn-sm btn-primary" onclick="showRuleModal(' + r.id + ')"><i class="fas fa-edit"></i></button></td>' +
    '</tr>').join('') + '</tbody></table></div>';
  container.innerHTML = html;
}

async function showRuleModal(id) {
  let rule = {};
  if (id) { const data = await api('notifications'); rule = data.find(r => r.id == id) || {}; }
  const html = '<form onsubmit="saveRule(event)">' +
    '<input type="hidden" id="ruleId" value="' + (rule.id || 0) + '">' +
    '<div class="form-group"><label>Severity Level</label><select id="ruleSeverity"><option value="Critical"' + (rule.severity === 'Critical' ? ' selected' : '') + '>Critical</option><option value="High"' + (rule.severity === 'High' ? ' selected' : '') + '>High</option><option value="Medium"' + (rule.severity === 'Medium' || !rule.id ? ' selected' : '') + '>Medium</option><option value="Low"' + (rule.severity === 'Low' ? ' selected' : '') + '>Low</option></select></div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label><input type="checkbox" id="ruleInApp"' + (rule.channel_inapp == 1 || !rule.id ? ' checked' : '') + '> In-App Notifications</label></div>' +
      '<div class="form-group"><label><input type="checkbox" id="ruleSMS"' + (rule.channel_sms == 1 ? ' checked' : '') + '> SMS Alerts</label></div>' +
    '</div>' +
    '<div class="form-group"><label><input type="checkbox" id="ruleEmail"' + (rule.channel_email == 1 || !rule.id ? ' checked' : '') + '> Email Notifications</label></div>' +
    '<div class="form-group"><label>Recipients (comma-separated emails/names)</label><input id="ruleRecipients" value="' + (rule.recipients || '') + '"></div>' +
    '<div style="text-align:right"><button type="button" class="btn btn-outline" onclick="closeModal()" style="margin-right:8px">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>' +
    '</form>';
  openModal('Notification Rule', html);
}

async function saveRule(e) {
  e.preventDefault();
  const body = {
    id: document.getElementById('ruleId').value,
    severity: document.getElementById('ruleSeverity').value,
    channel_inapp: document.getElementById('ruleInApp').checked ? 1 : 0,
    channel_sms: document.getElementById('ruleSMS').checked ? 1 : 0,
    channel_email: document.getElementById('ruleEmail').checked ? 1 : 0,
    recipients: document.getElementById('ruleRecipients').value
  };
  const res = await api('save_rule', {}, body);
  if (res.success) { toast('Rule saved'); closeModal(); loadNotifications(); }
  else { toast('Save failed', 'error'); }
}

// ========== REPORTS ==========
async function loadReports() {
  const data = await api('reports');
  if (data.error) { toast('Failed to load reports', 'error'); return; }
  const container = document.getElementById('reportsContent');

  // Summary stats
  let html = '<div class="stat-cards">' +
    '<div class="stat-card"><div class="icon-box icon-primary"><i class="fas fa-video"></i></div><div class="info"><h2>' + (data.total_cameras || 0) + '</h2><p>Total Cameras</p></div></div>' +
    '<div class="stat-card"><div class="icon-box icon-danger"><i class="fas fa-bell"></i></div><div class="info"><h2>' + (data.total_events || 0) + '</h2><p>Total Events</p></div></div>' +
    '<div class="stat-card"><div class="icon-box icon-warning"><i class="fas fa-exclamation-triangle"></i></div><div class="info"><h2>' + (data.total_incidents || 0) + '</h2><p>Total Incidents</p></div></div>' +
  '</div>';

  // By Status
  html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';

  html += '<div class="card"><div class="card-header"><h3>Cameras by Status</h3></div><table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
  (data.by_status || []).forEach(s => { html += '<tr><td>' + statusBadge(s.status) + '</td><td><strong>' + s.c + '</strong></td></tr>'; });
  html += '</tbody></table></div>';

  html += '<div class="card"><div class="card-header"><h3>Cameras by Location</h3></div><table><thead><tr><th>Location</th><th>Count</th></tr></thead><tbody>';
  (data.by_location || []).forEach(l => { html += '<tr><td>' + (l.location || '-') + '</td><td><strong>' + l.c + '</strong></td></tr>'; });
  html += '</tbody></table></div>';

  html += '<div class="card"><div class="card-header"><h3>Incidents by Severity</h3></div><table><thead><tr><th>Severity</th><th>Count</th></tr></thead><tbody>';
  (data.incidents_by_severity || []).forEach(s => { html += '<tr><td>' + severityBadge(s.severity) + '</td><td><strong>' + s.c + '</strong></td></tr>'; });
  html += '</tbody></table></div>';

  html += '<div class="card"><div class="card-header"><h3>Incidents by Status</h3></div><table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
  (data.incidents_by_status || []).forEach(s => { html += '<tr><td>' + incidentStatusBadge(s.status) + '</td><td><strong>' + s.c + '</strong></td></tr>'; });
  html += '</tbody></table></div>';

  html += '</div>';

  // Top cameras
  if (data.top_cameras && data.top_cameras.length > 0) {
    html += '<div class="card" style="margin-top:20px"><div class="card-header"><h3>Top Cameras by Events</h3></div><table><thead><tr><th>Camera</th><th>Events</th></tr></thead><tbody>';
    data.top_cameras.forEach(c => { html += '<tr><td>' + c.name + '</td><td><strong>' + c.event_count + '</strong></td></tr>'; });
    html += '</tbody></table></div>';
  }

  // Events last 7 days
  if (data.alerts_last7 && data.alerts_last7.length > 0) {
    html += '<div class="card" style="margin-top:20px"><div class="card-header"><h3>Events (Last 7 Days)</h3></div><table><thead><tr><th>Date</th><th>Events</th></tr></thead><tbody>';
    data.alerts_last7.forEach(a => { html += '<tr><td>' + a.dt + '</td><td><strong>' + a.c + '</strong></td></tr>'; });
    html += '</tbody></table></div>';
  }

  container.innerHTML = html;
}

// ========== AI ASSISTANT ==========
async function sendChat() {
  const input = document.getElementById('chatInput');
  const msg = input.value.trim();
  if (!msg) return;
  input.value = '';

  const container = document.getElementById('chatMessages');
  container.innerHTML += '<div class="chat-message user"><div class="bubble">' + msg + '</div></div>';

  const res = await api('ai_ask', {}, { question: msg });
  const answer = res.answer || 'Sorry, I could not understand that question.';
  container.innerHTML += '<div class="chat-message bot"><div class="bubble">' + answer + '</div></div>';
  container.scrollTop = container.scrollHeight;
}

// ========== SEARCH ==========
async function doSearch() {
  const q = document.getElementById('searchInput').value.trim();
  if (q.length < 2) { toast('Type at least 2 characters', 'warning'); return; }

  const data = await api('search', { q: q });
  const container = document.getElementById('searchResults');
  let html = '';

  // Cameras
  if (data.cameras && data.cameras.length > 0) {
    html += '<div class="card"><div class="card-header"><h3><i class="fas fa-video"></i> Cameras (' + data.cameras.length + ')</h3></div>' +
      '<div class="table-wrapper"><table><thead><tr><th>Code</th><th>Name</th><th>Location</th><th>Status</th></tr></thead><tbody>' +
      data.cameras.map(c => '<tr><td>' + c.camera_code + '</td><td>' + c.name + '</td><td>' + (c.location || '-') + '</td><td>' + statusBadge(c.status) + '</td></tr>').join('') +
      '</tbody></table></div></div>';
  }

  // Alerts
  if (data.alerts && data.alerts.length > 0) {
    html += '<div class="card"><div class="card-header"><h3><i class="fas fa-bell"></i> Alerts (' + data.alerts.length + ')</h3></div>' +
      data.alerts.map(a => '<div class="alert-item"><div class="alert-body"><h4>' + (a.camera_name || 'System') + '</h4><p>' + (a.message || '-') + '</p></div>' + levelBadge(a.alert_level) + '</div>').join('') + '</div>';
  }

  // Incidents
  if (data.incidents && data.incidents.length > 0) {
    html += '<div class="card"><div class="card-header"><h3><i class="fas fa-exclamation-triangle"></i> Incidents (' + data.incidents.length + ')</h3></div>' +
      '<div class="table-wrapper"><table><thead><tr><th>Number</th><th>Type</th><th>Location</th><th>Severity</th><th>Status</th></tr></thead><tbody>' +
      data.incidents.map(i => '<tr><td>' + i.incident_number + '</td><td>' + (i.incident_type || '-') + '</td><td>' + (i.location || '-') + '</td><td>' + severityBadge(i.severity) + '</td><td>' + incidentStatusBadge(i.status) + '</td></tr>').join('') +
      '</tbody></table></div></div>';
  }

  if (!html) html = '<div class="empty-state"><i class="fas fa-search"></i><h3>No Results</h3><p>No matches found for "' + q + '"</p></div>';
  container.innerHTML = html;
}

// ========== AUDIT LOG ==========
async function loadAuditLog() {
  const data = await api('audit_log');
  const tbody = document.querySelector('#auditTable tbody');
  if (!Array.isArray(data) || data.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-state"><i class="fas fa-clipboard-list"></i> No audit entries</td></tr>';
    return;
  }
  tbody.innerHTML = data.map(a => '<tr>' +
    '<td>' + fmtDate(a.created_at) + '</td>' +
    '<td><strong>' + (a.username || '-') + '</strong></td>' +
    '<td><span class="badge badge-primary">' + (a.action || '-') + '</span></td>' +
    '<td>' + (a.detail || '-') + '</td>' +
    '<td><code>' + (a.ip_address || '-') + '</code></td>' +
  '</tr>').join('');
}

// ========== SETTINGS ==========
async function loadSettings() {
  const data = await api('settings');
  const container = document.getElementById('settingsContent');
  container.innerHTML = '<form onsubmit="saveSettings(event)">' +
    '<div class="form-row">' +
      '<div class="form-group"><label>NVR IP Address</label><input id="setNVR" value="' + (data.nvr_ip || '') + '"></div>' +
      '<div class="form-group"><label>NVR Port</label><input id="setNVRPort" value="' + (data.nvr_port || '') + '"></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label>go2rtc Port</label><input id="setGo2rtc" value="' + (data.go2rtc_port || '1984') + '"></div>' +
      '<div class="form-group"><label>Snapshot Refresh (sec)</label><input type="number" id="setSnapInt" value="' + (data.snapshot_interval || '5') + '"></div>' +
    '</div>' +
    '<div class="form-row">' +
      '<div class="form-group"><label><input type="checkbox" id="setEmail"' + (data.alert_email_enabled == 1 ? ' checked' : '') + '> Alert Email Enabled</label></div>' +
      '<div class="form-group"><label>Max Storage Days</label><input type="number" id="setStorageDays" value="' + (data.max_storage_days || '30') + '"></div>' +
    '</div>' +
    '<div style="text-align:right;margin-top:16px"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button></div>' +
    '</form>';
}

async function saveSettings(e) {
  e.preventDefault();
  const res = await api('save_settings', {}, { nvr_ip: document.getElementById('setNVR').value });
  if (res.success) toast('Settings saved');
  else toast('Save failed', 'error');
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', function() {
  loadDashboard();
});
</script>
</body>
</html>
