<?php
$pdo = new PDO('mysql:host=localhost;dbname=cho_budget_db;charset=utf8', 'root', '');
$stmt = $pdo->query('DESCRIBE facilities');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
