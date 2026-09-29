<?php 
// 1. START SESSION
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'includes/db_connect.php'; 

// 2. DELETE LOGIC (Updated to protect realignments)
if (isset($_POST['delete_bucket'])) {
    $id_to_delete = $_POST['bucket_id'];
    try {
        // Check obligations first
        $chk = $pdo->prepare("SELECT COUNT(*) FROM obligations WHERE budget_bucket_id = ?");
        $chk->execute([$id_to_delete]);
        
        // Check realignments second
        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM realignment_details WHERE budget_bucket_id = ?");
        $chk2->execute([$id_to_delete]);

        if ($chk->fetchColumn() > 0) {
            $error_msg = "Cannot delete: This item has active obligations.";
        } elseif ($chk2->fetchColumn() > 0) {
            $error_msg = "Cannot delete: This item is linked to a budget realignment request.";
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM allotments WHERE budget_bucket_id = ?")->execute([$id_to_delete]);
            $pdo->prepare("DELETE FROM appropriations WHERE budget_bucket_id = ?")->execute([$id_to_delete]);
            $pdo->prepare("DELETE FROM budget_buckets WHERE id = ?")->execute([$id_to_delete]);
            $pdo->commit();
            $success_msg = "Item deleted successfully.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error deleting: " . $e->getMessage();
    }
}

// --- CUSTOM FONTS & STYLES ---
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Roboto+Condensed:wght@400;500;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; }
    
    /* Condensed font for data tables to save space */
    .data-font { 
        font-family: 'Roboto Condensed', sans-serif; 
        font-size: 0.9rem; 
    }
    /* Numerical cells: Monospace-ish, bold, no wrapping */
    .num-cell {
        font-family: 'Roboto Condensed', monospace;
        white-space: nowrap; 
        font-weight: 500;
    }
    /* Truncate long text descriptions */
    .text-truncate-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.2;
    }
</style>

<?php
$page_title = 'Budget Matrix';
require 'includes/header.php'; 
$role = $_SESSION['role'] ?? 'viewer';

// DROPDOWNS (Moved up for defaults)
$yrs = $pdo->query("SELECT year FROM fiscal_years ORDER BY year DESC")->fetchAll();
$funds = $pdo->query("SELECT * FROM fund_sources")->fetchAll();
$ooes = $pdo->query("SELECT * FROM ooes")->fetchAll();

// 3. FILTER VARIABLES
$year = $_GET['year'] ?? date('Y');
$fund_id = $_GET['fund_id'] ?? ($funds[0]['id'] ?? 'all');
$ooe_id = $_GET['ooe_id'] ?? 'all';

// 4. BUILD QUERY (Updated with PPA and Realignment Math)
$where = ["bb.fiscal_year = ?"];
$params = [$year];
if ($fund_id != 'all') { $where[] = "bb.fund_source_id = ?"; $params[] = $fund_id; }
if ($ooe_id != 'all') { $where[] = "li.ooe_id = ?"; $params[] = $ooe_id; }
$where_sql = implode(" AND ", $where);

$sql = "SELECT bb.id, bb.fiscal_year, bb.reserve_released, 
               bb.q2_released, bb.q3_released, bb.q4_released, bb.sem2_released,
               bb.pct_sem1, bb.pct_sem2, bb.pct_q1, bb.pct_q2, bb.pct_q3, bb.pct_q4, bb.pct_reserve, bb.pct_full,
            COALESCE(app.amount, 0) as base_amount, 
            COALESCE(t_in.total, 0) AS transfer_in,
            COALESCE(t_out.total, 0) AS transfer_out,
            li.name AS item_name, li.account_code, o.name AS ooe_name, fs.name AS fund_name,
            ppa.name AS ppa_name, ppa.ppa_code AS ppa_code
        FROM budget_buckets bb
        JOIN line_items li ON bb.line_item_id = li.id
        JOIN ooes o ON li.ooe_id = o.id
        JOIN fund_sources fs ON bb.fund_source_id = fs.id
        LEFT JOIN budget_ppas ppa ON bb.ppa_id = ppa.id
        LEFT JOIN (
            SELECT budget_bucket_id, SUM(amount) as amount
            FROM appropriations
            GROUP BY budget_bucket_id
        ) app ON bb.id = app.budget_bucket_id
        LEFT JOIN (
            SELECT rd.budget_bucket_id, SUM(rd.amount) as total
            FROM realignment_details rd
            JOIN realignment_requests rr ON rd.request_id = rr.id
            WHERE rd.type = 'destination' AND rr.status = 'approved'
            GROUP BY rd.budget_bucket_id
        ) t_in ON bb.id = t_in.budget_bucket_id
        LEFT JOIN (
            SELECT rd.budget_bucket_id, SUM(rd.amount) as total
            FROM realignment_details rd
            JOIN realignment_requests rr ON rd.request_id = rr.id
            WHERE rd.type = 'source' AND rr.status = 'approved'
            GROUP BY rd.budget_bucket_id
        ) t_out ON bb.id = t_out.budget_bucket_id
        WHERE $where_sql
        ORDER BY fs.name, ppa.name, o.name, li.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$matrix = $stmt->fetchAll(PDO::FETCH_ASSOC);



function get_fund_acronym($name) {
    $clean_name = trim(strtolower($name));
    
    $map = [
        'general fund' => 'GF', 
        'special health fund' => 'SHF', 
        'trust fund' => 'TF', 
        'gender and development fund' => 'GAD',
        'national tax allotment' => 'NTA',
        'disaster risk reduction and management fund' => 'DRRMF'
    ];
    
    return $map[$clean_name] ?? strtoupper(substr($name, 0, 3));
}
?>

<div class="container-fluid mt-4 px-4">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-primary" style="font-family: 'Roboto Condensed';"><i class="bi bi-grid-3x3-gap me-2"></i>Budget Matrix (SAAOB)</h4>
    </div>

    <?php if (isset($success_msg)): ?><div class="alert alert-success py-2 small"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if (isset($error_msg)): ?><div class="alert alert-danger py-2 small"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3 bg-white border-0">
        <div class="card-body py-2">
            <form method="GET" class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <select name="year" class="form-select form-select-sm fw-bold" style="width: auto;" onchange="this.form.submit()">
                        <?php foreach($yrs as $y): ?><option value="<?php echo $y['year']; ?>" <?php echo $y['year']==$year?'selected':''; ?>><?php echo $y['year']; ?></option><?php endforeach; ?>
                    </select>
                    <input type="hidden" name="fund_id" value="<?php echo htmlspecialchars($fund_id); ?>">
                    <select name="ooe_id" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                        <option value="all">All OOE Classes</option>
                        <?php foreach($ooes as $o): ?><option value="<?php echo $o['id']; ?>" <?php echo $o['id']==$ooe_id?'selected':''; ?>><?php echo $o['name']; ?></option><?php endforeach; ?>
                    </select>
                </div>
                
                <div class="d-flex align-items-center gap-2">
                    <?php if($role === 'admin'): ?>
                        <a href="settings.php" class="btn btn-sm btn-primary fw-bold shadow-sm"><i class="bi bi-plus-lg"></i> Add New Item</a>
                    <?php endif; ?>
                    
                    <div class="btn-group shadow-sm">
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-dark bg-white"><i class="bi bi-printer"></i> Print</button>
                        <button type="button" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" style="border-bottom: 2px solid #dee2e6;">

        <?php foreach($funds as $f): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo ($fund_id == $f['id']) ? 'active fw-bold border-bottom-0' : 'text-muted'; ?>" 
               href="?<?php echo http_build_query(array_merge($_GET, ['fund_id' => $f['id']])); ?>">
               <?php echo htmlspecialchars(get_fund_acronym($f['name'])); ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="card shadow border-0 mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="matrixTable" class="table table-sm table-hover mb-0 align-middle table-bordered text-center data-font">
                    <thead class="table-dark align-middle text-white">
                        <tr>
                            <th rowspan="3" style="width: 5%;">Fund</th>
                            <th rowspan="3" style="width: 25%;" class="text-start ps-3">Item / OOE</th>
                            <th rowspan="3" style="width: 10%;">Adj. Approp.</th>
                            <th colspan="4" class="bg-secondary text-uppercase small" style="letter-spacing: 0.5px;">Allotment Release</th>
                            <th rowspan="3" style="width: 10%;">Reserve</th>
                            <th rowspan="3" style="width: 8%;" class="print-hide">Action</th>
                        </tr>
                        <tr class="table-secondary text-dark fw-bold small">
                            <th colspan="2" style="border-bottom: 1px solid #999;">1st Semester</th>
                            <th colspan="2" style="border-bottom: 1px solid #999;">2nd Semester</th>
                        </tr>
                        <tr class="table-light text-secondary small">
                            <th style="width: 8%;">Q1</th>
                            <th style="width: 8%;">Q2</th>
                            <th style="width: 8%;">Q3</th>
                            <th style="width: 8%;">Q4</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $current_ppa_tracker = '';
                        $current_ooe_tracker = '';

                        if (count($matrix) > 0): 
                            foreach ($matrix as $row): 
                                
                                // --- HIERARCHY GROUPING LOGIC ---
                                $fund_name = $row['fund_name'];
                                $ppa_name = $row['ppa_name'];
                                $tracker_key = $fund_name . '-' . $ppa_name;

                                // 1. Level 1 & 2: Fund + Program Header
                                if ($current_ppa_tracker != $tracker_key) {
                                    $current_ppa_tracker = $tracker_key;
                                    $current_ooe_tracker = ''; // Reset OOE tracker when PPA changes
                                    
                                    $badge = get_fund_acronym($fund_name);
                                    $bg_color = $ppa_name ? 'bg-primary' : 'bg-dark';
                                    $ppa_code_display = !empty($row['ppa_code']) ? " <code class='text-white-50 fw-normal' style='font-size: 0.75rem;'>" . htmlspecialchars($row['ppa_code']) . "</code>" : '';
                                    $display_name = $ppa_name ? "PROGRAM: " . htmlspecialchars($ppa_name) . $ppa_code_display : "STANDARD APPROPRIATION";
                                    
                                    echo "<tr class='{$bg_color} text-white border-bottom border-dark'>
                                            <td colspan='9' class='fw-bold text-uppercase ps-3 py-2 text-start' style='letter-spacing: 0.5px;'>
                                                <span class='badge bg-light text-dark me-2'>{$badge}</span> 
                                                {$display_name}
                                            </td>
                                          </tr>";
                                }

                                // 2. Level 3: OOE Class Header
                                if ($current_ooe_tracker != $row['ooe_name']) {
                                    $current_ooe_tracker = $row['ooe_name'];
                                    echo "<tr class='table-light border-bottom border-secondary'>
                                            <td colspan='9' class='fw-bold text-uppercase ps-4 py-2 text-start text-dark fst-italic' style='font-family: Inter;'>
                                                <i class='bi bi-arrow-return-right me-2 text-muted'></i> {$current_ooe_tracker}
                                            </td>
                                          </tr>";
                                }

                                // --- PREPARE DATA (REALIGNMENT MATH) ---
                                $base = $row['base_amount'];
                                $t_in = $row['transfer_in'];
                                $t_out = $row['transfer_out'];
                                
                                // The new total after realignments
                                $amt = $base + $t_in - $t_out;
                                
                                // Calculation logic based strictly on the ORIGINAL Base Appropriation
                                $val_sem1 = $base * ($row['pct_sem1'] / 100);
                                $val_sem2 = $base * ($row['pct_sem2'] / 100);
                                $val_full = $base * ($row['pct_full'] / 100);
                                $val_q1 = $base * ($row['pct_q1'] / 100); 
                                $val_q2 = $base * ($row['pct_q2'] / 100);
                                $val_q3 = $base * ($row['pct_q3'] / 100);
                                $val_q4 = $base * ($row['pct_q4'] / 100);
                                $res_amt = $base * ($row['pct_reserve'] / 100);

                                // Reserve Icon
                                $res_icon = '';
                                if ($row['pct_reserve'] > 0) {
                                    $res_icon = $row['reserve_released'] 
                                        ? '<i class="bi bi-unlock-fill text-success small ms-1" title="Released"></i>' 
                                        : '<i class="bi bi-lock-fill text-danger small ms-1" title="Withheld"></i>';
                                }

                                // Lock Icons
                                $lk_q2 = ($row['pct_q2']>0 && !$row['q2_released']) ? '<i class="bi bi-lock-fill text-danger small ms-1"></i>' : '';
                                $lk_q3 = ($row['pct_q3']>0 && !$row['q3_released']) ? '<i class="bi bi-lock-fill text-danger small ms-1"></i>' : '';
                                $lk_q4 = ($row['pct_q4']>0 && !$row['q4_released']) ? '<i class="bi bi-lock-fill text-danger small ms-1"></i>' : '';
                                $lk_s2 = ($row['pct_sem2']>0 && !$row['sem2_released']) ? '<i class="bi bi-lock-fill text-danger small ms-1"></i>' : '';
                            ?>
                                <tr>
                                    <td class="fw-bold"><span class="badge bg-secondary text-white border-white"><?php echo get_fund_acronym($row['fund_name']); ?></span></td>
                                    
                                    <td class="text-start ps-4" title="<?php echo htmlspecialchars($row['item_name']); ?>">
                                        <div class="fw-bold text-dark text-truncate-2"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                        
                                        <?php if(!empty($row['account_code'])): ?>
                                            <span class="badge bg-light text-secondary border font-monospace small">
                                                <?php echo htmlspecialchars($row['account_code']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-end fw-bold text-success num-cell">
                                        ₱ <?php echo number_format($amt, 2); ?>
                                        <?php if($t_in > 0 || $t_out > 0): ?>
                                            <div style="font-size: 0.65rem; line-height: 1;" class="text-muted fw-normal mt-1" title="Base Approp: ₱ <?php echo number_format($base, 2); ?> | Transfers In: +₱ <?php echo number_format($t_in, 2); ?> | Transfers Out: -₱ <?php echo number_format($t_out, 2); ?>">
                                                (Realigned)
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <?php if ($row['pct_full'] > 0): ?>
                                        <td colspan="4" class="text-center align-middle text-muted num-cell bg-info bg-opacity-10">
                                            <?php echo number_format($val_full, 2); ?>
                                        </td>

                                    <?php else: ?>
                                        
                                        <?php if ($row['pct_sem1'] > 0): ?>
                                            <td colspan="2" class="text-center align-middle text-muted num-cell">
                                                <?php echo number_format($val_sem1, 2); ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="text-end text-muted num-cell small"><?php echo ($val_q1 > 0) ? number_format($val_q1, 2) : '-'; ?></td>
                                            <td class="text-end text-muted num-cell small"><?php echo ($val_q2 > 0) ? number_format($val_q2, 2) . $lk_q2 : '-'; ?></td>
                                        <?php endif; ?>

                                        <?php if ($row['pct_sem2'] > 0): ?>
                                            <td colspan="2" class="text-center align-middle text-muted num-cell">
                                                <?php echo number_format($val_sem2, 2) . $lk_s2; ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="text-end text-muted num-cell small"><?php echo ($val_q3 > 0) ? number_format($val_q3, 2) . $lk_q3 : '-'; ?></td>
                                            <td class="text-end text-muted num-cell small"><?php echo ($val_q4 > 0) ? number_format($val_q4, 2) . $lk_q4 : '-'; ?></td>
                                        <?php endif; ?>

                                    <?php endif; ?>
                                    
                                    <td class="text-end fw-bold num-cell <?php echo $row['pct_reserve']>0 ? 'text-danger' : 'text-muted'; ?>">
                                        <?php echo ($row['pct_reserve'] > 0) ? number_format($res_amt, 2) . $res_icon : '-'; ?>
                                    </td>
                                    
                                    <td class="print-hide">
                                        <?php if ($role === 'admin'): ?>
                                            <div class="d-flex justify-content-center gap-1">
                                                <a href="edit_budget.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary px-2" title="Edit Allocation"><i class="bi bi-pencil-square"></i></a>
                                                <form method="POST" onsubmit="return confirm('Delete this item?');" style="margin:0;">
                                                    <input type="hidden" name="bucket_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="delete_bucket" class="btn btn-sm btn-outline-danger px-2" title="Delete"><i class="bi bi-x-lg"></i></button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-5 text-muted">No budget items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>