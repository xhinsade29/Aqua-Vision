<?php
/**
 * Aqua-Vision — Researcher Activity Log
 * View historical sensor data and trends
 * Location: apps/researcher/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Researcher Activity Error [$errno]: $errstr in $errfile:$errline");
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

function getActivityTimeline($conn, $hours = 24, $deviceId = null, $sensorType = null) {
    $timeline = [];
    
    // Get sensor readings
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
    
    if ($deviceId) {
        $sql .= " AND d.device_id = ?";
        $params[] = $deviceId;
        $types .= "i";
    }
    
    if ($sensorType) {
        $sql .= " AND s.sensor_type = ?";
        $params[] = $sensorType;
        $types .= "s";
    }
    
    $sql .= " ORDER BY sr.recorded_at DESC LIMIT 500";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $readings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($readings as $r) {
        $status = 'normal';
        if ($r['value'] < $r['min_threshold'] || $r['value'] > $r['max_threshold']) {
            $status = 'out_of_range';
        }
        
        $timeline[] = [
            'type' => 'reading',
            'timestamp' => $r['recorded_at'],
            'device_name' => $r['device_name'],
            'location_name' => $r['location_name'],
            'river_section' => $r['river_section'],
            'sensor_type' => $r['sensor_type'],
            'value' => $r['value'],
            'unit' => $r['unit'],
            'status' => $status,
            'min_threshold' => $r['min_threshold'],
            'max_threshold' => $r['max_threshold']
        ];
    }
    
    // Get alerts
    $alertSql = "SELECT a.alert_id, a.alert_type, a.message, a.status, a.created_at,
                        d.device_name, s.sensor_type, l.river_section
                 FROM alerts a
                 JOIN sensors s ON s.sensor_id = a.sensor_id
                 JOIN devices d ON d.device_id = s.device_id
                 LEFT JOIN locations l ON l.location_id = d.location_id
                 WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
    
    $alertParams = [$hours];
    $alertTypes = "i";
    
    if ($deviceId) {
        $alertSql .= " AND d.device_id = ?";
        $alertParams[] = $deviceId;
        $alertTypes .= "i";
    }
    
    if ($sensorType) {
        $alertSql .= " AND s.sensor_type = ?";
        $alertParams[] = $sensorType;
        $alertTypes .= "s";
    }
    
    $alertSql .= " ORDER BY a.created_at DESC LIMIT 100";
    
    $stmt = $conn->prepare($alertSql);
    $stmt->bind_param($alertTypes, ...$alertParams);
    $stmt->execute();
    $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($alerts as $a) {
        $timeline[] = [
            'type' => 'alert',
            'timestamp' => $a['created_at'],
            'device_name' => $a['device_name'],
            'location_name' => $a['river_section'] ? ucfirst($a['river_section']) : 'Unknown',
            'river_section' => $a['river_section'],
            'sensor_type' => $a['sensor_type'],
            'alert_type' => $a['alert_type'],
            'message' => $a['message'],
            'status' => $a['status']
        ];
    }
    
    // Sort by timestamp descending
    usort($timeline, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return array_slice($timeline, 0, 200);
}

function getStatistics($conn, $hours = 24) {
    $stats = [];
    
    // Total readings
    $result = $conn->query("SELECT COUNT(*) as cnt FROM sensor_readings WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $hours HOUR)");
    $stats['total_readings'] = $result->fetch_assoc()['cnt'];
    
    // Active sensors
    $result = $conn->query("SELECT COUNT(DISTINCT sensor_id) as cnt FROM sensor_readings WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $hours HOUR)");
    $stats['active_sensors'] = $result->fetch_assoc()['cnt'];
    
    // Alerts
    $result = $conn->query("SELECT 
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_alerts,
        SUM(CASE WHEN alert_type='critical' THEN 1 ELSE 0 END) as critical_alerts,
        SUM(CASE WHEN alert_type='high' THEN 1 ELSE 0 END) as high_alerts
    FROM alerts WHERE created_at >= DATE_SUB(NOW(), INTERVAL $hours HOUR)");
    $stats['alerts'] = $result->fetch_assoc();
    
    // Last reading
    $result = $conn->query("SELECT MAX(recorded_at) as last FROM sensor_readings");
    $stats['last_reading'] = $result->fetch_assoc()['last'];
    
    return $stats;
}

// ── API: Fetch Data ──────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'fetch') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    
    $hours = intval($_GET['hours'] ?? 24);
    $deviceId = intval($_GET['device_id'] ?? 0) ?: null;
    $sensorType = $_GET['sensor_type'] ?? null;
    
    $timeline = getActivityTimeline($conn, $hours, $deviceId, $sensorType);
    $stats = getStatistics($conn, $hours);
    
    echo json_encode([
        'ok' => true,
        'timeline' => $timeline,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_NUMERIC_CHECK);
    exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'history';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$deviceFilter = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;
$sensorFilter = $_GET['sensor_type'] ?? null;

// Get filter options
$devices = $conn->query("SELECT device_id, device_name, status FROM devices ORDER BY device_name")->fetch_all(MYSQLI_ASSOC);
$sensorTypesResult = $conn->query("SELECT DISTINCT sensor_type FROM sensors WHERE sensor_type NOT IN ('humidity', 'pressure', 'flow_rate') ORDER BY sensor_type");
$sensorTypes = [];
while ($row = $sensorTypesResult->fetch_assoc()) {
    $sensorTypes[] = $row['sensor_type'];
}
if (empty($sensorTypes)) {
    $sensorTypes = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
}

// Get data
$timeline = getActivityTimeline($conn, $hoursFilter, $deviceFilter, $sensorFilter);
$stats = getStatistics($conn, $hoursFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log — Aqua-Vision Researcher</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
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
        
        /* Stats Grid */
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
        
        /* Filters */
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
        
        /* Timeline */
        .timeline-card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
        }
        .timeline-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .timeline-body {
            max-height: 600px; overflow-y: auto;
        }
        
        .timeline-item {
            display: flex; gap: 16px; padding: 16px 20px;
            border-bottom: 1px solid var(--border); transition: background 0.2s;
        }
        .timeline-item:hover { background: var(--bg); }
        .timeline-item:last-child { border-bottom: none; }
        
        .timeline-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .timeline-icon.reading { background: #dbeafe; }
        .timeline-icon.alert-critical { background: var(--crit-bg); color: var(--crit); }
        .timeline-icon.alert-high { background: var(--warn-bg); color: var(--warn); }
        
        .timeline-content { flex: 1; min-width: 0; }
        .timeline-title {
            font-size: 14px; font-weight: 600; color: var(--c1);
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .timeline-desc { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .timeline-meta {
            font-size: 11px; color: var(--text3); margin-top: 6px;
            display: flex; gap: 16px; flex-wrap: wrap;
        }
        .timeline-meta span { display: flex; align-items: center; gap: 4px; }
        
        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 500;
        }
        .badge-normal { background: var(--good-bg); color: var(--good); }
        .badge-warning { background: var(--warn-bg); color: var(--warn); }
        .badge-critical { background: var(--crit-bg); color: var(--crit); }
        .badge-section {
            padding: 2px 8px; border-radius: 4px;
            font-size: 10px; text-transform: uppercase;
        }
        .badge-upstream { background: #dbeafe; color: #1e40af; }
        .badge-midstream { background: #fef3c7; color: #92400e; }
        .badge-downstream { background: #fee2e2; color: #991b1b; }
        
        /* Empty State */
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-state-title { font-size: 18px; font-weight: 600; color: var(--c1); margin-bottom: 8px; }
        
        /* Value Display */
        .value-display {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px; font-weight: 600; color: var(--c1);
        }
        .value-unit { font-size: 12px; color: var(--text3); font-weight: 400; }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .main-content { padding: 16px 20px; }
            .card { padding: 16px; }
            .filter-bar { flex-direction: column; align-items: flex-start; gap: 8px; }
            .filter-bar .btn { width: 100%; justify-content: center; }
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
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Research Activity Log</h1>
                <p class="page-subtitle">Track sensor readings and water quality data for research analysis</p>
            </div>
            <a href="?action=export&format=csv&hours=<?= $hoursFilter ?>" class="btn btn-primary">
                📥 Export Data
            </a>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Readings</div>
                <div class="stat-value"><?= number_format($stats['total_readings']) ?></div>
                <div class="stat-sub">Last <?= $hoursFilter ?> hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Sensors</div>
                <div class="stat-value"><?= number_format($stats['active_sensors']) ?></div>
                <div class="stat-sub">Reporting data</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Alerts</div>
                <div class="stat-value" style="color: <?= $stats['alerts']['active_alerts'] > 0 ? 'var(--crit)' : 'var(--good)' ?>">
                    <?= number_format($stats['alerts']['active_alerts'] ?? 0) ?>
                </div>
                <div class="stat-sub">Critical: <?= $stats['alerts']['critical_alerts'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Last Reading</div>
                <div class="stat-value" style="font-size: 18px; margin-top: 12px;">
                    <?= $stats['last_reading'] ? date('M d, H:i', strtotime($stats['last_reading'])) : 'Never' ?>
                </div>
                <div class="stat-sub"><?= timeAgo($stats['last_reading']) ?></div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Time Range:</span>
                <select class="filter-select" onchange="updateFilter('hours', this.value)">
                    <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                    <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                    <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Device:</span>
                <select class="filter-select" onchange="updateFilter('device_id', this.value)">
                    <option value="">All Devices</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['device_id'] ?>" <?= $deviceFilter == $d['device_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['device_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Sensor:</span>
                <select class="filter-select" onchange="updateFilter('sensor_type', this.value)">
                    <option value="">All Sensors</option>
                    <?php foreach ($sensorTypes as $t): ?>
                        <option value="<?= $t ?>" <?= $sensorFilter === $t ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $t)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="activitylog.php" class="btn btn-outline">Reset</a>
        </div>
        
        <!-- Timeline -->
        <div class="timeline-card">
            <div class="timeline-header">
                <span class="card-title">Activity Timeline</span>
                <span style="font-size: 13px; color: var(--text3);">
                    <?= count($timeline) ?> events
                </span>
            </div>
            <div class="timeline-body">
                <?php if (empty($timeline)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <div class="empty-state-title">No Activity</div>
                        <p style="color: var(--text2);">No sensor readings or alerts in the selected time range</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($timeline as $item): ?>
                        <div class="timeline-item">
                            <?php if ($item['type'] === 'reading'): ?>
                                <div class="timeline-icon reading">📊</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= ucfirst(str_replace('_', ' ', $item['sensor_type'])) ?> Reading
                                        <?php if ($item['status'] === 'out_of_range'): ?>
                                            <span class="badge badge-warning">⚠ Out of Range</span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">✓ Normal</span>
                                        <?php endif; ?>
                                        <?php if ($item['river_section']): ?>
                                            <span class="badge-section badge-<?= $item['river_section'] ?>">
                                                <?= ucfirst($item['river_section']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-desc">
                                        <span class="value-display">
                                            <?= round($item['value'], 2) ?>
                                            <span class="value-unit"><?= $item['unit'] ?></span>
                                        </span>
                                        <span style="color: var(--text3); margin-left: 8px;">
                                            (Range: <?= $item['min_threshold'] ?> - <?= $item['max_threshold'] ?>)
                                        </span>
                                    </div>
                                    <div class="timeline-meta">
                                        <span>📍 <?= htmlspecialchars($item['location_name'] ?? 'Unknown') ?></span>
                                        <span>🔧 <?= htmlspecialchars($item['device_name']) ?></span>
                                        <span>🕐 <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="timeline-icon alert-<?= $item['alert_type'] ?>">⚠️</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        <?= ucfirst($item['alert_type']) ?> Alert
                                        <?php if ($item['status'] === 'active'): ?>
                                            <span class="badge badge-critical">● Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-normal">Resolved</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-desc"><?= htmlspecialchars($item['message']) ?></div>
                                    <div class="timeline-meta">
                                        <span>📍 <?= ucfirst($item['river_section'] ?? 'Unknown') ?></span>
                                        <span>🔧 <?= htmlspecialchars($item['device_name']) ?></span>
                                        <span>🕐 <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function updateFilter(key, value) {
            const url = new URL(window.location.href);
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
            window.location.href = url.toString();
        }
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            fetch('?action=fetch&hours=<?= $hoursFilter ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        // Could update stats dynamically here
                    }
                })
                .catch(console.error);
        }, 30000);
    </script>
</body>
</html>
<?php
function timeAgo($datetime) {
    if (!$datetime) return 'No data';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return floor($diff / 86400) . ' days ago';
}
?>
