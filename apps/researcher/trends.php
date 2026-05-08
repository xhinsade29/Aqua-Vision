<?php
/**
 * Aqua-Vision — Researcher Trend Analysis
 * Long-term sensor data patterns and trends
 * Location: apps/researcher/trends.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Trends Error [$errno]: $errstr in $errfile:$errline");
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

function getTrendData($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                DATE(sr.recorded_at) as date,
                AVG(sr.value) as avg_value,
                MIN(sr.value) as min_value,
                MAX(sr.value) as max_value,
                STDDEV(sr.value) as std_dev,
                COUNT(*) as reading_count,
                s.unit
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(sr.recorded_at)
            ORDER BY date";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getThresholdViolations($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                COUNT(*) as violation_count,
                AVG(sr.value) as avg_violation_value,
                MIN(sr.value) as min_violation_value,
                MAX(sr.value) as max_violation_value
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND (
                  (s.min_threshold IS NOT NULL AND sr.value < s.min_threshold) OR
                  (s.max_threshold IS NOT NULL AND sr.value > s.max_threshold)
              )";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getHourlyPattern($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                HOUR(sr.recorded_at) as hour,
                AVG(sr.value) as avg_value,
                COUNT(*) as reading_count
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY HOUR(sr.recorded_at)
            ORDER BY hour";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getMedian($conn, $sensorType, $days = 30) {
    $sql = "SELECT sr.value
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY sr.value";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    $values = [];
    while ($row = $result->fetch_assoc()) {
        $values[] = $row['value'];
    }
    $count = count($values);
    if ($count === 0) return null;
    $mid = floor($count / 2);
    return ($count % 2 === 0) ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
}

function getPercentiles($conn, $sensorType, $days = 30) {
    $sql = "SELECT sr.value
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY sr.value";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    $values = [];
    while ($row = $result->fetch_assoc()) {
        $values[] = $row['value'];
    }
    $count = count($values);
    if ($count === 0) return ['p25' => null, 'p50' => null, 'p75' => null];
    
    $p25Index = floor($count * 0.25);
    $p50Index = floor($count * 0.50);
    $p75Index = floor($count * 0.75);
    
    return [
        'p25' => $values[$p25Index],
        'p50' => $values[$p50Index],
        'p75' => $values[$p75Index]
    ];
}

function detectAnomalies($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                DATE(sr.recorded_at) as date,
                sr.value,
                AVG(sr.value) OVER (ORDER BY sr.recorded_at ROWS BETWEEN 7 PRECEDING AND CURRENT ROW) as moving_avg,
                STDDEV(sr.value) OVER (ORDER BY sr.recorded_at ROWS BETWEEN 7 PRECEDING AND CURRENT ROW) as moving_std
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY sr.recorded_at";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $anomalies = [];
    while ($row = $result->fetch_assoc()) {
        if ($row['moving_std'] > 0 && abs($row['value'] - $row['moving_avg']) > 2 * $row['moving_std']) {
            $anomalies[] = [
                'date' => $row['date'],
                'value' => $row['value'],
                'expected' => $row['moving_avg'],
                'deviation' => round(abs($row['value'] - $row['moving_avg']) / $row['moving_std'], 2)
            ];
        }
    }
    return $anomalies;
}

function getDeviceBreakdown($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                d.device_name,
                COUNT(*) as reading_count,
                AVG(sr.value) as avg_value,
                MIN(sr.value) as min_value,
                MAX(sr.value) as max_value
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY d.device_id, d.device_name
            ORDER BY reading_count DESC
            LIMIT 5";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function calculateLinearRegression($trendData) {
    if (count($trendData) < 2) return ['slope' => 0, 'intercept' => 0, 'r2' => 0];
    
    $n = count($trendData);
    $x = range(0, $n - 1);
    $y = array_column($trendData, 'avg_value');
    
    $sumX = array_sum($x);
    $sumY = array_sum($y);
    $sumXY = 0;
    $sumX2 = 0;
    $sumY2 = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $sumXY += $x[$i] * $y[$i];
        $sumX2 += $x[$i] * $x[$i];
        $sumY2 += $y[$i] * $y[$i];
    }
    
    $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
    $intercept = ($sumY - $slope * $sumX) / $n;
    
    // Calculate R-squared
    $meanY = $sumY / $n;
    $ssTotal = 0;
    $ssResidual = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $predicted = $slope * $x[$i] + $intercept;
        $ssTotal += pow($y[$i] - $meanY, 2);
        $ssResidual += pow($y[$i] - $predicted, 2);
    }
    
    $r2 = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0;
    
    return ['slope' => $slope, 'intercept' => $intercept, 'r2' => round($r2, 3)];
}

function getLastUpdate($conn, $sensorType) {
    $sql = "SELECT MAX(sr.recorded_at) as last_update
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $sensorType);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['last_update'] ?? null;
}

function getMonthlyComparison($conn, $sensorType) {
    $sql = "SELECT 
                DATE_FORMAT(sr.recorded_at, '%Y-%m') as month,
                AVG(sr.value) as avg_value,
                MIN(sr.value) as min_value,
                MAX(sr.value) as max_value,
                COUNT(*) as reading_count
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(sr.recorded_at, '%Y-%m')
            ORDER BY month";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $sensorType);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getSectionTrends($conn, $sensorType, $days = 30) {
    $sql = "SELECT 
                l.river_section,
                DATE(sr.recorded_at) as date,
                AVG(sr.value) as avg_value
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            LEFT JOIN locations l ON l.location_id = d.location_id
            WHERE s.sensor_type = ?
              AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND l.river_section IS NOT NULL
            GROUP BY l.river_section, DATE(sr.recorded_at)
            ORDER BY date";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $sensorType, $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'trends';

// Get available sensor types
$sensorTypesResult = $conn->query("SELECT DISTINCT sensor_type FROM sensors WHERE sensor_type NOT IN ('humidity', 'pressure', 'flow_rate') ORDER BY sensor_type");
$sensorTypes = [];
while ($row = $sensorTypesResult->fetch_assoc()) {
    $sensorTypes[] = $row['sensor_type'];
}
if (empty($sensorTypes)) {
    $sensorTypes = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
}

$activeSensor = $_GET['sensor'] ?? ($sensorTypes[0] ?? 'temperature');
$timeRange = intval($_GET['days'] ?? 30);

// Get trend data
$trendData = getTrendData($conn, $activeSensor, $timeRange);
$monthlyData = getMonthlyComparison($conn, $activeSensor);
$sectionTrends = getSectionTrends($conn, $activeSensor, $timeRange);
$thresholdViolations = getThresholdViolations($conn, $activeSensor, $timeRange);
$hourlyPattern = getHourlyPattern($conn, $activeSensor, $timeRange);
$medianValue = getMedian($conn, $activeSensor, $timeRange);
$percentiles = getPercentiles($conn, $activeSensor, $timeRange);
$anomalies = detectAnomalies($conn, $activeSensor, $timeRange);
$deviceBreakdown = getDeviceBreakdown($conn, $activeSensor, $timeRange);
$regression = calculateLinearRegression($trendData);
$lastUpdate = getLastUpdate($conn, $activeSensor);

// Calculate trend direction
$trendDirection = 'stable';
$change = 0;
if (count($trendData) >= 2) {
    $firstHalf = array_slice($trendData, 0, intval(count($trendData) / 2));
    $secondHalf = array_slice($trendData, intval(count($trendData) / 2));
    $firstAvg = array_sum(array_column($firstHalf, 'avg_value')) / count($firstHalf);
    $secondAvg = array_sum(array_column($secondHalf, 'avg_value')) / count($secondHalf);
    
    $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;
    if ($change > 5) $trendDirection = 'increasing';
    elseif ($change < -5) $trendDirection = 'decreasing';
}

// Calculate overall standard deviation
$allStdDevs = array_column($trendData, 'std_dev');
$overallStdDev = count($allStdDevs) > 0 ? round(array_sum($allStdDevs) / count($allStdDevs), 3) : 0;

// Calculate coefficient of variation
$avgValue = count($trendData) > 0 ? round(array_sum(array_column($trendData, 'avg_value')) / count($trendData), 2) : 0;
$coefficientOfVariation = $avgValue > 0 ? round(($overallStdDev / $avgValue) * 100, 2) : 0;

// Calculate moving average for chart
$movingAverage = [];
$window = 5;
for ($i = 0; $i < count($trendData); $i++) {
    $start = max(0, $i - $window + 1);
    $slice = array_slice($trendData, $start, $window);
    $movingAverage[$i] = array_sum(array_column($slice, 'avg_value')) / count($slice);
}

// Get unit
$unit = $trendData[0]['unit'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trend Analysis — Aqua-Vision Researcher</title>
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
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-outline { background: var(--surface); color: var(--text2); border: 1px solid var(--border); }
        
        .stats-grid {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px;
            margin-bottom: 16px;
        }
        .last-update {
            font-size: 11px; color: var(--text3);
            text-align: right; margin-bottom: 16px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 16px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 18px; font-weight: 700; color: var(--c1); margin-top: 6px; }
        .stat-sub { font-size: 10px; color: var(--text2); margin-top: 2px; }
        .trend-indicator {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 500; margin-top: 8px;
        }
        .trend-up { background: var(--crit-bg); color: var(--crit); }
        .trend-down { background: var(--good-bg); color: var(--good); }
        .trend-stable { background: var(--warn-bg); color: var(--warn); }
        
        .content-grid {
            display: grid; grid-template-columns: 2fr 1fr; gap: 16px;
            margin-bottom: 24px;
        }
        .content-grid-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }
        
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
        
        .chart-container { height: 300px; }
        
        .insight-item {
            padding: 12px 16px; border-bottom: 1px solid var(--border);
        }
        .insight-item:last-child { border-bottom: none; }
        .insight-title { font-size: 13px; font-weight: 600; color: var(--c1); }
        .insight-text { font-size: 12px; color: var(--text2); margin-top: 4px; }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .content-grid, .content-grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .main-content { padding: 16px 20px; }
            .card { padding: 16px; }
            .filter-bar { flex-direction: column; align-items: flex-start; gap: 8px; }
            .filter-bar .sel { width: 100%; }
            .filter-bar .btn { width: 100%; justify-content: center; }
        }
        @media (max-width: 480px) {
            .page-title { font-size: 20px; }
            .card { padding: 12px; }
            .main-content { padding: 12px 16px; }
            .chart-container { height: 250px; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/researcher_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Trend Analysis</h1>
                <p class="page-subtitle">Analyze long-term sensor data patterns and water quality trends</p>
            </div>
            <a href="?action=export&sensor=<?= $activeSensor ?>&days=<?= $timeRange ?>" class="btn btn-primary">
                📥 Export Trend Data
            </a>
        </div>
        
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Sensor Type:</span>
                <select class="filter-select" onchange="window.location.href='?sensor='+this.value+'&days=<?= $timeRange ?>'">
                    <?php foreach ($sensorTypes as $type): ?>
                        <option value="<?= $type ?>" <?= $type === $activeSensor ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Time Range:</span>
                <select class="filter-select" onchange="window.location.href='?sensor=<?= $activeSensor ?>&days='+this.value">
                    <option value="7" <?= $timeRange == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $timeRange == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $timeRange == 90 ? 'selected' : '' ?>>Last 3 Months</option>
                    <option value="180" <?= $timeRange == 180 ? 'selected' : '' ?>>Last 6 Months</option>
                    <option value="365" <?= $timeRange == 365 ? 'selected' : '' ?>>Last Year</option>
                </select>
            </div>
        </div>
        
        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Trend Direction</div>
                <div class="stat-value" style="font-size: 20px;">
                    <?php if ($trendDirection === 'increasing'): ?>
                        <span style="color: var(--crit);">↗</span> Inc
                    <?php elseif ($trendDirection === 'decreasing'): ?>
                        <span style="color: var(--good);">↘</span> Dec
                    <?php else: ?>
                        <span style="color: var(--warn);">→</span> Stable
                    <?php endif; ?>
                </div>
                <div class="stat-sub"><?= round(abs($change ?? 0), 1) ?>% change</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average</div>
                <div class="stat-value" style="font-size: 20px;"><?= $avgValue ?></div>
                <div class="stat-sub"><?= $unit ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Median</div>
                <div class="stat-value" style="font-size: 20px;"><?= $medianValue !== null ? round($medianValue, 2) : 'N/A' ?></div>
                <div class="stat-sub"><?= $unit ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Std Deviation</div>
                <div class="stat-value" style="font-size: 20px;"><?= $overallStdDev ?></div>
                <div class="stat-sub">Variability</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Coefficient of Var</div>
                <div class="stat-value" style="font-size: 18px;"><?= $coefficientOfVariation ?>%</div>
                <div class="stat-sub">Volatility</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Anomalies</div>
                <div class="stat-value" style="font-size: 18px; color: <?= count($anomalies) > 0 ? 'var(--crit)' : 'var(--good)' ?>;">
                    <?= count($anomalies) ?>
                </div>
                <div class="stat-sub">Outliers detected</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Trend R²</div>
                <div class="stat-value" style="font-size: 18px;">
                    <?= $regression['r2'] ?>
                </div>
                <div class="stat-sub">Fit quality</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Threshold Violations</div>
                <div class="stat-value" style="font-size: 20px; color: <?= ($thresholdViolations['violation_count'] ?? 0) > 0 ? 'var(--crit)' : 'var(--good)' ?>;">
                    <?= number_format($thresholdViolations['violation_count'] ?? 0) ?>
                </div>
                <div class="stat-sub">Alerts triggered</div>
            </div>
        </div>
        
        <div class="last-update">
            Last data update: <?= $lastUpdate ? date('M d, Y H:i', strtotime($lastUpdate)) : 'N/A' ?>
        </div>
        
        <!-- Charts -->
        <div class="content-grid">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Daily Trends (<?= $timeRange ?> days)</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Key Insights</span>
                </div>
                <div>
                    <div class="insight-item">
                        <div class="insight-title">📈 Trend Direction</div>
                        <div class="insight-text">
                            The <?= ucfirst(str_replace('_', ' ', $activeSensor)) ?> shows a <?= $trendDirection ?> trend over the last <?= $timeRange ?> days.
                            <?= abs($change ?? 0) > 10 ? 'Significant change detected - consider investigating causes.' : 'Values remain within expected range.' ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">📊 Data Quality</div>
                        <div class="insight-text">
                            <?= count($trendData) ?> days of data analyzed with 
                            <?= number_format(array_sum(array_column($trendData, 'reading_count'))) ?> total readings.
                            <?= count($trendData) > 0 ? 'Sufficient data for reliable trend analysis.' : 'Limited data available for analysis.' ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">🎯 Recommendation</div>
                        <div class="insight-text">
                            <?php if ($trendDirection === 'increasing'): ?>
                                Monitor closely as values are trending upward. Review upstream activities.
                            <?php elseif ($trendDirection === 'decreasing'): ?>
                                Trend is favorable but verify sensors are functioning correctly.
                            <?php else: ?>
                                Conditions are stable. Continue routine monitoring.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">📏 Variability</div>
                        <div class="insight-text">
                            Standard deviation: <?= $overallStdDev ?> <?= $unit ?>.
                            Coefficient of variation: <?= $coefficientOfVariation ?>%.
                            <?= $coefficientOfVariation > 20 ? 'High volatility detected - investigate potential causes.' : 'Normal variability within acceptable range.' ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">⚠️ Threshold Violations</div>
                        <div class="insight-text">
                            <?= number_format($thresholdViolations['violation_count'] ?? 0) ?> violations recorded in the last <?= $timeRange ?> days.
                            <?= ($thresholdViolations['violation_count'] ?? 0) > 0 ? 'Review alert logs and take corrective action.' : 'No threshold violations - readings within safe limits.' ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">📊 Percentile Distribution</div>
                        <div class="insight-text">
                            25th: <?= $percentiles['p25'] ?> <?= $unit ?> | 50th: <?= $percentiles['p50'] ?> <?= $unit ?> | 75th: <?= $percentiles['p75'] ?> <?= $unit ?>
                        </div>
                    </div>
                    <div class="insight-item">
                        <div class="insight-title">🔍 Anomaly Detection</div>
                        <div class="insight-text">
                            <?= count($anomalies) ?> anomalies detected (>2σ from moving average).
                            <?= count($anomalies) > 0 ? 'Investigate unusual readings for sensor issues or environmental events.' : 'No significant anomalies detected.' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Device Breakdown -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <span class="card-title">Top 5 Devices by Reading Count</span>
            </div>
            <div class="card-body">
                <?php if (empty($deviceBreakdown)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text3);">No device data available</div>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <th style="text-align: left; padding: 8px; font-size: 12px; color: var(--text3);">Device</th>
                                <th style="text-align: right; padding: 8px; font-size: 12px; color: var(--text3);">Readings</th>
                                <th style="text-align: right; padding: 8px; font-size: 12px; color: var(--text3);">Avg</th>
                                <th style="text-align: right; padding: 8px; font-size: 12px; color: var(--text3);">Range</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deviceBreakdown as $device): ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 8px; font-size: 13px; color: var(--text);"><?= htmlspecialchars($device['device_name']) ?></td>
                                <td style="text-align: right; padding: 8px; font-size: 13px; color: var(--text2);">
                                    <?= number_format($device['reading_count']) ?>
                                </td>
                                <td style="text-align: right; padding: 8px; font-size: 13px; color: var(--text2);">
                                    <?= round($device['avg_value'], 2) ?>
                                </td>
                                <td style="text-align: right; padding: 8px; font-size: 13px; color: var(--text2);">
                                    <?= round($device['min_value'], 2) ?> - <?= round($device['max_value'], 2) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Section Comparison -->
        <div class="content-grid-2" style="margin-top: 24px;">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Section Comparison (<?= $timeRange ?> days)</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="sectionChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Hourly Pattern (<?= $timeRange ?> days)</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Monthly Comparison -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <span class="card-title">Monthly Comparison (Last 12 Months)</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Daily Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendData = <?= json_encode($trendData) ?>;
        
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendData.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
                datasets: [
                    {
                        label: 'Average',
                        data: trendData.map(d => d.avg_value),
                        borderColor: '#1C4D8D',
                        backgroundColor: 'rgba(28, 77, 141, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Moving Avg (5-day)',
                        data: <?= json_encode($movingAverage) ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'transparent',
                        borderDash: [3, 3],
                        fill: false,
                        tension: 0.4,
                        pointRadius: 0
                    },
                    {
                        label: 'Trend Line',
                        data: trendData.map((d, i) => <?= $regression['slope'] ?> * i + <?= $regression['intercept'] ?>),
                        borderColor: '#8b5cf6',
                        backgroundColor: 'transparent',
                        borderDash: [8, 4],
                        fill: false,
                        pointRadius: 0
                    },
                    {
                        label: 'Min',
                        data: trendData.map(d => d.min_value),
                        borderColor: '#059669',
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0
                    },
                    {
                        label: 'Max',
                        data: trendData.map(d => d.max_value),
                        borderColor: '#dc2626',
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: false },
                    x: { grid: { display: false } }
                }
            }
        });
        
        // Monthly Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyData = <?= json_encode($monthlyData) ?>;
        
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyData.map(d => {
                    const [year, month] = d.month.split('-');
                    return new Date(year, month - 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Monthly Average',
                    data: monthlyData.map(d => d.avg_value),
                    backgroundColor: 'rgba(28, 77, 141, 0.8)',
                    borderColor: '#1C4D8D',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: false },
                    x: { grid: { display: false } }
                }
            }
        });
        
        // Section Comparison Chart
        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        const sectionData = <?= json_encode($sectionTrends) ?>;
        
        // Group by section
        const sections = [...new Set(sectionData.map(d => d.river_section))];
        const sectionDatasets = sections.map(section => ({
            label: section.charAt(0).toUpperCase() + section.slice(1),
            data: sectionData.filter(d => d.river_section === section).map(d => ({
                x: new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
                y: d.avg_value
            })),
            borderColor: section === 'upstream' ? '#16a34a' : section === 'midstream' ? '#d97706' : '#1C4D8D',
            backgroundColor: 'transparent',
            tension: 0.4,
            pointRadius: 3
        }));
        
        new Chart(sectionCtx, {
            type: 'line',
            data: {
                datasets: sectionDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: false },
                    x: { type: 'category', grid: { display: false } }
                }
            }
        });
        
        // Hourly Pattern Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const hourlyData = <?= json_encode($hourlyPattern) ?>;
        
        const hourlyAvg = hourlyData.length > 0 ? hourlyData.reduce((sum, h) => sum + h.avg_value, 0) / hourlyData.length : 0;
        
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: hourlyData.map(d => d.hour + ':00'),
                datasets: [{
                    label: 'Average Value',
                    data: hourlyData.map(d => d.avg_value),
                    backgroundColor: hourlyData.map(d => 
                        d.avg_value > hourlyAvg ? 'rgba(220, 38, 38, 0.7)' : 'rgba(28, 77, 141, 0.7)'
                    ),
                    borderColor: hourlyData.map(d => 
                        d.avg_value > hourlyAvg ? '#dc2626' : '#1C4D8D'
                    ),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: false },
                    x: { grid: { display: false } }
                }
            }
        });
    </script>
</body>
</html>
