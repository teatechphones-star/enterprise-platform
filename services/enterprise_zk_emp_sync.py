#!/usr/bin/env python3
"""
Enterprise ZKTeco Employee Sync (bidirectional)

Keeps employees in the MySQL database and on the ZKTeco device always in sync.
- Imports device-only users INTO the database (creates missing employee records)
- Pushes DB-only employees TO the device (adds new people)
- Updates name changes (DB is source of truth for name)
- Skips garbage stubs (auto-created by attendance poller)
- Handles code conflicts gracefully

Run: python3 enterprise_zk_emp_sync.py [--once] [--dry-run]
Cron: runs every 2 minutes alongside the attendance poller
"""
import re, sys, argparse, logging
import pymysql
from zk import ZK
from zk.base import User as ZKUser

DEVICE_IP = "192.168.0.106"
DEVICE_PORT = 4370
COMM_PASSWORD = 0
EMP_DEPT_DEFAULT = 5

DB = dict(host="localhost", user="enterprise_app", password="EnterpriseApp@2026",
          database="enterprise_platform", charset="utf8mb4", autocommit=True)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("zk_emp_sync")


def db():
    return pymysql.connect(**DB)


def normalize_name(name):
    if not name:
        return ""
    n = name.lower().strip()
    n = re.sub(r'[._\-]+', ' ', n)
    n = re.sub(r'\s+', ' ', n).strip()
    return n


def is_garbage_employee(emp):
    name = emp.get("full_name", "") or ""
    code = emp.get("employee_code", "") or ""
    if re.match(r'^Employee\s*#\s*\S+$', name, re.IGNORECASE):
        return True
    if name.strip() == "" and code.strip() == "":
        return True
    if any(ord(c) < 32 or ord(c) > 126 for c in code if c not in ('-', ' ')):
        return True
    return False


def ensure_zk_uid_column(conn):
    with conn.cursor() as cur:
        cur.execute("SHOW COLUMNS FROM employees LIKE 'zk_uid'")
        if not cur.fetchone():
            cur.execute("ALTER TABLE employees ADD COLUMN zk_uid INT DEFAULT NULL")
            conn.commit()
            log.info("Added zk_uid column.")


def get_real_employees(conn):
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT id, employee_code, full_name, zk_uid, status FROM employees WHERE status='Active' ORDER BY id")
        return [e for e in cur.fetchall() if not is_garbage_employee(e)]


def get_all_employees(conn):
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT id, employee_code, full_name, zk_uid, status FROM employees ORDER BY id")
        return cur.fetchall()


def get_device_users(conn_dev):
    return {int(u.uid): u for u in conn_dev.get_users()}


def find_free_uid(device_users, assigned):
    used = set(device_users.keys()) | set(int(x) for x in assigned)
    for i in range(1, 9999999):
        if i not in used:
            return i
    raise RuntimeError("No UID space")


def max_fresh_user_id(device_users):
    """Return the next free user_id: max existing device user_id + 1."""
    ids = set()
    for u in device_users.values():
        try:
            ids.add(int(str(u.user_id)))
        except (ValueError, TypeError):
            pass
    if not ids:
        return 1
    return max(ids) + 1


def run_once(dry_run=False):
    conn = db()
    try:
        ensure_zk_uid_column(conn)

        zk = ZK(DEVICE_IP, port=DEVICE_PORT, timeout=10, password=COMM_PASSWORD, force_udp=False)
        conn_dev = zk.connect()
        try:
            device_users = get_device_users(conn_dev)
            log.info("Device: %d users", len(device_users))

            employees = get_real_employees(conn)
            log.info("DB real employees: %d", len(employees))

            all_employees = get_all_employees(conn)
            existing_codes = {e["employee_code"]: e for e in all_employees}

            emp_by_name = {}
            for e in employees:
                nn = normalize_name(e["full_name"])
                if nn:
                    emp_by_name[nn] = e

            imported = 0
            pushed = 0
            updated = 0
            linked = 0
            skipped = 0
            assigned_uids = set()

            for e in employees:
                if e["zk_uid"] is not None:
                    assigned_uids.add(int(e["zk_uid"]))

            # --- PHASE 1: Import device-only users INTO DB ---
            for uid, u in device_users.items():
                nn = normalize_name(u.name)
                if not nn:
                    log.warning("  SKIP uid=%d: empty name", uid)
                    skipped += 1
                    continue

                if nn in emp_by_name:
                    e = emp_by_name[nn]
                    if e["zk_uid"] is None:
                        log.info("  LINK %s (id=%d) -> uid=%d (name match)", e["employee_code"], e["id"], uid)
                        if not dry_run:
                            with conn.cursor() as cur:
                                cur.execute("UPDATE employees SET zk_uid=%s WHERE id=%s", (int(uid), int(e["id"])))
                            conn.commit()
                        linked += 1
                    continue

                code = "EMP-" + str(uid).zfill(3)
                if code in existing_codes:
                    existing = existing_codes[code]
                    existing_nn = normalize_name(existing["full_name"])
                    if existing_nn == nn:
                        # Same name — just link
                        if existing["zk_uid"] is None:
                            log.info("  LINK %s (id=%d) -> uid=%d", code, existing["id"], uid)
                            if not dry_run:
                                with conn.cursor() as cur:
                                    cur.execute("UPDATE employees SET zk_uid=%s WHERE id=%s", (int(uid), int(existing["id"])))
                                conn.commit()
                            linked += 1
                        continue
                    elif is_garbage_employee(existing):
                        # Reclaim the stub row: it was auto-created by the attendance
                        # poller for this exact pin and carries no real identity.
                        log.info("  RECLAIM %s (id=%d): stub '%s' -> real '%s'", code,
                                 existing["id"], existing["full_name"], u.name)
                        if not dry_run:
                            with conn.cursor() as cur:
                                cur.execute("UPDATE employees SET full_name=%s, status='Active', zk_uid=%s WHERE id=%s",
                                            (u.name, int(uid), int(existing["id"])))
                            conn.commit()
                        linked += 1
                        continue
                    else:
                        log.warning("  SKIP uid=%d '%s': code %s taken by '%s' (name mismatch)",
                                    uid, u.name, code, existing["full_name"])
                        skipped += 1
                        continue

                log.info("  IMPORT uid=%d '%s' -> %s", uid, u.name, code)
                if not dry_run:
                    with conn.cursor() as cur:
                        cur.execute("""
                            INSERT INTO employees (employee_code, full_name, status, department_id, zk_uid)
                            VALUES (%s, %s, 'Active', %s, %s)
                        """, (code, u.name, EMP_DEPT_DEFAULT, int(uid)))
                    conn.commit()
                    assigned_uids.add(int(uid))
                    existing_codes[code] = {"employee_code": code, "full_name": u.name, "zk_uid": uid}
                imported += 1

            # --- PHASE 2: Push DB-only employees TO device ---
            employees = get_real_employees(conn)
            next_user_id = max_fresh_user_id(device_users)
            for e in employees:
                nn = normalize_name(e["full_name"])
                if not nn:
                    continue
                if e["zk_uid"] is not None:
                    uid = int(e["zk_uid"])
                    dev_u = device_users.get(uid)
                    if dev_u and normalize_name(dev_u.name) != nn:
                        # keep existing device user_id
                        dev_uid = dev_u.user_id
                        zk_name = e["full_name"][:24]
                        log.info("  UPDATE-DEV uid=%d (user_id=%s): '%s' -> '%s'", uid, dev_uid, dev_u.name, zk_name)
                        if not dry_run:
                            conn_dev.set_user(uid=uid, name=zk_name, privilege=0, password="",
                                              group_id="", user_id=str(dev_uid), card=0)
                        updated += 1
                else:
                    zk_name = e["full_name"][:24]
                    uid = find_free_uid(device_users, assigned_uids)
                    new_id = next_user_id
                    next_user_id += 1
                    log.info("  PUSH id=%d '%s' -> uid=%d user_id=%d", e["id"], zk_name, uid, new_id)
                    if not dry_run:
                        conn_dev.set_user(uid=uid, name=zk_name, privilege=0, password="",
                                          group_id="", user_id=str(new_id), card=0)
                        with conn.cursor() as cur:
                            cur.execute("UPDATE employees SET zk_uid=%s WHERE id=%s", (int(uid), int(e["id"])))
                        conn.commit()
                    assigned_uids.add(uid)
                    device_users[uid] = type('obj', (object,), {'name': zk_name, 'user_id': str(new_id)})()
                    pushed += 1

            log.info("Sync done: %d imported, %d linked, %d pushed, %d updated, %d skipped%s",
                     imported, linked, pushed, updated, skipped, " (DRY RUN)" if dry_run else "")
        finally:
            conn_dev.disconnect()
    finally:
        conn.close()


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    run_once(dry_run=args.dry_run)
