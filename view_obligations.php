<?php
require 'includes/db_connect.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$page_title = 'Obligation Log';
require 'includes/header.php';

// ---------------------------------------------------------
// 1. HANDLE GROUP ACTIONS (Cancel / Delete)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_id = $_POST['cancel_id'] ?? $_POST['delete_id'] ?? null;
    $trx_ref = null;

    if ($target_id) {
        $stmt_check = $pdo->prepare("SELECT transaction_ref FROM obligations WHERE id = ?");
        $stmt_check->execute([$target_id]);
        $trx_ref = $stmt_check->fetchColumn();
    }

    if (isset($_POST['cancel_id']) && $trx_ref) {
        $stmt = $pdo->prepare("UPDATE obligations SET status = 'cancelled' WHERE transaction_ref = ?");
        $stmt->execute([$trx_ref]);
        $success_msg = "Transaction (Ref: $trx_ref) has been cancelled.";
        log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'UPDATE', "Cancelled PPA Group $trx_ref");
    }

    if (isset($_POST['delete_id']) && $_SESSION['role'] === 'admin' && $trx_ref) {
        // Delete children first
        $stmt = $pdo->prepare("DELETE FROM charge_items WHERE obligation_id IN (SELECT id FROM obligations WHERE transaction_ref = ?)");
        $stmt->execute([$trx_ref]);
        // Delete header
        $stmt = $pdo->prepare("DELETE FROM obligations WHERE transaction_ref = ?");
        $stmt->execute([$trx_ref]);
        $success_msg = "Transaction (Ref: $trx_ref) has been permanently deleted.";
        log_activity($pdo, $_SESSION['user_id'], $_SESSION['username'], 'DELETE', "Deleted PPA Group $trx_ref");
    }
}

// ---------------------------------------------------------
// 2. FETCH & FILTER DATA
// ---------------------------------------------------------
$filter_year   = $_GET['year'] ?? date('Y');
$filter_fund   = $_GET['fund_id'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search_text   = $_GET['search'] ?? '';
$scan_ref      = $_GET['scan_ref'] ?? ''; 

// Capture Line Item ID from Dashboard Link
$filter_line_item = $_GET['line_item_id'] ?? null;

$where_clauses = ["YEAR(o.obligation_date) = ?"];
$params = [$filter_year];

if (!empty($scan_ref)) {
    $where_clauses = ["o.transaction_ref LIKE ?"];
    $params = ["%$scan_ref%"];
} else {
    // 1. FUND FILTER
    if ($filter_fund != 'all') { 
        $where_clauses[] = "bb.fund_source_id = ?"; 
        $params[] = $filter_fund; 
    }
    
    // 2. STATUS FILTER
    if ($filter_status != 'all') { 
        if ($filter_status == 'utilized') {
            $where_clauses[] = "o.status IN ('obligated', 'disbursing', 'liquidated')";
        } else {
            $where_clauses[] = "o.status = ?"; 
            $params[] = $filter_status; 
        }
    }

    // 3. LINE ITEM DEEP FILTER
    if ($filter_line_item) {
        $where_clauses[] = "EXISTS (SELECT 1 FROM charge_items ci_sub WHERE ci_sub.obligation_id = o.id AND ci_sub.line_item_id = ?)";
        $params[] = $filter_line_item;
    } elseif (isset($_GET['bucket_id'])) {
        $where_clauses[] = "o.budget_bucket_id = ?";
        $params[] = $_GET['bucket_id'];
    }

    // 4. TEXT SEARCH
    if (!empty($search_text)) {
        $where_clauses[] = "(o.ppa_name LIKE ? OR o.particulars LIKE ? OR li.name LIKE ? OR o.program_unit LIKE ? OR o.bonded_officer LIKE ?)";
        $p = "%$search_text%";
        array_push($params, $p, $p, $p, $p, $p);
    }
}

$where_sql = implode(" AND ", $where_clauses);

// Main Query with v2.2.0 Subquery Enhancement (Fixed column name to 'status')
$sql = "SELECT 
            o.id as obligation_id, o.transaction_ref, o.ppa_name, o.particulars, o.obligation_date, 
            o.charging_period, o.program_unit, o.bonded_officer, o.status,
            ci.amount as planned_amount, ci.disbursed_amount, ci.dv_number,
            li.name as item_name, li.id as item_id,
            ooe.name as ooe_name, fs.name as fund_name,
            
            -- Fetch the most recent diary entry for this specific PPA
            (SELECT status FROM routing_history rh WHERE rh.obligation_id = o.id ORDER BY rh.created_at DESC LIMIT 1) as latest_diary
            
        FROM obligations o
        LEFT JOIN budget_buckets bb ON o.budget_bucket_id = bb.id
        LEFT JOIN fund_sources fs ON bb.fund_source_id = fs.id
        LEFT JOIN charge_items ci ON o.id = ci.obligation_id
        LEFT JOIN line_items li ON ci.line_item_id = li.id
        LEFT JOIN ooes ooe ON li.ooe_id = ooe.id
        WHERE $where_sql
        ORDER BY o.obligation_date DESC, o.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// GROUP DATA
$groups = [];
foreach ($raw_rows as $row) {
    $key = !empty($row['transaction_ref']) ? $row['transaction_ref'] : 'legacy_'.$row['obligation_id'];
    
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'ref' => $row['transaction_ref'],
            'main_id' => $row['obligation_id'],
            'ppa_name' => $row['ppa_name'],
            'particulars' => $row['particulars'],
            'date' => $row['obligation_date'], 
            'period' => $row['charging_period'],
            'fund' => $row['fund_name'] ?? 'Unknown Fund',
            'unit' => $row['program_unit'],
            'officer' => $row['bonded_officer'],
            'status' => $row['status'],
            'latest_diary' => $row['latest_diary'], 
            'total_planned' => 0,
            'total_disbursed' => 0,
            'items' => []
        ];
    }
    
    if ($row['item_name']) {
        $groups[$key]['items'][] = $row;
        if($row['status'] !== 'cancelled') {
            // If filtered by a specific line item, only add that item's amount to the header totals
            if (!$filter_line_item || $row['item_id'] == $filter_line_item) {
                $groups[$key]['total_planned'] += $row['planned_amount'];
                $groups[$key]['total_disbursed'] += $row['disbursed_amount'];
            }
        }
    } elseif ($row['planned_amount'] > 0 && $row['status'] !== 'cancelled') {
        if (!$filter_line_item || $row['item_id'] == $filter_line_item) {
            $groups[$key]['total_planned'] += $row['planned_amount'];
        }
    }
}

$yrs = $pdo->query("SELECT year FROM fiscal_years ORDER BY year DESC")->fetchAll();
$funds = $pdo->query("SELECT * FROM fund_sources")->fetchAll();

function getPeriodBadge($code) {
    $map = ['full'=>'<span class="badge bg-primary">Full</span>', 'sem1'=>'<span class="badge bg-primary">1st Sem</span>', 'sem2'=>'<span class="badge bg-primary">2nd Sem</span>', 'q1'=>'<span class="badge bg-info text-dark">Q1</span>', 'q2'=>'<span class="badge bg-info text-dark">Q2</span>', 'q3'=>'<span class="badge bg-info text-dark">Q3</span>', 'q4'=>'<span class="badge bg-info text-dark">Q4</span>', 'reserve'=>'<span class="badge bg-warning text-dark">Res</span>'];
    return $map[$code] ?? '<span class="badge bg-secondary">?</span>';
}

function getStatusBadge($status) {
    return match($status) {
        'pending'    => '<span class="badge bg-secondary">Pending</span>',
        'obligated'  => '<span class="badge bg-primary">Obligated</span>',
        'disbursing' => '<span class="badge bg-warning text-dark">Disbursing</span>',
        'liquidated' => '<span class="badge bg-success">Liquidated</span>',
        'cancelled'  => '<span class="badge bg-danger">Cancelled</span>',
        default      => '<span class="badge bg-light text-dark border">Unknown</span>'
    };
}
?>

<style>
    .text-truncate-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        white-space: normal;
        line-height: 1.3;
    }
    
    @media print {
        /* Hide everything not needed in the printed report */
        .no-print, .navbar, .sidebar, form, .modal, .print-hide-col { 
            display: none !important; 
        }
        /* Make table fit page */
        body { background-color: #fff !important; padding: 0 !important; font-size: 10pt; }
        .container-fluid { padding: 0 !important; width: 100% !important; max-width: 100% !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table-responsive { overflow: visible !important; }
        .table th, .table td { border: 1px solid #000 !important; padding: 5px !important; }
        .table-dark { background-color: #f8f9fa !important; color: #000 !important; -webkit-print-color-adjust: exact; }
        .badge { border: 1px solid #000; color: #000 !important; background-color: transparent !important; }
        /* Add a header specifically for printing */
        .print-header { display: block !important; margin-bottom: 20px; text-align: center; }
        .print-header h3 { margin: 0; font-weight: bold; }
        .print-header p { margin: 0; color: #555; }
    }
    
    .print-header { display: none; } /* Hidden on screen */
</style>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<div class="container-fluid mt-4 px-4">

    <?php if (isset($success_msg)): ?><div class="alert alert-success alert-dismissible fade show no-print"><i class="bi bi-check-circle-fill me-2"></i><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0">Master Obligation Log</h4>
            <span class="badge bg-primary">v2.2.0</span>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark fw-bold shadow-sm" title="Print Log to PDF or Printer">
                <i class="bi bi-printer-fill me-1"></i> Print / PDF
            </button>
            <a href="create_obligation.php" class="btn btn-success fw-bold shadow-sm"><i class="bi bi-plus-circle me-1"></i> New Obligation</a>
        </div>
    </div>

    <div class="print-header">
        <h3>Master Obligation Log</h3>
        <p>Filtered by: Year <?php echo $filter_year; ?> | Status: <?php echo ucfirst($filter_status); ?></p>
    </div>

    <div class="card shadow-sm mb-4 bg-light border-0 no-print">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center" id="searchForm">
                <?php if($filter_line_item): ?><input type="hidden" name="line_item_id" value="<?php echo $filter_line_item; ?>"><?php endif; ?>

                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <button class="btn btn-dark" type="button" data-bs-toggle="modal" data-bs-target="#scannerModal" title="Open Camera">
                            <i class="bi bi-camera-fill"></i>
                        </button>
                        <input type="text" name="scan_ref" id="scan_input" class="form-control fw-bold font-monospace text-uppercase" 
                               placeholder="REF / ID" value="<?php echo htmlspecialchars($scan_ref); ?>">
                        <?php if(!empty($scan_ref)): ?>
                            <a href="view_obligations.php" class="btn btn-outline-secondary border-start-0" title="Clear Scan"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-1">
                    <select name="year" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                        <?php foreach($yrs as $y): ?><option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $filter_year ? 'selected' : ''; ?>><?php echo $y['year']; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="fund_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">All Funds</option>
                        <?php foreach($funds as $f): ?><option value="<?php echo $f['id']; ?>" <?php echo $f['id'] == $filter_fund ? 'selected' : ''; ?>><?php echo $f['name']; ?></option><?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="utilized" <?php echo $filter_status=='utilized'?'selected':''; ?>>Utilized (All Active)</option>
                        <option disabled>──────────</option>
                        <option value="pending" <?php echo $filter_status=='pending'?'selected':''; ?>>Pending</option>
                        <option value="obligated" <?php echo $filter_status=='obligated'?'selected':''; ?>>Obligated</option>
                        <option value="disbursing" <?php echo $filter_status=='disbursing'?'selected':''; ?>>Disbursing</option>
                        <option value="liquidated" <?php echo $filter_status=='liquidated'?'selected':''; ?>>Liquidated</option>
                        <option value="cancelled" <?php echo $filter_status=='cancelled'?'selected':''; ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="Search Title, Particulars..." value="<?php echo htmlspecialchars($search_text); ?>">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                    </div>
                </div>
                
                <div class="col-md-1">
                    <a href="view_obligations.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-dark text-center">
                        <tr>
                            <th class="print-hide-col" style="width: 3%"></th> 
                            <th style="width: 8%">Ref No.</th> 
                            <th style="width: 25%" class="text-start">PPA Name / Particulars</th>
                            <th style="width: 6%">Target Date</th>
                            <th style="width: 5%">Period</th>
                            <th style="width: 12%" class="text-start">Unit / Officer</th>
                            <th style="width: 12%">Amount</th> <th style="width: 17%" class="text-start">Latest Diary Log</th> 
                            <th style="width: 6%">Status</th>
                            <th class="print-hide-col" style="width: 6%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($groups) > 0): ?>
                            <?php foreach($groups as $grp_key => $grp): 
                                $show_actual = in_array($grp['status'], ['disbursing', 'liquidated']);
                                $display_amount = $show_actual ? $grp['total_disbursed'] : $grp['total_planned'];
                                $amount_color = $show_actual ? 'text-success' : 'text-dark';
                                $amount_icon = $show_actual ? '<i class="bi bi-check2-all me-1"></i>' : '';
                                
                                $is_expanded = $filter_line_item ? 'show' : '';
                                $btn_collapsed = $filter_line_item ? '' : 'collapsed';
                            ?>
                                <tr class="bg-white border-bottom">
                                    <td class="text-center print-hide-col">
                                        <button class="btn btn-sm btn-light border rounded-circle text-primary <?php echo $btn_collapsed; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?php echo $grp_key; ?>"><i class="bi bi-chevron-down"></i></button>
                                    </td>
                                    <td class="text-center font-monospace fw-bold text-primary"><?php echo htmlspecialchars($grp['ref']); ?></td>
                                    <td>
                                        <div class="text-uppercase fw-bold text-dark text-truncate-2" title="<?php echo htmlspecialchars($grp['ppa_name']); ?>">
                                            <?php echo htmlspecialchars($grp['ppa_name']); ?>
                                        </div>
                                        <div class="small text-muted fst-italic text-truncate-2 mt-1">
                                            <?php echo htmlspecialchars($grp['particulars']); ?>
                                        </div>
                                    </td>
                                    <td class="text-center text-muted"><?php echo date('M d, Y', strtotime($grp['date'])); ?></td>
                                    <td class="text-center"><?php echo getPeriodBadge($grp['period']); ?></td>
                                    
                                    <td class="text-muted small">
                                        <?php if($grp['unit']): ?><div class="text-truncate" title="<?php echo htmlspecialchars($grp['unit']); ?>"><i class="bi bi-building"></i> <?php echo htmlspecialchars($grp['unit']); ?></div><?php endif; ?>
                                        <?php if($grp['officer']): ?><div class="text-truncate mt-1" title="<?php echo htmlspecialchars($grp['officer']); ?>"><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($grp['officer']); ?></div><?php endif; ?>
                                    </td>
                                    
                                    <td class="text-end fw-bold h6 mb-0 <?php echo $amount_color; ?>">
                                        <?php echo $amount_icon; ?>₱ <?php echo number_format($display_amount, 2); ?>
                                    </td>
                                    
                                    <td class="text-muted small">
                                        <div class="text-truncate-2" title="<?php echo htmlspecialchars($grp['latest_diary'] ?? 'No entry'); ?>">
                                            <?php if($grp['latest_diary']): ?>
                                                <i class="bi bi-journal-text me-1 text-primary"></i> <?php echo htmlspecialchars($grp['latest_diary']); ?>
                                            <?php else: ?>
                                                <span class="fst-italic opacity-50">- No logs yet -</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="text-center"><?php echo getStatusBadge($grp['status']); ?></td>
                                    <td class="text-center print-hide-col">
                                        <div class="d-flex justify-content-center gap-1">
                                            <?php if ($_SESSION['role'] !== 'viewer'): ?>
                                                <a href="edit_obligation.php?id=<?php echo $grp['main_id']; ?>" class="btn btn-sm btn-outline-primary border-0" title="Edit / Process"><i class="bi bi-pencil-square"></i></a>
                                            <?php else: ?>
                                                <a href="edit_obligation.php?id=<?php echo $grp['main_id']; ?>" class="btn btn-sm btn-outline-secondary border-0" title="View"><i class="bi bi-eye"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="no-print">
                                    <td colspan="10" class="p-0 border-0">
                                        <div class="collapse bg-light border-bottom <?php echo $is_expanded; ?>" id="collapse_<?php echo $grp_key; ?>">
                                            <div class="p-3 ps-5">
                                                <h6 class="text-uppercase small fw-bold text-muted mb-2">Breakdown of Charges:</h6>
                                                <table class="table table-sm table-bordered bg-white w-75" style="font-size: 0.85rem;">
                                                    <thead class="table-secondary text-center">
                                                        <tr>
                                                            <th>Fund Source</th>
                                                            <th>Line Item / Expense Class</th>
                                                            <th>Planned Amount</th>
                                                            <th>Disbursed Amount</th>
                                                            <th>DV Number</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($grp['items'] as $item): 
                                                            $hl_class = ($filter_line_item == $item['item_id']) ? 'table-warning fw-bold' : '';
                                                        ?>
                                                        <tr class="<?php echo $hl_class; ?>">
                                                            <td class="text-center text-muted"><?php echo $item['fund_name']; ?></td>
                                                            <td>
                                                                <div class="text-dark"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                                <div class="small text-muted"><?php echo $item['ooe_name']; ?></div>
                                                            </td>
                                                            <td class="text-end text-muted">₱ <?php echo number_format($item['planned_amount'], 2); ?></td>
                                                            <td class="text-end fw-bold text-success">
                                                                <?php if($item['disbursed_amount'] > 0): ?>
                                                                    ₱ <?php echo number_format($item['disbursed_amount'], 2); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center font-monospace small">
                                                                <?php echo $item['dv_number'] ? $item['dv_number'] : '<span class="text-muted">-</span>'; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="text-center py-5 text-muted">No transactions found matching your criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade no-print" id="scannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Scan Barcode</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 bg-black">
                <div id="reader" style="width: 100%;"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <small class="text-muted">Point camera at the PPA Tracking Slip Barcode</small>
            </div>
        </div>
    </div>
</div>

<script>
    let html5QrcodeScanner;

    function onScanSuccess(decodedText, decodedResult) {
        html5QrcodeScanner.clear();
        document.getElementById('scan_input').value = decodedText;
        var myModalEl = document.getElementById('scannerModal');
        var modal = bootstrap.Modal.getInstance(myModalEl);
        modal.hide();
        document.getElementById('searchForm').submit();
    }

    function onScanFailure(error) {}

    const scannerModal = document.getElementById('scannerModal');
    scannerModal.addEventListener('shown.bs.modal', function () {
        html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", { fps: 10, qrbox: {width: 250, height: 150} }, false
        );
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    });

    scannerModal.addEventListener('hidden.bs.modal', function () {
        if(html5QrcodeScanner) {
            html5QrcodeScanner.clear().catch(error => { console.error("Failed to clear scanner. ", error); });
        }
    });

    document.addEventListener('keydown', function(event) { 
        if (event.altKey && event.key === 'n') { 
            event.preventDefault(); 
            window.location.href = 'create_obligation.php'; 
        } 
    });
</script>

<?php require 'includes/footer.php'; ?>