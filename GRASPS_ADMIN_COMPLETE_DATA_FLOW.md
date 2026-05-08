# Aqua-Vision: Complete Admin Data Flow
## GRASPS Performance Assessment — Administrator Role System Interaction

---

## 1. SYSTEM ARCHITECTURE OVERVIEW

### 1.1 Three-Tier Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              PRESENTATION LAYER                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │                          Web Browser (Client)                          │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │ │
│  │  │  HTML/CSS   │  │ JavaScript  │  │  Chart.js   │  │  Leaflet.js │  │ │
│  │  │  (Markup)   │  │  (Logic)    │  │  (Charts)   │  │  (Maps)     │  │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────┬───────────────────────────────────────┘
                                      │ HTTP/HTTPS (AJAX/Fetch)
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                             APPLICATION LAYER                                │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │                         Apache + PHP 8.x Server                         │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │ │
│  │  │  dashboard. │  │  devices.   │  │  locations. │  │  login.php  │ │
│  │  │  php        │  │  php        │  │  php        │  │  (Auth)     │ │
│  │  │  (API + UI) │  │  (CRUD)     │  │  (CRUD)     │  │             │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │ │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐                │ │
│  │  │  Business   │  │  Threshold  │  │   Export    │                │ │
│  │  │  Logic      │  │  Engine     │  │  Generator  │                │ │
│  │  │  (Helpers)  │  │  (Alerts)   │  │  (CSV/JSON) │                │ │
│  │  └─────────────┘  └─────────────┘  └─────────────┘                │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────┬───────────────────────────────────────┘
                                      │ MySQLi (Prepared Statements)
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                               DATABASE LAYER                                 │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐          │
│  │   users  │ │ locations│ │  devices │ │  sensors │ │ sensor_  │          │
│  │  (auth)  │ │   (map)  │ │ (status) │ │(threshold│ │ readings │          │
│  │          │ │          │ │          │ │   s)     │ │(time-series)│        │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘          │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                       │
│  │  alerts  │ │maintenance│ │notifications│ │system_  │                       │
│  │(threshold│ │  _logs   │ │  (user)   │ │  logs   │                       │
│  │ breach)  │ │(service) │ │          │ │ (audit) │                       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Admin Access Points

| Page | File Path | Purpose | HTTP Methods |
|------|-----------|---------|-------------|
| **Login** | `/login.php` | Authentication | GET, POST |
| **Dashboard** | `/apps/admin/dashboard.php` | Monitoring & simulation | GET, POST |
| **Devices** | `/apps/admin/devices.php` | Device CRUD + map | GET, POST |
| **Locations** | `/apps/admin/locations.php` | Location CRUD + map | GET, POST |
| **Researchers** | `/apps/researcher/dashboard.php` | Data export & analysis | GET |

---

## 2. ADMIN AUTHENTICATION FLOW

### 2.1 Complete Login Sequence

```
┌─────────┐          ┌─────────────┐          ┌──────────────┐          ┌──────────┐
│  Admin  │          │   Browser   │          │  PHP Server  │          │  MySQL   │
└────┬────┘          └──────┬──────┘          └──────┬───────┘          └────┬─────┘
     │                      │                      │                      │
     │  [1] Navigate to     │                      │                      │
     │  /login.php          │                      │                      │
     │ ─────────────────────>│                      │                      │
     │                      │                      │                      │
     │                      │  [2] Render form     │                      │
     │  ┌──────────────┐    │                      │                      │
     │  │ Aqua-Vision  │    │                      │                      │
     │  │ River Health │    │                      │                      │
     │  │ Monitoring   │    │                      │                      │
     │  │              │    │                      │                      │
     │  │ 👤 Username  │    │                      │                      │
     │  │ [__________] │    │                      │                      │
     │  │ 🔒 Password  │    │                      │                      │
     │  │ [__________] │    │                      │                      │
     │  │              │    │                      │                      │
     │  │ [  🔐 Log In ]│    │                      │                      │
     │  └──────────────┘    │                      │                      │
     │                      │                      │                      │
     │  [3] Enter:          │                      │                      │
     │  username: admin     │                      │                      │
     │  password: admin123│                      │                      │
     │                      │                      │                      │
     │  [4] Submit form     │                      │                      │
     │                      │  POST login.php      │                      │
     │                      │  Content-Type:       │                      │
     │                      │  application/x-www-  │                      │
     │                      │  form-urlencoded     │                      │
     │                      │  Body:               │                      │
     │                      │  username=admin&    │                      │
     │                      │  password=admin123  │                      │
     │                      │  remember=on        │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │  [5] Start session   │                      │
     │                      │  session_start()     │                      │
     │                      │                      │                      │
     │                      │  [6] Query database  │                      │
     │                      │  SELECT user_id,     │                      │
     │                      │  username,           │                      │
     │                      │  password_hash,      │                      │
     │                      │  role, full_name     │                      │
     │                      │  FROM users          │                      │
     │                      │  WHERE username = ?  │                      │
     │                      │  AND is_active = 1   │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │                      │  [7] Return user row │
     │                      │                      │  user_id=1,          │
     │                      │                      │  role='admin',       │
     │                      │                      │  password_hash=       │
     │                      │                      │  $2y$10$...          │
     │                      │                      │<──────────────────────│
     │                      │                      │                      │
     │                      │  [8] Verify password │                      │
     │                      │  password_verify(    │                      │
     │                      │    'admin123',       │                      │
     │                      │    $hash             │                      │
     │                      │  )                   │                      │
     │                      │  → TRUE (match)      │                      │
     │                      │                      │                      │
     │                      │  [9] Create session  │                      │
     │                      │  variables:          │                      │
     │                      │  $_SESSION['user_id']│                      │
     │                      │    = 1               │                      │
     │                      │  $_SESSION['username']│                      │
     │                      │    = 'admin'         │                      │
     │                      │  $_SESSION['role']   │                      │
     │                      │    = 'admin'         │                      │
     │                      │  $_SESSION['full_name']│                      │
     │                      │    = 'System Admin'  │                      │
     │                      │                      │                      │
     │                      │  [10] Log login      │                      │
     │                      │  activity            │                      │
     │                      │  INSERT INTO         │                      │
     │                      │  system_logs (       │                      │
     │                      │    user_id,          │                      │
     │                      │    action,           │                      │
     │                      │    details,          │                      │
     │                      │    ip_address,       │                      │
     │                      │    user_agent,       │                      │
     │                      │    created_at        │                      │
     │                      │  ) VALUES (          │                      │
     │                      │    1, 'login',       │                      │
     │                      │    'Admin logged in',│                      │
     │                      │    '192.168.1.100',  │                      │
     │                      │    'Mozilla/5.0...', │                      │
     │                      │    NOW()             │                      │
     │                      │  )                   │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │  [11] Redirect 302   │                      │
     │                      │  Location: /apps/    │                      │
     │                      │  admin/dashboard.php   │                      │
     │                      │<─────────────────────│                      │
     │                      │                      │                      │
     │  [12] Browser        │                      │                      │
     │  follows redirect    │                      │                      │
     │  with PHPSESSID      │                      │                      │
     │  cookie              │                      │                      │
     │                      │                      │                      │
     │                      │  GET /apps/admin/    │                      │
     │                      │  dashboard.php       │                      │
     │                      │  Cookie: PHPSESSID=│                      │
     │                      │  abc123              │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │                      │
     │                      │  [13] require_login()│                      │
     │                      │  Check:              │                      │
     │                      │  isset($_SESSION     │                      │
     │                      │  ['user_id']) → TRUE│                      │
     │                      │  $_SESSION['role']   │                      │
     │                      │    === 'admin'       │                      │
     │                      │    → TRUE            │                      │
     │                      │  → Continue          │                      │
     │                      │                      │                      │
     │                      │  [14] Load dashboard │                      │
     │                      │  data (see Section 3)│                      │
     │                      │                      │                      │
     │  [15] Render admin   │                      │                      │
     │  dashboard with      │                      │
     │  all monitoring data│                      │                      │
     │<─────────────────────│                      │                      │
```

### 2.2 Session Validation (Every Request)

```php
// File: database/config.php

// Start or resume session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce login on all admin pages
function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

// Optional: Role enforcement
function require_admin() {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: /apps/' . $_SESSION['role'] . '/dashboard.php');
        exit;
    }
}

// Usage at top of every admin page:
require_once __DIR__ . '/../database/config.php';
require_admin();
```

---

## 3. ADMIN DASHBOARD — INITIAL LOAD & REAL-TIME POLLING

### 3.1 Server-Side Page Construction

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
       │                      │  → Valid admin       │                      │
       │                      │                      │
       │                      │  [2] Query: Device   │                      │
       │                      │  counts by status    │                      │
       │                      │  SELECT status,      │                      │
       │                      │  COUNT(*) as total   │                      │
       │                      │  FROM devices        │                      │
       │                      │  GROUP BY status     │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [3] Return:         │                      │
       │                      │  active: 7, maint: 1,│                      │
       │                      │  total: 8            │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [4] Query: Latest   │                      │
       │                      │  sensor readings     │                      │
       │                      │  per device          │                      │
       │                      │  SELECT d.device_id, │                      │
       │                      │  s.sensor_type,      │                      │
       │                      │  sr.value, sr.unit   │                      │
       │                      │  FROM devices d      │                      │
       │                      │  LEFT JOIN sensors s │                      │
       │                      │    ON d.device_id=   │                      │
       │                      │    s.device_id       │                      │
       │                      │  LEFT JOIN sensor_   │                      │
       │                      │    readings sr       │                      │
       │                      │    ON s.sensor_id=    │                      │
       │                      │    sr.sensor_id      │                      │
       │                      │  WHERE sr.recorded_at │                      │
       │                      │    IN (SELECT MAX(...)│                      │
       │                      │    FROM sensor_      │                      │
       │                      │    readings ...)     │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] Return:         │                      │
       │                      │  device 1: temp=26.5,│                      │
       │                      │  pH=7.2...           │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [6] Query: Active   │                      │
       │                      │  alerts with context │                      │
       │                      │  SELECT a.*,         │                      │
       │                      │  s.sensor_type,      │                      │
       │                      │  d.device_name,      │                      │
       │                      │  l.location_name,    │                      │
       │                      │  l.river_section     │                      │
       │                      │  FROM alerts a       │                      │
       │                      │  JOIN sensors s      │                      │
       │                      │    ON a.sensor_id=    │                      │
       │                      │    s.sensor_id       │                      │
       │                      │  JOIN devices d      │                      │
       │                      │    ON s.device_id=    │                      │
       │                      │    d.device_id       │                      │
       │                      │  JOIN locations l    │                      │
       │                      │    ON d.location_id=  │                      │
       │                      │    l.location_id     │                      │
       │                      │  WHERE a.status=     │                      │
       │                      │  'active'             │                      │
       │                      │  ORDER BY a.created_at│                      │
       │                      │  DESC                 │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Return: 3 rows   │                      │
       │                      │  [pH high @ upstream, │                      │
       │                      │   temp high @ mid,    │                      │
       │                      │   turbidity high @   │                      │
       │                      │   downstream]         │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [8] Query: Map      │                      │
       │                      │  locations with      │                      │
       │                      │  device aggregation  │                      │
       │                      │  SELECT l.*,         │                      │
       │                      │  COUNT(d.device_id)  │                      │
       │                      │  as device_count,    │                      │
       │                      │  GROUP_CONCAT(       │                      │
       │                      │    d.device_name,    │                      │
       │                      │    ':', d.status      │                      │
       │                      │  ) as devices        │                      │
       │                      │  FROM locations l    │                      │
       │                      │  LEFT JOIN devices d  │                      │
       │                      │    ON l.location_id=  │                      │
       │                      │    d.location_id      │                      │
       │                      │  GROUP BY l.         │                      │
       │                      │  location_id          │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [9] Return: 6 rows   │                      │
       │                      │  [upstream start/end,│                      │
       │                      │   midstream start/end,│                      │
       │                      │   downstream          │
       │                      │   start/end]          │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [10] Query: 24h     │                      │
       │                      │  trend data          │                      │
       │                      │  av_overview_trend24()│                      │
       │                      │  → Hourly averages   │                      │
       │                      │  for each sensor     │                      │
       │                      │  type                │                      │
       │                      │                      │
       │                      │  [11] Calculate river│                      │
       │                      │  section health      │                      │
       │                      │  av_overview_         │                      │
       │                      │  calculate_river_    │                      │
       │                      │  status()            │                      │
       │                      │  → Normal/Moderate/  │                      │
       │                      │    Critical           │                      │
       │                      │                      │
       │                      │  [12] Build HTML     │                      │
       │                      │  response            │                      │
       │                      │  - Embed PHP vars    │                      │
       │                      │    into markup       │                      │
       │                      │  - Inline JSON for   │                      │
       │                      │    JavaScript init   │                      │
       │                      │                      │
       │  [13] Full HTML      │                      │                      │
       │  document with       │                      │                      │
       │  embedded data       │                      │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │
       │  [14] Browser parses │                      │                      │
       │  HTML, loads CSS     │                      │                      │
       │                      │                      │
       │  [15] JavaScript     │                      │                      │
       │  executes:           │                      │
       │  - Map.init()        │                      │
       │  - Chart.init()      │                      │
       │  - startPolling()    │                      │
```

### 3.2 AJAX Real-Time Polling (30-second interval)

```javascript
// dashboard.php — Client-side polling
let isPolling = true;
const POLL_INTERVAL = 30000; // 30 seconds

function pollDashboardData() {
  if (!isPolling) return;

  fetch('dashboard.php?action=fetch')
    .then(response => response.json())
    .then(data => {
      if (data.ok) {
        // [1] Update system status banner
        updateBanner(data.river_status, data.banner_color, data.banner_emoji);

        // [2] Update device counters
        updateCounters(data.dev_counts);

        // [3] Update active alerts table
        renderAlerts(data.alerts);

        // [4] Update sensor readings panel
        updateSensorReadings(data.device_readings);

        // [5] Update 24h trend charts
        updateCharts(data.chart_data, data.device_chart_data);

        // [6] Update sensor logs table
        updateLogs(data.logs);

        // [7] Update maintenance section
        updateMaintenance(data.maintenance);
      }
    })
    .catch(err => console.error('Poll error:', err));
}

// Start polling
setInterval(pollDashboardData, POLL_INTERVAL);
```

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] AJAX request    │                      │
       │  GET dashboard.php?  │                      │
       │  action=fetch         │                      │
       │  Accept:             │                      │
       │  application/json     │                      │
       │ ──────────────────────>│                      │
       │                      │                      │
       │                      │  [2] Call            │                      │
       │                      │  _build_full_fetch() │                      │
       │                      │  → Aggregates all    │                      │
       │                      │    dashboard data    │                      │
       │                      │    in single call    │                      │
       │                      │                      │
       │                      │  [3] Execute         │                      │
       │                      │  multiple queries:   │                      │
       │                      │  - Device counts     │                      │
       │                      │  - Latest readings   │                      │
       │                      │  - Active alerts     │                      │
       │                      │  - Map locations     │                      │
       │                      │  - 24h trend data    │                      │
       │                      │  - Section health    │                      │
       │                      │  - Maintenance logs  │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Return all rows │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [5] Assemble JSON   │                      │
       │                      │  response:           │                      │
       │                      │  {                   │                      │
       │                      │    ok: true,         │                      │
       │                      │    ts: "2026-03-22   │                      │
       │                      │      14:30:15",      │                      │
       │                      │    river_status:     │                      │
       │                      │      "Normal",        │                      │
       │                      │    banner_color:     │                      │
       │                      │      "#16a34a",       │                      │
       │                      │    dev_counts: {     │                      │
       │                      │      total: 8,       │                      │
       │                      │      active: 7,       │                      │
       │                      │      maint: 1         │                      │
       │                      │    },                │                      │
       │                      │    alerts: [...],    │                      │
       │                      │    device_readings:  │                      │
       │                      │      {...},          │                      │
       │                      │    chart_data: {...}, │                      │
       │                      │    logs: [...],      │                      │
       │                      │    maintenance: [...],│                      │
       │                      │    map_locations:    │                      │
       │                      │      [...]           │                      │
       │                      │  }                   │                      │
       │                      │                      │
       │  [6] JSON response   │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │
       │  [7] Client renders  │                      │
       │  updates:            │                      │
       │  - Banner color      │                      │
       │    (green/orange/red)│                      │
       │  - Alert count badge │                      │
       │  - Chart data points │                      │
       │  - Map marker states │                      │
       │  - Log table rows    │                      │
```

---

## 4. SENSOR SIMULATION & ALERT GENERATION

### 4.1 Complete Simulation Flow

```
┌─────────────┐          ┌──────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │ Business Logic│          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │                      │
       │  [1] Admin opens     │                      │                      │
       │  simulation panel    │                      │                      │
       │  on dashboard        │                      │                      │
       │                      │                      │                      │
       │  [2] Select device   │                      │                      │
       │  from dropdown       │                      │                      │
       │  (filtered: only     │                      │                      │
       │  active + assigned)  │                      │                      │
       │                      │                      │                      │
       │  [3] Enter values:   │                      │                      │
       │  Temperature: 26.5°C │                      │                      │
       │  pH Level: 9.2       │                      │                      │
       │  Turbidity: 15.3 NTU │                      │                      │
       │  Dissolved O2: 8.5   │                      │                      │
       │  mg/L                │                      │                      │
       │  Water Level: 1.8 m  │                      │                      │
       │  Sediments: 42.0 mg/L│                      │                      │
       │                      │                      │                      │
       │  [4] Click "Send"    │                      │                      │
       │                      │  POST dashboard.php  │                      │
       │                      │  ?action=simulate     │                      │
       │                      │  Content-Type:       │                      │
       │                      │  application/json    │                      │
       │                      │  Body: {             │                      │
       │                      │    device_id: 1,     │                      │
       │                      │    temperature:      │                      │
       │                      │      26.5,           │                      │
       │                      │    ph_level: 9.2,    │                      │
       │                      │    turbidity: 15.3,  │                      │
       │                      │    dissolved_oxygen: │                      │
       │                      │      8.5,           │                      │
       │                      │    water_level: 1.8, │                      │
       │                      │    sediments: 42.0   │                      │
       │                      │  }                   │                      │
       │                      │ ──────────────────────>│                      │
       │                      │                      │                      │
       │                      │  [5] Validate        │                      │
       │                      │  device_id           │                      │
       │                      │  SELECT device_id,   │                      │
       │                      │  status,             │                      │
       │                      │  location_id         │                      │
       │                      │  FROM devices        │                      │
       │                      │  WHERE device_id = ? │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [6] Device found,   │                      │
       │                      │  status=active       │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [7] For each sensor │                      │
       │                      │  type in request:    │                      │
       │                      │                      │                      │
       │                      │    ┌─────────────────┐                      │
       │                      │    │ [7a] Find sensor│                      │
       │                      │    │ for device +    │                      │
       │                      │    │ sensor_type     │                      │
       │                      │    │ SELECT sensor_id│                      │
       │                      │    │ FROM sensors    │                      │
       │                      │    │ WHERE device_id=│                      │
       │                      │    │ ? AND sensor_   │                      │
       │                      │    │ type = ?        │                      │
       │                      │    │ ──────────────────────>│
       │                      │    │                 │                      │
       │                      │    │ If not found:   │                      │
       │                      │    │ CREATE sensor   │                      │
       │                      │    │ with defaults   │                      │
       │                      │    │ ──────────────────────>│
       │                      │    │                 │                      │
       │                      │    │ [7b] INSERT     │                      │
       │                      │    │ reading         │                      │
       │                      │    │ INSERT INTO     │                      │
       │                      │    │ sensor_readings │                      │
       │                      │    │ (sensor_id,     │                      │
       │                      │    │  value,         │                      │
       │                      │    │  recorded_at)   │                      │
       │                      │    │ VALUES (?, ?,   │                      │
       │                      │    │  NOW())         │                      │
       │                      │    │ ──────────────────────>│
       │                      │    │                 │                      │
       │                      │    │ [7c] THRESHOLD  │                      │
       │                      │    │ CHECK           │                      │
       │                      │    │ ┌───────────────┐│                      │
       │                      │    │ Get min/max   ││                      │
       │                      │    │ from sensors  ││                      │
       │                      │    │ table:        ││                      │
       │                      │    │ pH: min=6.5   ││                      │
       │                      │    │     max=8.5   ││                      │
       │                      │    │               ││                      │
       │                      │    │ Input: 9.2    ││                      │
       │                      │    │ 9.2 > 8.5 →   ││                      │
       │                      │    │ BREACH!       ││                      │
       │                      │    │               ││                      │
       │                      │    │ Severity:     ││                      │
       │                      │    │ 9.2 > 8.5*1.3 ││                      │
       │                      │    │ → high (not   ││                      │
       │                      │    │ critical)     ││                      │
       │                      │    │               ││                      │
       │                      │    │ Alert msg:    ││                      │
       │                      │    │ "pH level too  ││                      │
       │                      │    │  high: 9.20   ││                      │
       │                      │    │  pH on         ││                      │
       │                      │    │  WQ-Upstream-  ││                      │
       │                      │    │  Start"       ││                      │
       │                      │    │               ││                      │
       │                      │    │ [7d] INSERT    ││                      │
       │                      │    │ alert (if      ││                      │
       │                      │    │ breached)      ││                      │
       │                      │    │ INSERT INTO    ││                      │
       │                      │    │ alerts (       ││                      │
       │                      │    │   sensor_id,   ││                      │
       │                      │    │   reading_id,  ││                      │
       │                      │    │   alert_type,  ││                      │
       │                      │    │   message,     ││                      │
       │                      │    │   status,      ││                      │
       │                      │    │   created_at   ││                      │
       │                      │    │ ) VALUES (     ││                      │
       │                      │    │   7, 1524,     ││                      │
       │                      │    │   'high',       ││                      │
       │                      │    │   'pH level...'││                      │
       │                      │    │   'active',    ││                      │
       │                      │    │   NOW()        ││                      │
       │                      │    │ )              ││                      │
       │                      │    │ ──────────────────────>│
       │                      │    │                 │                      │
       │                      │    └─────────────────┘                      │
       │                      │                      │                      │
       │                      │  [8] Update device   │                      │
       │                      │  last_active         │                      │
       │                      │  UPDATE devices      │                      │
       │                      │  SET last_active =   │                      │
       │                      │    NOW()             │                      │
       │                      │  WHERE device_id = 1│                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [9] Log simulation  │                      │
       │                      │  activity            │                      │
       │                      │  INSERT INTO         │                      │
       │                      │  system_logs (       │                      │
       │                      │    user_id,           │                      │
       │                      │    action,           │                      │
       │                      │    details,          │                      │
       │                      │    created_at        │                      │
       │                      │  ) VALUES (          │                      │
       │                      │    1,                │                      │
       │                      │    'simulate',       │                      │
       │                      │    'Simulated data   │                      │
       │                      │     for device 1',   │                      │
       │                      │    NOW()             │                      │
       │                      │  )                   │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [10] Build JSON     │                      │
       │                      │  response            │                      │
       │                      │  {                   │                      │
       │                      │    success: true,    │                      │
       │                      │    reading_id: 1523, │                      │
       │                      │    device_id: 1,     │                      │
       │                      │    device_name:       │                      │
       │                      │      "WQ-Upstream-   │                      │
       │                      │       Start",        │                      │
       │                      │    river_section:    │                      │
       │                      │      "upstream",      │                      │
       │                      │    readings: {       │                      │
       │                      │      temperature: {  │                      │
       │                      │        value: 26.5,  │                      │
       │                      │        unit: "°C"    │                      │
       │                      │      },              │                      │
       │                      │      ph_level: {     │                      │
       │                      │        value: 9.2,   │                      │
       │                      │        unit: "pH"    │                      │
       │                      │      }                │                      │
       │                      │    },                 │                      │
       │                      │    alerts_created: [  │                      │
       │                      │      {               │                      │
       │                      │        type: "high", │                      │
       │                      │        message:      │                      │
       │                      │          "pH level   │                      │
       │                      │           too high",│                      │
       │                      │        sensor_type:  │                      │
       │                      │          "ph_level"  │                      │
       │                      │      }                │                      │
       │                      │    ],                 │                      │
       │                      │    timestamp:        │                      │
       │                      │      "2026-03-22     │                      │
       │                      │       14:30:15"       │                      │
       │                      │  }                   │                      │
       │                      │                      │
       │  [11] JSON response  │                      │                      │
       │<─────────────────────│                      │                      │
       │                      │                      │
       │  [12] UI updates:    │                      │
       │  → Display new       │                      │
       │    readings in       │                      │
       │    sensor grid       │                      │
       │  → Add orange alert  │                      │
       │    badge for pH      │                      │
       │  → Flash pH value    │                      │
       │    with highlight    │                      │
       │  → Update chart with │                      │
       │    new data point    │                      │
       │  → Show toast:       │                      │
       │    "Data recorded -  │                      │
       │     1 alert created" │                      │
```

### 4.2 Threshold Check Algorithm

```php
// From: apps/admin/includes/dashboard_overview_data.php

function checkThreshold($value, $min, $max, $sensorType) {
    // Calculate severity thresholds
    $criticalLow  = $min * 0.7;   // 30% below minimum
    $warningLow   = $min;         // At minimum
    $warningHigh  = $max;         // At maximum
    $criticalHigh = $max * 1.3;   // 30% above maximum

    if ($value < $criticalLow) {
        return [
            'breached' => true,
            'severity' => 'critical',
            'type' => 'low',
            'message' => "$sensorType critically low: $value"
        ];
    }
    else if ($value < $warningLow) {
        return [
            'breached' => true,
            'severity' => 'warning',
            'type' => 'low',
            'message' => "$sensorType too low: $value"
        ];
    }
    else if ($value > $criticalHigh) {
        return [
            'breached' => true,
            'severity' => 'critical',
            'type' => 'high',
            'message' => "$sensorType critically high: $value"
        ];
    }
    else if ($value > $warningHigh) {
        return [
            'breached' => true,
            'severity' => 'warning',
            'type' => 'high',
            'message' => "$sensorType too high: $value"
        ];
    }

    return ['breached' => false];
}
```

---

## 5. DEVICE CRUD OPERATIONS

### 5.1 CREATE — Add New Device

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Navigate to     │                      │
       │  /apps/admin/        │                      │
       │  devices.php         │                      │
       │                      │                      │
       │  [2] Click "+ Add"   │                      │
       │                      │                      │
       │  [3] Fill form:      │                      │
       │  Device Name:        │                      │
       │    "WQ-Test-Station" │                      │
       │  Type: water_quality │                      │
       │  Status: active      │                      │
       │  Location:           │                      │
       │    Midstream Start   │                      │
       │                      │                      │
       │  [4] Submit          │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=add          │                      │
       │                      │  device_name=WQ-     │                      │
       │                      │    Test-Station      │                      │
       │                      │  device_type=        │                      │
       │                      │    water_quality_    │                      │
       │                      │    station           │                      │
       │                      │  status=active       │                      │
       │                      │  location_id=3       │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] Validate:       │                      │
       │                      │  - name not empty    │                      │
       │                      │  - name unique check │                      │
       │                      │  - status in enum    │                      │
       │                      │  - location_id valid │                      │
       │                      │    FK check:         │                      │
       │                      │    SELECT location_id│                      │
       │                      │    FROM locations    │                      │
       │                      │    WHERE location_id │                      │
       │                      │    = 3               │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [6] Location valid  │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [7] INSERT device   │                      │
       │                      │  INSERT INTO devices │                      │
       │                      │  (device_name,       │                      │
       │                      │   device_type,       │                      │
       │                      │   location_id,       │                      │
       │                      │   status,            │                      │
       │                      │   installation_date, │                      │
       │                      │   created_at)        │                      │
       │                      │  VALUES (            │                      │
       │                      │   'WQ-Test-Station', │                      │
       │                      │   'water_quality_    │                      │
       │                      │    station',         │                      │
       │                      │   3, 'active',       │                      │
       │                      │   CURDATE(),         │                      │
       │                      │   NOW()              │                      │
       │                      │  )                   │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [8] Return new      │                      │
       │                      │  device_id = 9       │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [9] AUTO-CREATE     │                      │
       │                      │  DEFAULT SENSORS     │                      │
       │                      │  For water_quality_  │                      │
       │                      │  station type:       │                      │
       │                      │                      │
       │                      │  INSERT INTO sensors │                      │
       │                      │  (device_id,         │                      │
       │                      │   sensor_type,       │                      │
       │                      │   unit,              │                      │
       │                      │   min_threshold,     │                      │
       │                      │   max_threshold)     │                      │
       │                      │  VALUES              │                      │
       │                      │  (9, 'temperature',  │                      │
       │                      │   '°C', 20, 35),     │                      │
       │                      │  (9, 'ph_level',     │                      │
       │                      │   'pH', 6.5, 8.5),   │                      │
       │                      │  (9, 'turbidity',    │                      │
       │                      │   'NTU', 0, 50),     │                      │
       │                      │  (9, 'dissolved_     │                      │
       │                      │   oxygen', 'mg/L',   │                      │
       │                      │   5, 14),            │                      │
       │                      │  (9, 'water_level',  │                      │
       │                      │   'm', 0.5, 3.0),    │                      │
       │                      │  (9, 'sediments',    │                      │
       │                      │   'mg/L', 0, 500)    │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [10] Log action      │                      │
       │                      │  INSERT system_logs  │                      │
       │                      │  action='device_      │                      │
       │                      │  created'            │                      │
       │                      │  details='Created    │                      │
       │                      │  WQ-Test-Station'    │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [11] Set flash      │                      │
       │                      │  message:            │                      │
       │                      │  "Device added        │                      │
       │                      │  successfully"       │                      │
       │                      │                      │
       │                      │  [12] Redirect 302   │                      │
       │                      │  to devices.php      │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [13] Browser loads   │                      │
       │  devices.php with    │                      │
       │  success message     │                      │
       │                      │                      │
       │  [14] New device     │                      │
       │  appears in list     │                      │
       │  Map marker added    │                      │
       │  at location 3       │                      │
```

### 5.2 UPDATE — Edit Device

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks    │                      │
       │  ✏️ Edit on device    │                      │
       │  row                 │                      │
       │                      │                      │
       │  [2] Modal opens     │                      │
       │  with pre-filled:    │                      │
       │  - device_id: 3      │                      │
       │  - name: WQ-Mid-     │                      │
       │    Start             │                      │
       │  - status: active    │                      │
       │  - location: 3       │                      │
       │                      │                      │
       │  [3] Change status   │                      │
       │  to "maintenance"    │                      │
       │  Unassign location   │                      │
       │  (set to empty)      │                      │
       │                      │                      │
       │  [4] Submit          │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=edit         │                      │
       │                      │  device_id=3         │                      │
       │                      │  device_name=WQ-     │                      │
       │                      │    Midstream-Start   │                      │
       │                      │  status=maintenance  │                      │
       │                      │  location_id=        │                      │
       │                      │  (empty string →     │                      │
       │                      │   NULL)              │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] Validate        │                      │
       │                      │  device_id exists    │                      │
       │                      │                      │
       │                      │  [6] UPDATE devices  │                      │
       │                      │  SET                 │                      │
       │                      │    device_name =      │                      │
       │                      │    'WQ-Midstream-    │                      │
       │                      │     Start',           │                      │
       │                      │    status =           │                      │
       │                      │    'maintenance',    │                      │
       │                      │    location_id =      │                      │
       │                      │    NULL,             │                      │
       │                      │    updated_at =       │                      │
       │                      │    NOW()             │                      │
       │                      │  WHERE device_id = 3 │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [7] Log edit        │                      │
       │                      │  system_logs         │                      │
       │                      │  action='device_edit'│                      │
       │                      │  details='Changed     │                      │
       │                      │  status to            │                      │
       │                      │  maintenance,        │                      │
       │                      │  unassigned'         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [8] Redirect with   │                      │
       │                      │  success flash       │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [9] Map marker       │                      │
       │  changes:           │                      │
       │  - Color: green →    │                      │
       │    orange            │                      │
       │  - Removed from      │                      │
       │    location cluster  │                      │
       │  - Now in            │                      │
       │    "Unassigned"      │                      │
       │    section           │                      │
```

### 5.3 DELETE — Remove Device

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin clicks 🗑️ │                      │
       │  on device row        │                      │
       │                      │                      │
       │  [2] Confirm dialog   │                      │
       │  "Delete WQ-Test-    │                      │
       │   Station?           │                      │
       │   All related data    │                      │
       │   will be removed."  │                      │
       │                      │                      │
       │  [3] Confirm          │                      │
       │                      │  POST devices.php    │                      │
       │                      │  action=delete       │                      │
       │                      │  device_id=9         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] DELETE device   │                      │
       │                      │  DELETE FROM devices │                      │
       │                      │  WHERE device_id = 9 │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [5] CASCADE deletes │                      │
       │                      │  via FK constraints:   │                      │
       │                      │                      │
       │                      │  sensors (device_id) │                      │
       │                      │  → ON DELETE CASCADE │                      │
       │                      │    → sensor_readings │                      │
       │                      │      (sensor_id)     │                      │
       │                      │      → ON DELETE     │                      │
       │                      │        CASCADE       │                      │
       │                      │    → alerts          │                      │
       │                      │      (sensor_id)     │                      │
       │                      │      → ON DELETE     │                      │
       │                      │        CASCADE       │                      │
       │                      │                      │
       │                      │  maintenance_logs   │                      │
       │                      │  (device_id)         │                      │
       │                      │  → ON DELETE CASCADE │                      │
       │                      │                      │
       │                      │  [6] Log deletion    │                      │
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
       │  Device list         │                      │
       │  updated             │                      │
```

---

## 6. LOCATION MANAGEMENT FLOW

### 6.1 Add Location with Map

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Navigate to     │                      │
       │  /apps/admin/        │                      │
       │  locations.php       │                      │
       │                      │                      │
       │  [2] Click "+ Add    │                      │
       │  Location"          │                      │
       │                      │                      │
       │  [3] Map opens       │                      │
       │  Default center:     │                      │
       │  Manolo Fortich      │                      │
       │  (8.3689, 124.8630)  │                      │
       │                      │                      │
       │  [4] Click on map    │                      │
       │  or drag pin to      │                      │
       │  desired location    │                      │
       │                      │                      │
       │  [5] JavaScript      │                      │
       │  updates form:       │                      │
       │  lat: 8.394873       │                      │
       │  lng: 124.903068     │                      │
       │                      │                      │
       │  [6] Fill form:      │                      │
       │  Name: Midstream End │                      │
       │  Section: midstream  │                      │
       │  Type: end           │                      │
       │  Coordinates: auto   │                      │
       │  filled from map     │                      │
       │                      │                      │
       │  [7] Assign devices  │                      │
       │  ☑️ WQ-Midstream-     │                      │
       │     Start            │                      │
       │  ☐ WQ-Midstream-End  │                      │
       │  ☐ Weather-Central   │                      │
       │                      │                      │
       │  [8] Submit          │                      │
       │                      │  POST locations.php  │                      │
       │                      │  action=add          │                      │
       │                      │  location_name=       │                      │
       │                      │    Midstream+End     │                      │
       │                      │  river_section=      │                      │
       │                      │    midstream         │                      │
       │                      │  location_type=end   │                      │
       │                      │  latitude=8.394873   │                      │
       │                      │  longitude=          │                      │
       │                      │    124.903068        │                      │
       │                      │  devices[]=2         │                      │
       │                      │  devices[]=7         │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [9] INSERT location   │                      │
       │                      │  INSERT INTO         │                      │
       │                      │  locations (          │                      │
       │                      │    location_name,     │                      │
       │                      │    river_section,    │                      │
       │                      │    location_type,    │                      │
       │                      │    latitude,          │                      │
       │                      │    longitude,        │                      │
       │                      │    created_at        │                      │
       │                      │  ) VALUES (           │                      │
       │                      │    'Midstream End',  │                      │
       │                      │    'midstream',       │                      │
       │                      │    'end',             │                      │
       │                      │    8.394873,          │                      │
       │                      │    124.903068,        │                      │
       │                      │    NOW()              │                      │
       │                      │  )                   │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [10] Return          │                      │
       │                      │  location_id = 4      │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [11] UPDATE devices  │                      │
       │                      │  SET location_id = 4 │                      │
       │                      │  WHERE device_id IN   │                      │
       │                      │  (2, 7)              │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [12] Log action      │                      │
       │                      │  system_logs          │                      │
       │                      │  action='location_    │                      │
       │                      │  created'             │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [13] Redirect        │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [14] Map shows       │                      │
       │  new marker with      │                      │
       │  assigned devices     │                      │
```

---

## 7. ALERT MANAGEMENT FLOW

### 7.1 Acknowledge All Active Alerts

```
┌─────────────┐          ┌──────────────┐          ┌──────────┐
│   Browser   │          │  PHP Server  │          │  MySQL   │
└──────┬──────┘          └──────┬───────┘          └────┬─────┘
       │                      │                      │
       │  [1] Admin sees 3    │                      │
       │  active alerts in    │                      │
       │  dashboard panel      │                      │
       │                      │                      │
       │  [2] Review alerts:  │                      │
       │  - 🔴 pH too high   │                      │
       │    @ Upstream        │                      │
       │  - 🟡 Temp elevated │                      │
       │    @ Midstream       │                      │
       │  - 🟡 Turbidity high│                      │
       │    @ Downstream      │                      │
       │                      │                      │
       │  [3] Click "Acknow-  │                      │
       │  ledge All" button  │                      │
       │                      │  POST dashboard.php  │                      │
       │                      │  action=             │                      │
       │                      │  acknowledge_all     │                      │
       │                      │  Body: {}            │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [4] Get admin ID    │                      │
       │                      │  from session: 1     │                      │
       │                      │                      │
       │                      │  [5] UPDATE all      │                      │
       │                      │  active alerts       │                      │
       │                      │  UPDATE alerts       │                      │
       │                      │  SET                   │                      │
       │                      │    status =            │                      │
       │                      │    'resolved',        │                      │
       │                      │    resolved_by = 1,   │                      │
       │                      │    resolved_at =      │                      │
       │                      │    NOW()              │                      │
       │                      │  WHERE status =       │                      │
       │                      │    'active'            │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [6] Return            │                      │
       │                      │  3 rows affected     │                      │
       │                      │<──────────────────────│
       │                      │                      │
       │                      │  [7] Log action        │                      │
       │                      │  INSERT INTO           │                      │
       │                      │  system_logs (        │                      │
       │                      │    user_id,           │                      │
       │                      │    action,            │                      │
       │                      │    details,           │                      │
       │                      │    created_at         │                      │
       │                      │  ) VALUES (           │                      │
       │                      │    1,                 │                      │
       │                      │    'ALERTS_          │                      │
       │                      │    ACKNOWLEDGE',      │                      │
       │                      │    'Resolved 3        │                      │
       │                      │    active alerts',    │                      │
       │                      │    NOW()              │                      │
       │                      │  )                    │                      │
       │                      │ ──────────────────────>│
       │                      │                      │
       │                      │  [8] Build response    │                      │
       │                      │  { ok: true,          │                      │
       │                      │    resolved: 3 }       │                      │
       │                      │<─────────────────────│
       │                      │                      │
       │  [9] UI updates       │                      │
       │  → Alert table       │                      │
       │    empties             │                      │
       │  → Badge count        │                      │
       │    (3 → 0)             │                      │
       │  → Toast: "3 alerts   │                      │
       │    resolved"           │                      │
       │  → Banner color       │                      │
       │    may change to      │                      │
       │    green               │                      │
```

### 7.2 Alert State Lifecycle

```
┌─────────────────┐
│  SENSOR READING │
│   Inserted      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Threshold Check │
│ ┌─────────────┐ │
│ │ Value < min?│ │ ──→ LOW alert
│ │ Value > max?│ │ ──→ HIGH alert
│ │ In range?   │ │ ──→ No alert
│ └─────────────┘ │
└────────┬────────┘
         │ (breach detected)
         ▼
┌─────────────────┐
│  ALERT CREATED  │
│  status: active │
│  alert_type:    │
│  low/high/crit  │
└────────┬────────┘
         │
         │ [Admin views dashboard]
         ▼
┌─────────────────┐
│  Admin clicks   │
│  "Acknowledge   │
│  All"           │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  ALERT RESOLVED │
│  status: resolved│
│  resolved_by:   │
│  admin_id       │
│  resolved_at:   │
│  timestamp      │
└─────────────────┘
```

---

## 8. COMPLETE ADMIN SESSION FLOW

### 8.1 End-to-End User Journey

```
┌──────────────┐
│   START      │
│  Open browser│
│  /login.php  │
└──────┬───────┘
       │
       ▼
┌──────────────────┐
│ [1] LOGIN        │
│ Enter credentials │
│ Submit form      │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐     ┌──────────────────┐
│ [2] AUTHENTICATE │────→│ Invalid?         │
│ Check password   │     │ Show error       │
│ Create session   │     │ Return to login  │
└────────┬─────────┘     └──────────────────┘
         │
         ▼
┌──────────────────┐
│ [3] REDIRECT     │
│ To admin/        │
│ dashboard.php    │
└────────┬─────────┘
         │
         ▼
┌──────────────────────────────────────────┐
│ [4] DASHBOARD LOADS                      │
│ - PHP queries database                   │
│ - Renders HTML with embedded data        │
│ - JavaScript initializes:                │
│   → Map with device markers              │
│   → Charts with 24h trends               │
│   → Alert table                          │
│   → Start 30s polling                    │
└────────┬─────────────────────────────────┘
         │
    ┌────┴────┬────────┬────────┬────────┐
    │         │        │        │        │
    ▼         ▼        ▼        ▼        ▼
┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐
│[5a]  │ │[5b]  │ │[5c]  │ │[5d]  │ │[5e]  │
│Simu- │ │Device│ │Loc-  │ │Alert │ │Export│
│late  │ │CRUD  │ │ation │ │Manag-│ │Data  │
│Data  │ │      │ │CRUD  │ │ement │ │      │
└──┬───┘ └──┬───┘ └──┬───┘ └──┬───┘ └──┬───┘
   │        │        │        │        │
   │        │        │        │        │
   ▼        ▼        ▼        ▼        ▼
┌──────────────────────────────────────────┐
│ [6] ALL ACTIONS LOGGED TO system_logs  │
│ With: user_id, action, details,        │
│ ip_address, timestamp                    │
└────────┬─────────────────────────────────┘
         │
         ▼
┌──────────────────┐
│ [7] LOGOUT       │
│ Click logout     │
│ Destroy session  │
│ Log action       │
└────────┬─────────┘
         │
         ▼
┌──────────────┐
│    END       │
│  Redirect to │
│  /login.php  │
└──────────────┘
```

### 8.2 Data Flow Summary by Action

| Admin Action | Input | Processing | Output | Tables Affected |
|-------------|-------|-----------|--------|----------------|
| **Login** | username, password | Hash verify, session create | Dashboard redirect | users (read), system_logs (insert) |
| **View Dashboard** | — (session cookie) | Aggregate queries, trend calc | HTML + embedded JSON | 6 tables (read) |
| **Poll Data** | — (AJAX) | _build_full_fetch() | JSON payload | 6 tables (read) |
| **Simulate Reading** | device_id, sensor values | Insert readings, threshold check | JSON with alerts | sensor_readings (insert), alerts (conditional insert), devices (update), system_logs (insert) |
| **Add Device** | name, type, status, location | Validate, insert device, auto-create sensors | Redirect with success | devices (insert), sensors (x6 insert), system_logs (insert) |
| **Edit Device** | device_id, fields | Validate, update fields | Redirect with success | devices (update), system_logs (insert) |
| **Delete Device** | device_id | DELETE with cascade | Redirect with success | devices (delete), sensors (cascade), readings (cascade), alerts (cascade), maintenance_logs (cascade), system_logs (insert) |
| **Add Location** | name, section, coords, devices | Insert location, assign devices | Redirect with success | locations (insert), devices (update), system_logs (insert) |
| **Acknowledge Alerts** | — (button click) | UPDATE status to resolved | JSON {resolved: N} | alerts (update), system_logs (insert) |
| **Export Data** | format, sensor_type, hours | Query, format as CSV/JSON | Download file | sensor_readings, sensors, devices, locations (read) |
| **Logout** | — (click) | Destroy session, log action | Login page | system_logs (insert) |

---

## APPENDIX A: Complete Database Query Reference

### A.1 Authentication Query
```sql
SELECT user_id, username, password_hash, role, full_name, is_active
FROM users
WHERE username = ? AND is_active = 1;
```

### A.2 Dashboard Data Aggregation
```sql
-- Device counts by status
SELECT status, COUNT(*) as total
FROM devices
GROUP BY status;

-- Latest readings per device
SELECT d.device_id, s.sensor_type, sr.value, s.unit
FROM devices d
LEFT JOIN sensors s ON d.device_id = s.device_id
LEFT JOIN sensor_readings sr ON s.sensor_id = sr.sensor_id
WHERE sr.recorded_at = (
    SELECT MAX(recorded_at)
    FROM sensor_readings
    WHERE sensor_id = s.sensor_id
);

-- Active alerts with context
SELECT a.alert_id, a.alert_type, a.message, a.status,
       s.sensor_type, d.device_name, l.location_name, l.river_section
FROM alerts a
JOIN sensors s ON a.sensor_id = s.sensor_id
JOIN devices d ON s.device_id = d.device_id
LEFT JOIN locations l ON d.location_id = l.location_id
WHERE a.status = 'active'
ORDER BY a.created_at DESC;

-- Map data with device aggregation
SELECT l.location_id, l.location_name, l.latitude, l.longitude,
       l.river_section, l.location_type,
       COUNT(d.device_id) as device_count,
       GROUP_CONCAT(
         CONCAT(d.device_name, ':', d.status)
         SEPARATOR '|'
       ) as devices
FROM locations l
LEFT JOIN devices d ON l.location_id = d.location_id
GROUP BY l.location_id;
```

### A.3 Simulation Queries
```sql
-- Insert sensor reading
INSERT INTO sensor_readings (sensor_id, value, recorded_at)
VALUES (?, ?, NOW());

-- Check threshold (in PHP, not SQL)
-- SELECT min_threshold, max_threshold FROM sensors WHERE sensor_id = ?

-- Create alert if breached
INSERT INTO alerts (sensor_id, reading_id, alert_type, message, status, created_at)
VALUES (?, ?, 'high', ?, 'active', NOW());

-- Update device last_active
UPDATE devices SET last_active = NOW() WHERE device_id = ?;
```

### A.4 CRUD Queries
```sql
-- Create device
INSERT INTO devices (device_name, device_type, location_id, status, installation_date, created_at)
VALUES (?, ?, ?, ?, CURDATE(), NOW());

-- Create default sensors (x6 for water quality)
INSERT INTO sensors (device_id, sensor_type, unit, min_threshold, max_threshold)
VALUES
(?, 'temperature', '°C', 20, 35),
(?, 'ph_level', 'pH', 6.5, 8.5),
(?, 'turbidity', 'NTU', 0, 50),
(?, 'dissolved_oxygen', 'mg/L', 5, 14),
(?, 'water_level', 'm', 0.5, 3.0),
(?, 'sediments', 'mg/L', 0, 500);

-- Update device
UPDATE devices
SET device_name = ?, status = ?, location_id = ?, updated_at = NOW()
WHERE device_id = ?;

-- Delete device (cascade handles related records)
DELETE FROM devices WHERE device_id = ?;
```

### A.5 Alert Management
```sql
-- Acknowledge all active alerts
UPDATE alerts
SET status = 'resolved',
    resolved_by = ?,
    resolved_at = NOW()
WHERE status = 'active';
```

---

## APPENDIX B: File Structure & Component Mapping

| Component | File Path | Role in Admin Flow |
|-----------|-----------|-------------------|
| **Database Config** | `database/config.php` | Connection, session, auth functions |
| **Schema** | `database/setup.sql` | Table definitions, seed data |
| **Login** | `login.php` | Authentication form + handler |
| **Admin Dashboard** | `apps/admin/dashboard.php` | Main monitoring UI + API endpoints |
| **Dashboard API** | `apps/admin/includes/dashboard_overview_api.php` | Simulate, fetch, monitor_state |
| **Dashboard Data** | `apps/admin/includes/dashboard_overview_data.php` | Trend calc, threshold check, alert gen |
| **Devices Page** | `apps/admin/devices.php` | Device CRUD + map visualization |
| **Locations Page** | `apps/admin/locations.php` | Location CRUD + interactive map |
| **Navigation** | `assets/navigation.php` | Sidebar with role-based links |
| **Researcher Export** | `apps/researcher/dashboard.php` | CSV/JSON export + analysis |

---

*Complete Admin Data Flow Documentation*
*Aqua-Vision Water Quality Monitoring System*
*GRASPS Performance Assessment — Client-Server Architecture*
