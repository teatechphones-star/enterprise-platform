<?php
// ============================================================
// Authentication & Authorization Service
// ============================================================
class Auth
{
    /** Attempt to log in; returns true on success. */
    public static function login($username, $password)
    {
        $db = Database::getInstance();
        $user = $db->fetch(
            "SELECT u.*, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.username = :u AND u.status = 'active'",
            ['u' => $username]
        );
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['role_id'] = $user['role_id'];

        // create session token record
        $token = bin2hex(random_bytes(32));
        $db->query(
            "INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at)
             VALUES (:u, :t, :ip, :ua, DATE_ADD(NOW(), INTERVAL 12 HOUR))",
            [
                'u' => $user['id'],
                't' => $token,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            ]
        );
        $_SESSION['token'] = $token;

        $db->query("UPDATE users SET last_login = NOW() WHERE id = :id", ['id' => $user['id']]);
        return true;
    }

    /** Check if the current user is logged in. */
    public static function check()
    {
        return isset($_SESSION['user_id']);
    }

    /** Return current user id or null. */
    public static function id()
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function user()
    {
        if (!self::check()) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role' => $_SESSION['role'],
        ];
    }

    /** Ensure the user is logged in, else redirect to login. */
    public static function requireLogin()
    {
        if (!self::check()) {
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    }

    /** Ensure user has a given role. */
    public static function requireRole($roles)
    {
        self::requireLogin();
        $roles = is_array($roles) ? $roles : [$roles];
        if (!in_array($_SESSION['role'], $roles)) {
            http_response_code(403);
            die('403 Forbidden - You do not have permission to view this page.');
        }
    }

    /** Logout: destroy session. */
    public static function logout()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Log an audit event. */
    public static function audit($module, $action, $details = '')
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO audit_logs (user_id, action, module, details, ip_address)
             VALUES (:u, :a, :m, :d, :ip)",
            [
                'u' => self::id(),
                'a' => $action,
                'm' => $module,
                'd' => $details,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]
        );
    }
}
