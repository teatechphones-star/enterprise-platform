"""
Enterprise SMS Gateway Service
Runs on Ubuntu VPS (Port 5005)
Supports: Android ADB, Serial GSM Modem, Twilio, Africa's Talking, Generic Webhook, and Simulator.
"""
import os
import json
import sqlite3
import subprocess
import datetime
import urllib.parse
from flask import Flask, request, jsonify

app = Flask(__name__)
DB_PATH = '/var/www/enterprise/storage/sms_gateway.db'

def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = get_db()
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS outgoing_sms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient TEXT NOT NULL,
        message TEXT NOT NULL,
        source_module TEXT DEFAULT 'System',
        status TEXT DEFAULT 'Queued',
        driver TEXT DEFAULT 'Simulator',
        log TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL
    )''')
    c.execute('''CREATE TABLE IF NOT EXISTS sms_config (
        key TEXT PRIMARY KEY,
        value TEXT
    )''')
    defaults = {
        'driver': 'Simulator',
        'android_ip': '192.168.100.45:5555',
        'modem_port': '/dev/ttyUSB0',
        'modem_baud': '115200',
        'twilio_sid': '',
        'twilio_token': '',
        'twilio_from': '',
        'at_username': 'sandbox',
        'at_api_key': '',
        'at_from': '',
        'webhook_url': '',
        'auto_cctv_sms': '1',
        'auto_attendance_sms': '1',
        'auto_hr_sms': '1',
        'admin_phone': '+233201234567'
    }
    for k, v in defaults.items():
        c.execute("INSERT OR IGNORE INTO sms_config (key, value) VALUES (?, ?)", (k, v))
    conn.commit()
    conn.close()

def get_config_dict():
    conn = get_db()
    c = conn.cursor()
    c.execute("SELECT key, value FROM sms_config")
    rows = c.fetchall()
    conn.close()
    return {r['key']: r['value'] for r in rows}

def set_config(key, val):
    conn = get_db()
    c = conn.cursor()
    c.execute("INSERT INTO sms_config (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (key, str(val)))
    conn.commit()
    conn.close()

def dispatch_sms(phone, msg, driver_override=None):
    cfg = get_config_dict()
    driver = driver_override or cfg.get('driver', 'Simulator')
    
    # Clean phone
    phone = phone.strip()
    
    if driver == 'Android_ADB':
        target = cfg.get('android_ip', '192.168.100.45:5555')
        # Ensure ADB daemon running and try connecting
        try:
            if target and ':' in target:
                subprocess.run(f"adb connect {target}", shell=True, capture_output=True, timeout=5)
            cmd = f"adb shell service call isms 5 s16 'com.android.mms' s16 '{phone}' s16 'null' s16 '{msg}' s16 'null' s16 'null' 2>&1"
            res = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=10)
            if res.returncode == 0 and 'Result: Parcel' in res.stdout:
                return True, f"ADB Dispatch OK: {res.stdout.strip()}", driver
            elif 'no devices' in res.stdout or 'cannot connect' in res.stdout:
                return False, f"ADB Device Offline / Not Found: {res.stdout.strip()}", driver
            else:
                return (res.returncode == 0), res.stdout.strip(), driver
        except Exception as e:
            return False, f"ADB Error: {str(e)}", driver

    elif driver == 'Serial_Modem':
        port = cfg.get('modem_port', '/dev/ttyUSB0')
        baud = int(cfg.get('modem_baud', 115200))
        try:
            import serial
            ser = serial.Serial(port, baud, timeout=5)
            ser.write(b'AT+CMGF=1\r\n')
            ser.write(f'AT+CMGS="{phone}"\r\n'.encode())
            ser.write(f'{msg}\x1A'.encode())
            ser.close()
            return True, f"Sent via Serial Modem on {port}", driver
        except Exception as e:
            return False, f"Serial Modem Error on {port}: {str(e)}", driver

    elif driver == 'Twilio':
        sid = cfg.get('twilio_sid', '')
        token = cfg.get('twilio_token', '')
        from_num = cfg.get('twilio_from', '')
        if not sid or not token:
            return False, "Twilio SID and Token are not configured", driver
        try:
            import requests
            url = f"https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json"
            res = requests.post(url, auth=(sid, token), data={'From': from_num, 'To': phone, 'Body': msg}, timeout=10)
            if res.status_code in [200, 201]:
                return True, f"Twilio SMS Delivered (SID: {res.json().get('sid')})", driver
            else:
                return False, f"Twilio Error {res.status_code}: {res.text}", driver
        except Exception as e:
            return False, f"Twilio Exception: {str(e)}", driver

    elif driver == 'AfricasTalking':
        username = cfg.get('at_username', 'sandbox')
        api_key = cfg.get('at_api_key', '')
        from_sender = cfg.get('at_from', '')
        if not api_key:
            return False, "Africa's Talking API Key missing", driver
        try:
            import requests
            url = "https://api.africastalking.com/version1/messaging" if username != 'sandbox' else "https://api.sandbox.africastalking.com/version1/messaging"
            headers = {'apiKey': api_key, 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'}
            data = {'username': username, 'to': phone, 'message': msg}
            if from_sender: data['from'] = from_sender
            res = requests.post(url, headers=headers, data=data, timeout=10)
            if res.status_code in [200, 201]:
                return True, f"Africa's Talking Delivered: {res.text}", driver
            else:
                return False, f"Africa's Talking Error {res.status_code}: {res.text}", driver
        except Exception as e:
            return False, f"Africa's Talking Exception: {str(e)}", driver

    elif driver == 'Generic_Webhook':
        url = cfg.get('webhook_url', '')
        if not url:
            return False, "Webhook URL not configured", driver
        try:
            import requests
            res = requests.post(url, json={'to': phone, 'message': msg, 'timestamp': datetime.datetime.now().isoformat()}, timeout=10)
            return (res.status_code < 300), f"Webhook responded {res.status_code}: {res.text[:100]}", driver
        except Exception as e:
            return False, f"Webhook Exception: {str(e)}", driver

    # Simulator (default fallback)
    return True, f"[SIMULATOR] SMS dispatched successfully to {phone} (Chars: {len(msg)})", driver

@app.route('/api/sms/send', methods=['POST'])
def send_sms():
    data = request.json or request.form or {}
    recipient = data.get('recipient')
    message = data.get('message')
    module = data.get('module', 'System')
    driver_override = data.get('driver')

    if not recipient or not message:
        return jsonify({'ok': False, 'error': 'Recipient and message required'}), 400

    conn = get_db()
    c = conn.cursor()
    c.execute("INSERT INTO outgoing_sms (recipient, message, source_module, status, driver) VALUES (?, ?, ?, 'Queued', ?)",
              (recipient, message, module, driver_override or 'Active_Config'))
    sms_id = c.lastrowid
    conn.commit()
    conn.close()

    # Dispatch
    success, log_msg, used_driver = dispatch_sms(recipient, message, driver_override)
    
    new_status = 'Sent' if success else 'Failed'
    conn = get_db()
    c = conn.cursor()
    c.execute("UPDATE outgoing_sms SET status=?, driver=?, log=?, sent_at=CURRENT_TIMESTAMP WHERE id=?",
              (new_status, used_driver, log_msg, sms_id))
    conn.commit()
    conn.close()

    return jsonify({
        'ok': success,
        'sms_id': sms_id,
        'status': new_status,
        'driver': used_driver,
        'log': log_msg
    })

@app.route('/api/sms/queue', methods=['GET'])
def list_queue():
    limit = int(request.args.get('limit', 100))
    module_filter = request.args.get('module')
    status_filter = request.args.get('status')
    
    conn = get_db()
    c = conn.cursor()
    query = "SELECT * FROM outgoing_sms WHERE 1=1"
    params = []
    if module_filter:
        query += " AND source_module=?"
        params.append(module_filter)
    if status_filter:
        query += " AND status=?"
        params.append(status_filter)
    query += " ORDER BY id DESC LIMIT ?"
    params.append(limit)
    
    c.execute(query, params)
    rows = [dict(r) for r in c.fetchall()]
    conn.close()
    return jsonify({'ok': True, 'list': rows})

@app.route('/api/sms/retry', methods=['POST'])
def retry_sms():
    data = request.json or request.form or {}
    sms_id = data.get('sms_id')
    if not sms_id:
        return jsonify({'ok': False, 'error': 'sms_id required'}), 400
    
    conn = get_db()
    c = conn.cursor()
    c.execute("SELECT * FROM outgoing_sms WHERE id=?", (sms_id,))
    row = c.fetchone()
    if not row:
        conn.close()
        return jsonify({'ok': False, 'error': 'SMS record not found'}), 404

    recipient = row['recipient']
    message = row['message']
    
    success, log_msg, used_driver = dispatch_sms(recipient, message)
    new_status = 'Sent' if success else 'Failed'
    c.execute("UPDATE outgoing_sms SET status=?, driver=?, log=?, sent_at=CURRENT_TIMESTAMP WHERE id=?",
              (new_status, used_driver, log_msg, sms_id))
    conn.commit()
    conn.close()
    return jsonify({'ok': success, 'status': new_status, 'log': log_msg, 'driver': used_driver})

@app.route('/api/sms/status', methods=['GET'])
def status():
    cfg = get_config_dict()
    driver = cfg.get('driver', 'Simulator')
    
    conn = get_db()
    c = conn.cursor()
    c.execute("SELECT COUNT(*) FROM outgoing_sms WHERE status='Sent'")
    sent_count = c.fetchone()[0]
    c.execute("SELECT COUNT(*) FROM outgoing_sms WHERE status='Failed'")
    failed_count = c.fetchone()[0]
    c.execute("SELECT COUNT(*) FROM outgoing_sms WHERE status='Queued'")
    queued_count = c.fetchone()[0]
    c.execute("SELECT COUNT(*) FROM outgoing_sms")
    total_count = c.fetchone()[0]
    conn.close()
    
    # Device status check
    adb_online = False
    if driver == 'Android_ADB':
        try:
            r = subprocess.run("adb devices", shell=True, capture_output=True, text=True, timeout=3)
            lines = [l for l in r.stdout.split('\n') if '\tdevice' in l]
            adb_online = len(lines) > 0
        except Exception:
            adb_online = False

    return jsonify({
        'ok': True,
        'service': 'Self-Hosted Enterprise SMS Gateway',
        'status': 'OPERATIONAL',
        'driver': driver,
        'adb_device_online': adb_online,
        'admin_phone': cfg.get('admin_phone', ''),
        'metrics': {
            'total': total_count,
            'sent': sent_count,
            'failed': failed_count,
            'queued': queued_count,
            'success_rate': round((sent_count / total_count * 100), 1) if total_count > 0 else 100.0
        },
        'config': {
            'driver': driver,
            'android_ip': cfg.get('android_ip', ''),
            'modem_port': cfg.get('modem_port', ''),
            'twilio_sid_set': bool(cfg.get('twilio_sid')),
            'at_api_key_set': bool(cfg.get('at_api_key')),
            'webhook_url_set': bool(cfg.get('webhook_url')),
            'auto_cctv_sms': cfg.get('auto_cctv_sms', '1'),
            'auto_attendance_sms': cfg.get('auto_attendance_sms', '1'),
            'auto_hr_sms': cfg.get('auto_hr_sms', '1')
        }
    })

@app.route('/api/sms/config', methods=['POST', 'GET'])
def config_endpoint():
    if request.method == 'GET':
        return jsonify({'ok': True, 'config': get_config_dict()})
    
    data = request.json or request.form or {}
    for k, v in data.items():
        set_config(k, v)
    return jsonify({'ok': True, 'message': 'Configuration updated successfully', 'config': get_config_dict()})

if __name__ == '__main__':
    init_db()
    app.run(host='0.0.0.0', port=5005)
