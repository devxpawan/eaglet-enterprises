<?php
require_once __DIR__ . '/../config/paths.php';

date_default_timezone_set('Asia/Colombo');

// Detect environment
if ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1') {
    // Local Database
    $servername = 'localhost';
    $username   = 'root';
    $password   = '';
    $dbname     = 'bms';
} else {
    // Live Database
    $servername = 'localhost';
    $username   = 'eaglete1_admin';
    $password   = 'eaglet@21';
    $dbname     = 'eaglete1_bms';
}

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>