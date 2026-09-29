<?php
require 'includes/db_connect.php';
$y = $_GET['year'];
$f = $_GET['fund'];

$stmt = $pdo->prepare("SELECT bb.id, li.name 
                       FROM budget_buckets bb 
                       JOIN line_items li ON bb.line_item_id = li.id 
                       WHERE bb.fiscal_year = ? AND bb.fund_source_id = ?
                       ORDER BY li.name ASC");
$stmt->execute([$y, $f]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>