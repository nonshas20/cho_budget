<?php
// process_obligation.php
require 'db_connect.php';

// 1. Capture Form Data
$bucket_id = $_POST['budget_bucket_id'];
$ppa_name  = $_POST['ppa_name'];
$amount    = (float)$_POST['amount'];
$date      = date('Y-m-d'); // Defaults to today

// Calculate current quarter (1, 2, 3, or 4) based on today's date
$current_quarter = ceil(date('n') / 3); 

try {
    // ---------------------------------------------------------
    // MATH PART A: GET THE LIMITS (What are allowed to spend?)
    // ---------------------------------------------------------

    // Get Annual Appropriation Ceiling
    $stmt = $pdo->prepare("SELECT amount FROM appropriations WHERE budget_bucket_id = ?");
    $stmt->execute([$bucket_id]);
    $appropriation_ceiling = $stmt->fetchColumn() ?: 0; // Default to 0 if not set

    // Get Quarterly Allotment
    $stmt = $pdo->prepare("SELECT amount FROM allotments WHERE budget_bucket_id = ? AND quarter = ?");
    $stmt->execute([$bucket_id, $current_quarter]);
    $quarterly_allotment = $stmt->fetchColumn() ?: 0;


    // ---------------------------------------------------------
    // MATH PART B: GET THE HISTORY (What have we spent so far?)
    // ---------------------------------------------------------

    // Get Total Obligations YTD (Year-to-Date)
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM obligations WHERE budget_bucket_id = ?");
    $stmt->execute([$bucket_id]);
    $total_obligations_ytd = $stmt->fetchColumn() ?: 0;

    // Get Total Obligations for THIS Quarter only
    // We filter by checking if the obligation month falls in the current quarter
    $stmt = $pdo->prepare("
        SELECT SUM(amount) FROM obligations 
        WHERE budget_bucket_id = ? 
        AND QUARTER(obligation_date) = ?
    ");
    $stmt->execute([$bucket_id, $current_quarter]);
    $total_obligations_qtr = $stmt->fetchColumn() ?: 0;


    // ---------------------------------------------------------
    // MATH PART C: THE VALIDATION LOGIC
    // ---------------------------------------------------------

    $errors = [];
    $warnings = [];

    // RULE 1: Appropriation Ceiling (HARD STOP)
    // "Obligations across the year must never exceed this ceiling."
    if (($total_obligations_ytd + $amount) > $appropriation_ceiling) {
        $remaining = $appropriation_ceiling - $total_obligations_ytd;
        $errors[] = "CRITICAL: This exceeds the Annual Appropriation! Remaining Ceiling: ₱" . number_format($remaining, 2);
    }

    // RULE 2: Quarterly Allotment (SOFT WARNING)
    // "Obligations can still be logged even if they exceed allotment."
    if (($total_obligations_qtr + $amount) > $quarterly_allotment) {
        $remaining_qtr = $quarterly_allotment - $total_obligations_qtr;
        $diff = ($total_obligations_qtr + $amount) - $quarterly_allotment;
        $warnings[] = "NOTE: You are exceeding the Q{$current_quarter} Allotment by ₱" . number_format($diff, 2);
    }

    // ---------------------------------------------------------
    // EXECUTION
    // ---------------------------------------------------------

    if (!empty($errors)) {
        // If there are CRITICAL errors, stop and show them.
        echo "<div style='color: red; padding: 20px; border: 1px solid red; background: #ffe6e6;'>";
        echo "<h3>Transaction Blocked</h3>";
        foreach ($errors as $e) echo "<p>$e</p>";
        echo "<a href='create_obligation.php'>Go Back</a>";
        echo "</div>";
        exit;
    }

    // If no critical errors, SAVE DATA
    $sql = "INSERT INTO obligations (budget_bucket_id, ppa_name, amount, obligation_date, created_by) VALUES (?, ?, ?, ?, 1)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bucket_id, $ppa_name, $amount, $date]);

    // Success Message (Include Warnings if any)
    echo "<div style='padding: 20px; font-family: sans-serif;'>";
    echo "<h2 style='color: green;'>Success! Obligation Logged.</h2>";
    
    if (!empty($warnings)) {
        echo "<div style='background: #fff3cd; color: #856404; padding: 15px; border: 1px solid #ffeeba;'>";
        echo "<strong>System Notice:</strong><br>";
        foreach ($warnings as $w) echo "$w<br>";
        echo "</div>";
    }

    echo "<br><a href='create_obligation.php'>Log Another</a> | <a href='dashboard.php'>View Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
}
?>