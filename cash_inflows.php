<?php
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_role  = strtolower($_SESSION['role']);
$can_edit   = in_array($user_role, ['admin', 'encoder']);
$can_delete = ($user_role === 'admin');

$page_title = 'Cash Inflows Ledger';
require 'includes/header.php';

$success_msg = "";
$error_msg   = "";

// ---------------------------------------------------------
// 2. HANDLE ACTIONS
// ---------------------------------------------------------

// A. Record Standard Bank Inflow (Lump Sum)
if ($can_edit && isset($_POST['save_inflow'])) {
    try {
        $bank_id     = $_POST['bank_account_id'];
        $date_recv   = $_POST['date_received'];
        $ref_no      = trim($_POST['reference_no']);
        $or_no       = trim($_POST['official_receipt']);
        $category    = trim($_POST['category']);
        $particulars = trim($_POST['particulars']);
        $amount      = floatval(str_replace(',', '', $_POST['amount']));
        $user_id     = $_SESSION['user_id'] ?? null;

        if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

        $sql = "INSERT INTO cash_inflows (bank_account_id, date_received, reference_no, official_receipt, category, particulars, amount, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$bank_id, $date_recv, $ref_no, $or_no, $category, $particulars, $amount, $user_id]);
        
        $success_msg = "Bank inflow successfully recorded.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// B. Record Inter-Bank Transfer or Outward Remittance
if ($can_edit && isset($_POST['save_transfer'])) {
    try {
        $src_id        = $_POST['source_bank_id'];
        $transfer_type = $_POST['transfer_type'] ?? 'internal';
        $dest_id       = ($transfer_type === 'internal') ? $_POST['destination_bank_id'] : null;
        $external_dest = ($transfer_type === 'external') ? trim($_POST['external_recipient']) : null;
        $date_trans    = $_POST['transfer_date'];
        $mov_ref       = trim($_POST['mov_reference']);
        $particulars   = trim($_POST['particulars']);
        $amount        = floatval(str_replace(',', '', $_POST['amount']));
        $user_id       = $_SESSION['user_id'] ?? null;

        if ($transfer_type === 'internal' && $src_id == $dest_id) throw new Exception("Source and Destination accounts cannot be the same.");
        if ($transfer_type === 'external' && empty($external_dest)) throw new Exception("External recipient must be specified.");
        if ($amount <= 0) throw new Exception("Transfer amount must be greater than zero.");

        $sql = "INSERT INTO bank_transfers (source_bank_id, destination_bank_id, external_destination, transfer_date, mov_reference, amount, particulars, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$src_id, $dest_id, $external_dest, $date_trans, $mov_ref, $amount, $particulars, $user_id]);
        
        $success_msg = ($transfer_type === 'external') ? "Outward remittance successfully recorded." : "Fund transfer successfully recorded.";
    } catch (Exception $e) { $error_msg = "Transfer Error: " . $e->getMessage(); }
}

// C. Record Bank Adjustment
if ($can_edit && isset($_POST['save_adjustment'])) {
    try {
        $bank_id     = $_POST['adj_bank_account_id'];
        $date_adj    = $_POST['adjustment_date'];
        $type        = $_POST['adjustment_type']; 
        $category    = trim($_POST['adj_category']);
        $ref_no      = trim($_POST['adj_reference_no']);
        $particulars = trim($_POST['adj_particulars']);
        $amount      = floatval(str_replace(',', '', $_POST['adj_amount']));
        $user_id     = $_SESSION['user_id'] ?? null;

        if ($amount <= 0) throw new Exception("Adjustment amount must be greater than zero.");

        $sql = "INSERT INTO bank_adjustments (bank_account_id, adjustment_date, type, category, reference_no, amount, particulars, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$bank_id, $date_adj, $type, $category, $ref_no, $amount, $particulars, $user_id]);
        
        $success_msg = "Bank adjustment successfully recorded.";
    } catch (Exception $e) { $error_msg = "Adjustment Error: " . $e->getMessage(); }
}

// D. UNIVERSAL EDIT RECORD
if ($can_delete && isset($_POST['update_record'])) {
    try {
        $id     = $_POST['edit_id'];
        $type   = $_POST['edit_type'];
        $amount = floatval(str_replace(',', '', $_POST['edit_amount']));
        $date   = $_POST['edit_date'];
        $ref    = trim($_POST['edit_ref']);
        $or_no  = trim($_POST['edit_or']); 
        $part   = trim($_POST['edit_part']);
        $cat    = trim($_POST['edit_cat']);

        if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

        if ($type === 'inflow') {
            $pdo->prepare("UPDATE cash_inflows SET amount=?, date_received=?, reference_no=?, official_receipt=?, category=?, particulars=? WHERE id=?")
                ->execute([$amount, $date, $ref, $or_no, $cat, $part, $id]);
        } elseif ($type === 'adjustment') {
            $pdo->prepare("UPDATE bank_adjustments SET amount=?, adjustment_date=?, reference_no=?, category=?, particulars=? WHERE id=?")
                ->execute([$amount, $date, $ref, $cat, $part, $id]);
        } else {
            $pdo->prepare("UPDATE bank_transfers SET amount=?, transfer_date=?, mov_reference=?, particulars=? WHERE id=?")
                ->execute([$amount, $date, $ref, $part, $id]);
        }
        $success_msg = "Record successfully updated.";
    } catch (Exception $e) { $error_msg = "Update Error: " . $e->getMessage(); }
}

// E. Delete Record
if ($can_delete && isset($_POST['delete_record'])) {
    try {
        $id = $_POST['del_id'];
        $type = $_POST['trans_type'];
        
        if ($type === 'inflow') {
            $pdo->prepare("DELETE FROM cash_inflows WHERE id = ?")->execute([$id]);
        } elseif ($type === 'adjustment') {
            $pdo->prepare("DELETE FROM bank_adjustments WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("DELETE FROM bank_transfers WHERE id = ?")->execute([$id]);
        }
        $success_msg = "Record successfully deleted.";
    } catch (Exception $e) { $error_msg = "Delete Error: " . $e->getMessage(); }
}

// ---------------------------------------------------------
// 3. FETCH DATA & BALANCES
// ---------------------------------------------------------
$f_start = $_GET['start'] ?? date('Y-01-01'); 
$f_end   = $_GET['end']   ?? date('Y-m-t');   
$f_bank  = $_GET['bank_id'] ?? 'all';
$f_cat   = isset($_GET['category']) ? (is_array($_GET['category']) ? $_GET['category'] : [$_GET['category']]) : [];

// Generate Export Link parameters
$export_params = "start={$f_start}&end={$f_end}&bank_id={$f_bank}";
foreach($f_cat as $cat) $export_params .= "&category[]=" . urlencode($cat);

// Aggregate categories for filter
$categories = ['Beginning Balance', 'PhilHealth Credit', 'LGU Subsidy', 'DOH Grant', 'Donation', 'Other'];
$filter_categories = array_unique(array_merge($categories, ['Transfer In', 'Transfer Out', 'Interest Earned', 'Reversal / Refund', 'Withholding Tax', 'Bank Fee / Charge']));
sort($filter_categories);

$sql_banks = "SELECT 
                ba.id, ba.account_name, ba.account_number, fs.name as fund_name,
                (
                    COALESCE((SELECT SUM(amount) FROM cash_inflows WHERE bank_account_id = ba.id), 0) +
                    COALESCE((SELECT SUM(amount) FROM bank_transfers WHERE destination_bank_id = ba.id), 0) -
                    COALESCE((SELECT SUM(amount) FROM bank_transfers WHERE source_bank_id = ba.id), 0) +
                    COALESCE((SELECT SUM(amount) FROM bank_adjustments WHERE bank_account_id = ba.id AND type = 'credit'), 0) -
                    COALESCE((SELECT SUM(amount) FROM bank_adjustments WHERE bank_account_id = ba.id AND type = 'debit'), 0)
                ) as total_balance
              FROM bank_accounts ba 
              LEFT JOIN fund_sources fs ON ba.fund_source_id = fs.id
              WHERE ba.status = 'active'
              ORDER BY fs.name ASC";
$active_banks = $pdo->query($sql_banks)->fetchAll(PDO::FETCH_ASSOC);

$total_cash_in_bank = 0;
foreach($active_banks as $bank) { $total_cash_in_bank += $bank['total_balance']; }

// B. Unified Ledger History (4-Way UNION)
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

// Category Multi-Select Handling
if (!empty($f_cat)) {
    $cat_placeholders = implode(',', array_fill(0, count($f_cat), '?'));
    $w_inf[] = "ci.category IN ($cat_placeholders)";
    foreach($f_cat as $c) $p_inf[] = $c;
    
    if(!in_array('Transfer In', $f_cat)) $w_dst[] = "1=0";
    if(!in_array('Transfer Out', $f_cat)) $w_src[] = "1=0";
    
    $w_adj[] = "badj.category IN ($cat_placeholders)";
    foreach($f_cat as $c) $p_adj[] = $c;
}

// 1: Inflows (Simplified)
$q_inf = "SELECT ci.id, ci.bank_account_id, ba.account_name, fs.name as fund_name, ci.date_received as trans_date, ci.reference_no, ci.official_receipt as or_no, ci.category, ci.particulars, ci.amount, 'inflow' as trans_type 
          FROM cash_inflows ci JOIN bank_accounts ba ON ci.bank_account_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id
          WHERE " . implode(" AND ", $w_inf);

// 2: Transfers IN
$q_dst = "SELECT bt.id, bt.destination_bank_id as bank_account_id, ba.account_name, fs.name as fund_name, bt.transfer_date as trans_date, bt.mov_reference as reference_no, NULL as or_no, 'Transfer In' as category, CONCAT('From: ', src_ba.account_name, ' - ', bt.particulars) as particulars, bt.amount, 'transfer_in' as trans_type
          FROM bank_transfers bt JOIN bank_accounts ba ON bt.destination_bank_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id JOIN bank_accounts src_ba ON bt.source_bank_id = src_ba.id
          WHERE " . implode(" AND ", $w_dst);

// 3: Transfers OUT (Includes Internal Transfers Out and External Remittances)
$q_src = "SELECT bt.id, bt.source_bank_id as bank_account_id, ba.account_name, fs.name as fund_name, bt.transfer_date as trans_date, bt.mov_reference as reference_no, NULL as or_no, 'Transfer Out' as category, CONCAT('To: ', COALESCE(dest_ba.account_name, bt.external_destination), ' - ', bt.particulars) as particulars, -bt.amount as amount, 'transfer_out' as trans_type
          FROM bank_transfers bt JOIN bank_accounts ba ON bt.source_bank_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id LEFT JOIN bank_accounts dest_ba ON bt.destination_bank_id = dest_ba.id
          WHERE " . implode(" AND ", $w_src);

// 4: Bank Adjustments
$q_adj = "SELECT badj.id, badj.bank_account_id, ba.account_name, fs.name as fund_name, badj.adjustment_date as trans_date, badj.reference_no, NULL as or_no, badj.category, badj.particulars, IF(badj.type='credit', badj.amount, -badj.amount) as amount, 'adjustment' as trans_type
          FROM bank_adjustments badj JOIN bank_accounts ba ON badj.bank_account_id = ba.id JOIN fund_sources fs ON ba.fund_source_id = fs.id
          WHERE " . implode(" AND ", $w_adj);

$sql_hist = "$q_inf UNION ALL $q_dst UNION ALL $q_src UNION ALL $q_adj ORDER BY trans_date DESC, id DESC";
$all_params = array_merge($p_inf, $p_dst, $p_src, $p_adj);

$stmt = $pdo->prepare($sql_hist);
$stmt->execute($all_params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid mt-4 px-4 screen-only">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0">Bank Statement Ledger</h4>
            <span class="badge bg-dark">General Ledger</span>
        </div>
        <div class="d-flex gap-2">
            <?php if($can_edit): ?>
                <button class="btn btn-warning fw-bold shadow-sm no-print text-dark" data-bs-toggle="modal" data-bs-target="#adjustmentModal">
                    <i class="bi bi-calculator me-1"></i> Bank Adjustment
                </button>
                <button class="btn btn-primary fw-bold shadow-sm no-print" data-bs-toggle="modal" data-bs-target="#transferModal">
                    <i class="bi bi-arrow-left-right me-1"></i> Transfer / Outflow
                </button>
            <?php endif; ?>
            <a href="export_inflows.php?<?php echo $export_params; ?>" target="_blank" class="btn btn-success fw-bold shadow-sm no-print">
                <i class="bi bi-file-earmark-excel-fill me-1"></i> Export XLS
            </a>
            <button onclick="printReport()" class="btn btn-outline-dark fw-bold shadow-sm no-print">
                <i class="bi bi-printer-fill me-1"></i> Print Report
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-dark text-white border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="small text-uppercase opacity-75 fw-bold"><i class="bi bi-bank me-2"></i>Total Cash in Bank</div>
                    <h3 class="mb-0 fw-bold mt-2">₱ <?php echo number_format($total_cash_in_bank, 2); ?></h3>
                </div>
            </div>
        </div>
        <?php foreach($active_banks as $bank): ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body p-3">
                    <div class="text-truncate fw-bold text-dark" title="<?php echo htmlspecialchars($bank['account_name']); ?>">
                        <?php echo htmlspecialchars($bank['account_name']); ?>
                    </div>
                    <div class="small text-muted mb-2 font-monospace"><?php echo $bank['account_number']; ?></div>
                    <div class="d-flex justify-content-between align-items-end mt-2">
                        <div>
                            <div class="small text-muted text-uppercase" style="font-size: 0.65rem;">Current Balance</div>
                            <h5 class="fw-bold text-success mb-0">₱ <?php echo number_format($bank['total_balance'], 2); ?></h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show no-print"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger no-print"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="row g-4">
        <?php if($can_edit): ?>
        <div class="col-lg-3 no-print">
            <div class="card shadow border-0 mb-4">
                <div class="card-header bg-success text-white fw-bold">
                    <i class="bi bi-box-arrow-in-down-right me-2"></i>Record Bank Deposit
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small text-muted mb-3 py-2">
                        <i class="bi bi-info-circle text-primary me-1"></i> Enter lump-sum bank credits here. Use the <strong>PhilHealth Receivables</strong> module to encode detailed facility ACPNs.
                    </div>
                    <form method="POST" onsubmit="return confirm('Confirm cash receipt?');">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Deposit Account</label>
                            <select name="bank_account_id" class="form-select border-success fw-bold" required>
                                <option value="" disabled selected>Select Account...</option>
                                <?php foreach($active_banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="small fw-bold text-muted">Date Received</label>
                                <input type="date" name="date_received" class="form-control" value="<?php echo date('Y-m-d'); ?>" required max="9999-12-31">
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted">Total Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light fw-bold">₱</span>
                                    <input type="text" name="amount" class="form-control fw-bold text-success" required placeholder="0.00" oninput="formatAmount(this)">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="small fw-bold text-muted">Bank Ref / Trace</label>
                                <input type="text" name="reference_no" class="form-control" placeholder="Optional">
                            </div>
                            <div class="col-6">
                                <label class="small fw-bold text-muted text-success">O.R. No.</label>
                                <input type="text" name="official_receipt" class="form-control border-success" placeholder="(Optional)">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Category</label>
                            <input type="text" name="category" class="form-control" list="catOptions" placeholder="e.g. PhilHealth Credit">
                            <datalist id="catOptions">
                                <?php foreach($categories as $cat) echo "<option value=\"$cat\">"; ?>
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold text-muted">Particulars</label>
                            <textarea name="particulars" class="form-control" rows="2" placeholder="Lump sum description..." required></textarea>
                        </div>
                        <button type="submit" name="save_inflow" class="btn btn-success w-100 fw-bold">Save Bank Record</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?php echo $can_edit ? 'col-lg-9' : 'col-12'; ?>">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 no-print">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark mb-0">Universal Transaction Ledger</h6>
                    </div>
                    
                    <form method="GET" class="row g-2 align-items-end p-2 bg-light rounded border">
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">Start</label>
                            <input type="date" name="start" class="form-control form-control-sm" value="<?php echo $f_start; ?>" max="9999-12-31">
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">End</label>
                            <input type="date" name="end" class="form-control form-control-sm" value="<?php echo $f_end; ?>" max="9999-12-31">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">Bank Account</label>
                            <select name="bank_id" class="form-select form-select-sm">
                                <option value="all">All Accounts</option>
                                <?php foreach($active_banks as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo $f_bank == $b['id'] ? 'selected' : ''; ?>><?php echo $b['account_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">Category Filter</label>
                            <div class="multiselect-container" id="catMultiSelect">
                                <div class="multiselect-display form-control form-control-sm bg-white" onclick="toggleMultiSelect(this)">
                                    <span class="multiselect-text">All Categories</span><i class="bi bi-chevron-down float-end mt-1"></i>
                                </div>
                                <div class="multiselect-options shadow-sm border rounded">
                                    <?php foreach($filter_categories as $c): ?>
                                        <label class="multiselect-option">
                                            <input type="checkbox" name="category[]" value="<?php echo htmlspecialchars($c); ?>" <?php echo in_array($c, $f_cat) ? 'checked' : ''; ?> onchange="updateMultiSelectText('catMultiSelect', 'All Categories')"> 
                                            <?php echo htmlspecialchars($c); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-sm btn-dark w-100 fw-bold">Filter</button>
                                <a href="cash_inflows.php" class="btn btn-sm btn-outline-secondary px-2" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light text-center">
                                <tr>
                                    <th style="width: 10%">Date</th>
                                    <th style="width: 25%" class="text-start">Bank Account</th>
                                    <th style="width: 15%">Bank Ref / O.R.</th>
                                    <th style="width: 25%" class="text-start">Category & Particulars</th>
                                    <th style="width: 15%" class="text-end pe-3">Amount</th>
                                    <?php if($can_delete): ?><th style="width: 10%" class="no-print">Action</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $table_total = 0; ?>
                                <?php if(count($history) > 0): foreach($history as $h): $table_total += $h['amount']; 
                                    $amt_color = $h['amount'] < 0 ? 'text-danger' : 'text-success';
                                    
                                    if ($h['trans_type'] == 'transfer_out') { $cat_badge = 'bg-danger text-white'; }
                                    elseif ($h['trans_type'] == 'transfer_in') { $cat_badge = 'bg-primary text-white'; }
                                    elseif ($h['trans_type'] == 'adjustment' && $h['amount'] < 0) { $cat_badge = 'bg-warning text-dark'; }
                                    elseif ($h['trans_type'] == 'adjustment' && $h['amount'] > 0) { $cat_badge = 'bg-info text-dark'; }
                                    else { $cat_badge = 'bg-light text-dark border'; }

                                    $json_data = htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td class="text-center text-muted"><?php echo date('M d, Y', strtotime($h['trans_date'])); ?></td>
                                    <td>
                                        <div class="fw-bold text-dark text-truncate" style="max-width:180px;" title="<?php echo htmlspecialchars($h['account_name']); ?>"><?php echo htmlspecialchars($h['account_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($h['fund_name']); ?></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="font-monospace fw-bold text-dark"><?php echo htmlspecialchars($h['reference_no'] ?? '-'); ?></div>
                                        <?php if(!empty($h['or_no'])): ?>
                                            <div class="small text-success fw-bold mt-1" title="Official Receipt"><i class="bi bi-receipt me-1"></i><?php echo htmlspecialchars($h['or_no']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start">
                                        <div class="badge <?php echo $cat_badge; ?> mb-1"><?php echo htmlspecialchars($h['category'] ?? 'Uncategorized'); ?></div>
                                        <div class="text-muted fst-italic text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($h['particulars']); ?>"><?php echo htmlspecialchars($h['particulars']); ?></div>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $amt_color; ?> pe-3">₱ <?php echo number_format($h['amount'], 2); ?></td>
                                    
                                    <?php if($can_delete): ?>
                                    <td class="text-center no-print">
                                        <div class="d-flex justify-content-center gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary border-0" onclick='openEdit(<?php echo $json_data; ?>)' title="Edit Entry"><i class="bi bi-pencil-square"></i></button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this record? This will affect bank balances.');">
                                                <input type="hidden" name="del_id" value="<?php echo $h['id']; ?>">
                                                <input type="hidden" name="trans_type" value="<?php echo $h['trans_type']; ?>">
                                                <button type="submit" name="delete_record" class="btn btn-sm btn-outline-danger border-0" title="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-light fw-bold">
                                    <td colspan="4" class="text-end">NET LEDGER MOVEMENT:</td>
                                    <td class="text-end <?php echo $table_total < 0 ? 'text-danger' : 'text-success'; ?> pe-3">₱ <?php echo number_format($table_total, 2); ?></td>
                                    <?php if($can_delete): ?><td class="no-print"></td><?php endif; ?>
                                </tr>
                                <?php else: ?>
                                <tr><td colspan="<?php echo $can_delete ? 6 : 5; ?>" class="text-center py-5 text-muted">No transactions matching criteria.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="printableReport" class="d-none">
    <div class="report-wrapper">
        <div class="text-center mb-4">
            <h5 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif; font-size: 14pt;">Republic of the Philippines</h5>
            <div class="mb-0" style="font-family: 'Times New Roman', serif; font-size: 12pt;">City Government of Parañaque</div>
            <h4 class="fw-bold mb-3 mt-2" style="font-family: 'Times New Roman', serif; font-size: 16pt;">OFFICE OF THE CITY HEALTH OFFICER</h4>
            
            <h5 class="fw-bold text-uppercase d-inline-block pb-1" style="font-family: 'Times New Roman', serif; border-bottom: 2px solid black;">
                BANK STATEMENT LEDGER
            </h5>
            <div class="fw-bold mt-1" style="font-family: 'Times New Roman', serif;">Period: <?php echo date('M d', strtotime($f_start)) . ' to ' . date('M d, Y', strtotime($f_end)); ?></div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th style="width:15%">Date Credited</th>
                    <th style="width:20%">Bank Account</th>
                    <th style="width:20%">Ref / O.R. No.</th>
                    <th style="width:25%">Particulars</th>
                    <th style="width:20%">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $report_total = 0; ?>
                <?php foreach($history as $h): 
                    if (in_array($h['trans_type'], ['transfer_out', 'transfer_in', 'adjustment'])) continue;
                    $report_total += $h['amount'];
                ?>
                <tr>
                    <td class="text-center"><?php echo date('m/d/Y', strtotime($h['trans_date'])); ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($h['account_name']); ?></td>
                    <td class="text-center">
                        <?php echo htmlspecialchars($h['reference_no'] ?? ''); ?>
                        <?php if(!empty($h['or_no'])): ?><br><i>OR: <?php echo htmlspecialchars($h['or_no']); ?></i><?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($h['category']); ?></strong><br>
                        <span style="font-size: 8pt;"><?php echo htmlspecialchars($h['particulars']); ?></span>
                    </td>
                    <td class="text-end fw-bold">₱ <?php echo number_format($h['amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" class="text-end fw-bold bg-light">GRAND TOTAL</td>
                    <td class="text-end fw-bold bg-light">₱ <?php echo number_format($report_total, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="mt-5 print-grid">
            <div>
                <div class="small mb-4">Prepared by:</div>
                <div class="fw-bold text-uppercase text-decoration-underline"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'SYSTEM USER'); ?></div>
                <div class="small">System Encoder / Finance Unit</div>
            </div>
            <div class="text-end">
                <div class="small mb-4">Noted and Approved by:</div>
                <div class="fw-bold text-uppercase text-decoration-underline">DR. [CITY HEALTH OFFICER NAME]</div>
                <div class="small">OIC-City Health Officer</div>
            </div>
        </div>
    </div>
</div>

<?php if($can_delete): ?>
<div class="modal fade no-print" id="universalEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Transaction</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" onsubmit="return confirm('Confirm updates to this record?');">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <input type="hidden" name="edit_type" id="edit_type">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Date</label>
                            <input type="date" name="edit_date" id="edit_date" class="form-control" required max="9999-12-31">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold">₱</span>
                                <input type="text" name="edit_amount" id="edit_amount" class="form-control fw-bold" required oninput="formatAmount(this)">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Bank Ref / Trace</label>
                            <input type="text" name="edit_ref" id="edit_ref" class="form-control">
                        </div>
                        <div class="col-6" id="edit_or_group">
                            <label class="small fw-bold text-muted text-success">O.R. No.</label>
                            <input type="text" name="edit_or" id="edit_or" class="form-control border-success">
                        </div>
                    </div>
                    
                    <div class="mb-2" id="edit_cat_group">
                        <label class="small fw-bold text-muted">Category</label>
                        <input type="text" name="edit_cat" id="edit_cat" class="form-control" list="catOptions">
                    </div>
                    
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Particulars / Details</label>
                        <textarea name="edit_part" id="edit_part" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="update_record" class="btn btn-dark w-100 fw-bold">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($can_edit): ?>
<div class="modal fade no-print" id="adjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-calculator me-2"></i>Bank Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-secondary small py-2 mb-3">
                    Use this to record bank-initiated changes (e.g. End-of-month Interest, Withholding Tax, or Checkbook Fees).
                </div>
                <form method="POST" onsubmit="return confirm('Confirm bank adjustment?');">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Bank Account</label>
                        <select name="adj_bank_account_id" class="form-select border-dark fw-bold" required>
                            <option value="" disabled selected>Select Account...</option>
                            <?php foreach($active_banks as $bank): ?>
                                <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Date</label>
                            <input type="date" name="adjustment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required max="9999-12-31">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Adjustment Type</label>
                            <select name="adjustment_type" class="form-select" id="adjType" onchange="updateAdjUI()" required>
                                <option value="debit">Deduction / Debit (-)</option>
                                <option value="credit">Addition / Credit (+)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Category</label>
                            <select name="adj_category" id="adjCategory" class="form-select" required>
                                <option value="Withholding Tax">Withholding Tax</option>
                                <option value="Bank Fee / Charge">Bank Fee / Charge</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold" id="adjSign">- ₱</span>
                                <input type="text" name="adj_amount" id="adjAmount" class="form-control fw-bold text-danger" required placeholder="0.00" oninput="formatAmount(this)">
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">Reference / Statement No. (Optional)</label>
                        <input type="text" name="adj_reference_no" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Particulars</label>
                        <textarea name="adj_particulars" class="form-control" rows="2" placeholder="Describe the adjustment..."></textarea>
                    </div>
                    <button type="submit" name="save_adjustment" class="btn btn-warning text-dark w-100 fw-bold">Save Adjustment</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade no-print" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Fund Transfer & Outflow</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    Use this to record internal fund transfers or outward remittances to external parties (e.g., BIR, Suppliers).
                </div>
                <form method="POST" onsubmit="return confirm('Confirm transaction execution?');">
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">Source Account (Deduct From)</label>
                        <select name="source_bank_id" class="form-select border-danger fw-bold" required>
                            <option value="" disabled selected>Select Source...</option>
                            <?php foreach($active_banks as $bank): ?>
                                <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['account_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="text-center my-1 text-muted"><i class="bi bi-arrow-down fs-5"></i></div>
                    <div class="mb-3 border rounded p-2 bg-light">
                        <label class="small fw-bold text-muted d-block mb-2">Destination Type</label>
                        <div class="btn-group w-100 mb-2" role="group">
                            <input type="radio" class="btn-check" name="transfer_type" id="tt_internal" value="internal" autocomplete="off" checked onchange="toggleTransferType()">
                            <label class="btn btn-outline-primary btn-sm fw-bold" for="tt_internal">Internal Account</label>

                            <input type="radio" class="btn-check" name="transfer_type" id="tt_external" value="external" autocomplete="off" onchange="toggleTransferType()">
                            <label class="btn btn-outline-primary btn-sm fw-bold" for="tt_external">External Recipient</label>
                        </div>
                        
                        <div id="dest_internal_group">
                            <select name="destination_bank_id" id="destination_bank_id" class="form-select border-success fw-bold" required>
                                <option value="" disabled selected>Select Internal Destination...</option>
                                <?php foreach($active_banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['account_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="dest_external_group" style="display:none;">
                            <input type="text" name="external_recipient" id="external_recipient" class="form-control border-success fw-bold" placeholder="e.g. BIR, General Fund, Supplier Name" list="externalOptions">
                            <datalist id="externalOptions">
                                <option value="Bureau of Internal Revenue (BIR)">
                                <option value="City General Fund">
                                <option value="PhilHealth">
                            </datalist>
                        </div>
                    </div>
                    <hr>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Date of Transfer</label>
                            <input type="date" name="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required max="9999-12-31">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Amount</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold">₱</span>
                                <input type="text" name="amount" class="form-control fw-bold text-dark" required placeholder="0.00" oninput="formatAmount(this)">
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">MOV / Tracking Number</label>
                        <input type="text" name="mov_reference" class="form-control fw-bold" placeholder="e.g., LCE Reso #123, CTO Req #45" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Particulars</label>
                        <textarea name="particulars" class="form-control" rows="2" placeholder="Reason for transfer..." required></textarea>
                    </div>
                    <button type="submit" name="save_transfer" class="btn btn-primary w-100 fw-bold">Execute Transfer</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .multiselect-container { position: relative; width: 100%; } 
    .multiselect-display { cursor: pointer; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; }
    .multiselect-options { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; z-index: 1000; max-height: 250px; overflow-y: auto; padding: 5px; }
    .multiselect-option { display: block; padding: 3px 10px; cursor: pointer; font-size: 0.85rem; } 
    .multiselect-option:hover { background-color: #f8f9fa; }

    #printableReport { display: none; }
    
    @media print {
        @page { size: A4 landscape; margin: 0.5in; }
        .screen-only, .no-print, body > .container-fluid { display: none !important; }
        html, body { height: auto !important; margin: 0 !important; padding: 0 !important; background: white; }
        
        body.printing-report #printableReport { display: block !important; position: absolute; top: 0; left: 0; width: 100%; }
        
        .report-wrapper { width: 100%; margin: 0 auto; background: white; }
        .print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; width: 100%; }
        table.report-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 9pt; margin-bottom: 20px; }
        table.report-table th, table.report-table td { border: 1px solid #000 !important; padding: 5px; vertical-align: middle; word-wrap: break-word; }
        table.report-table thead th { text-align: center; font-weight: bold; font-family: 'Times New Roman', serif; background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
    }
</style>

<script>
    function formatAmount(i) { 
        let v = i.value.replace(/[^0-9.]/g, '');
        let p = v.split('.'); 
        p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); 
        i.value = p.join('.') + (p.length>1 && p[1].length>2 ? '' : ''); 
    }

    function toggleTransferType() {
        const isInternal = document.getElementById('tt_internal').checked;
        const internalGroup = document.getElementById('dest_internal_group');
        const externalGroup = document.getElementById('dest_external_group');
        const destSelect = document.getElementById('destination_bank_id');
        const extInput = document.getElementById('external_recipient');
        
        if (isInternal) {
            internalGroup.style.display = 'block';
            externalGroup.style.display = 'none';
            destSelect.setAttribute('required', 'required');
            extInput.removeAttribute('required');
            extInput.value = '';
        } else {
            internalGroup.style.display = 'none';
            externalGroup.style.display = 'block';
            extInput.setAttribute('required', 'required');
            destSelect.removeAttribute('required');
            destSelect.value = '';
        }
    }

    function toggleMultiSelect(el) { 
        const list = el.nextElementSibling; 
        document.querySelectorAll('.multiselect-options').forEach(opt => { if(opt !== list) opt.style.display = 'none'; });
        list.style.display = list.style.display === 'block' ? 'none' : 'block'; 
    }
    
    document.addEventListener('click', function(e) { 
        if (!e.target.closest('.multiselect-container')) { 
            document.querySelectorAll('.multiselect-options').forEach(opt => opt.style.display = 'none'); 
        }
    });
    
    function updateMultiSelectText(containerId, defaultText) { 
        const container = document.getElementById(containerId);
        const checkboxes = container.querySelectorAll('.multiselect-option input[type="checkbox"]:checked'); 
        const display = container.querySelector('.multiselect-text'); 
        if (checkboxes.length === 0) { display.innerText = defaultText; } 
        else if (checkboxes.length <= 1) { display.innerText = checkboxes[0].parentNode.innerText.trim(); } 
        else { display.innerText = checkboxes.length + " Selected"; } 
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateMultiSelectText('catMultiSelect', 'All Categories');
    });

    function printReport() { 
        document.body.classList.add('printing-report'); 
        window.print(); 
    }
    window.onafterprint = function() { 
        document.body.classList.remove('printing-report'); 
    };

    function updateAdjUI() {
        const type = document.getElementById('adjType').value;
        const sign = document.getElementById('adjSign');
        const amt = document.getElementById('adjAmount');
        const cat = document.getElementById('adjCategory');

        if (type === 'credit') {
            sign.innerText = '+ ₱';
            amt.classList.remove('text-danger'); amt.classList.add('text-success');
            cat.innerHTML = '<option value="Interest Earned">Interest Earned</option><option value="Reversal / Refund">Reversal / Refund</option>';
        } else {
            sign.innerText = '- ₱';
            amt.classList.remove('text-success'); amt.classList.add('text-danger');
            cat.innerHTML = '<option value="Withholding Tax">Withholding Tax</option><option value="Bank Fee / Charge">Bank Fee / Charge</option>';
        }
    }

    function openEdit(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_type').value = data.trans_type;
        document.getElementById('edit_date').value = data.trans_date;
        document.getElementById('edit_amount').value = Math.abs(parseFloat(data.amount)).toLocaleString('en-US', {minimumFractionDigits:2});
        document.getElementById('edit_ref').value = data.reference_no;
        document.getElementById('edit_or').value = data.or_no || '';
        
        let cleanPartic = data.particulars;
        if (data.trans_type === 'transfer_in' || data.trans_type === 'transfer_out') {
            cleanPartic = cleanPartic.split(' - ').slice(1).join(' - ');
        }
        document.getElementById('edit_part').value = cleanPartic;

        const catGroup = document.getElementById('edit_cat_group');
        const orGroup  = document.getElementById('edit_or_group');

        if (data.trans_type === 'inflow') {
            catGroup.style.display = 'block';
            orGroup.style.display = 'block';
            document.getElementById('edit_cat').value = data.category;
        } else if (data.trans_type === 'adjustment') {
            catGroup.style.display = 'block';
            orGroup.style.display = 'none';
            document.getElementById('edit_cat').value = data.category;
        } else {
            catGroup.style.display = 'none';
            orGroup.style.display = 'none';
        }

        new bootstrap.Modal(document.getElementById('universalEditModal')).show();
    }

    if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }
</script>

<?php require 'includes/footer.php'; ?>