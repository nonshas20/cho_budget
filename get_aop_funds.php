<?php
$pdo = new PDO("mysql:host=localhost;dbname=cho_aop_db;charset=utf8mb4", 'root', '');
$funds = $pdo->query("SELECT DISTINCT fund_source FROM ppas")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($funds, JSON_PRETTY_PRINT);
?>
