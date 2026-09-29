<?php
require 'includes/db_connect.php';

// SECURITY: Only Admin can access this page
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$page_title = 'User Management';
require 'includes/header.php';

$success_msg = "";
$error_msg = "";

// 1. ADD USER LOGIC
if (isset($_POST['add_user'])) {
    $u = trim($_POST['username']);
    $p = $_POST['password'];
    $r = $_POST['role'];
    // [NEW] Capture new fields
    $fn = trim($_POST['fullname']);
    $ti = trim($_POST['title']);
    $dp = trim($_POST['department']);

    // Check if username exists
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $check->execute([$u]);
    
    if ($check->fetchColumn() > 0) {
        $error_msg = "Username '$u' already exists.";
    } else {
        // [UPDATED] Insert with new columns
        $sql = "INSERT INTO users (username, fullname, title, department, password, role) VALUES (?, ?, ?, ?, MD5(?), ?)";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$u, $fn, $ti, $dp, $p, $r])) {
            $success_msg = "User '$u' added successfully!";
        } else {
            $error_msg = "Error adding user.";
        }
    }
}

// 2. DELETE USER LOGIC
if (isset($_POST['delete_user'])) {
    $del_id = $_POST['user_id'];
    if ($del_id == $_SESSION['user_id']) {
        $error_msg = "You cannot delete your own account.";
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$del_id]);
        $success_msg = "User deleted successfully.";
    }
}

// 3. FETCH ALL USERS
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<div class="container-fluid mt-4 px-4">
    
    <?php if($success_msg): ?><div class="alert alert-success"><?php echo $success_msg; ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-primary h-100">
                <div class="card-header bg-primary text-white fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Add New User</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Full Name</label>
                            <input type="text" name="fullname" class="form-control" required placeholder="e.g. Juan Dela Cruz">
                        </div>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Job Title</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Nurse II">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Department</label>
                                <input type="text" name="department" class="form-control" placeholder="e.g. HEPO">
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Username</label>
                            <input type="text" name="username" class="form-control" required placeholder="Login ID">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Password</label>
                            <input type="password" name="password" class="form-control" required placeholder="******">
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted">Role</label>
                            <select name="role" class="form-select">
                                <option value="viewer">Viewer (Read Only)</option>
                                <option value="encoder">Encoder (Edit Access)</option>
                                <option value="admin">Admin (Full Control)</option>
                            </select>
                        </div>
                        <button type="submit" name="add_user" class="btn btn-primary w-100 fw-bold">Create Account</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light fw-bold"><i class="bi bi-people-fill me-2"></i>Existing Users</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark small text-uppercase">
                                <tr>
                                    <th>Profile</th>
                                    <th>Title / Dept</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-primary"><?php echo htmlspecialchars($row['fullname']); ?></div>
                                            <div class="small text-muted text-monospace">@<?php echo htmlspecialchars($row['username']); ?></div>
                                        </td>
                                        <td>
                                            <div class="small fw-bold"><?php echo htmlspecialchars($row['title']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($row['department']); ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                            $badge = match($row['role']) {
                                                'admin' => 'bg-danger',
                                                'encoder' => 'bg-success',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?php echo $badge; ?>"><?php echo strtoupper($row['role']); ?></span>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td class="text-center">
                                            <?php if($row['id'] != $_SESSION['user_id']): ?>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="edit_user.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                                    <form method="POST" onsubmit="return confirm('Delete user <?php echo $row['username']; ?>?');" class="d-inline">
                                                        <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Current</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require 'includes/footer.php'; ?>