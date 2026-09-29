<?php
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_role = strtolower($_SESSION['role']);
$can_edit     = in_array($user_role, ['admin', 'encoder']);
$can_delete   = ($user_role === 'admin');
$can_print_ar = in_array($user_role, ['admin', 'encoder']);
$can_manage   = $can_edit;

$page_title = 'Petty Cash Management';
require 'includes/header.php';

$success_msg = "";
$error_msg   = "";

// ---------------------------------------------------------
// 2. HANDLE ACTIONS
// ---------------------------------------------------------

// A. ISSUE NEW VOUCHER (Cash Advance)
if ($can_edit && isset($_POST['save_pcv'])) {
    try {
        $charge_item_id = $_POST['fund_source_id']; 
        $payee          = trim($_POST['payee']);
        $partic         = trim($_POST['particulars']);
        $amt            = floatval(str_replace(',', '', $_POST['amount']));
        $date           = $_POST['pcv_date'];
        
        // 1. Get Limit
        $stmt_limit = $pdo->prepare("SELECT ci.amount FROM charge_items ci WHERE ci.id = ?");
        $stmt_limit->execute([$charge_item_id]);
        $limit = $stmt_limit->fetchColumn();

        // 2. Get Net Spent (Issued - Returned)
        // FIX: Added COALESCE to handle NULL returns
        $stmt_spent = $pdo->prepare("SELECT SUM(amount - COALESCE(amount_returned, 0)) FROM petty_cash_expenses WHERE charge_item_id = ?");
        $stmt_spent->execute([$charge_item_id]);
        $net_spent = $stmt_spent->fetchColumn();

        $current_balance = $limit - $net_spent;

        if ($amt > $current_balance) {
            $error_msg = "Insufficient funds. Available: ₱ " . number_format($current_balance, 2);
        } else {
            $pdo->beginTransaction();
            $stmt_cat = $pdo->prepare("SELECT li.name FROM charge_items ci JOIN line_items li ON ci.line_item_id = li.id WHERE ci.id = ?");
            $stmt_cat->execute([$charge_item_id]);
            $category = $stmt_cat->fetchColumn();
            
            $yr = date('Y');
            $stmt_seq = $pdo->query("SELECT COUNT(*) FROM petty_cash_expenses WHERE YEAR(pcv_date) = '$yr'");
            $count = $stmt_seq->fetchColumn() + 1;
            $pcv_no = sprintf("PCV-%s-%03d", $yr, $count);

            $sql = "INSERT INTO petty_cash_expenses 
                    (charge_item_id, pcv_number, payee, particulars, amount, category, pcv_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $pdo->prepare($sql)->execute([$charge_item_id, $pcv_no, $payee, $partic, $amt, $category, $date]);
            
            $pdo->commit();
            $success_msg = "Voucher <strong>$pcv_no</strong> issued successfully.";
        }
    } catch (Exception $e) { $pdo->rollBack(); $error_msg = "Error: " . $e->getMessage(); }
}

// B. LIQUIDATE / UPDATE VOUCHER
if ($can_edit && isset($_POST['update_pcv'])) {
    try {
        $pdo->beginTransaction();
        
        $id = $_POST['edit_id']; 
        $payee = trim($_POST['edit_payee']); 
        
        $issued_amount = floatval(str_replace(',', '', $_POST['edit_issued_amount'])); 
        $cash_return   = floatval(str_replace(',', '', $_POST['edit_cash_return']));
        $base_partic   = trim($_POST['edit_base_particulars']);
        
        $receipt_data = $_POST['receipts'] ?? []; 
        $total_receipts = 0;

        // 1. Clear old items
        $pdo->prepare("DELETE FROM petty_cash_items WHERE pc_expense_id = ?")->execute([$id]);

        // 2. Insert new items
        $stmt_item = $pdo->prepare("INSERT INTO petty_cash_items (pc_expense_id, or_number, or_date, plate_number, description, amount) VALUES (?, ?, ?, ?, ?, ?)");
        
        $or_list = [];
        $plate_list = [];

        foreach($receipt_data as $r) {
            if(!empty($r['amt'])) { 
                $r_amt   = floatval(str_replace(',', '', $r['amt']));
                $total_receipts += $r_amt;
                
                $r_or    = !empty($r['or'])   ? $r['or']   : null;
                $r_date  = !empty($r['date']) ? $r['date'] : null;
                $r_plate = !empty($r['plate']) ? $r['plate'] : null;
                $r_desc  = !empty($r['desc'])  ? $r['desc']  : null;

                $stmt_item->execute([$id, $r_or, $r_date, $r_plate, $r_desc, $r_amt]);
                
                if($r_or) $or_list[] = $r_or;
                if($r_plate) $plate_list[] = $r_plate;
            }
        }

        // 3. Validation (Allow 1.0 variance for rounding)
        if (abs(($total_receipts + $cash_return) - $issued_amount) > 1.0) {
            throw new Exception("Math Error: Total Receipts + Cash Return must equal Issued Amount.");
        }

        // 4. Update Parent
        $or_string = implode(', ', array_unique($or_list));
        $plate_string = implode(', ', array_unique($plate_list));
        
        // Append marker for reporting parsing, but keep clean base for editing
        $final_particulars = $base_partic . "\n\n[LIQUIDATION DETAILS]:"; 
        
        // Add text summary for fallback view
        foreach($receipt_data as $r) {
             if(!empty($r['amt'])) {
                 $final_particulars .= "\n• " . ($r['desc'] ?? 'Item') . " - " . $r['amt'];
             }
        }

        $pdo->prepare("UPDATE petty_cash_expenses SET 
                        payee=?, 
                        particulars=?, 
                        or_number=?, 
                        plate_number=?,
                        amount_returned=? 
                        WHERE id=?")
            ->execute([$payee, $final_particulars, $or_string, $plate_string, $cash_return, $id]);
            
        $pdo->commit();
        $success_msg = "PCV liquidated and items saved.";

    } catch (Exception $e) { 
        $pdo->rollBack(); 
        $error_msg = "Liquidation Error: " . $e->getMessage(); 
    }
}

if ($can_delete && isset($_POST['delete_pcv'])) {
    try {
        $id = $_POST['del_id']; 
        $pdo->prepare("DELETE FROM petty_cash_expenses WHERE id = ?")->execute([$id]);
        $success_msg = "PCV deleted.";
    } catch (Exception $e) { $error_msg = "Delete Error: " . $e->getMessage(); }
}

// ---------------------------------------------------------
// 3. FETCH DATA
// ---------------------------------------------------------
$f_start = $_GET['start'] ?? date('Y-01-01'); 
$f_end   = $_GET['end']   ?? date('Y-m-t');   
$f_cats  = isset($_GET['cat']) ? (is_array($_GET['cat']) ? $_GET['cat'] : []) : [];

// A. Active Wallets
// FIX: Added COALESCE(..., 0) to amount_returned inside the SUM
$sql_funds = "SELECT 
                ci.id, ci.amount as total_allocation, o.transaction_ref, li.name as line_item_name,
                COALESCE(SUM(pce.amount - COALESCE(pce.amount_returned, 0)), 0) as total_spent
              FROM charge_items ci 
              JOIN obligations o ON ci.obligation_id = o.id 
              JOIN line_items li ON ci.line_item_id = li.id 
              LEFT JOIN petty_cash_expenses pce ON ci.id = pce.charge_item_id
              WHERE o.is_cash_advance = 1 AND o.status = 'disbursing'
              GROUP BY ci.id ORDER BY o.transaction_ref ASC";
$active_funds = $pdo->query($sql_funds)->fetchAll(PDO::FETCH_ASSOC);
$total_cash_on_hand = 0;
foreach($active_funds as &$rf) { 
    $rf['current_balance'] = $rf['total_allocation'] - $rf['total_spent']; 
    if($rf['current_balance'] > -1) $total_cash_on_hand += $rf['current_balance']; 
}

// B. Main History Query
$where = ["pce.pcv_date BETWEEN ? AND ?"];
$params = [$f_start, $f_end];
if (!empty($f_cats)) {
    $placeholders = implode(',', array_fill(0, count($f_cats), '?'));
    $where[] = "pce.category IN ($placeholders)";
    foreach($f_cats as $c) $params[] = $c;
}

$sql_hist = "SELECT pce.*, li.name as source_item, ci.id as fund_id 
             FROM petty_cash_expenses pce 
             JOIN charge_items ci ON pce.charge_item_id = ci.id 
             JOIN obligations o ON ci.obligation_id = o.id 
             JOIN line_items li ON ci.line_item_id = li.id 
             WHERE " . implode(" AND ", $where) . " 
             ORDER BY pce.pcv_date DESC, pce.id DESC";
$stmt = $pdo->prepare($sql_hist);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// C. Fetch Child Items for the Visible Rows
$history_ids = array_column($history, 'id');
$items_map = [];
if (!empty($history_ids)) {
    $in_ids = implode(',', $history_ids);
    $sql_items = "SELECT * FROM petty_cash_items WHERE pc_expense_id IN ($in_ids) ORDER BY id ASC";
    $child_items = $pdo->query($sql_items)->fetchAll(PDO::FETCH_ASSOC);
    foreach($child_items as $child) {
        $items_map[$child['pc_expense_id']][] = $child;
    }
}

// Attach items
foreach ($history as &$h) {
    $h['items'] = $items_map[$h['id']] ?? [];
}
unset($h); 

// D. Unliquidated PCVs — current year only, no child items in petty_cash_items
$current_year = date('Y');
$sql_unliq = "
    SELECT pce.id, pce.pcv_number, pce.payee, pce.particulars,
           pce.amount, pce.pcv_date, pce.category,
           li.name as source_item, ci.id as fund_id,
           DATEDIFF(CURDATE(), pce.pcv_date) as age_days
    FROM petty_cash_expenses pce
    JOIN charge_items ci ON pce.charge_item_id = ci.id
    JOIN obligations o ON ci.obligation_id = o.id
    JOIN line_items li ON ci.line_item_id = li.id
    WHERE YEAR(pce.pcv_date) = :yr
      AND NOT EXISTS (
          SELECT 1 FROM petty_cash_items pci WHERE pci.pc_expense_id = pce.id
      )
      AND (pce.amount_returned IS NULL OR ABS(pce.amount - pce.amount_returned) > 1.0)
    ORDER BY pce.pcv_date ASC
";
$stmt_unliq = $pdo->prepare($sql_unliq);
$stmt_unliq->execute([':yr' => $current_year]);
$unliquidated       = $stmt_unliq->fetchAll(PDO::FETCH_ASSOC);
$total_unliq_count  = count($unliquidated);
$total_unliq_amount = array_sum(array_column($unliquidated, 'amount'));

$cats_db = $pdo->query("SELECT DISTINCT category FROM petty_cash_expenses ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$is_fuel_mode = (!empty($f_cats) && (strpos(strtolower($f_cats[0]), 'fuel') !== false || strpos(strtolower($f_cats[0]), 'gas') !== false));
$export_query = "start=$f_start&end=$f_end";
if(!empty($f_cats)) { foreach($f_cats as $fc) $export_query .= "&cat[]=" . urlencode($fc); }
?>

<div class="container-fluid mt-4 px-4 screen-only">
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-success text-white border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="small text-uppercase opacity-75 fw-bold">Total Cash on Hand</div>
                    <h3 class="mb-0 fw-bold">₱ <?php echo number_format($total_cash_on_hand, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="card-body p-3 text-white">
                    <div class="small text-uppercase fw-bold" style="opacity:.85;">Unliquidated Vouchers</div>
                    <h3 class="mb-0 fw-bold"><?php echo $total_unliq_count; ?> <span class="fs-6 fw-normal">PCV(s)</span></h3>
                    <div class="small mt-1" style="opacity:.8;">FY <?php echo $current_year; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
                <div class="card-body p-3 text-white">
                    <div class="small text-uppercase fw-bold" style="opacity:.85;">Outstanding Amount</div>
                    <h3 class="mb-0 fw-bold">₱ <?php echo number_format($total_unliq_amount, 2); ?></h3>
                    <div class="small mt-1" style="opacity:.8;">Pending Liquidation</div>
                </div>
            </div>
        </div>
        <?php foreach($active_funds as $af): if($af['current_balance'] < 0) continue; ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body p-3">
                    <div class="text-truncate fw-bold text-dark" title="<?php echo htmlspecialchars($af['line_item_name']); ?>">
                        <?php echo htmlspecialchars($af['line_item_name']); ?>
                    </div>
                    <div class="small text-muted mb-2"><?php echo $af['transaction_ref']; ?></div>
                    <div class="d-flex justify-content-between align-items-end mt-2">
                        <div>
                            <div class="small text-muted text-uppercase" style="font-size: 0.65rem;">Available Balance</div>
                            <h5 class="fw-bold text-primary mb-0">₱ <?php echo number_format($af['current_balance'], 2); ?></h5>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted text-uppercase" style="font-size: 0.65rem;">Total Utilized</div>
                            <h6 class="fw-bold text-danger mb-0">₱ <?php echo number_format($af['total_spent'], 2); ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!-- UNLIQUIDATED CHARGES MONITOR PANEL                          -->
    <!-- ============================================================ -->
    <div class="card shadow-sm border-0 mb-4 screen-only" id="unliqPanel" style="border-left: 5px solid #f59e0b !important;">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center border-bottom">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                <span class="fw-bold text-dark">Unliquidated Charges Monitor</span>
                <?php if ($total_unliq_count > 0): ?>
                <span class="badge bg-danger rounded-pill"><?php echo $total_unliq_count; ?> pending</span>
                <span class="text-muted small">— Outstanding: <strong class="text-danger">₱ <?php echo number_format($total_unliq_amount, 2); ?></strong></span>
                <?php else: ?>
                <span class="badge bg-success rounded-pill">All Clear</span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ($total_unliq_count > 0): ?>
                <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="printAgingReport()"><i class="bi bi-printer-fill me-1"></i>Print Aging</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-secondary" id="unliqToggleBtn" onclick="toggleUnliqPanel()" title="Toggle Panel">
                    <i class="bi bi-chevron-up" id="unliqToggleIcon"></i>
                </button>
            </div>
        </div>
        <div id="unliqPanelBody">
        <?php if ($total_unliq_count > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center small">
                    <thead style="background-color: #fef3c7;">
                        <tr>
                            <th>Date Issued</th>
                            <th>PCV No.</th>
                            <th class="text-start">Payee / Purpose</th>
                            <th>Source Fund</th>
                            <th>Issued</th>
                            <th>Age</th>
                            <?php if ($can_edit): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unliquidated as $u):
                        $age = intval($u['age_days']);
                        if      ($age <= 7)  { $ac = 'secondary'; $al = 'Fresh';    $ai = ''; }
                        elseif  ($age <= 14) { $ac = 'warning text-dark'; $al = 'Due Soon'; $ai = ''; }
                        elseif  ($age <= 30) { $ac = 'danger';    $al = 'Overdue';  $ai = '⚠ '; }
                        else                 { $ac = 'dark';      $al = 'Critical'; $ai = '🔴 '; }
                        $u['items']          = [];
                        $u['amount_returned'] = 0;
                        $u['or_number']      = null;
                        $u['plate_number']   = null;
                        $json_u = htmlspecialchars(json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td class="text-muted"><?php echo date('M d, Y', strtotime($u['pcv_date'])); ?></td>
                        <td><span class="fw-bold" style="color:#d97706;"><?php echo htmlspecialchars($u['pcv_number']); ?></span></td>
                        <td class="text-start">
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($u['payee']); ?></div>
                            <div class="text-muted fst-italic" style="font-size:0.75rem; max-width:300px;"><?php echo htmlspecialchars(explode('[LIQUIDATION DETAILS]:', $u['particulars'])[0]); ?></div>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars($u['source_item']); ?></td>
                        <td class="fw-bold text-dark">₱ <?php echo number_format($u['amount'], 2); ?></td>
                        <td><span class="badge bg-<?php echo $ac; ?>"><?php echo $ai . $age . 'd — ' . $al; ?></span></td>
                        <?php if ($can_edit): ?>
                        <td><button class="btn btn-sm btn-outline-primary border-0" onclick='openEdit(<?php echo $json_u; ?>)' title="Liquidate Now"><i class="bi bi-pencil-square"></i></button></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot style="background-color:#fef3c7;">
                        <tr>
                            <td colspan="4" class="text-end fw-bold">Total Outstanding:</td>
                            <td class="fw-bold text-danger">₱ <?php echo number_format($total_unliq_amount, 2); ?></td>
                            <td colspan="<?php echo $can_edit ? 2 : 1; ?>"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <div class="p-4 text-center text-success fw-bold">
                <i class="bi bi-check-circle-fill fs-4 me-2"></i>No unliquidated vouchers for FY <?php echo $current_year; ?>. All clear!
            </div>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="row g-4">
        <?php if($can_manage): ?>
        <div class="col-lg-4">
            <div class="card shadow border-0 mb-4">
                <div class="card-header bg-primary text-white fw-bold"><i class="bi bi-pencil-square me-2"></i>Issue Petty Cash Voucher</div>
                <div class="card-body">
                    <form method="POST" onsubmit="return confirm('Confirm issuance?');">
                        <div class="mb-3"><label class="form-label small fw-bold text-muted">Source Fund</label><select name="fund_source_id" id="fundSelect" class="form-select border-primary fw-bold" onchange="updateFormDetails()" required><option value="" disabled selected>--Select Source Fund--</option><?php foreach($active_funds as $af): if($af['current_balance'] < 0) continue; ?><option value="<?php echo $af['id']; ?>" data-bal="<?php echo $af['current_balance']; ?>" data-cat="<?php echo htmlspecialchars($af['line_item_name']); ?>"><?php echo $af['transaction_ref']; ?> : <?php echo htmlspecialchars($af['line_item_name']); ?></option><?php endforeach; ?></select><div class="form-text text-primary small fw-bold" id="balDisplay"></div></div>
                        <div class="row g-2 mb-2"><div class="col-6"><label class="small fw-bold text-muted">Date</label><input type="date" name="pcv_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div><div class="col-6"><label class="small fw-bold text-muted">Amount</label><input type="text" name="amount" class="form-control fw-bold text-danger" required placeholder="0.00" oninput="formatAmount(this)"></div></div>
                        <div class="mb-2"><label class="small fw-bold text-muted">Category</label><input type="text" id="catDisplay" class="form-control bg-light fw-bold" readonly placeholder="Select a Source Fund first"></div>
                        <div class="mb-2"><label class="small fw-bold text-muted">Payee</label><input type="text" name="payee" class="form-control" required></div>
                        <div class="mb-2"><label class="small fw-bold text-muted">Particulars</label><textarea name="particulars" class="form-control" rows="2" required></textarea></div>
                        <button type="submit" name="save_pcv" class="btn btn-primary w-100 fw-bold">Save & Deduct</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?php echo $can_manage ? 'col-lg-8' : 'col-12'; ?>">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-primary mb-0">Expense Register</h6>
                        <div>
                            <a href="export_petty_cash.php?<?php echo $export_query; ?>" target="_blank" class="btn btn-sm btn-success fw-bold me-1"><i class="bi bi-file-earmark-excel-fill"></i> XLS</a>
                            <button class="btn btn-sm btn-dark fw-bold" onclick="printReport()"><i class="bi bi-printer-fill"></i> Report</button>
                        </div>
                    </div>
                    <form method="GET" class="row g-2 align-items-end p-2 bg-light rounded border">
                        <div class="col-md-3"><label class="small fw-bold text-muted">Start Date</label><input type="date" name="start" class="form-control form-control-sm" value="<?php echo $f_start; ?>"></div>
                        <div class="col-md-3"><label class="small fw-bold text-muted">End Date</label><input type="date" name="end" class="form-control form-control-sm" value="<?php echo $f_end; ?>"></div>
                        <div class="col-md-4">
                            <div class="multiselect-container" id="catMultiSelect">
                                <div class="multiselect-display form-control form-control-sm bg-white" onclick="toggleMultiSelect()"><span id="multiselect-text">All Categories</span><i class="bi bi-chevron-down float-end small mt-1"></i></div>
                                <div class="multiselect-options shadow-sm border rounded"><?php foreach($cats_db as $c): ?><label class="multiselect-option"><input type="checkbox" name="cat[]" value="<?php echo $c; ?>" <?php echo in_array($c, $f_cats) ? 'checked' : ''; ?> onchange="updateMultiSelectText()"> <?php echo htmlspecialchars($c); ?></label><?php endforeach; ?></div>
                            </div>
                        </div>
                        <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">Filter</button></div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center small">
                            <thead class="table-dark">
                                <tr><th>Date</th><th>Ref / OR</th><th class="text-start">Particulars</th><th>Issued</th><th>Actual</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php if(count($history) > 0): foreach($history as $h): 
                                    $net_actual = $h['amount'] - $h['amount_returned'];
                                    $has_return = $h['amount_returned'] > 0;
                                    // FIX: Safer JSON encoding for JS passing
                                    $json_data = htmlspecialchars(json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td class="text-muted"><?php echo date('M d', strtotime($h['pcv_date'])); ?></td>
                                    <td><div class="fw-bold text-primary"><?php echo $h['pcv_number']; ?></div></td>
                                    <td class="text-start">
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($h['payee']); ?></div>
                                        <div class="text-muted fst-italic" style="font-size: 0.75rem; max-width:300px;"><?php echo htmlspecialchars(explode("[LIQUIDATION DETAILS]:", $h['particulars'])[0]); ?></div>
                                        <?php if (!empty($h['items'])): ?>
                                        <span class="badge rounded-pill text-success" style="font-size:0.6rem; border:1px solid #198754; background:#d1fae5;">✓ Liquidated</span>
                                        <?php elseif (abs($h['amount'] - (float)$h['amount_returned']) <= 1.0): ?>
                                        <span class="badge rounded-pill text-info" style="font-size:0.6rem; border:1px solid #0dcaf0; background:#e0f8fd;">✓ Returned Intact</span>
                                        <?php else: ?>
                                        <span class="badge rounded-pill text-warning" style="font-size:0.6rem; border:1px solid #f59e0b; background:#fef3c7;">⏳ Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-dark">₱ <?php echo number_format($h['amount'], 2); ?></td>
                                    <td class="fw-bold <?php echo $has_return ? 'text-success' : 'text-danger'; ?>">
                                        ₱ <?php echo number_format($net_actual, 2); ?>
                                        <?php if($has_return): ?><div class="x-small text-success">(Ret: <?php echo number_format($h['amount_returned'],2); ?>)</div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($can_print_ar): ?><button class="btn btn-sm btn-outline-dark border-0 me-1" onclick='printAR(<?php echo $json_data; ?>)' title="Print Receipt"><i class="bi bi-receipt"></i></button><?php endif; ?>
                                        <?php if($can_edit): ?><button class="btn btn-sm btn-outline-primary border-0" onclick='openEdit(<?php echo $json_data; ?>)' title="Liquidate/Edit"><i class="bi bi-pencil-square"></i></button><?php endif; ?>
                                        <?php if($can_delete): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete?');">
                                                <input type="hidden" name="del_id" value="<?php echo $h['id']; ?>">
                                                <button type="submit" name="delete_pcv" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?><tr><td colspan="6" class="py-4 text-muted">No expenses found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if($can_edit): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Liquidate / Edit Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="liquidationForm">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <input type="hidden" name="update_pcv" value="1">
                    <input type="hidden" id="edit_category" value="">
                    
                    <div class="row g-3">
                        <div class="col-md-5 border-end">
                            <h6 class="fw-bold text-muted">Voucher Details</h6>
                            <div class="mb-2"><label class="small fw-bold">Payee</label><input type="text" name="edit_payee" id="edit_payee" class="form-control" required></div>
                            <div class="mb-2">
                                <label class="small fw-bold">Issued Amount</label>
                                <div class="input-group"><span class="input-group-text bg-light fw-bold">₱</span><input type="text" name="edit_issued_amount" id="edit_issued_amount" class="form-control fw-bold text-dark bg-white" readonly></div>
                            </div>
                            <div class="mb-2"><label class="small fw-bold">Original Purpose</label><textarea name="edit_base_particulars" id="edit_base_particulars" class="form-control" rows="3" required></textarea></div>
                        </div>

                        <div class="col-md-7">
                            <h6 class="fw-bold text-muted d-flex justify-content-between">
                                Breakdown of Receipts
                                <button type="button" class="btn btn-sm btn-outline-success py-0" onclick="addReceiptRow()">+ Add Receipt</button>
                            </h6>
                            <div class="table-responsive border rounded mb-2" style="max-height: 250px; overflow-y: auto;">
                                <table class="table table-sm table-borderless mb-0" id="receiptTable">
                                    <thead class="bg-light sticky-top">
                                        <tr><th width="15%">OR Date</th><th width="15%">OR No.</th><th width="18%" class="plate-col d-none">Plate #</th><th width="30%">Description</th><th width="17%">Amount</th><th width="5%"></th></tr>
                                    </thead>
                                    <tbody id="receiptBody"></tbody>
                                </table>
                            </div>
                            <div class="card bg-light border-0 p-2">
                                <div class="row g-2 align-items-center">
                                    <div class="col-7 text-end small fw-bold">TOTAL UTILIZED:</div>
                                    <div class="col-5"><input type="text" name="edit_total_utilized" id="edit_total_utilized" class="form-control form-control-sm fw-bold text-success text-end" readonly value="0.00"></div>
                                    <div class="col-7 text-end small fw-bold">CASH RETURN:</div>
                                    <div class="col-5"><input type="text" name="edit_cash_return" id="edit_cash_return" class="form-control form-control-sm fw-bold text-danger text-end" readonly value="0.00"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 text-end"><button type="submit" class="btn btn-success fw-bold px-4">Save Liquidation</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="printableAR" class="d-none">
    <?php for($i=0; $i<2; $i++): $class = $i==0 ? 'top-half' : 'bottom-half'; ?>
    <div class="ar-half <?php echo $class; ?>">
        <div class="watermark-container" style="display:flex; justify-content:center;">
            <img src="assets/images/lgu.cho_logo.png" alt="Logo" style="width:600px; height:auto; display:block; margin:0 auto;">
        </div>
        <div class="text-center mb-3">
            <div class="fw-bold" style="font-size: 11pt;">Republic of the Philippines</div>
            <div class="fw-bold" style="font-size: 12pt;">PARAÑAQUE CITY HEALTH OFFICE</div>
            <div class="fw-bold mt-2" style="font-size: 14pt; text-decoration: underline;">ACKNOWLEDGEMENT RECEIPT</div>
        </div>
        <div class="d-flex justify-content-between mb-2 small">
            <div>Control No: <span class="ar_pcv fw-bold text-danger"></span></div>
            <div>Date: <span class="ar_date fw-bold"></span></div>
        </div>
        <div style="font-size: 11pt; line-height: 1.8;">
            I acknowledge receipt of the amount of <span class="fw-bold text-decoration-underline">PESOS : <span class="ar_amt_fig"></span></span> representing payment for:
        </div>
        <div class="border p-2 my-2 fw-bold text-center ar_particulars" style="min-height: 50px; background: #f9f9f9;"></div>
        <div class="print-grid mt-4">
            <div><div class="small mb-4">PAID BY:</div><div class="fw-bold text-uppercase text-decoration-underline">EVELYN OLIVEROS</div><div class="small">Petty Cash Custodian</div></div>
            <div><div class="small mb-4">RECEIVED BY:</div><div class="fw-bold text-uppercase text-decoration-underline ar_payee"></div><div class="small">Payee / Staff</div></div>
        </div>
        <div class="d-flex justify-content-between mt-4">
            <div class="border border-dark p-2 text-start" style="width: 30%; font-size: 7pt; color: #555;"><strong>IMPORTANT:</strong><br>Ask for OR addressed to<br><u>PARAÑAQUE CITY HEALTH OFFICE</u><br>TIN: 000-794-764-000</div>
            <div class="border border-dark p-2 text-center" style="width: 30%;">
                <div class="small fw-bold text-start text-muted" style="font-size: 7pt;">CASH RETURN (CHANGE)</div>
                <div class="ar_return_amt h5 fw-bold text-danger mt-2"></div>
            </div>
            <div class="border border-dark p-2 text-center" style="width: 30%;"><div class="small fw-bold text-start text-muted" style="font-size: 7pt;">OFFICIAL RECEIPT NO.</div></div>
        </div>
    </div>
    <?php if($i==0) echo '<div class="cut-line"><span>✂ &nbsp; &nbsp; Cut Here &nbsp; &nbsp; ✂</span></div>'; ?>
    <?php endfor; ?>
</div>

<div id="printableReport" class="d-none">
    <?php
    $report_history = $history;
    usort($report_history, function($a, $b) {
        $cat_cmp = strcmp($a['category'], $b['category']);
        if ($cat_cmp !== 0) return $cat_cmp;
        
        $date_cmp = strcmp($a['pcv_date'], $b['pcv_date']);
        if ($date_cmp !== 0) return $date_cmp;
        
        return $a['id'] <=> $b['id'];
    });
    ?>
    <div class="report-wrapper">
        <div class="text-center mb-4">
            <img src="assets/images/lgu.cho_logo.png" alt="CHO Logo" style="max-height: 100px; display: block; margin: 0 auto;">
            <h5 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif; font-size: 14pt;">Republic of the Philippines</h5>
            <h4 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif; font-size: 16pt;">OFFICE OF THE CITY HEALTH OFFICER</h4>
            <div class="mb-3" style="font-family: 'Times New Roman', serif; font-size: 14pt;">City of Parañaque</div>
            <h5 class="fw-bold text-uppercase d-inline-block pb-1" style="font-family: 'Times New Roman', serif; border-bottom: 2px solid black;">
                <?php echo $is_fuel_mode ? "GAS AND OIL EXPENSES" : "SUMMARY OF EXPENSES"; ?>
            </h5>
            <div class="fw-bold mt-1" style="font-family: 'Times New Roman', serif;">Period: <?php echo date('M d', strtotime($f_start)) . ' - ' . date('M d, Y', strtotime($f_end)); ?></div>
        </div>
        <table class="report-table">
            <?php if ($is_fuel_mode): ?>
                <thead><tr><th style="width:15%">OR DATE</th><th style="width:15%">OR #</th><th style="width:15%">PLATE #</th><th style="width:40%">PARTICULARS</th><th style="width:15%">AMOUNT</th></tr></thead>
                <tbody>
                    <?php $total=0; foreach($report_history as $h): 
                        if (empty($h['items']) && abs($h['amount'] - (float)$h['amount_returned']) <= 1.0) continue;
                        $net_parent = $h['amount'] - $h['amount_returned'];
                        $total += $net_parent;
                        if (!empty($h['items'])) {
                            foreach($h['items'] as $itm) {
                                $or_dt = !empty($itm['or_date']) ? date('m/d/Y', strtotime($itm['or_date'])) : '&mdash;';
                                echo '<tr><td class="text-center">'.$or_dt.'</td><td class="text-center">'.$itm['or_number'].'</td><td class="text-center fw-bold">'.$itm['plate_number'].'</td><td>'.$itm['description'].'</td><td class="text-end">'.number_format($itm['amount'], 2).'</td></tr>';
                            }
                        } else {
                            echo '<tr><td class="text-center">&mdash;</td><td class="text-center">'.$h['or_number'].'</td><td class="text-center fw-bold">'.$h['plate_number'].'</td><td>'.htmlspecialchars(explode("[LIQUIDATION", $h['particulars'])[0]).'</td><td class="text-end">'.number_format($net_parent, 2).'</td></tr>';
                        }
                    endforeach; ?>
                    <tr class="total-row"><td colspan="4" class="text-center fw-bold bg-salmon">GRAND TOTAL</td><td class="text-end fw-bold bg-salmon">₱ <?php echo number_format($total, 2); ?></td></tr>
                </tbody>
            <?php else: ?>
                <thead><tr><th style="width:15%">OR DATE</th><th style="width:17%">OR NO.</th><th style="width:53%">PARTICULARS</th><th style="width:15%">NET EXPENSE</th></tr></thead>
                <tbody>
                    <?php $grand=0; $cat=""; $sub=0; foreach($report_history as $h):
                        if (empty($h['items']) && abs($h['amount'] - (float)$h['amount_returned']) <= 1.0) continue;
                        $net_parent = $h['amount'] - $h['amount_returned'];
                        if($cat !== $h['category']) {
                            if($cat!=="") echo "<tr class='bg-yellow'><td colspan='3' class='text-end fw-bold'>Sub-Total $cat</td><td class='text-end fw-bold'>".number_format($sub,2)."</td></tr>";
                            $cat = $h['category']; $sub=0;
                            echo "<tr><td colspan='4' class='fw-bold ps-2 text-uppercase' style='text-align:left;'>$cat</td></tr>";
                        }
                        $sub += $net_parent; $grand += $net_parent;
                        if (!empty($h['items'])) {
                            foreach($h['items'] as $itm) {
                                $or_dt2 = !empty($itm['or_date']) ? date('m/d/Y', strtotime($itm['or_date'])) : '&mdash;';
                                echo '<tr><td class="text-center">'.$or_dt2.'</td><td class="text-center">'.$itm['or_number'].'</td><td style="text-align: left;">'.$itm['description'].'</td><td class="text-end">'.number_format($itm['amount'], 2).'</td></tr>';
                            }
                        } else {
                            echo '<tr><td class="text-center">&mdash;</td><td class="text-center">'.$h['or_number'].'</td><td style="text-align: left;">'.htmlspecialchars(explode("[LIQUIDATION", $h['particulars'])[0]).'</td><td class="text-end">'.number_format($net_parent, 2).'</td></tr>';
                        }
                    endforeach; ?>
                    <?php if($cat!=="") echo "<tr class='bg-yellow'><td colspan='3' class='text-end fw-bold'>Sub-Total $cat</td><td class='text-end fw-bold'>".number_format($sub,2)."</td></tr>"; ?>
                    <tr class="total-row"><td colspan="3" class="text-center fw-bold bg-salmon">GRAND TOTAL</td><td class="text-end fw-bold bg-salmon">₱ <?php echo number_format($grand, 2); ?></td></tr>
                </tbody>
            <?php endif; ?>
        </table>
        <div class="mt-4 fw-bold" style="font-family: 'Times New Roman', serif;">Certified correct and complete:</div>
        <div class="print-grid mt-5 text-center">
            <div><div class="fw-bold text-uppercase text-decoration-underline">EVELYN OLIVEROS</div><div class="small">Petty Cash Custodian</div></div>
            <div><div class="fw-bold text-uppercase text-decoration-underline">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</div><div class="small">OIC-City Health Officer</div></div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- PRINTABLE AGING REPORT                                       -->
<!-- ============================================================ -->
<div id="printableAgingReport" class="d-none">
    <div class="report-wrapper">
        <div class="text-center mb-4">
            <img src="assets/images/lgu.cho_logo.png" alt="CHO Logo" style="max-height: 100px; display: block; margin: 0 auto;">
            <h5 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif; font-size: 14pt;">Republic of the Philippines</h5>
            <h4 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif; font-size: 16pt;">OFFICE OF THE CITY HEALTH OFFICER</h4>
            <div style="font-family: 'Times New Roman', serif; font-size: 13pt;">City of Parañaque</div>
            <h5 class="fw-bold text-uppercase d-inline-block pb-1 mt-2" style="font-family: 'Times New Roman', serif; border-bottom: 2px solid black;">
                UNLIQUIDATED PETTY CASH VOUCHERS &mdash; AGING REPORT
            </h5>
            <div style="font-family: 'Times New Roman', serif;">As of: <?php echo date('F d, Y'); ?> &nbsp;|&nbsp; Fiscal Year: <strong><?php echo $current_year; ?></strong></div>
        </div>
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width:11%">DATE</th>
                    <th style="width:14%">PCV NO.</th>
                    <th style="width:20%">PAYEE</th>
                    <th style="width:32%">PURPOSE</th>
                    <th style="width:12%">AMOUNT</th>
                    <th style="width:6%">AGE</th>
                    <th style="width:5%">STATUS</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($unliquidated)): foreach ($unliquidated as $u):
                $age_p = intval($u['age_days']);
                if      ($age_p <= 7)  $status_p = 'Fresh';
                elseif  ($age_p <= 14) $status_p = 'Due Soon';
                elseif  ($age_p <= 30) $status_p = 'OVERDUE';
                else                   $status_p = 'CRITICAL';
            ?>
            <tr>
                <td class="text-center"><?php echo date('m/d/Y', strtotime($u['pcv_date'])); ?></td>
                <td><?php echo htmlspecialchars($u['pcv_number']); ?></td>
                <td><?php echo htmlspecialchars($u['payee']); ?></td>
                <td><?php echo htmlspecialchars(explode('[LIQUIDATION DETAILS]:', $u['particulars'])[0]); ?></td>
                <td class="text-end"><?php echo number_format($u['amount'], 2); ?></td>
                <td class="text-center"><?php echo $age_p; ?>d</td>
                <td class="text-center"><?php echo $status_p; ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center">No unliquidated vouchers for FY <?php echo $current_year; ?>.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="4" class="text-end fw-bold bg-salmon">TOTAL OUTSTANDING</td>
                    <td class="text-end fw-bold bg-salmon"><?php echo number_format($total_unliq_amount, 2); ?></td>
                    <td colspan="2" class="bg-salmon"></td>
                </tr>
            </tfoot>
        </table>
        <div class="mt-4 fw-bold" style="font-family: 'Times New Roman', serif;">Prepared and certified by:</div>
        <div class="print-grid mt-5 text-center">
            <div><div class="fw-bold text-uppercase text-decoration-underline">EVELYN OLIVEROS</div><div class="small">Petty Cash Custodian</div></div>
            <div><div class="fw-bold text-uppercase text-decoration-underline">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</div><div class="small">OIC-City Health Officer</div></div>
        </div>
    </div>
</div>

<style>
    .multiselect-container { position: relative; width: 100%; } .multiselect-display { cursor: pointer; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; }
    .multiselect-options { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; z-index: 1000; max-height: 250px; overflow-y: auto; padding: 5px; }
    .multiselect-option { display: block; padding: 3px 10px; cursor: pointer; font-size: 0.9rem; } .multiselect-option:hover { background-color: #f8f9fa; }
    #printableReport, #printableAR, #printableAgingReport { display: none; }
    @media print {
        @page { size: A4 portrait !important; margin: 0 !important; }
        .screen-only, .no-print-section, body > .container-fluid { display: none !important; }
        html, body { height: auto !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; overflow: visible !important; }
        body.printing-report #printableReport { display: block !important; position: absolute; top: 0; left: 0; width: 100%; } body.printing-report #printableReport * { visibility: visible; }
        body.printing-ar #printableAR { display: block !important; position: absolute; top: 0; left: 0; width: 100%; height: 100%; } body.printing-ar #printableAR * { visibility: visible; }
        body.printing-aging #printableAgingReport { display: block !important; position: absolute; top: 0; left: 0; width: 100%; } body.printing-aging #printableAgingReport * { visibility: visible; }
        .report-wrapper { width: 7in !important; margin: 0 auto; padding-top: 0.5in; background: white; }
        .ar-half { height: 48%; width: 7.5in; margin: 0 auto; padding: 0.4in; position: relative; background: white; }
        .cut-line { height: 4%; display: flex; align-items: center; justify-content: center; border-bottom: 2px dashed #999; font-size: 0.8rem; color: #666; width: 100%; }
        .cut-line span { background: white; padding: 0 10px; position: relative; top: 10px; }
        .watermark-container { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 300px; opacity: 0.1; z-index: 0; pointer-events: none; }
        .print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; width: 100%; }
        table.report-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 9pt; margin-bottom: 20px; }
        table.report-table th, table.report-table td { border: 1px solid #000 !important; padding: 3px 4px; vertical-align: middle; word-wrap: break-word; }
        table.report-table thead th { text-align: center; font-weight: bold; font-family: 'Times New Roman', serif; background-color: white; }
        .text-center { text-align: center !important; } .text-end { text-align: right !important; } .fw-bold { font-weight: bold !important; }
        .bg-yellow { background-color: #ffffcc !important; -webkit-print-color-adjust: exact; } .bg-salmon { background-color: #ff9999 !important; -webkit-print-color-adjust: exact; }
    }
</style>

<script>
function toggleMultiSelect() { const list = document.querySelector('.multiselect-options'); list.style.display = list.style.display === 'block' ? 'none' : 'block'; }
document.addEventListener('click', function(e) { const container = document.querySelector('.multiselect-container'); if (!container.contains(e.target)) { document.querySelector('.multiselect-options').style.display = 'none'; }});
function updateMultiSelectText() { const checkboxes = document.querySelectorAll('.multiselect-option input:checked'); const display = document.getElementById('multiselect-text'); if (checkboxes.length === 0) display.innerText = "All Categories"; else if (checkboxes.length <= 2) { let text = []; checkboxes.forEach(c => text.push(c.parentNode.innerText.trim())); display.innerText = text.join(", "); } else display.innerText = checkboxes.length + " Selected"; }
function formatAmount(i) { let v = i.value.replace(/[^0-9.]/g, ''), p = v.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); i.value = p.join('.') + (p.length>1 && p[1].length>2 ? '' : ''); }
function updateFormDetails() { const sel = document.getElementById('fundSelect'); if(sel.selectedIndex >= 0 && sel.value !== "") { const opt = sel.options[sel.selectedIndex]; document.getElementById('balDisplay').innerText = "Max: ₱ " + parseFloat(opt.getAttribute('data-bal')).toLocaleString('en-US',{minimumFractionDigits:2}); document.getElementById('catDisplay').value = opt.getAttribute('data-cat'); } else { document.getElementById('balDisplay').innerText = ""; document.getElementById('catDisplay').value = ""; } }

// Panel Toggle
function toggleUnliqPanel() {
    const body = document.getElementById('unliqPanelBody');
    const icon = document.getElementById('unliqToggleIcon');
    const collapsed = body.style.display === 'none';
    body.style.display = collapsed ? '' : 'none';
    icon.className = collapsed ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

// Print Logic
function printReport() {
    const currentStart = "<?php echo $f_start; ?>";
    const currentEnd = "<?php echo $f_end; ?>";
    const inputStart = document.querySelector('input[name="start"]').value;
    const inputEnd = document.querySelector('input[name="end"]').value;
    
    const formCats = Array.from(document.querySelectorAll('input[name="cat[]"]:checked')).map(cb => cb.value);
    const appliedCats = <?php echo json_encode($f_cats); ?>;
    const catsChanged = JSON.stringify(formCats.sort()) !== JSON.stringify(appliedCats.sort());

    if (inputStart !== currentStart || inputEnd !== currentEnd || catsChanged) {
        const form = document.querySelector('form[method="GET"]');
        let printFlag = document.createElement('input');
        printFlag.type = 'hidden';
        printFlag.name = 'print_auto';
        printFlag.value = '1';
        form.appendChild(printFlag);
        form.submit();
        return;
    }

    document.body.classList.remove('printing-ar', 'printing-aging'); 
    document.body.classList.add('printing-report'); 
    window.print(); 
}

<?php if (isset($_GET['print_auto']) && $_GET['print_auto'] == '1'): ?>
window.addEventListener('DOMContentLoaded', () => {
    const url = new URL(window.location);
    url.searchParams.delete('print_auto');
    window.history.replaceState({}, '', url);
    printReport();
});
<?php endif; ?>
function printAgingReport() { document.body.classList.remove('printing-report', 'printing-ar'); document.body.classList.add('printing-aging'); window.print(); }
function printAR(data) { 
    // Fill Classes for Dual Copy
    document.querySelectorAll('.ar_pcv').forEach(el => el.innerText = data.pcv_number);
    document.querySelectorAll('.ar_date').forEach(el => el.innerText = new Date(data.pcv_date).toLocaleDateString());
    document.querySelectorAll('.ar_amt_fig').forEach(el => el.innerText = parseFloat(data.amount).toLocaleString('en-US', {style:'currency', currency:'PHP'}));
    document.querySelectorAll('.ar_particulars').forEach(el => el.innerText = data.particulars.split("[LIQUIDATION DETAILS]:")[0]); 
    document.querySelectorAll('.ar_payee').forEach(el => el.innerText = data.payee);
    document.querySelectorAll('.ar_return_amt').forEach(el => el.innerText = (data.amount_returned > 0) ? "₱ " + parseFloat(data.amount_returned).toLocaleString('en-US', {minimumFractionDigits:2}) : "");
    
    document.body.classList.remove('printing-report'); document.body.classList.add('printing-ar'); window.print(); 
}
window.onafterprint = function() { document.body.classList.remove('printing-report', 'printing-ar', 'printing-aging'); };

// EDIT / LIQUIDATE LOGIC
function openEdit(data) { 
    document.getElementById('edit_id').value = data.id; 
    document.getElementById('edit_payee').value = data.payee; 
    document.getElementById('edit_issued_amount').value = parseFloat(data.amount).toLocaleString('en-US', {minimumFractionDigits:2}); 
    document.getElementById('edit_category').value = data.category.toLowerCase();
    
    // FIX: Parse particulars to remove previous liquidation text
    let cleanParticulars = data.particulars.split("[LIQUIDATION DETAILS]:")[0].trim();
    document.getElementById('edit_base_particulars').value = cleanParticulars;
    
    // Check fuel for columns
    const isFuel = data.category.toLowerCase().includes('fuel') || data.category.toLowerCase().includes('oil');
    document.querySelectorAll('.plate-col').forEach(el => el.classList.toggle('d-none', !isFuel));
    
    // Load Child Items
    const tbody = document.getElementById('receiptBody');
    tbody.innerHTML = '';
    
    if (data.items && data.items.length > 0) {
        data.items.forEach(item => {
            addReceiptRow(item.or_number, item.or_date || '', item.plate_number, item.description, item.amount);
        });
    } else {
        // Empty row if no items yet
        addReceiptRow(); 
    }
    
    calcLiquidation();
    new bootstrap.Modal(document.getElementById('editModal')).show(); 
}

function addReceiptRow(orVal='', dateVal='', plateVal='', descVal='', amtVal='') {
    const isFuel = document.getElementById('edit_category').value.includes('fuel') || document.getElementById('edit_category').value.includes('oil');
    const plateDisplay = isFuel ? '' : 'd-none';
    const ts = Date.now() + Math.random(); // unique id
    
    const row = `<tr>
        <td><input type="date" name="receipts[${ts}][date]" value="${dateVal}" class="form-control form-control-sm" title="OR Date"></td>
        <td><input type="text" name="receipts[${ts}][or]" value="${orVal}" class="form-control form-control-sm" placeholder="OR#"></td>
        <td class="${plateDisplay}"><input type="text" name="receipts[${ts}][plate]" value="${plateVal}" class="form-control form-control-sm" placeholder="Plate #"></td>
        <td><input type="text" name="receipts[${ts}][desc]" value="${descVal}" class="form-control form-control-sm" placeholder="Item name"></td>
        <td><input type="text" name="receipts[${ts}][amt]" value="${amtVal}" class="form-control form-control-sm receipt-amt text-end" placeholder="0.00" oninput="formatAmount(this); calcLiquidation()"></td>
        <td><button type="button" class="btn btn-sm text-danger" onclick="this.closest('tr').remove(); calcLiquidation()">x</button></td>
    </tr>`;
    document.getElementById('receiptBody').insertAdjacentHTML('beforeend', row);
}

function calcLiquidation() {
    let totalUtilized = 0;
    document.querySelectorAll('.receipt-amt').forEach(inp => { totalUtilized += parseFloat(inp.value.replace(/,/g, '')) || 0; });
    const issued = parseFloat(document.getElementById('edit_issued_amount').value.replace(/,/g, '')) || 0;
    const cashReturn = issued - totalUtilized;
    
    document.getElementById('edit_total_utilized').value = totalUtilized.toLocaleString('en-US', {minimumFractionDigits:2});
    document.getElementById('edit_cash_return').value = cashReturn.toLocaleString('en-US', {minimumFractionDigits:2});
    
    const retField = document.getElementById('edit_cash_return');
    if(cashReturn < 0) { retField.classList.remove('text-success'); retField.classList.add('text-danger'); } 
    else { retField.classList.remove('text-danger'); retField.classList.add('text-success'); }
}

document.addEventListener('DOMContentLoaded', () => { if(document.getElementById('fundSelect')) updateFormDetails(); updateMultiSelectText(); });
</script>

<?php require 'includes/footer.php'; ?>