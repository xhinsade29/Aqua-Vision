<?php
/**
 * Aqua-Vision — Public Viewer Landing Page
 * Read-only access synced with admin data
 * This is the first route before login
 */

require_once 'database/config.php';

// Fetch data using same queries as admin dashboard
$locations = [];
$recentReadings = [];
$activeAlerts = [];
$deviceReadings = [];
$mapLocations = [];
$riverStatus = 'Normal';

// Get all locations with river sections
$locResult = $conn->query("SELECT l.location_id, l.location_name, l.latitude, l.longitude, 
                                  l.description, l.river_section,
                                  COUNT(d.device_id) as device_count,
                                  SUM(d.status='active') as active_devices
                           FROM locations l
                           LEFT JOIN devices d ON d.location_id = l.location_id
                           GROUP BY l.location_id");
if ($locResult) {
    $locations = $locResult->fetch_all(MYSQLI_ASSOC);
}

// Get active devices with readings (matching admin query exactly)
$devicesRes = $conn->query("SELECT d.device_id,d.device_name,d.status,d.last_active,l.location_name,l.river_section,l.latitude,l.longitude,l.location_id FROM devices d LEFT JOIN locations l ON l.location_id=d.location_id WHERE d.status='active' ORDER BY l.river_section,d.device_name");
$devices = [];
$mapLocations = [];
$locationDevices = [];
if ($devicesRes) {
    while ($r = $devicesRes->fetch_assoc()) {
        $devices[] = $r;
        // Build map locations from device data (same as admin)
        if ($r['location_id'] && !isset($locationDevices[$r['location_id']])) {
            $locationDevices[$r['location_id']] = [];
            $mapLocations[] = [
                'location_id' => $r['location_id'],
                'location_name' => $r['location_name'],
                'latitude' => $r['latitude'],
                'longitude' => $r['longitude'],
                'river_section' => $r['river_section']
            ];
        }
        if ($r['location_id']) {
            $locationDevices[$r['location_id']][] = $r;
        }
    }
}

// Get latest readings per device
foreach ($devices as $dev) {
    $did = (int)$dev['device_id'];
    $r = $conn->query("SELECT s.sensor_type, sr.value, sr.recorded_at, s.unit
                      FROM sensor_readings sr
                      JOIN sensors s ON s.sensor_id = sr.sensor_id
                      WHERE s.device_id = $did
                      ORDER BY sr.recorded_at DESC
                      LIMIT 10");
    $latest = [];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if (!isset($latest[$row['sensor_type']])) {
                $latest[$row['sensor_type']] = $row;
            }
        }
    }
    if (!empty($latest)) {
        $deviceReadings[] = [
            'device_name' => $dev['device_name'],
            'location_name' => $dev['location_name'],
            'river_section' => $dev['river_section'],
            'readings' => $latest
        ];
    }
}

// Get active alerts with river sections
$alertResult = $conn->query("SELECT a.alert_id, a.alert_type, a.message, a.created_at,
                                    d.device_name, l.location_name, l.river_section, s.sensor_type
                             FROM alerts a
                             JOIN sensors s ON s.sensor_id = a.sensor_id
                             JOIN devices d ON d.device_id = s.device_id
                             JOIN locations l ON l.location_id = d.location_id
                             WHERE a.status = 'active'
                             ORDER BY a.created_at DESC");
if ($alertResult) {
    $activeAlerts = $alertResult->fetch_all(MYSQLI_ASSOC);
}

// Calculate river status
$warnCount = count($activeAlerts);
$riverStatus = $warnCount === 0 ? 'Normal' : ($warnCount <= 2 ? 'Moderate' : 'Critical');

// Stats
$totalLocations = count($locations);
$totalDevices = count($devices);
$totalAlerts = count($activeAlerts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aqua-Vision — Public Water Quality Viewer</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); min-height: 100vh; }
        .header { background: var(--c1); padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-logo { display: flex; align-items: center; gap: 12px; color: white; text-decoration: none; }
        .header-logo h1 { font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 700; }
        .header-logo span { font-size: 13px; opacity: 0.8; background: var(--c2); padding: 4px 10px; border-radius: 20px; }
        .login-btn { background: var(--c3); color: white; padding: 10px 20px; border-radius: var(--radius-sm); text-decoration: none; font-size: 14px; font-weight: 500; }
        .login-btn:hover { background: var(--c4); color: var(--c1); }
        .container { padding: 24px; max-width: 1400px; margin: 0 auto; }
        .river-banner { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 24px; margin-bottom: 24px; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 20px; }
        .banner-status-dot { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .banner-title { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 600; color: var(--text); }
        .banner-sub { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .banner-stats { display: flex; gap: 0; }
        .bstat { padding: 0 20px; text-align: center; border-left: 1px solid var(--border); }
        .bstat:first-child { border-left: none; }
        .bstat-v { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 600; color: var(--c1); }
        .bstat-l { font-size: 11px; color: var(--text3); }
        .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .card { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 15px; font-weight: 600; color: var(--c1); display: flex; align-items: center; gap: 8px; }
        .card-body { padding: 0; max-height: 400px; overflow-y: auto; }
        #map { height: 400px; width: 100%; }
        .data-item { display: flex; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--border); }
        .data-item:last-child { border-bottom: none; }
        .data-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .data-icon.reading { background: var(--good-bg); color: var(--good); }
        .data-icon.alert { background: var(--crit-bg); color: var(--crit); }
        .data-icon.location { background: var(--c4); color: var(--c1); }
        .data-content { flex: 1; }
        .data-title { font-size: 14px; font-weight: 600; color: var(--c1); }
        .data-desc { font-size: 13px; color: var(--text2); margin-top: 2px; }
        .data-meta { font-size: 11px; color: var(--text3); margin-top: 4px; display: flex; gap: 12px; flex-wrap: wrap; }
        .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 500; }
        .badge.warning { background: var(--warn-bg); color: var(--warn); }
        .badge.critical { background: var(--crit-bg); color: var(--crit); }
        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 16px; font-weight: 600; color: var(--c1); }
        .footer { background: var(--c1); color: white; padding: 24px; text-align: center; margin-top: 40px; }
        .footer-text { font-size: 13px; opacity: 0.8; }
        .sim-layout { display: flex; gap: 16px; height: 500px; }
        .sim-layout > div:first-child { flex: 1; }
        .sim-layout > div:last-child { width: 320px; }
        .map-legend { display: flex; gap: 16px; padding: 12px 20px; background: var(--bg); border-top: 1px solid var(--border); font-size: 12px; color: var(--text2); }
        .leg { display: flex; align-items: center; gap: 6px; }
        .leg-dot { width: 10px; height: 10px; border-radius: 50%; }
        .sim-stats-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
        .sim-stat-card { padding: 12px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); text-align: center; }
        .sim-stat-card.wide { grid-column: span 2; }
        .sim-stat-l { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.04em; }
        .sim-stat-v { font-size: 20px; font-weight: 700; font-family: 'Space Grotesk', sans-serif; }
        .sim-log-label { font-size: 11px; font-weight: 600; color: var(--text3); text-transform: uppercase; letter-spacing: 0.08em; }
        .dev-panel { padding: 16px; min-height: 200px; }
        .dev-panel-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; color: var(--text3); }
        .empty-icon { font-size: 48px; margin-bottom: 12px; }
        .tag-good { background: #dcfce7; color: #16a34a; }
        .tag-warn { background: #fef3c7; color: #d97706; }
        .tag-crit { background: #fee2e2; color: #dc2626; }
        @media (max-width: 768px) { .content-grid { grid-template-columns: 1fr; } .river-banner { grid-template-columns: 1fr; text-align: center; } .banner-stats { justify-content: center; } .sim-layout { flex-direction: column; height: auto; } .sim-layout > div:last-child { width: 100%; } }
    </style>
</head>
<body>
    <header class="header">
        <a href="/Aqua-Vision/" class="header-logo">
            <span>💧</span>
            <h1>Aqua-Vision</h1>
            <span>Public Viewer</span>
        </a>
        <a href="/Aqua-Vision/login.php" class="login-btn">🔐 Staff Login</a>
    </header>
    <div class="container">
        <!-- River Status Banner -->
        <div class="river-banner" style="border-left: 4px solid <?= $riverStatus === 'Normal' ? '#16a34a' : ($riverStatus === 'Moderate' ? '#d97706' : '#dc2626') ?>;">
            <div class="banner-status-dot" style="background: <?= $riverStatus === 'Normal' ? '#16a34a20' : ($riverStatus === 'Moderate' ? '#d9770620' : '#dc262620') ?>;">
                <?= $riverStatus === 'Normal' ? '✅' : ($riverStatus === 'Moderate' ? '⚠️' : '🚨') ?>
            </div>
            <div>
                <div class="banner-title">Mangima River Water Quality — <?= $riverStatus ?></div>
                <div class="banner-sub">Real-time monitoring across upstream, midstream, and downstream sections</div>
            </div>
            <div class="banner-stats">
                <div class="bstat"><div class="bstat-v"><?= $totalLocations ?></div><div class="bstat-l">Locations</div></div>
                <div class="bstat"><div class="bstat-v"><?= $totalDevices ?></div><div class="bstat-l">Devices</div></div>
                <div class="bstat"><div class="bstat-v" style="color: <?= $totalAlerts > 0 ? 'var(--crit)' : 'var(--good)' ?>"><?= $totalAlerts ?></div><div class="bstat-l">Alerts</div></div>
            </div>
        </div>
        <!-- River Flow Illustration -->
        <div style="display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 20px; padding: 16px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
            <div style="display: flex; align-items: center; flex: 1;">
                <div style="flex: 1; text-align: center; padding: 12px; background: linear-gradient(135deg, #05966920, #05966910); border-radius: var(--radius-sm) 0 0 var(--radius-sm); border: 2px solid #059669;">
                    <div style="font-size: 24px; margin-bottom: 4px;">🏔️</div>
                    <div style="font-size: 13px; font-weight: 600; color: #059669;">UPSTREAM</div>
                    <div style="font-size: 11px; color: var(--text3);">River Source</div>
                </div>
                <div style="width: 30px; height: 4px; background: linear-gradient(90deg, #059669, #d97706);"></div>
                <div style="flex: 1; text-align: center; padding: 12px; background: linear-gradient(135deg, #d9770620, #d9770610); border: 2px solid #d97706; border-left: none; border-right: none;">
                    <div style="font-size: 24px; margin-bottom: 4px;">🌊</div>
                    <div style="font-size: 13px; font-weight: 600; color: #d97706;">MIDSTREAM</div>
                    <div style="font-size: 11px; color: var(--text3);">Main Flow</div>
                </div>
                <div style="width: 30px; height: 4px; background: linear-gradient(90deg, #d97706, #dc2626);"></div>
                <div style="flex: 1; text-align: center; padding: 12px; background: linear-gradient(135deg, #dc262620, #dc262610); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; border: 2px solid #dc2626;">
                    <div style="font-size: 24px; margin-bottom: 4px;">🌅</div>
                    <div style="font-size: 13px; font-weight: 600; color: #dc2626;">DOWNSTREAM</div>
                    <div style="font-size: 11px; color: var(--text3);">River Mouth</div>
                </div>
            </div>
        </div>
        <!-- Map -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header" style="background: linear-gradient(135deg, var(--c4), white);">
                <span class="card-title">🗺️ Monitoring Locations — Active Devices</span>
                <span class="badge" style="background: var(--good-bg); color: var(--good);">● Live</span>
            </div>
            <div class="sim-layout">
                <div>
                    <div id="map" style="height: 420px;"></div>
                    <div class="map-legend">
                        <div class="leg"><span class="leg-dot" style="background:#059669"></span>Upstream</div>
                        <div class="leg"><span class="leg-dot" style="background:#d97706"></span>Midstream</div>
                        <div class="leg"><span class="leg-dot" style="background:#dc2626"></span>Downstream</div>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;padding:12px">
                    <div class="sim-stats-2">
                        <div class="sim-stat-card"><div class="sim-stat-l">Readings</div><div id="statReadings" class="sim-stat-v" style="color:#7c3aed"><?= count($deviceReadings) ?></div></div>
                        <div class="sim-stat-card"><div class="sim-stat-l">Alerts</div><div id="statAlerts" class="sim-stat-v" style="color:var(--warn)"><?= $totalAlerts ?></div></div>
                        <div class="sim-stat-card wide"><div class="sim-stat-l">Last Update</div><div style="font-family:var(--mono);font-size:13px;color:var(--text);margin-top:4px"><?= date('M d, H:i') ?></div></div>
                        <div class="sim-stat-card wide"><div class="sim-stat-l">Status</div><div style="font-size:11px;color:var(--good);font-family:var(--mono);margin-top:4px;line-height:1.5">● Live Monitoring</div></div>
                    </div>
                    <div style="display:flex;flex-direction:column;height:280px">
                        <div class="sim-log-label" style="margin-bottom:6px;flex-shrink:0">Recent Activity</div>
                        <div id="activityLog" style="height:250px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:var(--radius);padding:.75rem;overflow-y:auto;font-family:var(--mono);font-size:10.5px;line-height:1.6">
                            <?php if (!empty($deviceReadings)): ?>
                                <?php foreach (array_slice($deviceReadings, 0, 5) as $dev): ?>
                                    <?php $firstReading = reset($dev['readings']); ?>
                                    <div style="margin-bottom:8px;padding:6px;background:white;border-radius:4px;border-left:3px solid var(--good);">
                                        <div style="font-weight:600;color:var(--c1);"><?= htmlspecialchars($dev['device_name']) ?></div>
                                        <div style="color:var(--text3);">📍 <?= htmlspecialchars($dev['location_name']) ?></div>
                                        <div style="color:var(--text2);margin-top:2px;">
                                            <?php if ($firstReading): ?>
                                                <?= ucfirst(array_key_first($dev['readings'])) ?>: <?= number_format($firstReading['value'], 2) ?> <?= $firstReading['unit'] ?> 
                                                <span style="color:var(--text3);">• <?= date('H:i', strtotime($firstReading['recorded_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="color:var(--text3);text-align:center;padding:20px;">No recent readings available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Device Panel -->
        <div class="card" style="display:flex;flex-direction:column;">
            <div class="card-header">
                <div class="card-title">Device Sensor Data</div>
                <span class="badge" style="background: var(--good-bg); color: var(--good);">📡 <?= $totalDevices ?></span>
            </div>
            <div id="deviceDataDisplay" class="dev-panel">
                <div class="dev-panel-empty">
                    <div class="empty-icon">📡</div>
                    <p>Select a device to view real-time sensor readings</p>
                </div>
            </div>
        </div>
        <div class="content-grid">
            <!-- Latest Device Readings -->
            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, var(--good-bg), white);">
                    <span class="card-title" style="color: var(--good);">📊 Latest Sensor Readings</span>
                </div>
                <div class="card-body">
                    <?php if (empty($deviceReadings)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📈</div>
                            <div class="empty-state-title">No Device Data</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($deviceReadings as $device): ?>
                            <div class="data-item">
                                <div class="data-icon reading">📡</div>
                                <div class="data-content">
                                    <div class="data-title"><?= htmlspecialchars($device['device_name']) ?></div>
                                    <div class="data-desc">📍 <?= htmlspecialchars($device['location_name']) ?> — <?= ucfirst($device['river_section'] ?? 'unknown') ?></div>
                                    <div class="data-meta">
                                        <?php foreach ($device['readings'] as $sensor => $reading): ?>
                                            <span><?= ucfirst($sensor) ?>: <?= number_format($reading['value'], 2) ?> <?= $reading['unit'] ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Active Alerts -->
            <div class="card">
                <div class="card-header" style="background: linear-gradient(135deg, var(--crit-bg), white);">
                    <span class="card-title" style="color: var(--crit);">🚨 Active Alerts</span>
                </div>
                <div class="card-body">
                    <?php if (empty($activeAlerts)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">✅</div>
                            <div class="empty-state-title">No Active Alerts</div>
                            <p style="color: var(--text3); font-size: 13px;">All systems operating normally</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activeAlerts as $alert): ?>
                            <div class="data-item">
                                <div class="data-icon alert">⚠️</div>
                                <div class="data-content">
                                    <div class="data-title">
                                        <?= ucfirst($alert['alert_type']) ?> Alert
                                        <span class="badge <?= $alert['alert_type'] === 'critical' ? 'critical' : 'warning' ?>"><?= ucfirst($alert['alert_type']) ?></span>
                                    </div>
                                    <div class="data-desc"><?= htmlspecialchars($alert['message']) ?></div>
                                    <div class="data-meta">
                                        <span>📍 <?= htmlspecialchars($alert['location_name']) ?> (<?= ucfirst($alert['river_section'] ?? 'unknown') ?>)</span>
                                        <span><?= ucfirst($alert['sensor_type']) ?></span>
                                        <span>🕐 <?= date('M d, H:i', strtotime($alert['created_at'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- All Locations -->
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, var(--c4), white);">
                <span class="card-title">📍 All Monitoring Locations</span>
            </div>
            <div class="card-body">
                <?php if (empty($locations)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🌍</div>
                        <div class="empty-state-title">No Locations Configured</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($locations as $loc): ?>
                        <div class="data-item">
                            <div class="data-icon location">📍</div>
                            <div class="data-content">
                                <div class="data-title"><?= htmlspecialchars($loc['location_name']) ?> <span style="font-size: 12px; color: var(--text3);">(<?= ucfirst($loc['river_section'] ?? 'unknown') ?>)</span></div>
                                <div class="data-desc"><?= htmlspecialchars($loc['description'] ?? 'No description') ?></div>
                                <div class="data-meta">
                                    <span>🌐 <?= number_format($loc['latitude'], 6) ?>, <?= number_format($loc['longitude'], 6) ?></span>
                                    <span>🔧 <?= $loc['device_count'] ?> device(s) • <?= $loc['active_devices'] ?> active</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <footer class="footer">
        <p class="footer-text">
            Aqua-Vision Water Quality Monitoring System — Public Data Access<br>
            Data synced with admin dashboard in real-time from Mangima River monitoring stations.
        </p>
    </footer>
    <script>
        // Mangima River coordinates (same as admin dashboard)
        const mangimaStart = [8.345862, 124.898846];
        const mangimaEnd = [8.413179, 124.909497];
        const centerLat = (mangimaStart[0] + mangimaEnd[0]) / 2;
        const centerLng = (mangimaStart[1] + mangimaEnd[1]) / 2;
        
        // Create map centered on Mangima River
        const map = L.map('map', {
            zoomControl: false,
            minZoom: 12,
            maxZoom: 16,
            maxBounds: [[8.32, 124.88], [8.42, 124.93]],
            maxBoundsViscosity: 1.0
        }).setView([centerLat, centerLng], 13);
        
        L.control.zoom({position: 'bottomright'}).addTo(map);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);
        
        // River path coordinates (Mangima River)
        const R = [[8.345862,124.898846],[8.345963,124.898948],[8.346406,124.899192],[8.346703,124.899117],[8.346892,124.899034],[8.347059,124.898908],[8.347298,124.898613],[8.347470,124.898275],[8.347521,124.898120],[8.347571,124.898012],[8.347842,124.897806],[8.348131,124.897720],[8.348309,124.897642],[8.349344,124.896652],[8.349455,124.896524],[8.349493,124.896424],[8.349490,124.896304],[8.349323,124.895950],[8.349158,124.895700],[8.349134,124.895617],[8.349158,124.895526],[8.349474,124.895209],[8.349580,124.895051],[8.349654,124.894836],[8.349628,124.894745],[8.349503,124.894608],[8.349270,124.894439],[8.348768,124.894099],[8.348720,124.894008],[8.348755,124.893919],[8.349060,124.893669],[8.349270,124.893589],[8.349447,124.893557],[8.349580,124.893498],[8.349968,124.892860],[8.350522,124.891503],[8.351111,124.890497],[8.351252,124.890381],[8.351403,124.890328],[8.351642,124.890223],[8.351913,124.889885],[8.351955,124.889746],[8.351934,124.889650],[8.351873,124.889607],[8.351695,124.889633],[8.351528,124.889717],[8.351377,124.889735],[8.351087,124.889684],[8.350886,124.889588],[8.350804,124.889486],[8.350782,124.889349],[8.350849,124.889210],[8.351021,124.889036],[8.351637,124.888777],[8.351854,124.888684],[8.352271,124.888290],[8.352600,124.887831],[8.352640,124.887657],[8.352613,124.887462],[8.352510,124.887279],[8.352099,124.886963],[8.352064,124.886877],[8.352083,124.886781],[8.352473,124.886440],[8.352584,124.886296],[8.352916,124.885543],[8.353136,124.885167],[8.353285,124.885033],[8.353701,124.884776],[8.353773,124.884625],[8.353874,124.884556],[8.354383,124.884374],[8.354813,124.884129],[8.355172,124.883864],[8.355400,124.883531],[8.355509,124.882987],[8.355657,124.882434],[8.355915,124.881777],[8.356159,124.881426],[8.356358,124.881208],[8.356591,124.881147],[8.356743,124.881136],[8.357929,124.881246],[8.358054,124.881219],[8.358115,124.881165],[8.358308,124.880828],[8.358428,124.880407],[8.358789,124.879848],[8.359155,124.879513],[8.359648,124.879216],[8.359906,124.879114],[8.360920,124.878746],[8.361081,124.878644],[8.361410,124.878199],[8.361649,124.877958],[8.362345,124.877644],[8.363260,124.877056],[8.363377,124.876933],[8.364088,124.875943],[8.364218,124.875796],[8.364998,124.875568],[8.365354,124.875546],[8.365662,124.875578],[8.365879,124.875659],[8.366065,124.875819],[8.366596,124.876345],[8.366811,124.876384],[8.366951,124.876329],[8.367424,124.876083],[8.368193,124.875922],[8.368384,124.875772],[8.368782,124.874521],[8.368889,124.874441],[8.368936,124.874430],[8.369090,124.874430],[8.369218,124.874484],[8.369329,124.874570],[8.369409,124.874918],[8.369515,124.875052],[8.371224,124.875825],[8.372126,124.875777],[8.372890,124.875975],[8.373134,124.876163],[8.373209,124.876281],[8.373363,124.876763],[8.373402,124.876764],[8.373461,124.877185],[8.373511,124.877297],[8.374527,124.877837],[8.375740,124.878148],[8.376366,124.880304],[8.376122,124.881645],[8.376430,124.881999],[8.376313,124.882311],[8.376557,124.882434],[8.376621,124.884328],[8.376101,124.884848],[8.375608,124.885100],[8.375493,124.885266],[8.375467,124.885465],[8.375772,124.885910],[8.377691,124.887257],[8.377821,124.887415],[8.377847,124.887675],[8.377868,124.888416],[8.377884,124.888571],[8.377959,124.888657],[8.378208,124.888754],[8.378940,124.888743],[8.379652,124.888678],[8.379890,124.888710],[8.380671,124.889204],[8.380941,124.889467],[8.380994,124.889607],[8.380909,124.890990],[8.380676,124.891623],[8.380453,124.892461],[8.381122,124.893243],[8.381477,124.893683],[8.381626,124.894257],[8.381498,124.897446],[8.381565,124.897704],[8.381658,124.897776],[8.381889,124.897886],[8.382976,124.898050],[8.383146,124.898039],[8.383390,124.897951],[8.384181,124.897551],[8.384701,124.897535],[8.384887,124.897580],[8.386185,124.898259],[8.387397,124.898610],[8.387782,124.898793],[8.388002,124.898986],[8.388528,124.899860],[8.388631,124.899938],[8.390032,124.899970],[8.390685,124.900067],[8.390852,124.900188],[8.390961,124.900351],[8.391277,124.901011],[8.391420,124.901223],[8.391773,124.901547],[8.393169,124.902229],[8.394501,124.903087],[8.394623,124.903205],[8.394718,124.903597],[8.394745,124.903892],[8.394649,124.904112],[8.393970,124.904814],[8.393410,124.905694],[8.393344,124.905946],[8.393384,124.906051],[8.393514,124.906134],[8.394283,124.906392],[8.394718,124.906440],[8.395902,124.906349],[8.396249,124.906397],[8.396337,124.906501],[8.396387,124.906652],[8.396361,124.906823],[8.396239,124.907231],[8.396202,124.907730],[8.396257,124.907861],[8.396308,124.907883],[8.396459,124.907934],[8.398162,124.907896],[8.398775,124.907953],[8.399158,124.908068],[8.399354,124.908162],[8.399866,124.908682],[8.399948,124.908840],[8.400033,124.909326],[8.399972,124.909739],[8.399951,124.909905],[8.399444,124.910337],[8.399452,124.910345],[8.399396,124.910667],[8.399699,124.911238],[8.399839,124.911332],[8.399972,124.911321],[8.400129,124.911300],[8.400330,124.911209],[8.400519,124.911069],[8.400808,124.910742],[8.401055,124.910554],[8.401264,124.910514],[8.401378,124.910546],[8.401588,124.910841],[8.401737,124.910986],[8.401774,124.911007],[8.402125,124.911168],[8.402489,124.911218],[8.402853,124.911196],[8.403020,124.911119],[8.403792,124.910506],[8.405310,124.909972],[8.405901,124.909983],[8.406337,124.910087],[8.406533,124.910179],[8.406700,124.910291],[8.406745,124.910385],[8.406713,124.910512],[8.405924,124.911388],[8.405818,124.911576],[8.405829,124.911689],[8.405924,124.911801],[8.406275,124.911984],[8.406715,124.912414],[8.407049,124.912661],[8.409034,124.913466],[8.409793,124.913708],[8.410064,124.913713],[8.410472,124.913676],[8.411629,124.913198],[8.412245,124.912800],[8.412515,124.912462],[8.412632,124.911962],[8.413237,124.909739],[8.413179,124.909497]];
        
        // Draw river with multiple layers for depth effect
        L.polyline(R, {color: '#0d1117', weight: 18, opacity: .12}).addTo(map);
        L.polyline(R, {color: '#1a56db', weight: 8, opacity: .55}).addTo(map);
        L.polyline(R, {color: '#60a5fa', weight: 4, opacity: .85}).addTo(map);
        
        // Animated flow line
        const fl = L.polyline(R, {color: '#93c5fd', weight: 2.5, opacity: .65, dashArray: '10 20', dashOffset: '0'}).addTo(map);
        let doff = 0;
        setInterval(() => { doff -= 1.5; fl.setStyle({dashOffset: String(doff)}); }, 60);
        
        // Flow direction arrows
        [3,7,10,14,18,22].forEach(i => {
            if (i >= R.length-1) return;
            const from = R[i], to = R[i+1];
            const lat = (from[0] + to[0]) / 2;
            const lng = (from[1] + to[1]) / 2;
            const angle = Math.atan2(to[1]-from[1], to[0]-from[0]) * 180 / Math.PI - 90;
            L.marker([lat, lng], {icon: L.divIcon({html: `<div style="transform:rotate(${angle}deg);color:#60a5fa;font-size:9px;opacity:.6">▲</div>`, iconSize: [10,10], iconAnchor: [5,5], className: ''}), interactive: false}).addTo(map);
        });
        
        // Start and End markers
        function pIcon(color, label) {
            return L.divIcon({html: `<div style="position:relative;width:40px;height:40px"><div style="position:absolute;inset:0;border-radius:50%;background:${color};opacity:.12;animation:ripple 2s ease-out infinite"></div><div style="position:absolute;inset:8px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.15)"></div><div style="position:absolute;bottom:-16px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:9px;font-weight:600;color:${color};font-family:sans-serif">${label}</div></div>`, iconSize: [40,40], iconAnchor: [20,20], className: ''});
        }
        L.marker([8.345862,124.898846],{icon:pIcon('#059669','START')}).addTo(map);
        L.marker([8.413179,124.909497],{icon:pIcon('#dc2626','END')}).addTo(map);
        
        // River label
        L.marker([8.368, 124.882], {icon: L.divIcon({html: `<div style="font-family:serif;font-size:12px;font-style:italic;color:#1a56db;opacity:.5;white-space:nowrap;transform:rotate(42deg)">Mangima River</div>`, iconSize: [130,20], iconAnchor: [65,10], className: ''}), interactive: false}).addTo(map);
        
        // Add ripple animation style
        if (!document.getElementById('ripple-style')) {
            const s = document.createElement('style');
            s.id = 'ripple-style';
            s.textContent = '@keyframes ripple{0%{transform:scale(.6);opacity:.9}100%{transform:scale(2.4);opacity:0}}';
            document.head.appendChild(s);
        }
        
        // Location markers with river section colors
        const sC = {upstream: '#059669', midstream: '#d97706', downstream: '#dc2626'};
        const sL = {upstream: 'Upstream', midstream: 'Midstream', downstream: 'Downstream'};
        const _mapMk = {};
        const locs = <?= json_encode(array_map(fn($l)=>['id'=>(int)$l['location_id'],'name'=>$l['location_name'],'lat'=>(float)$l['latitude'],'lng'=>(float)$l['longitude'],'section'=>$l['river_section']], $locations)) ?>;
        const locationDevices = <?= json_encode($locationDevices, JSON_NUMERIC_CHECK) ?>;
        
        locs.forEach(loc => {
            const color = sC[loc.section] || '#1a56db';
            const devs = (locationDevices[loc.id] || []).filter(d => d.status === 'active');
            const dHtml = devs.length > 0 ? 
                `<div style="margin:8px 0;padding-top:8px;border-top:1px solid #f0f0f0"><div style="font-size:10px;font-weight:600;color:#0d1117;margin-bottom:4px;letter-spacing:.04em;text-transform:uppercase">Active Devices</div>${devs.map(d => {
                    const c = '#059669';
                    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:4px 8px;border-radius:4px;background:#f9fafb;margin-bottom:2px"><span style="font-size:11px;color:#0d1117;display:flex;align-items:center;gap:5px"><span style="width:5px;height:5px;border-radius:50%;background:${c};display:inline-block"></span>${d.device_name}</span><span style="font-size:10px;color:${c};font-weight:600">Active</span></div>`;
                }).join('')}</div>` : 
                `<div style="margin:8px 0;font-size:11px;color:#9ca3af;padding-top:8px;border-top:1px solid #f0f0f0">No active devices</div>`;
            
            const marker = L.circleMarker([loc.lat, loc.lng], {radius: 12, fillColor: color, color: '#fff', weight: 2.5, fillOpacity: .95}).addTo(map);
            _mapMk[loc.id] = marker;
            marker.bindPopup(`<div style="font-family:sans-serif;min-width:210px"><div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><div style="width:8px;height:8px;border-radius:50%;background:${color}"></div><div style="font-size:13px;font-weight:600;color:#0d1117">${sL[loc.section] || loc.section}</div></div><div style="font-size:11px;color:#3d4a5c;margin-bottom:4px">${loc.name}</div>${dHtml}<div style="font-size:10px;color:#8897aa;margin-top:6px;font-family:monospace;text-align:center">${loc.lat.toFixed(5)}°N · ${loc.lng.toFixed(5)}°E</div></div>`, {maxWidth: 250});
            L.tooltip({permanent: true, direction: 'bottom', offset: [0, 12]}).setContent(`<span style="font-size:9px;font-weight:600;color:#3d4a5c;font-family:sans-serif;letter-spacing:.04em;text-transform:uppercase">${sL[loc.section] || loc.section}</span>`).setLatLng([loc.lat, loc.lng]).addTo(map);
        });
        
        // Fit bounds to show river and all locations
        const allPts = [...R, ...locs.map(l => [l.lat, l.lng])];
        const bounds = L.latLngBounds(allPts);
        if (bounds.isValid()) map.fitBounds(bounds.pad(.12));
    </script>
</body>
</html>