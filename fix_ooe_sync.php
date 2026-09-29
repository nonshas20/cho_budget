<?php
require 'includes/db_connect.php';
$pdo_aop = new PDO("mysql:host=localhost;dbname=cho_aop_db;charset=utf8mb4", 'root', '');

// Fetch OOE map correctly
$ooe_map = [];
$ooes = $pdo->query("SELECT id, name FROM ooes")->fetchAll();
foreach ($ooes as $o) {
    $n = strtoupper($o['name']);
    if (strpos($n, 'PERSONAL') !== false || $n === 'PS') $ooe_map['PS'] = $o['id'];
    elseif (strpos($n, 'CAPITAL') !== false || $n === 'CO') $ooe_map['CO'] = $o['id'];
    elseif (strpos($n, 'MAINTENANCE') !== false || $n === 'MOOE') $ooe_map['MOOE'] = $o['id'];
}

$count = 0;
// Go through all AOP items
$sql = "SELECT p.id as aop_ppa_id, p.tracking_code 
        FROM ppas p 
        WHERE p.tracking_code IS NOT NULL AND p.status = 'Approved'";
$programs = $pdo_aop->query($sql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($programs as $prog) {
    $aop_ppa_id = $prog['aop_ppa_id'];
    
    $items_sql = "SELECT COALESCE(bli.object_code, '') AS object_code,
                         COALESCE(bli.description, li.custom_item_name) AS description,
                         COALESCE(bli.expense_type, 'MOOE') AS expense_type
                  FROM line_items li
                  LEFT JOIN budget_line_items bli ON li.budget_line_item_id = bli.id
                  WHERE li.ppa_id = ?
                  GROUP BY object_code, description, expense_type";
    $stmt = $pdo_aop->prepare($items_sql);
    $stmt->execute([$aop_ppa_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        $code = trim($item['object_code']);
        $desc = trim($item['description']);
        $type = strtoupper(trim($item['expense_type']));
        $correct_ooe_id = $ooe_map[$type] ?? ($ooe_map['MOOE'] ?? 1);
        
        if ($code) {
            $li_stmt = $pdo->prepare("SELECT id FROM line_items WHERE account_code = ? AND name = ?");
            $li_stmt->execute([$code, $desc]);
        } else {
            $li_stmt = $pdo->prepare("SELECT id FROM line_items WHERE name = ? AND (account_code IS NULL OR account_code = '')");
            $li_stmt->execute([$desc]);
        }
        $line_item_id = $li_stmt->fetchColumn();
        
        if ($line_item_id) {
            $upd = $pdo->prepare("UPDATE line_items SET ooe_id = ? WHERE id = ?");
            $upd->execute([$correct_ooe_id, $line_item_id]);
            $count++;
        }
    }
}
echo "Fixed $count line items based on correct OOE mapping.\n";
?>
