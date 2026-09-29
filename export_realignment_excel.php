<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

require 'includes/db_connect.php';

$request_id = $_GET['id'] ?? null;
if (!$request_id) { die("Invalid Request ID"); }

// Fetch Request
$stmt = $pdo->prepare("SELECT * FROM realignment_requests WHERE id = ?");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) { die("Request not found"); }

// Find out the affected Fund Source and OOE
$stmt = $pdo->prepare("
    SELECT bb.fund_source_id, li.ooe_id, fs.name as fund_name, o.name as ooe_name
    FROM realignment_details rd
    JOIN budget_buckets bb ON rd.budget_bucket_id = bb.id
    JOIN line_items li ON bb.line_item_id = li.id
    JOIN fund_sources fs ON bb.fund_source_id = fs.id
    JOIN ooes o ON li.ooe_id = o.id
    WHERE rd.request_id = ?
    LIMIT 1
");
$stmt->execute([$request_id]);
$meta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meta) { die("No items found in this request"); }

$fund_id = $meta['fund_source_id'];
$ooe_id = $meta['ooe_id'];
$fund_name = $meta['fund_name'];
$ooe_name = $meta['ooe_name'];
$fiscal_year = $req['fiscal_year'];

// Get all items in this class to show the full projected balances
$query = "
    SELECT 
        bb.id as bucket_id, li.name as item_name, li.account_code,
        bb.pct_full, bb.pct_sem1, bb.pct_sem2, bb.pct_q1, bb.pct_q2, bb.pct_q3, bb.pct_q4, bb.pct_reserve,
        bb.q2_released, bb.sem2_released, bb.q3_released, bb.q4_released, bb.reserve_released,
        COALESCE(a.amount, 0) as approp,
        COALESCE((SELECT SUM(CASE WHEN o.status IN ('liquidated', 'disbursing') THEN COALESCE(ci.disbursed_amount, ci.amount) ELSE ci.amount END) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = bb.id AND o.status IN ('obligated', 'disbursing', 'liquidated')), 0) as utilized,
        COALESCE((SELECT COUNT(DISTINCT o.id) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = bb.id AND o.status IN ('obligated', 'disbursing', 'liquidated')), 0) as util_count,
        COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'destination' AND rr.status = 'approved'), 0) as transfer_in,
        COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'source' AND rr.status = 'approved'), 0) as transfer_out,
        COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'source' AND rr.status = 'pending' AND rr.id != ?), 0) as pending_transfer_out_other,
        COALESCE((SELECT SUM(ci.amount) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = bb.id AND o.status = 'pending'), 0) as total_pending,
        COALESCE((SELECT COUNT(DISTINCT o.id) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = bb.id AND o.status = 'pending'), 0) as pending_count
    FROM budget_buckets bb
    JOIN line_items li ON bb.line_item_id = li.id
    LEFT JOIN appropriations a ON a.budget_bucket_id = bb.id
    WHERE bb.fiscal_year = ? AND bb.fund_source_id = ? AND li.ooe_id = ?
    ORDER BY li.name
";
$stmt = $pdo->prepare($query);
$stmt->execute([$request_id, $fiscal_year, $fund_id, $ooe_id]);
$buckets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get this specific request's details
$stmt = $pdo->prepare("SELECT type, budget_bucket_id, amount FROM realignment_details WHERE request_id = ?");
$stmt->execute([$request_id]);
$req_details = $stmt->fetchAll(PDO::FETCH_ASSOC);

$req_adjustments = [];
foreach($req_details as $rd) {
    if(!isset($req_adjustments[$rd['budget_bucket_id']])) {
        $req_adjustments[$rd['budget_bucket_id']] = 0;
    }
    if($rd['type'] == 'source') {
        $req_adjustments[$rd['budget_bucket_id']] -= $rd['amount'];
    } else {
        $req_adjustments[$rd['budget_bucket_id']] += $rd['amount'];
    }
}

$rows_html = "";
$total_approp = 0;
$total_released = 0;
$total_utilized = 0;
$total_util_count = 0;
$total_pending_amt = 0;
$total_pending_cnt = 0;
$total_projected = 0;

foreach($buckets as $b) {
    $adj = $req_adjustments[$b['bucket_id']] ?? 0;
    
    // Apply projected realignment to transfers
    $proj_t_in = $b['transfer_in'] + ($adj > 0 ? $adj : 0);
    $proj_t_out = $b['transfer_out'] + ($adj < 0 ? abs($adj) : 0);
    
    $base_saaob = $b['approp'];
    $adj_saaob = $base_saaob + $proj_t_in - $proj_t_out;
    
    $val_full = $base_saaob * ($b['pct_full']/100);
    $val_sem1 = $base_saaob * ($b['pct_sem1']/100);
    $val_q1   = $base_saaob * ($b['pct_q1']/100);
    
    $rel_amt = $val_full + $val_sem1 + $val_q1; 
    if(($b['q2_released']??0)==1) $rel_amt += $base_saaob * ($b['pct_q2']/100);
    if(($b['sem2_released']??0)==1) $rel_amt += $base_saaob * ($b['pct_sem2']/100);
    if(($b['q3_released']??0)==1) $rel_amt += $base_saaob * ($b['pct_q3']/100);
    if(($b['q4_released']??0)==1) $rel_amt += $base_saaob * ($b['pct_q4']/100);
    if(($b['reserve_released']??0)==1) $rel_amt += $base_saaob * ($b['pct_reserve']/100);
    
    $rel_amt = $rel_amt + $proj_t_in - $proj_t_out;
    
    $utilized = $b['utilized'];
    $util_count = $b['util_count'];
    $other_p_out = $b['pending_transfer_out_other'];
    
    $pending_cnt = $b['pending_count'];
    $pending_amt = $b['total_pending'];
    
    // As instructed: Released minus Utilized and other pending transfers out (no pending PPAs included)
    $projected = $rel_amt - $utilized - $other_p_out;

    // Visibility rule: if everything is zero, skip it.
    if (abs($adj_saaob) < 0.01 && abs($rel_amt) < 0.01 && abs($utilized) < 0.01 && abs($adj) < 0.01 && abs($projected) < 0.01 && $pending_cnt == 0 && $util_count == 0) continue;

    $total_approp += $adj_saaob;
    $total_released += $rel_amt;
    $total_utilized += $utilized;
    $total_util_count += $util_count;
    $total_pending_amt += $pending_amt;
    $total_pending_cnt += $pending_cnt;
    $total_projected += $projected;

    $adj_str = ($adj != 0) ? number_format($adj, 2) : "-";
    $adj_color = "";
    if ($adj > 0) {
        $adj_str = "+" . $adj_str;
        $adj_color = "color: green;";
    } elseif ($adj < 0) {
        $adj_color = "color: red;";
    }
    
    $rows_html .= "
        <tr>
            <td>{$b['item_name']}</td>
            <td style='text-align: right;'>" . number_format($adj_saaob, 2) . "</td>
            <td style='text-align: right;'>" . number_format($rel_amt, 2) . "</td>
            <td style='text-align: right;'>" . number_format($utilized, 2) . "</td>
            <td style='text-align: center;'>{$util_count}</td>
            <td style='text-align: right;'>" . number_format($pending_amt, 2) . "</td>
            <td style='text-align: center;'>{$pending_cnt}</td>
            <td style='text-align: right; {$adj_color}'>{$adj_str}</td>
            <td style='text-align: right; font-weight: bold;'>" . number_format($projected, 2) . "</td>
        </tr>
    ";
}

$yr_suffix = substr($fiscal_year, -2);
$padded_id = str_pad($req['id'], 3, '0', STR_PAD_LEFT);
$serialNo = "BRR-{$yr_suffix}-{$padded_id}";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"Projected_Balances_{$serialNo}.xls\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta charset="utf-8">
    <title>Projected Balances</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #dddddd; padding: 8px; }
        th { background-color: #f2f2f2; text-align: center; font-weight: bold; }
        .title { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 10px; }
        .subtitle { font-size: 11pt; text-align: center; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="title">Projected Balances After Realignment</div>
    <div class="subtitle">
        <strong>Request No:</strong> <?php echo $serialNo; ?><br>
        <strong>Fund Source:</strong> <?php echo $fund_name; ?><br>
        <strong>Expense Class:</strong> <?php echo $ooe_name; ?><br>
        <strong>Fiscal Year:</strong> <?php echo $fiscal_year; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 20%;">Item</th>
                <th style="width: 10%;">Appropriation (Projected)</th>
                <th style="width: 10%;">Released (Projected)</th>
                <th style="width: 10%;">Utilized Amount</th>
                <th style="width: 10%;"># of Charges (Utilized)</th>
                <th style="width: 10%;">Pending Amount</th>
                <th style="width: 10%;">Pending PPAs (#)</th>
                <th style="width: 10%;">Realignment Amount</th>
                <th style="width: 10%;">Projected Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php echo $rows_html; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #f9f9f9; font-weight: bold;">
                <td style="text-align: right;">TOTAL:</td>
                <td style="text-align: right;"><?php echo number_format($total_approp, 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($total_released, 2); ?></td>
                <td style="text-align: right;"><?php echo number_format($total_utilized, 2); ?></td>
                <td style="text-align: center;"><?php echo $total_util_count; ?></td>
                <td style="text-align: right;"><?php echo number_format($total_pending_amt, 2); ?></td>
                <td style="text-align: center;"><?php echo $total_pending_cnt; ?></td>
                <td style="text-align: right;">-</td>
                <td style="text-align: right;"><?php echo number_format($total_projected, 2); ?></td>
            </tr>
        </tfoot>
    </table>
    
    <div style="margin-top: 30px;">
        <em>Note: The projected columns factor in this specific realignment request as if it were approved, along with all other approved or pending transactions. Projected Balance does NOT deduct Pending PPAs.</em>
    </div>
</body>
</html>
