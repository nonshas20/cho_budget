<?php
require 'includes/db_connect.php';

$funds = $pdo->query("SELECT id, name FROM fund_sources")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($funds, JSON_PRETTY_PRINT);
?>
