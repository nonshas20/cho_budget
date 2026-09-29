<?php

define('APP_VERSION', '3.4');

$host = 'localhost';
$db   = 'cho_budget_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // AOP Builder Connection (Read-Only usage)
    $dsn_aop = "mysql:host=$host;dbname=cho_aop_db;charset=$charset";
    $pdo_aop = new PDO($dsn_aop, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// LOGGING FUNCTION (Must be inside the <?php tags)
function log_activity($pdo, $user_id, $user_name, $action, $desc) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action_type, description, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $user_name, $action, $desc, $ip]);
    } catch (Exception $e) {
        // Silently fail logging
    }
}
?>