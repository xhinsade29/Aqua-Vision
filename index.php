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

// Get active devices with readings
$devicesRes = $conn->query("SELECT d.device_id, d.device_name, d.status,
                                   l.location_name, l.river_section, l.latitude, l.longitude
                            FROM devices d
                            LEFT JOIN locations l ON l.location_id = d.location_id
                            WHERE d.status='active'");
$devices = [];
if ($devicesRes) {
    while ($r = $devicesRes->fetch_assoc()) {
        $devices[] = $r;
        if ($r['latitude'] && $r['longitude']) {
            $mapLocations[] = $r;
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
                        <div class="sim-stat-card"><div class="sim-stat-l">Readings</div><div id="simCount" class="sim-stat-v" style="color:#7c3aed">0</div></div>
                        <div class="sim-stat-card"><div class="sim-stat-l">Alerts</div><div id="simAlerts" class="sim-stat-v" style="color:var(--warn)">0</div></div>
                        <div class="sim-stat-card wide"><div class="sim-stat-l">Last Read</div><div id="simLastTs" style="font-family:var(--mono);font-size:13px;color:var(--text);margin-top:4px">—</div></div>
                        <div class="sim-stat-card wide"><div class="sim-stat-l">Device</div><div id="simLastDevice" style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-top:4px;line-height:1.5">—</div></div>
                    </div>
                    <div style="display:flex;flex-direction:column;height:280px">
                        <div class="sim-log-label" style="margin-bottom:6px;flex-shrink:0">Simulation Log</div>
                        <div id="simLog" style="height:250px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:var(--radius);padding:.75rem;overflow-y:auto;font-family:var(--mono);font-size:10.5px;line-height:1.6">
                            <div style="color:var(--text3)">Monitoring stopped — press ▶ Start to begin.</div>
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
        const map = L.map('map').setView([8.4867, 124.6489], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
        <?php foreach ($mapLocations as $loc): ?><?php if ($loc['latitude'] && $loc['longitude']): ?>L.marker([<?= $loc['latitude'] ?>, <?= $loc['longitude'] ?>]).addTo(map).bindPopup('<strong><?= htmlspecialchars($loc['device_name']) ?></strong><br><?= ucfirst($loc['river_section'] ?? 'unknown') ?> section');<?php endif; ?><?php endforeach; ?>
        <?php if (!empty($mapLocations)): ?>const bounds = [<?php foreach ($mapLocations as $loc): ?><?php if ($loc['latitude'] && $loc['longitude']): ?>[<?= $loc['latitude'] ?>, <?= $loc['longitude'] ?>],<?php endif; ?><?php endforeach; ?>];if (bounds.length > 0) map.fitBounds(bounds, { padding: [50, 50] });<?php endif; ?>
    </script>
</body>
</html>