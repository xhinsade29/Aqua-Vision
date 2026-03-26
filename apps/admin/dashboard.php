<?php
/**
 * Aqua-Vision — Overview Dashboard (Redesigned)
 * Location: apps/admin/dashboard.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Dashboard Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// ── Helper ────────────────────────────────────────────────────────────────────
function trend24(mysqli $conn, string $col, ?int $deviceId = null): array {
    $c = $conn->real_escape_string($col);
    $deviceFilter = $deviceId ? "AND s.device_id = " . (int)$deviceId : "";
    $sql = "SELECT HOUR(sr.recorded_at) AS hr, AVG(sr.value) AS avg_val 
            FROM sensor_readings sr 
            JOIN sensors s ON s.sensor_id = sr.sensor_id 
            WHERE s.sensor_type = '$c' AND sr.recorded_at >= NOW() - INTERVAL 24 HOUR $deviceFilter 
            GROUP BY hr ORDER BY hr";
    $res = $conn->query($sql);
    if (!$res) { return array_fill(0, 24, null); }
    $map = [];
    while ($r = $res->fetch_assoc()) $map[(int)$r['hr']] = round((float)$r['avg_val'], 2);
    $out = [];
    for ($i = 0; $i < 24; $i++) $out[] = $map[$i] ?? null;
    return $out;
}

// ── API: ?action=simulate ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'simulate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { echo json_encode(['error'=>'Invalid JSON']); exit; }
    $did  = isset($body['device_id'])       ? (int)$body['device_id']         : 0;
    $temp = isset($body['temperature'])      ? (float)$body['temperature']     : null;
    $ph   = isset($body['ph_level'])         ? (float)$body['ph_level']        : null;
    $turb = isset($body['turbidity'])        ? (float)$body['turbidity']       : null;
    $do_  = isset($body['dissolved_oxygen']) ? (float)$body['dissolved_oxygen']: null;
    $wl   = isset($body['water_level'])      ? (float)$body['water_level']     : null;
    if ($did <= 0) { echo json_encode(['error'=>'Invalid device_id']); exit; }
    $chk = $conn->prepare("SELECT device_id, device_name FROM devices WHERE device_id=? AND status='active'");
    $chk->bind_param('i', $did); $chk->execute();
    $dev = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$dev) { echo json_encode(['error'=>"Device $did not found or inactive"]); exit; }
    $ins = $conn->prepare("INSERT INTO device_data (device_id,temperature,ph_level,turbidity,dissolved_oxygen,water_level,recorded_at) VALUES (?,?,?,?,?,?,NOW())");
    $ins->bind_param('iddddd', $did, $temp, $ph, $turb, $do_, $wl);
    if (!$ins->execute()) { echo json_encode(['error'=>'Insert failed: '.$conn->error]); exit; }
    $rid = $conn->insert_id; $ins->close();
    $conn->query("UPDATE devices SET last_active=NOW() WHERE device_id=$did");
    $thresholds = [[$temp,20,35,'Temperature'],[$ph,6.5,8.5,'pH Level'],[$turb,0,50,'Turbidity'],[$do_,5,14,'Dissolved Oxygen'],[$wl,0.5,3.0,'Water Level']];
    $alertsCreated = [];
    foreach ($thresholds as [$v,$mn,$mx,$lbl]) {
        if ($v === null) continue;
        if ($v < $mn || $v > $mx) {
            $dir = $v < $mn ? 'low' : 'high';
            $type = ($v < $mn * 0.8 || $v > $mx * 1.3) ? 'critical' : $dir;
            $msg = "$lbl $dir: $v (safe $mn–$mx) on {$dev['device_name']}";
            $ast = $conn->prepare("INSERT INTO alerts (device_id,alert_type,message,status,created_at) VALUES (?,?,?,'active',NOW())");
            $ast->bind_param('iss', $did, $type, $msg); $ast->execute(); $ast->close();
            $alertsCreated[] = ['type'=>$type,'message'=>$msg];
        }
    }
    $conn->close();
    echo json_encode(['success'=>true,'reading_id'=>$rid,'device_id'=>$did,'device_name'=>$dev['device_name'],'alerts_created'=>$alertsCreated,'timestamp'=>date('Y-m-d H:i:s')]);
    exit;
}

// ── API: ?action=monitor_state ────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
if (($_GET['action'] ?? '') === 'monitor_state') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON body']); exit; }
            $state = json_encode(['running'=>$body['running']??false,'mode'=>$body['mode']??'normal','device_id'=>$body['device_id']??0,'interval'=>$body['interval']??5000,'started_at'=>($body['running']??false)?date('Y-m-d H:i:s'):null,'started_by'=>$_SESSION['user_id']??0]);
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('live_monitor',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()");
            if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'Prepare failed: '.$conn->error]); exit; }
            $stmt->bind_param('s', $state); $stmt->execute(); $stmt->close();
            echo json_encode(['ok'=>true]);
        } else {
            $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='live_monitor'");
            $row = $res->fetch_assoc();
            $state = $row ? json_decode($row['setting_value'], true) : ['running'=>false];
            if (json_last_error() !== JSON_ERROR_NONE) $state = ['running'=>false];
            echo json_encode(['ok'=>true,'state'=>$state]);
        }
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

// ── API: ?action=fetch ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'fetch') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    try {
        $dcRes = $conn->query("SELECT COUNT(*) AS total,SUM(status='active') AS active,SUM(status='inactive') AS offline,SUM(status='maintenance') AS maint FROM devices");
        $devCounts = $dcRes->fetch_assoc();
        $alertCount = (int)$conn->query("SELECT COUNT(*) AS cnt FROM alerts WHERE status='active'")->fetch_assoc()['cnt'];
        $dRes = $conn->query("SELECT d.device_id,d.device_name,d.status,d.last_active,l.location_name,l.river_section FROM devices d LEFT JOIN locations l ON l.location_id=d.location_id WHERE d.status='active' AND d.location_id IS NOT NULL ORDER BY l.river_section,d.device_name");
        $devs = [];
        while ($r = $dRes->fetch_assoc()) $devs[] = $r;
        $deviceReadings = [];
        foreach ($devs as $dv) {
            $did = (int)$dv['device_id'];
            $r = $conn->query("SELECT temperature,ph_level,turbidity,dissolved_oxygen,water_level,recorded_at FROM device_data WHERE device_id=$did ORDER BY recorded_at DESC LIMIT 1");
            $deviceReadings[$did] = ($r && ($row = $r->fetch_assoc())) ? $row : null;
        }
        $aRes = $conn->query("SELECT a.alert_id,a.alert_type,a.message,a.created_at,l.location_name,d.device_name FROM alerts a JOIN devices d ON d.device_id=a.device_id JOIN locations l ON l.location_id=d.location_id WHERE a.status='active' ORDER BY a.created_at DESC LIMIT 5");
        $alerts = [];
        while ($r = $aRes->fetch_assoc()) $alerts[] = $r;
        $lRes = $conn->query("
            SELECT dd.recorded_at,d.device_name,l.location_name,'temperature' AS sensor_type,'°C' AS unit,20 AS min_threshold,35 AS max_threshold,dd.temperature AS value FROM device_data dd JOIN devices d ON d.device_id=dd.device_id JOIN locations l ON l.location_id=d.location_id WHERE dd.temperature IS NOT NULL
            UNION ALL SELECT dd.recorded_at,d.device_name,l.location_name,'pH','pH',6.5,8.5,dd.ph_level FROM device_data dd JOIN devices d ON d.device_id=dd.device_id JOIN locations l ON l.location_id=d.location_id WHERE dd.ph_level IS NOT NULL
            UNION ALL SELECT dd.recorded_at,d.device_name,l.location_name,'turbidity','NTU',0,50,dd.turbidity FROM device_data dd JOIN devices d ON d.device_id=dd.device_id JOIN locations l ON l.location_id=d.location_id WHERE dd.turbidity IS NOT NULL
            UNION ALL SELECT dd.recorded_at,d.device_name,l.location_name,'dissolved_oxygen','mg/L',5,14,dd.dissolved_oxygen FROM device_data dd JOIN devices d ON d.device_id=dd.device_id JOIN locations l ON l.location_id=d.location_id WHERE dd.dissolved_oxygen IS NOT NULL
            UNION ALL SELECT dd.recorded_at,d.device_name,l.location_name,'water_level','m',0.5,3.0,dd.water_level FROM device_data dd JOIN devices d ON d.device_id=dd.device_id JOIN locations l ON l.location_id=d.location_id WHERE dd.water_level IS NOT NULL
            ORDER BY recorded_at DESC LIMIT 15");
        $logs = [];
        while ($r = $lRes->fetch_assoc()) $logs[] = ['recorded_at'=>$r['recorded_at'],'device_name'=>$r['device_name'],'location_name'=>$r['location_name'],'sensor_type'=>$r['sensor_type'],'unit'=>$r['unit'],'min_threshold'=>(float)$r['min_threshold'],'max_threshold'=>(float)$r['max_threshold'],'value'=>(float)$r['value']];
        $locRes = $conn->query("SELECT l.location_id,l.river_section,COUNT(d.device_id) AS total_devices,SUM(d.status='active') AS active_devices,SUM(d.status='maintenance') AS maint_devices FROM locations l LEFT JOIN devices d ON d.location_id=l.location_id GROUP BY l.location_id");
        $mapLoc = [];
        while ($r = $locRes->fetch_assoc()) $mapLoc[] = $r;
        $warnCount = 0;
        foreach ($deviceReadings as $row) {
            if (!$row) continue;
            foreach ([[$row['temperature'],20,35],[$row['ph_level'],6.5,8.5],[$row['turbidity'],0,50],[$row['dissolved_oxygen'],5,14],[$row['water_level'],0.5,3.0]] as [$v,$mn,$mx])
                if ($v !== null && ($v < $mn || $v > $mx)) $warnCount++;
        }
        $riverStatus = $warnCount===0?'Normal':($warnCount<=2?'Moderate':'Critical');
        $bannerColor = $warnCount===0?'#16a34a':($warnCount<=2?'#f59e0b':'#ef4444');
        $bannerEmoji = $warnCount===0?'✅':($warnCount<=2?'⚠️':'🚨');
        $chartData = ['temperature'=>trend24($conn,'temperature'),'pH'=>trend24($conn,'ph_level'),'turbidity'=>trend24($conn,'turbidity'),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen'),'water_level'=>trend24($conn,'water_level')];
        $deviceChartData = [];
        foreach ($devs as $dev) {
            $did = (int)$dev['device_id'];
            $deviceChartData[$did] = ['temperature'=>trend24($conn,'temperature',$did),'pH'=>trend24($conn,'ph_level',$did),'turbidity'=>trend24($conn,'turbidity',$did),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen',$did),'water_level'=>trend24($conn,'water_level',$did)];
        }
        $conn->close();
        echo json_encode(['ok'=>true,'ts'=>date('Y-m-d H:i:s'),'river_status'=>$riverStatus,'banner_color'=>$bannerColor,'banner_emoji'=>$bannerEmoji,'warn_count'=>$warnCount,'alert_count'=>$alertCount,'dev_counts'=>$devCounts,'device_readings'=>$deviceReadings,'devices'=>array_map(fn($d)=>['device_id'=>(int)$d['device_id'],'device_name'=>$d['device_name'],'status'=>$d['status'],'location_name'=>$d['location_name'],'river_section'=>$d['river_section'],'last_active'=>$d['last_active']],$devs),'alerts'=>$alerts,'logs'=>$logs,'map_locations'=>$mapLoc,'chart_data'=>$chartData,'device_chart_data'=>$deviceChartData], JSON_NUMERIC_CHECK);
        exit;
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'overview';

// Check if required tables exist
$tablesExist = $conn->query("SHOW TABLES LIKE 'devices'")->num_rows > 0;

if (!$tablesExist) {
    // Redirect to database setup if tables don't exist
    header('Location: ../database/setup.php');
    exit();
}

$devCounts = $conn->query("SELECT COUNT(*) AS total,SUM(status='active') AS active,SUM(status='inactive') AS offline,SUM(status='maintenance') AS maint FROM devices")->fetch_assoc();
$alertCount = (int)$conn->query("SELECT COUNT(*) AS cnt FROM alerts WHERE status='active'")->fetch_assoc()['cnt'];
$alertsRes = $conn->query("SELECT a.alert_id,a.alert_type,a.message,a.created_at,l.location_name,d.device_name FROM alerts a JOIN devices d ON d.device_id=a.device_id JOIN locations l ON l.location_id=d.location_id WHERE a.status='active' ORDER BY a.created_at DESC LIMIT 5");
$alerts = [];
if ($alertsRes) {
    while ($r = $alertsRes->fetch_assoc()) $alerts[] = $r;
}
$devicesRes = $conn->query("SELECT d.device_id,d.device_name,d.status,d.last_active,l.location_name,l.river_section,l.latitude,l.longitude FROM devices d LEFT JOIN locations l ON l.location_id=d.location_id WHERE d.status='active' AND d.location_id IS NOT NULL ORDER BY l.river_section,d.device_name");
$devices = [];
if ($devicesRes) {
    while ($r = $devicesRes->fetch_assoc()) $devices[] = $r;
}
$deviceReadings = [];
foreach ($devices as $dev) {
    $did = (int)$dev['device_id'];
    $res = $conn->query("SELECT sr.value, sr.recorded_at, s.sensor_type FROM sensor_readings sr JOIN sensors s ON s.sensor_id = sr.sensor_id WHERE s.device_id = $did ORDER BY sr.recorded_at DESC LIMIT 5");
    $readings = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $readings[] = $r;
    }
    $deviceReadings[$did] = $readings;
}
$maintRes = $conn->query("SELECT ml.maintenance_type, ml.notes, ml.performed_at, d.device_name, u.full_name FROM maintenance_logs ml JOIN devices d ON d.device_id = ml.device_id JOIN users u ON u.user_id = ml.performed_by ORDER BY ml.performed_at DESC LIMIT 4");
$maints = [];
if ($maintRes) {
    while ($r = $maintRes->fetch_assoc()) $maints[] = $r;
}
$logsRes = $conn->query("
    SELECT sr.recorded_at, d.device_name, l.location_name, s.sensor_type, s.unit, s.min_threshold, s.max_threshold, sr.value
    FROM sensor_readings sr
    JOIN sensors s ON s.sensor_id = sr.sensor_id
    JOIN devices d ON d.device_id = s.device_id
    JOIN locations l ON l.location_id = d.location_id
    ORDER BY sr.recorded_at DESC LIMIT 50");
$logs = [];
while ($r = $logsRes->fetch_assoc()) $logs[] = $r;
$locRes = $conn->query("SELECT l.location_id,l.location_name,l.latitude,l.longitude,l.river_section,COUNT(d.device_id) AS total_devices,SUM(d.status='active') AS active_devices,SUM(d.status='maintenance') AS maint_devices,MIN(d.device_id) AS device_id FROM locations l LEFT JOIN devices d ON d.location_id=l.location_id GROUP BY l.location_id ORDER BY l.river_section");
$mapLocations = []; $locationDevices = [];
while ($r = $locRes->fetch_assoc()) {
    $mapLocations[] = $r;
    $locId = (int)$r['location_id'];
    $dRes = $conn->query("SELECT device_id,device_name,status FROM devices WHERE location_id=$locId ORDER BY device_name");
    $dd = [];
    while ($d = $dRes->fetch_assoc()) $dd[] = $d;
    $locationDevices[$locId] = $dd;
}
$chartData = ['temperature'=>trend24($conn,'temperature'),'pH'=>trend24($conn,'ph_level'),'turbidity'=>trend24($conn,'turbidity'),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen'),'water_level'=>trend24($conn,'water_level')];
$allChartData = [];
foreach ($devices as $dev) {
    $did = (int)$dev['device_id'];
    $allChartData[$did] = ['temperature'=>trend24($conn,'temperature',$did),'pH'=>trend24($conn,'ph_level',$did),'turbidity'=>trend24($conn,'turbidity',$did),'dissolved_oxygen'=>trend24($conn,'dissolved_oxygen',$did),'water_level'=>trend24($conn,'water_level',$did)];
}
$latestRes = $conn->query("SELECT s.sensor_type, s.min_threshold, s.max_threshold, sr.value FROM sensor_readings sr JOIN sensors s ON s.sensor_id = sr.sensor_id WHERE s.sensor_type = 'temperature' ORDER BY sr.recorded_at DESC LIMIT 100");
$allRows = [];
if ($latestRes) {
    while ($row = $latestRes->fetch_assoc()) $allRows[] = $row;
}
$warnCount = 0;
foreach ($allRows as $r) { if ($r['value'] < $r['min_threshold'] || $r['value'] > $r['max_threshold']) $warnCount++; }
$riverStatus = $warnCount===0?'Normal':($warnCount<=2?'Moderate':'Critical');
$bannerColor = $warnCount===0?'#16a34a':($warnCount<=2?'#f59e0b':'#ef4444');
$lastTs = !empty($logs) ? $logs[0]['recorded_at'] : null;
?>
<?php include '../../assets/navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aqua-Vision — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<style>
/* ── Reset & Tokens ─────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
  --ink:    #0d1117;
  --ink2:   #3d4a5c;
  --ink3:   #8897aa;
  --ink4:   #b8c4d0;
  --rule:   rgba(13,17,23,.07);
  --rule2:  rgba(13,17,23,.12);
  --bg:     #f5f6f8;
  --surf:   #ffffff;
  --surf2:  #f9fafb;
  --accent: #1a56db;
  --acc-bg: #eff4ff;
  --good:   #059669;
  --good-bg:#d1fae5;
  --warn:   #d97706;
  --warn-bg:#fef3c7;
  --crit:   #dc2626;
  --crit-bg:#fee2e2;
  --up:     #059669;
  --mid:    #d97706;
  --down:   #dc2626;

  --r-sm:4px;--r:8px;--r-lg:12px;--r-xl:16px;
  --sh: 0 1px 2px rgba(13,17,23,.04), 0 4px 16px rgba(13,17,23,.06);
  --sh-sm: 0 1px 2px rgba(13,17,23,.05);

  --sans: 'Instrument Sans', sans-serif;
  --serif: 'Instrument Serif', serif;
  --mono: 'JetBrains Mono', monospace;
}

html { font-size: 14px; }
body { font-family: var(--sans); background: var(--bg); color: var(--ink); min-height: 100vh; -webkit-font-smoothing: antialiased; }

/* ── Scrollbar ──────────────────────────────────────────────── */
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--rule2); border-radius: 4px; }

/* ── Layout ─────────────────────────────────────────────────── */
.wrap { max-width: 1440px; padding: 28px 32px 64px; }

/* ── Topbar ─────────────────────────────────────────────────── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 32px; padding-bottom: 20px;
  border-bottom: 1px solid var(--rule);
}
.topbar-brand { display: flex; align-items: baseline; gap: 10px; }
.topbar-brand .wordmark {
  font-family: var(--serif); font-size: 22px; color: var(--ink);
  letter-spacing: -.01em; font-style: italic;
}
.topbar-brand .slash { color: var(--ink4); font-size: 16px; margin: 0 2px; }
.topbar-brand .page-name {
  font-size: 13px; font-weight: 500; color: var(--ink3); letter-spacing: .01em;
}
.topbar-right { display: flex; align-items: center; gap: 10px; }
.ts-line {
  font-family: var(--mono); font-size: 11px; color: var(--ink4);
  display: flex; align-items: center; gap: 6px;
}
.ts-line::before {
  content: ''; display: inline-block; width: 6px; height: 6px;
  border-radius: 50%; background: var(--good); animation: pulse 2s infinite;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Buttons ────────────────────────────────────────────────── */
.btn {
  height: 32px; padding: 0 14px; border-radius: var(--r);
  font: 500 12px/1 var(--sans); cursor: pointer; border: none;
  display: inline-flex; align-items: center; gap: 6px;
  transition: all .15s; text-decoration: none; white-space: nowrap;
}
.btn-outline {
  background: var(--surf); color: var(--ink2);
  border: 1px solid var(--rule2);
}
.btn-outline:hover { background: var(--surf2); border-color: var(--ink4); }
.btn-primary { background: var(--ink); color: #fff; }
.btn-primary:hover { background: var(--ink2); }
.btn-ghost { background: transparent; color: var(--ink3); padding: 0 8px; }
.btn-ghost:hover { color: var(--ink); background: var(--surf2); }

/* ── Status Banner ──────────────────────────────────────────── */
.river-banner {
  display: grid; grid-template-columns: auto 1fr auto;
  align-items: center; gap: 20px;
  background: var(--surf); border: 1px solid var(--rule);
  border-radius: var(--r-xl); padding: 18px 24px;
  margin-bottom: 24px; box-shadow: var(--sh-sm);
  position: relative; overflow: hidden;
}
.river-banner::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0;
  width: 3px; background: var(--status-color, var(--good));
  border-radius: 3px 0 0 3px;
}
.banner-status-dot {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; background: var(--status-bg, var(--good-bg)); flex-shrink: 0;
}
.banner-body { min-width: 0; }
.banner-title {
  font-family: var(--serif); font-size: 16px; font-style: italic;
  color: var(--ink); margin-bottom: 2px;
}
.banner-sub { font-size: 12px; color: var(--ink3); }
.banner-stats { display: flex; align-items: center; gap: 0; }
.bstat {
  padding: 0 20px; text-align: right;
  border-left: 1px solid var(--rule);
}
.bstat:first-child { border-left: none; }
.bstat-v { font-family: var(--mono); font-size: 18px; font-weight: 500; color: var(--ink); }
.bstat-l { font-size: 10px; color: var(--ink4); letter-spacing: .06em; text-transform: uppercase; margin-top: 2px; }

/* ── KPI Row ─────────────────────────────────────────────────── */
.kpi-row {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 12px; margin-bottom: 24px;
}
.kpi {
  background: var(--surf); border: 1px solid var(--rule);
  border-radius: var(--r-lg); padding: 18px 20px;
  box-shadow: var(--sh-sm); position: relative;
}
.kpi-label { font-size: 11px; font-weight: 500; color: var(--ink3); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 10px; }
.kpi-value { font-family: var(--mono); font-size: 28px; font-weight: 500; color: var(--ink); line-height: 1; }
.kpi-sub { font-size: 11px; color: var(--ink4); margin-top: 6px; }
.kpi-badge {
  position: absolute; top: 16px; right: 16px;
  width: 28px; height: 28px; border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center; font-size: 13px;
}
.kpi-badge.good { background: var(--good-bg); }
.kpi-badge.warn { background: var(--warn-bg); }
.kpi-badge.crit { background: var(--crit-bg); }
.kpi-badge.info { background: var(--acc-bg); }

/* ── Grid Layout ────────────────────────────────────────────── */
.grid-main {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 16px; margin-bottom: 16px; align-items: start;
}
.grid-bottom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px; margin-bottom: 16px;
}

/* ── Card ───────────────────────────────────────────────────── */
.card {
  background: var(--surf); border: 1px solid var(--rule);
  border-radius: var(--r-xl); box-shadow: var(--sh-sm); overflow: hidden;
}
.card-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-bottom: 1px solid var(--rule);
  gap: 10px; flex-wrap: wrap;
}
.card-head-l { display: flex; align-items: center; gap: 8px; min-width: 0; }
.card-title {
  font-size: 13px; font-weight: 600; color: var(--ink);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.card-head-r { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* ── Pills / Tags ───────────────────────────────────────────── */
.tag {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 600; padding: 2px 8px;
  border-radius: 20px; white-space: nowrap; letter-spacing: .03em;
}
.tag-good { background: var(--good-bg); color: var(--good); }
.tag-warn { background: var(--warn-bg); color: var(--warn); }
.tag-crit { background: var(--crit-bg); color: var(--crit); }
.tag-info { background: var(--acc-bg); color: var(--accent); }
.tag-mute { background: #f3f4f6; color: #6b7280; }
.tag-up   { background: #d1fae5; color: #059669; }
.tag-mid  { background: #fef3c7; color: #d97706; }
.tag-down { background: #fee2e2; color: #dc2626; }

/* ── Selects ────────────────────────────────────────────────── */
.sel {
  height: 30px; padding: 0 10px;
  border: 1px solid var(--rule2); border-radius: var(--r);
  font: 500 11px var(--sans); background: var(--surf); color: var(--ink2);
  cursor: pointer; outline: none; transition: border-color .15s;
}
.sel:hover, .sel:focus { border-color: var(--ink4); }

/* ── Map ────────────────────────────────────────────────────── */
#av-map { height: 420px; width: 100%; }
.map-legend {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 10px 18px; border-top: 1px solid var(--rule);
  background: var(--surf2);
}
.leg { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--ink3); }
.leg-dot { width: 7px; height: 7px; border-radius: 50%; }

/* ── Sim Controls ───────────────────────────────────────────── */
.sim-controls {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 10px 18px; border-bottom: 1px solid var(--rule);
  background: var(--surf2);
}
.sim-stats {
  display: grid; grid-template-columns: repeat(4, 1fr);
  border-top: 1px solid var(--rule);
}
.sim-stat {
  padding: 12px 16px; border-right: 1px solid var(--rule);
}
.sim-stat:last-child { border-right: none; }
.sim-stat-l { font-size: 10px; font-weight: 600; color: var(--ink4); text-transform: uppercase; letter-spacing: .06em; }
.sim-stat-v { font-family: var(--mono); font-size: 20px; font-weight: 500; color: var(--ink); margin-top: 2px; }
.sim-log-wrap {
  padding: 10px 18px 12px; border-top: 1px solid var(--rule);
  background: var(--surf2);
}
.sim-log-label { font-size: 10px; font-weight: 600; color: var(--ink4); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; }
#simLog {
  max-height: 100px; overflow-y: auto;
  font-family: var(--mono); font-size: 10.5px; line-height: 1.6;
}

/* ── Device Panel ───────────────────────────────────────────── */
.dev-panel { overflow-y: auto; max-height: 500px; }
.dev-panel-empty {
  padding: 48px 24px; text-align: center; color: var(--ink4);
}
.dev-panel-empty .empty-icon { font-size: 32px; margin-bottom: 10px; opacity: .4; }
.dev-panel-empty p { font-size: 12px; }
.dev-header {
  padding: 14px 18px; border-bottom: 1px solid var(--rule);
  background: var(--surf2); display: flex; align-items: flex-start;
  justify-content: space-between; gap: 12px;
}
.dev-name { font-size: 14px; font-weight: 600; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.dev-loc { font-size: 11px; color: var(--ink4); margin-top: 3px; }
.sensor-row {
  display: flex; align-items: center;
  padding: 11px 18px; border-bottom: 1px solid var(--rule);
  gap: 12px; transition: background .1s;
}
.sensor-row:last-child { border-bottom: none; }
.sensor-row:hover { background: var(--surf2); }
.sensor-row.out { background: rgba(217,119,6,.03); }
.sensor-row.out:hover { background: rgba(217,119,6,.06); }
.sensor-icon {
  width: 32px; height: 32px; border-radius: var(--r);
  background: var(--surf2); border: 1px solid var(--rule);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.sensor-label { flex: 1; min-width: 0; }
.sensor-name { font-size: 12px; font-weight: 500; color: var(--ink); }
.sensor-range { font-size: 10px; color: var(--ink4); margin-top: 1px; font-family: var(--mono); }
.sensor-val {
  font-family: var(--mono); font-size: 18px; font-weight: 500;
  text-align: right; flex-shrink: 0;
}
.sensor-val .unit { font-size: 11px; color: var(--ink4); margin-left: 2px; font-weight: 400; }
.sensor-status { text-align: right; flex-shrink: 0; min-width: 80px; }

/* ── Chart ──────────────────────────────────────────────────── */
.chart-wrap { padding: 12px 16px 16px; height: 280px; position: relative; }

/* ── Alerts ─────────────────────────────────────────────────── */
.alert-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 18px; border-bottom: 1px solid var(--rule);
}
.alert-item:last-child { border-bottom: none; }
.alert-ic {
  width: 28px; height: 28px; border-radius: var(--r);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0; margin-top: 1px;
}
.alert-ic.crit { background: var(--crit-bg); }
.alert-ic.warn { background: var(--warn-bg); }
.alert-msg { font-size: 12px; font-weight: 500; color: var(--ink); line-height: 1.4; }
.alert-meta { font-size: 11px; color: var(--ink4); margin-top: 3px; font-family: var(--mono); }

/* ── Maintenance ────────────────────────────────────────────── */
.maint-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 18px; border-bottom: 1px solid var(--rule);
}
.maint-item:last-child { border-bottom: none; }
.maint-ic {
  width: 32px; height: 32px; border-radius: var(--r);
  background: var(--warn-bg); display: flex; align-items: center;
  justify-content: center; font-size: 14px; flex-shrink: 0;
}
.maint-name { font-size: 12px; font-weight: 500; color: var(--ink); }
.maint-note { font-size: 11px; color: var(--ink4); margin-top: 2px; }
.maint-when { font-family: var(--mono); font-size: 10px; color: var(--ink4); text-align: right; margin-left: auto; white-space: nowrap; }

/* ── Log Table ──────────────────────────────────────────────── */
.log-section { margin-bottom: 20px; }
.log-filter-bar {
  padding: 10px 18px; border-bottom: 1px solid var(--rule);
  background: var(--surf2); display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.log-filter-label { font-size: 11px; color: var(--ink4); font-weight: 500; }
.dev-group-head {
  padding: 10px 18px; display: flex; align-items: center; gap: 10px;
  background: var(--surf2); cursor: pointer; user-select: none;
  border-bottom: 1px solid var(--rule);
  transition: background .1s;
}
.dev-group-head:hover { background: #f1f3f5; }
.dev-group-badge {
  width: 28px; height: 28px; border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
}
.dev-group-name { font-size: 12px; font-weight: 600; color: var(--ink); }
.dev-group-loc { font-size: 11px; color: var(--ink4); }
.dev-group-meta { margin-left: auto; text-align: right; flex-shrink: 0; }
.dev-group-ts { font-family: var(--mono); font-size: 10px; color: var(--ink4); }
.dev-group-chevron { font-size: 10px; color: var(--ink4); margin-left: 4px; transition: transform .2s; }
.dev-group-chevron.open { transform: rotate(180deg); }

.metric-tbl { width: 100%; border-collapse: collapse; }
.metric-tbl tr { border-bottom: 1px solid var(--rule); transition: background .1s; }
.metric-tbl tr:last-child { border-bottom: none; }
.metric-tbl tr:hover { background: var(--surf2); }
.metric-tbl tr.out { background: rgba(217,119,6,.03); }
.metric-tbl tr.out:hover { background: rgba(217,119,6,.06); }
.metric-tbl td {
  padding: 9px 14px; font-size: 12px; color: var(--ink3);
  font-family: var(--mono);
}
.metric-tbl td.icon-c { width: 32px; padding-right: 4px; font-size: 14px; }
.metric-tbl td.name-c { font-family: var(--sans); font-weight: 500; color: var(--ink); font-size: 12px; min-width: 120px; }
.metric-tbl td.val-c { font-size: 13px; font-weight: 500; min-width: 90px; }
.metric-tbl td.range-c { font-size: 11px; color: var(--ink4); min-width: 110px; }
.metric-tbl td.status-c { text-align: right; padding-right: 18px; }

/* ── Sync indicator ──────────────────────────────────────────── */
#syncInd {
  font-family: var(--mono); font-size: 11px; color: var(--ink4);
  opacity: .5; transition: all .4s; white-space: nowrap;
}

/* ── Empty states ───────────────────────────────────────────── */
.empty { padding: 28px; text-align: center; color: var(--ink4); font-size: 12px; }

/* ── Section divider ─────────────────────────────────────────── */
.section-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; margin-top: 4px;
}
.section-label {
  font-size: 11px; font-weight: 600; color: var(--ink4);
  text-transform: uppercase; letter-spacing: .08em;
  display: flex; align-items: center; gap: 8px;
}
.section-label::before {
  content: ''; display: block; width: 16px; height: 1px; background: var(--ink4);
}

/* ── Animations ─────────────────────────────────────────────── */
.fade-in { animation: fi .3s ease both; }
@keyframes fi { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.fade-in:nth-child(1){animation-delay:.04s}
.fade-in:nth-child(2){animation-delay:.08s}
.fade-in:nth-child(3){animation-delay:.12s}
.fade-in:nth-child(4){animation-delay:.16s}
.fade-in:nth-child(5){animation-delay:.20s}
.fade-in:nth-child(6){animation-delay:.24s}
.fade-in:nth-child(7){animation-delay:.28s}

@keyframes av-ripple{0%{transform:scale(.6);opacity:.9}100%{transform:scale(2.4);opacity:0}}
</style>
</head>
<body>
<div class="wrap">

<!-- ── Topbar ───────────────────────────────────────────────── -->
<div class="topbar fade-in">
  <div class="topbar-brand">
    <span class="wordmark">Aqua-Vision</span>
    <span class="slash">/</span>
    <span class="page-name">Overview</span>
  </div>
  <div class="topbar-right">
    <div class="ts-line" id="clock">Connecting…</div>
    <span id="syncInd">⟳</span>
    <a href="../../database/export.php" class="btn btn-outline">↓ Export</a>
    <button class="btn btn-outline" onclick="syncNow()">⟳ Refresh</button>
    <button class="btn btn-primary" onclick="location.reload()">↺ Reload</button>
  </div>
</div>

<!-- ── River Status Banner ──────────────────────────────────── -->
<div id="statusBanner" class="river-banner fade-in"
     style="--status-color:<?= $bannerColor ?>;--status-bg:<?= $warnCount===0?'var(--good-bg)':($warnCount<=2?'var(--warn-bg)':'var(--crit-bg)') ?>">
  <div class="banner-status-dot" id="bannerIcon"><?= $warnCount===0?'✅':($warnCount<=2?'⚠️':'🚨') ?></div>
  <div class="banner-body">
    <div class="banner-title" id="bannerTitle">Mangima River &mdash; <?= $riverStatus ?></div>
    <div class="banner-sub" id="bannerSub">
      <?= $warnCount===0 ? 'All sensor readings are within safe thresholds.' : "$warnCount parameter(s) outside safe range" ?>
      <?= $lastTs ? ' · Updated ' . date('H:i', strtotime($lastTs)) : '' ?>
    </div>
  </div>
  <div class="banner-stats">
    <div class="bstat">
      <div class="bstat-v" id="bAlerts"><?= $alertCount ?></div>
      <div class="bstat-l">Alerts</div>
    </div>
    <div class="bstat">
      <div class="bstat-v" id="bDevices"><?= $devCounts['active'] ?>/<?= $devCounts['total'] ?></div>
      <div class="bstat-l">Online</div>
    </div>
    <div class="bstat">
      <div class="bstat-v" id="bLastTs"><?= $lastTs ? date('H:i', strtotime($lastTs)) : '—' ?></div>
      <div class="bstat-l">Last Read</div>
    </div>
  </div>
</div>

<!-- ── KPI Row ──────────────────────────────────────────────── -->
<div class="kpi-row fade-in">
  <div class="kpi">
    <div class="kpi-badge good">📡</div>
    <div class="kpi-label">Active Devices</div>
    <div class="kpi-value"><?= $devCounts['active'] ?></div>
    <div class="kpi-sub">of <?= $devCounts['total'] ?> total · <?= $devCounts['maint'] ?> maintenance</div>
  </div>
  <div class="kpi">
    <div class="kpi-badge <?= $alertCount>0?'crit':'good' ?>">⚠</div>
    <div class="kpi-label">Active Alerts</div>
    <div class="kpi-value" id="kpiAlerts"><?= $alertCount ?></div>
    <div class="kpi-sub"><?= $alertCount===0 ? 'All clear' : 'Requires attention' ?></div>
  </div>
  <div class="kpi">
    <div class="kpi-badge info">📍</div>
    <div class="kpi-label">Monitoring Zones</div>
    <div class="kpi-value"><?= count($mapLocations) ?></div>
    <div class="kpi-sub">Upstream · Midstream · Downstream</div>
  </div>
  <div class="kpi">
    <div class="kpi-badge <?= $warnCount>0?'warn':'good' ?>">🌊</div>
    <div class="kpi-label">River Status</div>
    <div class="kpi-value" style="font-size:20px;font-family:var(--sans);font-weight:600"><?= $riverStatus ?></div>
    <div class="kpi-sub"><?= $warnCount ?> parameter<?= $warnCount!==1?'s':'' ?> out of range</div>
  </div>
</div>

<!-- ── Main Grid: Map + Device Panel ─────────────────────────── -->
<div class="section-head fade-in">
  <div class="section-label">Live Monitoring</div>
</div>

<div class="grid-main fade-in">

  <!-- Map Card -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-l">
        <span class="card-title">Monitoring Locations — Bukidnon</span>
        <span id="simStatus" class="tag tag-mute">● Stopped</span>
      </div>
      <div class="card-head-r">
        <select id="simInterval" class="sel">
          <option value="5000">5 s</option>
          <option value="10000" selected>10 s</option>
          <option value="30000">30 s</option>
          <option value="60000">1 min</option>
        </select>
        <select id="simMode" class="sel">
          <option value="normal">Normal</option>
          <option value="flood">Flood</option>
          <option value="pollution">Pollution</option>
          <option value="drought">Drought</option>
        </select>
        <button id="simStartBtn" onclick="startSim()"
          style="height:30px;padding:0 14px;border-radius:var(--r);font:600 11px var(--sans);cursor:pointer;border:none;background:#7c3aed;color:#fff">
          ▶ Start
        </button>
        <button id="simStopBtn" onclick="stopSim()" disabled
          style="height:30px;padding:0 14px;border-radius:var(--r);font:600 11px var(--sans);cursor:pointer;border:1px solid var(--rule2);background:var(--surf);color:var(--ink3);opacity:.45">
          ■ Stop
        </button>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 300px; gap: 1rem;">
      <!-- Map Container -->
      <div>
        <div id="av-map"></div>
        <div class="map-legend">
          <div class="leg"><span class="leg-dot" style="background:#059669"></span> Upstream</div>
          <div class="leg"><span class="leg-dot" style="background:#d97706"></span> Midstream</div>
          <div class="leg"><span class="leg-dot" style="background:#dc2626"></span> Downstream</div>
          <div class="leg"><span class="leg-dot" style="background:#9ca3af"></span> Offline</div>
        </div>
      </div>

      <!-- Readings Panel -->
      <div style="display: flex; flex-direction: column;">
        <div class="sim-stats" style="grid-template-columns: 1fr 1fr; grid-template-rows: auto auto; gap: 0.75rem; margin-bottom: 1rem;">
          <div class="sim-stat" style="border: 1px solid var(--rule); border-radius: var(--r); padding: 1rem; text-align: center; background: var(--surf);">
            <div class="sim-stat-l">Readings</div>
            <div id="simCount" class="sim-stat-v" style="color:#7c3aed; font-size: 1.5rem;">0</div>
          </div>
          <div class="sim-stat" style="border: 1px solid var(--rule); border-radius: var(--r); padding: 1rem; text-align: center; background: var(--surf);">
            <div class="sim-stat-l">Alerts</div>
            <div id="simAlerts" class="sim-stat-v" style="color:var(--warn); font-size: 1.5rem;">0</div>
          </div>
          <div class="sim-stat" style="border: 1px solid var(--rule); border-radius: var(--r); padding: 1rem; background: var(--surf);">
            <div class="sim-stat-l">Last Read</div>
            <div id="simLastTs" style="font-family:var(--mono);font-size:13px;color:var(--ink);margin-top:4px">—</div>
          </div>
          <div class="sim-stat" style="border: 1px solid var(--rule); border-radius: var(--r); padding: 1rem; background: var(--surf);">
            <div class="sim-stat-l">Latest Values</div>
            <div id="simLastReading" style="font-size:10.5px;color:var(--ink3);font-family:var(--mono);margin-top:4px;line-height:1.6">—</div>
          </div>
        </div>

        <div class="sim-log-wrap" style="flex: 1; display: flex; flex-direction: column;">
          <div class="sim-log-label">Simulation Log</div>
          <div id="simLog" style="flex: 1; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: var(--r); padding: 0.75rem;">
            <div style="color:var(--ink4);font-family:var(--mono);font-size:10.5px">
              Monitoring stopped — press ▶ Start to begin.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Device Panel -->
  <div class="card" style="display:flex;flex-direction:column">
    <div class="card-head">
      <div class="card-head-l">
        <span class="card-title">Device Sensor Data</span>
        <span class="tag tag-info">📡 <?= count($devices) ?></span>
      </div>
      <div class="card-head-r">
        <select id="deviceSelector" class="sel" onchange="showDeviceData(this.value)">
          <option value="">Select device…</option>
          <?php foreach ($devices as $dev): ?>
            <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div id="deviceDataDisplay" class="dev-panel">
      <div class="dev-panel-empty">
        <div class="empty-icon">📡</div>
        <p>Select a device to view real-time sensor readings</p>
      </div>
    </div>
  </div>

</div><!-- /grid-main -->

<!-- ── 24-Hour Trend Chart ───────────────────────────────────── -->
<div class="section-head fade-in">
  <div class="section-label">24-Hour Trends</div>
  <div style="display:flex;align-items:center;gap:8px">
    <select id="chartDeviceId" class="sel" onchange="updateChart()">
      <option value="">All Devices</option>
      <?php foreach ($devices as $dev): ?>
        <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
      <?php endforeach; ?>
    </select>
    <span class="tag tag-info">Live</span>
  </div>
</div>

<div class="card fade-in" style="margin-bottom:24px">
  <div class="chart-wrap">
    <canvas id="trendChart"></canvas>
  </div>
</div>

<!-- ── Alerts + Maintenance ──────────────────────────────────── -->
<div class="section-head fade-in">
  <div class="section-label">Events & Maintenance</div>
</div>

<div class="grid-bottom fade-in">

  <!-- Alerts -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-l">
        <span class="card-title">Active Alerts</span>
      </div>
      <span id="alertPill" class="tag <?= $alertCount>0?'tag-crit':'tag-good' ?>"><?= $alertCount ?> Active</span>
    </div>
    <div id="alertsBody">
      <?php if (empty($alerts)): ?>
        <div class="empty">✓ No active alerts — all sensors nominal.</div>
      <?php else: ?>
        <?php foreach ($alerts as $al):
          $cls = in_array($al['alert_type'],['critical','high']) ? 'crit' : 'warn';
          $em  = $cls==='crit' ? '🚨' : '⚠️';
        ?>
        <div class="alert-item">
          <div class="alert-ic <?= $cls ?>"><?= $em ?></div>
          <div>
            <div class="alert-msg"><?= htmlspecialchars($al['message']) ?></div>
            <div class="alert-meta"><?= htmlspecialchars($al['device_name']) ?> · <?= htmlspecialchars($al['location_name']) ?> · <?= date('H:i, M j', strtotime($al['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Maintenance -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-l"><span class="card-title">Maintenance Logs</span></div>
      <span class="tag tag-info">Recent</span>
    </div>
    <?php if (empty($maints)): ?>
      <div class="empty">No maintenance records found.</div>
    <?php else: ?>
      <?php foreach ($maints as $m):
        $mIc = ['active'=>'✅','inactive'=>'⛔','maintenance'=>'🔧','calibration'=>'📐','repair'=>'🔧','cleaning'=>'🧹'][$m['maintenance_type']] ?? '📋';
      ?>
      <div class="maint-item">
        <div class="maint-ic"><?= $mIc ?></div>
        <div style="flex:1;min-width:0">
          <div class="maint-name"><?= htmlspecialchars($m['device_name']) ?></div>
          <div class="maint-note"><?= htmlspecialchars($m['notes']) ?></div>
        </div>
        <div class="maint-when">
          <?= htmlspecialchars($m['full_name']) ?><br>
          <?= date('M j · H:i', strtotime($m['performed_at'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /grid-bottom -->

<!-- ── Sensor Data Logs (Grouped) ───────────────────────────── -->
<div class="section-head fade-in">
  <div class="section-label">Sensor Data Logs</div>
  <span id="logCount" class="tag tag-info">Latest <?= count($logs) ?></span>
</div>

<div class="card log-section fade-in">
  <!-- Filter bar -->
  <div class="log-filter-bar">
    <span class="log-filter-label">Filter:</span>
    <select id="logFilterDev" class="sel" onchange="renderLogGroups()">
      <option value="">All devices</option>
      <?php foreach ($devices as $dev): ?>
        <option value="<?= $dev['device_id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= ucfirst($dev['river_section']??'') ?>)</option>
      <?php endforeach; ?>
    </select>
    <select id="logFilterSensor" class="sel" onchange="renderLogGroups()">
      <option value="">All sensors</option>
      <option value="temperature">🌡 Temperature</option>
      <option value="pH">🧪 pH Level</option>
      <option value="turbidity">🌫 Turbidity</option>
      <option value="dissolved_oxygen">💧 Dissolved O₂</option>
      <option value="water_level">🌊 Water Level</option>
    </select>
    <select id="logFilterStatus" class="sel" onchange="renderLogGroups()">
      <option value="">All readings</option>
      <option value="normal">Normal only</option>
      <option value="warn">Out of range</option>
    </select>
  </div>
  <div id="logGroupsBody"></div>
</div>

</div><!-- /wrap -->

<!-- ════════════════════════════════════════════════════════════ -->
<!--  JavaScript                                                  -->
<!-- ════════════════════════════════════════════════════════════ -->
<script>
const SELF = 'dashboard.php';

// ── Clock ─────────────────────────────────────────────────────
function updateClock() {
  document.getElementById('clock').textContent =
    new Date().toLocaleString('en-PH', {weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
}
updateClock(); setInterval(updateClock, 1000);

// ── Helper ────────────────────────────────────────────────────
function _e(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Chart ─────────────────────────────────────────────────────
const hours = Array.from({length:24},(_,i)=>{
  const h=(new Date().getHours()-23+i+24)%24;
  return String(h).padStart(2,'0')+':00';
});
const dbData = <?= json_encode($chartData, JSON_NUMERIC_CHECK) ?>;
const allChartData = <?= json_encode($allChartData ?? [], JSON_NUMERIC_CHECK) ?>;

const CHART_DS = [
  {label:'Turbidity (NTU)',    color:'#d97706', data:dbData.turbidity,        yAxisID:'y'},
  {label:'pH Level',           color:'#3b82f6', data:dbData.pH,               yAxisID:'y1'},
  {label:'Temperature (°C)',   color:'#ef4444', data:dbData.temperature,      yAxisID:'y'},
  {label:'Dissolved O₂ (mg/L)',color:'#10b981', data:dbData.dissolved_oxygen, yAxisID:'y'},
  {label:'Water Level (m)',    color:'#8b5cf6', data:dbData.water_level,      yAxisID:'y'},
];

Chart.defaults.font.family = "'JetBrains Mono', monospace";
Chart.defaults.color = '#8897aa';

const chart = new Chart(document.getElementById('trendChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: hours,
    datasets: CHART_DS.map(d => ({
      label: d.label, data: d.data,
      borderColor: d.color,
      backgroundColor: d.color + '14',
      borderWidth: 1.5, pointRadius: 1.5, pointHoverRadius: 4,
      fill: false, tension: .4, spanGaps: true, yAxisID: d.yAxisID,
    }))
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    interaction: {mode:'index', intersect:false},
    plugins: {
      legend: {
        display: true, position: 'top',
        labels: {boxWidth:10, padding:14, font:{size:10}, usePointStyle:true, pointStyleWidth:8},
      },
      tooltip: {mode:'index', intersect:false, backgroundColor:'rgba(13,17,23,.92)', padding:10, cornerRadius:6}
    },
    scales: {
      x: {
        grid: {color:'rgba(13,17,23,.04)'},
        ticks: {font:{size:9}, maxTicksLimit:8}
      },
      y: {
        type:'linear', display:true, position:'left',
        grid: {color:'rgba(13,17,23,.04)'},
        ticks: {font:{size:9}},
        title: {display:true, text:'Turbidity / Temp / DO / Level', font:{size:9}}
      },
      y1: {
        type:'linear', display:true, position:'right',
        grid: {drawOnChartArea:false},
        ticks: {font:{size:9}, color:'#3b82f6'},
        title: {display:true, text:'pH', font:{size:9}}
      }
    }
  }
});

function updateChart() {
  const deviceId = document.getElementById('chartDeviceId').value;
  if (!deviceId) {
    chart.data.datasets.forEach((ds, i) => { ds.data = dbData[Object.keys(dbData)[i]]; });
  } else {
    const dd = allChartData[deviceId] || {temperature:Array(24).fill(null),pH:Array(24).fill(null),turbidity:Array(24).fill(null),dissolved_oxygen:Array(24).fill(null),water_level:Array(24).fill(null)};
    chart.data.datasets.forEach((ds, i) => { ds.data = dd[Object.keys(dbData)[i]]; });
  }
  chart.update();
}

// ── Device Panel ──────────────────────────────────────────────
let DEV_READINGS = <?= json_encode(
  array_combine(
    array_column($devices,'device_id'),
    array_map(fn($d)=>$deviceReadings[$d['device_id']]??null,$devices)
  ), JSON_NUMERIC_CHECK) ?>;

let DEV_INFO = <?= json_encode(
  array_combine(
    array_column($devices,'device_id'),
    array_map(fn($d)=>['name'=>$d['device_name'],'location'=>$d['location_name'],'section'=>$d['river_section']??'','status'=>$d['status']],$devices)
  )) ?>;

const SENSORS = [
  {col:'temperature',      icon:'🌡', label:'Temperature',     unit:'°C',  min:20,  max:35 },
  {col:'ph_level',         icon:'🧪', label:'pH Level',        unit:'pH',  min:6.5, max:8.5},
  {col:'turbidity',        icon:'🌫', label:'Turbidity',       unit:'NTU', min:0,   max:50 },
  {col:'dissolved_oxygen', icon:'💧', label:'Dissolved O₂',   unit:'mg/L',min:5,   max:14 },
  {col:'water_level',      icon:'🌊', label:'Water Level',     unit:'m',   min:0.5, max:3.0},
];

const SECT_TAG = {
  upstream:   'tag-up',
  midstream:  'tag-mid',
  downstream: 'tag-down',
};
const SECT_BADGE_BG = {
  upstream:   '#d1fae5',
  midstream:  '#fef3c7',
  downstream: '#fee2e2',
};
const SECT_LABEL = {upstream:'Upstream', midstream:'Midstream', downstream:'Downstream'};

function showDeviceData(deviceId) {
  const display = document.getElementById('deviceDataDisplay');
  const id = parseInt(deviceId, 10);
  const sel = document.getElementById('deviceSelector');
  if (sel && sel.value !== String(id||'')) sel.value = id || '';
  if (!id) {
    display.innerHTML = `<div class="dev-panel-empty"><div class="empty-icon">📡</div><p>Select a device to view real-time sensor readings</p></div>`;
    return;
  }
  const info = DEV_INFO[id];
  const data = DEV_READINGS[id];
  if (!info) { display.innerHTML = '<div class="empty">Device not found.</div>'; return; }

  const stMap = {
    active:      {color:'#059669', label:'Active'},
    maintenance: {color:'#3b82f6', label:'Maintenance'},
    inactive:    {color:'#dc2626', label:'Offline'},
  };
  const st  = stMap[info.status] || stMap.inactive;
  const sec = SECT_LABEL[info.section] || info.section;
  const ts  = (data && data.recorded_at)
    ? new Date(data.recorded_at.replace(' ','T')).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',hour12:false})
    : null;
  const tagCls = SECT_TAG[info.section] || 'tag-info';

  let html = `
    <div class="dev-header">
      <div>
        <div class="dev-name">
          <span style="width:7px;height:7px;border-radius:50%;background:${st.color};display:inline-block;flex-shrink:0"></span>
          ${_e(info.name)}
        </div>
        <div class="dev-loc">📍 ${_e(info.location)}${sec ? ' &mdash; ' + sec : ''}</div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <span class="tag ${tagCls}">${sec}</span>
        ${ts ? `<div style="font-family:var(--mono);font-size:10px;color:var(--ink4);margin-top:4px">${ts}</div>` : ''}
      </div>
    </div>`;

  if (!data) {
    html += '<div class="empty">No readings recorded for this device.</div>';
  } else {
    SENSORS.forEach(s => {
      const raw  = data[s.col];
      const hasV = raw !== null && raw !== undefined;
      const val  = hasV ? parseFloat(raw) : null;
      const good = hasV ? (val >= s.min && val <= s.max) : null;
      const vc   = good===true ? '#059669' : good===false ? '#d97706' : 'var(--ink4)';
      const cls  = good===false ? ' out' : '';
      const pill = good===true
        ? '<span class="tag tag-good">✓ Normal</span>'
        : good===false
          ? `<span class="tag tag-warn">⚠ ${val < s.min ? 'Low' : 'High'}</span>`
          : '<span class="tag tag-mute">— No data</span>';

      html += `
        <div class="sensor-row${cls}">
          <div class="sensor-icon">${s.icon}</div>
          <div class="sensor-label">
            <div class="sensor-name">${s.label}</div>
            <div class="sensor-range">Safe ${s.min} – ${s.max} ${s.unit}</div>
          </div>
          <div class="sensor-val" style="color:${vc}">
            ${hasV ? val.toFixed(val%1===0?0:1) : '—'}
            ${hasV ? `<span class="unit">${s.unit}</span>` : ''}
          </div>
          <div class="sensor-status">${pill}</div>
        </div>`;
    });
  }
  display.innerHTML = html;
}

// ── Sync Engine ───────────────────────────────────────────────
let _syncTimer = null, _syncBusy = false;
function startSync(ms) { stopSync(); syncNow(); _syncTimer = setInterval(syncNow, ms || 10000); }
function stopSync() { if (_syncTimer) { clearInterval(_syncTimer); _syncTimer = null; } }

async function syncNow() {
  if (_syncBusy) return; _syncBusy = true;
  try {
    const res = await fetch(`${SELF}?action=fetch&_=${Date.now()}`);
    if (!res.ok) return;
    const d = await res.json();
    if (!d.ok) return;
    _applySync(d);
  } catch(_) {}
  finally { _syncBusy = false; }
}

function _applySync(d) {
  // Banner
  const banner = document.getElementById('statusBanner');
  if (banner) {
    banner.style.setProperty('--status-color', d.banner_color);
    banner.style.setProperty('--status-bg', d.warn_count===0?'var(--good-bg)':d.warn_count<=2?'var(--warn-bg)':'var(--crit-bg)');
    const icon = document.getElementById('bannerIcon'); if (icon) icon.textContent = d.banner_emoji;
    const t = document.getElementById('bannerTitle'); if (t) t.textContent = `Mangima River — ${d.river_status}`;
    const s = document.getElementById('bannerSub'); if (s) s.textContent = d.warn_count===0 ? 'All sensor readings are within safe thresholds.' : `${d.warn_count} parameter(s) outside safe range`;
    const bA = document.getElementById('bAlerts'); if (bA) bA.textContent = d.alert_count;
    const bD = document.getElementById('bDevices'); if (bD) bD.textContent = `${d.dev_counts.active}/${d.dev_counts.total}`;
    if (d.logs && d.logs.length > 0) {
      const bT = document.getElementById('bLastTs');
      if (bT) { const ts = new Date(d.logs[0].recorded_at.replace(' ','T')); bT.textContent = ts.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:false}); }
    }
  }
  // KPI alerts
  const ka = document.getElementById('kpiAlerts'); if (ka) ka.textContent = d.alert_count;

  // DEV_READINGS + DEV_INFO
  d.devices.forEach(dv => {
    DEV_READINGS[dv.device_id] = d.device_readings[dv.device_id] || null;
    DEV_INFO[dv.device_id] = {name:dv.device_name, location:dv.location_name, section:dv.river_section, status:dv.status};
  });
  const sel = document.getElementById('deviceSelector');
  const curId = sel ? parseInt(sel.value) || 0 : 0;
  if (curId) showDeviceData(curId);
  else if (d.devices.length > 0 && sel && !sel.value) { sel.value = d.devices[0].device_id; showDeviceData(d.devices[0].device_id); }

  // Chart
  if (d.chart_data) {
    Object.keys(dbData).forEach(key => { dbData[key] = d.chart_data[key] || Array(24).fill(null); });
    Object.keys(d.device_chart_data || {}).forEach(did => { allChartData[did] = d.device_chart_data[did]; });
    updateChart();
  }

  // Alerts
  const ap = document.getElementById('alertPill');
  if (ap) { ap.textContent = `${d.alert_count} Active`; ap.className = `tag ${d.alert_count>0?'tag-crit':'tag-good'}`; }
  const ab = document.getElementById('alertsBody');
  if (ab) {
    if (!d.alerts.length) { ab.innerHTML = '<div class="empty">✓ No active alerts — all sensors nominal.</div>'; }
    else ab.innerHTML = d.alerts.map(al => {
      const cls = (['critical','high'].includes(al.alert_type)) ? 'crit' : 'warn';
      const em  = cls==='crit' ? '🚨' : '⚠️';
      const ts  = new Date(al.created_at.replace(' ','T')).toLocaleString('en-PH',{hour:'2-digit',minute:'2-digit',month:'short',day:'numeric',hour12:false});
      return `<div class="alert-item"><div class="alert-ic ${cls}">${em}</div><div><div class="alert-msg">${_e(al.message)}</div><div class="alert-meta">${_e(al.device_name)} · ${_e(al.location_name)} · ${ts}</div></div></div>`;
    }).join('');
  }

  // Logs
  if (d.logs && d.logs.length > 0) {
    buildLogGroups(d.logs);
    renderLogGroups();
    const lc = document.getElementById('logCount'); if (lc) lc.textContent = `Latest ${d.logs.length}`;
  }

  // Map markers
  const sc = {upstream:'#059669', midstream:'#d97706', downstream:'#dc2626'};
  (d.map_locations || []).forEach(loc => {
    const m = _mapMk[loc.location_id]; if (!m) return;
    const allOff = loc.total_devices > 0 && loc.active_devices == 0 && loc.maint_devices == 0;
    m.setStyle({fillColor: allOff ? '#9ca3af' : (sc[loc.river_section] || '#3b82f6')});
  });

  // Sync indicator
  const ind = document.getElementById('syncInd');
  if (ind) {
    ind.style.opacity = '1'; ind.style.color = '#059669';
    ind.textContent = '⟳ ' + new Date().toLocaleTimeString('en-PH',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
    setTimeout(() => { ind.style.opacity = '.4'; ind.style.color = ''; }, 2000);
  }
}

// ── Live Simulator ────────────────────────────────────────────
const SIM_DEVICES = <?= json_encode(array_column($devices,'device_id'), JSON_NUMERIC_CHECK) ?>;
const MODES = {
  normal:    {temperature:{base:27,drift:1.5,min:24,max:30},ph_level:{base:7.2,drift:0.2,min:6.8,max:7.6},turbidity:{base:20,drift:8,min:5,max:45},dissolved_oxygen:{base:7.5,drift:0.5,min:6.5,max:8.5},water_level:{base:1.5,drift:0.1,min:1.2,max:1.8}},
  flood:     {temperature:{base:26,drift:1,min:24,max:28},ph_level:{base:6.8,drift:0.3,min:6.2,max:7.2},turbidity:{base:120,drift:30,min:60,max:200},dissolved_oxygen:{base:5.5,drift:0.8,min:4.0,max:6.5},water_level:{base:2.7,drift:0.2,min:2.3,max:3.5}},
  pollution: {temperature:{base:29,drift:1,min:27,max:32},ph_level:{base:5.8,drift:0.4,min:5.0,max:6.8},turbidity:{base:80,drift:20,min:40,max:130},dissolved_oxygen:{base:3.5,drift:0.5,min:2.5,max:4.5},water_level:{base:1.4,drift:0.1,min:1.1,max:1.6}},
  drought:   {temperature:{base:33,drift:1.5,min:30,max:37},ph_level:{base:8.0,drift:0.3,min:7.5,max:8.7},turbidity:{base:8,drift:3,min:3,max:15},dissolved_oxygen:{base:9.0,drift:0.5,min:8.0,max:10},water_level:{base:0.4,drift:0.05,min:0.3,max:0.6}},
};
const _ds = {}; let _st = null, _sc = 0, _sac = 0, _di = 0;

function _initDs(id, mode) { const m = MODES[mode]||MODES.normal; _ds[id]={}; for(const[k,cfg]of Object.entries(m)) _ds[id][k]=+(cfg.base+(Math.random()-.5)*cfg.drift).toFixed(2); }
function _next(id, key, mode) { const cfg=(MODES[mode]||MODES.normal)[key]; if(!_ds[id])_initDs(id,mode); let v=_ds[id][key]+(Math.random()-.5)*cfg.drift*.35; v=Math.max(cfg.min,Math.min(cfg.max,v)); _ds[id][key]=v; return+v.toFixed(2); }
function _slog(msg, color) {
  const log = document.getElementById('simLog'); if(!log) return;
  const now = new Date().toLocaleTimeString('en-PH',{hour12:false});
  const d = document.createElement('div');
  d.style.cssText = `color:${color||'var(--ink3)'};padding:1px 0;font-family:var(--mono);font-size:10.5px`;
  d.textContent = `[${now}]  ${msg}`;
  log.insertBefore(d, log.firstChild);
  while(log.children.length > 60) log.removeChild(log.lastChild);
}

async function _sendTick() {
  const mode = document.getElementById('simMode').value;
  const ids = SIM_DEVICES; // Use all devices
  if (!ids.length) { _slog('No active devices.', 'var(--warn)'); return; }
  const id = ids[_di % ids.length]; _di++;
  const p = {device_id:id, temperature:_next(id,'temperature',mode), ph_level:_next(id,'ph_level',mode), turbidity:_next(id,'turbidity',mode), dissolved_oxygen:_next(id,'dissolved_oxygen',mode), water_level:_next(id,'water_level',mode)};
  try {
    const res = await fetch(`${SELF}?action=simulate`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});
    const data = await res.json();
    if (!res.ok || data.error) { _slog(`ERROR: ${data.error||res.status}`, 'var(--crit)'); return; }
    _sc++;
    document.getElementById('simCount').textContent = _sc;
    document.getElementById('simLastTs').textContent = new Date().toLocaleTimeString('en-PH',{hour12:false});
    document.getElementById('simLastReading').textContent = `${data.device_name} | T:${p.temperature} pH:${p.ph_level} Tu:${p.turbidity} DO:${p.dissolved_oxygen} Lv:${p.water_level}`;
    if (data.alerts_created && data.alerts_created.length > 0) {
      _sac += data.alerts_created.length;
      document.getElementById('simAlerts').textContent = _sac;
      data.alerts_created.forEach(a => _slog(`⚠ ALERT [${a.type.toUpperCase()}] ${a.message}`, 'var(--warn)'));
    }
    _slog(`✓ #${data.reading_id} ${data.device_name} — T:${p.temperature} pH:${p.ph_level} Tu:${p.turbidity} DO:${p.dissolved_oxygen} Lv:${p.water_level}`, 'var(--good)');
    await syncNow();
  } catch(err) { _slog(`Fetch error: ${err.message}`, 'var(--crit)'); }
}

function startSim() {
  if (_st) return;
  const ms   = parseInt(document.getElementById('simInterval').value);
  const mode = document.getElementById('simMode').value;
  const ids  = SIM_DEVICES; // Simulate all devices
  ids.forEach(id => _initDs(id, mode)); _di = 0;
  _st = setInterval(_sendTick, ms);
  document.getElementById('simStatus').textContent = ' Running';
  document.getElementById('simStatus').className = 'tag tag-good';
  document.getElementById('simStartBtn').disabled = true;
  document.getElementById('simStopBtn').disabled = false;
  document.getElementById('simStopBtn').style.opacity = '1';
  _slog(` Started — mode:${mode} · interval:${ms/1000}s · devices:[${ids.join(',')}]`, '#7c3aed');
  saveMonitorState(true);
  _sendTick();
}
function stopSim() {
  if (!_st) return;
  clearInterval(_st); _st = null;
  document.getElementById('simStatus').textContent = ' Stopped';
  document.getElementById('simStatus').className = 'tag tag-mute';
  document.getElementById('simStartBtn').disabled = false;
  document.getElementById('simStopBtn').disabled = true;
  document.getElementById('simStopBtn').style.opacity = '.45';
  _slog('■ Stopped.', 'var(--ink4)');
  saveMonitorState(false);
}

// ── Persist Monitor State ─────────────────────────────────────
async function saveMonitorState(running) {
  const mode = document.getElementById('simMode')?.value || 'normal';
  const deviceId = parseInt(document.getElementById('simDeviceId')?.value) || 0;
  const interval = parseInt(document.getElementById('simInterval')?.value) || 10000;
  try {
    await fetch(`${SELF}?action=monitor_state`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({running,mode,device_id:deviceId,interval})});
  } catch(e) {}
}
async function loadMonitorState() {
  try {
    const res = await fetch(`${SELF}?action=monitor_state&_=${Date.now()}`);
    if (!res.ok) return null;
    const data = await res.json();
    return data.ok ? data.state : null;
  } catch(e) { return null; }
}
async function restoreMonitorIfRunning() {
  const state = await loadMonitorState();
  if (state && state.running) {
    const ms = document.getElementById('simMode'); if(ms&&state.mode) ms.value=state.mode;
    const ds = document.getElementById('simDeviceId'); if(ds&&state.device_id) ds.value=state.device_id;
    const is = document.getElementById('simInterval'); if(is&&state.interval) is.value=state.interval;
    _slog(`↻ Resuming (mode:${state.mode}, started ${state.started_at})`, '#7c3aed');
    startSim();
  }
}

// ── Log Groups ────────────────────────────────────────────────
const SENSOR_META = {
  temperature:      {icon:'🌡', label:'Temperature',   unit:'°C',  min:20,  max:35 },
  pH:               {icon:'🧪', label:'pH Level',      unit:'pH',  min:6.5, max:8.5},
  turbidity:        {icon:'🌫', label:'Turbidity',     unit:'NTU', min:0,   max:50 },
  dissolved_oxygen: {icon:'💧', label:'Dissolved O₂', unit:'mg/L',min:5,   max:14 },
  water_level:      {icon:'🌊', label:'Water Level',   unit:'m',   min:0.5, max:3.0},
};

let LOG_GROUPS = {};
function buildLogGroups(logsArray) {
  LOG_GROUPS = {};
  (logsArray || []).forEach(log => {
    const key = log.device_name + '||' + log.location_name;
    if (!LOG_GROUPS[key]) LOG_GROUPS[key] = {device_name:log.device_name, location_name:log.location_name, readings:{}};
    const existing = LOG_GROUPS[key].readings[log.sensor_type];
    if (!existing || log.recorded_at > existing.recorded_at) LOG_GROUPS[key].readings[log.sensor_type] = log;
  });
}

function renderLogGroups() {
  const filterDev    = document.getElementById('logFilterDev')?.value    || '';
  const filterSensor = document.getElementById('logFilterSensor')?.value || '';
  const filterStatus = document.getElementById('logFilterStatus')?.value || '';

  let html = '', anyVisible = false;

  Object.values(LOG_GROUPS).forEach(group => {
    const devEntry = Object.values(DEV_INFO).find(d => d.name === group.device_name);
    const section  = devEntry?.section || '';
    const devId    = Object.keys(DEV_INFO).find(id => DEV_INFO[id].name === group.device_name) || '';
    if (filterDev && String(devId) !== String(filterDev)) return;

    const timestamps = Object.values(group.readings).map(r=>r.recorded_at).filter(Boolean).sort().reverse();
    const lastTs = timestamps[0]
      ? new Date(timestamps[0].replace(' ','T')).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false})
      : '—';

    let rowsHtml = '', rowCount = 0;
    Object.entries(SENSOR_META).forEach(([type, meta]) => {
      if (filterSensor && type !== filterSensor) return;
      const reading = group.readings[type]; if (!reading) return;
      const val  = parseFloat(reading.value);
      const good = val >= meta.min && val <= meta.max;
      if (filterStatus === 'normal' && !good) return;
      if (filterStatus === 'warn'   &&  good) return;
      const vc   = good ? '#059669' : '#d97706';
      const pill = good ? '<span class="tag tag-good">✓ Normal</span>'
                        : `<span class="tag tag-warn">⚠ ${val<meta.min?'Low':'High'}</span>`;
      rowsHtml += `<tr${!good?' class="out"':''}><td class="icon-c">${meta.icon}</td><td class="name-c">${meta.label}</td><td class="val-c" style="color:${vc}">${val.toFixed(val%1===0?0:1)} <span style="font-size:11px;color:var(--ink4);font-weight:400">${meta.unit}</span></td><td class="range-c">${meta.min} – ${meta.max} ${meta.unit}</td><td class="status-c">${pill}</td></tr>`;
      rowCount++;
    });
    if (!rowCount) return;
    anyVisible = true;

    const bgBadge = SECT_BADGE_BG[section] || '#eff4ff';
    const tagCls  = SECT_TAG[section] || 'tag-info';
    const secLbl  = SECT_LABEL[section] || section;

    html += `
      <div style="border-bottom:1px solid var(--rule)">
        <div class="dev-group-head" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'':'none';this.querySelector('.dev-group-chevron').classList.toggle('open')">
          <div class="dev-group-badge" style="background:${bgBadge}">📡</div>
          <div style="flex:1;min-width:0">
            <div class="dev-group-name">${_e(group.device_name)}</div>
            <div class="dev-group-loc">📍 ${_e(group.location_name)}</div>
          </div>
          <div class="dev-group-meta">
            <span class="tag ${tagCls}">${secLbl}</span>
            <div class="dev-group-ts">${lastTs}</div>
          </div>
          <div class="dev-group-chevron open">▼</div>
        </div>
        <div><table class="metric-tbl">${rowsHtml}</table></div>
      </div>`;
  });

  const body = document.getElementById('logGroupsBody');
  if (!body) return;
  body.innerHTML = anyVisible
    ? html
    : '<div class="empty">No readings match the selected filters.</div>';
}

// ── Leaflet Map ───────────────────────────────────────────────
const locs = <?= json_encode(array_map(fn($l)=>['id'=>(int)$l['location_id'],'name'=>$l['location_name'],'lat'=>(float)$l['latitude'],'lng'=>(float)$l['longitude'],'section'=>$l['river_section'],'total'=>(int)$l['total_devices'],'active'=>(int)$l['active_devices'],'maint'=>(int)$l['maint_devices'],'device_id'=>$l['device_id']??null],$mapLocations)) ?>;
const locationDevices = <?= json_encode($locationDevices, JSON_NUMERIC_CHECK) ?>;
const _mapMk = {};

(function() {
  const avMap = L.map('av-map', {zoomControl:false}).setView([8.374, 124.903], 13);
  L.control.zoom({position:'bottomright'}).addTo(avMap);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {attribution:'© OpenStreetMap © CartoDB', subdomains:'abcd', maxZoom:19}).addTo(avMap);

  if (!document.getElementById('av-rs')) {
    const s = document.createElement('style'); s.id='av-rs';
    s.textContent = '@keyframes av-ripple{0%{transform:scale(.6);opacity:.9}100%{transform:scale(2.4);opacity:0}}';
    document.head.appendChild(s);
  }

  const R = [[8.346139,124.897389],[8.345958,124.898607],[8.346955,124.899036],[8.347603,124.898081],[8.349471,124.896461],[8.349216,124.895474],[8.349535,124.894755],[8.348909,124.894058],[8.349881,124.893209],[8.352050,124.889584],[8.351096,124.889497],[8.351978,124.888415],[8.352369,124.887056],[8.352210,124.886676],[8.352643,124.886427],[8.353468,124.884863],[8.355492,124.883376],[8.356292,124.881332],[8.358270,124.881140],[8.368532,124.875713],[8.373977,124.876690],[8.381657,124.897203],[8.394810,124.903483],[8.396343,124.907500],[8.399906,124.911121],[8.400757,124.910773],[8.401360,124.910322]];

  L.polyline(R,{color:'#0d1117',weight:11,opacity:.05}).addTo(avMap);
  L.polyline(R,{color:'#1a56db',weight:5,opacity:.35}).addTo(avMap);
  L.polyline(R,{color:'#60a5fa',weight:2.5,opacity:.6}).addTo(avMap);
  const fl = L.polyline(R,{color:'#93c5fd',weight:1.5,opacity:.4,dashArray:'8 16',dashOffset:'0'}).addTo(avMap);
  let doff = 0; setInterval(()=>{doff-=1.5;fl.setStyle({dashOffset:String(doff)});},60);

  [3,7,10,14,18,22].forEach(i => {
    if (i >= R.length-1) return;
    const from=R[i],to=R[i+1],lat=(from[0]+to[0])/2,lng=(from[1]+to[1])/2;
    const angle=Math.atan2(to[1]-from[1],to[0]-from[0])*180/Math.PI-90;
    L.marker([lat,lng],{icon:L.divIcon({html:`<div style="transform:rotate(${angle}deg);color:#60a5fa;font-size:9px;opacity:.6">▲</div>`,iconSize:[10,10],iconAnchor:[5,5],className:''}),interactive:false}).addTo(avMap);
  });

  function pIcon(color,label) {
    return L.divIcon({html:`<div style="position:relative;width:40px;height:40px"><div style="position:absolute;inset:0;border-radius:50%;background:${color};opacity:.12;animation:av-ripple 2s ease-out infinite"></div><div style="position:absolute;inset:8px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.15)"></div><div style="position:absolute;bottom:-16px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:9px;font-weight:600;color:${color};font-family:'Instrument Sans',sans-serif">${label}</div></div>`,iconSize:[40,40],iconAnchor:[20,20],className:''});
  }

  L.marker([8.346138,124.897384],{icon:pIcon('#059669','START')}).addTo(avMap);
  L.marker([8.401360,124.910322],{icon:pIcon('#dc2626','END')}).addTo(avMap);
  L.marker([8.368,124.882],{icon:L.divIcon({html:`<div style="font-family:'Instrument Serif',serif;font-size:12px;font-style:italic;color:#1a56db;opacity:.5;white-space:nowrap;transform:rotate(42deg)">Mangima River</div>`,iconSize:[130,20],iconAnchor:[65,10],className:''}),interactive:false}).addTo(avMap);

  const sC = {upstream:'#059669', midstream:'#d97706', downstream:'#dc2626'};
  const sL = {upstream:'Upstream', midstream:'Midstream', downstream:'Downstream'};

  locs.forEach(loc => {
    const color = sC[loc.section] || '#1a56db';
    const devs  = locationDevices[loc.id] || [];
    const dHtml = devs.length > 0
      ? `<div style="margin:8px 0;padding-top:8px;border-top:1px solid #f0f0f0"><div style="font-size:10px;font-weight:600;color:#0d1117;margin-bottom:4px;letter-spacing:.04em;text-transform:uppercase">Devices</div>${devs.map(d=>{const c=d.status==='active'?'#059669':d.status==='maintenance'?'#3b82f6':'#9ca3af';return`<div style="display:flex;align-items:center;justify-content:space-between;padding:4px 8px;border-radius:4px;background:#f9fafb;margin-bottom:2px"><span style="font-size:11px;color:#0d1117;display:flex;align-items:center;gap:5px"><span style="width:5px;height:5px;border-radius:50%;background:${c};display:inline-block"></span>${d.device_name}</span><span style="font-size:10px;color:${c};font-weight:600">${d.status==='active'?'Active':d.status==='maintenance'?'Maint.':'Offline'}</span></div>`}).join('')}</div>`
      : `<div style="margin:8px 0;font-size:11px;color:#9ca3af;padding-top:8px;border-top:1px solid #f0f0f0">No devices assigned</div>`;

    const marker = L.circleMarker([loc.lat,loc.lng],{radius:12,fillColor:color,color:'#fff',weight:2.5,fillOpacity:.95}).addTo(avMap);
    _mapMk[loc.id] = marker;
    marker.bindPopup(`<div style="font-family:'Instrument Sans',sans-serif;min-width:210px"><div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><div style="width:8px;height:8px;border-radius:50%;background:${color}"></div><div style="font-size:13px;font-weight:600;color:#0d1117">${sL[loc.section]||loc.section}</div></div><div style="font-size:11px;color:#3d4a5c;margin-bottom:4px">${loc.name}</div>${dHtml}<div style="display:flex;gap:6px;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0"><button onclick="event.stopPropagation();window.location.href='devices.php?action=edit_location&loc_id=${loc.id}'" style="flex:1;padding:5px;font-size:11px;border:1px solid #1a56db;background:#eff4ff;color:#1a56db;border-radius:5px;cursor:pointer;font-family:inherit">Edit</button><button onclick="event.stopPropagation();if(confirm('Delete ${loc.name}?'))window.location.href='devices.php?action=delete_location&loc_id=${loc.id}'" style="flex:1;padding:5px;font-size:11px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;border-radius:5px;cursor:pointer;font-family:inherit">Delete</button></div><div style="font-size:10px;color:#8897aa;margin-top:6px;font-family:'JetBrains Mono',monospace;text-align:center">${loc.lat.toFixed(5)}°N · ${loc.lng.toFixed(5)}°E</div></div>`,{maxWidth:250});
    marker.on('click', () => { if(loc.device_id) showDeviceData(loc.device_id); });
    L.tooltip({permanent:true,direction:'bottom',offset:[0,12]}).setContent(`<span style="font-size:9px;font-weight:600;color:#3d4a5c;font-family:'Instrument Sans',sans-serif;letter-spacing:.04em;text-transform:uppercase">${sL[loc.section]||loc.section}</span>`).setLatLng([loc.lat,loc.lng]).addTo(avMap);
  });

  const allPts = [...R, ...locs.map(l=>[l.lat,l.lng])];
  const bounds = L.latLngBounds(allPts);
  if (bounds.isValid()) avMap.fitBounds(bounds.pad(.12));
})();

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('deviceSelector');
  if (sel && sel.options.length > 1) showDeviceData(sel.options[1].value);
  buildLogGroups(<?= json_encode($logs, JSON_NUMERIC_CHECK) ?>);
  renderLogGroups();
  startSync(10000);
  restoreMonitorIfRunning();
});
</script>
</body>
</html>