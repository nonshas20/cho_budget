<?php
require 'includes/db_connect.php';

// SECURITY: Admin Only
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Access Denied"); }

$id = $_GET['id'] ?? null;
if (!$id) die("Invalid User ID");

$success_msg = "";
$error_msg = "";

// 1. SAVE CHANGES
if (isset($_POST['save_changes'])) {
    $u = trim($_POST['username']);
    $r = $_POST['role'];
    // [NEW] Capture fields
    $fn = trim($_POST['fullname']);
    $ti = trim($_POST['title']);
    $dp = trim($_POST['department']);
    $p = $_POST['password'];

    try {
        if (!empty($p)) {
            // Update WITH Password
            $stmt = $pdo->prepare("UPDATE users SET username=?, fullname=?, title=?, department=?, role=?, password=MD5(?) WHERE id=?");
            $stmt->execute([$u, $fn, $ti, $dp, $r, $p, $id]);
        } else {
            // Update WITHOUT Password
            $stmt = $pdo->prepare("UPDATE users SET username=?, fullname=?, title=?, department=?, role=? WHERE id=?");
            $stmt->execute([$u, $fn, $ti, $dp, $r, $id]);
        }
        $success_msg = "User updated successfully!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// 2. FETCH USER DATA
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) die("User not found.");

$page_title = 'Edit User';
require 'includes/header.php';
?>

<div class="container mt-5" style="max-width: 600px;">
    
    <?php if($success_msg): ?>
        <div class="alert alert-success">
            <?php echo $success_msg; ?> 
            <a href="manage_users.php" class="alert-link fw-bold">Return to User List</a>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white fw-bold">
            <i class="bi bi-pencil-square me-2"></i>Edit User: <?php echo htmlspecialchars($user['username']); ?>
        </div>
        <div class="card-body">
            <form method="POST">
                
                <h6 class="text-muted text-uppercase small fw-bold mb-3">Personal Details</h6>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Full Name</label>
                    <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Job Title</label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($user['title']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Department</label>
                        <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($user['department']); ?>">
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small fw-bold mb-3 border-top pt-3">Account Settings</h6>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Username</label>
                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Role</label>
                    <select name="role" class="form-select">
                        <option value="viewer" <?php echo ($user['role'] == 'viewer') ? 'selected' : ''; ?>>Viewer</option>
                        <option value="encoder" <?php echo ($user['role'] == 'encoder') ? 'selected' : ''; ?>>Encoder</option>
                        <option value="admin" <?php echo ($user['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-danger">Reset Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                </div>

                <div class="d-flex justify-content-between">
                    <a href="manage_users.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" name="save_changes" class="btn btn-success px-4 fw-bold">Save Changes</button>
                </div>

            </form>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>