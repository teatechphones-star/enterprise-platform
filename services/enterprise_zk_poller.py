#!/usr/bin/env python3
"""
Enterprise ZKTeco Poller
Polls the ZKTeco device on TCP 4370 (pyzk), ingests attendance into the
enterprise_platform DB mirroring /iclock/cdata.php logic.
Run: python3 enterprise_zk_poller.py [--once]  (cron every minute)
"""
import sys, time, datetime, argparse, logging, calendar
import pymysql
from zk import ZK
from zk import base as _zkbase

# Sanitize ZKTeco's occasionally-corrupt (garbage) timestamps so a single bad
# record does not abort the whole attendance read. Invalid dates -> None.
_orig_decode_time = _zkbase.ZK.__dict__['_ZK__decode_time']  # real class is zk.base.ZK
def _safe_decode_time(self, raw):
    try:
        return _orig_decode_time(self, raw)
    except (ValueError, OverflowError, TypeError):
        try:
            # raw is bytes of y,m,d,h,mi,s; try to salvage valid-ish fields
            if isinstance(raw, bytes) and len(raw) >= 6:
                y, mo, d, h, mi, s = raw[0], raw[1], raw[2], raw[3], raw[4], raw[5]
                y = 2000 + y if y < 100 else y
                if 1 <= mo <= 12 and 1 <= d <= calendar.monthrange(y, mo)[1]:
                    return datetime.datetime(y, mo, d, h, mi, s)
        except Exception:
            pass
        return None
_zkbase.ZK._ZK__decode_time = _safe_decode_time

DEVICE_IP = "192.168.0.106"
DEVICE_PORT = 4370
DEVICE_SN = "6160062263310"      # read live from device
DEVICE_NAME = "Factory Terminal (ZAM170)"
COMM_PASSWORD = 0
YEAR_FLOOR = 2026               # only ingest punches from 2026-2027

DB = dict(host="localhost", user="enterprise_app", password="EnterpriseApp@2026",
          database="enterprise_platform", charset="utf8mb4", autocommit=True)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("zkpoller")

def db():
    return pymysql.connect(**DB)

def emp_code(pin):
    # Mirror app: 'EMP-' + zero-padded to 3
    return "EMP-" + str(pin).zfill(3)

def sync_device_heartbeat(conn, sn, ip, users, att, fw, platform):
    with conn.cursor() as cur:
        cur.execute("""
          INSERT INTO biometric_devices (sn, device_name, ip_address, firmware_version, user_count, att_log_count, last_heartbeat, status)
          VALUES (%s,%s,%s,%s,%s,%s,NOW(),'Online')
          ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address), firmware_version=VALUES(firmware_version),
            user_count=VALUES(user_count), att_log_count=VALUES(att_log_count), last_heartbeat=NOW(),
            status='Online', device_name=COALESCE(NULLIF(device_name,''),VALUES(device_name))
        """, (sn, DEVICE_NAME, DEVICE_IP, f"{platform} fw{fw}", users, att))
    conn.commit()

def process_record(conn, sn, att):
    # att: pyzk Attendance(.user_id, .timestamp, .status, .punch, .uid)
    pin = str(att.user_id or "").strip()
    if not pin or not pin.isdigit():
        return 0  # empty/buffer-garbage slot, skip
    punch = att.timestamp
    if punch is None:
        return 0
    status_code = str(att.punch if att.punch is not None else 0)
    verify = str(att.status if att.status is not None else 1)
    # Only current-period real punches (device buffer holds tons of junk / bad dates)
    if not (YEAR_FLOOR <= punch.year <= YEAR_FLOOR + 1):
        return 0
    work_date = punch.strftime("%Y-%m-%d")
    code = emp_code(pin)
    insert_raw = False
    with conn.cursor() as cur:
        # raw log (immutable), only if not already present
        cur.execute("SELECT id FROM raw_clocking_logs WHERE device_sn=%s AND pin=%s AND punch_time=%s",
                    (sn, pin, punch.strftime("%Y-%m-%d %H:%M:%S")))
        if not cur.fetchone():
            cur.execute("""INSERT INTO raw_clocking_logs (device_sn,pin,punch_time,status_code,verify_type,work_code,raw_payload)
                           VALUES (%s,%s,%s,%s,%s,'',%s)""",
                        (sn, pin, punch.strftime("%Y-%m-%d %H:%M:%S"), status_code, verify,
                         f"{pin}\t{punch}\t{status_code}\t{verify}"))
            conn.commit()
            insert_raw = True

    # ---- attendance engine (mirror PHP) ----
    with conn.cursor() as cur:
        # find / create employee
        cur.execute("SELECT id FROM employees WHERE employee_code=%s OR id=%s", (code, pin))
        row = cur.fetchone()
        if row:
            emp_id = row[0]
        else:
            cur.execute("INSERT INTO employees (employee_code, full_name, status, department_id) VALUES (%s,%s,'Active',5)",
                        (code, f"Employee #{pin}"))
            emp_id = cur.lastrowid
        conn.commit()

        # existing attendance record for the day
        cur.execute("SELECT * FROM attendance_records WHERE employee_id=%s AND work_date=%s ORDER BY id DESC LIMIT 1",
                    (emp_id, work_date))
        rec = cur.fetchone()
        cols = [c[0] for c in cur.description]
        recd = dict(zip(cols, rec)) if rec else None

        # shift for late / ot
        cur.execute("""SELECT s.start_time,s.end_time,s.grace_minutes FROM shift_schedules ss
                       JOIN shifts s ON s.id=ss.shift_id
                       WHERE ss.employee_id=%s AND (ss.effective_date<=%s AND (ss.end_date IS NULL OR ss.end_date>=%s))
                       ORDER BY ss.effective_date DESC LIMIT 1""", (emp_id, work_date, work_date))
        shift = cur.fetchone()

        status = "Present"
        if shift:
            sstart = datetime.datetime.strptime(work_date + " " + str(shift[0]), "%Y-%m-%d %H:%M:%S")
            grace = int(shift[2] or 0)
            if punch > (sstart + datetime.timedelta(minutes=grace)):
                status = "Late"

        if not recd:
            cur.execute("""INSERT INTO attendance_records (employee_id,work_date,clock_in,status,source)
                           VALUES (%s,%s,%s,%s,'Machine')""", (emp_id, work_date, punch, status))
            log.info("  new clock-in emp=%s(%s) at %s status=%s", code, emp_id, punch, status)
        else:
            ot = 0
            if shift:
                send = datetime.datetime.strptime(work_date + " " + str(shift[1]), "%Y-%m-%d %H:%M:%S")
                if punch > send:
                    ot = round((punch - send).total_seconds() / 60)
            cur.execute("UPDATE attendance_records SET clock_out=%s, overtime_minutes=%s WHERE id=%s",
                        (punch, ot, recd["id"]))
            log.info("  clock-out emp=%s(%s) at %s ot=%s", code, emp_id, punch, ot)
        conn.commit()

        # live notification
        cur.execute("INSERT INTO notifications (module,title,message) VALUES ('Attendance','Biometric Punch Received',%s)",
                    (f"Employee {code} punched at {punch}"))
    return 1

def run_once():
    conn = db()
    try:
        zk = ZK(DEVICE_IP, port=DEVICE_PORT, timeout=10, password=COMM_PASSWORD, force_udp=False)
        conn_dev = zk.connect()
        try:
            fw = conn_dev.get_firmware_version()
            platform = conn_dev.get_platform()
            users = list(conn_dev.get_users())
            atts = list(conn_dev.get_attendance())
            sn = conn_dev.get_serialnumber().strip()
        finally:
            conn_dev.disconnect()
        sync_device_heartbeat(conn, sn or DEVICE_SN, DEVICE_IP, len(users), len(atts), fw, platform)
        log.info("device %s users=%d att=%d", sn, len(users), len(atts))
        inserted = 0
        for a in atts:
            try:
                inserted += process_record(conn, sn or DEVICE_SN, a)
            except Exception as e:
                log.warning("record %s failed: %s", a, e)
        log.info("done, new clock events=%d (plus raw inserts)", inserted)
    finally:
        conn.close()

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--once", action="store_true")
    a = ap.parse_args()
    run_once() if a.once else None