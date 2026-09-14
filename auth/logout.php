<?php
require_once __DIR__ . '/../includes/db_connect.php';

// Clear all session data and destroy the session entirely.
$_SESSION = [];
session_destroy();

header('Location: ../index.php');
exit;
