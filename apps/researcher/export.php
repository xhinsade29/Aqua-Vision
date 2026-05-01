<?php
/**
 * Aqua-Vision — Researcher Data Export
 * Export sensor data for external analysis
 * Location: apps/researcher/export.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Export Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// Check authentication and researcher role
if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
    exit;
}

$allowedRoles = ['researcher', 'admin'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    exit;
}

// ── Handle Export Actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $format = $_POST['format'] ?? 'csv';
    $sensorType = $_POST['sensor_type'] ?? null;
    $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_POST['end_date'] ?? date('Y-m-d');
    $deviceId = $_POST['device_id'] ?? null;
    
    // Build query
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at, 
                   s.sensor_type, s.unit,
                   d.device_name, l.location_name, l.river_section
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE DATE(sr.recorded_at) BETWEEN ? AND ?";
    
    $params = [$startDate, $endDate];
    $types = "ss";
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= "s";
    }
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= "i";
    }
    
    $sql .= " ORDER BY sr.recorded_at DESC LIMIT 10000";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aqua_vision_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Reading ID', 'Timestamp', 'Sensor Type', 'Value', 'Unit', 'Device', 'Location', 'River Section']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['reading_id'],
                $row['recorded_at'],
                $row['sensor_type'],
                $row['value'],
                $row['unit'],
                $row['device_name'],
                $row['location_name'],
                $row['river_section']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="aqua_vision_export_' . date('Y-m-d') . '.json"');
        echo json_encode(['data' => $data, 'exported_at' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT);
        exit;
    }
    
    $_SESSION['success'] = 'Export completed successfully! ' . count($data) . ' records exported.';
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'export';

// Get available sensor types
$sensorTypesResult = $conn->query("SELECT DISTINCT sensor_type FROM sensors WHERE sensor_type NOT IN ('humidity', 'pressure', 'flow_rate') ORDER BY sensor_type");
$sensorTypes = [];
while ($row = $sensorTypesResult->fetch_assoc()) {
    $sensorTypes[] = $row['sensor_type'];
}
if (empty($sensorTypes)) {
    $sensorTypes = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
}

$devices = $conn->query("SELECT device_id, device_name FROM devices WHERE status = 'active' ORDER BY device_name")->fetch_all(MYSQLI_ASSOC);

// Get data count for preview
$dataCount = $conn->query("SELECT COUNT(*) as cnt FROM sensor_readings WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Data — Aqua-Vision Researcher</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --c1: #0F2854; --c2: #1C4D8D; --c3: #4988C4; --c4: #BDE8F5;
            --bg: #f0f5fb; --surface: #fff; --border: rgba(15,40,84,.08);
            --text: #0F2854; --text2: #4a6080; --text3: #8aa0bc;
            --good: #16a34a; --good-bg: #dcfce7;
            --warn: #d97706; --warn-bg: #fef3c7;
            --radius: 14px; --radius-sm: 8px;
            --sidebar-w: 240px;
        }
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: var(--bg); 
            min-height: 100vh;
            margin-left: var(--sidebar-w);
        }
        
        .main-content { padding: 24px; max-width: 1000px; margin: 0 auto; }
        
        .page-header {
            text-align: center;
            margin-bottom: 32px;
            padding: 40px 0;
        }
        .page-title { 
            font-family: 'Space Grotesk', sans-serif; 
            font-size: 32px; font-weight: 700; color: var(--c1); 
        }
        .page-subtitle { 
            font-size: 16px; color: var(--text2); margin-top: 8px; 
        }
        
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px; border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--c1), var(--c2));
        }
        .card-title { font-size: 18px; font-weight: 600; color: white; }
        .card-body { padding: 24px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--text); margin-bottom: 8px;
        }
        .form-select, .form-input {
            width: 100%; padding: 12px 16px;
            border: 2px solid var(--border); border-radius: var(--radius-sm);
            font-size: 14px; background: var(--surface); color: var(--text);
        }
        .form-select:focus, .form-input:focus {
            outline: none; border-color: var(--c3);
        }
        
        .form-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }
        
        .format-options {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        .format-option {
            padding: 16px; border: 2px solid var(--border);
            border-radius: var(--radius-sm); cursor: pointer;
            transition: all 0.2s; text-align: center;
        }
        .format-option:hover { border-color: var(--c3); }
        .format-option input { display: none; }
        .format-option input:checked + .format-content {
            border-color: var(--c2); background: rgba(28, 77, 141, 0.05);
        }
        .format-icon { font-size: 28px; margin-bottom: 8px; }
        .format-name { font-size: 14px; font-weight: 600; color: var(--c1); }
        .format-desc { font-size: 12px; color: var(--text3); margin-top: 4px; }
        
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 14px 28px; border-radius: var(--radius-sm);
            font-size: 15px; font-weight: 600; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
            width: 100%;
        }
        .btn-primary { 
            background: linear-gradient(135deg, var(--c1), var(--c2)); 
            color: white; 
        }
        .btn-primary:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 4px 12px rgba(28, 77, 141, 0.3);
        }
        
        .stats-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            text-align: center;
        }
        .stat-value { font-size: 32px; font-weight: 700; color: var(--c1); }
        .stat-label { font-size: 12px; color: var(--text3); margin-top: 4px; }
        
        .info-box {
            background: var(--good-bg); border: 1px solid var(--good);
            border-radius: var(--radius-sm); padding: 16px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .info-box-icon { font-size: 20px; }
        .info-box-content { flex: 1; }
        .info-box-title { font-size: 14px; font-weight: 600; color: var(--good); }
        .info-box-text { font-size: 13px; color: var(--text2); margin-top: 4px; }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .main-content { padding: 16px 20px; }
            .card { padding: 16px; }
        }
        @media (max-width: 480px) {
            .page-title { font-size: 20px; }
            .card { padding: 12px; }
            .main-content { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/researcher_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title">📥 Export Research Data</h1>
            <p class="page-subtitle">Download sensor data for external analysis in Excel, R, Python, or other tools</p>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($dataCount) ?></div>
                <div class="stat-label">Records Available<br>(Last 30 Days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($sensorTypes) ?></div>
                <div class="stat-label">Sensor Types</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($devices) ?></div>
                <div class="stat-label">Active Devices</div>
            </div>
        </div>
        
        <!-- Export Form -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Configure Export</span>
            </div>
            <div class="card-body">
                <form method="POST" id="exportForm">
                    <!-- Date Range -->
                    <div class="form-group">
                        <label class="form-label">Date Range</label>
                        <div class="form-row">
                            <input type="date" name="start_date" class="form-input" 
                                   value="<?= date('Y-m-d', strtotime('-30 days')) ?>" required>
                            <input type="date" name="end_date" class="form-input"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Sensor Type (Optional)</label>
                            <select name="sensor_type" class="form-select">
                                <option value="">All Sensors</option>
                                <?php foreach ($sensorTypes as $type): ?>
                                    <option value="<?= $type ?>">
                                        <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Device (Optional)</label>
                            <select name="device_id" class="form-select">
                                <option value="">All Devices</option>
                                <?php foreach ($devices as $device): ?>
                                    <option value="<?= $device['device_id'] ?>">
                                        <?= htmlspecialchars($device['device_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Format Selection -->
                    <div class="form-group">
                        <label class="form-label">Export Format</label>
                        <div class="format-options">
                            <label class="format-option">
                                <input type="radio" name="format" value="csv" checked>
                                <div class="format-content">
                                    <div class="format-icon">📊</div>
                                    <div class="format-name">CSV</div>
                                    <div class="format-desc">For Excel, Google Sheets</div>
                                </div>
                            </label>
                            <label class="format-option">
                                <input type="radio" name="format" value="json">
                                <div class="format-content">
                                    <div class="format-icon">🗄️</div>
                                    <div class="format-name">JSON</div>
                                    <div class="format-desc">For Python, R, APIs</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Info Box -->
                    <div class="info-box" style="margin-bottom: 20px;">
                        <div class="info-box-icon">💡</div>
                        <div class="info-box-content">
                            <div class="info-box-title">Export Tips</div>
                            <div class="info-box-text">
                                • Maximum 10,000 records per export<br>
                                • Use date filters to narrow down data<br>
                                • CSV works best for Excel analysis<br>
                                • JSON is ideal for programmatic analysis
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        🚀 Download Data Export
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('exportForm').addEventListener('submit', function() {
            // Show toast after a short delay
            setTimeout(() => {
                if (typeof showToast === 'function') {
                    showToast('Export started! File will download shortly.', 'info', 3000);
                }
            }, 500);
        });
    </script>
</body>
</html>
