# Aqua-Vision: Admin System Flow & Data Interaction
## GRASPS Supplement — Role-Based Data Flow Documentation

---

## 1. ADMIN AUTHENTICATION FLOW

### 1.1 Login Sequence

```
┌─────────┐          ┌─────────────┐          ┌──────────────┐          ┌──────────┐
│  Admin  │          │   Browser   │          │  PHP Server  │          │  MySQL   │
└────┬────┘          └──────┬──────┘          └──────┬───────┘          └────┬─────┘
     │                      │                      │                      │
     │  [1] Access          │                      │                      │
     │  /login.php           │                      │                      │
     │ ─────────────────────>│                      │                      │
     │                      │  [2] Render form      │                      │
     │  ┌──────────────┐    │                      │                      │
     │  │ Username     │    │                      │                      │
     │  │ [__________] │    │                      │                      │
     │  │ Password     │    │                      │                      │
     │  │ [__________] │    │                      │                      │
     │  │ [  Log In  ] │    │                      │                      │
     │  └──────────────┘    │                      │                      │
     │                      │                      │                      │
     │  [3] Submit          │                      │                      │
     │  admin / password    │                      │                      │
     │                      │  POST login.php      │                      │
     │                      │  username=admin      │                      │
     │                      │  password=admin123   │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │                      │  [4] Query user      │
     │                      │                      │  SELECT * FROM users │
     │                      │                      │  WHERE username=?    │
     │                      │                      │ ──────────────────────>│
     │                      │                      │                      │
     │                      │                      │  [5] Return row      │
     │                      │                      │  user_id=1, role=admin│
     │                      │                      │  password_hash=...   │
     │                      │                      │<──────────────────────│
     │                      │                      │                      │
     │                      │                      │  [6] Verify password│
     │                      │                      │  password_verify()   │
     │                      │                      │  → TRUE              │
     │                      │                      │                      │
     │                      │                      │  [7] Create session  │
     │                      │                      │  $_SESSION['user_id']=1
     │                      │                      │  $_SESSION['role']='admin'
     │                      │                      │  $_SESSION['full_name']='...'
     │                      │                      │                      │
     │                      │                      │  [8] Log activity    │
     │                      │                      │  INSERT system_logs  │
     │                      │                      │  (action='login')    │
     │                      │                      │ ──────────────────────>│
     │                      │                      │                      │
     │                      │  [9] Redirect 302    │                      │
     │                      │  Location:           │                      │
     │                      │  /apps/admin/        │                      │
     │                      │  dashboard.php       │                      │
     │                      │<─────────────────────│                      │
     │                      │                      │                      │
     │  [10] Browser follows│                      │                      │
     │  redirect            │                      │                      │
     │ ─────────────────────>│                      │                      │
     │                      │  GET dashboard.php   │                      │
     │                      │  (with session       │                      │
     │                      │   cookie)            │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │                      │  [11] require_login()│
     │                      │                      │  Check $_SESSION     │
     │                      │                      │  → Valid admin       │
     │                      │                      │                      │
     │                      │                      │  [12] Build dashboard│
     │                      │                      │  data (see next flow)│
     │                      │                      │                      │
```

### 1.2 Session Validation (Per Request)

```php
// database/config.php — require_login()
function require_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

// Every admin page calls:
require_login();
// + optional role check:
// if ($_SESSION['role'] !== 'admin') { redirect to appropriate dashboard }
```

---

## 2. ADMIN DASHBOARD DATA FLOW

### 2.1 Initial Page Load (Server-Side Render)

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  GET dashboard.php   │                      │
       │  Cookie: PHPSESSID   │                      │
       │ ──────────────────────>│                      │
       │                      │                      │
       │                      │  [1] session_start() │                      │
       │                      │  require_login()     │                      │
       │                      │                      │                      │
       │                      │  [2] Fetch counts    │                      │
       │                      │  SELECT COUNT(*)     │                      │
       │                      │  FROM devices        │                      │
       │                      │  GROUP BY status     │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [3] Return counts   │                      │
       │                      │  total=8, active=7   │                      │
       │                      │  maintenance=1     │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [4] Fetch latest    │                      │
       │                      │  readings per device │                      │
       │                      │  (subquery or JOIN)  │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] Return readings │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [6] Fetch alerts    │                      │
       │                      │  SELECT * FROM alerts│                      │
       │                      │  WHERE status='active'│                      │
       │                      │  ORDER BY created_at │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Return alerts   │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [8] Fetch map data  │                      │
       │                      │  SELECT l.*,         │                      │
       │                      │  GROUP_CONCAT(d...)  │                      │
       │                      │  FROM locations l    │                      │
       │                      │  LEFT JOIN devices d │                      │
       │                      │  ON l.location_id=   │                      │
       │                      │  d.location_id       │                      │
       │                      │  GROUP BY l.         │                      │
       │                      │  location_id         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [9] Return locations│                      │
       │                      │  with device counts  │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [10] Build chart    │                      │
       │                      │  data (24h trend)    │                      │
       │                      │  av_overview_trend24()│                      │
       │                      │  → 24 hourly averages│                      │
       │                      │                      │
       │                      │  [11] Calculate river│                      │
       │                      │  section status      │                      │
       │                      │  av_overview_calculate│                      │
       │                      │  _river_status()     │                      │
       │                      │  → Normal/Moderate/  │                      │
       │                      │    Critical          │                      │
       │                      │                      │
       │                      │  [12] Render HTML    │                      │
       │                      │  + inline PHP vars   │                      │
       │                      │  + JS initialization │                      │
       │                      │                      │
       │  [13] Full HTML page │                      │                      │
       │  with embedded data  │                      │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │                      │
       │  [14] Browser        │                      │                      │
       │  executes JavaScript │                      │                      │
       │  → Map.init()        │                      │                      │
       │  → Chart.init()      │                      │                      │
       │  → Start polling     │                      │                      │
```

### 2.2 Real-Time Data Polling (AJAX)

```javascript
// Client-side polling every 30 seconds
setInterval(() => {
  fetch('dashboard.php?action=fetch')
    .then(r => r.json())
    .then(data => {
      updateBanner(data.river_status, data.banner_color);
      updateAlertTable(data.alerts);
      updateSensorReadings(data.device_readings);
      refreshCharts(data.chart_data);
    });
}, 30000);
```

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] AJAX request    │                      │
       │  GET dashboard.php?  │                      │
       │  action=fetch        │                      │
       │ ──────────────────────>│                      │
       │                      │                      │
       │                      │  [2] Build full fetch│                      │
       │                      │  _build_full_fetch() │                      │
       │                      │  → Single function   │                      │
       │                      │    aggregates ALL    │                      │
       │                      │    dashboard data    │                      │
       │                      │                      │
       │                      │  [3] Multiple queries│                      │
       │                      │  ( batched via PHP ) │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Return all rows │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [5] Assemble JSON   │                      │
       │                      │  { ok: true, ts,     │                      │
       │                      │    river_status,     │                      │
       │                      │    device_readings,  │                      │
       │                      │    alerts, logs,     │                      │
       │                      │    chart_data, ... } │                      │
       │                      │                      │
       │  [6] JSON response   │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │
       │  [7] DOM updates     │                      │
       │  → Update counters   │                      │
       │  → Re-render alerts  │                      │
       │  → Update chart data │                      │
       │  → Flash changed     │                      │
       │    values            │                      │
```

---

## 3. SENSOR SIMULATION & ALERT GENERATION FLOW

### 3.1 Admin Submits Sensor Reading

```
┌─────────────┐          ┌──────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │ Business Logic│          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │                      │
       │  [1] Admin enters    │                      │                      │
       │  sensor values in    │                      │                      │
       │  simulation form     │                      │                      │
       │                      │                      │                      │
       │  [2] Click "Send"    │                      │                      │
       │                      │  POST dashboard.php  │                      │
       │                      │  ?action=simulate    │                      │
       │                      │  Body: JSON {        │                      │
       │                      │    device_id: 1,     │                      │
       │                      │    temperature: 26.5,│                      │
       │                      │    ph_level: 9.2,    │                      │
       │                      │    turbidity: 15.3   │                      │
       │                      │  }                   │                      │
       │                      │ ──────────────────────>│                      │
       │                      │                      │                      │
       │                      │  [3] Validate        │                      │
       │                      │  device_id exists?   │                      │
       │                      │  device active?      │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Device OK       │                      │
       │                      │<──────────────────────│
       │                      │                      │                      │
       │                      │  [5] For each sensor │                      │
       │                      │  type in payload:    │                      │
       │                      │                      │                      │
       │                      │    [5a] Find or     │                      │
       │                      │    create sensor    │                      │
       │                      │    record for device │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │    [5b] INSERT      │                      │
       │                      │    sensor_readings   │                      │
       │                      │    (sensor_id,      │                      │
       │                      │     value,           │                      │
       │                      │     recorded_at)     │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │    [5c] Get          │                      │
       │                      │    min/max threshold │                      │
       │                      │    from sensors table│                      │
       │                      │<──────────────────────│
       │                      │                      │                      │
       │                      │    [5d] THRESHOLD    │                      │
       │                      │    CHECK             │                      │
       │                      │    ┌────────────────┐│                      │
       │                      │    │ value < min ? ││                      │
       │                      │    │    → LOW alert││                      │
       │                      │    │               ││                      │
       │                      │    │ value > max ? ││                      │
       │                      │    │    → HIGH/CRIT││                      │
       │                      │    │               ││                      │
       │                      │    │ pH=9.2 > 8.5 ││                      │
       │                      │    │    → HIGH     ││                      │
       │                      │    └────────────────┘│                      │
       │                      │                      │                      │
       │                      │    [5e] IF breached: │                      │
       │                      │    INSERT INTO alerts│                      │
       │                      │    (sensor_id,       │                      │
       │                      │     reading_id,      │                      │
       │                      │     alert_type,      │                      │
       │                      │     message,         │                      │
       │                      │     status='active') │                      │
       │                      │ ──────────────────────>│
       │                      │                      │                      │
       │                      │  [6] Update device   │                      │
       │                      │  last_active = NOW() │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Log system      │                      │
       │                      │  activity            │                      │
       │                      │  INSERT system_logs  │                      │
       │                      │  (action='simulate') │                      │
       │                      │ ──────────────────────>│
       │                      │                      │                      │
       │                      │  [8] Build response  │                      │
       │                      │  { success: true,    │                      │
       │                      │    reading_id,       │                      │
       │                      │    alerts_created:   │                      │
       │                      │      [{type:'high', │                      │
       │                      │        message,      │                      │
       │                      │        sensor_type}] }│                      │
       │                      │                      │
       │  [9] JSON response   │                      │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │                      │
       │  [10] UI updates:    │                      │                      │
       │  → Show new reading  │                      │                      │
       │  → Add alert badge   │                      │                      │
       │  → Update chart      │                      │                      │
       │  → Flash pH value    │                      │                      │
       │    in orange         │                      │                      │
```

### 3.2 Alert Message Construction

```php
// Example: pH level too high (9.2, max=8.5)
// Generated in dashboard_overview_api.php

$alertMessage = sprintf(
    '%s too high: %.2f %s on %s',
    'pH level',           // sensor type label
    9.2,                  // actual value
    'pH',                 // unit
    'WQ-Upstream-Start'   // device name
);
// Result: "pH level too high: 9.20 pH on WQ-Upstream-Start"

// Severity calculation
if ($value > $max * 1.3 || $value < $min * 0.7) {
    $severity = 'critical';  // Extreme deviation
} else {
    $severity = 'high';      // Standard threshold breach
}
```

---

## 4. ADMIN CRUD OPERATIONS

### 4.1 Device CRUD — Add New Device

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks    │                      │
       │  "+ Add Device"     │                      │
       │                      │                      │
       │  [2] Fill form:      │                      │
       │  - device_name       │                      │
       │  - status (active)   │                      │
       │  - location_id (3) │                      │
       │                      │                      │
       │  [3] Submit          │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=add          │                      │
       │                      │  device_name=WQ-...  │                      │
       │                      │  status=active       │                      │
       │                      │  location_id=3       │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Validate inputs │                      │
       │                      │  - name not empty    │                      │
       │                      │  - status in enum    │                      │
       │                      │  - location_id valid │                      │
       │                      │  (FK check)          │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] Location exists │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [6] INSERT device   │                      │
       │                      │  INSERT INTO devices │                      │
       │                      │  (device_name,       │                      │
       │                      │   device_type,       │                      │
       │                      │   location_id,       │                      │
       │                      │   status,            │                      │
       │                      │   created_at)        │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Auto-create     │                      │
       │                      │  default sensors     │                      │
       │                      │  for water_quality_    │                      │
       │                      │  station type:       │                      │
       │                      │  temperature, pH,    │                      │
       │                      │  turbidity, DO,      │                      │
       │                      │  water_level,        │                      │
       │                      │  sediments           │                      │
       │                      │  (with default       │                      │
       │                      │  thresholds)         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [8] Log action      │                      │
       │                      │  system_logs:        │                      │
       │                      │  'device_created'    │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [9] Set flash       │                      │
       │                      │  message in session  │                      │
       │                      │                      │
       │                      │  [10] Redirect 302 │                      │
       │                      │  to devices.php      │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [11] Browser loads  │                      │
       │  devices.php with    │                      │
       │  success message     │                      │
       │  "Device added"      │                      │
       │                      │                      │
       │  [12] Map refreshes  │                      │
       │  New marker appears  │                      │
       │  at location 3       │                      │
```

### 4.2 Device CRUD — Edit Status

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks ✏️ │                      │
       │  on device row       │                      │
       │                      │                      │
       │  [2] Modal opens     │                      │
       │  Pre-filled form     │                      │
       │                      │                      │
       │  [3] Change status   │                      │
       │  active → maintenance│                      │
       │                      │                      │
       │  [4] Submit          │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=edit         │                      │
       │                      │  device_id=3         │                      │
       │                      │  status=maintenance  │                      │
       │                      │  location_id=        │                      │
       │                      │  (empty = unassign)  │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] UPDATE devices  │                      │
       │                      │  SET status=         │                      │
       │                      │  'maintenance',      │                      │
       │                      │  location_id=NULL,   │                      │
       │                      │  updated_at=NOW()    │                      │
       │                      │  WHERE device_id=3   │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [6] Log change      │                      │
       │                      │  system_logs         │                      │
       │                      │  action='device_edit' │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Redirect with   │                      │
       │                      │  success flash       │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [8] Map marker      │                      │
       │  changes color       │                      │
       │  green → orange      │                      │
       │  (maintenance)       │                      │
```

### 4.3 Device CRUD — Delete with Cascade

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks 🗑️│                      │
       │                      │                      │
       │  [2] Confirm dialog  │                      │
       │  "Delete WQ-...?     │                      │
       │   All sensors and    │                      │
       │   readings will be   │                      │
       │   removed."          │                      │
       │                      │                      │
       │  [3] Confirm         │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=delete       │                      │
       │                      │  device_id=9         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] DELETE device   │                      │
       │                      │  FROM devices        │                      │
       │                      │  WHERE device_id=9 │                      │
       │                      │                      │
       │                      │  [5] CASCADE via FK│                      │
       │                      │  constraints:        │                      │
       │                      │  - sensors (ON DELETE│                      │
       │                      │    CASCADE)          │                      │
       │                      │  - sensor_readings   │                      │
       │                      │    (ON DELETE        │                      │
       │                      │    CASCADE)          │                      │
       │                      │  - alerts (ON DELETE │                      │
       │                      │    CASCADE)          │                      │
       │                      │  - maintenance_logs │                      │
       │                      │    (ON DELETE       │                      │
       │                      │    CASCADE)          │                      │
       │                      │                      │
       │                      │  [6] Log deletion  │                      │
       │                      │  system_logs         │                      │
       │                      │  action='device_     │                      │
       │                      │  deleted'            │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Redirect with   │                      │
       │                      │  success message     │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [8] Map marker      │                      │
       │  removed             │                      │
       │  Device list updated │                      │
```

---

## 5. ALERT MANAGEMENT FLOW

### 5.1 Acknowledge All Alerts

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks    │                      │
       │  "Acknowledge All"   │                      │
       │                      │                      │
       │  [2] POST request    │                      │
       │                      │  POST dashboard.php  │                      │
       │                      │  action=             │                      │
       │                      │  acknowledge_all     │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [3] UPDATE alerts   │                      │
       │                      │  SET                 │                      │
       │                      │    status=           │                      │
       │                      │    'resolved',       │                      │
       │                      │    resolved_by=      │                      │
       │                      │    $_SESSION         │                      │
       │                      │    ['user_id'],      │                      │
       │                      │    resolved_at=      │                      │
       │                      │    NOW()             │                      │
       │                      │  WHERE status=       │                      │
       │                      │    'active'          │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Return count    │                      │
       │                      │  3 rows affected     │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [5] Log action      │                      │
       │                      │  system_logs         │                      │
       │                      │  action=             │                      │
       │                      │  'ALERTS_ACKNOWLEDGE'│                      │
       │                      │  details:            │                      │
       │                      │  'Resolved 3 alerts' │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [6] JSON response   │                      │
       │                      │  { ok: true,         │                      │
       │                      │    resolved: 3 }     │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [7] UI updates      │                      │
       │  Alert table clears  │                      │
       │  Badge count → 0     │                      │
       │  Toast notification  │                      │
```

---

## 6. COMPLETE ADMIN DASHBOARD STATE TRANSITION

### State Diagram: Alert Lifecycle

```
                    ┌─────────────┐
                    │  SENSOR     │
                    │  READING    │
                    │  Inserted   │
                    └──────┬──────┘
                           │
                    [threshold check]
                           │
              ┌────────────┴────────────┐
              │                         │
        [value in range]          [value out of range]
              │                         │
              ▼                         ▼
       ┌─────────────┐          ┌─────────────┐
       │   NORMAL    │          │ ALERT CREATED│
       │   STATE     │          │ status=active│
       └─────────────┘          └──────┬──────┘
                                       │
                                       │ [Admin views dashboard]
                                       │
                                       ▼
                              ┌─────────────┐
                              │  Admin sees │
                              │  alert in   │
                              │  active list│
                              └──────┬──────┘
                                     │
                              [Click Acknowledge]
                                     │
                                     ▼
                              ┌─────────────┐
                              │  RESOLVED   │
                              │  status=    │
                              │  resolved   │
                              │  resolved_by│
                              │  = admin_id │
                              └─────────────┘
```

---

## APPENDIX: Database Queries by Admin Action

| Admin Action | Primary Tables | Key SQL Operation |
|-------------|----------------|-------------------|
| View Dashboard | devices, locations, alerts, sensor_readings | Multiple SELECTs with JOINs |
| Simulate Reading | sensor_readings, sensors, devices, alerts | INSERT readings + conditional INSERT alerts |
| Acknowledge Alerts | alerts | UPDATE status='resolved' WHERE status='active' |
| Add Device | devices, sensors (auto) | INSERT devices + INSERT sensors (x6) |
| Edit Device | devices | UPDATE devices SET ... WHERE device_id=? |
| Delete Device | devices, sensors, sensor_readings, alerts, maintenance_logs | DELETE devices (cascade handles rest) |
| View Map | locations, devices | SELECT l.*, GROUP_CONCAT(d...) FROM locations l LEFT JOIN devices d |

---

*Generated for GRASPS Admin Role Assessment*
*Demonstrates complete client-server-database interaction for administrator workflows*
