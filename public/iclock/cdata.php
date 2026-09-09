<?php
/**
 * ZKTeco / ADMS Push Protocol Gateway - cdata.php
 * Handles device registration, heartbeat, attendance log upload, and employee sync.
 */
require_once __DIR__ . '/../config.php';

function db() {
    static $c = null;
    if ($c === null) {
        $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($c->connect_error) die("DB Error: " . $c->connect_error);
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

$sn = $_GET['SN'] ?? $_GET['sn'] ?? 'UNKNOWN_DEVICE';
$table = $_GET['table'] ?? $_GET['TABLE'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Update device heartbeat and status
q("INSERT INTO biometric_devices (sn, ip_address, last_heartbeat, status) 
   VALUES (?, ?, NOW(), 'Online') 
   ON DUPLICATE KEY UPDATE ip_address=?, last_heartbeat=NOW(), status='Online'", 
   'sss', [$sn, $ip, $ip]);

// Read raw body (POST payload from terminal)
$raw = file_get_contents('php://input');

// 1. Attendance Log Push (ATTLOG)
if (strtoupper($table) === 'ATTLOG' || stripos($raw, "\t") !== false) {
    $lines = explode("\n", str_replace("\r", "", $raw));
    $count = 0;
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // ZKTeco ATTLOG format: PIN \t Time \t Status \t VerifyType \t WorkCode \t Reserved \t Reserved
        $parts = explode("\t", $line);
        if (count($parts) >= 2) {
            $pin = trim($parts[0]);
            $punch_time = trim($parts[1]);
            $status_code = $parts[2] ?? '0'; // 0: Check-In, 1: Check-Out, 2: Break-Out, 3: Break-In, 4: OT-In, 5: OT-Out
            $verify_type = $parts[3] ?? '1'; // 1: Fingerprint, 2: Password, 15: Face, etc.
            $work_code = $parts[4] ?? '';

            if (!empty($pin) && !empty($punch_time)) {
                // 1.1 Store immutable raw log for audit integrity
                q("INSERT INTO raw_clocking_logs (device_sn, pin, punch_time, status_code, verify_type, work_code, raw_payload) 
                   VALUES (?, ?, ?, ?, ?, ?, ?)",
                   'sssssss', [$sn, $pin, $punch_time, $status_code, $verify_type, $work_code, $line]);

                // 1.2 Process into Attendance Engine
                processAttendanceRecord($pin, $punch_time, $status_code);
                $count++;
            }
        }
    }
    
    // Return OK count per ZKTeco ADMS specification
    echo "OK: $count";
    exit;
}

// 2. User Info / Biodata Sync (USERINFO / BIODATA)
if (strtoupper($table) === 'USERINFO' || strtoupper($table) === 'USER') {
    $lines = explode("\n", str_replace("\r", "", $raw));
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        // Format: PIN=123 \t Name=John Doe \t Pri=0 ...
        parse_str(str_replace("\t", "&", $line), $userData);
        $pin = $userData['PIN'] ?? $userData['CardNo'] ?? null;
        $name = $userData['Name'] ?? null;
        
        if ($pin && $name) {
            // Upsert into employees table if not present or link by employee_code / pin
            $code = 'EMP-' . str_pad($pin, 3, '0', STR_PAD_LEFT);
            q("INSERT INTO employees (employee_code, full_name, status, department_id)
               VALUES (?, ?, 'Active', 5)
               ON DUPLICATE KEY UPDATE full_name=IF(full_name IS NULL OR full_name='', VALUES(full_name), full_name)",
               'ss', [$code, $name]);
        }
    }
    echo "OK";
    exit;
}

// Default response for initial handshakes (options, push options)
echo "OK";

/**
 * Attendance Engine Processor
 * Calculates Present, Late, Clock-In, Clock-Out based on employee shift
 */
function processAttendanceRecord($pin, $punch_time, $status_code) {
    $work_date = date('Y-m-d', strtotime($punch_time));
    $code = 'EMP-' . str_pad($pin, 3, '0', STR_PAD_LEFT);

    // Find employee ID
    $emp = q("SELECT id, department_id FROM employees WHERE employee_code=? OR id=?", 'si', [$code, $pin]);
    if (empty($emp)) {
        // Auto-create stub employee if unknown
        $res = q("INSERT INTO employees (employee_code, full_name, status, department_id) VALUES (?, ?, 'Active', 5)",
                 'ss', [$code, "Employee #$pin"]);
        $emp_id = $res['insert_id'] ?? 0;
    } else {
        $emp_id = $emp[0]['id'];
    }

    if (!$emp_id) return;

    // Check existing attendance record for today
    $rec = q("SELECT * FROM attendance_records WHERE employee_id=? AND work_date=? ORDER BY id DESC LIMIT 1", 'is', [$emp_id, $work_date]);

    // Check shift for late calculation
    $shift = q("SELECT s.* FROM shift_schedules ss JOIN shifts s ON s.id=ss.shift_id 
                WHERE ss.employee_id=? AND (ss.effective_date<=? AND (ss.end_date IS NULL OR ss.end_date>=?)) 
                ORDER BY ss.effective_date DESC LIMIT 1", 'iss', [$emp_id, $work_date, $work_date]);
    
    $status = 'Present';
    if (!empty($shift)) {
        $sstart = strtotime($work_date . ' ' . $shift[0]['start_time']);
        $grace = (int)$shift[0]['grace_minutes'];
        if (strtotime($punch_time) > ($sstart + $grace * 60)) {
            $status = 'Late';
        }
    }

    if (empty($rec)) {
        // First punch of the day: Clock-In
        q("INSERT INTO attendance_records (employee_id, work_date, clock_in, status, source) 
           VALUES (?, ?, ?, ?, 'Machine')",
           'isss', [$emp_id, $work_date, $punch_time, $status]);
    } else {
        // Subsequent punch: Update Clock-Out
        $in_time = strtotime($rec[0]['clock_in']);
        $out_time = strtotime($punch_time);
        
        $ot = 0;
        if (!empty($shift)) {
            $send = strtotime($work_date . ' ' . $shift[0]['end_time']);
            if ($out_time > $send) {
                $ot = round(($out_time - $send) / 60);
            }
        }

        q("UPDATE attendance_records SET clock_out=?, overtime_minutes=? WHERE id=?", 
           'sii', [$punch_time, $ot, $rec[0]['id']]);
    }

    // Insert Notification for live feed
    q("INSERT INTO notifications (module, title, message) VALUES ('Attendance', ?, ?)",
       'ss', ['Biometric Punch Received', "Employee $code punched at $punch_time"]);
}
