<?php
require 'includes/db_connect.php';
error_reporting(E_ALL);
// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_role  = strtolower($_SESSION['role']);
$can_edit   = in_array($user_role, ['admin', 'encoder']);
$can_delete = ($user_role === 'admin');

$page_title = 'LHB Programs Staging Area';
require 'includes/header.php';

$success_msg = "";
$error_msg   = "";

// ---------------------------------------------------------
// 2. HANDLE ACTIONS (ACTIVATE / DEACTIVATE PPA) - MOVED UP
// ---------------------------------------------------------
if ($can_edit && isset($_POST['deactivate_ppa'])) {
try {
$aop_ppa_id = $_POST['aop_ppa_id'];
$tracking_code = $_POST['tracking_code'];

 
    // Fetch ALL budget_ppas rows for this tracking code (handles historical duplicates)
    $deact_stmt = $pdo->prepare("SELECT id FROM budget_ppas WHERE ppa_code = ?");
    $deact_stmt->execute([$tracking_code]);
    $all_ppa_ids = $deact_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($all_ppa_ids)) throw new Exception("PPA not found in the Budget Matrix.");

    // Collect ALL bucket IDs across ALL duplicate PPA rows
    $ppa_in_clause = str_repeat('?,', count($all_ppa_ids) - 1) . '?';
    $bucket_stmt = $pdo->prepare("SELECT id FROM budget_buckets WHERE ppa_id IN ($ppa_in_clause)");
    $bucket_stmt->execute($all_ppa_ids);
    $all_bucket_ids = $bucket_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($all_bucket_ids) > 0) {
        $in_clause = str_repeat('?,', count($all_bucket_ids) - 1) . '?';

        $chk_stmt = $pdo->prepare("SELECT COUNT(*) FROM obligations WHERE budget_bucket_id IN ($in_clause)");
        $chk_stmt->execute($all_bucket_ids);
        if ($chk_stmt->fetchColumn() > 0) throw new Exception("Cannot deactivate: Some items in this PPA have active obligations.");

        $chk_stmt2 = $pdo->prepare("SELECT COUNT(*) FROM realignment_details WHERE budget_bucket_id IN ($in_clause)");
        $chk_stmt2->execute($all_bucket_ids);
        if ($chk_stmt2->fetchColumn() > 0) throw new Exception("Cannot deactivate: Some items in this PPA are linked to budget realignments.");
    }

    $pdo->beginTransaction();

    if (count($all_bucket_ids) > 0) {
        $in_clause = str_repeat('?,', count($all_bucket_ids) - 1) . '?';
        $pdo->prepare("DELETE FROM allotments WHERE budget_bucket_id IN ($in_clause)")->execute($all_bucket_ids);
        $pdo->prepare("DELETE FROM appropriations WHERE budget_bucket_id IN ($in_clause)")->execute($all_bucket_ids);
        $pdo->prepare("DELETE FROM budget_buckets WHERE ppa_id IN ($ppa_in_clause)")->execute($all_ppa_ids);
    }

    // Delete ALL duplicate budget_ppas rows for this tracking code
    $pdo->prepare("DELETE FROM budget_ppas WHERE ppa_code = ?")->execute([$tracking_code]);

    $user_id = $_SESSION['user_id'] ?? 0;
    $user_name = $_SESSION['username'] ?? 'System';
    log_activity($pdo, $user_id, $user_name, 'DEACTIVATE', "Deactivated PPA: $tracking_code and removed its budget items.");

    $pdo->commit();
    $success_msg = "Successfully deactivated PPA {$tracking_code}.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error_msg = "Error deactivating PPA: " . $e->getMessage();
}
 

}

if ($can_edit && isset($_POST['activate_ppa'])) {
try {
$aop_ppa_id = $_POST['aop_ppa_id'];

 
    $stmt = $pdo_aop->prepare("SELECT * FROM ppas WHERE id = ?");
    $stmt->execute([$aop_ppa_id]);
    $aop_ppa = $stmt->fetch();

    if (!$aop_ppa) throw new Exception("PPA not found in AOP Builder.");
    if (empty($aop_ppa['tracking_code'])) throw new Exception("PPA is missing a Tracking Code. Please generate one in the AOP Builder first.");

    $aop_to_budget_map = [ 'Special Health Fund' => 'Special Health Fund', 'Trust Fund-HCI' => 'Trust Fund', 'Trust Fund-Donation' => 'Trust Fund', 'Gender And Development Fund' => 'Gender And Development Fund', 'NTA-LCPC' => 'National Tax Allotment', 'DRRMF' => 'Disaster Risk Reduction and Management Fund', 'General Fund' => 'General Fund' ];
    $expected_name = $aop_to_budget_map[$aop_ppa['fund_source']] ?? $aop_ppa['fund_source'];

    $stmt = $pdo->prepare("SELECT id FROM fund_sources WHERE name = ?");
    $stmt->execute([$expected_name]);
    $fund_id = $stmt->fetchColumn();

    if (!$fund_id) throw new Exception("Unknown fund source: " . $aop_ppa['fund_source'] . ". Please ensure the fund source exists in the budget settings.");

    // Upsert PPA scoped by fiscal_year to prevent cross-year collisions
    // beginTransaction() is AFTER this SELECT so it runs outside any uncommitted TX
    $tracking_code = $aop_ppa['tracking_code'];
    $upsert_stmt = $pdo->prepare("SELECT id FROM budget_ppas WHERE ppa_code = ? AND fiscal_year = ?");
    $upsert_stmt->execute([$tracking_code, $aop_ppa['aop_year']]);
    $budget_ppa_id = $upsert_stmt->fetchColumn();

    $pdo->beginTransaction();

    if (!$budget_ppa_id) {
        $ins_ppa = $pdo->prepare("INSERT INTO budget_ppas (fiscal_year, fund_source_id, ppa_code, name, description) VALUES (?, ?, ?, ?, ?)");
        $ins_ppa->execute([$aop_ppa['aop_year'], $fund_id, $tracking_code, $aop_ppa['title'], $aop_ppa['description']]);
        $budget_ppa_id = $pdo->lastInsertId();
    } else {
        $upd_ppa = $pdo->prepare("UPDATE budget_ppas SET name = ?, description = ? WHERE id = ?");
        $upd_ppa->execute([$aop_ppa['title'], $aop_ppa['description'], $budget_ppa_id]);
    }

    $sql = "SELECT li.id, li.adjusted_total, bli.object_code, bli.description, bli.expense_type FROM line_items li JOIN budget_line_items bli ON li.budget_line_item_id = bli.id WHERE li.ppa_id = ?";
    $aop_items_stmt = $pdo_aop->prepare($sql);
    $aop_items_stmt->execute([$aop_ppa_id]);
    $aop_items = $aop_items_stmt->fetchAll();

    if (count($aop_items) === 0) throw new Exception("This program has no encoded budget line items in the AOP Builder.");

    // Fetch OOE map
    $ooe_map = [];
    $ooes = $pdo->query("SELECT id, name FROM ooes")->fetchAll();
    foreach ($ooes as $o) {
        if (strpos(strtoupper($o['name']), 'PERSONAL') !== false) $ooe_map['PS'] = $o['id'];
        elseif (strpos(strtoupper($o['name']), 'CAPITAL') !== false) $ooe_map['CO'] = $o['id'];
        elseif (strpos(strtoupper($o['name']), 'MAINTENANCE') !== false) $ooe_map['MOOE'] = $o['id'];
    }

    $activated_count = 0;

    foreach ($aop_items as $item) {
        $code = trim($item['object_code']);
        $desc = trim($item['description']);
        $type = strtoupper(trim($item['expense_type']));
        $ooe_id = $ooe_map[$type] ?? ($ooe_map['MOOE'] ?? 1);

        if ($code) {
            $li_stmt = $pdo->prepare("SELECT id FROM line_items WHERE account_code = ? AND name = ?");
            $li_stmt->execute([$code, $desc]);
        } else {
            $li_stmt = $pdo->prepare("SELECT id FROM line_items WHERE name = ? AND (account_code IS NULL OR account_code = '')");
            $li_stmt->execute([$desc]);
        }
        $line_item_id = $li_stmt->fetchColumn();

        if (!$line_item_id) {
            $ins_li = $pdo->prepare("INSERT INTO line_items (name, account_code, ooe_id) VALUES (?, ?, ?)");
            $ins_li->execute([$desc, $code ?: null, $ooe_id]);
            $line_item_id = $pdo->lastInsertId();
        }

        // Use distinct variable to avoid $stmt collision with INSERT above
        $check_bucket_stmt = $pdo->prepare("SELECT id FROM budget_buckets WHERE fiscal_year = ? AND fund_source_id = ? AND ppa_id = ? AND line_item_id = ?");
        $check_bucket_stmt->execute([$aop_ppa['aop_year'], $fund_id, $budget_ppa_id, $line_item_id]);
        if (!$check_bucket_stmt->fetchColumn()) {
            $p_full=100; $p_s1=0; $p_s2=0; $p_q1=0; $p_q2=0; $p_q3=0; $p_q4=0; $p_res=0;

            $ins_bucket = $pdo->prepare("INSERT INTO budget_buckets
                    (fiscal_year, fund_source_id, ppa_id, line_item_id, pct_full, pct_sem1, pct_sem2, pct_q1, pct_q2, pct_q3, pct_q4, pct_reserve)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins_bucket->execute([$aop_ppa['aop_year'], $fund_id, $budget_ppa_id, $line_item_id, $p_full, $p_s1, $p_s2, $p_q1, $p_q2, $p_q3, $p_q4, $p_res]);
            $bucket_id = $pdo->lastInsertId();

            $aop_cost = floatval($item['adjusted_total']);
            if ($aop_cost > 0) {
                $ins_app = $pdo->prepare("INSERT INTO appropriations (budget_bucket_id, amount) VALUES (?, ?)");
                $ins_app->execute([$bucket_id, $aop_cost]);
            }

            $activated_count++;
        }
    }

    $pdo->commit();
    if ($activated_count > 0) {
        $success_msg = "Successfully activated $activated_count line items for {$aop_ppa['tracking_code']}.";
    } else {
        $success_msg = "PPA {$aop_ppa['tracking_code']} is already active with its current items.";
    }

    $user_id = $_SESSION['user_id'] ?? 0;
    $user_name = $_SESSION['username'] ?? 'System';
    log_activity($pdo, $user_id, $user_name, 'ACTIVATE', "Activated PPA: {$aop_ppa['tracking_code']}. Added/verified $activated_count line items.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error_msg = "Error activating PPA: " . $e->getMessage();
}


}

// ---------------------------------------------------------
// 3. FETCH DATA & FILTERS (MOVED UP)
// ---------------------------------------------------------
$f_year = $_GET['year'] ?? date('Y');
$f_fund = $_GET['fund_source'] ?? 'all';

$funds = $pdo->query("SELECT * FROM fund_sources ORDER BY name ASC")->fetchAll();
$years = $pdo->query("SELECT year FROM fiscal_years ORDER BY year DESC")->fetchAll();

$where = ["p.aop_year = ?"];
$params = [$f_year];
$where[] = "p.status = 'Approved'"; // MUST BE APPROVED

if ($f_fund !== 'all') {
$stmt = $pdo->prepare("SELECT name FROM fund_sources WHERE id = ?");
$stmt->execute([$f_fund]);
$fs_name = $stmt->fetchColumn();


if ($fs_name) {
    $budget_to_aop_map = [
        'special health fund' => ['Special Health Fund'],
        'trust fund-hci' => ['Trust Fund-HCI', 'Trust Fund-Donation'],
        'trust fund' => ['Trust Fund-HCI', 'Trust Fund-Donation'],
        'gender and development fund' => ['Gender And Development Fund'],
        'nta-lcpc' => ['NTA-LCPC'],
        'national tax allotment' => ['NTA-LCPC'],
        'disaster risk reduction and management fund' => ['DRRMF'],
        'drrmf' => ['DRRMF']
    ];
    $clean_fs = strtolower(trim($fs_name));
    if (isset($budget_to_aop_map[$clean_fs])) {
        $mapped_funds = $budget_to_aop_map[$clean_fs];
        $placeholders = implode(',', array_fill(0, count($mapped_funds), '?'));
        $where[] = "p.fund_source IN ($placeholders)";
        $params = array_merge($params, $mapped_funds);
    }
}


} else {
$where[] = "p.fund_source != 'General Fund'";
}

// (Duplicate action blocks removed — handlers are above)

// ---------------------------------------------------------
// 3. FETCH & PROCESS DATA
// ---------------------------------------------------------
$where_sql = implode(" AND ", $where);

$sql = "SELECT p.id, p.tracking_code, p.title as name, p.description, p.fund_source as fund_name, p.status,
(SELECT SUM(li.adjusted_total) FROM line_items li WHERE li.ppa_id = p.id) as utilized_amount
FROM ppas p
WHERE $where_sql
ORDER BY p.fund_source ASC, p.title ASC";

$stmt = $pdo_aop->prepare($sql);
$stmt->execute($params);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($programs as &$p) {
if ($p['tracking_code']) {
// Scope by ppa_code AND fiscal_year to correctly reflect per-year activation status
$check_ppa_stmt = $pdo->prepare("SELECT id FROM budget_ppas WHERE ppa_code = ? AND fiscal_year = ?");
$check_ppa_stmt->execute([$p['tracking_code'], $f_year]);
$budget_ppa_id = $check_ppa_stmt->fetchColumn();


    if ($budget_ppa_id) {
        // Count buckets across all budget_ppas rows for this code+year
        $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM budget_buckets WHERE ppa_id IN (SELECT id FROM budget_ppas WHERE ppa_code = ? AND fiscal_year = ?)");
        $cnt_stmt->execute([$p['tracking_code'], $f_year]);
        $p['bucket_count'] = $cnt_stmt->fetchColumn();
        $p['is_active'] = true;
    } else {
        $p['bucket_count'] = 0;
        $p['is_active'] = false;
    }
} else {
    $p['bucket_count'] = 0;
    $p['is_active'] = false;
}


}
unset($p);

?>

<div class="container-fluid mt-4 px-4">
<div class="d-flex justify-content-between align-items-center mb-3">
<div class="d-flex align-items-center gap-2">
<h4 class="fw-bold text-dark mb-0">LHB Programs Staging Area</h4>
<span class="badge bg-success">AOP Builder Integration</span>
</div>
<a href="http://localhost/aop-builder" target="_blank" class="btn btn-outline-primary fw-bold shadow-sm">
<i class="bi bi-box-arrow-up-right me-1"></i> Open AOP Builder
</a>
</div>


<div class="alert alert-info small py-2 mb-4 border-info shadow-sm">
    <i class="bi bi-info-circle-fill me-1"></i> <strong>System Note:</strong> This page pulls <code>Approved</code> PPAs directly from the <strong>AOP Builder</strong> database for non-General Fund sources. Click <strong>Activate Budget</strong> to import their line items directly into the Budget Matrix. For edits or new PPAs, please use the AOP Builder.
</div>

<?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error_msg): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo $error_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card shadow border-0 mb-5">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0">AOP Approved Registry</h6>
        <form method="GET" class="d-flex gap-2">
            <input type="hidden" name="fund_source" value="<?php echo htmlspecialchars($f_fund); ?>">
            <select name="year" class="form-select form-select-sm fw-bold border-secondary" onchange="this.form.submit()">
                <?php foreach($years as $y): ?>
                    <option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $f_year ? 'selected' : ''; ?>><?php echo $y['year']; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card-body p-0">
        <ul class="nav nav-tabs bg-light pt-2 px-3" style="border-bottom: 2px solid #dee2e6;">
            <li class="nav-item">
                <a class="nav-link <?php echo ($f_fund == 'all') ? 'active fw-bold border-bottom-0' : 'text-muted'; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['fund_source' => 'all'])); ?>">
                   All Non-GF
                </a>
            </li>
            <?php foreach($funds as $f):
                if (strpos(strtolower($f['name']), 'general fund') !== false) continue;

                // Logic to get acronym for tabs
                $clean_fund = trim(strtolower($f['name']));
                $fund_acronym = '';
                $map = [
                    'special health fund' => 'SHF',
                    'trust fund-hci' => 'TF',
                    'trust fund' => 'TF',
                    'gender and development fund' => 'GAD',
                    'nta-lcpc' => 'NTA',
                    'national tax allotment' => 'NTA',
                    'disaster risk reduction and management fund' => 'DRRMF',
                    'disaster risk reduction management fund' => 'DRRMF',
                    'drrmf' => 'DRRMF'
                ];
                if (isset($map[$clean_fund])) {
                    $fund_acronym = $map[$clean_fund];
                } else {
                    $words = explode(' ', $f['name']);
                    foreach ($words as $word) {
                        if (strtolower($word) !== 'and' && preg_match('/[a-zA-Z]/', $word)) {
                            $fund_acronym .= strtoupper($word[0]);
                        }
                    }
                    if (!$fund_acronym) $fund_acronym = strtoupper(substr($f['name'], 0, 3));
                }
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo ($f_fund == $f['id']) ? 'active fw-bold border-bottom-0' : 'text-muted'; ?>"
                   href="?<?php echo http_build_query(array_merge($_GET, ['fund_source' => $f['id']])); ?>">
                   <?php echo htmlspecialchars($fund_acronym); ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-3" style="width: 6%;">Fund</th>
                        <th style="width: 14%;">PPA Code</th>
                        <th style="width: 30%;">Program / PPA Name</th>
                        <th style="width: 20%;">Description</th>
                        <th class="text-end" style="width: 15%;">AOP Budget</th>
                        <th class="text-center" style="width: 15%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($programs) > 0): foreach($programs as $p):

                        $clean_fund = trim(strtolower($p['fund_name']));
                        $fund_map = [
                            'general fund' => 'GF',
                            'special health fund' => 'SHF',
                            'trust fund-hci' => 'TF',
                            'trust fund-donation' => 'TF',
                            'trust fund' => 'TF',
                            'gender and development fund' => 'GAD',
                            'nta-lcpc' => 'NTA',
                            'national tax allotment' => 'NTA',
                            'disaster risk reduction and management fund' => 'DRRMF',
                            'disaster risk reduction management fund' => 'DRRMF',
                            'drrmf' => 'DRRMF'
                        ];

                        if (isset($fund_map[$clean_fund])) {
                            $fund_acronym = $fund_map[$clean_fund];
                        } else {
                            $fund_words = explode(' ', $p['fund_name']);
                            $fund_acronym = '';
                            foreach ($fund_words as $word) {
                                if (strtolower($word) !== 'and' && preg_match('/[a-zA-Z]/', $word)) {
                                    $fund_acronym .= strtoupper($word[0]);
                                }
                            }
                            if (!$fund_acronym) $fund_acronym = strtoupper(substr($p['fund_name'], 0, 3));
                        }

                        $saaob_total = floatval($p['utilized_amount']);
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold text-secondary" title="<?php echo htmlspecialchars($p['fund_name']); ?>" style="cursor: help;">
                            <?php echo htmlspecialchars($fund_acronym); ?>
                        </td>
                        <td>
                            <?php if(!empty($p['tracking_code'])): ?>
                                <code class="text-primary fw-bold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($p['tracking_code']); ?></code>
                            <?php else: ?>
                                <span class="text-danger fst-italic small"><i class="bi bi-exclamation-triangle"></i> Missing Code</span>
                            <?php endif; ?>
                        </td>
                        <td><div class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($p['name']); ?></div></td>
                        <td class="text-muted small fst-italic">
                            <?php echo htmlspecialchars($p['description'] ?: 'No description provided'); ?>
                        </td>
                        <td class="text-end">
                            <?php if($saaob_total > 0): ?>
                                <span class="fw-bold text-dark">₱ <?php echo number_format($saaob_total, 2); ?></span>
                                <?php if($p['is_active']): ?>
                                    <div class="text-muted small" style="font-size: 0.65rem;"><?php echo $p['bucket_count']; ?> active lines</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic small">No allocations</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if($p['is_active']): ?>
                                <span class="badge bg-success fw-bold mb-1 d-block"><i class="bi bi-check-circle me-1"></i> Activated</span>
                                <?php if($can_edit): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to completely deactivate this PPA? All its budget items will be removed from the Budget Matrix. This action cannot be undone.');">
                                        <input type="hidden" name="aop_ppa_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="tracking_code" value="<?php echo htmlspecialchars($p['tracking_code']); ?>">
                                        <button type="submit" name="deactivate_ppa" class="btn btn-sm btn-danger fw-bold py-0" style="font-size: 0.7rem;"><i class="bi bi-arrow-counterclockwise"></i> Deactivate</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif(!empty($p['tracking_code']) && $can_edit): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Activate this PPA and pull all line items into the Budget Matrix?');">
                                    <input type="hidden" name="aop_ppa_id" value="<?php echo $p['id']; ?>">
                                    <button type="submit" name="activate_ppa" class="btn btn-sm btn-primary fw-bold py-0"><i class="bi bi-cloud-download me-1"></i> Activate Budget</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small fst-italic">Requires Code</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No approved programs found in AOP Builder for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


</div>

<?php require 'includes/footer.php'; ?>