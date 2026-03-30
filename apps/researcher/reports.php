<?php
/**
 * Aqua-Vision — Researcher Reports Page
 * Generate and export water quality reports
 * Location: apps/researcher/reports.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Reports Error [$errno]: $errstr in $errfile:$errline");
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

function getReportData($conn, $filters) {
    $sensorType = $filters['sensor_type'] ?? null;
    $startDate = $filters['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $filters['end_date'] ?? date('Y-m-d');
    $deviceId = $filters['device_id'] ?? null;
    $riverSection = $filters['river_section'] ?? null;
    
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at, 
                   s.sensor_type, s.unit, s.min_threshold, s.max_threshold,
                   d.device_id, d.device_name, l.location_name, l.river_section
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
    
    if ($riverSection) {
        $sql .= " AND l.river_section = ?";
        $params[] = $riverSection;
        $types .= "s";
    }
    
    $sql .= " ORDER BY sr.recorded_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function generateReportSummary($readings) {
    if (empty($readings)) return null;
    
    $values = array_column($readings, 'value');
    $bySensor = [];
    $bySection = [];
    
    foreach ($readings as $r) {
        $sensor = $r['sensor_type'];
        $section = $r['river_section'] ?? 'Unknown';
        
        if (!isset($bySensor[$sensor])) {
            $bySensor[$sensor] = ['values' => [], 'unit' => $r['unit']];
        }
        $bySensor[$sensor]['values'][] = $r['value'];
        
        if (!isset($bySection[$section])) {
            $bySection[$section] = ['count' => 0, 'sensors' => []];
        }
        $bySection[$section]['count']++;
        if (!in_array($sensor, $bySection[$section]['sensors'])) {
            $bySection[$section]['sensors'][] = $sensor;
        }
    }
    
    $sensorStats = [];
    foreach ($bySensor as $sensor => $data) {
        $vals = $data['values'];
        $sensorStats[$sensor] = [
            'unit' => $data['unit'],
            'count' => count($vals),
            'mean' => array_sum($vals) / count($vals),
            'min' => min($vals),
            'max' => max($vals),
            'std_dev' => calculateStdDev($vals)
        ];
    }
    
    return [
        'total_readings' => count($readings),
        'date_range' => [
            'start' => min(array_column($readings, 'recorded_at')),
            'end' => max(array_column($readings, 'recorded_at'))
        ],
        'by_sensor' => $sensorStats,
        'by_section' => $bySection
    ];
}

function calculateStdDev($values) {
    if (count($values) < 2) return 0;
    $mean = array_sum($values) / count($values);
    $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / (count($values) - 1);
    return sqrt($variance);
}

// ── Handle Report Generation ──────────────────────────────────────────────────
$reportData = null;
$reportSummary = null;
$filters = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = [
        'sensor_type' => $_POST['sensor_type'] ?? null,
        'start_date' => $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days')),
        'end_date' => $_POST['end_date'] ?? date('Y-m-d'),
        'device_id' => $_POST['device_id'] ?? null,
        'river_section' => $_POST['river_section'] ?? null
    ];
    
    $reportData = getReportData($conn, $filters);
    $reportSummary = generateReportSummary($reportData);
    
    // Handle export
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="water_quality_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Report Generated', date('Y-m-d H:i:s')]);
        fputcsv($output, ['Date Range', $filters['start_date'] . ' to ' . $filters['end_date']]);
        fputcsv($output, []);
        fputcsv($output, ['Reading ID', 'Timestamp', 'Device', 'Location', 'River Section', 'Sensor Type', 'Value', 'Unit', 'Status']);
        
        foreach ($reportData as $row) {
            $status = 'Normal';
            if ($row['value'] < $row['min_threshold'] || $row['value'] > $row['max_threshold']) {
                $status = 'Out of Range';
            }
            fputcsv($output, [
                $row['reading_id'],
                $row['recorded_at'],
                $row['device_name'],
                $row['location_name'],
                $row['river_section'],
                $row['sensor_type'],
                $row['value'],
                $row['unit'],
                $status
            ]);
        }
        fclose($output);
        exit;
    }
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'reports';

// Get filter options
$sensorTypes = $conn->query("SELECT DISTINCT sensor_type FROM sensors ORDER BY sensor_type")->fetch_all(MYSQLI_ASSOC);
$devices = $conn->query("SELECT device_id, device_name FROM devices WHERE status = 'active' ORDER BY device_name")->fetch_all(MYSQLI_ASSOC);
$sections = $conn->query("SELECT DISTINCT river_section FROM locations WHERE river_section IS NOT NULL ORDER BY river_section")->fetch_all(MYSQLI_ASSOC);

// Get report templates
$templates = [
    'daily' => ['name' => 'Daily Summary', 'hours' => 24],
    'weekly' => ['name' => 'Weekly Analysis', 'hours' => 168],
    'monthly' => ['name' => 'Monthly Report', 'hours' => 720],
    'custom' => ['name' => 'Custom Range', 'hours' => 0]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — Aqua-Vision Researcher</title>
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
        
        .main-content { padding: 24px; max-width: 1400px; }
        
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
        
        .content-grid {
            display: grid; grid-template-columns: 320px 1fr; gap: 24px;
        }
        
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
        }
        .card-title { font-size: 15px; font-weight: 600; color: var(--c1); }
        .card-body { padding: 20px; }
        
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--text); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-select, .form-input {
            width: 100%; padding: 10px 12px;
            border: 2px solid var(--border); border-radius: var(--radius-sm);
            font-size: 13px; background: var(--surface); color: var(--text);
        }
        .form-select:focus, .form-input:focus {
            outline: none; border-color: var(--c3);
        }
        
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
            width: 100%;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-primary:hover { background: var(--c1); }
        .btn-success { background: var(--good); color: white; }
        .btn-success:hover { background: #15803d; }
        .btn-outline { 
            background: var(--surface); color: var(--text2); 
            border: 1px solid var(--border); 
        }
        .btn-outline:hover { background: var(--bg); }
        
        .template-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
            margin-bottom: 20px;
        }
        .template-card {
            padding: 16px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); cursor: pointer;
            transition: all 0.2s; text-align: center;
        }
        .template-card:hover { border-color: var(--c3); background: var(--bg); }
        .template-card.active {
            border-color: var(--c2); background: rgba(28, 77, 141, 0.05);
        }
        .template-icon { font-size: 24px; margin-bottom: 8px; }
        .template-name { font-size: 12px; font-weight: 600; color: var(--c1); }
        
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 28px; font-weight: 700; color: var(--c1); margin-top: 8px; }
        .stat-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
        
        .sensor-stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;
        }
        .sensor-stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 16px; border: 1px solid var(--border);
        }
        .sensor-stat-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px;
        }
        .sensor-stat-name { font-size: 14px; font-weight: 600; color: var(--c1); }
        .sensor-stat-unit { font-size: 11px; color: var(--text3); }
        .sensor-stat-row {
            display: flex; justify-content: space-between; padding: 6px 0;
            border-bottom: 1px solid var(--border); font-size: 13px;
        }
        .sensor-stat-row:last-child { border-bottom: none; }
        .sensor-stat-label { color: var(--text2); }
        .sensor-stat-value { font-weight: 600; color: var(--c1); }
        
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
        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 500;
        }
        .status-badge.normal { background: var(--good-bg); color: var(--good); }
        .status-badge.warning { background: var(--warn-bg); color: var(--warn); }
        .status-badge.critical { background: var(--crit-bg); color: var(--crit); }
        
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-state-title { font-size: 18px; font-weight: 600; color: var(--c1); margin-bottom: 8px; }
        .empty-state-text { font-size: 14px; color: var(--text2); }
        
        .section-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 500;
        }
        .section-badge.upstream { background: #dbeafe; color: #1e40af; }
        .section-badge.midstream { background: #fef3c7; color: #92400e; }
        .section-badge.downstream { background: #fee2e2; color: #991b1b; }
        
        .chart-container { height: 300px; }
        
        @media (max-width: 1200px) {
            .content-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/researcher_nav.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Generate Reports</h1>
                <p class="page-subtitle">Create comprehensive water quality reports with filtering and export options</p>
            </div>
        </div>
        
        <div class="content-grid">
            <!-- Filter Panel -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Report Configuration</span>
                    </div>
                    <div class="card-body">
                        <!-- Templates -->
                        <div class="template-grid">
                            <div class="template-card <?= ($_POST['template'] ?? '') === 'daily' ? 'active' : '' ?>" onclick="selectTemplate('daily', 1)">
                                <div class="template-icon">📅</div>
                                <div class="template-name">Daily</div>
                            </div>
                            <div class="template-card <?= ($_POST['template'] ?? '') === 'weekly' ? 'active' : '' ?>" onclick="selectTemplate('weekly', 7)">
                                <div class="template-icon">📊</div>
                                <div class="template-name">Weekly</div>
                            </div>
                            <div class="template-card <?= ($_POST['template'] ?? '') === 'monthly' ? 'active' : '' ?>" onclick="selectTemplate('monthly', 30)">
                                <div class="template-icon">📈</div>
                                <div class="template-name">Monthly</div>
                            </div>
                            <div class="template-card <?= ($_POST['template'] ?? '') === 'custom' ? 'active' : '' ?>" onclick="selectTemplate('custom', 0)">
                                <div class="template-icon">⚙️</div>
                                <div class="template-name">Custom</div>
                            </div>
                        </div>
                        
                        <form method="POST" id="reportForm">
                            <input type="hidden" name="template" id="templateInput" value="<?= $_POST['template'] ?? 'custom' ?>">
                            
                            <div class="form-group">
                                <label class="form-label">Date Range</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <input type="date" name="start_date" class="form-input" 
                                           value="<?= $_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days')) ?>">
                                    <input type="date" name="end_date" class="form-input"
                                           value="<?= $_POST['end_date'] ?? date('Y-m-d') ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Sensor Type (Optional)</label>
                                <select name="sensor_type" class="form-select">
                                    <option value="">All Sensors</option>
                                    <?php foreach ($sensorTypes as $type): ?>
                                        <option value="<?= $type['sensor_type'] ?>" 
                                                <?= ($_POST['sensor_type'] ?? '') === $type['sensor_type'] ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $type['sensor_type'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Device (Optional)</label>
                                <select name="device_id" class="form-select">
                                    <option value="">All Devices</option>
                                    <?php foreach ($devices as $device): ?>
                                        <option value="<?= $device['device_id'] ?>"
                                                <?= ($_POST['device_id'] ?? '') == $device['device_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($device['device_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">River Section (Optional)</label>
                                <select name="river_section" class="form-select">
                                    <option value="">All Sections</option>
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?= $section['river_section'] ?>"
                                                <?= ($_POST['river_section'] ?? '') === $section['river_section'] ? 'selected' : '' ?>>
                                            <?= ucfirst($section['river_section']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="margin-bottom: 10px;">
                                🔍 Generate Report
                            </button>
                            
                            <?php if ($reportData): ?>
                                <button type="submit" name="export_csv" class="btn btn-success">
                                    📥 Export CSV
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Report Results -->
            <div>
                <?php if (!$reportData): ?>
                    <div class="card">
                        <div class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <div class="empty-state-title">No Report Generated</div>
                            <div class="empty-state-text">Select your filters and click "Generate Report" to view data</div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Summary Stats -->
                    <div class="stats-grid" style="margin-bottom: 24px;">
                        <div class="stat-card">
                            <div class="stat-label">Total Readings</div>
                            <div class="stat-value"><?= number_format($reportSummary['total_readings']) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Date Range</div>
                            <div class="stat-value" style="font-size: 18px;">
                                <?= date('M d', strtotime($reportSummary['date_range']['start'])) ?> - 
                                <?= date('M d', strtotime($reportSummary['date_range']['end'])) ?>
                            </div>
                            <div class="stat-sub"><?= count($reportSummary['by_sensor']) ?> sensor types</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">River Sections</div>
                            <div class="stat-value"><?= count($reportSummary['by_section']) ?></div>
                            <div class="stat-sub">Covered in report</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Data Quality</div>
                            <div class="stat-value" style="color: var(--good);">✓</div>
                            <div class="stat-sub">All sensors operational</div>
                        </div>
                    </div>
                    
                    <!-- Sensor Statistics -->
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-header">
                            <span class="card-title">Sensor Statistics by Type</span>
                        </div>
                        <div class="card-body">
                            <div class="sensor-stat-grid">
                                <?php foreach ($reportSummary['by_sensor'] as $sensor => $stats): ?>
                                    <div class="sensor-stat-card">
                                        <div class="sensor-stat-header">
                                            <span class="sensor-stat-name"><?= ucfirst(str_replace('_', ' ', $sensor)) ?></span>
                                            <span class="sensor-stat-unit"><?= $stats['unit'] ?></span>
                                        </div>
                                        <div class="sensor-stat-row">
                                            <span class="sensor-stat-label">Mean</span>
                                            <span class="sensor-stat-value"><?= round($stats['mean'], 2) ?></span>
                                        </div>
                                        <div class="sensor-stat-row">
                                            <span class="sensor-stat-label">Min / Max</span>
                                            <span class="sensor-stat-value"><?= round($stats['min'], 2) ?> / <?= round($stats['max'], 2) ?></span>
                                        </div>
                                        <div class="sensor-stat-row">
                                            <span class="sensor-stat-label">Std Deviation</span>
                                            <span class="sensor-stat-value"><?= round($stats['std_dev'], 2) ?></span>
                                        </div>
                                        <div class="sensor-stat-row">
                                            <span class="sensor-stat-label">Readings</span>
                                            <span class="sensor-stat-value"><?= number_format($stats['count']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section Distribution -->
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-header">
                            <span class="card-title">Data Distribution by River Section</span>
                        </div>
                        <div class="card-body">
                            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                                <?php foreach ($reportSummary['by_section'] as $section => $data): ?>
                                    <div class="section-badge <?= $section ?>">
                                        <?= $section === 'upstream' ? '⬆️' : ($section === 'midstream' ? '➡️' : '⬇️') ?>
                                        <?= ucfirst($section) ?>: 
                                        <?= number_format($data['count']) ?> readings
                                        (<?= count($data['sensors']) ?> sensors)
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Data Table -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Recent Readings (Last 50)</span>
                            <span style="font-size: 12px; color: var(--text3);">
                                Showing <?= min(50, count($reportData)) ?> of <?= number_format(count($reportData)) ?> records
                            </span>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Device</th>
                                        <th>Sensor</th>
                                        <th>Value</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($reportData, 0, 50) as $row): 
                                        $status = 'normal';
                                        $statusLabel = 'Normal';
                                        if ($row['value'] < $row['min_threshold'] || $row['value'] > $row['max_threshold']) {
                                            $status = 'critical';
                                            $statusLabel = 'Out of Range';
                                        }
                                    ?>
                                        <tr>
                                            <td><?= date('M d, H:i', strtotime($row['recorded_at'])) ?></td>
                                            <td><?= htmlspecialchars($row['device_name']) ?></td>
                                            <td><?= ucfirst(str_replace('_', ' ', $row['sensor_type'])) ?></td>
                                            <td><?= round($row['value'], 2) ?> <?= $row['unit'] ?></td>
                                            <td>
                                                <span class="status-badge <?= $status ?>">
                                                    <?= $status === 'normal' ? '✓' : '!' ?> <?= $statusLabel ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function selectTemplate(template, days) {
            document.querySelectorAll('.template-card').forEach(c => c.classList.remove('active'));
            event.currentTarget.classList.add('active');
            document.getElementById('templateInput').value = template;
            
            if (days > 0) {
                const end = new Date();
                const start = new Date();
                start.setDate(start.getDate() - days);
                
                document.querySelector('input[name="start_date"]').value = start.toISOString().split('T')[0];
                document.querySelector('input[name="end_date"]').value = end.toISOString().split('T')[0];
            }
        }
    </script>
</body>
</html>
