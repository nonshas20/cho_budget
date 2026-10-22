<?php
// 1. START SESSION & AUTHORIZATION CHECK
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'includes/db_connect.php';
require_once 'includes/procurement_bridge.php';

$user_role = strtolower($_SESSION['role'] ?? 'viewer');
if (!in_array($user_role, ['admin', 'encoder'])) {
    header("Location: view_obligations.php?error=unauthorized");
    exit;
}

// HELPER: Generate Acronym
function getFundAcronym($name) {
    $clean_name = preg_replace('/[^a-zA-Z0-9 ]/', '', strtoupper(trim($name)));
    $words = explode(' ', $clean_name);
    return (count($words) >= 2) ? substr($words[0], 0, 1) . substr($words[1], 0, 1) : substr($words[0], 0, 2);
}

// INTERNAL API: Fetch Items
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
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [["id" => "", "name" => "(No items found)"]]);
    } catch (Exception $e) { echo json_encode([["id" => "", "name" => "DB Error"]]); }
    exit; 
}

$page_title = "Create New Obligation";
require 'includes/header.php';

// HANDLE SAVE
if (isset($_POST['save_obligation'])) {
    try {
        if (!in_array($user_role, ['admin', 'encoder'])) {
            throw new Exception("Unauthorized: You do not have permission to encode obligations.");
        }
        $pdo->beginTransaction();

        $year        = $_POST['fiscal_year']; 
        $period      = $_POST['charging_period'];
        $target_date = $_POST['target_date']; 
        $ppa_name    = trim($_POST['ppa_name']);
        $partic      = trim($_POST['particulars']); 
        
        // Safer check for bonded officer input
        $officer     = !empty($_POST['bonded_officer']) ? trim($_POST['bonded_officer']) : null;
        
        $unit        = trim($_POST['program_unit']);
        $status      = $_POST['status']; 
        $fund_id     = $_POST['fund_source'];
        $bucket_ids  = $_POST['bucket_ids'] ?? [];
        $amounts     = $_POST['amounts'] ?? [];

        if (empty($bucket_ids) || empty($period) || empty($target_date)) throw new Exception("Please fill in all required fields.");

        // 1. STRICT LOCK CHECK
        $is_locked = false;
        $stmt_check = $pdo->prepare("SELECT * FROM budget_buckets WHERE id = ?");
        foreach($bucket_ids as $bid) {
            if(!$bid) continue;
            $stmt_check->execute([$bid]);
            $b = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if ($b) {
                if ($period == 'q2' && ($b['q2_released'] ?? 1) == 0) $is_locked = true;
                if ($period == 'q3' && ($b['q3_released'] ?? 1) == 0) $is_locked = true;
                if ($period == 'q4' && ($b['q4_released'] ?? 1) == 0) $is_locked = true;
                if ($period == 'sem2' && ($b['sem2_released'] ?? 1) == 0) $is_locked = true;
                if ($period == 'reserve' && ($b['reserve_released'] ?? 1) == 0) $is_locked = true;
            }
        }

        if ($is_locked && in_array($status, ['obligated', 'disbursing', 'liquidated'])) {
            $status = 'pending';
            $status_msg = " (Saved as Pending due to unreleased funds)";
        } else {
            $status_msg = "";
        }

        // 2. GENERATE REF NUMBER
        $stmt_fn = $pdo->prepare("SELECT name FROM fund_sources WHERE id = ?");
        $stmt_fn->execute([$fund_id]);
        $fund_name = $stmt_fn->fetchColumn();
        $fund_code = getFundAcronym($fund_name); 
        $yr_code = substr($year, -2); 
        $prefix_search = "$yr_code-$fund_code-%";
        $stmt_seq = $pdo->prepare("SELECT transaction_ref FROM obligations WHERE transaction_ref LIKE ? ORDER BY transaction_ref DESC LIMIT 1");
        $stmt_seq->execute([$prefix_search]);
        $last_ref = $stmt_seq->fetchColumn();
        
        if ($last_ref) {
            $parts = explode('-', $last_ref);
            $next_num = intval(end($parts)) + 1;
        } else {
            $next_num = 1;
        }
        $trx_ref = sprintf("%s-%s-%03d", $yr_code, $fund_code, $next_num);

        // 3. PREPARE ITEMS
        $total_amount = 0;
        $valid_items = [];
        $primary_bucket_id = null;
        $stmt_li = $pdo->prepare("SELECT line_item_id FROM budget_buckets WHERE id = ?");

        foreach($bucket_ids as $index => $b_id) {
            $amt = floatval(str_replace(',', '', $amounts[$index]));
            if(!empty($b_id) && $amt > 0) {
                if(!$primary_bucket_id) $primary_bucket_id = $b_id; 
                $total_amount += $amt;
                $stmt_li->execute([$b_id]);
                $li_id = $stmt_li->fetchColumn();
                if($li_id) $valid_items[] = ['li_id' => $li_id, 'amt' => $amt, 'b_id' => $b_id];
            }
        }

        if($total_amount <= 0) throw new Exception("Total amount must be greater than zero.");

        // 4. INSERT OBLIGATION HEADER (WITH PROCUREMENT DOCUMENT CODE)
        $is_ca = isset($_POST['is_cash_advance']) ? 1 : 0; 
        $doc_code = strtoupper(trim($_POST['document_code'] ?? ''));
        ensure_obligations_has_document_code_column($pdo);

        // GUARD: Ensure procurement code is not already linked to another obligation
        if (!empty($doc_code)) {
            $alreadyLinked = check_procurement_code_already_linked($pdo, $doc_code);
            if ($alreadyLinked) {
                throw new Exception("Procurement Code {$doc_code} is ALREADY linked to Obligation {$alreadyLinked['transaction_ref']} (#{$alreadyLinked['id']}: {$alreadyLinked['ppa_name']}). Each procurement project can only be linked to one obligation.");
            }

            // GUARD: Ensure procurement project is FINALIZED in CHO Procurement (not Draft or Cancelled)
            $procState = check_procurement_project_state($pdo, $doc_code);
            if ($procState) {
                if ($procState['status'] === 'CANCELLED') {
                    throw new Exception("Procurement Code {$doc_code} ({$procState['title']}) is CANCELLED in CHO Procurement and cannot be linked.");
                }
                if ((int)$procState['is_locked'] === 0) {
                    throw new Exception("Procurement Code {$doc_code} ({$procState['title']}) is currently in DRAFT status in CHO Procurement. It must be Finalized before linking.");
                }
            }
        }

        $initial_routing = !empty($doc_code) ? "STAGE 1: SUBMISSION OF REQUISITION TO CHO\r\n\r\n" . $doc_code : null;

        $sql_head = "INSERT INTO obligations (transaction_ref, document_code, budget_bucket_id, charging_period, obligation_date, ppa_name, particulars, amount, status, is_cash_advance, bonded_officer, program_unit, status_routing, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql_head)->execute([$trx_ref, ($doc_code ?: null), $primary_bucket_id, $period, $target_date, $ppa_name, $partic, $total_amount, $status, $is_ca, $officer, $unit, $initial_routing]);
        $obligation_id = $pdo->lastInsertId();

        // 5. INSERT CHARGE ITEMS
        $stmt_item = $pdo->prepare("INSERT INTO charge_items (obligation_id, line_item_id, budget_bucket_id, amount) VALUES (?, ?, ?, ?)");
        foreach($valid_items as $item) {
            $stmt_item->execute([$obligation_id, $item['li_id'], $item['b_id'], $item['amt']]);
        }

        // 6. IF LINKED TO PROCUREMENT, INITIALIZE 18-STAGE TRACKING & LOG DIARY
        if (!empty($doc_code)) {
            $pdo->prepare("INSERT INTO routing_history (obligation_id, user_name, status, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$obligation_id, $_SESSION['username'], "LINKED TO PROCUREMENT DOCUMENT: {$doc_code} (18-Stage Routing Slip Active)"]);
            
            try {
                get_linked_procurement_project($pdo, [
                    'id' => $obligation_id,
                    'document_code' => $doc_code,
                    'ppa_name' => $ppa_name,
                    'obligation_date' => $target_date,
                    'fund_name' => $fund_name ?? 'General Fund'
                ]);
            } catch (\Throwable $e) {}
        }

        $pdo->commit();
        log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'CREATE', "Created PPA $trx_ref" . (!empty($doc_code) ? " (Linked: $doc_code)" : ""));
        $success_msg = "Successfully created PPA <strong>$trx_ref</strong>" . (!empty($doc_code) ? " linked to Procurement Code <span class='badge font-monospace text-white' style='background-color:#004253'>$doc_code</span>" : "") . " (Status: " . ucfirst($status) . ").$status_msg <a href='edit_obligation.php?id=$obligation_id' class='btn btn-sm btn-outline-success ms-2 fw-bold'><i class='bi bi-folder2-open me-1'></i>Manage Routing Slip</a>";

    } catch (Exception $e) { 
        $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage(); 
    }
}

$current_year = date('Y'); 
$years = $pdo->query("SELECT * FROM fiscal_years ORDER BY year DESC")->fetchAll();
$funds = $pdo->query("SELECT * FROM fund_sources")->fetchAll();
$units = ["Admin Division", "Public Health", "Epidemiology", "Laboratory", "Pharmacy", "Sanitation", "BHW Unit", "Finance Unit"];

// Procurement Bridge Linkage Data (Unlinked projects only)
ensure_obligations_has_document_code_column($pdo);
$prefill_doc_code = strtoupper(trim($_GET['doc_code'] ?? ''));
$proc_projects = [];
$already_linked_codes = [];
$draft_proc_codes = [];
try {
    $proc_projects = get_available_unlinked_procurement_projects($pdo);
    $already_linked_codes = $pdo->query("SELECT o.document_code, o.transaction_ref, o.ppa_name FROM cho_budget_db.obligations o WHERE o.document_code IS NOT NULL AND o.document_code != ''")->fetchAll(PDO::FETCH_ASSOC);
    $draft_proc_codes = $pdo->query("SELECT document_code, title, is_locked, status FROM cho_procurement_db.requisition_projects WHERE document_code IS NOT NULL AND (is_locked = 0 OR status = 'CANCELLED')")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
?>

<div class="container mt-4" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-primary"><i class="bi bi-plus-circle-fill me-2"></i>Create New PPA</h4>
        <a href="view_obligations.php" class="btn btn-secondary btn-sm">Back to Log</a>
    </div>

    <?php if (isset($success_msg)): ?><div class="alert alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if (isset($error_msg)): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" id="obligationForm">
                <input type="hidden" name="save_obligation" value="1">
                
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold small text-muted">Fiscal Year</label>
                        <select name="fiscal_year" id="fy_select" class="form-select" onchange="fetchItems(); validateDateRange();">
                            <?php foreach($years as $y): ?><option value="<?php echo $y['year']; ?>" <?php echo ($y['year'] == $current_year) ? 'selected' : ''; ?>><?php echo $y['year']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold small text-muted">Fund Source</label>
                        <select name="fund_source" id="fund_select" class="form-select" onchange="fetchItems()">
                            <?php foreach($funds as $f): ?><option value="<?php echo $f['id']; ?>"><?php echo $f['name']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-bold small text-muted">Program / Unit</label>
                        <input type="text" name="program_unit" class="form-control" list="unitOptions" placeholder="e.g. Public Health">
                        <datalist id="unitOptions"><?php foreach($units as $u): ?><option value="<?php echo $u; ?>"><?php endforeach; ?></datalist>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">PPA Name (Short Title)</label>
                    <input type="text" name="ppa_name" class="form-control" placeholder="e.g., Procurement of Medical Supplies" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Particulars / Description</label>
                    <textarea name="particulars" class="form-control" rows="2" placeholder="Specific details..."></textarea>
                </div>

                <!-- CHO PROCUREMENT TRACKING LINK (OPTIONAL) -->
                <div class="card border mb-3 shadow-2xs" style="border-top: 3px solid #004253 !important; background-color: #f8fafc;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-bold small text-dark mb-0 d-flex align-items-center gap-1.5">
                                <i class="bi bi-folder-symlink-fill text-success"></i>
                                <span>Link to CHO Procurement Tracking (Optional)</span>
                            </label>
                            <span class="badge bg-light text-muted border font-monospace" style="font-size: 0.68rem;">18-Stage Slip Bridge</span>
                        </div>
                        <p class="text-muted small mb-2" style="font-size: 0.75rem;">
                            Bind this obligation to a procurement tracking code (e.g. <code>PROC-2026-FIO0MY</code>) to stick the 18 physical routing stages and enable release forwarding.
                        </p>

                        <!-- Select from unlinked dropdown -->
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1 fw-semibold">Quick Select from Available Unlinked Procurement:</label>
                            <select id="proc_doc_select" class="form-select form-select-sm" onchange="onProcSelectChange(this.value)">
                                <option value="">-- Click here to select an available procurement document (<?= count($proc_projects); ?> available) --</option>
                                <?php foreach($proc_projects as $p): ?>
                                    <option value="<?= htmlspecialchars($p['document_code']); ?>" <?= ($prefill_doc_code === $p['document_code']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($p['document_code']); ?> — <?= htmlspecialchars($p['title']); ?> (FY <?= $p['target_year']; ?> &bull; <?= htmlspecialchars($p['fund_source']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="small text-muted" style="font-size: 0.70rem;">OR enter/search document code manually:</span>
                        </div>

                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white font-monospace text-muted fw-bold">#</span>
                            <input 
                                type="text" 
                                name="document_code" 
                                id="proc_doc_code_input" 
                                class="form-control font-monospace fw-bold" 
                                placeholder="PROC-2026-XXXXXX" 
                                value="<?= htmlspecialchars($prefill_doc_code); ?>" 
                                list="procDocCodes" 
                                autocomplete="off"
                                onclick="if(this.showPicker) try{this.showPicker();}catch(e){}"
                                oninput="this.value = this.value.toUpperCase(); syncSelectWithInput(this.value); validateProcCode(this.value);"
                            >
                            <button type="button" class="btn btn-outline-secondary" onclick="clearProcCode();">Clear</button>
                        </div>

                        <datalist id="procDocCodes">
                            <?php foreach($proc_projects as $p): ?>
                                <option value="<?= htmlspecialchars($p['document_code']); ?>"><?= htmlspecialchars($p['title']); ?> (FY <?= $p['target_year']; ?> - <?= htmlspecialchars($p['fund_source']); ?>)</option>
                            <?php endforeach; ?>
                        </datalist>

                        <!-- Live Matched Project Preview -->
                        <div id="proc_code_preview" class="mt-2" style="display: none;"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Charge Items</label>
                    <div class="card bg-light border p-3">
                        <div id="items_container"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2 fw-bold w-100" onclick="addItemRow()"><i class="bi bi-plus-circle-fill me-1"></i> Add Item</button>
                        <div class="border-top mt-3 pt-2 d-flex justify-content-between align-items-center">
                            <span class="text-muted small fw-bold text-uppercase">Total Allocation</span>
                            <span class="h5 fw-bold text-primary mb-0" id="runningTotalDisplay">₱ 0.00</span>
                        </div>
                    </div>
                </div>

                <div class="card bg-white border mb-4">
                    <div class="card-header bg-light fw-bold small text-muted">Charging & Timeline</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Charging Period</label>
                                <select name="charging_period" id="period_select" class="form-select border-primary fw-bold" required onchange="validateLocks(); validateDateRange();">
                                    <option value="">-- Select --</option>
                                    <option value="full">Full Release</option>
                                    <option value="sem1">1st Semester</option>
                                    <option value="sem2">2nd Semester</option>
                                    <option value="q1">1st Quarter</option>
                                    <option value="q2">2nd Quarter</option>
                                    <option value="q3">3rd Quarter</option>
                                    <option value="q4">4th Quarter</option>
                                    <option value="reserve">Reserve Fund</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Target Date</label>
                                <input type="date" name="target_date" id="target_date" class="form-control fw-bold" required>
                                <div class="form-text x-small text-muted" id="date_hint">Select Period first</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Initial Status</label>
                                <select name="status" id="status_select" class="form-select fw-bold bg-secondary text-white">
                                    <option value="pending" id="opt_pending" selected>Pending (Draft)</option>
                                    <option value="obligated" id="opt_obligated">Obligated (Approved)</option>
                                </select>
                                <div id="lock_warning" class="text-danger x-small mt-1 fw-bold" style="display:none;"><i class="bi bi-lock-fill"></i> Unreleased funds. Forced to Pending.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                       <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="bonded_check" name="use_bonded" onchange="toggleBonded()">
                            <label class="form-check-label small fw-bold" for="bonded_check">Bonded Officer?</label>
                        </div>
                        <input type="text" name="bonded_officer" id="officer_input" class="form-control form-control-sm" placeholder="Name..." disabled style="width: 200px;">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_cash_advance" id="ca_check" value="1">
                        <label class="form-check-label small fw-bold text-primary" for="ca_check"><i class="bi bi-wallet2 me-1"></i> Petty Cash Fund</label>
                    </div>
                </div>
                <hr>
                <button type="submit" name="save_obligation" id="saveBtn" class="btn btn-primary fw-bold w-100 py-2 shadow"><i class="bi bi-save2-fill me-2"></i> CREATE TRANSACTION</button>
            </form>
        </div>
    </div>
</div>

<script>
let availableItems = [];

// [FIX] Prevent Double Submission
document.getElementById('obligationForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('saveBtn');
    
    // Safety check: if already disabled, stop
    if (btn.disabled) {
        e.preventDefault();
        return;
    }

    // Disable and show loading state
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
});

function fetchItems() {
    let y = document.getElementById('fy_select').value;
    let f = document.getElementById('fund_select').value;
    let container = document.getElementById('items_container');
    container.innerHTML = '<div class="text-center text-muted p-2">Loading items...</div>';
    fetch(`create_obligation.php?action=get_items_json&year=${y}&fund=${f}`).then(r=>r.json()).then(data=>{
        availableItems = data; container.innerHTML = ''; addItemRow(); validateLocks();
    });
}

function addItemRow() {
    let options = '<option value="">-- Select Line Item --</option>';
    if (availableItems.length) availableItems.forEach(i => {
        let disp = i.ppa_name ? `[${i.ppa_name}] ${i.name}` : i.name;
        options += `<option value="${i.id}">${disp}</option>`;
    });
    const html = `<div class="row g-2 mb-2 align-items-center item-row"><div class="col-md-8"><select name="bucket_ids[]" class="form-select form-select-sm fw-bold border-secondary item-select" required onchange="validateLocks()">${options}</select></div><div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text fw-bold">₱</span><input type="text" name="amounts[]" class="form-control fw-bold text-success amount-input" placeholder="0.00" oninput="formatAmount(this); calculateTotal();" required></div></div><div class="col-md-1 text-center"><button type="button" class="btn btn-link text-danger p-0" onclick="this.closest('.item-row').remove(); calculateTotal();"><i class="bi bi-x-circle-fill"></i></button></div></div>`;
    document.getElementById('items_container').insertAdjacentHTML('beforeend', html);
}

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(inp => total += parseFloat(inp.value.replace(/,/g, ''))||0);
    document.getElementById('runningTotalDisplay').innerText = "₱ " + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}

function validateDateRange() {
    const year = document.getElementById('fy_select').value;
    const period = document.getElementById('period_select').value;
    const dateInput = document.getElementById('target_date');
    let min, max;
    if(period.includes('q1')) { min=`${year}-01-01`; max=`${year}-03-31`; }
    else if(period.includes('q2')) { min=`${year}-04-01`; max=`${year}-06-30`; }
    else if(period.includes('q3')) { min=`${year}-07-01`; max=`${year}-09-30`; }
    else if(period.includes('q4')) { min=`${year}-10-01`; max=`${year}-12-31`; }
    else { min=`${year}-01-01`; max=`${year}-12-31`; }
    dateInput.min = min; dateInput.max = max;
    document.getElementById('date_hint').innerText = `Valid: ${min} to ${max}`;
}

function validateLocks() {
    const period = document.getElementById('period_select').value;
    const statusSel = document.getElementById('status_select');
    const optObligated = document.getElementById('opt_obligated');
    const warning = document.getElementById('lock_warning');
    let isLocked = false;

    document.querySelectorAll('.item-select').forEach(sel => {
        const item = availableItems.find(i => i.id == sel.value);
        if(item && period) {
            if((period=='q2' && item.q2_released == '0') || (period=='q3' && item.q3_released == '0') || (period=='q4' && item.q4_released == '0') || (period=='sem2' && item.sem2_released == '0') || (period=='reserve' && item.reserve_released == '0')) isLocked=true;
        }
    });

    if(isLocked) {
        warning.style.display = 'block';
        statusSel.value = 'pending';
        optObligated.disabled = true; 
        statusSel.className = 'form-select fw-bold bg-secondary text-white';
    } else {
        warning.style.display = 'none';
        optObligated.disabled = false;
        if(statusSel.value === 'obligated') statusSel.className = 'form-select fw-bold bg-success text-white';
    }
}

document.getElementById('status_select').addEventListener('change', function() {
    this.className = 'form-select fw-bold ' + (this.value=='obligated' ? 'bg-success text-white' : 'bg-secondary text-white');
});

function formatAmount(input) { let v=input.value.replace(/[^0-9.]/g,''),p=v.split('.');p[0]=p[0].replace(/\B(?=(\d{3})+(?!\d))/g,",");input.value=p.join('.')+(p.length>1&&p[1].length>2?'':''); }
function toggleBonded() { const chk=document.getElementById('bonded_check'); const inp=document.getElementById('officer_input'); inp.disabled=!chk.checked; if(!chk.checked) inp.value=''; }

// Procurement Linking Validation & Live Preview
const procProjectsList = <?= json_encode($proc_projects); ?>;
const alreadyLinkedCodes = <?= json_encode($already_linked_codes); ?>;
const draftProcCodes = <?= json_encode($draft_proc_codes); ?>;

function onProcSelectChange(val) {
    const inp = document.getElementById('proc_doc_code_input');
    if (inp) {
        inp.value = val;
        validateProcCode(val);
    }
}

function syncSelectWithInput(val) {
    const sel = document.getElementById('proc_doc_select');
    if (sel) {
        sel.value = val;
    }
}

function clearProcCode() {
    const inp = document.getElementById('proc_doc_code_input');
    const sel = document.getElementById('proc_doc_select');
    if (inp) inp.value = '';
    if (sel) sel.value = '';
    validateProcCode('');
}

function validateProcCode(code) {
    const previewDiv = document.getElementById('proc_code_preview');
    const saveBtn = document.getElementById('saveBtn');
    if (!previewDiv) return;
    const cleanCode = code ? code.trim().toUpperCase() : '';

    if (!cleanCode) {
        previewDiv.style.display = 'none';
        previewDiv.innerHTML = '';
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.title = '';
        }
        return;
    }

    // 1. Guard Check: Is this code already linked to an existing obligation?
    const matchedLinked = alreadyLinkedCodes.find(item => item.document_code && item.document_code.toUpperCase() === cleanCode);
    if (matchedLinked) {
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.title = `Double-Link Blocked: Code ${cleanCode} is already bound to obligation ${matchedLinked.transaction_ref}`;
        }
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2.5 rounded border border-danger bg-danger-subtle text-danger d-flex align-items-start gap-2 shadow-2xs">
                <i class="bi bi-x-circle-fill text-danger fs-5 mt-0.5"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong class="text-danger small">ALREADY LINKED &bull; DOUBLE-LINK FORBIDDEN</strong>
                        <span class="badge bg-danger" style="font-size: 0.65rem;">BLOCKED</span>
                    </div>
                    <div class="small mt-1 text-danger-emphasis" style="font-size: 0.76rem;">
                        Procurement Code <strong>${cleanCode}</strong> is already linked to Obligation <strong>${matchedLinked.transaction_ref}</strong> (${matchedLinked.ppa_name}).
                    </div>
                    <div class="small text-muted mt-0.5" style="font-size: 0.70rem;">
                        Each procurement document can only be linked to one obligation. Please select an unlinked project from the dropdown.
                    </div>
                </div>
            </div>
        `;
        return;
    }

    // 2. Guard Check: Is this project still in DRAFT or CANCELLED in CHO Procurement?
    const matchedDraft = draftProcCodes.find(item => item.document_code && item.document_code.toUpperCase() === cleanCode);
    if (matchedDraft) {
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.title = matchedDraft.status === 'CANCELLED' 
                ? `Blocked: Project ${cleanCode} is CANCELLED`
                : `Blocked: Project ${cleanCode} is in DRAFT status`;
        }
        previewDiv.style.display = 'block';

        if (matchedDraft.status === 'CANCELLED') {
            previewDiv.innerHTML = `
                <div class="p-2.5 rounded border border-danger bg-danger-subtle text-danger d-flex align-items-start gap-2 shadow-2xs">
                    <i class="bi bi-x-circle-fill text-danger fs-5 mt-0.5"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="text-danger small">PROJECT CANCELLED IN PROCUREMENT</strong>
                            <span class="badge bg-danger" style="font-size: 0.65rem;">CANCELLED</span>
                        </div>
                        <div class="small mt-1 text-danger-emphasis" style="font-size: 0.76rem;">
                            Project <strong>${matchedDraft.title}</strong> (${cleanCode}) has been cancelled and cannot be linked to budget obligations.
                        </div>
                    </div>
                </div>
            `;
        } else {
            previewDiv.innerHTML = `
                <div class="p-2.5 rounded border border-warning bg-warning-subtle text-warning-emphasis d-flex align-items-start gap-2 shadow-2xs">
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

    // If not blocked, make sure save button is enabled
    if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.title = '';
    }

    // 2. Is this code in available unlinked procurement projects?
    const matched = procProjectsList.find(p => p.document_code && p.document_code.toUpperCase() === cleanCode);
    if (matched) {
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2.5 rounded border border-success bg-white d-flex align-items-start gap-2 shadow-2xs">
                <i class="bi bi-check-circle-fill text-success fs-6 mt-0.5"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong class="text-dark small text-truncate d-block">${matched.title}</strong>
                        <span class="badge bg-success" style="font-size: 0.65rem;">AVAILABLE FOR LINKING</span>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <span>FY ${matched.target_year}</span> &bull; <span>${matched.fund_source}</span> &bull; <span class="font-monospace text-dark">${matched.document_code}</span>
                    </div>
                </div>
            </div>
        `;
    } else {
        previewDiv.style.display = 'block';
        previewDiv.innerHTML = `
            <div class="p-2 rounded border border-info bg-white d-flex align-items-center gap-2">
                <i class="bi bi-info-circle text-info"></i>
                <span class="text-muted small" style="font-size: 0.72rem;">Custom code: A new 18-stage physical tracking project will be registered for <strong>${cleanCode}</strong> upon saving.</span>
            </div>
        `;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    fetchItems();
    const input = document.getElementById('proc_doc_code_input');
    if (input && input.value) {
        syncSelectWithInput(input.value);
        validateProcCode(input.value);
    }
});
</script>

<?php require 'includes/footer.php'; ?>