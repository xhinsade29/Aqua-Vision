<?php
/**
 * Aqua-Vision — Researcher Dashboard
 * Focus: View all data, generate reports, analyze sensor readings
 * Location: apps/researcher/dashboard.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Researcher Dashboard Error [$errno]: $errstr in $errfile:$errline");
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

// ── Helper Functions ─────────────────────────────────────────────────────────

/**
 * Get sensor readings with time range filtering
 */
function getSensorReadings($conn, $sensorType = null, $hours = 24, $deviceId = null) {
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at, 
                   s.sensor_type, s.unit, s.min_threshold, s.max_threshold,
                   d.device_id, d.device_name, l.location_name, l.river_section
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $params = [$hours];
    $types = "i";
    
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
    
    $sql .= " ORDER BY sr.recorded_at DESC LIMIT 1000";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get hourly averages for trend analysis
 */
function getHourlyTrends($conn, $sensorType, $hours = 24) {
    $sql = "SELECT 
                DATE_FORMAT(sr.recorded_at, '%Y-%m-%d %H:00') as hour,
                AVG(sr.value) as avg_value,
                MIN(sr.value) as min_value,
                MAX(sr.value) as max_value,
                COUNT(*) as reading_count
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ? 
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY hour
            ORDER BY hour";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get statistical summary for a sensor type
 */
function getSensorStatistics($conn, $sensorType, $hours = 24) {
    $sql = "SELECT 
                AVG(sr.value) as mean,
                MIN(sr.value) as minimum,
                MAX(sr.value) as maximum,
                STDDEV(sr.value) as std_deviation,
                COUNT(*) as total_readings,
                s.unit
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ? 
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Compare river sections
 */
function getSectionComparison($conn, $sensorType) {
    $sql = "SELECT 
                l.river_section,
                AVG(sr.value) as avg_value,
                MIN(sr.value) as min_value,
                MAX(sr.value) as max_value,
                COUNT(*) as reading_count
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE s.sensor_type = ? 
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND l.river_section IS NOT NULL
            GROUP BY l.river_section
            ORDER BY FIELD(l.river_section, 'upstream', 'midstream', 'downstream')";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $sensorType);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get all active devices with their latest readings
 */
function getDevicesWithReadings($conn) {
    $sql = "SELECT 
                d.device_id, d.device_name, d.status, d.last_active,
                l.location_name, l.river_section, l.latitude, l.longitude
            FROM devices d
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE d.status = 'active'
            ORDER BY l.river_section, d.device_name";
    
    $result = $conn->query($sql);
    $devices = [];
    
    while ($device = $result->fetch_assoc()) {
        // Get latest readings for each sensor type
        $readingsSql = "SELECT 
                            s.sensor_type, sr.value, sr.recorded_at, s.unit,
                            s.min_threshold, s.max_threshold
                        FROM sensors s
                        LEFT JOIN sensor_readings sr ON sr.sensor_id = s.sensor_id
                        LEFT JOIN (
                            SELECT sensor_id, MAX(recorded_at) as max_date
                            FROM sensor_readings
                            GROUP BY sensor_id
                        ) latest ON latest.sensor_id = s.sensor_id AND latest.max_date = sr.recorded_at
                        WHERE s.device_id = ? AND sr.reading_id IS NOT NULL";
        
        $stmt = $conn->prepare($readingsSql);
        $stmt->bind_param("i", $device['device_id']);
        $stmt->execute();
        $readings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $device['readings'] = [];
        foreach ($readings as $reading) {
            $device['readings'][$reading['sensor_type']] = $reading;
        }
        
        $devices[] = $device;
    }
    
    return $devices;
}

/**
 * Get alert summary for analysis
 */
function getAlertSummary($conn, $hours = 168) {
    $sql = "SELECT 
                a.alert_type,
                s.sensor_type,
                COUNT(*) as alert_count,
                AVG(sr.value) as avg_value_when_alerted
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN sensor_readings sr ON sr.reading_id = a.reading_id
            WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY a.alert_type, s.sensor_type
            ORDER BY alert_count DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── API: Export Data ──────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    
    $format = $_GET['format'] ?? 'csv';
    $sensorType = $_GET['sensor_type'] ?? null;
    $hours = intval($_GET['hours'] ?? 24);
    $deviceId = intval($_GET['device_id'] ?? 0) ?: null;
    
    $data = getSensorReadings($conn, $sensorType, $hours, $deviceId);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sensor_data_' . date('Y-m-d_H-i') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Reading ID', 'Device', 'Location', 'River Section', 'Sensor Type', 'Value', 'Unit', 'Recorded At', 'Min Threshold', 'Max Threshold']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['reading_id'],
                $row['device_name'],
                $row['location_name'],
                $row['river_section'],
                $row['sensor_type'],
                $row['value'],
                $row['unit'],
                $row['recorded_at'],
                $row['min_threshold'],
                $row['max_threshold']
            ]);
        }
        fclose($output);
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['data' => $data, 'exported_at' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT);
    }
    exit;
}

// ── API: Get Analysis Data ────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'analyze' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $sensorType = $_GET['sensor_type'] ?? 'temperature';
    $hours = intval($_GET['hours'] ?? 24);
    
    $response = [
        'statistics' => getSensorStatistics($conn, $sensorType, $hours),
        'hourly_trends' => getHourlyTrends($conn, $sensorType, $hours),
        'section_comparison' => getSectionComparison($conn, $sensorType),
        'alert_summary' => getAlertSummary($conn, $hours > 168 ? $hours : 168)
    ];
    
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'researcher-dashboard';

// Get available sensor types
$sensorTypesResult = $conn->query("SELECT DISTINCT sensor_type FROM sensors ORDER BY sensor_type");
$sensorTypes = [];
while ($row = $sensorTypesResult->fetch_assoc()) {
    $sensorTypes[] = $row['sensor_type'];
}

// Default sensor type for display
$activeSensor = $_GET['sensor'] ?? ($sensorTypes[0] ?? 'temperature');
$timeRange = intval($_GET['hours'] ?? 24);

// Get data for the page
$devices = getDevicesWithReadings($conn);
$recentReadings = getSensorReadings($conn, null, $timeRange);
$statistics = getSensorStatistics($conn, $activeSensor, $timeRange);
$hourlyTrends = getHourlyTrends($conn, $activeSensor, $timeRange);
$sectionComparison = getSectionComparison($conn, $activeSensor);
$alertSummary = getAlertSummary($conn, 168);

// Calculate correlation matrix (simplified)
$correlationData = [];
foreach ($sensorTypes as $type) {
    $stats = getSensorStatistics($conn, $type, $timeRange);
    if ($stats && $stats['total_readings'] > 0) {
        $correlationData[$type] = $stats;
    }
}

// Get reading counts by sensor type
$readingCounts = [];
foreach ($sensorTypes as $type) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM sensor_readings sr 
                           JOIN sensors s ON s.sensor_id = sr.sensor_id 
                           WHERE s.sensor_type = '$type' 
                           AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL $timeRange HOUR)");
    $readingCounts[$type] = $result->fetch_assoc()['cnt'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Researcher Dashboard — Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --c1: #0F2854; --c2: #1C4D8D; --c3: #4988C4; --c4: #BDE8F5;
            --bg: #f0f5fb; --surface: #fff; --border: rgba(15,40,84,.08);
            --text: #0F2854; --text2: #4a6080; --text3: #8aa0bc;
            --good: #16a34a; --good-bg: #dcfce7;
            --warn: #d97706; --warn-bg: #fef3c7;
            --crit: #dc2626; --crit-bg: #fee2e2;
            --radius: 14px; --radius-sm: 8px;
            --sidebar-w: 240px;
        }
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: var(--bg); 
            min-height: 100vh;
            margin-left: var(--sidebar-w);
        }
        
        .main-content { padding: 24px; }
        
        /* Header */
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-title { 
            font-family: 'Space Grotesk', sans-serif; 
            font-size: 28px; font-weight: 700; color: var(--c1); 
        }
        .page-subtitle { 
            font-size: 14px; color: var(--text2); margin-top: 4px; 
        }
        
        /* Filter Bar */
        .filter-bar {
            display: flex; gap: 12px; align-items: center;
            background: var(--surface); padding: 16px 20px;
            border-radius: var(--radius); border: 1px solid var(--border);
            margin-bottom: 24px; flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 8px; }
        .filter-label { font-size: 12px; color: var(--text3); font-weight: 500; }
        .filter-select {
            padding: 8px 12px; border: 2px solid var(--border);
            border-radius: var(--radius-sm); font-size: 13px;
            background: var(--surface); color: var(--text);
            cursor: pointer; min-width: 140px;
        }
        .filter-select:focus { outline: none; border-color: var(--c3); }
        
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-primary:hover { background: var(--c1); }
        .btn-outline { background: var(--surface); color: var(--text2); border: 1px solid var(--border); }
        .btn-outline:hover { background: var(--bg); }
        
        /* Grid Layouts */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stats-grid-3 {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .content-grid {
            display: grid; grid-template-columns: 2fr 1fr; gap: 16px;
            margin-bottom: 24px;
        }
        .content-grid-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
            margin-bottom: 24px;
        }
        
        /* Cards */
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-title { font-size: 15px; font-weight: 600; color: var(--c1); }
        .card-body { padding: 20px; }
        
        /* Stat Cards */
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { 
            font-size: 32px; font-weight: 700; color: var(--c1); margin-top: 8px;
            font-family: 'Space Grotesk', sans-serif;
        }
        .stat-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
        .stat-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 500; margin-top: 8px;
        }
        .stat-badge.good { background: var(--good-bg); color: var(--good); }
        .stat-badge.warn { background: var(--warn-bg); color: var(--warn); }
        .stat-badge.crit { background: var(--crit-bg); color: var(--crit); }
        
        /* Chart Container */
        .chart-container { position: relative; height: 300px; }
        .chart-container-sm { position: relative; height: 200px; }
        
        /* Data Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: var(--bg); padding: 12px 16px;
            text-align: left; font-size: 11px; font-weight: 600;
            color: var(--text2); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .data-table td {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
            font-size: 13px; color: var(--text);
        }
        .data-table tr:hover td { background: var(--bg); }
        .data-table tr:last-child td { border-bottom: none; }
        
        /* Section Comparison */
        .section-bar {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; border-bottom: 1px solid var(--border);
        }
        .section-bar:last-child { border-bottom: none; }
        .section-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .section-icon.upstream { background: #dbeafe; }
        .section-icon.midstream { background: #fef3c7; }
        .section-icon.downstream { background: #fee2e2; }
        .section-info { flex: 1; }
        .section-name { font-size: 13px; font-weight: 600; color: var(--c1); }
        .section-range { font-size: 11px; color: var(--text3); margin-top: 2px; }
        .section-value { font-size: 20px; font-weight: 700; color: var(--c1); }
        
        /* Alert Summary */
        .alert-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px; border-bottom: 1px solid var(--border);
        }
        .alert-item:last-child { border-bottom: none; }
        .alert-icon {
            width: 28px; height: 28px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
        }
        .alert-icon.critical { background: var(--crit-bg); color: var(--crit); }
        .alert-icon.high { background: var(--warn-bg); color: var(--warn); }
        .alert-icon.low { background: var(--good-bg); color: var(--good); }
        .alert-info { flex: 1; }
        .alert-type { font-size: 12px; font-weight: 500; color: var(--text); }
        .alert-sensor { font-size: 11px; color: var(--text3); }
        .alert-count {
            padding: 4px 10px; border-radius: 12px;
            font-size: 12px; font-weight: 600;
        }
        .alert-count.high { background: var(--crit-bg); color: var(--crit); }
        
        /* Sensor Selector */
        .sensor-tabs {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding: 4px;
        }
        .sensor-tab {
            padding: 8px 16px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text2); transition: all 0.2s;
        }
        .sensor-tab:hover { background: var(--bg); }
        .sensor-tab.active {
            background: var(--c2); color: white; border-color: var(--c2);
        }
        
        /* Export Options */
        .export-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
        }
        .export-option {
            padding: 16px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); text-align: center;
            cursor: pointer; transition: all 0.2s;
        }
        .export-option:hover {
            border-color: var(--c3); background: var(--bg);
        }
        .export-icon { font-size: 24px; margin-bottom: 8px; }
        .export-name { font-size: 13px; font-weight: 500; color: var(--c1); }
        .export-desc { font-size: 11px; color: var(--text3); margin-top: 4px; }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid, .stats-grid-3 { grid-template-columns: repeat(2, 1fr); }
            .content-grid, .content-grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid, .stats-grid-3 { grid-template-columns: 1fr; }
            body { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/researcher_nav.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Researcher Dashboard</h1>
                <p class="page-subtitle">Analyze sensor data, generate reports, and monitor water quality trends</p>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Sensor Type:</span>
                <select class="filter-select" onchange="window.location.href='?sensor='+this.value+'&hours=<?= $timeRange ?>'">
                    <?php foreach ($sensorTypes as $type): ?>
                        <option value="<?= $type ?>" <?= $type === $activeSensor ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Time Range:</span>
                <select class="filter-select" onchange="window.location.href='?sensor=<?= $activeSensor ?>&hours='+this.value">
                    <option value="24" <?= $timeRange == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="48" <?= $timeRange == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                    <option value="72" <?= $timeRange == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                    <option value="168" <?= $timeRange == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="720" <?= $timeRange == 720 ? 'selected' : '' ?>>Last 30 Days</option>
                </select>
            </div>
            <div style="margin-left: auto; display: flex; gap: 8px;">
                <a href="?action=export&format=csv&sensor_type=<?= $activeSensor ?>&hours=<?= $timeRange ?>" class="btn btn-outline">
                    📥 Export CSV
                </a>
                <a href="reports.php" class="btn btn-primary">
                    📊 Generate Report
                </a>
            </div>
        </div>
        
        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Mean <?= ucfirst(str_replace('_', ' ', $activeSensor)) ?></div>
                <div class="stat-value">
                    <?= $statistics ? round($statistics['mean'], 2) : 'N/A' ?>
                </div>
                <div class="stat-sub"><?= $statistics['unit'] ?? '' ?></div>
                <?php if ($statistics): ?>
                    <?php 
                    $status = 'good';
                    if ($statistics['mean'] < $statistics['min_threshold'] || $statistics['mean'] > $statistics['max_threshold']) {
                        $status = 'crit';
                    }
                    ?>
                    <span class="stat-badge <?= $status ?>">
                        <?= $status === 'good' ? '✓ Normal' : ($status === 'crit' ? '✗ Out of Range' : '⚠ Warning') ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Minimum Value</div>
                <div class="stat-value">
                    <?= $statistics ? round($statistics['minimum'], 2) : 'N/A' ?>
                </div>
                <div class="stat-sub"><?= $statistics['unit'] ?? '' ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Maximum Value</div>
                <div class="stat-value">
                    <?= $statistics ? round($statistics['maximum'], 2) : 'N/A' ?>
                </div>
                <div class="stat-sub"><?= $statistics['unit'] ?? '' ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Readings</div>
                <div class="stat-value">
                    <?= $statistics ? number_format($statistics['total_readings']) : 'N/A' ?>
                </div>
                <div class="stat-sub">In last <?= $timeRange ?> hours</div>
                <?php if ($statistics && $statistics['std_deviation']): ?>
                    <span class="stat-badge good">
                        σ = <?= round($statistics['std_deviation'], 2) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Trend Chart -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><?= ucfirst(str_replace('_', ' ', $activeSensor)) ?> Trends (<?= $timeRange ?>h)</span>
                    <select class="filter-select" style="min-width: auto;" onchange="updateChartType(this.value)">
                        <option value="line">Line Chart</option>
                        <option value="bar">Bar Chart</option>
                    </select>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- River Section Comparison -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Section Comparison</span>
                </div>
                <div>
                    <?php if (empty($sectionComparison)): ?>
                        <div style="padding: 40px; text-align: center; color: var(--text3);">
                            No data available for comparison
                        </div>
                    <?php else: ?>
                        <?php foreach ($sectionComparison as $section): ?>
                            <div class="section-bar">
                                <div class="section-icon <?= $section['river_section'] ?>">
                                    <?= $section['river_section'] === 'upstream' ? '⬆️' : ($section['river_section'] === 'midstream' ? '➡️' : '⬇️') ?>
                                </div>
                                <div class="section-info">
                                    <div class="section-name"><?= ucfirst($section['river_section']) ?></div>
                                    <div class="section-range">
                                        <?= round($section['min_value'], 2) ?> - <?= round($section['max_value'], 2) ?> 
                                        (<?= $section['reading_count'] ?> readings)
                                    </div>
                                </div>
                                <div class="section-value">
                                    <?= round($section['avg_value'], 2) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Second Row -->
        <div class="content-grid-2">
            <!-- Recent Data Table -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Sensor Readings</span>
                    <a href="?action=export&format=csv&hours=<?= $timeRange ?>" class="btn btn-outline" style="padding: 6px 12px; font-size: 12px;">
                        Export All
                    </a>
                </div>
                <div style="max-height: 350px; overflow-y: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Device</th>
                                <th>Sensor</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $recent = array_slice($recentReadings, 0, 10);
                            foreach ($recent as $reading): 
                            ?>
                                <tr>
                                    <td><?= date('M d H:i', strtotime($reading['recorded_at'])) ?></td>
                                    <td><?= htmlspecialchars($reading['device_name']) ?></td>
                                    <td><?= ucfirst(str_replace('_', ' ', $reading['sensor_type'])) ?></td>
                                    <td>
                                        <?= round($reading['value'], 2) ?> <?= $reading['unit'] ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Alert Summary -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Alert Summary (Last 7 Days)</span>
                </div>
                <div>
                    <?php if (empty($alertSummary)): ?>
                        <div style="padding: 40px; text-align: center; color: var(--text3);">
                            No alerts in the last 7 days
                        </div>
                    <?php else: ?>
                        <?php 
                        $totalAlerts = array_sum(array_column($alertSummary, 'alert_count'));
                        foreach (array_slice($alertSummary, 0, 6) as $alert): 
                        ?>
                            <div class="alert-item">
                                <div class="alert-icon <?= $alert['alert_type'] ?>">
                                    <?= $alert['alert_type'] === 'critical' ? '!' : ($alert['alert_type'] === 'high' ? '⚠' : '•') ?>
                                </div>
                                <div class="alert-info">
                                    <div class="alert-type"><?= ucfirst($alert['alert_type']) ?> Alert</div>
                                    <div class="alert-sensor"><?= ucfirst(str_replace('_', ' ', $alert['sensor_type'])) ?></div>
                                </div>
                                <div class="alert-count <?= $alert['alert_type'] ?>">
                                    <?= $alert['alert_count'] ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($alertSummary) > 6): ?>
                            <div style="padding: 12px; text-align: center; font-size: 12px; color: var(--text3);">
                                +<?= count($alertSummary) - 6 ?> more alert types
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Device Overview -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <span class="card-title">Active Devices Overview</span>
                <span style="font-size: 12px; color: var(--text3);">
                    <?= count($devices) ?> devices online
                </span>
            </div>
            <div style="max-height: 300px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Location</th>
                            <th>Section</th>
                            <th>Last Active</th>
                            <th>Latest Readings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($devices, 0, 8) as $device): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($device['device_name']) ?></strong></td>
                                <td><?= htmlspecialchars($device['location_name'] ?? 'Unknown') ?></td>
                                <td><?= ucfirst($device['river_section'] ?? 'N/A') ?></td>
                                <td><?= $device['last_active'] ? date('M d H:i', strtotime($device['last_active'])) : 'Never' ?></td>
                                <td>
                                    <?php 
                                    $readingCount = count($device['readings']);
                                    echo $readingCount > 0 ? "{$readingCount} sensors active" : "No data";
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendData = <?= json_encode($hourlyTrends) ?>;
        
        const labels = trendData.map(d => {
            const date = new Date(d.hour);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit' });
        });
        
        const avgValues = trendData.map(d => d.avg_value);
        const minValues = trendData.map(d => d.min_value);
        const maxValues = trendData.map(d => d.max_value);
        
        let trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Average',
                        data: avgValues,
                        borderColor: '#1C4D8D',
                        backgroundColor: 'rgba(28, 77, 141, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3,
                        pointBackgroundColor: '#1C4D8D'
                    },
                    {
                        label: 'Min',
                        data: minValues,
                        borderColor: '#059669',
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0,
                        tension: 0.4
                    },
                    {
                        label: 'Max',
                        data: maxValues,
                        borderColor: '#dc2626',
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            font: { size: 11 }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(15, 40, 84, 0.05)'
                        },
                        ticks: {
                            font: { size: 11 }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: { size: 10 },
                            maxRotation: 45
                        }
                    }
                }
            }
        });
        
        function updateChartType(type) {
            trendChart.config.type = type;
            trendChart.update();
        }
        
        // Auto-refresh data every 30 seconds
        setInterval(() => {
            fetch('?action=analyze&sensor_type=<?= $activeSensor ?>&hours=<?= $timeRange ?>')
                .then(r => r.json())
                .then(data => {
                    // Could update charts dynamically here
                })
                .catch(console.error);
        }, 30000);
    </script>
</body>
</html>
