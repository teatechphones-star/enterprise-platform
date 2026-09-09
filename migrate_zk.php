<?php
/**
 * ZK Attendance backfill + shift/late-early migration.
 * Maps raw clocking punches (PIN via zk_uid) to employees, fills attendance_records
 * from last month to today, and adds shift/late/early logic by employee + department.
 * Run ONCE: php /var/www/enterprise/migrate_zk.php
 */
require_once __DIR__ . '/public/config.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) { echo "ERR connect: {$db->connect_error}\n"; exit(1); }
$db->set_charset('utf8mb4');

function esc($db,$s){ return $db->real_escape_string((string)$s); }

echo "== 1. Schema additions ==\n";
// department_shifts: default shift per department (for late/early when employee has no individual schedule)
$db->query("CREATE TABLE IF NOT EXISTS department_shifts (
  department_id INT NOT NULL PRIMARY KEY,
  shift_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
)") or die("dept_shifts: ".$db->error);
echo "   department_shifts OK\n";

// attendance_records extra columns
$cols = $db->query("SHOW COLUMNS FROM attendance_records");
$have = [];
while($r=$cols->fetch_assoc()) $have[$r['Field']]=true;
if(!isset($have['shift_id']))  $db->query("ALTER TABLE attendance_records ADD COLUMN shift_id INT NULL") or die($db->error);
if(!isset($have['late_minutes'])) $db->query("ALTER TABLE attendance_records ADD COLUMN late_minutes INT NOT NULL DEFAULT 0") or die($db->error);
if(!isset($have['early_minutes'])) $db->query("ALTER TABLE attendance_records ADD COLUMN early_minutes INT NOT NULL DEFAULT 0") or die($db->error);
if(!isset($have['work_duration_minutes'])) $db->query("ALTER TABLE attendance_records ADD COLUMN work_duration_minutes INT NOT NULL DEFAULT 0") or die($db->error);
echo "   attendance_records columns OK\n";

echo "== 2. Resolve shift for employee (individual > department > General) ==\n";
function shiftFor($db,$eid,$dept_id,$date){
  // individual schedule
  $r=$db->query("SELECT s.* FROM shift_schedules ss JOIN shifts s ON s.id=ss.shift_id
    WHERE ss.employee_id=".(int)$eid." AND ss.effective_date<='".esc($db,$date)."'
    AND (ss.end_date IS NULL OR ss.end_date>='".esc($db,$date)."') ORDER BY ss.effective_date DESC LIMIT 1")->fetch_assoc();
  if($r) return $r;
  // department default
  if($dept_id){
    $r=$db->query("SELECT s.* FROM department_shifts ds JOIN shifts s ON s.id=ds.shift_id WHERE ds.department_id=".(int)$dept_id)->fetch_assoc();
    if($r) return $r;
  }
  // fallback General 08:00-17:00 (id 4) else first shift
  $r=$db->query("SELECT * FROM shifts WHERE name='General' LIMIT 1")->fetch_assoc();
  if(!$r) $r=$db->query("SELECT * FROM shifts ORDER BY id LIMIT 1")->fetch_assoc();
  return $r;
}

echo "== 3. Backfill attendance_records from raw_clocking_logs (last month -> today) ==\n";
$from = date('Y-m-d', strtotime('first day of last month'));
$to   = date('Y-m-d');
// map pin -> employee_id via zk_uid (ac number)
$emap = [];
$rr=$db->query("SELECT id, zk_uid, department_id FROM employees WHERE zk_uid IS NOT NULL AND zk_uid!=''");
while($r=$rr->fetch_assoc()){
  $emap[(string)$r['zk_uid']] = $r;
}
echo "   mapped ".count($emap)." employees by zk_uid\n";

// group punches per (employee,pin,date)
$logs = $db->query("SELECT pin, DATE_FORMAT(punch_time,'%Y-%m-%d') d, punch_time
  FROM raw_clocking_logs WHERE processed=0
  AND DATE(punch_time) BETWEEN '$from' AND '$to'
  ORDER BY pin, punch_time");
$groups = [];
while($g=$logs->fetch_assoc()){
  $pin=(string)$g['pin'];
  if(!isset($emap[$pin])) continue;
  $key=$emap[$pin]['id'].'|'.$g['d'];
  if(!isset($groups[$key])) $groups[$key]=[];
  $groups[$key][]=$g['punch_time'];
}
echo "   grouped ".count($groups)." employee-days of punches\n";

$inserted=0; $updated=0;
foreach($groups as $key=>$punchArr){
  [$eid,$d] = explode('|',$key);
  $eid=(int)$eid;
  // derive dept from employee row
  $ee=$db->query("SELECT department_id FROM employees WHERE id=$eid")->fetch_assoc();
  $dept = $ee ? $ee['department_id'] : null;
  $times=array_values($punchArr); sort($times);
  $clock_in = $times[0];
  $clock_out = count($times)>1 ? end($times) : null;
  $shift = shiftFor($db,$eid,$dept,$d);
  $shift_id = $shift['id'] ?? null;
  $sstart = strtotime($d.' '.$shift['start_time']);
  $grace = (int)($shift['grace_minutes'] ?? 5);
  $in_ts = strtotime($clock_in);
  $late = max(0, round(($in_ts - ($sstart + $grace*60))/60));
  // early = clocked out before scheduled end (early departure)
  $early=0;
  if($clock_out){
    $send = strtotime($d.' '.$shift['end_time']);
    // handle overnight shifts where end<start: end belongs to next day
    if(strtotime($shift['end_time']) <= strtotime($shift['start_time'])) $send = strtotime($d.' '.$shift['end_time'].' +1 day');
    $out_ts=strtotime($clock_out);
    if($out_ts < $send) $early = max(0, round(($send-$out_ts)/60));
  }
  $status = $late>0 ? 'Late' : 'Present';
  if(!$clock_out) $status='Present'; // no out yet still present
  if($early>0 && $status==='Present') $status='Early'; // early departure flag
  $dur = $clock_out ? max(0,round((strtotime($clock_out)-$in_ts)/60)) : 0;
  // mark punche(s) processed
  // check existing
  $ex=$db->query("SELECT id FROM attendance_records WHERE employee_id=$eid AND work_date='".esc($db,$d)."'")->fetch_assoc();
  if($ex){
    $sid = $shift_id ? $shift_id : "NULL";
    $db->query("UPDATE attendance_records SET clock_in='".esc($db,$clock_in)."', clock_out=".($clock_out?"'".esc($db,$clock_out)."'":"NULL").",
      status='".esc($db,$status)."', shift_id=$sid, late_minutes=$late, early_minutes=$early,
      work_duration_minutes=$dur, source='Machine' WHERE id={$ex['id']}");
    $updated++;
  } else {
    $sid = $shift_id ? (string)$shift_id : "NULL";
    $db->query("INSERT INTO attendance_records (employee_id,work_date,clock_in,clock_out,status,shift_id,late_minutes,early_minutes,work_duration_minutes,source)
      VALUES ($eid,'".esc($db,$d)."','".esc($db,$clock_in)."',".($clock_out?"'".esc($db,$clock_out)."'":"NULL").",
      '".esc($db,$status)."',$sid,$late,$early,$dur,'Machine')");
    $inserted++;
  }
}
$db->query("UPDATE raw_clocking_logs SET processed=1 WHERE processed=0 AND DATE(punch_time) BETWEEN '$from' AND '$to'");
echo "   inserted $inserted, updated $updated attendance records\n";

echo "== 4. Brief default: assign General shift (id 4) to departments that have none ==\n";
// (none forced; admin configures in Settings)

echo "DONE.\n";