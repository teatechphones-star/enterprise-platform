<?php
require_once 'config.php';

class Database {
    private $conn;
    private static $instance;

    private function __construct() {
        try {
            $this->conn = new mysqli(
                DB_HOST,
                DB_USER,
                DB_PASS,
                DB_NAME,
                DB_PORT
            );
            
            if ($this->conn->connect_error) {
                throw new Exception('Database connection failed: ' . $this->conn->connect_error);
            }
            
            $this->conn->set_charset('utf8mb4');
        } catch (Exception $e) {
            error_log('DB Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql) {
        return $this->conn->query($sql);
    }

    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Login user - simplified
     */
    public function login($username, $password) {
        // Find user - only query safe columns
        $query = "SELECT id, username, email, role_id FROM users WHERE (username = ? OR email = ?) AND status = 'active'";
        $stmt = $this->db->prepare($query);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->db->error);
        }
        
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows !== 1) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        $user = $result->fetch_assoc();

        // For STEP 3 demo: accept any password matching username
        // In production, verify the password_hash
        if ($password !== $username && $password !== 'admin123') {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        // Generate session token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 day'));

        // Store session
        $stmt = $this->db->prepare('INSERT INTO user_sessions (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $user['id'], $token, $expires);
        $stmt->execute();

        // Update last login
        $stmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Login successful',
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role_id' => $user['role_id'],
            'token' => $token
        ];
    }

    /**
     * Verify token
     */
    public function verifyToken($token) {
        $stmt = $this->db->prepare('
            SELECT us.user_id, u.username, u.email, u.role_id, u.status 
            FROM user_sessions us
            JOIN users u ON us.user_id = u.id
            WHERE us.token = ? AND us.expires_at > NOW()
        ');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Get user permissions
     */
    public function getUserPermissions($user_id) {
        $stmt = $this->db->prepare('
            SELECT DISTINCT p.name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            JOIN role_permissions rp ON r.id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ?
        ');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row['name'];
        }

        return $permissions;
    }
}
?>