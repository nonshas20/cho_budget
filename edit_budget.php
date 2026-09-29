<?php
require 'includes/db_connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header("Location: index.php"); exit; }

$page_title = 'Budget Allocation Engine';
require 'includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: budget_matrix.php"); exit; }

// ---------------------------------------------------------
// 1. HANDLE POST ACTIONS
// ---------------------------------------------------------

// A. SAVE CONFIGURATION
if (isset($_POST['save_budget'])) {
    try {
        $pdo->beginTransaction();
        
        $amount_raw = floatval(str_replace(',', '', $_POST['amount']));
        $account_code = !empty($_POST['account_code']) ? trim($_POST['account_code']) : null; // [NEW] Capture Code
        
        function getVal($name) { return isset($_POST['use_' . $name]) ? floatval($_POST['pct_' . $name]) : 0; }

        $p_s1 = getVal('sem1'); $p_s2 = getVal('sem2');
        $p_q1 = getVal('q1');   $p_q2 = getVal('q2');
        $p_q3 = getVal('q3');   $p_q4 = getVal('q4');
        $p_res = getVal('reserve'); 
        
        $p_full = 0;

        if (isset($_POST['use_full'])) {
            $p_s1=$p_s2=$p_q1=$p_q2=$p_q3=$p_q4=$p_res=0;
            $p_full = 100; 
        }

        // [NEW] Update Account Code in Line Items Table
        // We first need to find the line_item_id linked to this budget bucket
        $stmt_li = $pdo->prepare("SELECT line_item_id FROM budget_buckets WHERE id = ?");
        $stmt_li->execute([$id]);
        $line_item_id = $stmt_li->fetchColumn();
        
        if ($line_item_id) {
            $pdo->prepare("UPDATE line_items SET account_code = ? WHERE id = ?")->execute([$account_code, $line_item_id]);
        }

        // Update Appropriations
        $check = $pdo->prepare("SELECT id FROM appropriations WHERE budget_bucket_id = ?");
        $check->execute([$id]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE appropriations SET amount = ? WHERE budget_bucket_id = ?")->execute([$amount_raw, $id]);
        } else {
            $pdo->prepare("INSERT INTO appropriations (budget_bucket_id, amount) VALUES (?, ?)")->execute([$id, $amount_raw]);
        }

        // Update Config
        $sql_up = "UPDATE budget_buckets SET pct_sem1=?, pct_sem2=?, pct_q1=?, pct_q2=?, pct_q3=?, pct_q4=?, pct_reserve=?, pct_full=? WHERE id=?";
        $pdo->prepare($sql_up)->execute([$p_s1, $p_s2, $p_q1, $p_q2, $p_q3, $p_q4, $p_res, $p_full, $id]);

        // Generate Allotments
        $pdo->prepare("DELETE FROM allotments WHERE budget_bucket_id = ?")->execute([$id]);
        $ins = $pdo->prepare("INSERT INTO allotments (budget_bucket_id, quarter, amount) VALUES (?, ?, ?)");

        // Q1
        $amt_q1 = 0;
        if ($p_s1 > 0) $amt_q1 += ($amount_raw * ($p_s1 / 100)) / 2;
        if ($p_q1 > 0) $amt_q1 += ($amount_raw * ($p_q1 / 100));
        if ($p_full > 0) $amt_q1 += $amount_raw; 
        if ($amt_q1 > 0) $ins->execute([$id, 1, $amt_q1]);

        // Q2
        $amt_q2 = 0;
        if ($p_s1 > 0) $amt_q2 += ($amount_raw * ($p_s1 / 100)) / 2;
        if ($p_q2 > 0) $amt_q2 += ($amount_raw * ($p_q2 / 100));
        if ($amt_q2 > 0) $ins->execute([$id, 2, $amt_q2]);

        // Q3
        $amt_q3 = 0;
        if ($p_s2 > 0) $amt_q3 += ($amount_raw * ($p_s2 / 100)) / 2;
        if ($p_q3 > 0) $amt_q3 += ($amount_raw * ($p_q3 / 100));
        if ($amt_q3 > 0) $ins->execute([$id, 3, $amt_q3]);

        // Q4
        $amt_q4 = 0;
        if ($p_s2 > 0) $amt_q4 += ($amount_raw * ($p_s2 / 100)) / 2;
        if ($p_q4 > 0) $amt_q4 += ($amount_raw * ($p_q4 / 100));
        if ($amt_q4 > 0) $ins->execute([$id, 4, $amt_q4]);

        $pdo->commit();
        $success_msg = "Configuration saved successfully.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage();
    }
}

// B. HANDLE LOCK TOGGLES
if (isset($_POST['toggle_lock'])) {
    $col = $_POST['target_col'];
    $val = ($_POST['action'] == 'unlock') ? 1 : 0;
    $allowed = ['q2_released', 'q3_released', 'q4_released', 'reserve_released', 'sem2_released'];
    if (in_array($col, $allowed)) {
        $pdo->prepare("UPDATE budget_buckets SET $col = ? WHERE id = ?")->execute([$val, $id]);
        $success_msg = "Lock status updated.";
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA & DEFAULTS
// ---------------------------------------------------------
// [UPDATED] Add li.account_code to SELECT
$stmt = $pdo->prepare("SELECT bb.*, li.name AS item_name, li.account_code, o.name AS ooe_name, fs.name AS fund_name,
                       COALESCE((SELECT amount FROM appropriations WHERE budget_bucket_id = bb.id), 0) as current_app
                       FROM budget_buckets bb
                       JOIN line_items li ON bb.line_item_id = li.id
                       JOIN ooes o ON li.ooe_id = o.id
                       JOIN fund_sources fs ON bb.fund_source_id = fs.id
                       WHERE bb.id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

$saaob = $item['current_app'];
$ooe_cls = strtoupper($item['ooe_name']);
$is_new = ($item['pct_sem1'] + $item['pct_sem2'] + $item['pct_q1'] + $item['pct_q2'] + $item['pct_q3'] + $item['pct_q4'] + $item['pct_reserve'] + $item['pct_full']) == 0;

$use_full = ($item['pct_full'] > 0);

if ($is_new) {
    if (strpos($ooe_cls, 'PS') !== false || $item['release_type'] == 'semestral') {
        $item['pct_sem1'] = 50; $item['pct_sem2'] = 50;
    } elseif (strpos($ooe_cls, 'CO') !== false || $item['release_type'] == 'full') {
        $use_full = true;
    } else {
        $item['pct_sem1'] = 55; $item['pct_q3'] = 15; $item['pct_q4'] = 10; $item['pct_reserve'] = 20;
    }
} else {
    if (!$use_full && $item['pct_q1'] == 100 && $item['pct_sem1'] == 0 && $item['pct_q2'] == 0) {
        $use_full = true; $item['pct_q1'] = 0; 
    }
}

// ---------------------------------------------------------
// 3. LOCK LOGIC
// ---------------------------------------------------------
$s2_open = $item['sem2_released'];
$q2_open = $item['q2_released'];
$q3_open = $item['q3_released'];
$q4_open = $item['q4_released'];
$res_open = $item['reserve_released'];

$can_unlock_s2 = true; 
$can_unlock_q2 = true; 
$can_unlock_q3 = $q2_open || ($item['pct_sem1'] > 0); 
$can_unlock_q4 = $q3_open; 
$can_unlock_res = $q4_open || ($item['pct_sem2'] > 0 && $s2_open); 

function renderLockBtn($id_suffix, $targetCol, $isOpen, $canUnlock, $dependencyMsg) {
    $style = "border-radius: 6px; width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center;";
    echo '<span id="lock_container_'.$id_suffix.'">'; 
    if (!$isOpen) {
        if ($canUnlock) {
            echo '<button type="submit" form="lockForm" name="toggle_lock" onclick="setLockTarget(\''.$targetCol.'\', \'unlock\')" 
                  class="btn btn-danger btn-sm shadow-sm" title="Locked - Click to Release" style="'.$style.'">
                  <i class="bi bi-lock-fill"></i></button>';
        } else {
            echo '<button type="button" class="btn btn-secondary btn-sm shadow-sm" disabled 
                  title="'.$dependencyMsg.'" style="'.$style.' opacity: 0.5;">
                  <i class="bi bi-lock-fill"></i></button>';
        }
    } else {
        echo '<button type="submit" form="lockForm" name="toggle_lock" onclick="setLockTarget(\''.$targetCol.'\', \'lock\')" 
              class="btn btn-success btn-sm shadow-sm" title="Released - Click to Lock" style="'.$style.'">
              <i class="bi bi-unlock-fill"></i></button>';
    }
    echo '</span>';
}
?>

<div class="container mt-4" style="max-width: 1200px;">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-primary">Budget Allocation Engine</h4>
        <a href="budget_matrix.php" class="btn btn-secondary fw-bold">Back to Matrix</a>
    </div>

    <?php if (isset($success_msg)): ?><div class="alert alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if (isset($error_msg)): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <form method="POST">
        <div class="card shadow-sm border-start border-5 border-primary mb-4">
            <div class="card-body">
                <div class="row align-items-end">
                    
                    <div class="col-md-5">
                        <label class="form-label small text-muted fw-bold">Line Item</label>
                        <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                        <div class="text-muted small">
                            <?php echo $item['fund_name']; ?> &bull; <span class="badge bg-light text-dark border"><?php echo $item['ooe_name']; ?></span>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small text-muted fw-bold">UACS Code</label>
                        <input type="text" name="account_code" id="uacs_budget_input" 
                               class="form-control fw-bold font-monospace text-primary" 
                               value="<?php echo htmlspecialchars($item['account_code'] ?? ''); ?>" 
                               placeholder="5-02-..." maxlength="14">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Annual Appropriation (SAAOB)</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold">₱</span>
                            <input type="text" name="amount" id="saaobInput" class="form-control fw-bold text-primary" 
                                   value="<?php echo number_format($saaob, 2); ?>" oninput="calculateAll()">
                        </div>
                    </div>
                    
                    <div class="col-md-1">
                        <button type="submit" name="save_budget" class="btn btn-primary fw-bold w-100">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-3">
            <span class="fw-bold text-muted small">TOTAL PERCENTAGE ALLOCATED:</span>
            <h5 class="fw-bold mb-0" id="totalPctDisplay">0%</h5>
        </div>

        <div class="row g-3">
            
            <div class="col-md-3">
                <div class="card h-100 border-primary bg-light">
                    <div class="card-header bg-primary text-white fw-bold text-center small">SEMESTRAL</div>
                    <div class="card-body p-2">
                        <div class="card mb-2 shadow-sm">
                            <div class="card-body p-2">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="use_sem1" id="chk_s1" 
                                           <?php echo ($item['pct_sem1']>0) ? 'checked' : ''; ?> onchange="toggleGroups('s1')">
                                    <label class="form-check-label small fw-bold" for="chk_s1">1st Sem (%)</label>
                                </div>
                                <input type="number" name="pct_sem1" id="p_s1" class="form-control form-control-sm fw-bold text-center mb-1" 
                                       value="<?php echo $item['pct_sem1']; ?>" min="0" max="100" step="0.01" oninput="calculateAll()">
                                <div class="text-center fw-bold text-primary small">₱ <span id="val_s1">0.00</span></div>
                            </div>
                        </div>
                        <div class="card shadow-sm">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="use_sem2" id="chk_s2" 
                                               <?php echo ($item['pct_sem2']>0) ? 'checked' : ''; ?> onchange="toggleGroups('s2')">
                                        <label class="form-check-label small fw-bold" for="chk_s2">2nd Sem (%)</label>
                                    </div>
                                    <?php renderLockBtn('s2', 'sem2_released', $s2_open, $can_unlock_s2, ''); ?>
                                </div>
                                <input type="number" name="pct_sem2" id="p_s2" class="form-control form-control-sm fw-bold text-center mb-2" 
                                       value="<?php echo $item['pct_sem2']; ?>" min="0" max="100" step="0.01" oninput="calculateAll()">
                                <div class="text-center fw-bold text-primary small mb-2">₱ <span id="val_s2">0.00</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card h-100 border-success bg-light">
                    <div class="card-header bg-success text-white fw-bold text-center small">QUARTERLY</div>
                    <div class="card-body p-2">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-2">
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input" type="checkbox" name="use_q1" id="chk_q1" 
                                                   <?php echo ($item['pct_q1']>0) ? 'checked' : ''; ?> onchange="toggleGroups('q1')">
                                            <label class="form-check-label small fw-bold" for="chk_q1">Q1 (%)</label>
                                        </div>
                                        <input type="number" name="pct_q1" id="p_q1" class="form-control form-control-sm fw-bold text-center mb-1" value="<?php echo $item['pct_q1']; ?>" oninput="calculateAll()">
                                        <div class="text-center fw-bold text-success small">₱ <span id="val_q1">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="use_q2" id="chk_q2" 
                                                       <?php echo ($item['pct_q2']>0) ? 'checked' : ''; ?> onchange="toggleGroups('q2')">
                                                <label class="form-check-label small fw-bold" for="chk_q2">Q2 (%)</label>
                                            </div>
                                            <?php renderLockBtn('q2', 'q2_released', $q2_open, $can_unlock_q2, ''); ?>
                                        </div>
                                        <input type="number" name="pct_q2" id="p_q2" class="form-control form-control-sm fw-bold text-center mb-2" value="<?php echo $item['pct_q2']; ?>" oninput="calculateAll()">
                                        <div class="text-center fw-bold text-success small mb-2">₱ <span id="val_q2">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="use_q3" id="chk_q3" 
                                                       <?php echo ($item['pct_q3']>0) ? 'checked' : ''; ?> onchange="toggleGroups('q3')">
                                                <label class="form-check-label small fw-bold" for="chk_q3">Q3 (%)</label>
                                            </div>
                                            <?php renderLockBtn('q3', 'q3_released', $q3_open, $can_unlock_q3, 'Unlock Q2 first'); ?>
                                        </div>
                                        <input type="number" name="pct_q3" id="p_q3" class="form-control form-control-sm fw-bold text-center mb-2" value="<?php echo $item['pct_q3']; ?>" oninput="calculateAll()">
                                        <div class="text-center fw-bold text-success small mb-2">₱ <span id="val_q3">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card shadow-sm h-100">
                                    <div class="card-body p-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="use_q4" id="chk_q4" 
                                                       <?php echo ($item['pct_q4']>0) ? 'checked' : ''; ?> onchange="toggleGroups('q4')">
                                                <label class="form-check-label small fw-bold" for="chk_q4">Q4 (%)</label>
                                            </div>
                                            <?php renderLockBtn('q4', 'q4_released', $q4_open, $can_unlock_q4, 'Unlock Q3 first'); ?>
                                        </div>
                                        <input type="number" name="pct_q4" id="p_q4" class="form-control form-control-sm fw-bold text-center mb-2" value="<?php echo $item['pct_q4']; ?>" oninput="calculateAll()">
                                        <div class="text-center fw-bold text-success small mb-2">₱ <span id="val_q4">0.00</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card h-100 border-info bg-light">
                    <div class="card-header bg-info text-white fw-bold text-center small">FULL</div>
                    <div class="card-body p-2">
                        <div class="card shadow-sm h-100">
                            <div class="card-body p-2 d-flex flex-column justify-content-center text-center">
                                <div class="form-check form-switch mb-1 d-inline-block">
                                    <input class="form-check-input" type="checkbox" name="use_full" id="chk_full" 
                                           <?php echo ($use_full) ? 'checked' : ''; ?> onchange="toggleGroups('full')">
                                    <label class="form-check-label small fw-bold" for="chk_full">Full (100%)</label>
                                </div>
                                <input type="number" name="pct_full" id="p_full" class="form-control form-control-sm fw-bold text-center mb-1" 
                                       value="<?php echo $use_full ? 100 : 0; ?>" readonly>
                                <div class="fw-bold text-dark small">₱ <span id="val_full">0.00</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-2">
                <div class="card h-100 border-danger bg-light">
                    <div class="card-header bg-danger text-white fw-bold text-center small">RESERVE</div>
                    <div class="card-body p-2">
                        <div class="card shadow-sm h-100 border-danger">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="use_reserve" id="chk_res" 
                                               <?php echo ($item['pct_reserve']>0) ? 'checked' : ''; ?> onchange="toggleGroups('res')">
                                        <label class="form-check-label small fw-bold text-danger" for="chk_res">Reserve</label>
                                    </div>
                                    <?php renderLockBtn('res', 'reserve_released', $res_open, $can_unlock_res, 'Unlock Q4 first'); ?>
                                </div>
                                <input type="number" name="pct_reserve" id="p_res" class="form-control form-control-sm fw-bold text-center mb-2 text-danger" 
                                       value="<?php echo $item['pct_reserve']; ?>" min="0" max="100" step="0.01" oninput="calculateAll()">
                                <div class="text-center fw-bold text-danger small mb-2">₱ <span id="val_res">0.00</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
    
    <form id="lockForm" method="POST">
        <input type="hidden" name="target_col" id="lockTargetCol">
        <input type="hidden" name="action" id="lockAction">
    </form>
</div>

<script>
const keys = ['s1', 's2', 'q1', 'q2', 'q3', 'q4', 'full', 'res'];
const inputs = {}; const checks = {}; const vals = {};
const lockMap = { 's2': 'lock_container_s2', 'q2': 'lock_container_q2', 'q3': 'lock_container_q3', 'q4': 'lock_container_q4', 'res': 'lock_container_res' };

keys.forEach(k => {
    inputs[k] = document.getElementById('p_' + k);
    checks[k] = document.getElementById('chk_' + k);
    vals[k]   = document.getElementById('val_' + k);
});

function toggleGroups(trigger) {
    if (trigger === 's1' && checks.s1.checked) { checks.q1.checked=false; checks.q2.checked=false; checks.full.checked=false; }
    if (trigger === 's2' && checks.s2.checked) { checks.q3.checked=false; checks.q4.checked=false; checks.full.checked=false; }
    if (trigger === 'full' && checks.full.checked) { keys.forEach(k => { if(k!=='full') checks[k].checked=false; }); }
    if ((trigger === 'q1' || trigger === 'q2') && checks[trigger].checked) checks.s1.checked = false;
    if ((trigger === 'q3' || trigger === 'q4') && checks[trigger].checked) checks.s2.checked = false;
    calculateAll();
}

function calculateAll() {
    let raw = document.getElementById('saaobInput').value.replace(/[^0-9.]/g, '');
    let parts = raw.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    document.getElementById('saaobInput').value = parts.join('.') + (parts.length > 1 && parts[1].length > 2 ? '' : '');
    
    let totalAmt = parseFloat(raw) || 0;
    let totalPct = 0;

    keys.forEach(k => {
        let el = inputs[k]; let chk = checks[k];
        if (chk.checked) {
            el.disabled = false;
            if(k === 'full') el.value = 100;
            let p = parseFloat(el.value) || 0;
            totalPct += p;
            let v = totalAmt * (p / 100);
            vals[k].innerText = v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            if(lockMap[k]) {
                let btn = document.getElementById(lockMap[k]);
                if(btn) btn.style.display = 'inline-block';
            }
        } else {
            el.disabled = true; el.value = 0; vals[k].innerText = "0.00";
            if(lockMap[k]) {
                let btn = document.getElementById(lockMap[k]);
                if(btn) btn.style.display = 'none';
            }
        }
    });

    let disp = document.getElementById('totalPctDisplay');
    disp.innerText = totalPct.toFixed(2) + "%";
    disp.className = "fw-bold mb-0 " + (Math.abs(totalPct - 100) < 0.1 ? "text-success" : "text-danger");
}

function setLockTarget(c, a) {
    document.getElementById('lockTargetCol').value = c;
    document.getElementById('lockAction').value = a;
}

// [NEW] AUTO FORMAT UACS CODE (5-02-...)
document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'uacs_budget_input') {
        let input = e.target.value.replace(/\D/g, ''); 
        let formatted = '';

        if (input.length > 0) formatted += input.substring(0, 1);
        if (input.length >= 2) formatted += '-' + input.substring(1, 3);
        if (input.length >= 4) formatted += '-' + input.substring(3, 5);
        if (input.length >= 6) formatted += '-' + input.substring(5, 8);
        if (input.length >= 9) formatted += '-' + input.substring(8, 10);

        e.target.value = formatted;
    }
});

document.addEventListener('DOMContentLoaded', calculateAll);
</script>

<?php require 'includes/footer.php'; ?>