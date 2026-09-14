<?php
/**
 * auth_check.php
 * Include this (after db_connect.php, which starts the session) on any
 * page that must only be visible to a logged-in user or admin.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/auth_check.php';
 *   requireLogin('user');   // or requireLogin('admin')
 */

function requireLogin($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        if ($role === 'admin') {
            header('Location: ../auth/admin_login.php');
        } else {
            header('Location: ../auth/user_login.php');
        }
        exit;
    }
}
