<?php
require 'includes/db_connect.php';

try {
    echo "Updating bank_transfers table...\n";
    
    // 1. Drop the foreign key constraint
    $pdo->exec("ALTER TABLE bank_transfers DROP FOREIGN KEY fk_transfer_dest");
    
    // 2. Make destination_bank_id nullable
    $pdo->exec("ALTER TABLE bank_transfers MODIFY destination_bank_id INT(11) NULL");
    
    // 3. Add external_destination column
    // We check if it exists first just in case
    $check = $pdo->query("SHOW COLUMNS FROM bank_transfers LIKE 'external_destination'")->fetch();
    if (!$check) {
        $pdo->exec("ALTER TABLE bank_transfers ADD external_destination VARCHAR(255) DEFAULT NULL AFTER destination_bank_id");
    }
    
    // 4. Re-add foreign key on destination_bank_id
    // It is perfectly valid to have a foreign key on a nullable column
    $pdo->exec("ALTER TABLE bank_transfers ADD CONSTRAINT fk_transfer_dest FOREIGN KEY (destination_bank_id) REFERENCES bank_accounts(id) ON DELETE CASCADE");
    
    echo "Successfully updated bank_transfers schema!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
