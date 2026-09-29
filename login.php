<?php
session_start();
require 'includes/db_connect.php';

$error = "";

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Check credentials (using MD5)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = MD5(?)");
    $stmt->execute([$username, $password]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - CHO Budget Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #e9ecef; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 450px; padding: 2rem; border-radius: 12px; }
        .brand-title { font-size: 1.5rem; color: teal; }
        .disclaimer-text { font-size: 0.8rem; font-style: italic; color: #6c757d; }
    </style>
</head>
<body>
    <div class="card shadow-md login-card border-0">
        <div class="text-center mb-4">
            <h3 class="fw-bold brand-title mb-2">CHO Budget Management System</h3>
            
            <div class="alert alert-light border small py-2 mb-0 mt-3 bg-light">
                <strong class="text-secondary text-uppercase" style="font-size: 0.7rem;">Internal Use Only</strong><br>
                <span class="disclaimer-text">Shadow System for the CHO-Finance Unit</span><br>
                <span class="disclaimer-text">v<?php echo APP_VERSION; ?></span>
            </div>
        </div>
        
        <?php if($error): ?><div class="alert alert-danger py-2 text-center"><?php echo $error; ?></div><?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold small text-secondary">Username</label>
                <input type="text" name="username" class="form-control form-control-md" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold small text-secondary">Password</label>
                <input type="password" name="password" class="form-control form-control-md" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100  ">Sign In</button>
        </form>
    </div>
</body>
</html>