<?php
require 'includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { exit('Unauthorized'); }

$f_start = $_GET['start'] ?? date('Y-01-01'); 
$f_end   = $_GET['end']   ?? date('Y-m-t');   
$f_bank  = $_GET['bank_id'] ?? 'all';
$f_fac   = $_GET['facility_id'] ?? 'all';

$w_inf = ["ci.date_received BETWEEN ? AND ?"];   $p_inf = [$f_start, $f_end];
$w_dst = ["bt.transfer_date BETWEEN ? AND ?"];   $p_dst = [$f_start, $f_end];
$w_src = ["bt.transfer_date BETWEEN ? AND ?"];   $p_src = [$f_start, $f_end];
$w_adj = ["badj.adjustment_date BETWEEN ? AND ?"]; $p_adj = [$f_start, $f_end];

if ($f_bank !== 'all') {
    $w_inf[] = "ci.bank_account_id = ?";      $p_inf[] = $f_bank;
    $w_dst[] = "bt.destination_bank_id = ?";  $p_dst[] = $f_bank;
    $w_src[] = "bt.source_bank_id = ?";       $p_src[] = $f_bank;
    $w_adj[] = "badj.bank_account_id = ?";    $p_adj[] = $f_bank;
}

if ($f_fac !== 'all') {
    $w_inf[] = "ci.facility_id = ?";          $p_inf[] = $f_fac;
    $w_dst[] = "1=0"; $w_src[] = "1=0"; $w_adj[] = "1=0"; 
}

$q_inf = "SELECT ci.date_received as trans_date, ba.account_name, fs.name as fund_name, ci.reference_no, ci.official_receipt as or_no, ci.category, ci.particulars, ci.amount, 'Inflow' as trans_type, fac.name as facility_name, fa.accreditation_no FROM cash_inflows ci JOIN bank_accounts ba ON ci.bank_account_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id LEFT JOIN facilities fac ON ci.facility_id = fac.id LEFT JOIN facility_accreditations fa ON ci.accreditation_id = fa.id WHERE " . implode(" AND ", $w_inf);
$q_dst = "SELECT bt.transfer_date as trans_date, ba.account_name, fs.name as fund_name, bt.mov_reference as reference_no, NULL as or_no, 'Transfer In' as category, bt.particulars, bt.amount, 'Transfer' as trans_type, NULL as facility_name, NULL as accreditation_no FROM bank_transfers bt JOIN bank_accounts ba ON bt.destination_bank_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id WHERE " . implode(" AND ", $w_dst);
$q_src = "SELECT bt.transfer_date as trans_date, ba.account_name, fs.name as fund_name, bt.mov_reference as reference_no, NULL as or_no, 'Transfer Out' as category, bt.particulars, -bt.amount as amount, 'Transfer' as trans_type, NULL as facility_name, NULL as accreditation_no FROM bank_transfers bt JOIN bank_accounts ba ON bt.source_bank_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id WHERE " . implode(" AND ", $w_src);
$q_adj = "SELECT badj.adjustment_date as trans_date, ba.account_name, fs.name as fund_name, badj.reference_no, NULL as or_no, badj.category, badj.particulars, IF(badj.type='credit', badj.amount, -badj.amount) as amount, 'Adjustment' as trans_type, NULL as facility_name, NULL as accreditation_no FROM bank_adjustments badj JOIN bank_accounts ba ON badj.bank_account_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id WHERE " . implode(" AND ", $w_adj);

$sql_hist = "$q_inf UNION ALL $q_dst UNION ALL $q_src UNION ALL $q_adj ORDER BY trans_date DESC";
$stmt = $pdo->prepare($sql_hist);
$stmt->execute(array_merge($p_inf, $p_dst, $p_src, $p_adj));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Cash_Ledger_Export_" . date('Ymd') . ".xls");

echo "<table border='1'>";
echo "<tr><th>Date</th><th>Type</th><th>Bank Account</th><th>Fund</th><th>Facility</th><th>Accreditation No</th><th>Ref/ACPN</th><th>OR No</th><th>Category</th><th>Particulars</th><th>Amount</th></tr>";
foreach ($rows as $r) {
    echo "<tr>";
    echo "<td>" . $r['trans_date'] . "</td>";
    echo "<td>" . $r['trans_type'] . "</td>";
    echo "<td>" . htmlspecialchars($r['account_name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['fund_name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['facility_name'] ?? 'N/A') . "</td>";
    echo "<td>" . htmlspecialchars($r['accreditation_no'] ?? 'N/A') . "</td>";
    echo "<td>" . htmlspecialchars($r['reference_no']) . "</td>";
    echo "<td>" . htmlspecialchars($r['or_no'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($r['category']) . "</td>";
    echo "<td>" . htmlspecialchars($r['particulars']) . "</td>";
    echo "<td>" . number_format($r['amount'], 2, '.', '') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>