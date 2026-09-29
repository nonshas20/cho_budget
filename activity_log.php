<?php
require 'includes/db_connect.php';

// 1. SECURITY
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php"); 
    exit;
}

$page_title = "Audit Trail";
require 'includes/header.php';

// 2. FILTER LOGIC
$f_search = $_GET['search'] ?? '';
$f_action = $_GET['action'] ?? 'all';
$f_date   = $_GET['date'] ?? '';

$where = ["1=1"];
$params = [];

if ($f_search) {
    $where[] = "(user_name LIKE ? OR description LIKE ?)";
    $params[] = "%$f_search%";
    $params[] = "%$f_search%";
}
if ($f_action != 'all') {
    $where[] = "action_type = ?";
    $params[] = $f_action;
}
if ($f_date) {
    $where[] = "DATE(created_at) = ?";
    $params[] = $f_date;
}

$where_sql = implode(" AND ", $where);

// 3. FETCH LOGS
$sql = "SELECT * FROM activity_logs WHERE $where_sql ORDER BY created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for dropdown
$actions = $pdo->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="container-fluid mt-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="fw-bold text-primary"><i class="bi bi-shield-lock-fill me-2"></i>System Audit Trail</h3>
        <button onclick="window.print()" class="btn btn-outline-dark btn-sm print-hide"><i class="bi bi-printer"></i> Print Log</button>
    </div>

    <div class="card shadow-sm bg-light border-0 mb-4 print-hide">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search User or Description..." value="<?php echo htmlspecialchars($f_search); ?>">
                </div>
                <div class="col-md-2">
                    <select name="action" class="form-select form-select-sm">
                        <option value="all">All Actions</option>
                        <?php foreach($actions as $act): ?>
                            <option value="<?php echo $act; ?>" <?php echo ($f_action == $act) ? 'selected' : ''; ?>><?php echo ucfirst($act); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?php echo $f_date; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-filter"></i> Filter</button>
                </div>
                <div class="col-md-2 text-end">
                    <a href="activity_log.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle small">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 15%;">Timestamp</th>
                            <th style="width: 15%;">User</th>
                            <th style="width: 10%;">Action</th>
                            <th style="width: 50%;">Description</th>
                            <th style="width: 10%;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($logs) > 0): ?>
                            <?php foreach($logs as $log): 
                                $badge_color = 'secondary';
                                if(stripos($log['action_type'], 'delete') !== false || stripos($log['action_type'], 'void') !== false) $badge_color = 'danger';
                                if(stripos($log['action_type'], 'update') !== false) $badge_color = 'warning text-dark';
                                if(stripos($log['action_type'], 'create') !== false) $badge_color = 'success';
                            ?>
                                <tr>
                                    <td class="text-muted"><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($log['user_name']); ?></td>
                                    <td><span class="badge bg-<?php echo $badge_color; ?>"><?php echo strtoupper($log['action_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                                    <td class="font-monospace text-muted"><?php echo $log['ip_address']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No activity records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Showing last 200 records.
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>