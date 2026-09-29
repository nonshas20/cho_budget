<?php
require 'includes/db_connect.php';

// 1. SECURITY & SESSION
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$success_msg = "";
$error_msg = "";

// ---------------------------------------------------------
// LOGIC: CREATE NEW FISCAL YEAR
// ---------------------------------------------------------
if (isset($_POST['create_year'])) {
    $new_year = intval($_POST['new_year']);
    
    if ($new_year < 2020 || $new_year > 2099) {
        $error_msg = "Invalid Year. Please enter a valid 4-digit year (e.g., 2026).";
    } else {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM fiscal_years WHERE year = ?");
            $check->execute([$new_year]);
            if ($check->fetchColumn() > 0) {
                $error_msg = "Fiscal Year $new_year already exists.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO fiscal_years (year) VALUES (?)");
                $stmt->execute([$new_year]);
                log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'CREATE', "Opened New Fiscal Year: $new_year");
                $success_msg = "Fiscal Year $new_year successfully opened.";
            }
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// LOGIC: SET CURRENT WORKING YEAR
// ---------------------------------------------------------
if (isset($_POST['set_active_year'])) {
    $target_year = $_POST['active_year'];
    $_SESSION['active_year'] = $target_year;
    $success_msg = "Working context set to Fiscal Year $target_year.";
}

// ---------------------------------------------------------
// LOGIC: ADD NEW ITEM TO LIBRARY
// ---------------------------------------------------------
if (isset($_POST['add_item'])) {
    $name = trim($_POST['item_name']);
    $ooe_id = $_POST['ooe_id'];
    $code = !empty($_POST['account_code']) ? trim($_POST['account_code']) : null; 
    
    if (!empty($name) && !empty($ooe_id)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO line_items (name, account_code, ooe_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $code, $ooe_id]);
            log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'CREATE', "Added Item to Library: $name");
            $success_msg = "Item added to library.";
        } catch (Exception $e) {
            $error_msg = "Error adding item: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all fields.";
    }
}

// ---------------------------------------------------------
// LOGIC: DELETE ITEM FROM LIBRARY
// ---------------------------------------------------------
if (isset($_POST['delete_item_id'])) {
    $del_id = $_POST['delete_item_id'];
    
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM budget_buckets WHERE line_item_id = ?");
        $check->execute([$del_id]);
        
        if ($check->fetchColumn() > 0) {
            $error_msg = "Cannot delete: This item is currently active in a budget. Remove it from the Budget Matrix first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM line_items WHERE id = ?");
            $stmt->execute([$del_id]);
            log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'DELETE', "Deleted Item ID #$del_id from Library");
            $success_msg = "Item removed from library.";
        }
    } catch (Exception $e) {
        $error_msg = "Database Error: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// LOGIC: BULK ACTIVATE ITEMS FOR BUDGET YEAR (UPDATED FOR PPA)
// ---------------------------------------------------------
if (isset($_POST['activate_items'])) {
    $target_year = $_POST['target_year'];
    $fund_id     = $_POST['fund_id'];
    $ppa_id      = !empty($_POST['ppa_id']) ? $_POST['ppa_id'] : null; // [NEW] Capture LHB Program
    $items       = $_POST['item_ids'] ?? [];

    if (!empty($items)) {
        try {
            $pdo->beginTransaction();
            $count = 0;
            
            foreach ($items as $item_id) {
                // [NEW] Check uniqueness based on Year + Fund + Line Item + PPA
                // If PPA is null, we check where ppa_id IS NULL
                if ($ppa_id) {
                    $check = $pdo->prepare("SELECT id FROM budget_buckets WHERE fiscal_year = ? AND fund_source_id = ? AND line_item_id = ? AND ppa_id = ?");
                    $check->execute([$target_year, $fund_id, $item_id, $ppa_id]);
                } else {
                    $check = $pdo->prepare("SELECT id FROM budget_buckets WHERE fiscal_year = ? AND fund_source_id = ? AND line_item_id = ? AND ppa_id IS NULL");
                    $check->execute([$target_year, $fund_id, $item_id]);
                }
                
                if (!$check->fetch()) {
                    $ooe_sql = "SELECT o.name FROM line_items li JOIN ooes o ON li.ooe_id = o.id WHERE li.id = ?";
                    $ooe_stmt = $pdo->prepare($ooe_sql);
                    $ooe_stmt->execute([$item_id]);
                    $ooe_name = strtoupper($ooe_stmt->fetchColumn());

                    $p_full=0; $p_s1=0; $p_s2=0; $p_q1=0; $p_q2=0; $p_q3=0; $p_q4=0; $p_res=0;

                    if (strpos($ooe_name, 'PERSONAL') !== false || strpos($ooe_name, 'PS') !== false) {
                        $p_s1 = 50; $p_s2 = 50;
                    } elseif (strpos($ooe_name, 'CAPITAL') !== false || strpos($ooe_name, 'CO') !== false) {
                        $p_full = 100;
                    } else {
                        $p_s1 = 55; $p_q3 = 15; $p_q4 = 10; $p_res = 20;
                    }

                    // [NEW] Insert including the ppa_id
                    $ins = $pdo->prepare("INSERT INTO budget_buckets 
                        (fiscal_year, fund_source_id, ppa_id, line_item_id, pct_full, pct_sem1, pct_sem2, pct_q1, pct_q2, pct_q3, pct_q4, pct_reserve) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$target_year, $fund_id, $ppa_id, $item_id, $p_full, $p_s1, $p_s2, $p_q1, $p_q2, $p_q3, $p_q4, $p_res]);
                    $count++;
                }
            }
            
            $pdo->commit();
            if ($count > 0) {
                $prog_note = $ppa_id ? " (Under Program ID: $ppa_id)" : "";
                $success_msg = "Successfully activated $count items for FY $target_year$prog_note.";
                log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'UPDATE', "Bulk Activated $count items for FY $target_year");
            } else {
                $error_msg = "No new items were added (selected items might already exist in this budget configuration).";
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Error activating items: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please select at least one item to activate.";
    }
}

// FETCH DATA FOR DISPLAY
$years = $pdo->query("SELECT * FROM fiscal_years ORDER BY year DESC")->fetchAll();
$ooes = $pdo->query("SELECT * FROM ooes")->fetchAll();
$funds = $pdo->query("SELECT * FROM fund_sources")->fetchAll();

$current_working_year = $_SESSION['active_year'] ?? date('Y');


// Get Library Items
$library_sql = "SELECT li.id, li.name, li.account_code, o.name as ooe_name 
                FROM line_items li 
                JOIN ooes o ON li.ooe_id = o.id 
                ORDER BY li.name";
$library_items = $pdo->query($library_sql)->fetchAll();

$page_title = "System Configuration";
require 'includes/header.php';
?>

<div class="container mt-4 mb-5" style="max-width: 1200px;">
    
    <h3 class="mb-4 fw-bold text-dark"><i class="bi bi-gear-fill me-2"></i>System Configuration</h3>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white fw-bold">
            1. Fiscal Year Controls
        </div>
        <div class="card-body bg-light">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold small text-muted">Open Database Years</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach($years as $y): ?>
                            <span class="badge bg-primary fs-6"><?php echo $y['year']; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <form method="POST">
                        <label class="form-label fw-bold small text-muted">Current Working Context</label>
                        <div class="input-group">
                            <select name="active_year" class="form-select border-success fw-bold text-success">
                                <?php foreach($years as $y): ?>
                                    <option value="<?php echo $y['year']; ?>" <?php echo ($y['year'] == $current_working_year) ? 'selected' : ''; ?>>
                                        Work on Fiscal Year: <?php echo $y['year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="set_active_year" class="btn btn-outline-success">Set Active</button>
                        </div>
                    </form>
                </div>

                <div class="col-md-3">
                    <form method="POST">
                        <label class="form-label fw-bold small text-muted">Open New Year</label>
                        <div class="input-group">
                            <input type="number" name="new_year" class="form-control" placeholder="YYYY" min="2020" max="2099" step="1" required>
                            <button type="submit" name="create_year" class="btn btn-outline-dark">Create</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        
        <div class="col-lg-5">
            <div class="card shadow border-0 h-100">
                <div class="card-header bg-secondary text-white fw-bold d-flex justify-content-between align-items-center">
                    <span>Step A: Item Library</span>
                    <span class="badge bg-light text-dark">Global Master List</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Add items here first (e.g., "Office Supplies"). These items can be reused every year.</p>
                    
                    <form method="POST" class="mb-4 p-3 bg-light rounded border">
                        <div class="mb-2">
                            <label class="form-label fw-bold small">Item Name</label>
                            <input type="text" name="item_name" class="form-control form-control-sm" placeholder="e.g., Office Supplies" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold small text-muted">UACS Account Code (Optional)</label>
                            <input type="text" name="account_code" id="uacs_settings_input" class="form-control form-control-sm fw-bold font-monospace text-primary" 
                                   placeholder="e.g. 5-02-99-990-01" maxlength="14">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Classification</label>
                            <select name="ooe_id" class="form-select form-select-sm" required>
                                <?php foreach($ooes as $o): ?>
                                    <option value="<?php echo $o['id']; ?>"><?php echo $o['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="add_item" class="btn btn-sm btn-secondary w-100 fw-bold">Add to Library</button>
                    </form>

                    <h6 class="fw-bold border-bottom pb-2">Library Items</h6>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <ul class="list-group list-group-flush small">
                            <?php foreach($library_items as $item): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        
                                        <?php if(!empty($item['account_code'])): ?>
                                            <span class="badge bg-light text-primary border ms-1 font-monospace" style="font-size: 0.7rem;">
                                                <?php echo htmlspecialchars($item['account_code']); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <br><span class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($item['ooe_name']); ?></span>
                                    </div>
                                    
                                    <form method="POST" onsubmit="return confirm('Delete this item? This is only possible if the item is NOT used in any budget.');">
                                        <input type="hidden" name="delete_item_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger border-0" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow border-0 h-100">
                <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
                    <span>Step B: Annual Budget Builder</span>
                    <span class="badge bg-white text-primary">Year: <?php echo $current_working_year; ?></span>
                </div>
                <div class="card-body bg-light">
                    <div class="alert alert-primary py-2 small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> You are adding items to the <strong><?php echo $current_working_year; ?></strong> budget.
                    </div>

                    <form method="POST" id="builderForm">
                        <input type="hidden" name="target_year" value="<?php echo $current_working_year; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fund Source</label>
                            <?php 
                                $gf_id = 1;
                                foreach($funds as $f) {
                                    if(strtolower($f['name']) === 'general fund') $gf_id = $f['id'];
                                }
                            ?>
                            <input type="hidden" name="fund_id" value="<?php echo $gf_id; ?>">
                            <input type="text" class="form-control fw-bold bg-light" value="General Fund (Default)" readonly>
                            <input type="hidden" name="ppa_id" value="">
                            <div class="form-text" style="font-size: 0.7rem;">For non-General Fund PPAs, please use the <a href="lhb_programs.php">LHB Programs Staging Area</a>.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold d-flex justify-content-between">
                                <span>3. Select Library Items to Activate</span>
                                <button type="button" class="btn btn-xs btn-link p-0 text-decoration-none" onclick="toggleCheckboxes(this)">Select All</button>
                            </label>
                            
                            <div class="card p-0 border border-secondary" style="height: 300px; overflow-y: auto;">
                                <ul class="list-group list-group-flush" id="itemList">
                                    <?php foreach($library_items as $item): ?>
                                        <li class="list-group-item">
                                            <div class="form-check">
                                                <input class="form-check-input item-checkbox" type="checkbox" name="item_ids[]" value="<?php echo $item['id']; ?>" id="chk_<?php echo $item['id']; ?>">
                                                <label class="form-check-label w-100" for="chk_<?php echo $item['id']; ?>">
                                                    <span class="fw-bold"><?php echo htmlspecialchars($item['name']); ?></span>
                                                    
                                                    <?php if(!empty($item['account_code'])): ?>
                                                        <span class="text-primary font-monospace ms-2 small">[<?php echo htmlspecialchars($item['account_code']); ?>]</span>
                                                    <?php endif; ?>
                                                    
                                                    <span class="badge bg-light text-muted border ms-2"><?php echo htmlspecialchars($item['ooe_name']); ?></span>
                                                </label>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="form-text">Checked items will be added to the Matrix for the selected Year, Fund, and Program.</div>
                        </div>

                        <button type="submit" name="activate_items" class="btn btn-primary w-100 fw-bold py-2">
                            <i class="bi bi-check-circle-fill me-2"></i> Activate Selected Items for <?php echo $current_working_year; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    /* Make Select2 match Bootstrap 5 styling */
    .select2-container .select2-selection--single {
        height: 38px !important;
        border: 1px solid #0d6efd; /* Primary border color */
        border-radius: 0.25rem;
        display: flex;
        align-items: center;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }
</style>

<script>
// 2. Select All Toggle Logic
function toggleCheckboxes(btn) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const isSelecting = btn.innerText === 'Select All';
    
    checkboxes.forEach(cb => {
        cb.checked = isSelecting;
    });
    
    btn.innerText = isSelecting ? 'Deselect All' : 'Select All';
}

// 5. Auto Format UACS Code (5-02-...)
document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'uacs_settings_input') {
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
</script>

<?php require 'includes/footer.php'; ?>