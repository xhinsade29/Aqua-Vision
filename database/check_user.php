<?php
require_once 'config.php';

echo "<h1>Check Admin User</h1>";

// Check if admin user exists
$stmt = $conn->prepare("SELECT username, email, full_name, role, is_active FROM users WHERE username = 'admin'");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<div class='alert alert-danger'>❌ Admin user NOT found in database</div>";
    
    // Create admin user manually
    $password = 'admin123';
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("
        INSERT INTO users (username, email, password_hash, full_name, role, is_active) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssi", 'admin', 'admin@aqua-vision.com', $password_hash, 'System Administrator', 'admin', 1);
    
    if ($stmt->execute()) {
        echo "<div class='alert alert-success'>✅ Admin user created successfully!</div>";
        echo "<p><strong>Login credentials:</strong></p>";
        echo "<ul>";
        echo "<li>Username: <strong>admin</strong></li>";
        echo "<li>Password: <strong>admin123</strong></li>";
        echo "</ul>";
        echo "<p><a href='../login.php'>Go to Login</a></p>";
    } else {
        echo "<div class='alert alert-danger'>❌ Failed to create admin user: " . htmlspecialchars($stmt->error) . "</div>";
    }
    $stmt->close();
} else {
    $user = $result->fetch_assoc();
    echo "<div class='alert alert-info'>ℹ️ Admin user found:</div>";
    echo "<ul>";
    echo "<li>Username: " . htmlspecialchars($user['username']) . "</li>";
    echo "<li>Email: " . htmlspecialchars($user['email']) . "</li>";
    echo "<li>Name: " . htmlspecialchars($user['full_name']) . "</li>";
    echo "<li>Role: " . htmlspecialchars($user['role']) . "</li>";
    echo "<li>Active: " . ($user['is_active'] ? 'Yes' : 'No') . "</li>";
    echo "</ul>";
    
    if (!$user['is_active']) {
        echo "<div class='alert alert-warning'>⚠️ User is inactive. Activating...</div>";
        $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE username = 'admin'");
        if ($stmt->execute()) {
            echo "<div class='alert alert-success'>✅ User activated!</div>";
        }
        $stmt->close();
    }
    
    // Test password verification
    $password = 'admin123';
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE username = 'admin'");
    $stmt->execute();
    $result = $stmt->get_result();
    $hash = $result->fetch_assoc()['password_hash'];
    
    if (password_verify($password, $hash)) {
        echo "<div class='alert alert-success'>✅ Password verification works!</div>";
    } else {
        echo "<div class='alert alert-danger'>❌ Password verification failed. Resetting password...</div>";
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
        $stmt->bind_param("s", $new_hash);
        if ($stmt->execute()) {
            echo "<div class='alert alert-success'>✅ Password reset to 'admin123'</div>";
        }
        $stmt->close();
    }
    
    echo "<p><a href='../login.php'>Try Login Again</a></p>";
}

$stmt->close();
$conn->close();
?>

<style>
.alert { padding: 12px 16px; margin: 10px 0; border-radius: 8px; }
.alert-success { background: #dcfce7; color: #16a34a; border: 1px solid #16a34a33; }
.alert-danger { background: #fee2e2; color: #dc2626; border: 1px solid #dc262633; }
.alert-warning { background: #fef3c7; color: #d97706; border: 1px solid #d9770633; }
.alert-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #1d4ed833; }
</style>
