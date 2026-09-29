<?php
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_role   = strtolower($_SESSION['role']);
$can_edit    = in_array($user_role, ['admin', 'encoder']);
$can_approve = ($user_role === 'admin');

$page_title = 'Budget Realignments';
require 'includes/header.php';

$success_msg = "";
$error_msg   = "";
$active_year = $_GET['year'] ?? date('Y');

// ---------------------------------------------------------
// 2. HANDLE ACTIONS
// ---------------------------------------------------------

// A. Request Multi-Item Realignment (INSERT)
if ($can_edit && isset($_POST['request_realignment'])) {
    try {
        $date    = $_POST['request_date'];
        $part    = trim($_POST['particulars']);
        $user_id = $_SESSION['user_id'] ?? null;
        
        $sources = $_POST['source_id'] ?? [];
        $source_amts = $_POST['source_amount'] ?? [];
        $dests = $_POST['dest_id'] ?? [];
        $dest_amts = $_POST['dest_amount'] ?? [];

        if(empty($sources) || empty($dests)) throw new Exception("You must have at least one source and one destination.");
        
        // HARDENED BACKEND VALIDATION: Check Source Balances
        $source_totals = [];
        for($i = 0; $i < count($sources); $i++) {
            $src_id = $sources[$i];
            $amt = floatval(str_replace(',', '', $source_amts[$i]));
            if(!isset($source_totals[$src_id])) $source_totals[$src_id] = 0;
            $source_totals[$src_id] += $amt;
        }

        foreach($source_totals as $src_id => $req_amt) {
            $chk_sql = "SELECT 
                COALESCE((SELECT amount FROM appropriations WHERE budget_bucket_id = ?), 0) 
                + COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = ? AND rd.type = 'destination' AND rr.status = 'approved'), 0)
                - COALESCE((SELECT SUM(CASE WHEN o.status IN ('liquidated', 'disbursing') THEN COALESCE(ci.disbursed_amount, ci.amount) ELSE ci.amount END) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = ? AND o.status IN ('pending', 'obligated', 'disbursing', 'liquidated')), 0)
                - COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = ? AND rd.type = 'source' AND rr.status IN ('approved', 'pending')), 0)
                AS available_balance";
            $chk_stmt = $pdo->prepare($chk_sql);
            $chk_stmt->execute([$src_id, $src_id, $src_id, $src_id]);
            $avail = floatval($chk_stmt->fetchColumn());
            
            if (round($req_amt, 2) > round($avail, 2)) {
                throw new Exception("Requested deduction exceeds the available balance for one of the source items.");
            }
        }

        $pdo->beginTransaction();

        $sql = "INSERT INTO realignment_requests (fiscal_year, request_date, particulars, created_by) VALUES (?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$active_year, $date, $part, $user_id]);
        $request_id = $pdo->lastInsertId();

        $stmt_detail = $pdo->prepare("INSERT INTO realignment_details (request_id, type, budget_bucket_id, amount) VALUES (?, ?, ?, ?)");
        $total_src = 0;
        for($i = 0; $i < count($sources); $i++) {
            $amt = floatval(str_replace(',', '', $source_amts[$i]));
            if($amt <= 0) throw new Exception("Amounts must be greater than zero.");
            $stmt_detail->execute([$request_id, 'source', $sources[$i], $amt]);
            $total_src += $amt;
        }

        $total_dest = 0;
        for($i = 0; $i < count($dests); $i++) {
            $amt = floatval(str_replace(',', '', $dest_amts[$i]));
            if($amt <= 0) throw new Exception("Amounts must be greater than zero.");
            $stmt_detail->execute([$request_id, 'destination', $dests[$i], $amt]);
            $total_dest += $amt;
        }

        if (round($total_src, 2) !== round($total_dest, 2)) {
            throw new Exception("Total sources must equal total destinations.");
        }

        $pdo->commit();
        $success_msg = "Realignment request successfully submitted.";
    } catch (Exception $e) { 
        if($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage(); 
    }
}

// B. Edit Multi-Item Realignment (UPDATE)
if ($can_edit && isset($_POST['edit_realignment'])) {
    try {
        $req_id  = $_POST['edit_req_id'];
        $date    = $_POST['edit_date'];
        $part    = trim($_POST['edit_particulars']);
        
        $sources = $_POST['edit_source_id'] ?? [];
        $source_amts = $_POST['edit_source_amount'] ?? [];
        $dests = $_POST['edit_dest_id'] ?? [];
        $dest_amts = $_POST['edit_dest_amount'] ?? [];

        if(empty($sources) || empty($dests)) throw new Exception("You must have at least one source and one destination.");
        
        // HARDENED BACKEND VALIDATION: Ignore the current request's footprint when checking balances
        $source_totals = [];
        for($i = 0; $i < count($sources); $i++) {
            $src_id = $sources[$i];
            $amt = floatval(str_replace(',', '', $source_amts[$i]));
            if(!isset($source_totals[$src_id])) $source_totals[$src_id] = 0;
            $source_totals[$src_id] += $amt;
        }

        foreach($source_totals as $src_id => $req_amt) {
            $chk_sql = "SELECT 
                COALESCE((SELECT amount FROM appropriations WHERE budget_bucket_id = ?), 0) 
                + COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = ? AND rd.type = 'destination' AND rr.status = 'approved'), 0)
                - COALESCE((SELECT SUM(CASE WHEN o.status IN ('liquidated', 'disbursing') THEN COALESCE(ci.disbursed_amount, ci.amount) ELSE ci.amount END) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = ? AND o.status IN ('pending', 'obligated', 'disbursing', 'liquidated')), 0)
                - COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = ? AND rd.type = 'source' AND rr.status IN ('approved', 'pending') AND rr.id != ?), 0)
                AS available_balance";
            $chk_stmt = $pdo->prepare($chk_sql);
            $chk_stmt->execute([$src_id, $src_id, $src_id, $src_id, $req_id]);
            $avail = floatval($chk_stmt->fetchColumn());
            
            if (round($req_amt, 2) > round($avail, 2)) {
                throw new Exception("Requested deduction exceeds the available balance for one of the source items.");
            }
        }

        $pdo->beginTransaction();

        $sql = "UPDATE realignment_requests SET request_date = ?, particulars = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$date, $part, $req_id]);

        // Wipe old details and insert new ones
        $pdo->prepare("DELETE FROM realignment_details WHERE request_id = ?")->execute([$req_id]);

        $stmt_detail = $pdo->prepare("INSERT INTO realignment_details (request_id, type, budget_bucket_id, amount) VALUES (?, ?, ?, ?)");
        $total_src = 0;
        for($i = 0; $i < count($sources); $i++) {
            $amt = floatval(str_replace(',', '', $source_amts[$i]));
            $stmt_detail->execute([$req_id, 'source', $sources[$i], $amt]);
            $total_src += $amt;
        }

        $total_dest = 0;
        for($i = 0; $i < count($dests); $i++) {
            $amt = floatval(str_replace(',', '', $dest_amts[$i]));
            $stmt_detail->execute([$req_id, 'destination', $dests[$i], $amt]);
            $total_dest += $amt;
        }

        if (round($total_src, 2) !== round($total_dest, 2)) {
            throw new Exception("Total sources must equal total destinations.");
        }

        $pdo->commit();
        $success_msg = "Realignment request successfully updated.";
    } catch (Exception $e) { 
        if($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Update Error: " . $e->getMessage(); 
    }
}

// C. Status Manager (Admin Only)
if ($can_approve && isset($_POST['manage_status'])) {
    try {
        $id = $_POST['status_req_id'];
        $action = $_POST['new_status']; 
        $mov = trim($_POST['mov_reference'] ?? '');
        $user_id = $_SESSION['user_id'] ?? null;

        if ($action === 'approved' && empty($mov)) {
            throw new Exception("An Approval Document (MOV) reference is required to authorize this transfer.");
        }
        
        if ($action !== 'approved') {
            $mov = null; // Clear MOV if reverted to pending/cancelled
        }

        $sql = "UPDATE realignment_requests SET status = ?, mov_reference = ?, action_by = ?, action_date = NOW() WHERE id = ?";
        $pdo->prepare($sql)->execute([$action, $mov, $user_id, $id]);
        
        $success_msg = "Request status successfully updated to " . strtoupper($action) . ".";
    } catch (Exception $e) { $error_msg = "Action Error: " . $e->getMessage(); }
}

// ---------------------------------------------------------
// 3. FETCH DATA 
// ---------------------------------------------------------

$items_query = "
    SELECT 
        bb.id as bucket_id, li.name as item_name, li.account_code,
        fs.id as fund_id, fs.name as fund_name, 
        o.id as ooe_id, o.name as ooe_name,
        COALESCE(a.amount, 0) as approp,
        COALESCE((SELECT SUM(CASE WHEN o.status IN ('liquidated', 'disbursing') THEN COALESCE(ci.disbursed_amount, ci.amount) ELSE ci.amount END) FROM charge_items ci JOIN obligations o ON ci.obligation_id = o.id WHERE ci.budget_bucket_id = bb.id AND o.status IN ('pending', 'obligated', 'disbursing', 'liquidated')), 0) as utilized,
        COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'destination' AND rr.status = 'approved'), 0) as transfer_in,
        COALESCE((SELECT SUM(rd.amount) FROM realignment_details rd JOIN realignment_requests rr ON rd.request_id = rr.id WHERE rd.budget_bucket_id = bb.id AND rd.type = 'source' AND rr.status IN ('approved', 'pending')), 0) as transfer_out
    FROM budget_buckets bb
    JOIN line_items li ON bb.line_item_id = li.id
    JOIN ooes o ON li.ooe_id = o.id
    JOIN fund_sources fs ON bb.fund_source_id = fs.id
    LEFT JOIN appropriations a ON a.budget_bucket_id = bb.id
    WHERE bb.fiscal_year = ? AND fs.name LIKE '%General Fund%'
    ORDER BY fs.name, o.name, li.name
";
$stmt = $pdo->prepare($items_query);
$stmt->execute([$active_year]);
$budget_buckets = [];
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['available_balance'] = $row['approp'] + $row['transfer_in'] - $row['utilized'] - $row['transfer_out'];
    $budget_buckets[] = $row;
}

$class_combinations = [];
foreach($budget_buckets as $b) {
    $key = $b['fund_id'] . '-' . $b['ooe_id'];
    if(!isset($class_combinations[$key])) {
        $class_combinations[$key] = [
            'fund_id' => $b['fund_id'], 'ooe_id' => $b['ooe_id'],
            'label' => $b['fund_name'] . ' - ' . $b['ooe_name']
        ];
    }
}

$sql_requests = "SELECT * FROM realignment_requests WHERE fiscal_year = ? ORDER BY request_date DESC, id DESC";
$stmt = $pdo->prepare($sql_requests);
$stmt->execute([$active_year]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Modified Details Query to include IDs for the JS Edit Modals
$sql_details = "
    SELECT rd.*, li.name as item_name, li.account_code, fs.name as fund_name, bb.fund_source_id as fund_id, li.ooe_id as ooe_id, ooe.name as ooe_name
    FROM realignment_details rd
    JOIN realignment_requests rr ON rd.request_id = rr.id
    JOIN budget_buckets bb ON rd.budget_bucket_id = bb.id
    JOIN fund_sources fs ON bb.fund_source_id = fs.id
    JOIN line_items li ON bb.line_item_id = li.id
    JOIN ooes ooe ON li.ooe_id = ooe.id
    WHERE rr.fiscal_year = ?
";
$stmt = $pdo->prepare($sql_details);
$stmt->execute([$active_year]);
$all_details = $stmt->fetchAll(PDO::FETCH_ASSOC);

$details_map = [];
foreach($all_details as $d) {
    $details_map[$d['request_id']][] = $d;
}
?>

<div class="container-fluid mt-4 px-4 screen-only">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0">Budget Realignments</h4>
            <span class="badge bg-warning text-dark">Fund Transfers</span>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted text-nowrap">FY:</label>
                <select name="year" class="form-select form-select-sm fw-bold border-dark" onchange="this.form.submit()">
                    <?php 
                    $current_year = date('Y');
                    for($y = $current_year + 1; $y >= 2023; $y--) {
                        $selected = ($y == $active_year) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>
            </form>
            <?php if($can_edit): ?>
            <button class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#requestModal">
                <i class="bi bi-plus-circle me-1"></i> Create Request
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="alert alert-info small py-2 mb-4 border-info shadow-sm">
        <i class="bi bi-info-circle-fill me-1"></i> <strong>Accounting Rule:</strong> Realignments are strictly applicable to <strong>General Fund</strong> items only. Other fund sources require formal replanning. Realignments must involve items under the same Object of Expenditure (OOE).
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="card shadow border-0 mb-5">
        <div class="card-header bg-white py-3">
            <h6 class="fw-bold text-dark mb-0">Realignment History (FY <?php echo $active_year; ?>)</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 10%">Date</th>
                            <th style="width: 35%">Budget Movement Breakdown</th>
                            <th style="width: 25%">Request Details</th>
                            <th style="width: 15%" class="text-end">Total Moved</th>
                            <th class="text-center" style="width: 15%">Status / Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($requests) > 0): foreach($requests as $r): 
                            if ($r['status'] == 'pending') { $badge = 'bg-warning text-dark'; $icon = 'bi-hourglass-split'; }
                            elseif ($r['status'] == 'approved') { $badge = 'bg-success text-white'; $icon = 'bi-check-circle'; }
                            elseif ($r['status'] == 'cancelled') { $badge = 'bg-secondary text-white'; $icon = 'bi-slash-circle'; }
                            else { $badge = 'bg-danger text-white'; $icon = 'bi-x-circle'; }

                            $details = $details_map[$r['id']] ?? [];
                            $total_amt = 0;
                            
                            $yr_suffix = substr($r['fiscal_year'], -2);
                            $padded_id = str_pad($r['id'], 3, '0', STR_PAD_LEFT);
                            $serial_no = "BRR-{$yr_suffix}-{$padded_id}";

                            $json_req = htmlspecialchars(json_encode(['req' => $r, 'details' => $details, 'serial' => $serial_no]), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="text-muted text-center"><?php echo date('M d, Y', strtotime($r['request_date'])); ?></td>
                            <td>
                                <div class="mb-2">
                                    <div class="fw-bold text-danger mb-1" style="font-size: 0.7rem;">FROM (Deductions):</div>
                                    <?php foreach($details as $d): if($d['type'] == 'source'): $total_amt += $d['amount']; ?>
                                        <div class="d-flex justify-content-between text-muted border-bottom pb-1 mb-1">
                                            <span class="text-truncate pe-2" style="max-width: 250px;"><i class="bi bi-dash me-1"></i><?php echo htmlspecialchars($d['item_name']); ?></span>
                                            <span>₱ <?php echo number_format($d['amount'], 2); ?></span>
                                        </div>
                                    <?php endif; endforeach; ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-success mb-1" style="font-size: 0.7rem;">TO (Additions):</div>
                                    <?php foreach($details as $d): if($d['type'] == 'destination'): ?>
                                        <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                                            <span class="text-truncate fw-bold text-primary pe-2" style="max-width: 250px;"><i class="bi bi-plus me-1"></i><?php echo htmlspecialchars($d['item_name']); ?></span>
                                            <span class="fw-bold text-success">₱ <?php echo number_format($d['amount'], 2); ?></span>
                                        </div>
                                    <?php endif; endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <div class="text-dark fst-italic mb-2" style="font-size: 0.75rem;"><?php echo htmlspecialchars($r['particulars']); ?></div>
                                <?php if($r['mov_reference']): ?>
                                    <div class="badge bg-light text-dark border w-100 text-start text-truncate" title="<?php echo htmlspecialchars($r['mov_reference']); ?>">
                                        <i class="bi bi-file-earmark-check text-success me-1"></i><?php echo htmlspecialchars($r['mov_reference']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold fs-6 text-dark">₱ <?php echo number_format($total_amt, 2); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $badge; ?> mb-2 w-100 py-2"><i class="bi <?php echo $icon; ?> me-1"></i><?php echo strtoupper($r['status']); ?></span>
                                
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-dark w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        Actions
                                    </button>
                                    <ul class="dropdown-menu shadow border-0 small">
                                        <?php if($r['status'] == 'pending'): ?>
                                            <li><a class="dropdown-item" href="export_realignment_doc.php?id=<?php echo $r['id']; ?>" title="Generate Request"><i class="bi bi-file-word me-2 text-primary"></i> Download DOC</a></li>
                                            <li><a class="dropdown-item" href="export_realignment_excel.php?id=<?php echo $r['id']; ?>" title="Generate Post-Realignment Balance Projection"><i class="bi bi-file-earmark-excel me-2 text-success"></i> Download Excel</a></li>
                                        <?php endif; ?>
                                        
                                        <?php if($r['status'] == 'pending' && $can_edit): ?>
                                            <li><a class="dropdown-item" href="#" onclick='openEditModal(<?php echo $json_req; ?>)'><i class="bi bi-pencil-square me-2 text-warning"></i> Edit Request</a></li>
                                        <?php endif; ?>
                                        
                                        <?php if($can_approve): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item" href="#" onclick='openStatusModal(<?php echo $json_req; ?>)'><i class="bi bi-shield-lock me-2 text-danger"></i> Manage Status</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No realignment requests found for FY <?php echo $active_year; ?>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if($can_edit): ?>
<div class="modal fade" id="requestModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-primary">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-list-columns me-2"></i>Create Multi-Item Realignment Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <form method="POST" id="realignmentForm" onsubmit="return validateRealignment('create');">
                    
                    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-dark">
                        <div class="card-body py-2">
                            <label class="small fw-bold text-muted mb-1">1. Select Fund Source & Expense Class (This locks your available items)</label>
                            <select id="classSelector" class="form-select fw-bold" onchange="lockExpenseClass()">
                                <option value="" disabled selected>-- Select combination --</option>
                                <?php foreach($class_combinations as $val => $data): ?>
                                    <option value="<?php echo $val; ?>"><?php echo $data['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="itemsContainer" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-danger shadow-sm h-100">
                                    <div class="card-header bg-danger text-white fw-bold py-2"><i class="bi bi-dash-circle me-1"></i>SOURCES (Deduct From)</div>
                                    <div class="card-body p-2" id="sourceWrapper"></div>
                                    <div class="card-footer bg-white border-0 text-end py-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSourceRow()"><i class="bi bi-plus me-1"></i>Add Source Item</button>
                                        <div class="mt-2 fw-bold text-danger">Total Deduction: <span id="sourceTotal">₱ 0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card border-success shadow-sm h-100">
                                    <div class="card-header bg-success text-white fw-bold py-2"><i class="bi bi-plus-circle me-1"></i>DESTINATIONS (Add To)</div>
                                    <div class="card-body p-2" id="destWrapper"></div>
                                    <div class="card-footer bg-white border-0 text-end py-2">
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="addDestRow()"><i class="bi bi-plus me-1"></i>Add Destination Item</button>
                                        <div class="mt-2 fw-bold text-success">Total Addition: <span id="destTotal">₱ 0.00</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mt-3">
                            <div class="card-body">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-card-text me-1"></i>Request Details</h6>
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Request Date</label>
                                        <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="small fw-bold text-muted">Justification / Reason</label>
                                        <input type="text" name="particulars" class="form-control" placeholder="Why is this realignment necessary?" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-danger mt-3 d-none fw-bold" id="validationAlert"></div>

                        <div class="mt-3 text-end">
                            <button type="submit" name="request_realignment" class="btn btn-primary fw-bold px-5 py-2 fs-5">Finalize Request</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Realignment Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <form method="POST" id="editForm" onsubmit="return validateRealignment('edit');">
                    <input type="hidden" name="edit_req_id" id="edit_req_id">
                    
                    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-warning">
                        <div class="card-body py-2">
                            <label class="small fw-bold text-muted mb-1">Fund Source & Expense Class (Locked for Edits)</label>
                            <div class="h5 fw-bold text-dark mb-0" id="edit_class_display"></div>
                            <div class="small text-muted fst-italic">To change the fund class, delete this request and create a new one.</div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card border-danger shadow-sm h-100">
                                <div class="card-header bg-danger text-white fw-bold py-2"><i class="bi bi-dash-circle me-1"></i>SOURCES (Deduct From)</div>
                                <div class="card-body p-2" id="editSourceWrapper"></div>
                                <div class="card-footer bg-white border-0 text-end py-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="addSourceRow('edit')"><i class="bi bi-plus me-1"></i>Add Source Item</button>
                                    <div class="mt-2 fw-bold text-danger">Total Deduction: <span id="editSourceTotal">₱ 0.00</span></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card border-success shadow-sm h-100">
                                <div class="card-header bg-success text-white fw-bold py-2"><i class="bi bi-plus-circle me-1"></i>DESTINATIONS (Add To)</div>
                                <div class="card-body p-2" id="editDestWrapper"></div>
                                <div class="card-footer bg-white border-0 text-end py-2">
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="addDestRow('edit')"><i class="bi bi-plus me-1"></i>Add Destination Item</button>
                                    <div class="mt-2 fw-bold text-success">Total Addition: <span id="editDestTotal">₱ 0.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-body">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-card-text me-1"></i>Request Details</h6>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted">Request Date</label>
                                    <input type="date" name="edit_date" id="edit_date" class="form-control" required>
                                </div>
                                <div class="col-md-9">
                                    <label class="small fw-bold text-muted">Justification / Reason</label>
                                    <input type="text" name="edit_particulars" id="edit_particulars" class="form-control" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-danger mt-3 d-none fw-bold" id="editValidationAlert"></div>

                    <div class="mt-3 text-end">
                        <button type="submit" name="edit_realignment" class="btn btn-warning text-dark fw-bold px-5 py-2 fs-5">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($can_approve): ?>
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-dark">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock me-2"></i>Manage Request Status</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <form method="POST">
                    <input type="hidden" name="status_req_id" id="status_req_id">
                    
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1">Set New Status</label>
                        <select name="new_status" id="new_status_val" class="form-select form-select-lg fw-bold" onchange="toggleStatusMOV()" required>
                            <option value="pending">PENDING (Floating)</option>
                            <option value="approved">APPROVED (Finalized)</option>
                            <option value="disapproved">DISAPPROVED (Rejected)</option>
                            <option value="cancelled">CANCELLED (Voided)</option>
                        </select>
                    </div>
                    
                    <div id="status_mov_div" style="display: none;">
                        <div class="alert alert-success small py-2 mb-2">
                            <i class="bi bi-info-circle me-1"></i> An Approval Document (MOV) is required to finalize and release these funds into the system.
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-dark mb-1">MOV Reference No.</label>
                            <input type="text" name="mov_reference" id="status_mov_ref" class="form-control border-success fw-bold font-monospace" placeholder="e.g. LHB Reso #12, Budget Memo 45">
                        </div>
                    </div>
                    
                    <button type="submit" name="manage_status" class="btn btn-dark w-100 fw-bold">Update System Status</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<div id="printDocument" class="d-none">
    
    <div class="print-page letter-page">
        <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <img src="assets/images/city_logo.png" alt="City Logo" style="width: 80px; height: 80px; margin-right: 15px;" onerror="this.style.display='none'">
            <div class="text-center">
                <h6 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">Republic of the Philippines</h6>
                <h5 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">CITY GOVERNMENT OF PARAÑAQUE</h5>
                <h4 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">OFFICE OF THE CITY HEALTH OFFICER</h4>
            </div>
        </div>
        <hr style="border: 1px solid black; margin-bottom: 15px;">
        
        <div class="text-end mb-3" style="font-family: Arial, sans-serif; font-size: 10pt;">
            <strong>Tracking No:</strong> <span id="p_serial" class="font-monospace fw-bold text-danger"></span>
        </div>

        <div class="mb-4" style="font-family: Arial, sans-serif; font-size: 10pt;">
            <p><strong>Date:</strong> <span id="p_date"></span></p>
            <p>
                <strong>HON. EDWIN L. OLIVAREZ</strong><br>
                City Mayor<br>
                City of Parañaque
            </p>
            <p class="mt-3">
                <strong>THRU: CITY BUDGET OFFICE</strong>
            </p>
            <p class="mt-3">
                <strong>SUBJECT: REQUEST FOR REALIGNMENT OF FUNDS (FY <span id="p_fy"></span>)</strong>
            </p>
        </div>

        <div style="font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.5; text-align: justify;">
            <p>Dear Mayor Olivarez,</p>
            
            <p>This is to respectfully request your approval for the realignment of funds within the <strong><span id="p_fund"></span></strong> of the City Health Office for Fiscal Year <span id="p_fy2"></span>.</p>
            
            <p>This realignment is necessary for the following reason(s): <strong><span id="p_justification"></span></strong>.</p>
            
            <p>Specifically, we propose to transfer funds <strong>FROM</strong> the following source line item(s), which currently have an unexpended and unobligated available balance:</p>
            
            <ul id="p_source_list" style="list-style-type: none; padding-left: 20px;">
                </ul>

            <p>We propose to transfer the aforementioned funds <strong>TO</strong> the following destination line item(s) to augment existing allocations:</p>
            
            <ul id="p_dest_list" style="list-style-type: none; padding-left: 20px;">
                </ul>

            <p class="mt-4">Attached is the detailed Certification to support this request. Your highly favorable consideration of this matter will ensure the uninterrupted delivery of essential health services to our constituents.</p>
            
            <p>Thank you.</p>
        </div>

        <div class="mt-3" style="font-family: Arial, sans-serif; font-size: 10pt;">
            <p>Very truly yours,</p>
            <br>
            <p class="fw-bold mb-0 text-uppercase">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</p>
            <p>OIC - City Health Officer</p>
        </div>
    </div>

    <div class="print-page cert-page">
        <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
            <img src="assets/images/city_logo.png" alt="City Logo" style="width: 80px; height: 80px; margin-right: 15px;" onerror="this.style.display='none'">
            <div class="text-center">
                <h6 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">Republic of the Philippines</h6>
                <h5 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">CITY GOVERNMENT OF PARAÑAQUE</h5>
                <h4 class="fw-bold mb-0" style="font-family: 'Times New Roman', serif;">OFFICE OF THE CITY HEALTH OFFICER</h4>
            </div>
        </div>
        <hr style="border: 1px solid black; margin-bottom: 15px;">

        <div class="text-end mb-4" style="font-family: Arial, sans-serif; font-size: 10pt;">
            <strong>Tracking No:</strong> <span id="c_serial" class="font-monospace fw-bold text-danger"></span>
        </div>

        <div class="text-center mb-3 mt-2">
            <h3 class="fw-bold text-uppercase" style="font-family: 'Arial', sans-serif;">CERTIFICATION</h3>
        </div>

        <div style="font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.5; text-align: justify;">
            <p>This is to certify that the appropriation for the following line budget items requested for realignment are no longer needed for implementation by this department:</p>
            
            <table class="cert-table mb-3" style="text-indent: 0; margin-left: 0;">
                <thead>
                    <tr style="background-color: #f0f0f0; text-transform: uppercase;">
                        <th style="width: 70%; text-align: center;">Object of Expenditures</th>
                        <th style="width: 30%; text-align: center;" colspan="2">Amount</th>
                    </tr>
                </thead>
                <tbody id="c_source_body">
                    </tbody>
                <tfoot>
                    <tr>
                        <td style="font-weight: bold;">TOTAL</td>
                        <td class="no-border-right" style="font-weight: bold; width: 40px;">Php</td>
                        <td class="no-border-left" style="text-align: right; font-weight: bold;" id="c_source_total"></td>
                    </tr>
                </tfoot>
            </table>

            <p class="mt-3">The realignment of the said appropriation will be used for the following:</p>
            
            <table class="cert-table mb-4" style="text-indent: 0; margin-left: 0;">
                <thead>
                    <tr style="background-color: #f0f0f0; text-transform: uppercase;">
                        <th style="width: 70%; text-align: center;">Object of Expenditures</th>
                        <th style="width: 30%; text-align: center;" colspan="2">Amount</th>
                    </tr>
                </thead>
                <tbody id="c_dest_body">
                    </tbody>
                <tfoot>
                    <tr>
                        <td style="font-weight: bold;">TOTAL</td>
                        <td class="no-border-right" style="font-weight: bold; width: 40px;">Php</td>
                        <td class="no-border-left" style="text-align: right; font-weight: bold;" id="c_dest_total"></td>
                    </tr>
                </tfoot>
            </table>

            <p class="mt-3">Issued this <span id="c_day"></span> day of <span id="c_month"></span> <span id="c_year"></span> in the City of Parañaque.</p>
        </div>

        <div class="mt-3" style="font-family: Arial, sans-serif; font-size: 10pt;">
            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <div style="width: 45%; text-align: right;">
                    <p style="text-align: left;">Certified by:</p>
                    <br>
                    <p class="fw-bold mb-0 text-uppercase text-center" style="white-space: nowrap;">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</p>
                    <p style="text-align: left;">OIC - City Health Officer</p>
                </div>            
                <div style="width: 45%;">
                    <p>Noted by:</p>
                    <br>
                    <p class="fw-bold mb-0 text-uppercase">ALEJANDRO A. DEPANO II</p>
                    <p>City Budget Officer</p>
                </div>
            </div>

            <div style="text-align: center; margin-top: 20px;">
                <p style="text-align: center;">Approved by:</p>
                <br>
                <p class="fw-bold mb-0 text-uppercase">HON. EDWIN L. OLIVAREZ</p>
                <p>City Mayor</p>
            </div>
        </div>
    </div>
</div>

<style>
    /* Bulletproof Formal Print Styles */
    @media print {
        @page { 
            size: A4 portrait !important; 
            margin: 0 !important; 
        }
        
        .screen-only, .navbar, .sidebar, .modal, .btn { 
            display: none !important; 
        }
        
        body, html { 
            margin: 0 !important; 
            padding: 0 !important; 
            background: white !important; 
            font-family: "Arial", sans-serif; 
            color: black; 
        }
        
        body.printing-realign #printDocument { 
            display: block !important; 
            width: 100%;
        }
        
        /* Strict A4 Constraints */
        .print-page { 
            width: 210mm !important; 
            height: 296mm !important; /* Exact A4 height */
            /* Top: 0.4in, Right: 1in, Bottom: 0.5in, Left: 1in */
            padding: 0.4in 1in 0.5in 1in !important; 
            margin: 0 auto !important; 
            box-sizing: border-box !important; 
            page-break-after: always !important;
            page-break-inside: avoid !important;
            overflow: hidden !important; 
            background: white;
        }

        .print-page:last-child {
            page-break-after: auto !important;
        }

        .cert-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11pt; }
        .cert-table th, .cert-table td { border: 1px solid black !important; padding: 5px 8px; vertical-align: middle; }
        .cert-table th { text-align: center; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-border-right { border-right: none !important; }
        .no-border-left { border-left: none !important; text-align: right; }
    }
</style>

<script>
    // -------------------------------------------------------------
    // DATA GLOBALS
    // -------------------------------------------------------------
    const allBuckets = <?php echo json_encode($budget_buckets); ?>;
    let filteredItems = [];
    let editFilteredItems = [];

    // Helper Functions
    function formatAmount(i) { 
        let v = i.value.replace(/[^0-9.]/g, '');
        let p = v.split('.'); 
        p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); 
        i.value = p.join('.') + (p.length>1 && p[1].length>2 ? '' : ''); 
        calculateTotals('create');
        calculateTotals('edit');
    }

    function parseAmt(str) {
        return parseFloat(str.replace(/,/g, '')) || 0;
    }

    // -------------------------------------------------------------
    // 1. CREATE MODAL LOGIC
    // -------------------------------------------------------------
    function lockExpenseClass() {
        const val = document.getElementById('classSelector').value;
        if(!val) return;
        
        const [fundId, ooeId] = val.split('-');
        filteredItems = allBuckets.filter(b => b.fund_id == fundId && b.ooe_id == ooeId);
        
        document.getElementById('itemsContainer').style.display = 'block';
        document.getElementById('sourceWrapper').innerHTML = '';
        document.getElementById('destWrapper').innerHTML = '';
        
        addSourceRow();
        addDestRow();
    }

    function getOptionsHTML(itemsArray, isSource, selectedVal = '') {
        let html = '<option value="" disabled selected>-- Select Line Item --</option>';
        itemsArray.forEach(item => {
            let avail = parseFloat(item.available_balance);
            let availText = isSource ? ` (Avail: ₱ ${avail.toLocaleString('en-US', {minimumFractionDigits:2})})` : '';
            if(isSource && avail <= 0 && item.bucket_id != selectedVal) return; // Allow rendering if it's the currently selected one for edits
            
            let sel = (item.bucket_id == selectedVal) ? 'selected' : '';
            html += `<option value="${item.bucket_id}" data-avail="${avail}" ${sel}>${item.item_name}${availText}</option>`;
        });
        return html;
    }

    function addSourceRow(mode = 'create', selectedVal = '', amount = '') {
        const wrapId = mode === 'create' ? 'sourceWrapper' : 'editSourceWrapper';
        const namePref = mode === 'create' ? '' : 'edit_';
        const itemsArr = mode === 'create' ? filteredItems : editFilteredItems;
        
        const div = document.createElement('div');
        div.className = `row g-1 mb-2 align-items-center ${mode}-source-row`;
        div.innerHTML = `
            <div class="col-8">
                <select name="${namePref}source_id[]" class="form-select form-select-sm border-danger text-truncate" required onchange="calculateTotals('${mode}')">
                    ${getOptionsHTML(itemsArr, true, selectedVal)}
                </select>
            </div>
            <div class="col-3">
                <input type="text" name="${namePref}source_amount[]" class="form-control form-control-sm text-end border-danger ${mode}-source-amt" value="${amount}" required placeholder="Amount" oninput="formatAmount(this)">
            </div>
            <div class="col-1 text-center">
                <i class="bi bi-x-circle text-danger" style="cursor:pointer;" onclick="this.closest('.${mode}-source-row').remove(); calculateTotals('${mode}');"></i>
            </div>
        `;
        document.getElementById(wrapId).appendChild(div);
    }

    function addDestRow(mode = 'create', selectedVal = '', amount = '') {
        const wrapId = mode === 'create' ? 'destWrapper' : 'editDestWrapper';
        const namePref = mode === 'create' ? '' : 'edit_';
        const itemsArr = mode === 'create' ? filteredItems : editFilteredItems;

        const div = document.createElement('div');
        div.className = `row g-1 mb-2 align-items-center ${mode}-dest-row`;
        div.innerHTML = `
            <div class="col-8">
                <select name="${namePref}dest_id[]" class="form-select form-select-sm border-success text-truncate" required>
                    ${getOptionsHTML(itemsArr, false, selectedVal)}
                </select>
            </div>
            <div class="col-3">
                <input type="text" name="${namePref}dest_amount[]" class="form-control form-control-sm text-end border-success ${mode}-dest-amt" value="${amount}" required placeholder="Amount" oninput="formatAmount(this)">
            </div>
            <div class="col-1 text-center">
                <i class="bi bi-x-circle text-danger" style="cursor:pointer;" onclick="this.closest('.${mode}-dest-row').remove(); calculateTotals('${mode}');"></i>
            </div>
        `;
        document.getElementById(wrapId).appendChild(div);
    }

    function calculateTotals(mode) {
        const srcClass = `.${mode}-source-amt`;
        const destClass = `.${mode}-dest-amt`;
        const totalSrcId = mode === 'create' ? 'sourceTotal' : 'editSourceTotal';
        const totalDestId = mode === 'create' ? 'destTotal' : 'editDestTotal';

        let totalSrc = 0;
        document.querySelectorAll(srcClass).forEach(el => totalSrc += parseAmt(el.value));
        document.getElementById(totalSrcId).innerText = '₱ ' + totalSrc.toLocaleString('en-US', {minimumFractionDigits:2});

        let totalDest = 0;
        document.querySelectorAll(destClass).forEach(el => totalDest += parseAmt(el.value));
        document.getElementById(totalDestId).innerText = '₱ ' + totalDest.toLocaleString('en-US', {minimumFractionDigits:2});
        
        return { src: totalSrc, dest: totalDest };
    }

    function validateRealignment(mode) {
        const totals = calculateTotals(mode);
        const alertBox = document.getElementById(mode === 'create' ? 'validationAlert' : 'editValidationAlert');
        const rowClass = `.${mode}-source-row`;
        const amtClass = `.${mode}-source-amt`;
        let isValid = true;
        let sourceMap = {};
        
        if (Math.abs(totals.src - totals.dest) > 0.01) {
            alertBox.innerText = `Validation Failed: Your Total Deductions (₱ ${totals.src.toLocaleString('en-US', {minimumFractionDigits:2})}) do not match your Total Additions (₱ ${totals.dest.toLocaleString('en-US', {minimumFractionDigits:2})}).`;
            alertBox.classList.remove('d-none');
            return false;
        }
        
        if (totals.src === 0) {
            alertBox.innerText = "Amounts cannot be zero.";
            alertBox.classList.remove('d-none');
            return false;
        }

        document.querySelectorAll(rowClass).forEach(row => {
            const select = row.querySelector('select');
            const amtInput = row.querySelector(amtClass);
            const bucketId = select.value;
            const amt = parseAmt(amtInput.value);
            
            if(bucketId) {
                const opt = select.options[select.selectedIndex];
                const avail = parseFloat(opt.getAttribute('data-avail'));
                
                if (!sourceMap[bucketId]) {
                    sourceMap[bucketId] = { name: opt.text.split(' (')[0], requested: 0, available: avail };
                }
                sourceMap[bucketId].requested += amt;
            }
        });

        for (const [id, data] of Object.entries(sourceMap)) {
            if (data.requested > data.available) {
                alertBox.innerHTML = `Overdraft Error! <br> You are requesting to deduct <b>₱ ${data.requested.toLocaleString('en-US', {minimumFractionDigits:2})}</b> from <br> <i>${data.name}</i>, <br> but it only has <b>₱ ${data.available.toLocaleString('en-US', {minimumFractionDigits:2})}</b> available.`;
                alertBox.classList.remove('d-none');
                isValid = false;
            }
        }

        if(!isValid) return false;

        alertBox.classList.add('d-none');
        return true;
    }

    // -------------------------------------------------------------
    // 2. EDIT MODAL LOGIC
    // -------------------------------------------------------------
    function openEditModal(payload) {
        const req = payload.req;
        const details = payload.details;

        document.getElementById('edit_req_id').value = req.id;
        document.getElementById('edit_date').value = req.request_date;
        document.getElementById('edit_particulars').value = req.particulars;

        if(details.length > 0) {
            const first = details[0];
            document.getElementById('edit_class_display').innerText = first.fund_name + ' - ' + first.ooe_name;
            
            // Filter global buckets for the Edit rows
            editFilteredItems = allBuckets.filter(b => b.fund_id == first.fund_id && b.ooe_id == first.ooe_id);
            
            // For edits, we must "add back" the currently requested amount to the available balance temporarily in JS
            // so the user doesn't get an immediate overdraft error when opening the modal
            editFilteredItems.forEach(b => {
                let thisReqDetail = details.find(d => d.budget_bucket_id == b.bucket_id && d.type == 'source');
                if(thisReqDetail) {
                    b.available_balance = parseFloat(b.available_balance) + parseFloat(thisReqDetail.amount);
                }
            });

            document.getElementById('editSourceWrapper').innerHTML = '';
            document.getElementById('editDestWrapper').innerHTML = '';

            details.forEach(d => {
                let amtStr = parseFloat(d.amount).toLocaleString('en-US', {minimumFractionDigits:2});
                if(d.type === 'source') addSourceRow('edit', d.budget_bucket_id, amtStr);
                else addDestRow('edit', d.budget_bucket_id, amtStr);
            });
            calculateTotals('edit');
        }

        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    // -------------------------------------------------------------
    // 3. STATUS MANAGER LOGIC
    // -------------------------------------------------------------
    function openStatusModal(payload) {
        document.getElementById('status_req_id').value = payload.req.id;
        document.getElementById('new_status_val').value = payload.req.status;
        document.getElementById('status_mov_ref').value = payload.req.mov_reference || '';
        toggleStatusMOV();
        new bootstrap.Modal(document.getElementById('statusModal')).show();
    }

    function toggleStatusMOV() {
        const status = document.getElementById('new_status_val').value;
        const movDiv = document.getElementById('status_mov_div');
        const movInput = document.getElementById('status_mov_ref');
        
        if (status === 'approved') {
            movDiv.style.display = 'block';
            movInput.required = true;
        } else {
            movDiv.style.display = 'none';
            movInput.required = false;
        }
    }

    // -------------------------------------------------------------
    // 4. PRINTING LOGIC
    // -------------------------------------------------------------
    function printRequest(payload) {
        const req = payload.req;
        const details = payload.details;
        const serialNo = payload.serial;

        // Parse Date
        const dateObj = new Date(req.request_date);
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        const formattedDate = dateObj.toLocaleDateString('en-US', options);
        
        const day = dateObj.getDate();
        let suffix = 'th';
        if (day % 10 === 1 && day !== 11) suffix = 'st';
        else if (day % 10 === 2 && day !== 12) suffix = 'nd';
        else if (day % 10 === 3 && day !== 13) suffix = 'rd';

        const fundName = details.length > 0 ? details[0].fund_name : 'General Fund';

        // Populate Letter Elements
        document.getElementById('p_serial').innerText = serialNo;
        document.getElementById('p_date').innerText = formattedDate;
        document.getElementById('p_fy').innerText = req.fiscal_year;
        document.getElementById('p_fy2').innerText = req.fiscal_year;
        document.getElementById('p_fund').innerText = fundName;
        document.getElementById('p_justification').innerText = req.particulars;

        // Populate Cert Elements
        document.getElementById('c_serial').innerText = serialNo;
        document.getElementById('c_day').innerText = day + suffix;
        document.getElementById('c_month').innerText = dateObj.toLocaleDateString('en-US', {month: 'long'});
        document.getElementById('c_year').innerText = dateObj.getFullYear();

        // Build HTML
        let srcListHtml = ''; let destListHtml = '';
        let cSrcBody = ''; let cDestBody = '';
        let totalSrcAmt = 0; let totalDestAmt = 0;

        details.forEach(d => {
            let amt = parseFloat(d.amount);
            let amtFormatted = amt.toLocaleString('en-US', {minimumFractionDigits: 2});
            
            if (d.type === 'source') {
                totalSrcAmt += amt;
                srcListHtml += `<li style="margin-bottom: 5px;">• ${d.item_name} - <strong>₱ ${amtFormatted}</strong></li>`;
                cSrcBody += `
                    <tr>
                        <td>${d.item_name}</td>
                        <td class="no-border-right" style="width: 40px;">Php</td>
                        <td class="no-border-left" style="font-weight: bold; text-align: right;">${amtFormatted}</td>
                    </tr>`;
            } else {
                totalDestAmt += amt;
                destListHtml += `<li style="margin-bottom: 5px;">• ${d.item_name} - <strong>₱ ${amtFormatted}</strong></li>`;
                cDestBody += `
                    <tr>
                        <td>${d.item_name}</td>
                        <td class="no-border-right" style="width: 40px;">Php</td>
                        <td class="no-border-left" style="font-weight: bold; text-align: right;">${amtFormatted}</td>
                    </tr>`;
            }
        });

        // Inject HTML
        document.getElementById('p_source_list').innerHTML = srcListHtml;
        document.getElementById('p_dest_list').innerHTML = destListHtml;
        document.getElementById('c_source_body').innerHTML = cSrcBody;
        document.getElementById('c_source_total').innerText = totalSrcAmt.toLocaleString('en-US', {minimumFractionDigits: 2});
        document.getElementById('c_dest_body').innerHTML = cDestBody;
        document.getElementById('c_dest_total').innerText = totalDestAmt.toLocaleString('en-US', {minimumFractionDigits: 2});

        // Trigger Print
        document.body.classList.add('printing-realign');
        window.print();
    }

    window.onafterprint = function() { 
        document.body.classList.remove('printing-realign'); 
    };

    if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }
</script>

<?php require 'includes/footer.php'; ?>