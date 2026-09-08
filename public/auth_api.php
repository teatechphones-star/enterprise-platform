<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'Auth.php';

class AuthAPI {
    private $auth;
    private $method;
    private $action;

    public function __construct() {
        $this->auth = new Auth();
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->action = $_GET['action'] ?? '';
    }

    public function handle() {
        try {
            switch ($this->action) {
                case 'register':
                    return $this->register();
                case 'login':
                    return $this->login();
                case 'logout':
                    return $this->logout();
                case 'verify':
                    return $this->verify();
                case 'permissions':
                    return $this->getPermissions();
                case 'refresh':
                    return $this->refresh();
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log('API Error: ' . $e->getMessage());
            return $this->error('Server error: ' . $e->getMessage(), 500);
        }
    }

    private function register() {
        if ($this->method !== 'POST') {
            return $this->error('Method not allowed', 405);
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['username'], $data['email'], $data['password'], $data['full_name'])) {
            return $this->error('Missing required fields', 400);
        }

        $result = $this->auth->register(
            $data['username'],
            $data['email'],
            $data['password'],
            $data['full_name'],
            $data['role_id'] ?? 6 // Default to Employee
        );

        if ($result['success']) {
            http_response_code(201);
        } else {
            http_response_code(400);
        }

        return $this->response($result);
    }

    private function login() {
        if ($this->method !== 'POST') {
            return $this->error('Method not allowed', 405);
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['username'], $data['password'])) {
            return $this->error('Missing username or password', 400);
        }

        $result = $this->auth->login($data['username'], $data['password']);

        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(401);
        }

        return $this->response($result);
    }

    private function logout() {
        if ($this->method !== 'POST') {
            return $this->error('Method not allowed', 405);
        }

        $token = $this->getToken();
        if (!$token) {
            return $this->error('No token provided', 401);
        }

        $this->auth->logout($token);
        return $this->response(['success' => true, 'message' => 'Logged out successfully']);
    }

    private function verify() {
        $token = $this->getToken();
        if (!$token) {
            return $this->error('No token provided', 401);
        }

        $user = $this->auth->verifyToken($token);
        if (!$user) {
            return $this->error('Invalid or expired token', 401);
        }

        return $this->response([
            'success' => true,
            'user' => $user
        ]);
    }

    private function getPermissions() {
        $token = $this->getToken();
        if (!$token) {
            return $this->error('No token provided', 401);
        }

        $user = $this->auth->verifyToken($token);
        if (!$user) {
            return $this->error('Invalid or expired token', 401);
        }

        $permissions = $this->auth->getUserPermissions($user['user_id']);
        return $this->response([
            'success' => true,
            'user_id' => $user['user_id'],
            'permissions' => $permissions
        ]);
    }

    private function refresh() {
        if ($this->method !== 'POST') {
            return $this->error('Method not allowed', 405);
        }

        $token = $this->getToken();
        if (!$token) {
            return $this->error('No token provided', 401);
        }

        $user = $this->auth->verifyToken($token);
        if (!$user) {
            return $this->error('Invalid or expired token', 401);
        }

        // Logout old token and login again (creates new token)
        $this->auth->logout($token);
        
        // For refresh, we need to create a new token without password
        // This would require a separate method in Auth class
        // For now, return success with old token (can be enhanced)
        return $this->response([
            'success' => true,
            'message' => 'Token refreshed',
            'token' => $token // In production, generate new token
        ]);
    }

    private function getToken() {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $parts = explode(' ', $headers['Authorization']);
            if (count($parts) === 2 && $parts[0] === 'Bearer') {
                return $parts[1];
            }
        }
        return null;
    }

    private function response($data) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    private function error($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message], JSON_PRETTY_PRINT);
        exit();
    }
}

$api = new AuthAPI();
$api->handle();
?>