-- ============================================================
-- Enterprise Automation Platform - Central Database Schema
-- Single shared MySQL database for all 5 modules
-- (Plant Log, HR, Attendance, CCTV, Admin Dashboard)
-- ============================================================

CREATE DATABASE IF NOT EXISTS enterprise_platform
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE enterprise_platform;

-- ============================================================
-- AUTHENTICATION LAYER (Step 3)
-- ============================================================
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  module VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120),
  email VARCHAR(120),
  role_id INT,
  employee_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45),
  user_agent VARCHAR(255),
  expires_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- HR MODULE (Step 5)
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  manager_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  department_id INT,
  description TEXT,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  date_of_birth DATE,
  gender ENUM('Male','Female','Other'),
  phone VARCHAR(30),
  email VARCHAR(120),
  address TEXT,
  emergency_contact VARCHAR(120),
  emergency_phone VARCHAR(30),
  department_id INT,
  position_id INT,
  supervisor_id INT NULL,
  employment_type ENUM('Full-time','Part-time','Contract','Probation'),
  start_date DATE,
  end_date DATE NULL,
  work_location VARCHAR(120),
  shift VARCHAR(50),
  status ENUM('Active','On Leave','Suspended','Terminated','Probation') DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  category ENUM('Performance','Attendance','Behavior','Warning','Meeting','Training','General','Other') DEFAULT 'General',
  note TEXT NOT NULL,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disciplinary_cases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  issue TEXT NOT NULL,
  severity ENUM('Verbal Warning','Written Warning','Final Warning','Suspension','Other'),
  action_taken TEXT,
  status ENUM('Reported','Under Review','Meeting','Decision','Action','Resolved','Closed') DEFAULT 'Reported',
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  contract_type VARCHAR(50),
  start_date DATE,
  end_date DATE NULL,
  probation_start DATE,
  probation_end DATE,
  renewal_date DATE,
  status ENUM('Active','Expired','Renewed','Ended') DEFAULT 'Active',
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  doc_type VARCHAR(80),
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255),
  uploaded_by INT,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ATTENDANCE MODULE (Step 6)
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  days_allocated INT DEFAULT 0,
  description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  leave_type_id INT,
  start_date DATE,
  end_date DATE,
  days_requested INT,
  reason TEXT,
  status ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
  approved_by INT NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  work_date DATE NOT NULL,
  clock_in DATETIME,
  clock_out DATETIME NULL,
  status ENUM('Present','Absent','Late','Early Departure','On Leave','Overtime','Missing Clock-in','Missing Clock-out') DEFAULT 'Present',
  overtime_minutes INT DEFAULT 0,
  source ENUM('Machine','Manual','App') DEFAULT 'App',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_date (employee_id, work_date),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PLANT LOG MODULE (Step 4)
-- ============================================================
CREATE TABLE IF NOT EXISTS plantlog_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  fields_json TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plantlog_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT,
  operator_id INT NULL,
  work_date DATE,
  shift VARCHAR(50),
  machine VARCHAR(120),
  readings_json TEXT,
  production_units INT DEFAULT 0,
  incident_report TEXT,
  notes TEXT,
  attachments JSON,
  submitted_by INT,
  submitted_at DATETIME,
  department_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (template_id) REFERENCES plantlog_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- CCTV MODULE (Step 7)
-- ============================================================
CREATE TABLE IF NOT EXISTS cameras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  location VARCHAR(120),
  stream_url VARCHAR(255),
  status ENUM('Online','Offline','Maintenance') DEFAULT 'Offline',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cctv_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id INT,
  event_type ENUM('Person Detected','After Hours Entry','Gathering','Vehicle','Intrusion','Motion'),
  confidence DECIMAL(5,2),
  image_path VARCHAR(255),
  detected_at DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cctv_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT,
  alert_level ENUM('Info','Warning','Critical') DEFAULT 'Info',
  message TEXT,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES cctv_events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATIONS / REPORTS / AUDIT (shared)
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  module VARCHAR(50),
  title VARCHAR(255),
  message TEXT,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100),
  module VARCHAR(50),
  details TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150),
  module VARCHAR(50),
  type ENUM('PDF','Excel','CSV') DEFAULT 'PDF',
  file_path VARCHAR(255),
  generated_by INT,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA (roles & admin user)
-- ============================================================
INSERT INTO roles (name, description) VALUES
  ('Super Admin', 'Full system administration'),
  ('Administrator', 'Platform management'),
  ('HR Manager', 'Full HR access + approvals'),
  ('HR Officer', 'HR employee records, attendance, leave, notes'),
  ('Manager', 'Limited employee info and authorized reports'),
  ('Supervisor', 'Plant supervision'),
  ('Operator', 'Plant log data entry'),
  ('Employee', 'Own profile, attendance, leave')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Default admin: username=admin, password=admin123 (bcrypt placeholder updated by app)
INSERT INTO users (username, password_hash, full_name, email, role_id, is_active)
VALUES ('admin', '$2y$10$placeholder_hash_for_admin', 'System Administrator', 'admin@company.com',
  (SELECT id FROM roles WHERE name='Super Admin'), 1)
ON DUPLICATE KEY UPDATE username=username;

-- Default leave types
INSERT INTO leave_types (name, days_allocated, description) VALUES
  ('Annual', 20, 'Annual paid leave'),
  ('Sick', 10, 'Sick leave'),
  ('Maternity', 90, 'Maternity leave'),
  ('Paternity', 10, 'Paternity leave'),
  ('Emergency', 5, 'Emergency leave'),
  ('Other', 5, 'Other approved leave')
ON DUPLICATE KEY UPDATE name=VALUES(name);
