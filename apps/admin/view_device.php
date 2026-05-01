<?php
/**
 * View Device - Display all device records with time series data
 */

require_once '../../database/config.php';

// Check authentication
require_login();

// Get device ID from URL
$deviceId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$deviceId) {
    header('Location: devices.php');
    exit();
}

// Get device information
$deviceQuery = $conn->prepare("SELECT d.*, l.location_name, l.river_section, l.latitude, l.longitude 
    FROM devices d 
    LEFT JOIN locations l ON l.location_id = d.location_id 
    WHERE d.device_id = ?");
$deviceQuery->bind_param('i', $deviceId);
$deviceQuery->execute();
$deviceResult = $deviceQuery->get_result();
$device = $deviceResult->fetch_assoc();
$deviceQuery->close();

if (!$device) {
    header('Location: devices.php');
    exit();
}

// Get all sensor readings for this device (last 30 days)
$readingsQuery = $conn->prepare("SELECT sr.*, s.sensor_type, s.unit, s.min_threshold, s.max_threshold
    FROM sensor_readings sr
    JOIN sensors s ON s.sensor_id = sr.sensor_id
    WHERE s.device_id = ?
    ORDER BY sr.recorded_at DESC
    LIMIT 1000");
$readingsQuery->bind_param('i', $deviceId);
$readingsQuery->execute();
$readingsResult = $readingsQuery->get_result();
$readings = [];
while ($row = $readingsResult->fetch_assoc()) {
    $readings[] = $row;
}
$readingsQuery->close();

// Get activity history
$activities = [];
$activityQuery = $conn->prepare("SELECT sl.*, u.name as user_name FROM system_logs sl
    LEFT JOIN users u ON u.user_id = sl.user_id
    WHERE sl.details LIKE ? 
    ORDER BY sl.created_at DESC
    LIMIT 100");
if ($activityQuery) {
    $searchTerm = "%device ID: $deviceId%";
    $activityQuery->bind_param('s', $searchTerm);
    $activityQuery->execute();
    $activityResult = $activityQuery->get_result();
    while ($row = $activityResult->fetch_assoc()) {
        $activities[] = $row;
    }
    $activityQuery->close();
}

// Get alerts for this device (join through sensors table)
$alerts = [];
$alertsQuery = $conn->prepare("SELECT a.* FROM alerts a
    JOIN sensors s ON s.sensor_id = a.sensor_id
    WHERE s.device_id = ?
    ORDER BY a.created_at DESC
    LIMIT 50");
if ($alertsQuery) {
    $alertsQuery->bind_param('i', $deviceId);
    $alertsQuery->execute();
    $alertsResult = $alertsQuery->get_result();
    while ($row = $alertsResult->fetch_assoc()) {
        $alerts[] = $row;
    }
    $alertsQuery->close();
}

// Process readings for charts (group by sensor type)
$chartData = [
    'temperature' => [],
    'ph_level' => [],
    'turbidity' => [],
    'dissolved_oxygen' => [],
    'water_level' => [],
    'sediments' => []
];

foreach ($readings as $reading) {
    $type = $reading['sensor_type'];
    if (isset($chartData[$type])) {
        $chartData[$type][] = [
            'timestamp' => $reading['recorded_at'],
            'value' => $reading['value']
        ];
    }
}

// Calculate statistics
$stats = [];
foreach ($chartData as $type => $data) {
    if (count($data) > 0) {
        $values = array_column($data, 'value');
        $stats[$type] = [
            'count' => count($values),
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            'latest' => $data[0]['value'],
            'latest_time' => $data[0]['timestamp']
        ];
    }
}

$currentPage = 'devices';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Device - <?= htmlspecialchars($device['device_name']) ?> | Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root {
            --primary: #1a56db;
            --primary-dark: #1e429f;
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
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            color: var(--gray-600);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            background: var(--gray-50);
            color: var(--gray-800);
        }
        
        .card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            margin-bottom: 1rem;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        .device-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active { background: #d1fae5; color: #059669; }
        .status-inactive { background: #fee2e2; color: #dc2626; }
        .status-maintenance { background: #dbeafe; color: #3b82f6; }
        .status-offline { background: var(--gray-100); color: var(--gray-500); }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        
        .info-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            font-weight: 500;
        }
        
        .info-value {
            font-size: 0.875rem;
            color: var(--gray-800);
            font-weight: 600;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1rem;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .stat-card {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 1rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .data-table th {
            font-weight: 600;
            color: var(--gray-600);
            background: var(--gray-50);
            font-size: 0.75rem;
            text-transform: uppercase;
        }
        
        .data-table tr:hover {
            background: var(--gray-50);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #dbeafe; color: #1a56db; }
        
        .tab-container {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid var(--gray-200);
            padding: 0 1.25rem;
        }
        
        .tab {
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-500);
            background: none;
            border: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: var(--gray-700);
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        #deviceMap {
            height: 300px;
            border-radius: var(--radius);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <?php include '../../assets/navigation.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($device['device_name']) ?></h1>
                <p style="color: var(--gray-500); font-size: 0.875rem; margin-top: 0.25rem;">
                    Device ID: #<?= $deviceId ?> | Location: <?= htmlspecialchars($device['location_name'] ?? 'Unassigned') ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="devices.php?action=edit&id=<?= $deviceId ?>" class="back-btn" style="background: var(--primary); color: white; border-color: var(--primary);">Edit Device</a>
            </div>
        </div>
        
        <!-- Device Overview -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Device Overview</span>
                <span class="device-status status-<?= $device['status'] ?>">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: currentColor;"></span>
                    <?= ucfirst($device['status']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Device Name</span>
                        <span class="info-value"><?= htmlspecialchars($device['device_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value"><?= ucfirst($device['status']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value"><?= htmlspecialchars($device['location_name'] ?? '—') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">River Section</span>
                        <span class="info-value"><?= ucfirst($device['river_section'] ?? '—') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Coordinates</span>
                        <span class="info-value">
                            <?php if ($device['latitude'] && $device['longitude']): ?>
                                <?= number_format($device['latitude'], 6) ?>°N, <?= number_format($device['longitude'], 6) ?>°E
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Active</span>
                        <span class="info-value">
                            <?= $device['last_active'] ? date('M d, Y H:i', strtotime($device['last_active'])) : 'Never' ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Created</span>
                        <span class="info-value">
                            <?= $device['created_at'] ? date('M d, Y', strtotime($device['created_at'])) : '—' ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Total Records</span>
                        <span class="info-value"><?= number_format(count($readings)) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <?php if (!empty($stats)): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Sensor Statistics (Last 30 Days)</span>
            </div>
            <div class="card-body">
                <?php foreach ($stats as $type => $stat): ?>
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; font-weight: 600; color: var(--gray-700); margin-bottom: 0.75rem; text-transform: capitalize;">
                        <?= str_replace('_', ' ', $type) ?>
                    </h4>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?= number_format($stat['latest'], 2) ?></div>
                            <div class="stat-label">Latest Value</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= number_format($stat['avg'], 2) ?></div>
                            <div class="stat-label">Average</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= number_format($stat['min'], 2) ?></div>
                            <div class="stat-label">Minimum</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= number_format($stat['max'], 2) ?></div>
                            <div class="stat-label">Maximum</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= number_format($stat['count']) ?></div>
                            <div class="stat-label">Readings</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="card">
            <div class="tab-container">
                <button class="tab active" onclick="showTab('charts')">📊 Time Series Charts</button>
                <button class="tab" onclick="showTab('map')">🗺️ Device Location</button>
                <button class="tab" onclick="showTab('records')">📋 All Records</button>
                <button class="tab" onclick="showTab('alerts')">🚨 Alerts</button>
                <button class="tab" onclick="showTab('activity')">📜 Activity Log</button>
            </div>
            
            <!-- Charts Tab -->
            <div id="charts" class="tab-content active">
                <div class="card-body">
                    <?php if (!empty($readings)): ?>
                    <div class="charts-grid">
                        <?php foreach ($chartData as $type => $data): ?>
                            <?php if (!empty($data)): ?>
                            <div class="card" style="margin-bottom: 0;">
                                <div style="padding: 1rem; border-bottom: 1px solid var(--gray-200);">
                                    <h4 style="font-size: 0.875rem; font-weight: 600; color: var(--gray-700); text-transform: capitalize;">
                                        <?= str_replace('_', ' ', $type) ?> History
                                    </h4>
                                </div>
                                <div style="padding: 1rem;">
                                    <div class="chart-container">
                                        <canvas id="chart-<?= $type ?>"></canvas>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📊</div>
                        <p>No sensor data available for this device.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Map Tab -->
            <div id="map" class="tab-content">
                <div class="card-body">
                    <?php if ($device['latitude'] && $device['longitude']): ?>
                    <div id="deviceMap"></div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📍</div>
                        <p>No location data available for this device.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Records Tab -->
            <div id="records" class="tab-content">
                <div class="card-body" style="padding: 0;">
                    <?php if (!empty($readings)): ?>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Sensor Type</th>
                                    <th>Value</th>
                                    <th>Unit</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($readings as $reading): ?>
                                <?php
                                $isNormal = $reading['value'] >= $reading['min_threshold'] && $reading['value'] <= $reading['max_threshold'];
                                $statusClass = $isNormal ? 'badge-success' : 'badge-warning';
                                $statusText = $isNormal ? 'Normal' : ($reading['value'] < $reading['min_threshold'] ? 'Low' : 'High');
                                ?>
                                <tr>
                                    <td><?= date('M d, Y H:i:s', strtotime($reading['recorded_at'])) ?></td>
                                    <td><?= ucfirst(str_replace('_', ' ', $reading['sensor_type'])) ?></td>
                                    <td style="font-weight: 600;"><?= number_format($reading['value'], 2) ?></td>
                                    <td><?= $reading['unit'] ?></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>No records available for this device.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Alerts Tab -->
            <div id="alerts" class="tab-content">
                <div class="card-body" style="padding: 0;">
                    <?php if (!empty($alerts)): ?>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Message</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alerts as $alert): ?>
                                <tr>
                                    <td><?= date('M d, Y H:i', strtotime($alert['created_at'])) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $alert['alert_type'] === 'critical' ? 'danger' : ($alert['alert_type'] === 'warning' ? 'warning' : 'info') ?>">
                                            <?= ucfirst($alert['alert_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($alert['message']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $alert['status'] === 'active' ? 'danger' : 'success' ?>">
                                            <?= ucfirst($alert['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">🚨</div>
                        <p>No alerts for this device.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Activity Tab -->
            <div id="activity" class="tab-content">
                <div class="card-body" style="padding: 0;">
                    <?php if (!empty($activities)): ?>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?= date('M d, Y H:i', strtotime($activity['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($activity['action']) ?></td>
                                    <td><?= htmlspecialchars($activity['details']) ?></td>
                                    <td><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📜</div>
                        <p>No activity records for this device.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        function showTab(tabId) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabId).classList.add('active');
            
            // Initialize map when map tab is shown
            if (tabId === 'map' && !window.deviceMapInitialized) {
                initMap();
            }
        }
        
        // Initialize map
        function initMap() {
            <?php if ($device['latitude'] && $device['longitude']): ?>
            const lat = <?= $device['latitude'] ?>;
            const lng = <?= $device['longitude'] ?>;
            
            const map = L.map('deviceMap', {
                attributionControl: false
            }).setView([lat, lng], 14);
            
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                attribution: '',
                subdomains: 'abcd'
            }).addTo(map);
            
            L.marker([lat, lng]).addTo(map)
                .bindPopup('<?= htmlspecialchars($device['device_name']) ?>')
                .openPopup();
            
            window.deviceMapInitialized = true;
            <?php endif; ?>
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($chartData as $type => $data): ?>
                <?php if (!empty($data)): ?>
                const ctx<?= str_replace('_', '', $type) ?> = document.getElementById('chart-<?= $type ?>')?.getContext('2d');
                if (ctx<?= str_replace('_', '', $type) ?>) {
                    new Chart(ctx<?= str_replace('_', '', $type) ?>, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode(array_map(fn($d) => date('M d H:i', strtotime($d['timestamp'])), array_slice($data, 0, 50))) ?>,
                            datasets: [{
                                label: '<?= ucfirst(str_replace('_', ' ', $type)) ?>',
                                data: <?= json_encode(array_map(fn($d) => $d['value'], array_slice($data, 0, 50))) ?>,
                                borderColor: '<?= 
                                    $type === 'temperature' ? '#ef4444' : 
                                    ($type === 'ph_level' ? '#3b82f6' : 
                                    ($type === 'turbidity' ? '#d97706' : 
                                    ($type === 'dissolved_oxygen' ? '#10b981' : 
                                    ($type === 'water_level' ? '#8b5cf6' : '#92400e')))) 
                                ?>',
                                backgroundColor: '<?= 
                                    $type === 'temperature' ? '#ef444420' : 
                                    ($type === 'ph_level' ? '#3b82f620' : 
                                    ($type === 'turbidity' ? '#d9770620' : 
                                    ($type === 'dissolved_oxygen' ? '#10b98120' : 
                                    ($type === 'water_level' ? '#8b5cf620' : '#92400e20')))) 
                                ?>',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 3,
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                x: {
                                    display: true,
                                    ticks: {
                                        maxTicksLimit: 10,
                                        font: { size: 10 }
                                    }
                                },
                                y: {
                                    display: true,
                                    ticks: {
                                        font: { size: 10 }
                                    }
                                }
                            }
                        }
                    });
                }
                <?php endif; ?>
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
