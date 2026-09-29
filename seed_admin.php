<?php
require_once __DIR__ . '/includes/db_connect.php';

$username = 'admin';
$password = 'admin123';
$fullname = 'System Administrator';
$title = 'IT Administrator';
$department = 'CHO-Finance Unit';
$role = 'admin';

try {
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update password and role to ensure admin access
        $update = $pdo->prepare("UPDATE users SET password = MD5(?), role = ?, fullname = ?, title = ?, department = ? WHERE id = ?");
        $update->execute([$password, $role, $fullname, $title, $department, $existing['id']]);
        $message = "Admin user '$username' already existed and has been updated successfully.";
    } else {
        // Insert new admin user
        $insert = $pdo->prepare("INSERT INTO users (username, fullname, title, department, password, role) VALUES (?, ?, ?, ?, MD5(?), ?)");
        $insert->execute([$username, $fullname, $title, $department, $password, $role]);
        $message = "Admin user '$username' seeded successfully!";
    }

    if (php_sapi_name() === 'cli') {
        echo $message . PHP_EOL;
        echo "Username: $username" . PHP_EOL;
        echo "Password: $password" . PHP_EOL;
        echo "Role: $role" . PHP_EOL;
    } else {
        echo "<h3>$message</h3>";
        echo "<ul>";
        echo "<li><strong>Username:</strong> " . htmlspecialchars($username) . "</li>";
        echo "<li><strong>Password:</strong> " . htmlspecialchars($password) . "</li>";
        echo "<li><strong>Role:</strong> " . htmlspecialchars($role) . "</li>";
        echo "</ul>";
        echo "<p><a href='login.php'>Go to Login</a></p>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
?>
