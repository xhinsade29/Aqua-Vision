<?php
/**
 * Aqua-Vision — Device & Sensor History Page
 * Location: apps/admin/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("History Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// ── Helper Functions ───────────────────────────────────────────────────────────

/**
 * Get sensor reading history for a device
 */
function getDeviceReadingHistory($conn, $deviceId, $hours = 24) {
    $sql = "SELECT sr.reading_id, sr.value, sr.recorded_at, s.sensor_type, s.unit
            FROM sensor_readings sr
            JOIN sensors s ON s.sensor_id = sr.sensor_id
            WHERE s.device_id = ? AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sr.recorded_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $deviceId, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get alert history
 */
function getAlertHistory($conn, $limit = 50) {
    $sql = "SELECT a.alert_id, a.alert_type, a.message, a.status, a.created_at, 
                   a.acknowledged_at, a.resolved_at,
                   d.device_name, s.sensor_type, s.unit
            FROM alerts a
            JOIN sensors s ON s.sensor_id = a.sensor_id
            JOIN devices d ON d.device_id = s.device_id
            ORDER BY a.created_at DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get device status change history
 */
function getDeviceStatusHistory($conn, $limit = 50) {
    $sql = "SELECT d.device_id, d.device_name, d.status, d.device_condition, 
                   d.updated_at, d.last_active,
                   l.location_name, l.river_section
            FROM devices d
            LEFT JOIN locations l ON l.location_id = d.location_id
            ORDER BY d.updated_at DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get activity timeline combining readings, alerts, and status changes
 */
function getActivityTimeline($conn, $hours = 24) {
    $timeline = [];
    
    // Get recent sensor readings
    $readings = getDeviceReadingHistory($conn, null, $hours);
    foreach ($readings as $r) {
        $timeline[] = [
            'type' => 'reading',
            'timestamp' => $r['recorded_at'],
            'device_name' => null,
            'message' => sprintf("%s: %.2f %s", 
                ucfirst(str_replace('_', ' ', $r['sensor_type'])),
                $r['value'],
                $r['unit']
            ),
            'severity' => 'info',
            'data' => $r
        ];
    }
    
    // Get recent alerts
    $alerts = getAlertHistory($conn, 50);
    foreach ($alerts as $a) {
        if (strtotime($a['created_at']) >= strtotime("-$hours hours")) {
            $timeline[] = [
                'type' => 'alert',
                'timestamp' => $a['created_at'],
                'device_name' => $a['device_name'],
                'message' => $a['message'],
                'severity' => $a['alert_type'],
                'status' => $a['status'],
                'data' => $a
            ];
        }
    }
    
    // Sort by timestamp descending
    usort($timeline, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return array_slice($timeline, 0, 100);
}

// ── Get Data ─────────────────────────────────────────────────────────────────
$currentPage = 'history';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$deviceFilter = isset($_GET['device_id']) ? intval($_GET['device_id']) : null;

// Get all devices for filter dropdown
$devices = $conn->query("SELECT device_id, device_name, status FROM devices ORDER BY device_name")->fetch_all(MYSQLI_ASSOC);

// Get activity timeline
$timeline = getActivityTimeline($conn, $hoursFilter);

// Get alert statistics
$alertStats = $conn->query("SELECT 
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_alerts,
    SUM(CASE WHEN alert_type='critical' THEN 1 ELSE 0 END) as critical_alerts,
    SUM(CASE WHEN alert_type='high' THEN 1 ELSE 0 END) as high_alerts,
    SUM(CASE WHEN alert_type='low' THEN 1 ELSE 0 END) as low_alerts
FROM alerts")->fetch_assoc();

// Get reading statistics
$readingStats = $conn->query("SELECT COUNT(*) as total_readings,
    COUNT(DISTINCT sensor_id) as active_sensors,
    MAX(recorded_at) as last_reading
FROM sensor_readings 
WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL $hoursFilter HOUR)")->fetch_assoc();

?>
<?php include '../../assets/navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History | Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a56db;
            --primary-dark: #0e3a8a;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --radius: 8px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.6;
        }
        
        /* Layout */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: var(--gray-500);
            font-size: 0.875rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            border-left: 3px solid var(--primary);
        }
        
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.success { border-left-color: var(--success); }
        
        .stat-card h3 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .stat-card p {
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        /* Filters */
        .filters {
            background: white;
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.875rem;
            color: var(--gray-600);
            font-weight: 500;
        }
        
        .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.875rem;
            background: white;
            cursor: pointer;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            border: none;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        /* Timeline */
        .timeline {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .timeline-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .timeline-header h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .timeline-body {
            padding: 1.25rem;
        }
        
        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .timeline-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.25rem;
        }
        
        .timeline-icon.reading {
            background: var(--gray-100);
        }
        
        .timeline-icon.alert-low {
            background: #fef3c7;
        }
        
        .timeline-icon.alert-high {
            background: #fee2e2;
        }
        
        .timeline-icon.alert-critical {
            background: #fecaca;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-title {
            font-weight: 500;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        
        .timeline-desc {
            font-size: 0.875rem;
            color: var(--gray-600);
            margin-bottom: 0.25rem;
        }
        
        .timeline-meta {
            font-size: 0.75rem;
            color: var(--gray-400);
        }
        
        .timeline-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .timeline-badge.critical {
            background: #fecaca;
            color: #991b1b;
        }
        
        .timeline-badge.high {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .timeline-badge.low {
            background: #fef3c7;
            color: #92400e;
        }
        
        .timeline-badge.info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-400);
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1>📜 History & Activity Log</h1>
            <p>View sensor readings, alerts, and device activity over time</p>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card success">
                <h3><?= number_format($readingStats['total_readings'] ?? 0) ?></h3>
                <p>Sensor Readings (<?= $hoursFilter ?>h)</p>
            </div>
            <div class="stat-card warning">
                <h3><?= $alertStats['active_alerts'] ?? 0 ?></h3>
                <p>Active Alerts</p>
            </div>
            <div class="stat-card danger">
                <h3><?= $alertStats['critical_alerts'] ?? 0 ?></h3>
                <p>Critical Alerts</p>
            </div>
            <div class="stat-card">
                <h3><?= $alertStats['high_alerts'] ?? 0 ?></h3>
                <p>High Priority</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label>Time Range:</label>
                <select onchange="window.location.href='?hours='+this.value">
                    <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                    <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                    <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Device:</label>
                <select onchange="window.location.href='?hours=<?= $hoursFilter ?>&device_id='+this.value">
                    <option value="">All Devices</option>
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= $d['device_id'] ?>" <?= $deviceFilter == $d['device_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['device_name']) ?> (<?= $d['status'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="activitylog.php" class="btn btn-secondary">Reset Filters</a>
            <a href="?action=export&hours=<?= $hoursFilter ?>" class="btn btn-primary">📥 Export CSV</a>
        </div>
        
        <!-- Activity Timeline -->
        <div class="timeline">
            <div class="timeline-header">
                <h2>Activity Timeline</h2>
                <span style="font-size: 0.875rem; color: var(--gray-500);">
                    <?= count($timeline) ?> events
                </span>
            </div>
            <div class="timeline-body">
                <?php if (empty($timeline)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No activity recorded in the last <?= $hoursFilter ?> hours</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($timeline as $item): ?>
                        <div class="timeline-item">
                            <?php if ($item['type'] === 'reading'): ?>
                                <div class="timeline-icon reading">📊</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        Sensor Reading
                                        <span class="timeline-badge info">Normal</span>
                                    </div>
                                    <div class="timeline-desc"><?= htmlspecialchars($item['message']) ?></div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                    </div>
                                </div>
                            <?php elseif ($item['type'] === 'alert'): ?>
                                <div class="timeline-icon alert-<?= $item['severity'] ?>">⚠️</div>
                                <div class="timeline-content">
                                    <div class="timeline-title">
                                        Alert: <?= htmlspecialchars($item['device_name'] ?? 'Unknown') ?>
                                        <span class="timeline-badge <?= $item['severity'] ?>">
                                            <?= ucfirst($item['severity']) ?>
                                        </span>
                                        <?php if ($item['status'] === 'active'): ?>
                                            <span class="timeline-badge critical">Active</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-desc"><?= htmlspecialchars($item['message']) ?></div>
                                    <div class="timeline-meta">
                                        <?= date('M d, Y H:i:s', strtotime($item['timestamp'])) ?>
                                        <?php if ($item['status'] === 'acknowledged'): ?>
                                            • Acknowledged
                                        <?php elseif ($item['status'] === 'resolved'): ?>
                                            • Resolved
                                        <?php endif; ?>
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
        // Auto-refresh every 5 minutes
        setTimeout(() => window.location.reload(), 300000);
    </script>
</body>
</html>
