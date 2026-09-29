<?php
require 'includes/db_connect.php';

echo "<h2>Database Migration for CHO Budget</h2>";

try {
    // 1. Check if budget_bucket_id exists in charge_items
    $stmt = $pdo->query("SHOW COLUMNS FROM charge_items LIKE 'budget_bucket_id'");
    if ($stmt->rowCount() == 0) {
        echo "Adding `budget_bucket_id` column to `charge_items`...<br>";
        $pdo->exec("ALTER TABLE charge_items ADD COLUMN budget_bucket_id INT DEFAULT NULL AFTER obligation_id");
        echo "<span style='color:green;'>Success: Added `budget_bucket_id` column.</span><br><br>";

        echo "Backfilling data for existing charge items...<br>";
        // Update existing charge_items to inherit the budget_bucket_id from their parent obligation
        $updated = $pdo->exec("UPDATE charge_items ci 
                               JOIN obligations o ON ci.obligation_id = o.id 
                               SET ci.budget_bucket_id = o.budget_bucket_id 
                               WHERE ci.budget_bucket_id IS NULL");
        echo "<span style='color:green;'>Success: Backfilled $updated existing records.</span><br><br>";
    } else {
        echo "<span style='color:blue;'>Info: `budget_bucket_id` already exists in `charge_items`.</span><br><br>";
    }

    echo "<strong>Migration completed! You can now safely use the dashboard.</strong>";

} catch (Exception $e) {
    echo "<span style='color:red;'>Error during migration: " . htmlspecialchars($e->getMessage()) . "</span>";
}
?>
