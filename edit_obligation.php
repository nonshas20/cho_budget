<?php
// 1. START SESSION
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'includes/db_connect.php';
require_once 'includes/procurement_bridge.php';

$success_msg = "";
$error_msg   = "";

// ---------------------------------------------------------
// INTERNAL API: Fetch Items
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'get_items_json') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    try {
        $y = $_GET['year'] ?? date('Y');
        $f = $_GET['fund'] ?? 1;
        $checkCols = $pdo->query("SHOW COLUMNS FROM budget_buckets LIKE 'q1_released'");
        $hasLocks = $checkCols->fetch() ? true : false;
        $cols = $hasLocks 
            ? "bb.q1_released, bb.q2_released, bb.q3_released, bb.q4_released, bb.sem1_released, bb.sem2_released, bb.reserve_released"
            : "1 as q1_released, 1 as q2_released, 1 as q3_released, 1 as q4_released, 1 as sem1_released, 1 as sem2_released, 1 as reserve_released";
        $sql = "SELECT bb.id, li.name, ppa.name AS ppa_name, $cols FROM budget_buckets bb JOIN line_items li ON bb.line_item_id = li.id LEFT JOIN budget_ppas ppa ON bb.ppa_id = ppa.id WHERE bb.fiscal_year = ? AND bb.fund_source_id = ? ORDER BY ppa.name ASC, li.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$y, $f]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(empty($results) ? [["id" => "", "name" => "(No items found)"]] : $results);
    } catch (Exception $e) { echo json_encode([["id" => "", "name" => "DB Error"]]); }
    exit; 
}

// ---------------------------------------------------------
// MAIN EDIT LOGIC
// ---------------------------------------------------------
$page_title = 'Edit Obligation PPA';
require 'includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: view_obligations.php"); exit; }

$user_role = $_SESSION['role'] ?? 'viewer';
$is_admin = ($user_role === 'admin');
$is_viewer = ($user_role === 'viewer');
$routing_lock = $is_viewer ? 'disabled' : ''; 

// 1. FETCH DATA
$stmt = $pdo->prepare("SELECT * FROM obligations WHERE id = ?");
$stmt->execute([$id]);
$base_data = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$base_data) die("Record not found.");
$trx_ref = $base_data['transaction_ref'];

// 2. CONTEXT
$current_fund_id = 0; $current_fund_name = "Unknown"; $current_year = date('Y', strtotime($base_data['obligation_date']));
if ($base_data['budget_bucket_id']) {
    $stmt_b = $pdo->prepare("SELECT bb.fund_source_id, bb.fiscal_year, fs.name as fund_name FROM budget_buckets bb JOIN fund_sources fs ON bb.fund_source_id = fs.id WHERE bb.id = ?");
    $stmt_b->execute([$base_data['budget_bucket_id']]);
    $bucket_ctx = $stmt_b->fetch(PDO::FETCH_ASSOC);
    if($bucket_ctx) { $current_fund_id = $bucket_ctx['fund_source_id']; $current_fund_name = $bucket_ctx['fund_name']; $current_year = $bucket_ctx['fiscal_year']; }
}

// 3. FETCH ITEMS
$charge_items = [];
$sql_items = "SELECT ci.*, li.name as item_name, ci.budget_bucket_id as bucket_id, ppa.name as ppa_name 
              FROM charge_items ci 
              JOIN line_items li ON ci.line_item_id = li.id 
              LEFT JOIN budget_buckets bb ON ci.budget_bucket_id = bb.id 
              LEFT JOIN budget_ppas ppa ON bb.ppa_id = ppa.id 
              WHERE ci.obligation_id = ?";
$stmt_items = $pdo->prepare($sql_items);
$stmt_items->execute([$id]);
$charge_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// Fallback logic
if (empty($charge_items) && $trx_ref) {
    $stmt_migrate = $pdo->prepare("SELECT o.amount, li.name, bb.line_item_id, bb.id as bucket_id FROM obligations o JOIN budget_buckets bb ON o.budget_bucket_id = bb.id JOIN line_items li ON bb.line_item_id = li.id WHERE o.transaction_ref = ?");
    $stmt_migrate->execute([$trx_ref]);
    $mig_items = $stmt_migrate->fetchAll(PDO::FETCH_ASSOC);
    foreach($mig_items as $mi) {
        $charge_items[] = [ 'id' => 'new_legacy', 'line_item_id' => $mi['line_item_id'], 'bucket_id' => $mi['bucket_id'], 'item_name' => $mi['name'], 'amount' => $mi['amount'], 'dv_number' => null, 'disbursed_amount' => null ];
    }
}

// STATUS & LOCKS
$current_status = $base_data['status'];
$is_disbursing = ($current_status === 'disbursing' || $current_status === 'liquidated');
$is_liquidated = ($current_status === 'liquidated');

// UI STATE
$plan_lock = ($is_disbursing || $is_viewer) ? 'readonly' : ''; 
$plan_select_class = ($is_disbursing || $is_viewer) ? 'locked-select bg-light' : '';
$add_row_hidden = ($is_disbursing || $is_viewer) ? 'display:none;' : '';
$actuals_lock = ($is_liquidated || $is_viewer) ? 'readonly' : '';

// ---------------------------------------------------------
// HANDLE SAVE
// ---------------------------------------------------------
if (isset($_POST['save_changes'])) {
    try {
        $pdo->beginTransaction();
        $new_status = $_POST['status'];
        $period     = $_POST['charging_period'];
        
        $was_locked = ($_POST['is_locked_mode'] == '1');
        if ($was_locked) {
            $bonded_officer = $_POST['hidden_bonded_officer'] ?? null;
        } else {
            $bonded_officer = isset($_POST['use_bonded']) ? trim($_POST['bonded_officer']) : null;
        }

        $status_note = "";
        $primary_bucket_id = $base_data['budget_bucket_id'];

        if (isset($_POST['bucket_ids'])) {
            $check_buckets = $_POST['bucket_ids'];
            $is_period_locked = false;
            
            // Grab the very first bucket ID in the list to update the parent record
            if (!empty($check_buckets[0])) {
                $primary_bucket_id = $check_buckets[0];
            }
            
            $stmt_check = $pdo->prepare("SELECT * FROM budget_buckets WHERE id = ?");
            foreach($check_buckets as $bid) {
                if(!$bid) continue;
                $stmt_check->execute([$bid]);
                $b = $stmt_check->fetch(PDO::FETCH_ASSOC);
                if ($b) {
                    if ($period == 'q2' && ($b['q2_released'] ?? 1) == 0) $is_period_locked = true;
                    if ($period == 'q3' && ($b['q3_released'] ?? 1) == 0) $is_period_locked = true;
                    if ($period == 'q4' && ($b['q4_released'] ?? 1) == 0) $is_period_locked = true;
                    if ($period == 'sem2' && ($b['sem2_released'] ?? 1) == 0) $is_period_locked = true;
                    if ($period == 'reserve' && ($b['reserve_released'] ?? 1) == 0) $is_period_locked = true;
                }
            }

            if ($is_period_locked && in_array($new_status, ['obligated', 'disbursing', 'liquidated'])) {
                $new_status = 'pending';
                $status_note = " (Forced to Pending due to unreleased funds in $period)";
            }
        }

        // 2. HEADER UPDATE
        $sql_update = "UPDATE obligations SET ppa_name=?, particulars=?, charging_period=?, bonded_officer=?, program_unit=?, status=?, budget_bucket_id=? WHERE id=?";
        $pdo->prepare($sql_update)->execute([
            trim($_POST['ppa_name']),
            trim($_POST['particulars']),
            $period,
            $bonded_officer,
            trim($_POST['program_unit']),
            $new_status,
            $primary_bucket_id,
            $id
        ]);

        // 3. PLAN UPDATE
        if (isset($_POST['bucket_ids'])) { 
            if($trx_ref) {
                $stmt_sib = $pdo->prepare("SELECT id FROM obligations WHERE transaction_ref = ? AND id != ?");
                $stmt_sib->execute([$trx_ref, $id]);
                $siblings = $stmt_sib->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($siblings)) {
                    $placeholders = implode(',', array_fill(0, count($siblings), '?'));
                    $pdo->prepare("UPDATE petty_cash_funds SET obligation_id = ? WHERE obligation_id IN ($placeholders)")->execute(array_merge([$id], $siblings));
                    $pdo->prepare("DELETE FROM charge_items WHERE obligation_id IN ($placeholders)")->execute($siblings);
                    $pdo->prepare("DELETE FROM obligations WHERE id IN ($placeholders)")->execute($siblings);
                }
            }

            $posted_buckets = $_POST['bucket_ids'];
            $posted_amounts = $_POST['amounts'];
            $posted_row_ids = $_POST['item_ids'] ?? []; 
            $kept_ids = [];
            $total_planned = 0;
            $stmt_get_li = $pdo->prepare("SELECT line_item_id FROM budget_buckets WHERE id = ?");

            foreach($posted_buckets as $idx => $bid) {
                if(!$bid) continue;
                $amt = floatval(str_replace(',', '', $posted_amounts[$idx]));
                $row_id = $posted_row_ids[$idx] ?? 'new';

                if($amt > 0) {
                    $stmt_get_li->execute([$bid]);
                    $li_row = $stmt_get_li->fetch();
                    if($li_row) {
                        
                        // Auto-sync the disbursed amount ONLY if we are still in planning phases
                        $auto_disburse_amount = in_array($new_status, ['pending', 'obligated']) ? $amt : null;

                        if (is_numeric($row_id) && $row_id > 0) {
                            if ($auto_disburse_amount !== null) {
                                $pdo->prepare("UPDATE charge_items SET line_item_id=?, budget_bucket_id=?, amount=?, disbursed_amount=? WHERE id=?")
                                    ->execute([$li_row['line_item_id'], $bid, $amt, $auto_disburse_amount, $row_id]);
                            } else {
                                $pdo->prepare("UPDATE charge_items SET line_item_id=?, budget_bucket_id=?, amount=? WHERE id=?")
                                    ->execute([$li_row['line_item_id'], $bid, $amt, $row_id]);
                            }
                            $kept_ids[] = $row_id;
                        } else {
                            $pdo->prepare("INSERT INTO charge_items (obligation_id, line_item_id, budget_bucket_id, amount, disbursed_amount) VALUES (?, ?, ?, ?, ?)")
                                ->execute([$id, $li_row['line_item_id'], $bid, $amt, $auto_disburse_amount]);
                            $kept_ids[] = $pdo->lastInsertId();
                        }
                        $total_planned += $amt;
                    }
                }
            }
            
            if (!empty($posted_row_ids)) {
                $stmt_all = $pdo->prepare("SELECT id FROM charge_items WHERE obligation_id = ?");
                $stmt_all->execute([$id]);
                $db_ids = $stmt_all->fetchAll(PDO::FETCH_COLUMN);
                $to_delete = array_diff($db_ids, $kept_ids);
                if (!empty($to_delete)) {
                    $in = str_repeat('?,', count($to_delete) - 1) . '?';
                    $pdo->prepare("DELETE FROM charge_items WHERE id IN ($in)")->execute(array_values($to_delete));
                }
            }
            $pdo->prepare("UPDATE obligations SET amount = ? WHERE id = ?")->execute([$total_planned, $id]);
        }

        // 4. ACTUALS UPDATE (THE FIX: Only process this if the PPA is actually in the disbursing stage!)
        if (in_array($new_status, ['disbursing', 'liquidated']) && isset($_POST['dv_numbers'])) {
            $dv_ids = $_POST['dv_item_ids'] ?? [];
            $dv_nums = $_POST['dv_numbers'] ?? [];
            $dv_amts = $_POST['dv_amounts'] ?? [];
            $dv_dates = $_POST['dv_dates'] ?? [];

            foreach($dv_ids as $idx => $ci_id) {
                $d_num = trim($dv_nums[$idx]);
                $d_amt = floatval(str_replace(',', '', $dv_amts[$idx]));
                $d_date = !empty($dv_dates[$idx]) ? $dv_dates[$idx] : null;
                if ($ci_id) {
                    $pdo->prepare("UPDATE charge_items SET dv_number=?, disbursed_amount=?, date_disbursed=? WHERE id=?")
                        ->execute([$d_num, $d_amt, $d_date, $ci_id]);
                }
            }
        }

        $pdo->commit();
        log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'UPDATE', "Updated PPA Ref: " . $trx_ref);
        $success_msg = "Changes saved successfully." . $status_note;
        
        // Refresh Data
        $stmt->execute([$id]); $base_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt_items->execute([$id]);
        $charge_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        $current_status = $base_data['status'];
        $is_disbursing = ($current_status === 'disbursing' || $current_status === 'liquidated');
        $is_liquidated = ($current_status === 'liquidated');
        $plan_lock = ($is_disbursing || $is_viewer) ? 'readonly' : ''; 
        $plan_select_class = ($is_disbursing || $is_viewer) ? 'locked-select bg-light' : '';
        $add_row_hidden = ($is_disbursing || $is_viewer) ? 'display:none;' : '';
        $actuals_lock = ($is_liquidated || $is_viewer) ? 'readonly' : '';

    } catch (Exception $e) { $pdo->rollBack(); $error_msg = "Error: " . $e->getMessage(); }
}

// ROUTING & PROCUREMENT BRIDGE
if (isset($_POST['update_routing'])) {
    $new_status = trim($_POST['status_routing']);
    if (!empty($new_status)) {
        $pdo->prepare("UPDATE obligations SET status_routing = ? WHERE id = ?")->execute([$new_status, $id]);
        $pdo->prepare("INSERT INTO routing_history (obligation_id, user_name, status) VALUES (?, ?, ?)")->execute([$id, $_SESSION['username'], $new_status]);
        $success_msg = "Routing movement recorded in Document Diary.";
        $stmt->execute([$id]);
        $base_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// 18-STAGE PROCUREMENT FORWARD HANDLER
if (isset($_POST['forward_procurement_stage'])) {
    $stage_order  = (int)($_POST['stage_order'] ?? 0);
    $proc_proj_id = (int)($_POST['procurement_project_id'] ?? 0);
    $rel_date     = !empty($_POST['release_date']) ? $_POST['release_date'] : date('Y-m-d');
    $rel_remarks  = trim($_POST['release_remarks'] ?? '');
    $actor        = $_SESSION['username'] ?? 'ADMIN';

    if ($stage_order > 0 && $proc_proj_id > 0) {
        $res = forward_procurement_stage_from_budget($pdo, (int)$id, $proc_proj_id, $stage_order, $rel_date, $rel_remarks, $actor);
        if ($res['success']) {
            $success_msg = $res['message'];
            // Re-fetch base_data so status_routing and timestamps are fresh
            $stmt->execute([$id]);
            $base_data = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error_msg = $res['message'];
        }
    }
}

// LINK PROCUREMENT TRACKING CODE
if (isset($_POST['link_procurement_code'])) {
    $manual_code = strtoupper(trim($_POST['manual_document_code'] ?? ''));
    if (!empty($manual_code)) {
        // Backend Guard: prevent linking if code is already bound to another obligation
        $alreadyLinked = check_procurement_code_already_linked($pdo, $manual_code, (int)$id);
        if ($alreadyLinked) {
            $error_msg = "Double-Link Blocked: Procurement Code '{$manual_code}' is already linked to Obligation {$alreadyLinked['transaction_ref']} (#{$alreadyLinked['id']}: {$alreadyLinked['ppa_name']}). Each procurement project can only be linked to one obligation.";
        } else {
            // Backend Guard: check if project is DRAFT or CANCELLED in CHO Procurement
            $procState = check_procurement_project_state($pdo, $manual_code);
            if ($procState && $procState['status'] === 'CANCELLED') {
                $error_msg = "Cannot link: Procurement Project {$manual_code} is CANCELLED in CHO Procurement.";
            } elseif ($procState && (int)$procState['is_locked'] === 0) {
                $error_msg = "Cannot link: Procurement Project {$manual_code} ({$procState['title']}) is currently in DRAFT status in CHO Procurement. It must be Finalized before linking.";
            } else {
                ensure_obligations_has_document_code_column($pdo);
                $pdo->prepare("UPDATE obligations SET document_code = ? WHERE id = ?")->execute([$manual_code, $id]);
                if (empty($base_data['status_routing'])) {
                    $initRouting = "STAGE 1: SUBMISSION OF REQUISITION TO CHO\r\n\r\n" . $manual_code;
                    $pdo->prepare("UPDATE obligations SET status_routing = ? WHERE id = ?")->execute([$initRouting, $id]);
                }
                $success_msg = "Linked to Procurement Tracking Code: " . htmlspecialchars($manual_code);
                $stmt->execute([$id]);
                $base_data = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

// UNLINK PROCUREMENT TRACKING CODE
if (isset($_POST['unlink_procurement_code'])) {
    ensure_obligations_has_document_code_column($pdo);
    $old_code = $base_data['document_code'] ?? '';
    $pdo->prepare("UPDATE obligations SET document_code = NULL WHERE id = ?")->execute([$id]);
    $success_msg = "Unlinked procurement code " . htmlspecialchars($old_code) . " from this obligation.";
    $stmt->execute([$id]);
    $base_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

$stmt_hist = $pdo->prepare("SELECT * FROM routing_history WHERE obligation_id = ? ORDER BY created_at DESC");
$stmt_hist->execute([$id]);
$history = $stmt_hist->fetchAll();

// Fetch linked procurement tracking and 18-stage status
$proc_tracking = get_linked_procurement_project($pdo, $base_data);

// Fetch unlinked procurement projects and already-linked codes for edit validation & dropdown
$available_unlinked_projects = [];
$already_linked_codes_for_edit = [];
$draft_proc_codes_for_edit = [];
try {
    $available_unlinked_projects = get_available_unlinked_procurement_projects($pdo, (int)$id);
    $already_linked_codes_for_edit = $pdo->query("SELECT o.document_code, o.transaction_ref, o.ppa_name FROM cho_budget_db.obligations o WHERE o.document_code IS NOT NULL AND o.document_code != '' AND o.id != " . (int)$id)->fetchAll(PDO::FETCH_ASSOC);
    $draft_proc_codes_for_edit = $pdo->query("SELECT document_code, title, is_locked, status FROM cho_procurement_db.requisition_projects WHERE document_code IS NOT NULL AND (is_locked = 0 OR status = 'CANCELLED')")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
?>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<style>
    .locked-select { pointer-events: none; background-color: #e9ecef; }
</style>

<div class="container-fluid mt-4 px-4 main-content screen-content">
    <div class="card bg-white border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase small fw-bold text-muted mb-1"><i class="bi bi-pencil-square me-1"></i> Transaction Editor</div>
                    <h3 class="fw-bold text-primary mb-0">Edit PPA Transaction</h3>
                    <div class="mt-2">
                        <a href="view_obligations.php" class="btn btn-sm btn-outline-secondary fw-bold"><i class="bi bi-arrow-left me-1"></i> Back to Log</a>
                        
                        <?php if ($current_status === 'pending' || $current_status === 'obligated'): ?>
                            <button onclick="printRoutingSlip()" class="btn btn-sm btn-dark fw-bold ms-2"><i class="bi bi-printer-fill me-1"></i> Print Routing Slip</button>
                        <?php elseif ($current_status === 'liquidated'): ?>
                            <button onclick="printLiquidationReport()" class="btn btn-sm btn-success fw-bold ms-2"><i class="bi bi-file-earmark-bar-graph me-1"></i> Print Liquidation Report</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div class="border rounded p-2 bg-light d-inline-block text-center" style="min-width: 220px;">
                        <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 1px;">Transaction Reference</div>
                        <div class="h3 font-monospace fw-bold text-dark mb-1" style="letter-spacing: -1px;"><?php echo htmlspecialchars($trx_ref); ?></div>
                        <svg id="barcodeDisplay"></svg>
                        <?php if($is_liquidated): ?><div class="mt-1"><span class="badge bg-success text-white w-100"><i class="bi bi-check-circle-fill"></i> LIQUIDATED</span></div>
                        <?php elseif($is_disbursing): ?><div class="mt-1"><span class="badge bg-warning text-dark w-100"><i class="bi bi-cash-stack"></i> DISBURSING</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($success_msg)): ?><div class="alert alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if (!empty($error_msg)): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST">
                <input type="hidden" name="is_locked_mode" value="<?php echo $is_disbursing ? '1' : '0'; ?>">

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-light py-2 border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark small text-uppercase">1. Obligation Request (The Plan)</span>
                        <?php if($is_disbursing): ?><span class="badge bg-secondary"><i class="bi bi-lock-fill"></i> LOCKED</span><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <input type="hidden" id="fy_val" value="<?php echo $current_year; ?>">
                        <input type="hidden" id="fund_val" value="<?php echo $current_fund_id; ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><label class="form-label fw-bold small text-muted">Year</label><input type="text" class="form-control fw-bold bg-white" value="<?php echo $current_year; ?>" readonly></div>
                            <div class="col-md-5"><label class="form-label fw-bold small text-muted">Fund Source</label><input type="text" class="form-control fw-bold bg-white" value="<?php echo htmlspecialchars($current_fund_name); ?>" readonly></div>
                             <div class="col-md-4"><label class="form-label fw-bold small text-muted">Program / Unit</label><input type="text" name="program_unit" class="form-control" value="<?php echo htmlspecialchars($base_data['program_unit'] ?? ''); ?>" <?php echo $plan_lock; ?>></div>
                        </div>

                        <div class="mb-3"><label class="form-label fw-bold small text-muted">PPA Name</label><input type="text" name="ppa_name" class="form-control fw-bold" value="<?php echo htmlspecialchars($base_data['ppa_name'] ?? ''); ?>" required <?php echo $plan_lock; ?>></div>
                        <div class="mb-3"><label class="form-label fw-bold small text-muted">Particulars</label><textarea name="particulars" class="form-control" rows="2" <?php echo $plan_lock; ?>><?php echo htmlspecialchars($base_data['particulars'] ?? ''); ?></textarea></div>

                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted">Charge Items (Planned Allocation)</label>
                            <div class="card bg-white border p-3">
                                <div id="items_container"></div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-3 fw-bold w-100" onclick="addItemRow()" style="<?php echo $add_row_hidden; ?>"><i class="bi bi-plus-circle-fill me-1"></i> Add Item</button>
                                <div class="border-top mt-3 pt-2 d-flex justify-content-between align-items-center">
                                    <span class="text-muted small fw-bold text-uppercase">Total Allocation</span>
                                    <span class="h5 fw-bold text-primary mb-0" id="runningTotal">₱ 0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Charging Period</label>
                                <select name="charging_period" id="period_select" class="form-select border-primary fw-bold <?php echo $plan_select_class; ?>" onchange="validateLocks()">
                                    <?php 
                                    $periods = ['full'=>'Full Release','sem1'=>'1st Sem','sem2'=>'2nd Sem','q1'=>'Q1','q2'=>'Q2','q3'=>'Q3','q4'=>'Q4','reserve'=>'Reserve'];
                                    foreach($periods as $k=>$v) { $sel = ($base_data['charging_period'] == $k) ? 'selected' : ''; echo "<option value='$k' $sel>$v</option>"; }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Status Workflow</label>
                                <select name="status" id="status_select" class="form-select fw-bold bg-light" <?php echo $is_viewer ? 'disabled' : ''; ?> onchange="toggleDisbursementSection()">
                                    <option value="pending" <?php echo $base_data['status']=='pending'?'selected':''; ?>>Pending (Draft)</option>
                                    <option value="obligated" <?php echo $base_data['status']=='obligated'?'selected':''; ?>>Obligated (Approved)</option>
                                    <option value="disbursing" <?php echo $base_data['status']=='disbursing'?'selected':''; ?>>Disbursing (Actuals)</option>
                                    <option value="liquidated" <?php echo $base_data['status']=='liquidated'?'selected':''; ?>>Liquidated (Closed)</option>
                                    <option value="cancelled" <?php echo $base_data['status']=='cancelled'?'selected':''; ?>>Cancelled</option>
                                </select>
                                <div id="lock_warning" class="text-danger x-small mt-1 fw-bold" style="display:none;">
                                    <i class="bi bi-lock-fill"></i> Locked items detected. Stuck at Pending.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="disbursement_section" class="card shadow-sm border-0 mb-4 border-start border-4 border-warning" style="display: none;">
                    <div class="card-header bg-warning bg-opacity-10 py-2 border-bottom"><span class="fw-bold text-dark small text-uppercase"><i class="bi bi-cash-stack me-2"></i>2. Disbursement Processor (Actuals)</span></div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle mb-0" style="font-size: 0.85rem;">
                                <thead class="table-light text-center">
                                    <tr>
                                        <th width="30%">Item</th>
                                        <th width="15%">Planned</th>
                                        <th width="20%">DV No. (YYYY-MM-XXXXX)</th>
                                        <th width="12%">Date</th>
                                        <th width="12%">Actual</th>
                                        <th width="11%">Return</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($charge_items)): foreach($charge_items as $ci): ?>
                                    <tr class="disbursement-row">
                                        <td><span class="fw-bold text-dark small"><?php echo htmlspecialchars($ci['item_name']); ?></span><input type="hidden" name="dv_item_ids[]" value="<?php echo $ci['id']; ?>"></td>
                                        <td class="text-end text-muted planned-amt" data-val="<?php echo $ci['amount']; ?>">₱ <?php echo number_format($ci['amount'], 2); ?></td>
                                        <td><input type="text" name="dv_numbers[]" class="form-control form-control-sm font-monospace text-center" value="<?php echo htmlspecialchars($ci['dv_number']??''); ?>" placeholder="YYYY-MM-XXXXX" oninput="formatDV(this)" maxlength="15" <?php echo $actuals_lock; ?>></td>
                                        <td><input type="date" name="dv_dates[]" class="form-control form-control-sm" value="<?php echo $ci['date_disbursed'] ?? date('Y-m-d'); ?>" <?php echo $actuals_lock; ?>></td>
                                        <td><input type="text" name="dv_amounts[]" class="form-control form-control-sm fw-bold text-end text-success actual-input" value="<?php echo number_format($ci['disbursed_amount'] ?? $ci['amount'], 2); ?>" oninput="formatAmount(this); calcReturn(this);" <?php echo $actuals_lock; ?>></td>
                                        <td class="text-end fw-bold text-danger return-amt">₱ 0.00</td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">Save Plan first.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-5">
                    <div class="form-check">
                        <input type="hidden" name="hidden_bonded_officer" value="<?php echo htmlspecialchars($base_data['bonded_officer']??''); ?>">
                        <input class="form-check-input" type="checkbox" id="bonded_check" name="use_bonded" onchange="toggleBonded()" <?php echo !empty($base_data['bonded_officer'])?'checked':''; ?> <?php echo $is_disbursing ? 'disabled' : ''; ?>>
                        <label class="form-check-label small fw-bold" for="bonded_check">Bonded Officer?</label>
                        <input type="text" name="bonded_officer" id="officer_input" class="form-control form-control-sm mt-1" value="<?php echo htmlspecialchars($base_data['bonded_officer']??''); ?>" <?php echo !empty($base_data['bonded_officer']) && !$is_disbursing ?'':'disabled'; ?>>
                    </div>
                    <?php if(!$is_viewer): ?>
                    <button type="submit" name="save_changes" class="btn btn-primary fw-bold px-4 btn-lg shadow"><i class="bi bi-save me-2"></i> SAVE CHANGES</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <!-- 18-STAGE PHYSICAL PROCUREMENT ROUTING SLIP -->
            <div class="card shadow-sm border-0 mb-4" style="border-top: 4px solid #004253 !important;">
                <div class="card-header bg-white py-2.5 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-uppercase small fw-bold text-muted" style="font-size: 0.68rem; letter-spacing: 0.5px;">Physical Routing Slip</div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-1">
                            <i class="bi bi-folder2-open text-success me-1"></i>18-Stage Tracking
                        </h6>
                    </div>
                    <?php if ($proc_tracking && !empty($proc_tracking['document_code'])): ?>
                        <div class="d-flex align-items-center gap-1.5">
                            <span class="badge font-monospace" style="background-color: #004253; color: #fff; font-size: 0.72rem;">
                                <?= htmlspecialchars($proc_tracking['document_code']); ?>
                            </span>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to unlink <?= htmlspecialchars($proc_tracking['document_code']); ?> from this obligation?');">
                                <button type="submit" name="unlink_procurement_code" class="btn btn-outline-danger btn-xs py-0 px-1.5" style="font-size: 0.65rem;" title="Unlink this procurement code">
                                    <i class="bi bi-x-circle me-1"></i>Unlink
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="badge bg-secondary font-monospace" style="font-size: 0.72rem;">Unlinked</span>
                    <?php endif; ?>
                </div>

                <div class="card-body bg-white p-3">
                    <?php if ($proc_tracking && $proc_tracking['project']): ?>
                        <?php 
                            $curr_st = $proc_tracking['current_stage']; 
                            $is_done = ($proc_tracking['overall_status'] === 'COMPLETED');
                            $is_draft = !empty($proc_tracking['is_draft']);
                            $is_cancelled = !empty($proc_tracking['is_cancelled']);
                        ?>

                        <?php if ($is_cancelled): ?>
                            <!-- Cancelled Alert Banner -->
                            <div class="alert alert-danger p-2.5 small mb-3 d-flex align-items-center gap-2 shadow-2xs">
                                <i class="bi bi-x-circle-fill fs-5 text-danger"></i>
                                <div>
                                    <strong class="text-danger">PROCUREMENT PROJECT CANCELLED</strong>
                                    <div class="text-muted" style="font-size: 0.72rem;">This linked project was marked as CANCELLED in CHO Procurement. Stage advancement is suspended.</div>
                                </div>
                            </div>
                        <?php elseif ($is_draft): ?>
                            <!-- Reverted to Draft Alert Banner -->
                            <div class="alert alert-warning p-2.5 small mb-3 d-flex align-items-center gap-2 shadow-2xs">
                                <i class="bi bi-exclamation-triangle-fill fs-5 text-warning-emphasis"></i>
                                <div>
                                    <strong class="text-dark">PROJECT IN DRAFT (REVERTED IN BUILDER)</strong>
                                    <div class="text-muted" style="font-size: 0.72rem;">This project is currently unlocked in the Requisition Cart. Routing forward actions are paused until re-finalized.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Active Stage Highlight -->
                        <div class="p-3 rounded border mb-3 <?= $is_done ? 'bg-light border-success' : ($is_cancelled ? 'bg-light border-danger' : ($is_draft ? 'bg-light border-warning' : 'bg-light border-teal')) ?>" style="border-left: 4px solid <?= $is_done ? '#198754' : ($is_cancelled ? '#dc3545' : ($is_draft ? '#ffc107' : '#004253')); ?> !important;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge <?= $is_done ? 'bg-success' : ($is_cancelled ? 'bg-danger' : ($is_draft ? 'bg-warning text-dark' : 'bg-primary')) ?> text-uppercase" style="font-size: 0.65rem;">
                                    <?= $is_done ? 'ALL 18 STAGES COMPLETED' : ($is_cancelled ? 'CANCELLED' : ($is_draft ? 'PROJECT IN DRAFT' : 'CURRENT STAGE #' . ($curr_st['stage_order'] ?? 1))); ?>
                                </span>
                                <?php if (!$is_done && !$is_cancelled && !$is_draft): ?>
                                    <?php if ($proc_tracking['is_delayed']): ?>
                                        <span class="badge bg-danger text-white" style="font-size: 0.65rem;">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= $proc_tracking['days_in_stage']; ?>d DELAYED
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-white text-muted border" style="font-size: 0.65rem;">
                                            <i class="bi bi-clock me-1"></i><?= $proc_tracking['days_in_stage']; ?>d at desk
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="fw-bold text-dark text-uppercase small mt-1" style="font-size: 0.85rem;">
                                <?= htmlspecialchars($curr_st['stage_name'] ?? 'Completed'); ?>
                            </div>

                            <div class="text-muted small mt-1" style="font-size: 0.72rem;">
                                <strong>Office:</strong> <span class="text-dark fw-semibold"><?= htmlspecialchars($curr_st['department'] ?? 'CHO'); ?></span> &bull; 
                                <strong>Phase:</strong> <?= htmlspecialchars($curr_st['phase'] ?? 'Requisition'); ?>
                            </div>
                        </div>

                        <!-- Forward / Release Action Form (for Admin / Authorized Staff) -->
                        <?php if (!$is_done && !$is_draft && !$is_cancelled): ?>
                            <fieldset <?= $routing_lock; ?> class="border rounded p-3 bg-white mb-3 shadow-2xs">
                                <legend class="float-none w-auto px-2 small fw-bold text-uppercase text-muted" style="font-size: 0.68rem;">
                                    <i class="bi bi-send-check text-success me-1"></i>Release &amp; Forward to Next Stage
                                </legend>
                                <form method="POST">
                                    <input type="hidden" name="stage_order" value="<?= (int)($curr_st['stage_order'] ?? 1); ?>">
                                    <input type="hidden" name="procurement_project_id" value="<?= (int)$proc_tracking['project']['id']; ?>">

                                    <div class="mb-2">
                                        <label class="form-label small fw-bold text-muted mb-1" style="font-size: 0.72rem;">Date Released / Transmitted</label>
                                        <input type="date" name="release_date" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>" required>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label small fw-bold text-muted mb-1" style="font-size: 0.72rem;">Transmittal Notes / Remarks (Optional)</label>
                                        <input type="text" name="release_remarks" class="form-control form-control-sm" placeholder="e.g. Endorsed with signed memo to next desk">
                                    </div>

                                    <button type="submit" name="forward_procurement_stage" class="btn btn-sm btn-success fw-bold w-100 shadow-sm mt-1">
                                        <i class="bi bi-arrow-right-circle me-1"></i> Release &amp; Forward to Stage <?= min(18, ($curr_st['stage_order'] ?? 1) + 1); ?>
                                    </button>
                                </form>
                            </fieldset>
                        <?php else: ?>
                            <div class="alert alert-success py-2 px-3 small fw-bold mb-3 d-flex align-items-center gap-2">
                                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <span>All 18 routing stages have been successfully completed!</span>
                            </div>
                        <?php endif; ?>

                        <!-- Progress Bar -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center small mb-1" style="font-size: 0.72rem;">
                                <span class="fw-bold text-muted">Routing Milestones</span>
                                <span class="fw-bold text-dark font-monospace"><?= $proc_tracking['completed_count']; ?> / 18 (<?= $proc_tracking['progress_percent']; ?>%)</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $proc_tracking['progress_percent']; ?>%;" aria-valuenow="<?= $proc_tracking['progress_percent']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>

                        <!-- Collapsible 18 Milestones Checklist -->
                        <div class="accordion accordion-flush border rounded mb-3" id="accordionStages">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="flush-headingStages">
                                    <button class="accordion-button collapsed py-2 px-3 small fw-bold text-dark bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#flush-collapseStages" aria-expanded="false" aria-controls="flush-collapseStages" style="font-size: 0.72rem;">
                                        <i class="bi bi-list-check me-2 text-primary"></i> View All 18 Milestones
                                    </button>
                                </h2>
                                <div id="flush-collapseStages" class="accordion-collapse collapse" aria-labelledby="flush-headingStages" data-bs-parent="#accordionStages">
                                    <div class="accordion-body p-0 overflow-auto" style="max-height: 250px;">
                                        <ul class="list-group list-group-flush small" style="font-size: 0.72rem;">
                                            <?php foreach ($proc_tracking['stages'] as $st): ?>
                                                <?php 
                                                    $is_st_done = ($st['status'] === 'COMPLETED');
                                                    $is_st_curr = ($curr_st && $curr_st['id'] == $st['id'] && !$is_done);
                                                ?>
                                                <li class="list-group-item py-1.5 px-2.5 d-flex align-items-start gap-2 <?= $is_st_curr ? 'bg-light text-primary fw-bold' : '' ?>">
                                                    <div class="mt-0.5">
                                                        <?php if ($is_st_done): ?>
                                                            <i class="bi bi-check-circle-fill text-success"></i>
                                                        <?php elseif ($is_st_curr): ?>
                                                            <span class="spinner-grow spinner-grow-sm text-primary" style="width: 0.75rem; height: 0.75rem;" role="status"></span>
                                                        <?php else: ?>
                                                            <i class="bi bi-circle text-muted"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span class="text-truncate <?= $is_st_done ? 'text-decoration-line-through text-muted' : ($is_st_curr ? 'text-dark fw-bold' : 'text-secondary') ?>">
                                                                <?= $st['stage_order']; ?>. <?= htmlspecialchars($st['stage_name']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="text-muted d-flex justify-content-between align-items-center" style="font-size: 0.65rem;">
                                                            <span><?= htmlspecialchars($st['department']); ?></span>
                                                            <?php if (!empty($st['date_released'])): ?>
                                                                <span class="text-success"><?= date('m/d/y', strtotime($st['date_released'])); ?></span>
                                                            <?php elseif (!empty($st['date_submitted'])): ?>
                                                                <span class="text-primary"><?= date('m/d/y', strtotime($st['date_submitted'])); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cross-Link to CHO Procurement System -->
                        <div>
                            <a href="http://127.0.0.1:8000/tracking?project=<?= $proc_tracking['project']['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100 fw-bold d-flex align-items-center justify-content-center gap-1.5" style="font-size: 0.72rem;">
                                <i class="bi bi-box-arrow-up-right"></i>
                                <span>Open in CHO Procurement Workstation</span>
                            </a>
                        </div>

                    <?php else: ?>
                        <!-- Link Procurement Document Form -->
                        <div class="p-3 bg-light rounded border">
                            <div class="d-flex align-items-center gap-2 mb-2 pb-2 border-bottom">
                                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                    <i class="bi bi-folder-symlink-fill"></i>
                                </div>
                                <div class="text-start">
                                    <div class="fw-bold small text-dark">Link to CHO Procurement Tracking</div>
                                    <div class="text-muted small" style="font-size: 0.72rem;">Select an available procurement document or search code.</div>
                                </div>
                            </div>
                            
                            <form method="POST" id="editLinkForm">
                                <div class="mb-2 text-start">
                                    <label class="form-label small fw-bold text-muted mb-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-list-check me-1 text-primary"></i>Available Unlinked Documents (<?= count($available_unlinked_projects); ?>)
                                    </label>
                                    <select id="edit_proc_select" class="form-select form-select-sm" onchange="onEditSelectChange(this.value)">
                                        <option value="">-- Click to select an available procurement document --</option>
                                        <?php foreach($available_unlinked_projects as $p): ?>
                                            <option value="<?= htmlspecialchars($p['document_code']); ?>">
                                                <?= htmlspecialchars($p['document_code']); ?> — <?= htmlspecialchars($p['title']); ?> (FY <?= $p['target_year']; ?> &bull; <?= htmlspecialchars($p['fund_source']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-2 text-start">
                                    <label class="form-label small text-muted mb-1 fw-semibold" style="font-size: 0.72rem;">Or search / enter document code:</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white font-monospace text-muted fw-bold">#</span>
                                        <input 
                                            type="text" 
                                            name="manual_document_code" 
                                            id="edit_manual_doc_code" 
                                            class="form-control font-monospace fw-bold" 
                                            placeholder="PROC-2026-XXXXXX" 
                                            list="editProcDocCodes" 
                                            autocomplete="off"
                                            onclick="if(this.showPicker) try{this.showPicker();}catch(e){}"
                                            oninput="this.value = this.value.toUpperCase(); syncEditSelectWithInput(this.value); validateEditProcCode(this.value);"
                                        >
                                        <button class="btn btn-primary fw-bold" type="submit" name="link_procurement_code" id="editLinkSubmitBtn" disabled>
                                            <i class="bi bi-link-45deg me-1"></i>Link
                                        </button>
                                    </div>
                                </div>

                                <datalist id="editProcDocCodes">
                                    <?php foreach($available_unlinked_projects as $p): ?>
                                        <option value="<?= htmlspecialchars($p['document_code']); ?>"><?= htmlspecialchars($p['title']); ?> (FY <?= $p['target_year']; ?> - <?= htmlspecialchars($p['fund_source']); ?>)</option>
                                    <?php endforeach; ?>
                                </datalist>

                                <!-- Live Validation & Project Preview for Edit View -->
                                <div id="edit_proc_preview" class="mt-2 text-start" style="display: none;"></div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ROUTING MANAGER & DOCUMENT DIARY (PRESERVED) -->
            <div class="card shadow-sm border-0 mb-4"><div class="card-header bg-info text-white fw-bold"><i class="bi bi-signpost-split me-2"></i>Routing Manager</div><div class="card-body bg-white"><fieldset <?php echo $routing_lock; ?>><form method="POST"><div class="mb-3"><label class="form-label small fw-bold text-muted">Update Status</label><textarea name="status_routing" class="form-control border-info" rows="2"><?php echo htmlspecialchars($base_data['status_routing'] ?? ''); ?></textarea></div><button type="submit" name="update_routing" class="btn btn-info text-white fw-bold w-100"><i class="bi bi-send-check me-2"></i>Log Movement</button></form></fieldset></div></div>
            <div class="card shadow-sm border-0"><div class="card-header bg-dark text-white fw-bold"><span><i class="bi bi-journal-text me-2"></i>Document Diary</span></div><div class="card-body p-0 overflow-auto" style="max-height: 400px;"><ul class="list-group list-group-flush small"><?php foreach($history as $log): ?><li class="list-group-item"><div class="d-flex justify-content-between"><span class="fw-bold text-primary"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span></div><div class="mt-1"><?php echo htmlspecialchars($log['status']); ?></div><div class="text-end text-muted fst-italic" style="font-size: 0.75rem;">by <?php echo htmlspecialchars($log['user_name']); ?></div></li><?php endforeach; ?></ul></div></div>
        </div>
    </div>
</div>

<div id="printRouting" class="print-view">
    <div class="print-wrapper">
        <table class="header-table w-100 mb-2">
            <tr>
                <td style="vertical-align: top;">
                    <h5 class="fw-bold mb-0">CITY HEALTH OFFICE</h5>
                    <div class="small">Budget Management System</div>
                    <div class="small fw-bold mt-1">ROUTING SLIP</div>
                </td>
                <td class="text-end" style="vertical-align: top;">
                    <div class="h4 font-monospace fw-bold mb-0"><?php echo htmlspecialchars($trx_ref); ?></div>
                    <div style="max-width: 150px; overflow: hidden; margin-left: auto;">
                        <svg id="barcodeRouting"></svg>
                    </div>
                </td>
            </tr>
        </table>

        <table class="w-100 mb-3 info-table" style="font-size: 12px;">
            <tr><td width="20%" class="fw-bold">PPA Title:</td><td class="border-bottom"><?php echo htmlspecialchars($base_data['ppa_name']); ?></td></tr>
            <tr><td class="fw-bold pt-2">Particulars:</td><td class="pt-2 border-bottom"><?php echo htmlspecialchars($base_data['particulars']); ?></td></tr>
            <tr><td class="fw-bold pt-2">Unit:</td><td class="pt-2 border-bottom"><?php echo htmlspecialchars($base_data['program_unit']); ?></td></tr>
        </table>
        
        <table class="table table-bordered table-sm w-100 print-data-table" style="font-size: 11px;">
            <tr class="bg-light"><th>Budget Item</th><th class="text-end">Amount</th></tr>
            <?php $ptotal=0; foreach($charge_items as $ci): $ptotal+=$ci['amount']; ?>
            <tr><td><?php echo $ci['item_name']; ?></td><td class="text-end fw-bold"><?php echo number_format($ci['amount'],2); ?></td></tr>
            <?php endforeach; ?>
            <tr><td class="text-end fw-bold">TOTAL</td><td class="text-end fw-bold"><?php echo number_format($ptotal,2); ?></td></tr>
        </table>

        <table class="w-100 mt-4 mb-2 signature-table" border="0" style="border:none !important;">
            <tr style="border:none !important;">
                <td class="text-center" width="50%" style="vertical-align: top; border:none !important;">
                    <div class="text-start pl-4" style="margin-left: 20px;">Prepared by:</div>
                    <div class="mt-4 fw-bold text-uppercase text-decoration-underline"><?php echo $_SESSION['fullname'] ?? 'SYSTEM ADMIN'; ?></div>
                    <div class="small">Administrative Officer / Encoder</div>
                </td>
                <td class="text-center" width="50%" style="vertical-align: top; border:none !important;">
                    <div class="text-start pl-4" style="margin-left: 20px;">Noted and Approved by:</div>
                    <div class="mt-4 fw-bold text-uppercase text-decoration-underline">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</div>
                    <div class="small">City Health Officer</div>
                </td>
            </tr>
        </table>
        
        <div class="border-top mt-2 pt-2">
            <h6 class="fw-bold small text-center bg-light p-1 border">ROUTING LOG</h6>
            <table class="table table-bordered w-100 print-data-table" style="font-size: 10px;">
                <tr class="bg-light text-center"><th width="20%">Date/Time</th><th width="40%">Action / Station</th><th width="40%">Received By</th></tr>
                <?php for($i=0; $i<20; $i++): ?><tr><td style="height:25px"></td><td></td><td></td></tr><?php endfor; ?>
            </table>
        </div>
    </div>
</div>

<div id="printLiquidation" class="print-view">
    <div class="print-wrapper">
        <table class="header-table w-100 mb-4">
            <tr>
                <td style="vertical-align: top;">
                    <h5 class="fw-bold mb-0">CITY HEALTH OFFICE</h5>
                    <div class="small">Budget Management System</div>
                    <div class="small fw-bold mt-1">LIQUIDATION REPORT</div>
                </td>
                <td class="text-end" style="vertical-align: top;">
                    <div class="h4 font-monospace fw-bold mb-0"><?php echo htmlspecialchars($trx_ref); ?></div>
                    <div class="small text-muted"><?php echo date('F d, Y'); ?></div>
                </td>
            </tr>
        </table>

        <table class="table table-bordered w-100 print-data-table" style="font-size: 11px;">
            <tr class="bg-light text-center"><th>Line Item</th><th>DV No.</th><th>Planned</th><th>Actual</th><th>Return</th></tr>
            <?php $l_plan=0; $l_act=0; $l_ret=0; foreach($charge_items as $ci): 
                $ret = $ci['amount'] - ($ci['disbursed_amount'] ?? $ci['amount']);
                $l_plan+=$ci['amount']; $l_act+=($ci['disbursed_amount']??$ci['amount']); $l_ret+=$ret;
            ?>
            <tr>
                <td><?php echo $ci['item_name']; ?></td>
                <td class="text-center"><?php echo $ci['dv_number']; ?></td>
                <td class="text-end"><?php echo number_format($ci['amount'],2); ?></td>
                <td class="text-end fw-bold"><?php echo number_format($ci['disbursed_amount']??$ci['amount'],2); ?></td>
                <td class="text-end text-danger fw-bold"><?php echo number_format($ret,2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="bg-light fw-bold">
                <td colspan="2" class="text-end">TOTALS</td>
                <td class="text-end"><?php echo number_format($l_plan,2); ?></td>
                <td class="text-end"><?php echo number_format($l_act,2); ?></td>
                <td class="text-end"><?php echo number_format($l_ret,2); ?></td>
            </tr>
        </table>
        
        <table class="w-100 mt-5 signature-table" border="0" style="border:none !important;">
            <tr style="border:none !important;">
                <td class="text-center" width="50%" style="border:none !important;">
                    <div class="fw-bold text-decoration-underline"><?php echo $_SESSION['fullname'] ?? 'SYSTEM USER'; ?></div>
                    <div class="small">Prepared By</div>
                </td>
                <td class="text-center" width="50%" style="border:none !important;">
                    <div class="fw-bold text-decoration-underline">DR. [CHO NAME HERE]</div>
                    <div class="small">Approved By</div>
                </td>
            </tr>
        </table>
    </div>
</div>

<style>
    .print-view { display: none; }
    
    @media print {
        @page { size: portrait !important; margin: 0.25in !important; }
        body { margin: 0; padding: 0; height: auto; }
        body * { visibility: hidden; }
        
        .screen-content, .no-print-section { display: none !important; }
        
        body.printing-routing #printRouting { display: block !important; position: absolute; top: 0; left: 0; width: 100%; }
        body.printing-routing #printRouting * { visibility: visible; }
        
        body.printing-liquidation #printLiquidation { display: block !important; position: absolute; top: 0; left: 0; width: 100%; }
        body.printing-liquidation #printLiquidation * { visibility: visible; }
        
        .print-wrapper { width: 600px !important; max-width: 600px !important; margin: 0 auto; overflow: hidden; }
        .header-table td { border: none !important; }
        table.signature-table, table.signature-table tr, table.signature-table td { border: none !important; border-style: none !important; border-width: 0 !important; }
        .print-data-table { border-collapse: collapse; width: 100%; }
        .print-data-table th, .print-data-table td { border: 1px solid #000 !important; padding: 4px; }
    }
</style>

<script>
let availableItems = [];
const existingItems = <?php echo json_encode($charge_items); ?>;
const isDisbursing = <?php echo $is_disbursing ? 'true' : 'false'; ?>;
const trxRef = "<?php echo $trx_ref ? $trx_ref : 'LEGACY-'.$id; ?>";

function loadData() {
    let y = document.getElementById('fy_val').value;
    let f = document.getElementById('fund_val').value;
    fetch(`edit_obligation.php?action=get_items_json&year=${y}&fund=${f}`).then(res=>res.json()).then(data=>{
        availableItems = data;
        renderItems();
        toggleDisbursementSection();
        generateBarcode();
        calcAllReturns();
        validateLocks(); // Trigger validation on load
    });
}

function renderItems() {
    const container = document.getElementById('items_container');
    container.innerHTML = '';
    
    if(isDisbursing) {
        existingItems.forEach(item => {
            let disp = item.ppa_name ? `[${item.ppa_name}] ${item.item_name}` : item.item_name;
            const html = `<div class="row g-2 mb-2 border-bottom pb-2"><input type="hidden" name="item_ids[]" value="${item.id}"><input type="hidden" name="bucket_ids[]" value="${item.bucket_id}"><input type="hidden" name="amounts[]" value="${formatNum(item.amount)}"><div class="col-8 fw-bold small text-dark">${disp}</div><div class="col-4 text-end fw-bold text-success">₱ ${formatNum(item.amount)}</div></div>`;
            container.insertAdjacentHTML('beforeend', html);
        });
        calculateTotal(); return;
    }
    
    if(existingItems.length > 0) { existingItems.forEach(item => addItemRow(item.amount, item.bucket_id, item.id)); } else { addItemRow(); }
    calculateTotal();
}

function addItemRow(amount = '', selectedBucketId = '', rowId = '') {
    let options = '<option value="">-- Select --</option>';
    availableItems.forEach(i => {
        let sel = (i.id == selectedBucketId) ? 'selected' : '';
        let disp = i.ppa_name ? `[${i.ppa_name}] ${i.name}` : i.name;
        options += `<option value="${i.id}" ${sel}>${disp}</option>`;
    });
    const html = `<div class="row g-2 mb-2 align-items-center item-row"><input type="hidden" name="item_ids[]" value="${rowId}"><div class="col-md-8"><select name="bucket_ids[]" class="form-select form-select-sm fw-bold border-secondary item-select ${isDisbursing?'locked-select':''}" onchange="validateLocks()">${options}</select></div><div class="col-md-3"><input type="text" name="amounts[]" class="form-control fw-bold text-success amount-input" value="${formatNum(amount)}" oninput="formatAmount(this); calculateTotal();" ${isDisbursing?'readonly':''}></div><div class="col-md-1 text-center"><button type="button" class="btn btn-link text-danger p-0" onclick="this.closest('.item-row').remove(); calculateTotal();"><i class="bi bi-x-circle-fill"></i></button></div></div>`;
    document.getElementById('items_container').insertAdjacentHTML('beforeend', html);
}

function validateLocks() {
    const period = document.getElementById('period_select').value;
    const statusSel = document.getElementById('status_select');
    const warning = document.getElementById('lock_warning');
    let isLocked = false;

    // Don't validate if already saved in Disbursing Mode (assume unlocked via approval)
    if(isDisbursing) return;

    document.querySelectorAll('.item-select').forEach(sel => {
        const item = availableItems.find(i => i.id == sel.value);
        if(item && period) {
            if((period=='q2' && !item.q2_released) || (period=='q3' && !item.q3_released) || (period=='q4' && !item.q4_released) || (period=='sem2' && !item.sem2_released)) isLocked=true;
        }
    });

    if(isLocked) {
        warning.style.display = 'block';
        statusSel.value = 'pending';
        // Force Disable Non-Pending Options
        Array.from(statusSel.options).forEach(opt => {
            if(opt.value !== 'pending' && opt.value !== 'cancelled') opt.disabled = true;
        });
    } else {
        warning.style.display = 'none';
        // Re-enable
        Array.from(statusSel.options).forEach(opt => opt.disabled = false);
    }
}

function toggleDisbursementSection() {
    const status = document.getElementById('status_select').value;
    const section = document.getElementById('disbursement_section');
    section.style.display = (status === 'disbursing' || status === 'liquidated') ? 'block' : 'none';
}

function calculateTotal() {
    let total = 0;
    if(isDisbursing) { document.getElementsByName('amounts[]').forEach(inp => total += parseFloat(inp.value.replace(/,/g, ''))||0); }
    else { document.querySelectorAll('.amount-input').forEach(inp => total += parseFloat(inp.value.replace(/,/g, '')) || 0); }
    document.getElementById('runningTotal').innerText = "₱ " + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function calcReturn(input) {
    const row = input.closest('tr');
    const planned = parseFloat(row.querySelector('.planned-amt').dataset.val) || 0;
    const actual = parseFloat(input.value.replace(/,/g, '')) || 0;
    row.querySelector('.return-amt').innerText = "₱ " + (planned - actual).toLocaleString('en-US', {minimumFractionDigits: 2});
}

function calcAllReturns() { document.querySelectorAll('.actual-input').forEach(inp => calcReturn(inp)); }

function formatDV(input) {
    let v = input.value.replace(/\D/g, '').substring(0, 11);
    if (v.length > 6) { input.value = `${v.slice(0,4)}-${v.slice(4,6)}-${v.slice(6)}`; }
    else if (v.length > 4) { input.value = `${v.slice(0,4)}-${v.slice(4)}`; }
    else { input.value = v; }
}

function generateBarcode() { 
    if(typeof JsBarcode!=='undefined'){ 
        JsBarcode("#barcodeDisplay", trxRef, {format:"CODE128",width:1.5,height:30,displayValue:false}); 
        JsBarcode("#barcodeRouting", trxRef, {format:"CODE128",width:0.8,height:25,displayValue:false}); 
    } 
}
function printRoutingSlip() { document.body.classList.add('printing-routing'); window.print(); }
function printLiquidationReport() { document.body.classList.add('printing-liquidation'); window.print(); }
window.onafterprint = function() { document.body.classList.remove('printing-routing', 'printing-liquidation'); };

function formatNum(num) { return num ? parseFloat(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : ''; }
function formatAmount(input) { let v = input.value.replace(/[^0-9.]/g, ''), p = v.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); input.value = p.join('.') + (p.length>1&&p[1].length>2?'':''); }
function toggleBonded() { 
    const box = document.getElementById('bonded_check');
    const inp = document.getElementById('officer_input');
    if(!box.disabled) inp.disabled = !box.checked;
}

document.addEventListener('DOMContentLoaded', function() {
    loadData();
    const editCodeInput = document.getElementById('edit_manual_doc_code');
    if (editCodeInput && editCodeInput.value) {
        syncEditSelectWithInput(editCodeInput.value);
        validateEditProcCode(editCodeInput.value);
    }
});

// EDIT PAGE: Procurement Linking Validation & Helpers
const editAvailableProjects = <?= json_encode($available_unlinked_projects); ?>;
const editAlreadyLinkedCodes = <?= json_encode($already_linked_codes_for_edit); ?>;
const editDraftProcCodes = <?= json_encode($draft_proc_codes_for_edit); ?>;

function onEditSelectChange(val) {
    const inp = document.getElementById('edit_manual_doc_code');
    if (inp) {
        inp.value = val;
        validateEditProcCode(val);
    }
}

function syncEditSelectWithInput(val) {
    const sel = document.getElementById('edit_proc_select');
    if (sel) {
        sel.value = val;
    }
}

function validateEditProcCode(code) {
    const previewDiv = document.getElementById('edit_proc_preview');
    const btn = document.getElementById('editLinkSubmitBtn');
    if (!previewDiv) return;
    const cleanCode = code ? code.trim().toUpperCase() : '';

    if (!cleanCode) {
        previewDiv.style.display = 'none';
        previewDiv.innerHTML = '';
        if (btn) btn.disabled = true;
        return;
    }

    // 1. Guard check: Is this code already linked to another obligation?
    const matchedLinked = editAlreadyLinkedCodes.find(item => item.document_code && item.document_code.toUpperCase() === cleanCode);
    if (matchedLinked) {
        if (btn) {
            btn.disabled = true;
            btn.title = `Double-Link Blocked: Code ${cleanCode} is already bound to obligation ${matchedLinked.transaction_ref}`;
        }
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2.5 rounded border border-danger bg-danger-subtle text-danger d-flex align-items-start gap-2 shadow-2xs text-start">
                <i class="bi bi-x-circle-fill text-danger fs-5 mt-0.5"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong class="text-danger small">ALREADY LINKED &bull; FORBIDDEN</strong>
                        <span class="badge bg-danger" style="font-size: 0.65rem;">BLOCKED</span>
                    </div>
                    <div class="small mt-1 text-danger-emphasis" style="font-size: 0.75rem;">
                        Procurement Code <strong>${cleanCode}</strong> is already linked to Obligation <strong>${matchedLinked.transaction_ref}</strong> (${matchedLinked.ppa_name}).
                    </div>
                    <div class="small text-muted mt-0.5" style="font-size: 0.70rem;">
                        Each procurement project can only be linked to one obligation. Double-linking is not permitted.
                    </div>
                </div>
            </div>
        `;
        return;
    }

    // 2. Guard Check: Is this project still in DRAFT or CANCELLED in CHO Procurement?
    const matchedDraft = editDraftProcCodes.find(item => item.document_code && item.document_code.toUpperCase() === cleanCode);
    if (matchedDraft) {
        if (btn) {
            btn.disabled = true;
            btn.title = matchedDraft.status === 'CANCELLED' 
                ? `Blocked: Project ${cleanCode} is CANCELLED`
                : `Blocked: Project ${cleanCode} is in DRAFT status`;
        }
        previewDiv.style.display = 'block';

        if (matchedDraft.status === 'CANCELLED') {
            previewDiv.innerHTML = `
                <div class="p-2.5 rounded border border-danger bg-danger-subtle text-danger d-flex align-items-start gap-2 shadow-2xs text-start">
                    <i class="bi bi-x-circle-fill text-danger fs-5 mt-0.5"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="text-danger small">PROJECT CANCELLED IN PROCUREMENT</strong>
                            <span class="badge bg-danger" style="font-size: 0.65rem;">CANCELLED</span>
                        </div>
                        <div class="small mt-1 text-danger-emphasis" style="font-size: 0.76rem;">
                            Project <strong>${matchedDraft.title}</strong> (${cleanCode}) has been cancelled and cannot be linked.
                        </div>
                    </div>
                </div>
            `;
        } else {
            previewDiv.innerHTML = `
                <div class="p-2.5 rounded border border-warning bg-warning-subtle text-warning-emphasis d-flex align-items-start gap-2 shadow-2xs text-start">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-5 mt-0.5"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="text-dark small">PROJECT IN DRAFT &bull; NOT YET FINALIZED</strong>
                            <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">DRAFT</span>
                        </div>
                        <div class="small mt-1 text-dark" style="font-size: 0.76rem;">
                            Project <strong>${matchedDraft.title}</strong> (${cleanCode}) is still being prepared in the Requisition Cart.
                        </div>
                        <div class="small text-muted mt-0.5" style="font-size: 0.70rem;">
                            It must be finalized & tracked in CHO Procurement before it can be linked to an obligation.
                        </div>
                    </div>
                </div>
            `;
        }
        return;
    }

    // 2. Check if in available unlinked procurement projects
    const matched = editAvailableProjects.find(p => p.document_code && p.document_code.toUpperCase() === cleanCode);
    if (matched) {
        if (btn) {
            btn.disabled = false;
            btn.title = '';
        }
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2.5 rounded border border-success bg-white d-flex align-items-start gap-2 shadow-2xs text-start">
                <i class="bi bi-check-circle-fill text-success fs-6 mt-0.5"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong class="text-dark small text-truncate d-block">${matched.title}</strong>
                        <span class="badge bg-success" style="font-size: 0.65rem;">AVAILABLE</span>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <span>FY ${matched.target_year}</span> &bull; <span>${matched.fund_source}</span> &bull; <span class="font-monospace text-dark">${matched.document_code}</span>
                    </div>
                </div>
            </div>
        `;
    } else {
        // Custom new code
        if (btn) {
            btn.disabled = false;
            btn.title = '';
        }
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2 rounded border border-info bg-white d-flex align-items-center gap-2 text-start">
                <i class="bi bi-info-circle text-info"></i>
                <span class="text-muted small" style="font-size: 0.72rem;">Custom code: A new 18-stage physical tracking project will be initialized for <strong>${cleanCode}</strong>.</span>
            </div>
        `;
    }
}
</script>

<?php require 'includes/footer.php'; ?>