<?php
// ============================================================
// Shared helper functions
// ============================================================

/** Escape output for HTML. */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Redirect to a path under BASE_URL. */
function redirect($path)
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

/** Return a friendly flash message and clear it. */
function flash($key = 'message')
{
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return '';
}

/** Set a one-time flash message. */
function setFlash($msg, $key = 'message')
{
    $_SESSION[$key] = $msg;
}

/** Highlight/current nav helper. */
function navActive($segment)
{
    $path = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $prefix = str_replace('_', '-', $segment) . '.php';
    return $path === $prefix || $path === 'index.php' && $segment === 'dashboard' ? 'active' : '';
}
