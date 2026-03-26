<?php
/**
 * Aqua-Vision Database Setup
 * Location: database/setup.php
 */

require_once 'config.php';

echo "<h1>Aqua-Vision Database Setup</h1>";

// Read and execute the SQL file
$sqlFile = __DIR__ . '/setup.sql';
if (!file_exists($sqlFile)) {
    echo "<div class='alert alert-danger'>Error: setup.sql file not found!</div>";
    exit;
}

$sql = file_get_contents($sqlFile);

// Split SQL into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$errors = 0;

foreach ($statements as $statement) {
    if (empty($statement)) continue;
    
    if ($conn->query($statement)) {
        $success++;
    } else {
        $errors++;
        echo "<div class='alert alert-warning'>SQL Error: " . htmlspecialchars($conn->error) . "</div>";
        echo "<pre>" . htmlspecialchars($statement) . "</pre>";
    }
}

echo "<h2>Setup Results:</h2>";
echo "<div class='alert alert-success'>✅ $success statements executed successfully</div>";

if ($errors > 0) {
    echo "<div class='alert alert-danger'>❌ $errors statements failed</div>";
} else {
    echo "<div class='alert alert-info'>🎉 Database setup completed successfully!</div>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li><a href='../login.php'>Go to Login</a></li>";
    echo "<li>Username: <strong>admin</strong></li>";
    echo "<li>Password: <strong>admin123</strong></li>";
    echo "</ul>";
}

$conn->close();
?>

<style>
.alert { padding: 12px 16px; margin: 10px 0; border-radius: 8px; }
.alert-success { background: #dcfce7; color: #16a34a; border: 1px solid #16a34a33; }
.alert-danger { background: #fee2e2; color: #dc2626; border: 1px solid #dc262633; }
.alert-warning { background: #fef3c7; color: #d97706; border: 1px solid #d9770633; }
.alert-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #1d4ed833; }
pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow: auto; }
</style>
