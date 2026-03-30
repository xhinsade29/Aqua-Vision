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

// Calculate trend direction
$trendDirection = 'stable';
if (count($trendData) >= 2) {
    $firstHalf = array_slice($trendData, 0, intval(count($trendData) / 2));
    $secondHalf = array_slice($trendData, intval(count($trendData) / 2));
    $firstAvg = array_sum(array_column($firstHalf, 'avg_value')) / count($firstHalf);
    $secondAvg = array_sum(array_column($secondHalf, 'avg_value')) / count($secondHalf);
    
    $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;
    if ($change > 5) $trendDirection = 'increasing';
    elseif ($change < -5) $trendDirection = 'decreasing';
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
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 28px; font-weight: 700; color: var(--c1); margin-top: 8px; }
        .stat-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid, .content-grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
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
                <div class="stat-value" style="font-size: 24px;">
                    <?php if ($trendDirection === 'increasing'): ?>
                        <span style="color: var(--crit);">↗</span> Increasing
                    <?php elseif ($trendDirection === 'decreasing'): ?>
                        <span style="color: var(--good);">↘</span> Decreasing
                    <?php else: ?>
                        <span style="color: var(--warn);">→</span> Stable
                    <?php endif; ?>
                </div>
                <span class="trend-indicator trend-<?= $trendDirection ?>">
                    <?= round(abs($change ?? 0), 1) ?>% change
                </span>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average Value</div>
                <div class="stat-value">
                    <?= count($trendData) > 0 ? round(array_sum(array_column($trendData, 'avg_value')) / count($trendData), 2) : 'N/A' ?>
                </div>
                <div class="stat-sub"><?= $unit ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Data Points</div>
                <div class="stat-value"><?= number_format(array_sum(array_column($trendData, 'reading_count'))) ?></div>
                <div class="stat-sub">Readings analyzed</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Peak Value</div>
                <div class="stat-value">
                    <?= count($trendData) > 0 ? round(max(array_column($trendData, 'max_value')), 2) : 'N/A' ?>
                </div>
                <div class="stat-sub"><?= $unit ?></div>
            </div>
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
    </script>
</body>
</html>
