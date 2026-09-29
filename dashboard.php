<?php
// 1. START SESSION & DB
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 2. FILTERS & DATA FETCHING
// ---------------------------------------------------------
$curr_year = date('Y');
$f_year   = $_GET['year'] ?? $curr_year;
$hide_zero = isset($_GET['hide_zero']) ? true : false;

// Handle Array Inputs
$f_fund  = $_GET['fund'] ?? []; 
$f_ooe   = $_GET['ooe'] ?? [];
$f_items = $_GET['items'] ?? []; 

if (!is_array($f_fund)) $f_fund = ($f_fund == 'all' || empty($f_fund)) ? [] : [$f_fund];
if (!is_array($f_ooe))  $f_ooe  = ($f_ooe == 'all'  || empty($f_ooe))  ? [] : [$f_ooe];
if (!is_array($f_items)) $f_items = empty($f_items) ? [] : [$f_items];

$f_fund = array_diff($f_fund, ['all']);
$f_ooe  = array_diff($f_ooe, ['all']);

// ---------------------------------------------------------
// 3. BUILD MAIN QUERY
// ---------------------------------------------------------
$params = [$f_year];
$where = ["bb.fiscal_year = ?"];

if (!empty($f_fund)) { 
    $in  = str_repeat('?,', count($f_fund) - 1) . '?';
    $where[] = "bb.fund_source_id IN ($in)";
    $params = array_merge($params, $f_fund);
}
if (!empty($f_ooe)) {
    $in  = str_repeat('?,', count($f_ooe) - 1) . '?';
    $where[] = "li.ooe_id IN ($in)";
    $params = array_merge($params, $f_ooe);
}
if (!empty($f_items)) {
    $in  = str_repeat('?,', count($f_items) - 1) . '?';
    $where[] = "li.id IN ($in)";
    $params = array_merge($params, $f_items);
}

$f_search = $_GET['search'] ?? '';
if (!empty($f_search)) { $where[] = "li.name LIKE ?"; $params[] = "%$f_search%"; }

$where_sql = implode(" AND ", $where);

// MAIN QUERY: Aggregates strictly by Charge Item to allow split-charging visibility
$sql = "SELECT 
            bb.id, bb.fiscal_year, 
            bb.line_item_id, bb.fund_source_id, 
            bb.pct_full, bb.pct_sem1, bb.pct_sem2, bb.pct_q1, bb.pct_q2, bb.pct_q3, bb.pct_q4, bb.pct_reserve,
            bb.q2_released, bb.sem2_released, bb.q3_released, bb.q4_released, bb.reserve_released,
            
            -- [BASE APPROPRIATION]
            COALESCE(app.amount, 0) as saaob,
            
            -- [REALIGNMENT MATH]
            COALESCE((SELECT SUM(amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'destination' AND rr.status = 'approved'), 0) AS transfer_in,
            COALESCE((SELECT SUM(amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'source' AND rr.status = 'approved'), 0) AS transfer_out,
            COALESCE((SELECT SUM(amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'source' AND rr.status = 'pending'), 0) AS pending_transfer_out,
            
            -- [UTILIZED AMOUNT: Sum of specific charge items]
            COALESCE((
                SELECT SUM(
                    CASE 
                        WHEN o.status IN ('liquidated', 'disbursing') THEN COALESCE(ci.disbursed_amount, ci.amount)
                        ELSE ci.amount 
                    END
                ) 
                FROM charge_items ci
                JOIN obligations o ON ci.obligation_id = o.id
                WHERE ci.budget_bucket_id = bb.id
                AND ci.line_item_id = bb.line_item_id
                AND o.status IN ('obligated', 'disbursing', 'liquidated')
            ), 0) as total_utilized,

            -- [UTILIZED COUNT: How many times this item was charged]
            COALESCE((
                SELECT COUNT(DISTINCT o.id) 
                FROM charge_items ci
                JOIN obligations o ON ci.obligation_id = o.id
                WHERE ci.budget_bucket_id = bb.id
                AND ci.line_item_id = bb.line_item_id
                AND o.status IN ('obligated', 'disbursing', 'liquidated')
            ), 0) as util_count,

            -- [PENDING AMOUNT]
            COALESCE((
                SELECT SUM(ci.amount) 
                FROM charge_items ci 
                JOIN obligations o ON ci.obligation_id = o.id
                WHERE ci.budget_bucket_id = bb.id
                AND ci.line_item_id = bb.line_item_id
                AND o.status = 'pending'
            ), 0) as total_pending,

            -- [PENDING COUNT]
            COALESCE((
                SELECT COUNT(DISTINCT o.id) 
                FROM charge_items ci
                JOIN obligations o ON ci.obligation_id = o.id
                WHERE ci.budget_bucket_id = bb.id
                AND ci.line_item_id = bb.line_item_id
                AND o.status = 'pending'
            ), 0) as pending_count,

            li.name as item_name, fs.name as fund_name, o.name as ooe_name, ppa.name as ppa_name
        FROM budget_buckets bb
        JOIN line_items li ON bb.line_item_id = li.id
        JOIN fund_sources fs ON bb.fund_source_id = fs.id
        JOIN ooes o ON li.ooe_id = o.id
        LEFT JOIN budget_ppas ppa ON bb.ppa_id = ppa.id
        LEFT JOIN appropriations app ON bb.id = app.budget_bucket_id
        WHERE $where_sql
        ORDER BY o.name ASC, fs.name ASC, li.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// GLOBAL COUNTS (Accurate Unique PPA Count for Summary Cards)
$count_sql = "SELECT 
    COUNT(DISTINCT CASE WHEN o.status = 'pending' THEN o.id END) as pending_count,
    COUNT(DISTINCT CASE WHEN o.status IN ('obligated', 'disbursing', 'liquidated') THEN o.id END) as util_count,
    COALESCE(SUM(CASE WHEN o.status = 'pending' THEN ci.amount ELSE 0 END), 0) as total_pending_val
    FROM obligations o
    JOIN charge_items ci ON o.id = ci.obligation_id
    JOIN budget_buckets bb ON ci.budget_bucket_id = bb.id
    JOIN line_items li ON bb.line_item_id = li.id
    WHERE $where_sql";
$stmt_c = $pdo->prepare($count_sql);
$stmt_c->execute($params);
$global_counts = $stmt_c->fetch(PDO::FETCH_ASSOC);

// Process Data
$items = [];
$totals = ['saaob'=>0, 'released'=>0, 'utilized'=>0, 'pending'=>0, 'balance'=>0];

foreach ($raw_items as $row) {
    // ---------------------------------------------------------
    // CALCULATE ADJUSTED APPROPRIATION AFTER REALIGNMENT
    // ---------------------------------------------------------
    $base_saaob = $row['saaob'];
    $t_in       = $row['transfer_in'];
    $t_out      = $row['transfer_out'];
    $p_out      = $row['pending_transfer_out']; // Important: Lock this money away!
    
    // The working appropriation for all calculations
    $adj_saaob = $base_saaob + $t_in - $t_out;

    // Calculate quarterly splits and reserve based strictly on the ORIGINAL Base Appropriation
    $val_full = $base_saaob * ($row['pct_full']/100);
    $val_sem1 = $base_saaob * ($row['pct_sem1']/100); $val_sem2 = $base_saaob * ($row['pct_sem2']/100);
    $val_q1   = $base_saaob * ($row['pct_q1']/100);   $val_q2   = $base_saaob * ($row['pct_q2']/100);
    $val_q3   = $base_saaob * ($row['pct_q3']/100);   $val_q4   = $base_saaob * ($row['pct_q4']/100);
    $val_res  = $base_saaob * ($row['pct_reserve']/100);

    $rel_amt = $val_full + $val_sem1 + $val_q1; 
    if(($row['q2_released']??0)==1) $rel_amt += $val_q2;
    if(($row['sem2_released']??0)==1) $rel_amt += $val_sem2;
    if(($row['q3_released']??0)==1) $rel_amt += $val_q3;
    if(($row['q4_released']??0)==1) $rel_amt += $val_q4;
    if(($row['reserve_released']??0)==1) $rel_amt += $val_res;

    // Apply realignments DIRECTLY to released amount (100% of Transfer In adds to Available)
    $rel_amt = $rel_amt + $t_in - $t_out;

    // THE TRUE BALANCE = Released - Utilized - PENDING Transfers Out
    $bal = $rel_amt - $row['total_utilized'] - $p_out;
    
    if ($hide_zero && abs($bal) < 0.01) continue;

    $totals['saaob'] += $adj_saaob; 
    $totals['released'] += $rel_amt; 
    $totals['utilized'] += $row['total_utilized'];
    $totals['pending'] += $row['total_pending']; 
    $totals['balance'] += $bal;

    // Store calculated data back into array for HTML rendering
    $row['adj_saaob'] = $adj_saaob;
    $row['v_full'] = $val_full; $row['v_s1'] = $val_sem1; $row['v_s2'] = $val_sem2;
    $row['v_q1'] = $val_q1; $row['v_q2'] = $val_q2; $row['v_q3'] = $val_q3; $row['v_q4'] = $val_q4; $row['v_res'] = $val_res;
    $row['calc_released'] = $rel_amt; 
    $row['calc_balance'] = $bal;
    
    $row['html_s2']  = ($row['sem2_released']??0) ? '<i class="bi bi-unlock-fill text-success ms-1 small"></i>' : '<i class="bi bi-lock-fill text-danger ms-1 small"></i>';
    $row['html_q2']  = ($row['q2_released']??0)   ? '<i class="bi bi-unlock-fill text-success ms-1 small"></i>' : '<i class="bi bi-lock-fill text-danger ms-1 small"></i>';
    $row['html_q3']  = ($row['q3_released']??0)   ? '<i class="bi bi-unlock-fill text-success ms-1 small"></i>' : '<i class="bi bi-lock-fill text-danger ms-1 small"></i>';
    $row['html_q4']  = ($row['q4_released']??0)   ? '<i class="bi bi-unlock-fill text-success ms-1 small"></i>' : '<i class="bi bi-lock-fill text-danger ms-1 small"></i>';
    $row['html_res'] = ($row['reserve_released']??0) ? '<i class="bi bi-unlock-fill text-success ms-1 small"></i>' : '<i class="bi bi-lock-fill text-danger ms-1 small"></i>';

    $items[] = $row;
}

// DROPDOWNS
$years = $pdo->query("SELECT year FROM fiscal_years ORDER BY year DESC")->fetchAll();
$funds = $pdo->query("SELECT * FROM fund_sources")->fetchAll();
$ooes = $pdo->query("SELECT * FROM ooes")->fetchAll();

// SMART ITEM FILTER
$item_where = ["bb.fiscal_year = ?"];
$item_params = [$f_year];
if (!empty($f_fund)) {
    $in = str_repeat('?,', count($f_fund) - 1) . '?';
    $item_where[] = "bb.fund_source_id IN ($in)";
    $item_params = array_merge($item_params, $f_fund);
}
if (!empty($f_ooe)) {
    $in = str_repeat('?,', count($f_ooe) - 1) . '?';
    $item_where[] = "li.ooe_id IN ($in)";
    $item_params = array_merge($item_params, $f_ooe);
}
$sql_items_picker = "SELECT DISTINCT li.id, li.name FROM line_items li JOIN budget_buckets bb ON li.id = bb.line_item_id WHERE " . implode(" AND ", $item_where) . " ORDER BY li.name ASC";
$stmt_ip = $pdo->prepare($sql_items_picker);
$stmt_ip->execute($item_params);
$available_items = $stmt_ip->fetchAll(PDO::FETCH_ASSOC);

// Export Handler
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $filename = "Budget_Matrix_" . date('Y-m-d');
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fund', 'OOE', 'Item', 'Appropriation', 'Released', 'Utilized (Amt)', '# Charges', 'Pending (Amt)', '# Charges', 'Balance']);
    foreach ($items as $r) fputcsv($out, [$r['fund_name'], $r['ooe_name'], $r['item_name'], $r['adj_saaob'], $r['calc_released'], $r['total_utilized'], $r['util_count'], $r['total_pending'], $r['pending_count'], $r['calc_balance']]);
    fclose($out); exit;
}

?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Roboto+Condensed:wght@400;500;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; }
    .data-font { font-family: 'Roboto Condensed', sans-serif; font-size: 0.9rem; }
    .num-cell { font-family: 'Roboto Condensed', monospace; white-space: nowrap; font-weight: 500; }
    .text-truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.2; }
    
    .select2-container .select2-selection--single, .select2-container .select2-selection--multiple {
        height: 38px !important; border: 1px solid #ced4da; border-radius: 0.25rem;
    }
    .select2-container .select2-selection--multiple { min-height: 38px; height: auto !important; }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #0d6efd; color: white; border: none; font-size: 0.85rem; margin-top: 6px;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: white; border-right: 1px solid #0b5ed7;
    }
    
    @media print { .no-print, .top-cards, .filter-bar, .print-hide { display: none !important; } .card { border: none !important; box-shadow: none !important; } .table { width: 100%; border: 1px solid #000; } }
</style>

<?php
$page_title = 'Executive Dashboard';
require 'includes/header.php';

function get_acronym($name) {
    $clean = trim(strtolower($name));
    $map = [
        'general fund' => 'GF',
        'special health fund' => 'SHF',
        'trust fund' => 'TF',
        'gender and development fund' => 'GAD',
        'national tax allotment' => 'NTA',
        'disaster risk reduction and management fund' => 'DRRMF',
        'disaster risk reduction management fund' => 'DRRMF'
    ];
    
    if (isset($map[$clean])) return $map[$clean];
    
    $words = explode(' ', $name);
    $acronym = '';
    foreach ($words as $w) {
        if (strtolower($w) !== 'and' && preg_match('/[a-zA-Z]/', $w)) {
            $acronym .= strtoupper($w[0]);
        }
    }
    return $acronym ?: strtoupper(substr($name, 0, 3));
}
?>

<div class="container-fluid px-4 mt-4">

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card shadow-sm bg-warning bg-opacity-10 border-warning border-start border-4 h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="small fw-bold text-uppercase text-dark opacity-75">Pending PPA Requests</div><div class="h2 fw-bold text-dark mb-0"><?php echo number_format($global_counts['pending_count']); ?></div></div>
                        <div class="text-end"><div class="h5 fw-bold text-dark mb-0">₱ <?php echo number_format($global_counts['total_pending_val'], 2); ?></div><div class="small text-muted">Total Pending Amount</div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm bg-white border-primary border-start border-4 h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="small fw-bold text-uppercase text-primary">Ongoing / Utilized PPAs</div><div class="h2 fw-bold text-dark mb-0"><?php echo number_format($global_counts['util_count']); ?></div></div>
                        <div class="text-end"><i class="bi bi-briefcase-fill text-primary h3 opacity-50"></i><div class="small text-muted">Active, Disbursing, Liquidated</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" style="border-bottom: 2px solid #dee2e6;">
        <li class="nav-item">
            <a class="nav-link <?php echo empty($f_fund) ? 'active fw-bold border-bottom-0' : 'text-muted'; ?>" 
               href="?<?php echo http_build_query(array_merge($_GET, ['fund' => []])); ?>">
               Global Summary
            </a>
        </li>
        <?php foreach($funds as $f): 
            $is_active = (count($f_fund) == 1 && in_array($f['id'], $f_fund));
        ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $is_active ? 'active fw-bold border-bottom-0' : 'text-muted'; ?>" 
               href="?<?php echo http_build_query(array_merge($_GET, ['fund' => [$f['id']]])); ?>">
               <?php echo htmlspecialchars($f['acronym'] ?? get_acronym($f['name'])); ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="row g-3 mb-4 top-cards">
        <div class="col-md-3"><div class="card shadow-sm border-primary border-3 h-100"><div class="card-body p-3"><div class="text-primary fw-bold small text-uppercase mb-1" style="font-family: 'Roboto Condensed'">Total Appropriation (Adj.)</div><div class="h3 fw-bold text-dark mb-0 num-cell">₱ <?php echo number_format($totals['saaob'], 2); ?></div><div class="small text-muted mt-1">FY <?php echo $f_year; ?></div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-info border-3 h-100"><div class="card-body p-3"><div class="text-info fw-bold small text-uppercase mb-1" style="font-family: 'Roboto Condensed'">Released (YTD)</div><div class="h3 fw-bold text-dark mb-0 num-cell">₱ <?php echo number_format($totals['released'], 2); ?></div><div class="small text-muted mt-1">As of <?php echo date('M d, Y'); ?></div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-danger border-3 h-100"><div class="card-body p-3"><div class="text-danger fw-bold small text-uppercase mb-1" style="font-family: 'Roboto Condensed'">Utilized (Act+Liq)</div><div class="h3 fw-bold text-danger mb-0 num-cell">₱ <?php echo number_format($totals['utilized'], 2); ?></div><div class="small text-muted mt-1"><?php echo ($totals['released']>0) ? round(($totals['utilized']/$totals['released'])*100,1) : 0; ?>% Utilization</div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm border-success border-3 h-100"><div class="card-body p-3"><div class="text-success fw-bold small text-uppercase mb-1" style="font-family: 'Roboto Condensed'">Balance</div><div class="h3 fw-bold text-success mb-0 num-cell">₱ <?php echo number_format($totals['balance'], 2); ?></div><div class="small text-muted mt-1">Available for Charging</div></div></div></div>
    </div>

    <div class="card shadow-sm mb-3 bg-white border-0 filter-bar">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-start">
                <div class="col-md-1"><label class="form-label small fw-bold mb-0 text-muted">Year</label><select name="year" class="form-select form-select-sm fw-bold"><?php foreach($years as $y): ?><option value="<?php echo $y['year']; ?>" <?php echo $y['year']==$f_year?'selected':''; ?>><?php echo $y['year']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label small fw-bold mb-0 text-muted">1. Fund Source</label><select name="fund[]" class="form-select select2-multi" multiple="multiple" data-placeholder="All Funds"><?php foreach($funds as $f): ?><option value="<?php echo $f['id']; ?>" <?php echo in_array($f['id'], $f_fund) ? 'selected' : ''; ?>><?php echo $f['name']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label small fw-bold mb-0 text-muted">2. OOE Class</label><select name="ooe[]" class="form-select select2-multi" multiple="multiple" data-placeholder="All Classes"><?php foreach($ooes as $o): ?><option value="<?php echo $o['id']; ?>" <?php echo in_array($o['id'], $f_ooe) ? 'selected' : ''; ?>><?php echo $o['name']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label small fw-bold mb-0 text-muted">3. Specific Items</label><select name="items[]" class="form-select select2-multi" multiple="multiple" data-placeholder="Search Items"><?php foreach($available_items as $itm): ?><option value="<?php echo $itm['id']; ?>" <?php echo in_array($itm['id'], $f_items) ? 'selected' : ''; ?>><?php echo $itm['name']; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="hide_zero" id="hideZero" value="1" <?php echo $hide_zero ? 'checked' : ''; ?>><label class="form-check-label small text-muted" for="hideZero">Hide Zero Balance</label></div></div>
                <div class="col-md-2 pt-3 text-end d-flex gap-2 justify-content-end"><button type="submit" class="btn btn-primary btn-sm px-3 fw-bold"><i class="bi bi-filter"></i> Apply</button><a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Reset</a><div class="btn-group btn-group-sm"><button type="button" onclick="window.print()" class="btn btn-outline-dark" title="Print"><i class="bi bi-printer"></i></button><?php $q = http_build_query(array_diff_key($_GET, array('export' => ''))); ?><a href="dashboard.php?<?php echo $q; ?>&export=csv" class="btn btn-success text-white" title="Export CSV"><i class="bi bi-file-earmark-spreadsheet"></i></a></div></div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0 mb-5">
        <div class="card-header bg-white py-2 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-primary" style="font-family: 'Roboto Condensed'">BUDGET EXECUTION MATRIX</h6>
            <span class="badge bg-light text-muted border">YTD: <?php echo date('M Y'); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-hover mb-0 align-middle text-center data-font">
                    <thead class="table-dark text-white align-middle">
                        <tr>
                            <th rowspan="2" style="width: 4%;">Fund</th>
                            <th rowspan="2" class="text-start" style="width: 18%;">Item</th> 
                            <th rowspan="2" style="width: 8%;">Adj. Approp.</th>
                            <th colspan="4" class="bg-secondary text-uppercase small" style="letter-spacing: 0.5px;">Allotment Release</th>
                            <th rowspan="2" style="width: 6%;">Reserve</th>
                            <th rowspan="2" class="bg-primary bg-gradient text-white" style="width: 9%;">Released</th>
                            <th rowspan="2" class="bg-danger bg-gradient text-white" style="width: 10%;">Utilized *</th>
                            <th rowspan="2" class="bg-danger bg-gradient text-white" style="width: 3%;" title="# Charges">#</th>
                            <th rowspan="2" class="bg-warning bg-opacity-75 text-dark" style="width: 10%;">Pending</th>
                            <th rowspan="2" class="bg-warning bg-opacity-75 text-dark" style="width: 3%;" title="# Charges">#</th>
                            <th rowspan="2" class="bg-success bg-gradient text-white" style="width: 10%;">Balance</th>
                        </tr>
                        <tr class="table-secondary text-dark small">
                            <th style="width: 5%;">Q1</th><th style="width: 5%;">Q2</th><th style="width: 5%;">Q3</th><th style="width: 5%;">Q4</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $curr_group = '';
                        if (count($items) > 0):
                            foreach ($items as $row): 
                                if ($curr_group != $row['ooe_name']) {
                                    $curr_group = $row['ooe_name'];
                                    echo "<tr class='table-light border-bottom border-secondary'><td colspan='15' class='text-start fw-bold text-uppercase ps-3 py-2 bg-light text-dark' style='font-family: Inter; letter-spacing: 0.5px;'><i class='bi bi-folder2-open me-2'></i> $curr_group</td></tr>";
                                }
                        ?>
                                <tr>
                                    <td><span class="badge bg-secondary text-white border-white" style="font-weight: 500;"><?php echo get_acronym($row['fund_name']); ?></span></td>
                                    
                                    <td class="text-start fw-bold text-dark ps-2" title="<?php echo htmlspecialchars($row['item_name']); ?>">
                                        <?php if (!empty($row['ppa_name'])): ?>
                                            <div class="small text-muted text-uppercase fw-normal mb-1" style="font-size: 0.65rem; line-height: 1;"><i class="bi bi-tag-fill me-1"></i> <?php echo htmlspecialchars($row['ppa_name']); ?></div>
                                        <?php endif; ?>
                                        <div class="text-truncate-2"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                    </td>
                                    
                                    <td class="num-cell text-end text-muted">
                                        ₱ <?php echo number_format($row['adj_saaob'], 2); ?>
                                        <?php if($row['transfer_in'] > 0 || $row['transfer_out'] > 0 || $row['pending_transfer_out'] > 0): ?>
                                            <br><span style="font-size: 0.65rem;" class="text-primary fw-normal" title="Base: ₱ <?php echo number_format($row['saaob'], 2); ?> | In: +₱ <?php echo number_format($row['transfer_in'], 2); ?> | Out: -₱ <?php echo number_format($row['transfer_out'], 2); ?>">(Realigned)</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <?php if ($row['pct_full'] > 0): ?>
                                        <td colspan="4" class="bg-info bg-opacity-10 text-dark num-cell small"><?php echo number_format($row['v_full'], 2); ?></td>
                                    <?php else: ?>
                                        <?php if ($row['pct_sem1'] > 0): ?>
                                            <td colspan="2" class="text-muted num-cell small"><?php echo number_format($row['v_s1'], 2); ?></td>
                                        <?php else: ?>
                                            <td class="text-muted small num-cell"><?php echo ($row['v_q1'] > 0) ? number_format($row['v_q1']/1000, 1).'k' : '-'; ?></td>
                                            <td class="text-muted small num-cell"><?php echo ($row['v_q2'] > 0) ? number_format($row['v_q2']/1000, 1).'k' . $row['html_q2'] : '-'; ?></td>
                                        <?php endif; ?>
                                        <?php if ($row['pct_sem2'] > 0): ?>
                                            <td colspan="2" class="text-muted num-cell small"><?php echo number_format($row['v_s2'], 2) . $row['html_s2']; ?></td>
                                        <?php else: ?>
                                            <td class="text-muted small num-cell"><?php echo ($row['v_q3'] > 0) ? number_format($row['v_q3']/1000, 1).'k' . $row['html_q3'] : '-'; ?></td>
                                            <td class="text-muted small num-cell"><?php echo ($row['v_q4'] > 0) ? number_format($row['v_q4']/1000, 1).'k' . $row['html_q4'] : '-'; ?></td>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <td class="text-secondary small border-start num-cell"><?php echo ($row['v_res'] > 0) ? number_format($row['v_res'], 2) . $row['html_res'] : '-'; ?></td>
                                    <td class="bg-primary bg-opacity-10 fw-bold text-primary num-cell text-end">₱ <?php echo number_format($row['calc_released'], 2); ?></td>
                                    
                                    <td class="bg-danger bg-opacity-10 fw-bold text-danger num-cell text-end">
                                        <a href="view_obligations.php?line_item_id=<?php echo $row['line_item_id']; ?>&fund_id=<?php echo $row['fund_source_id']; ?>&status=utilized" 
                                           class="text-danger text-decoration-none">
                                            ₱ <?php echo number_format($row['total_utilized'], 2); ?>
                                        </a>
                                    </td>
                                    
                                    <td class="bg-danger bg-opacity-25 fw-bold p-0 align-middle">
                                        <?php if ($row['util_count'] > 0): ?>
                                            <a href="view_obligations.php?line_item_id=<?php echo $row['line_item_id']; ?>&fund_id=<?php echo $row['fund_source_id']; ?>&status=utilized" 
                                               class="d-block w-100 h-100 text-danger text-decoration-none py-1" title="Charges: <?php echo $row['util_count']; ?>">
                                                <?php echo $row['util_count']; ?>
                                            </a>
                                        <?php else: ?><span class="d-block py-1 text-muted opacity-50">0</span><?php endif; ?>
                                    </td>

                                    <td class="bg-warning bg-opacity-10 fw-bold text-dark num-cell text-end" style="color: #856404;">
                                        <a href="view_obligations.php?line_item_id=<?php echo $row['line_item_id']; ?>&fund_id=<?php echo $row['fund_source_id']; ?>&status=pending" 
                                           class="text-dark text-decoration-none" style="color: #856404;">
                                            ₱ <?php echo number_format($row['total_pending'], 2); ?>
                                        </a>
                                    </td>

                                    <td class="bg-warning bg-opacity-25 fw-bold p-0 align-middle">
                                        <?php if ($row['pending_count'] > 0): ?>
                                            <a href="view_obligations.php?line_item_id=<?php echo $row['line_item_id']; ?>&fund_id=<?php echo $row['fund_source_id']; ?>&status=pending" 
                                               class="d-block w-100 h-100 text-dark text-decoration-none py-1" style="color: #856404;" title="Charges: <?php echo $row['pending_count']; ?>">
                                                <?php echo $row['pending_count']; ?>
                                            </a>
                                        <?php else: ?><span class="d-block py-1 text-muted opacity-50">0</span><?php endif; ?>
                                    </td>

                                    <?php 
                                        // Dynamic color: Red if negative, Green if positive
                                        $bal_color = ($row['calc_balance'] < 0) ? 'bg-danger' : 'bg-success'; 
                                    ?>
                                    <td class="<?php echo $bal_color; ?> bg-gradient text-white fw-bold num-cell text-end pe-2">
                                        ₱ <?php echo number_format($row['calc_balance'], 2); ?>
                                        <?php if($row['pending_transfer_out'] > 0): ?>
                                            <div style="font-size: 0.65rem;" class="fw-normal text-white-50 mt-1" title="₱ <?php echo number_format($row['pending_transfer_out'], 2); ?> is locked in a pending realignment request.">
                                                (Locked: -₱ <?php echo number_format($row['pending_transfer_out'], 2); ?>)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="15" class="text-center py-5 text-muted">No records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2-multi').select2({
            width: '100%',
            allowClear: true,
            closeOnSelect: false
        });
    });
</script>

<?php require 'includes/footer.php'; ?>