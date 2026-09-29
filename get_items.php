<?php
require 'includes/db_connect.php'; // Connect to DB

header('Content-Type: application/json'); // Tell browser we are sending JSON data

// 1. Receive the Year and Fund ID from the request
$fund_id = isset($_GET['fund_id']) ? $_GET['fund_id'] : '';
$year    = isset($_GET['year']) ? $_GET['year'] : '';

// 2. If data is missing, return nothing
if (empty($fund_id) || empty($year)) {
    echo json_encode([]);
    exit;
}

try {
    // 3. Query the Database
    // We select the 'budget_buckets.id' because that's what we need to save later.
    // We join tables to get the readable names (e.g., "Drugs and Medicines")
    $sql = "SELECT 
                bb.id as bucket_id, 
                li.name as line_name, 
                o.name as ooe_name
            FROM budget_buckets bb
            JOIN line_items li ON bb.line_item_id = li.id
            JOIN ooes o ON li.ooe_id = o.id
            WHERE bb.fund_source_id = :fund_id 
              AND bb.fiscal_year = :year
            ORDER BY li.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fund_id' => $fund_id, ':year' => $year]);
    
    // 4. Send the results back to the browser
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($items);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>