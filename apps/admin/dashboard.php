<?php
/**
 * Aqua-Vision — Overview Dashboard
 * Location: apps/admin/dashboard.php
 */
require_once '../../database/config.php';
$currentPage = 'overview';

// ── 1. Device counts ─────────────────────────────────────────
$devCounts = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(status='active')      AS active,
           SUM(status='inactive')    AS offline,
           SUM(status='maintenance') AS maint
    FROM devices
")->fetch_assoc();

// ── 2. Alert count ───────────────────────────────────────────
$alertCount = (int)$conn->query("
    SELECT COUNT(*) AS cnt FROM alerts WHERE status='active'
")->fetch_assoc()['cnt'];

// ── 3. Latest reading per sensor ────────────────────────────
$latestRes = $conn->query("
    SELECT s.sensor_id, s.sensor_type, s.unit,
           s.min_threshold, s.max_threshold,
           sr.value, sr.recorded_at,
           l.location_name, l.river_section,
           d.device_name, d.status AS device_status, d.device_id
    FROM sensors s
    JOIN sensor_readings sr ON sr.reading_id = (
        SELECT MAX(r2.reading_id) FROM sensor_readings r2 WHERE r2.sensor_id = s.sensor_id
    )
    JOIN devices d  ON d.device_id  = s.device_id
    JOIN locations l ON l.location_id = d.location_id
    ORDER BY s.sensor_type, l.river_section
");
$allRows = [];
$byType  = [];
while ($row = $latestRes->fetch_assoc()) {
    $allRows[] = $row;
    $byType[$row['sensor_type']][] = $row;
}

// ── 4. Previous reading per sensor (for trend arrows) ────────
$prevRes = $conn->query("
    SELECT s.sensor_type, sr.value
    FROM sensors s
    JOIN sensor_readings sr ON sr.reading_id = (
        SELECT r2.reading_id FROM sensor_readings r2
        WHERE r2.sensor_id = s.sensor_id
        ORDER BY r2.reading_id DESC
        LIMIT 1 OFFSET 1
    )
");
$prevByType = [];
if ($prevRes) {
    while ($r = $prevRes->fetch_assoc()) {
        if (!isset($prevByType[$r['sensor_type']]))
            $prevByType[$r['sensor_type']] = (float)$r['value'];
    }
}

// ── 5. Active alerts ─────────────────────────────────────────
$alertsRes = $conn->query("
    SELECT a.alert_id, a.alert_type, a.message, a.created_at,
           s.sensor_type, s.unit, sr.value,
           l.location_name, d.device_name
    FROM alerts a
    JOIN sensors s         ON s.sensor_id  = a.sensor_id
    JOIN sensor_readings sr ON sr.reading_id = a.reading_id
    JOIN devices d         ON d.device_id  = s.device_id
    JOIN locations l       ON l.location_id = d.location_id
    WHERE a.status = 'active'
    ORDER BY a.created_at DESC
    LIMIT 5
");
$alerts = [];
while ($r = $alertsRes->fetch_assoc()) $alerts[] = $r;

// ── 6. Device list ───────────────────────────────────────────
$devicesRes = $conn->query("
    SELECT d.device_id, d.device_name, d.device_type, d.status, d.last_active,
           l.location_name, l.river_section
    FROM devices d
    JOIN locations l ON l.location_id = d.location_id
    ORDER BY l.river_section, d.device_name
");
$devices = [];
while ($r = $devicesRes->fetch_assoc()) $devices[] = $r;

// ── 7. Sensor logs (latest 10) ───────────────────────────────
$logsRes = $conn->query("
    SELECT sr.recorded_at, d.device_name, l.location_name,
           s.sensor_type, s.unit, s.min_threshold, s.max_threshold, sr.value
    FROM sensor_readings sr
    JOIN sensors s   ON s.sensor_id   = sr.sensor_id
    JOIN devices d   ON d.device_id   = s.device_id
    JOIN locations l ON l.location_id = d.location_id
    ORDER BY sr.recorded_at DESC
    LIMIT 10
");
$logs = [];
while ($r = $logsRes->fetch_assoc()) $logs[] = $r;

// ── 8. Maintenance logs (latest 3) ───────────────────────────
$maintRes = $conn->query("
    SELECT ml.maintenance_type, ml.notes, ml.performed_at,
           d.device_name, u.full_name
    FROM maintenance_logs ml
    JOIN devices d ON d.device_id = ml.device_id
    JOIN users u   ON u.user_id   = ml.performed_by
    ORDER BY ml.performed_at DESC
    LIMIT 3
");
$maints = [];
while ($r = $maintRes->fetch_assoc()) $maints[] = $r;

// ── 9. Map locations ─────────────────────────────────────────
$locRes = $conn->query("
    SELECT l.location_id, l.location_name, l.latitude, l.longitude, l.river_section,
           COUNT(d.device_id)            AS total_devices,
           SUM(d.status='active')        AS active_devices,
           SUM(d.status='maintenance')   AS maint_devices
    FROM locations l
    LEFT JOIN devices d ON d.location_id = l.location_id
    GROUP BY l.location_id
    ORDER BY l.river_section
");
$mapLocations = [];
while ($r = $locRes->fetch_assoc()) $mapLocations[] = $r;

// ── 10. 24-hour chart data ───────────────────────────────────
function trend24(mysqli $conn, string $type): array {
    $t   = $conn->real_escape_string($type);
    $res = $conn->query("
        SELECT HOUR(sr.recorded_at) AS hr, AVG(sr.value) AS avg_val
        FROM sensor_readings sr
        JOIN sensors s ON s.sensor_id = sr.sensor_id
        WHERE s.sensor_type = '$t'
          AND sr.recorded_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY hr ORDER BY hr
    ");
    $map = [];
    while ($r = $res->fetch_assoc()) $map[(int)$r['hr']] = round((float)$r['avg_val'], 2);
    $out = [];
    for ($i = 0; $i < 24; $i++) $out[] = $map[$i] ?? null;
    return $out;
}
$chartData = [
    'turbidity'        => trend24($conn, 'turbidity'),
    'pH'               => trend24($conn, 'pH'),
    'temperature'      => trend24($conn, 'temperature'),
    'dissolved_oxygen' => trend24($conn, 'dissolved_oxygen'),
    'water_level'      => trend24($conn, 'water_level'),
    'sediments'        => trend24($conn, 'sediments'),
];

// ── Helpers ──────────────────────────────────────────────────
function avgVal(array $byType, string $type): ?float {
    if (empty($byType[$type])) return null;
    return array_sum(array_column($byType[$type], 'value')) / count($byType[$type]);
}
function stClass(float $v, float $min, float $max): string {
    return ($v < $min || $v > $max) ? 'warn' : 'good';
}

// ── River overall status ─────────────────────────────────────
$warnCount = 0;
foreach ($allRows as $r) {
    if ($r['value'] < $r['min_threshold'] || $r['value'] > $r['max_threshold']) $warnCount++;
}
$riverStatus = $warnCount === 0 ? 'Normal' : ($warnCount <= 2 ? 'Moderate' : 'Critical');
$bannerColor = $warnCount === 0 ? '#16a34a' : ($warnCount <= 2 ? '#d97706' : '#dc2626');
$bannerEmoji = $warnCount === 0 ? '✅' : ($warnCount <= 2 ? '⚠️' : '🚨');
$lastTs      = !empty($allRows) ? $allRows[0]['recorded_at'] : null;

$conn->close();
?>
<?php include '../../assets/navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — Aqua-Vision</title>
  <meta name="description" content="Real-time river water quality monitoring dashboard for Mangima Watershed.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --c1:#0F2854;--c2:#1C4D8D;--c3:#4988C4;--c4:#BDE8F5;
      --bg:#f0f5fb;--surface:#fff;--border:rgba(15,40,84,.08);
      --text:#0F2854;--text2:#4a6080;--text3:#8aa0bc;
      --good:#16a34a;--good-bg:#dcfce7;
      --warn:#d97706;--warn-bg:#fef3c7;
      --crit:#dc2626;--crit-bg:#fee2e2;
      --info-bg:#eff6ff;
      --r:14px;--r-sm:8px;
      --sh:0 1px 3px rgba(15,40,84,.06),0 4px 12px rgba(15,40,84,.04);
    }
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
    .page{padding:24px 28px 48px;max-width:1400px}

    /* ── Topbar ── */
    .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    .topbar h1{font-family:'Space Grotesk',sans-serif;font-size:21px;font-weight:700;color:var(--c1)}
    .topbar .ts{font-size:11px;color:var(--text3);margin-top:3px;font-family:'JetBrains Mono',monospace}
    .btn{height:34px;padding:0 16px;border-radius:20px;font-size:12px;font-weight:500;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:6px;transition:all .18s;text-decoration:none}
    .btn-p{background:var(--c2);color:#fff}.btn-p:hover{background:var(--c1)}
    .btn-g{background:var(--surface);color:var(--c2);border:1px solid var(--border)}.btn-g:hover{background:var(--info-bg)}
    .btns{display:flex;gap:8px}

    /* ── Card shell ── */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden}

    /* ── Section header ── */
    .sh{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
    .sh-title{font-family:'Space Grotesk',sans-serif;font-size:13px;font-weight:600;color:var(--c1);display:flex;align-items:center;gap:6px}
    .sh-title .dot{width:6px;height:6px;border-radius:50%;background:var(--c3);flex-shrink:0}
    .tag{font-size:10px;font-weight:500;padding:3px 8px;border-radius:10px;background:var(--info-bg);color:var(--c2);border:1px solid rgba(28,77,141,.1);white-space:nowrap}
    .tag-warn{background:var(--warn-bg);color:var(--warn);border-color:rgba(217,119,6,.2)}
    .tag-crit{background:var(--crit-bg);color:var(--crit);border-color:rgba(220,38,38,.2)}
    .tag-good{background:var(--good-bg);color:var(--good);border-color:rgba(22,163,74,.2)}

    /* ── Pills ── */
    .pill{display:inline-flex;align-items:center;font-size:10px;font-weight:600;padding:3px 8px;border-radius:20px}
    .p-good{background:var(--good-bg);color:var(--good)}
    .p-warn{background:var(--warn-bg);color:var(--warn)}
    .p-crit{background:var(--crit-bg);color:var(--crit)}
    .p-off{background:#f3f4f6;color:#6b7280}
    .mono{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:500;color:var(--c1)}

    /* ── Status Banner ── */
    .banner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 22px;margin-bottom:18px}
    .banner-left{display:flex;align-items:center;gap:12px}
    .banner-icon{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:19px;background:var(--warn-bg);flex-shrink:0}
    .banner-title{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;color:var(--c1)}
    .banner-sub{font-size:11.5px;color:var(--text2);margin-top:2px}
    .banner-right{display:flex;align-items:center;gap:18px}
    .b-stat-val{font-family:'Space Grotesk',sans-serif;font-size:17px;font-weight:700;color:var(--c1);text-align:right}
    .b-stat-lbl{font-size:9.5px;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;text-align:right}
    .b-div{width:1px;height:32px;background:var(--border)}

    /* ── Summary table ── */
    .tbl{width:100%;border-collapse:collapse;font-size:12.5px}
    .tbl thead th{padding:9px 14px;text-align:left;font-size:9.5px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;background:#f8fafd;border-bottom:1px solid var(--border)}
    .tbl tbody tr{border-bottom:1px solid rgba(15,40,84,.04);transition:background .15s}
    .tbl tbody tr:last-child{border-bottom:none}
    .tbl tbody tr:hover{background:#f5f9ff}
    .tbl td{padding:9px 14px;color:var(--text)}
    .tbl td:first-child{font-weight:500}

    /* ══════════════════════════════════════════════════════════
       KEY METRIC CARDS
    ══════════════════════════════════════════════════════════ */
    .metrics{
      display:grid;
      grid-template-columns:repeat(6,1fr);
      gap:14px;
      margin-bottom:22px;
    }
    @media(max-width:1200px){.metrics{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:680px) {.metrics{grid-template-columns:repeat(2,1fr)}}

    .mc{
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:var(--r);
      padding:18px 16px 15px;
      box-shadow:var(--sh);
      position:relative;
      overflow:hidden;
      transition:transform .2s,box-shadow .2s;
    }
    .mc:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(15,40,84,.12)}

    /* coloured top stripe */
    .mc::before{
      content:'';position:absolute;top:0;left:0;right:0;height:3px;
      border-radius:var(--r) var(--r) 0 0;
    }
    .mc.good::before   {background:var(--good)}
    .mc.warn-hi::before{background:var(--warn)}
    .mc.warn-lo::before{background:var(--crit)}

    /* faint watermark circle */
    .mc::after{
      content:'';position:absolute;bottom:-18px;right:-18px;
      width:80px;height:80px;border-radius:50%;
      border:2px solid currentColor;opacity:.05;
    }

    .mc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .mc-icon{font-size:22px;line-height:1}

    /* trend arrow */
    .mc-trend{font-size:20px;font-weight:800;line-height:1;transition:transform .2s}
    .mc-trend.up  {color:var(--crit)}     /* rising = bad by default */
    .mc-trend.down{color:var(--good)}     /* falling = good by default */
    .mc-trend.flat{font-size:16px;color:var(--text3)}

    /* for parameters where up=good (e.g. DO) swap colours */
    .mc.good    .mc-trend.up  {color:var(--good)}
    .mc.good    .mc-trend.down{color:var(--crit)}
    .mc.warn-hi .mc-trend.up  {color:var(--warn)}
    .mc.warn-lo .mc-trend.down{color:var(--crit)}

    .mc-label{font-size:9.5px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}
    .mc-value{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:var(--c1);line-height:1}
    .mc-unit {font-size:11px;font-weight:400;color:var(--text3);margin-left:3px}

    .mc-footer{display:flex;align-items:center;justify-content:space-between;margin-top:13px}

    /* status badge */
    .mc-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.03em}
    .mc-badge.normal{background:var(--good-bg);color:var(--good)}
    .mc-badge.high  {background:var(--warn-bg);color:var(--warn)}
    .mc-badge.low   {background:var(--crit-bg);color:var(--crit)}

    /* numeric delta */
    .mc-delta{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;color:var(--text3)}

    /* ── Main 2-col ── */
    .main-grid{display:grid;grid-template-columns:1fr 330px;gap:18px;margin-bottom:18px}

    /* ── Map ── */
    .map-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden}
    #av-map{height:320px;width:100%}
    .map-legend{padding:10px 16px;display:flex;gap:14px;flex-wrap:wrap;border-top:1px solid var(--border)}
    .leg{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text2)}
    .leg-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}

    /* ── Devices ── */
    .dev-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh);overflow:hidden;display:flex;flex-direction:column}
    .dev-counts{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--border)}
    .dev-c{padding:13px 10px;text-align:center;border-right:1px solid var(--border)}
    .dev-c:last-child{border-right:none}
    .dev-cv{font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700}
    .dev-cl{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
    .dev-list{flex:1;overflow-y:auto;max-height:240px}
    .dev-item{display:flex;align-items:center;gap:9px;padding:9px 13px;border-bottom:1px solid rgba(15,40,84,.04);transition:background .15s}
    .dev-item:last-child{border-bottom:none}
    .dev-item:hover{background:#f5f9ff}
    .ind{width:7px;height:7px;border-radius:50%;flex-shrink:0}
    .ind-on{background:var(--good);animation:blink 2s infinite}
    .ind-off{background:#9ca3af}
    .ind-mt{background:var(--c3)}
    @keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
    .dev-nm{font-size:12px;font-weight:500;color:var(--c1)}
    .dev-lc{font-size:10px;color:var(--text3);margin-top:1px}

    /* ── Bottom 3-col ── */
    .bot-grid{display:grid;grid-template-columns:1fr 1fr 330px;gap:18px;margin-bottom:18px}
    .chart-wrap{padding:12px 16px 14px;height:210px;position:relative}
    .chart-tabs{display:flex;gap:3px;flex-wrap:wrap}
    .ct{font-size:10.5px;font-weight:500;padding:4px 9px;border-radius:6px;cursor:pointer;color:var(--text3);border:none;background:transparent;font-family:'DM Sans',sans-serif;transition:all .15s}
    .ct.active{background:var(--info-bg);color:var(--c2)}

    /* ── Alerts ── */
    .al-item{display:flex;align-items:flex-start;gap:9px;padding:10px 14px;border-bottom:1px solid rgba(15,40,84,.04);transition:background .15s}
    .al-item:last-child{border-bottom:none}
    .al-item:hover{background:#fafcff}
    .al-ic{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
    .al-ic.crit{background:var(--crit-bg)}.al-ic.warn{background:var(--warn-bg)}
    .al-title{font-size:12px;font-weight:500;color:var(--c1);line-height:1.3}
    .al-meta{font-size:10px;color:var(--text3);margin-top:3px}
    .al-val{font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:500;white-space:nowrap}
    .al-val.crit{color:var(--crit)}.al-val.warn{color:var(--warn)}

    /* ── Maintenance ── */
    .mt-item{display:flex;align-items:center;gap:9px;padding:10px 13px;border-bottom:1px solid rgba(15,40,84,.04)}
    .mt-item:last-child{border-bottom:none}
    .mt-ic{width:28px;height:28px;border-radius:7px;background:var(--warn-bg);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
    .mt-nm{font-size:12px;font-weight:500;color:var(--c1)}
    .mt-nt{font-size:10px;color:var(--text3);margin-top:1px}
    .mt-who{font-size:10px;font-weight:600;color:var(--c3);margin-left:auto;text-align:right;white-space:nowrap}

    /* ── Logs ── */
    .logs-card{margin-bottom:18px}
    .log-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
    .log-tbl thead th{padding:8px 13px;text-align:left;font-size:9.5px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;background:#f8fafd;border-bottom:1px solid var(--border);white-space:nowrap}
    .log-tbl tbody tr{border-bottom:1px solid rgba(15,40,84,.04);transition:background .15s}
    .log-tbl tbody tr:hover{background:#f5f9ff}
    .log-tbl tbody tr:last-child{border-bottom:none}
    .log-tbl td{padding:8px 13px;color:var(--text2);font-family:'JetBrains Mono',monospace;font-size:11px}
    .log-tbl td.lab{font-family:'DM Sans',sans-serif;font-weight:500;color:var(--c1);font-size:12px}

    .empty{padding:28px 20px;text-align:center;color:var(--text3);font-size:13px}
    .anim{animation:fu .38s ease both}
    @keyframes fu{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
    .anim:nth-child(1){animation-delay:.04s}.anim:nth-child(2){animation-delay:.09s}
    .anim:nth-child(3){animation-delay:.14s}.anim:nth-child(4){animation-delay:.19s}
    .anim:nth-child(5){animation-delay:.24s}.anim:nth-child(6){animation-delay:.29s}
  </style>
</head>
<body>
<div class="page">

<!-- ── Topbar ──────────────────────────────────── -->
<div class="topbar anim">
  <div>
    <h1>Overview Dashboard</h1>
    <div class="ts" id="clock">Loading...</div>
  </div>
  <div class="btns">
    <a href="../../database/export.php" class="btn btn-g">⬇ Export</a>
    <button class="btn btn-p" onclick="location.reload()">↺ Refresh</button>
  </div>
</div>

<!-- ── Status Banner ────────────────────────────── -->
<div class="card banner anim" style="border-left:4px solid <?= $bannerColor ?>;margin-bottom:18px">
  <div class="banner-left">
    <div class="banner-icon"><?= $bannerEmoji ?></div>
    <div>
      <div class="banner-title">Mangima River — <?= $bannerEmoji ?> <?= $riverStatus ?></div>
      <div class="banner-sub">
        <?php if ($warnCount === 0): ?>All sensor readings within safe limits.
        <?php else: ?><?= $warnCount ?> parameter(s) outside safe range<?= $lastTs ? ' · Last reading: '.date('H:i', strtotime($lastTs)) : '' ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="banner-right">
    <div>
      <div class="b-stat-val"><?= $alertCount ?></div>
      <div class="b-stat-lbl">Active Alerts</div>
    </div>
    <div class="b-div"></div>
    <div>
      <div class="b-stat-val"><?= $devCounts['active'] ?> / <?= $devCounts['total'] ?></div>
      <div class="b-stat-lbl">Devices Online</div>
    </div>
    <div class="b-div"></div>
    <div>
      <div class="b-stat-val"><?= $lastTs ? date('H:i', strtotime($lastTs)) : '—' ?></div>
      <div class="b-stat-lbl">Last Update</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     KEY METRIC CARDS (Real-time)
══════════════════════════════════════════════════════════ -->
<?php
// Fixed display order — always show these 6 parameters
$cardDefs = [
  ['key'=>'temperature',      'icon'=>'🌡', 'label'=>'Temperature',     'unit'=>'°C'],
  ['key'=>'pH',               'icon'=>'🧪', 'label'=>'pH Level',         'unit'=>'pH'],
  ['key'=>'turbidity',        'icon'=>'🌫', 'label'=>'Turbidity',        'unit'=>'NTU'],
  ['key'=>'dissolved_oxygen', 'icon'=>'💧', 'label'=>'Dissolved Oxygen', 'unit'=>'mg/L'],
  ['key'=>'conductivity',     'icon'=>'⚡', 'label'=>'Conductivity',     'unit'=>'µS/cm'],
  ['key'=>'water_level',      'icon'=>'🌊', 'label'=>'Water Level',      'unit'=>'m'],
];
?>
<div class="metrics">
<?php foreach ($cardDefs as $cd):
  $type = $cd['key'];

  // ── Current value ──────────────────────────────────────
  if (!empty($byType[$type])) {
    $cur  = round(avgVal($byType, $type), 2);
    $unit = $byType[$type][0]['unit'] ?: $cd['unit'];
    $mn   = (float)$byType[$type][0]['min_threshold'];
    $mx   = (float)$byType[$type][0]['max_threshold'];
  } else {
    // sensor not yet in DB — show placeholder
    $cur = null; $unit = $cd['unit']; $mn = 0; $mx = 0;
  }

  // ── Status: Normal / High / Low ──────────────────────
  if ($cur === null) {
    $stKey = 'good'; $stLbl = 'No Data'; $stCls = 'normal';
  } elseif ($cur > $mx) {
    $stKey = 'warn-hi'; $stLbl = 'High';   $stCls = 'high';
  } elseif ($cur < $mn) {
    $stKey = 'warn-lo'; $stLbl = 'Low';    $stCls = 'low';
  } else {
    $stKey = 'good';    $stLbl = 'Normal'; $stCls = 'normal';
  }

  // ── Trend arrow from previous reading ────────────────
  $prev = $prevByType[$type] ?? null;
  if ($cur === null || $prev === null || abs($cur - $prev) < 0.001) {
    $trendArrow = '→'; $trendCls = 'flat'; $deltaStr = '—';
  } elseif ($cur > $prev) {
    $delta = round($cur - $prev, 2);
    $trendArrow = '↑'; $trendCls = 'up'; $deltaStr = '+' . $delta . ' ' . $unit;
  } else {
    $delta = round($prev - $cur, 2);
    $trendArrow = '↓'; $trendCls = 'down'; $deltaStr = '−' . $delta . ' ' . $unit;
  }

  $displayVal = $cur !== null ? $cur : '—';
?>
<div class="mc <?= $stKey ?> anim">

  <!-- Icon + Trend arrow -->
  <div class="mc-header">
    <span class="mc-icon"><?= $cd['icon'] ?></span>
    <span class="mc-trend <?= $trendCls ?>" title="<?= htmlspecialchars($deltaStr) ?>"><?= $trendArrow ?></span>
  </div>

  <!-- Label + Value -->
  <div class="mc-label"><?= htmlspecialchars($cd['label']) ?></div>
  <div class="mc-value"><?= $displayVal ?><span class="mc-unit"><?= htmlspecialchars($unit) ?></span></div>

  <!-- Status badge + numeric delta -->
  <div class="mc-footer">
    <span class="mc-badge <?= $stCls ?>"><?= $stLbl ?></span>
    <span class="mc-delta"><?= $deltaStr ?></span>
  </div>

</div>
<?php endforeach; ?>
</div>


<!-- ── Map + Device List ─────────────────────────── -->
<div class="main-grid">

  <!-- Map -->
  <div class="map-panel anim">
    <div class="sh">
      <div class="sh-title"><div class="dot"></div>Monitoring Locations</div>
      <span class="tag">📍 <?= count($mapLocations) ?> Stations</span>
    </div>
    <div id="av-map"></div>
    <div class="map-legend">
      <div class="leg"><div class="leg-dot" style="background:var(--good)"></div>Normal</div>
      <div class="leg"><div class="leg-dot" style="background:var(--warn)"></div>Warning</div>
      <div class="leg"><div class="leg-dot" style="background:var(--crit)"></div>Offline</div>
      <div class="leg"><div class="leg-dot" style="background:var(--c3)"></div>Maintenance</div>
    </div>
  </div>

  <!-- Devices -->
  <div class="dev-panel anim">
    <div class="sh">
      <div class="sh-title"><div class="dot"></div>Device Status</div>
      <span class="tag">📡 <?= $devCounts['total'] ?> Total</span>
    </div>
    <div class="dev-counts">
      <div class="dev-c"><div class="dev-cv" style="color:var(--good)"><?= $devCounts['active'] ?></div><div class="dev-cl">Active</div></div>
      <div class="dev-c"><div class="dev-cv" style="color:var(--crit)"><?= $devCounts['offline'] ?></div><div class="dev-cl">Offline</div></div>
      <div class="dev-c"><div class="dev-cv" style="color:var(--c3)"><?= $devCounts['maint'] ?></div><div class="dev-cl">Maint.</div></div>
    </div>
    <div class="dev-list">
      <?php if (empty($devices)): ?>
        <div class="empty">No devices registered.</div>
      <?php endif; ?>
      <?php foreach ($devices as $d):
        $ind = $d['status']==='active' ? 'ind-on' : ($d['status']==='maintenance' ? 'ind-mt' : 'ind-off');
        $tag = $d['status']==='active' ? 'p-good'  : ($d['status']==='maintenance' ? 'p-warn'  : 'p-off');
      ?>
      <div class="dev-item">
        <div class="ind <?= $ind ?>"></div>
        <div style="flex:1">
          <div class="dev-nm"><?= htmlspecialchars($d['device_name']) ?> <span style="font-weight:400;color:var(--text3)">· <?= htmlspecialchars(ucfirst($d['device_type'])) ?></span></div>
          <div class="dev-lc"><?= htmlspecialchars(ucfirst($d['river_section'])) ?> — <?= htmlspecialchars($d['location_name']) ?></div>
        </div>
        <span class="pill <?= $tag ?>" style="font-size:9px;padding:2px 7px"><?= ucfirst($d['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ── Trend Chart + Alerts + Maintenance ───────── -->
<div class="bot-grid">

  <!-- Chart -->
  <div class="card anim">
    <div class="sh">
      <div class="sh-title"><div class="dot"></div>24-Hour Trends</div>
      <div class="chart-tabs">
        <button class="ct active" onclick="sw('turbidity',this)">Turbidity</button>
        <button class="ct" onclick="sw('ph',this)">pH</button>
        <button class="ct" onclick="sw('temp',this)">Temp</button>
        <button class="ct" onclick="sw('do',this)">DO</button>
      </div>
    </div>
    <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
  </div>

  <!-- Alerts -->
  <div class="card anim">
    <div class="sh">
      <div class="sh-title"><div class="dot"></div>Active Alerts</div>
      <span class="tag <?= $alertCount>0?'tag-crit':'' ?>"><?= $alertCount ?> Active</span>
    </div>
    <?php if (empty($alerts)): ?>
      <div class="empty">✅ No active alerts.</div>
    <?php else: ?>
      <?php foreach ($alerts as $al):
        $isCrit = $al['alert_type']==='critical';
        $cls    = $isCrit ? 'crit' : 'warn';
        $em     = $isCrit ? '🚨' : '⚠️';
      ?>
      <div class="al-item">
        <div class="al-ic <?= $cls ?>"><?= $em ?></div>
        <div style="flex:1">
          <div class="al-title"><?= htmlspecialchars($al['message']) ?></div>
          <div class="al-meta"><?= htmlspecialchars($al['device_name']) ?> · <?= htmlspecialchars($al['location_name']) ?> · <?= date('H:i, M d', strtotime($al['created_at'])) ?></div>
        </div>
        <div class="al-val <?= $cls ?>"><?= $al['value'] ?> <?= htmlspecialchars($al['unit']) ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Maintenance -->
  <div class="card anim" style="display:flex;flex-direction:column">
    <div class="sh">
      <div class="sh-title"><div class="dot"></div>Maintenance Logs</div>
      <span class="tag">🔧 Recent</span>
    </div>
    <?php if (empty($maints)): ?>
      <div class="empty">No maintenance records.</div>
    <?php else: ?>
      <?php foreach ($maints as $m):
        $mIcons = ['calibration'=>'📐','repair'=>'🔧','replacement'=>'🔋','cleaning'=>'🧹'];
        $mIc    = $mIcons[$m['maintenance_type']] ?? '🔧';
      ?>
      <div class="mt-item">
        <div class="mt-ic"><?= $mIc ?></div>
        <div style="flex:1">
          <div class="mt-nm"><?= htmlspecialchars($m['device_name']) ?></div>
          <div class="mt-nt"><?= htmlspecialchars(ucfirst($m['maintenance_type'])) ?> — <?= htmlspecialchars($m['notes']) ?></div>
        </div>
        <div class="mt-who"><?= htmlspecialchars($m['full_name']) ?><br><span style="font-weight:400;color:var(--text3)"><?= date('M d', strtotime($m['performed_at'])) ?></span></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- ── Sensor Logs ───────────────────────────────── -->
<div class="card logs-card anim">
  <div class="sh">
    <div class="sh-title"><div class="dot"></div>Sensor Data Logs</div>
    <span class="tag">📋 Latest <?= count($logs) ?> Readings</span>
  </div>
  <?php if (empty($logs)): ?>
    <div class="empty">No sensor readings logged yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="log-tbl">
    <thead>
      <tr>
        <th>Timestamp</th>
        <th>Device</th>
        <th>Location</th>
        <th>Sensor Type</th>
        <th>Value</th>
        <th>Unit</th>
        <th>Safe Range</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $log):
        $st   = stClass($log['value'], $log['min_threshold'], $log['max_threshold']);
        $pill = $st==='good' ? 'p-good' : 'p-warn';
        $lbl  = $st==='good' ? '✓ Normal' : '⚠ Out of Range';
        $vc   = $st==='good' ? 'var(--good)' : 'var(--warn)';
        $icon = $icons[$log['sensor_type']] ?? '📡';
      ?>
      <tr>
        <td><?= date('Y-m-d H:i', strtotime($log['recorded_at'])) ?></td>
        <td class="lab"><?= htmlspecialchars($log['device_name']) ?></td>
        <td><?= htmlspecialchars($log['location_name']) ?></td>
        <td class="lab"><?= $icon ?> <?= htmlspecialchars(ucwords(str_replace('_',' ',$log['sensor_type']))) ?></td>
        <td style="color:<?= $vc ?>;font-weight:600"><?= $log['value'] ?></td>
        <td><?= htmlspecialchars($log['unit']) ?></td>
        <td style="color:var(--text3)"><?= $log['min_threshold'] ?> – <?= $log['max_threshold'] ?></td>
        <td><span class="pill <?= $pill ?>"><?= $lbl ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

</div><!-- /page -->

<script>
// Clock
function updateClock(){
  const now=new Date();
  document.getElementById('clock').textContent=
    'Last updated: '+now.toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'medium'});
}
updateClock(); setInterval(updateClock,30000);

// Chart
const hours=Array.from({length:24},(_,i)=>{const h=(new Date().getHours()-23+i+24)%24;return String(h).padStart(2,'0')+':00'});
const dbData=<?= json_encode($chartData, JSON_NUMERIC_CHECK) ?>;
const DS={
  turbidity:{label:'Turbidity (NTU)',   color:'#d97706',bg:'rgba(217,119,6,0.09)',  data:dbData.turbidity},
  ph:       {label:'pH Level',           color:'#4988C4',bg:'rgba(73,136,196,0.09)', data:dbData.pH},
  temp:     {label:'Temperature (°C)',   color:'#dc2626',bg:'rgba(220,38,38,0.09)',  data:dbData.temperature},
  do:       {label:'Dissolved O₂ (mg/L)',color:'#16a34a',bg:'rgba(22,163,74,0.09)', data:dbData.dissolved_oxygen}
};
let chart;
function buildChart(key){
  const d=DS[key];
  if(chart)chart.destroy();
  chart=new Chart(document.getElementById('trendChart').getContext('2d'),{
    type:'line',
    data:{labels:hours,datasets:[{
      label:d.label,data:d.data,borderColor:d.color,backgroundColor:d.bg,
      borderWidth:2,pointRadius:2,pointHoverRadius:5,fill:true,tension:0.4,spanGaps:true
    }]},
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{mode:'index',intersect:false}},
      scales:{
        x:{grid:{color:'rgba(15,40,84,0.04)'},ticks:{font:{size:9},color:'#8aa0bc',maxTicksLimit:8}},
        y:{grid:{color:'rgba(15,40,84,0.04)'},ticks:{font:{size:9},color:'#8aa0bc'}}
      }
    }
  });
}
function sw(key,btn){
  document.querySelectorAll('.ct').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');buildChart(key);
}
buildChart('turbidity');

// Leaflet
const locs=<?= json_encode(array_map(fn($l)=>[
  'name'    =>$l['location_name'],
  'lat'     =>(float)$l['latitude'],
  'lng'     =>(float)$l['longitude'],
  'section' =>$l['river_section'],
  'total'   =>(int)$l['total_devices'],
  'active'  =>(int)$l['active_devices'],
  'maint'   =>(int)$l['maint_devices']
],$mapLocations)) ?>;

if(locs.length>0){
  const avgLat=locs.reduce((s,l)=>s+l.lat,0)/locs.length;
  const avgLng=locs.reduce((s,l)=>s+l.lng,0)/locs.length;
  const avMap=L.map('av-map').setView([avgLat,avgLng],14);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution:'© OpenStreetMap',maxZoom:19
  }).addTo(avMap);
  const sc={upstream:'#16a34a',midstream:'#d97706',downstream:'#dc2626'};
  const sl={upstream:'Upstream',midstream:'Midstream',downstream:'Downstream'};
  locs.forEach(loc=>{
    const offline=loc.total-loc.active-loc.maint;
    const color=sc[loc.section]||'#4988C4';
    L.circleMarker([loc.lat,loc.lng],{
      radius:12,fillColor:color,color:'#fff',weight:2.5,fillOpacity:.9
    }).addTo(avMap).bindPopup(`
      <div style="font-family:'DM Sans',sans-serif;min-width:170px">
        <div style="font-size:13px;font-weight:700;color:#0F2854;margin-bottom:6px">${sl[loc.section]||loc.section}</div>
        <div style="font-size:12px;color:#4a6080;margin-bottom:6px">${loc.name}</div>
        <div style="display:grid;grid-template-columns:auto auto;gap:3px 10px;font-size:11px">
          <span style="color:#16a34a;font-weight:600">✓ Active</span><b>${loc.active}</b>
          <span style="color:#4988C4;font-weight:600">⚙ Maint.</span><b>${loc.maint}</b>
          <span style="color:#9ca3af;font-weight:600">✗ Offline</span><b>${offline}</b>
        </div>
        <div style="margin-top:8px;font-size:10px;color:#8aa0bc">${loc.lat.toFixed(5)}°N, ${loc.lng.toFixed(5)}°E</div>
      </div>
    `,{maxWidth:220});
    L.tooltip({permanent:true,direction:'bottom',offset:[0,10],className:'av-lbl'})
      .setContent(`<b style="font-size:10px;color:#0F2854">${sl[loc.section]||loc.section}</b>`)
      .setLatLng([loc.lat,loc.lng]).addTo(avMap);
  });
}
</script>
</body>
</html>
