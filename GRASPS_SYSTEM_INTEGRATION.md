# Aqua-Vision: Client-Server Architecture & System Integration Design
## GRASPS Performance Assessment Documentation

---

## 1. SYSTEM PROPOSAL

### 1.1 System Overview

**Aqua-Vision** is a web-based water quality monitoring system designed to track, analyze, and manage the health of the Mangima River in Bukidnon, Philippines. The system employs a **client-server architecture** where sensor devices deployed at various river sections (upstream, midstream, downstream) transmit data to a central server, which processes, stores, and serves the data to users through an interactive web dashboard.

**Core Purpose:**
- Real-time monitoring of water quality parameters (pH, temperature, turbidity, dissolved oxygen, water level, sediments)
- Interactive map visualization of device locations across river sections
- Automated alert generation when sensor readings exceed safe thresholds
- Role-based access control for administrators, operators, researchers, and viewers
- Full CRUD management for devices, locations, and system configuration

### 1.2 Target Users

| Role | Access Level | Primary Functions |
|------|-------------|-------------------|
| **Admin** | Full access | Dashboard overview, device/location CRUD, user management, system settings, alert management |
| **Operator** | Operational | Device maintenance logging, sensor calibration, field data entry, alert acknowledgment |
| **Researcher** | Analytical | Data export (CSV/JSON), statistical analysis, trend visualization, correlation studies |
| **Viewer** | Read-only | Public dashboard view, basic sensor readings, no modification capabilities |

---

## 2. FUNCTIONAL SYSTEM PROTOTYPE

### 2.1 User Input (Forms)

The system accepts user input through multiple form interfaces:

**A. Authentication Forms**
- Login form: `username`, `password` → validated against `users` table
- Session management via PHP `$_SESSION` with role-based redirection

**B. Device Management Forms** (`apps/admin/devices.php`)
- Add/Edit Device: `device_name`, `status` (active/maintenance/inactive), `location_id`
- Delete Device: Confirmation with cascade handling for related sensors/readings

**C. Location Management Forms** (`apps/admin/locations.php`)
- Add/Edit Location: `location_name`, `river_section`, `latitude`, `longitude` (via interactive map pin)
- Device Assignment: Checkbox selection of active unassigned devices

**D. Sensor Simulation Form** (`apps/admin/dashboard.php`)
- Manual data entry: `temperature`, `ph_level`, `turbidity`, `dissolved_oxygen`, `water_level`, `sediments`
- Auto-generate realistic readings based on threshold ranges

**E. Maintenance Logging Form**
- `maintenance_type` (calibration/repair/replacement/cleaning/inspection/malfunction_fix)
- `damage_level`, `notes`, `parts_used`, `cost`, `duration_minutes`

### 2.2 Server Data Processing (API Requests)

The system processes data through multiple server-side endpoints:

**A. Sensor Simulation API**
```
POST dashboard.php?action=simulate
Body: { device_id, temperature, ph_level, turbidity, dissolved_oxygen, water_level, sediments }
```
- Validates device exists and is active
- Auto-creates sensors if missing for the device
- Inserts readings into `sensor_readings` table
- Checks thresholds and auto-generates alerts if values out of range
- Updates `devices.last_active` timestamp
- Logs activity to `system_logs`

**B. Dashboard Data Fetch API**
```
GET dashboard.php?action=fetch
Response: { devices, readings, alerts, chart_data, device_chart_data, logs, map_locations, maintenance, section_conditions }
```
- Aggregates latest readings per sensor per device
- Calculates 24-hour trend averages per hour
- Computes river section status (Normal/Moderate/Critical)
- Builds device-specific and global chart datasets

**C. Monitor State API**
```
POST/GET dashboard.php?action=monitor_state
Body: { running, mode, device_id, interval }
```
- Stores live monitoring configuration in `system_settings`
- Controls auto-refresh behavior for real-time dashboards

**D. Alert Management APIs**
```
POST dashboard.php?action=acknowledge_all  → Resolves all active alerts
POST dashboard.php?action=force_test_alert → Creates test alert for validation
```

**E. Researcher Export APIs** (`apps/researcher/dashboard.php`)
```
GET researcher/dashboard.php?action=export&format=csv&sensor_type=temperature&hours=24
GET researcher/dashboard.php?action=analyze&sensor_type=temperature&hours=24
```
- Exports filtered sensor data as CSV or JSON
- Returns statistical analysis: averages, min/max, hourly trends, section comparisons, alert summaries

### 2.3 Database Operations

**A. Database Schema (10 Tables)**
```
users          → Authentication & role management
locations      → River section monitoring points (upstream/midstream/downstream)
devices        → Sensor stations with status and location assignment
sensors        → Individual sensor types per device with thresholds
sensor_readings → Time-series data (value, recorded_at, FK to sensors)
alerts         → Threshold breach notifications with resolution tracking
maintenance_logs → Device service history with damage assessment
notifications  → User-specific alert messages
reports        → Generated analysis exports
system_logs    → Audit trail of all user actions
system_settings → Runtime configuration (monitor state, retention)
```

**B. Key Data Flows**
1. **Reading Insertion**: `sensor_readings` → triggers threshold check → creates `alerts` if out of range
2. **Device Assignment**: `devices.location_id` FK → `locations` enables map visualization
3. **Alert Cascade**: `alerts.sensor_id` → `sensors` → `devices` → `locations` for contextual messages
4. **Maintenance Tracking**: `maintenance_logs.device_id` + `performed_by` → `users` for accountability
5. **System Audit**: All actions logged to `system_logs` with `user_id`, `ip_address`, `user_agent`

**C. Indexes for Performance**
- `idx_sensor_time` on `(sensor_id, recorded_at)` → Fast time-series queries
- `idx_status_created` on `alerts` → Quick active alert retrieval
- `idx_device_performed` on `maintenance_logs` → Device service history

### 2.4 Client Output Display

**A. Admin Dashboard** (`apps/admin/dashboard.php`)
- **System Status Banner**: River health (Normal/Moderate/Critical) with color coding
- **Interactive Map**: Leaflet.js with device markers, popups showing status and assigned devices
- **Device Sensor Data Panel**: Dropdown-filtered active devices, real-time readings with threshold indicators
- **24-Hour Trends Chart**: Chart.js with multi-sensor line visualization, clickable legend for single-sensor focus
- **Active Alerts Table**: Severity, location, device, message with acknowledge actions
- **Sensor Logs**: Recent readings with threshold breach highlighting
- **Maintenance Logs**: Latest service activities with operator names

**B. Researcher Dashboard** (`apps/researcher/dashboard.php`)
- Statistical summaries per sensor type
- Correlation analysis between sensor parameters
- Section comparison (upstream vs midstream vs downstream)
- Data export functionality (CSV/JSON)

**C. Operator Interface**
- Maintenance form with damage level assessment
- Alert acknowledgment workflow
- Device status updates

---

## 3. CLIENT-SERVER ARCHITECTURE DESIGN

### 3.1 Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────┐  │
│  │  Web Browser │  │  Web Browser │  │  Web Browser │  │  Mobile  │  │
│  │  (Admin)     │  │  (Researcher)│  │  (Operator)  │  │  (Viewer)│  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  └────┬─────┘  │
│         │                 │                 │               │         │
│  ┌──────▼───────┐  ┌──────▼───────┐  ┌──────▼───────┐      │         │
│  │ HTML/CSS/JS  │  │ HTML/CSS/JS  │  │ HTML/CSS/JS  │      │         │
│  │ Chart.js     │  │ Chart.js     │  │ Leaflet Map  │      │         │
│  │ Leaflet.js   │  │ Export UI    │  │ Forms        │      │         │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘      │         │
│         │                 │                 │               │         │
│         └─────────────────┴─────────────────┘               │         │
│                           │                                   │         │
│                    HTTP/HTTPS (AJAX/Fetch)                    │         │
└───────────────────────────┼───────────────────────────────────┼─────────┘
                           │                                   │
                           ▼                                   ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                              SERVER LAYER                                │
│                         Apache HTTP Server (XAMPP)                       │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │                         PHP 8.x Engine                            │  │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────┐ │  │
│  │  │ dashboard.  │  │ devices.    │  │ locations.  │  │ login.  │ │  │
│  │  │ php         │  │ php         │  │ php         │  │ php     │ │  │
│  │  │ (API + UI)  │  │ (CRUD)      │  │ (CRUD +    │  │ (Auth)  │ │  │
│  │  │             │  │             │  │  Map)       │  │         │ │  │
│  │  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └────┬────┘ │  │
│  │         │                │                │              │      │  │
│  │  ┌──────▼────────────────▼────────────────▼──────────────▼────┐ │  │
│  │  │              Business Logic Layer                            │ │  │
│  │  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │ │  │
│  │  │  │ Threshold    │  │ Alert Gen.   │  │ Status Calc.     │  │ │  │
│  │  │  │ Checking     │  │ Engine       │  │ (Normal/Warning/│  │ │  │
│  │  │  │              │  │              │  │  Critical)       │  │ │  │
│  │  │  └──────────────┘  └──────────────┘  └──────────────────┘  │ │  │
│  │  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │ │  │
│  │  │  │ Trend        │  │ Data         │  │ Export/          │  │ │  │
│  │  │  │ Aggregation  │  │ Simulation   │  │  Analysis        │  │ │  │
│  │  │  └──────────────┘  └──────────────┘  └──────────────────┘  │ │  │
│  │  └──────────────────────────┬─────────────────────────────────┘ │  │
│  │                             │                                   │  │
│  │  ┌──────────────────────────▼─────────────────────────────────┐  │  │
│  │  │              Data Access Layer (MySQLi)                     │  │  │
│  │  │  ┌─────────────────────────────────────────────────────┐   │  │  │
│  │  │  │  database/config.php — Connection & Query Builder   │   │  │  │
│  │  │  └─────────────────────────────────────────────────────┘   │  │  │
│  │  └───────────────────────────────────────────────────────────┘  │  │
│  └─────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           DATABASE LAYER                               │
│                         MySQL (mangima_watershed)                      │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐   │
│  │  users   │ │ locations│ │ devices  │ │ sensors  │ │ readings │   │
│  │  (auth)  │ │  (map)   │ │ (status) │ │(threshold│ │ (time-   │   │
│  │          │ │          │ │          │ │  s)      │ │  series) │   │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘   │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                │
│  │  alerts  │ │maint_logs│ │notifications│ │ sys_logs│                │
│  │(threshold│ │(service) │ │  (user)   │ │ (audit) │                │
│  │ breach)  │ │          │ │          │ │          │                │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘                │
└─────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Component Responsibilities

| Layer | Component | Responsibility |
|-------|-----------|--------------|
| **Client** | HTML/CSS/JS | Render UI, capture input, display charts and maps |
| **Client** | Chart.js | Visualize 24-hour sensor trends with interactive legends |
| **Client** | Leaflet.js | Display interactive map with device location markers |
| **Server** | PHP Controllers | Handle HTTP requests, validate input, orchestrate business logic |
| **Server** | Business Logic | Threshold checking, alert generation, status calculation, trend aggregation |
| **Server** | Data Access | MySQLi prepared statements for secure database operations |
| **Database** | MySQL | Persistent storage with referential integrity via FK constraints |
| **Database** | Indexes | Optimized queries for time-series data and active alert retrieval |

---

## 4. SYSTEM INTEGRATION DESIGN

### 4.1 API Endpoints — Request & Response

#### **Endpoint 1: Sensor Simulation**
```
POST /apps/admin/dashboard.php?action=simulate
Content-Type: application/json

REQUEST BODY:
{
  "device_id": 1,
  "temperature": 26.5,
  "ph_level": 7.2,
  "turbidity": 15.3,
  "dissolved_oxygen": 8.5,
  "water_level": 1.8,
  "sediments": 42.0
}

RESPONSE 200:
{
  "success": true,
  "reading_id": 1523,
  "device_id": 1,
  "device_name": "WQ-Upstream-Start",
  "river_section": "upstream",
  "readings": {
    "temperature": { "value": 26.5, "unit": "°C", "reading_id": 1523 },
    "ph_level": { "value": 7.2, "unit": "pH", "reading_id": 1524 }
  },
  "alerts_created": [
    { "type": "high", "message": "Turbidity too high: 55.3 NTU...", "sensor_type": "turbidity" }
  ],
  "timestamp": "2026-03-22 14:30:15",
  "sync": { /* full dashboard payload */ }
}

RESPONSE 400:
{ "error": "Invalid device_id" }

RESPONSE 404:
{ "error": "Device not found or inactive" }
```

#### **Endpoint 2: Dashboard Data Fetch**
```
GET /apps/admin/dashboard.php?action=fetch

RESPONSE 200:
{
  "ok": true,
  "ts": "2026-03-22 14:30:15",
  "river_status": "Normal",
  "banner_color": "#16a34a",
  "banner_emoji": "✅",
  "warn_count": 0,
  "alert_count": 3,
  "dev_counts": { "total": 8, "active": 7, "offline": 0, "maint": 1 },
  "device_readings": {
    "1": { "temperature": 26.5, "ph_level": 7.2, "recorded_at": "2026-03-22 14:30:00" }
  },
  "devices": [ /* device metadata */ ],
  "alerts": [ /* active alerts with location context */ ],
  "logs": [ /* recent sensor readings */ ],
  "map_locations": [ /* location summaries for map markers */ ],
  "chart_data": { "temperature": [22.5, 23.1, ...], "pH": [...] },
  "device_chart_data": { "1": { "temperature": [...] } },
  "maintenance": [ /* recent service logs */ ],
  "section_conditions": {
    "upstream": { "temperature": 25.8, "ph_level": 7.1 },
    "midstream": { "temperature": 26.2, "ph_level": 7.3 },
    "downstream": { "temperature": 27.1, "ph_level": 7.0 }
  }
}
```

#### **Endpoint 3: Live Monitor State**
```
POST /apps/admin/dashboard.php?action=monitor_state
Content-Type: application/json

REQUEST BODY:
{
  "running": true,
  "mode": "normal",
  "device_id": 1,
  "interval": 5000
}

RESPONSE 200:
{ "ok": true }

GET /apps/admin/dashboard.php?action=monitor_state

RESPONSE 200:
{
  "ok": true,
  "state": {
    "running": true,
    "mode": "normal",
    "device_id": 1,
    "interval": 5000,
    "started_at": "2026-03-22 14:00:00",
    "started_by": 1
  }
}
```

#### **Endpoint 4: Data Export (Researcher)**
```
GET /apps/researcher/dashboard.php?action=export&format=csv&sensor_type=temperature&hours=24

RESPONSE (CSV):
Reading ID,Device,Location,River Section,Sensor Type,Value,Unit,Recorded At,Min Threshold,Max Threshold
1523,WQ-Upstream-Start,Upstream Start,upstream,temperature,26.5,°C,2026-03-22 14:30:00,20,35
...

GET /apps/researcher/dashboard.php?action=export&format=json&hours=168

RESPONSE (JSON):
{
  "data": [ /* array of reading records */ ],
  "exported_at": "2026-03-22 14:30:15"
}
```

#### **Endpoint 5: Statistical Analysis**
```
GET /apps/researcher/dashboard.php?action=analyze&sensor_type=temperature&hours=24

RESPONSE 200:
{
  "statistics": {
    "total_readings": 48,
    "avg_value": 25.8,
    "min_value": 22.1,
    "max_value": 29.4,
    "safe_percentage": 87.5
  },
  "hourly_trends": [ /* 24 data points with hour labels */ ],
  "section_comparison": {
    "upstream": { "avg": 25.2, "readings": 16 },
    "midstream": { "avg": 25.9, "readings": 16 },
    "downstream": { "avg": 26.3, "readings": 16 }
  },
  "alert_summary": { "total_alerts": 3, "critical": 1, "warning": 2 }
}
```

#### **Endpoint 6: Device CRUD**
```
POST /apps/admin/devices.php
Content-Type: application/x-www-form-urlencoded

action=add&device_name=WQ-Test-Station&status=active&location_id=3

RESPONSE: Redirect to devices.php with success message in session

POST /apps/admin/devices.php
action=edit&device_id=9&device_name=WQ-Test-Station-Renamed&status=maintenance&location_id=

POST /apps/admin/devices.php
action=delete&device_id=9
```

### 4.2 Data Flow Diagram

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   CLIENT     │     │   SERVER     │     │   BUSINESS   │     │   DATABASE   │
│   (Browser)  │     │   (PHP)      │     │   LOGIC      │     │   (MySQL)    │
└──────┬───────┘     └──────┬───────┘     └──────┬───────┘     └──────┬───────┘
       │                    │                    │                    │
       │  [1] Form Submit   │                    │                    │
       │ ──────────────────>│                    │                    │
       │  device_id, sensor │                    │                    │
       │  readings          │                    │                    │
       │                    │  [2] Validate      │                    │
       │                    │  Check device active│                    │
       │                    │  Verify sensor IDs  │                    │
       │                    │                    │                    │
       │                    │  [3] Build INSERT  │                    │
       │                    │  prepared statement │                    │
       │                    │────────────────────>│                    │
       │                    │                    │  [4] Execute Query │
       │                    │                    │────────────────────>│
       │                    │                    │  INSERT INTO        │
       │                    │                    │  sensor_readings    │
       │                    │                    │                    │
       │                    │                    │  [5] Threshold Check│
       │                    │                    │  value < min?       │
       │                    │                    │  value > max?       │
       │                    │                    │                    │
       │                    │                    │  [6] IF out of range│
       │                    │                    │  INSERT INTO alerts │
       │                    │                    │  (active status)    │
       │                    │                    │                    │
       │                    │  [7] Return Result │                    │
       │                    │<───────────────────│                    │
       │                    │  reading_id,       │                    │
       │                    │  alerts_created[]  │                    │
       │                    │                    │                    │
       │  [8] JSON Response │                    │                    │
       │<────────────────────│                    │                    │
       │  { success,        │                    │                    │
       │    readings,       │                    │                    │
       │    alerts }        │                    │                    │
       │                    │                    │                    │
       │  [9] Update UI     │                    │                    │
       │  Refresh charts    │                    │                    │
       │  Show new alert    │                    │                    │
       │  badges            │                    │                    │
       │                    │                    │                    │
```

### 4.3 Client-Server Interaction Sequence

**Scenario: Operator submits sensor reading from field device**

```
┌─────────┐          ┌─────────────┐          ┌──────────────┐          ┌──────────┐
│ Operator│          │   Browser   │          │  PHP Server  │          │  MySQL   │
└────┬────┘          └──────┬──────┘          └──────┬───────┘          └────┬─────┘
     │                      │                      │                      │
     │  [1] Log in          │                      │                      │
     │ ─────────────────────>│                      │                      │
     │                      │  POST login.php      │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │  [2] Verify credentials
     │                      │                      │  SELECT * FROM users │
     │                      │                      │  WHERE username=?    │
     │                      │                      │ ──────────────────────>│
     │                      │                      │                      │
     │                      │                      │  [3] Password match  │
     │                      │                      │<──────────────────────│
     │                      │                      │                      │
     │                      │  [4] Set session     │                      │
     │                      │  $_SESSION['user_id']│                      │
     │                      │<─────────────────────│                      │
     │                      │                      │                      │
     │                      │  Redirect to         │                      │
     │                      │  dashboard           │                      │
     │<─────────────────────│                      │                      │
     │                      │                      │                      │
     │  [5] View dashboard  │                      │                      │
     │  (AJAX fetch)        │                      │                      │
     │                      │  GET dashboard.php?  │                      │
     │                      │  action=fetch        │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │  [6] Aggregate data  │
     │                      │                      │  JOIN 4 tables       │
     │                      │                      │  GROUP BY hour       │
     │                      │                      │                      │
     │                      │                      │  [7] Return JSON     │
     │                      │  { devices, alerts,  │                      │
     │                      │    charts, logs }    │                      │
     │                      │<─────────────────────│                      │
     │                      │                      │                      │
     │  [8] Render UI       │                      │                      │
     │  Charts populated    │                      │                      │
     │  Map markers placed  │                      │                      │
     │                      │                      │                      │
     │  [9] Simulate reading│                      │                      │
     │  Enter sensor values │                      │                      │
     │  Click "Send"        │                      │                      │
     │                      │  POST dashboard.php? │                      │
     │                      │  action=simulate     │                      │
     │                      │ ──────────────────────>│                      │
     │                      │                      │  [10] Validate device│
     │                      │                      │  Prepare INSERT      │
     │                      │                      │  for each sensor     │
     │                      │                      │                      │
     │                      │                      │  [11] Check thresholds│
     │                      │                      │  IF value > max THEN │
     │                      │                      │    INSERT alert      │
     │                      │                      │                      │
     │                      │                      │  [12] Log activity   │
     │                      │                      │  INSERT system_logs  │
     │                      │                      │                      │
     │                      │  [13] Return sync    │                      │
     │                      │  data + fresh fetch  │                      │
     │                      │<─────────────────────│                      │
     │                      │                      │                      │
     │  [14] UI updates     │                      │                      │
     │  New reading shown   │                      │                      │
     │  Alert badge appears │                      │                      │
     │  if threshold breached│                     │                      │
     │                      │                      │                      │
```

### 4.4 Integration Scenario — Step-by-Step

**Scenario: New water quality alert triggered and acknowledged**

| Step | Actor | Action | System Component | Data Flow |
|------|-------|--------|------------------|-----------|
| 1 | Sensor Device | Transmits pH reading of 9.2 (above max 8.5) | IoT Gateway → Server | `POST dashboard.php?action=simulate` |
| 2 | PHP Controller | Validates device, inserts reading | Business Logic | `INSERT INTO sensor_readings (sensor_id=7, value=9.2)` |
| 3 | Threshold Engine | Compares value against max_threshold | Alert Generator | `9.2 > 8.5` → breach detected |
| 4 | Alert System | Creates active alert with contextual message | Database | `INSERT INTO alerts (sensor_id, reading_id, alert_type='high', message, status='active')` |
| 5 | Dashboard UI | Auto-refetches data via AJAX | Client JavaScript | `GET dashboard.php?action=fetch` every 30s |
| 6 | Admin | Sees new alert in "Active Alerts" panel | Browser | Alert rendered with red badge, location context |
| 7 | Admin | Clicks "Acknowledge All" button | Event Handler | `POST dashboard.php?action=acknowledge_all` |
| 8 | PHP Controller | Updates alert statuses | Database | `UPDATE alerts SET status='resolved', resolved_by=1, resolved_at=NOW()` |
| 9 | Audit Logger | Records acknowledgment action | System Logs | `INSERT INTO system_logs (action='ALERTS_ACKNOWLEDGE', details, ip_address)` |
| 10 | UI Refresh | Removes alert from active list, shows confirmation | Browser | Toast notification: "3 alerts resolved" |

---

## 5. WIREFRAMES (UI DESIGN)

### 5.1 Page 1: Login/Authentication

```
┌─────────────────────────────────────────┐
│           [Aqua-Vision Logo]            │
│                                         │
│         River Health Monitoring         │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │  👤 Username                  │    │
│  │  [________________________]   │    │
│  │                               │    │
│  │  🔒 Password                  │    │
│  │  [________________________]   │    │
│  │                               │    │
│  │  [      🔐 Log In           ] │    │
│  └─────────────────────────────────┘    │
│                                         │
│        Mangima River, Bukidnon          │
└─────────────────────────────────────────┘
```

### 5.2 Page 2: Admin Dashboard (Main)

```
┌────────┬────────────────────────────────────────────────────────────┐
│        │ [✅ System Normal]  [📡 7 Active]  [⚠️ 3 Alerts]  [🔧 1 Maint]│
│  AQUA  ├────────────────────────────────────────────────────────────┤
│ VISION │                                                            │
│        │  ┌────────────────────┐    ┌──────────────────────────┐   │
│  🏠    │  │  📍 MAP            │    │  📊 24-Hour Trends       │   │
│ Dashboard│ │  [Leaflet Map with │    │  [Chart.js Multi-line]   │   │
│        │  │   device markers]  │    │  Temperature —— pH ——    │   │
│  📡    │  │                    │    │  Turbidity —— DO ——      │   │
│ Devices│  │  ● Upstream        │    │  Water Level —— Sediments│   │
│        │  │  ● Midstream       │    │                          │   │
│  📍    │  │  ● Downstream      │    │  [Show All] [Legend]     │   │
│Locations│ └────────────────────┘    └──────────────────────────┘   │
│        │                                                            │
│  👥    │  ┌────────────────────────┐  ┌────────────────────────┐   │
│ Users  │  │ ⚠️ Active Alerts       │  │ 📋 Sensor Logs         │   │
│        │  │ ┌────────────────────┐ │  │ ┌────────────────────┐ │   │
│  ⚙️    │  │ │ 🔴 Critical: pH    │ │  │ │ WQ-Upstream-Start  │ │   │
│Settings│  │ │    too high @      │ │  │ │ Temperature: 26.5°C│ │   │
│        │  │ │    Midstream End   │ │  │ │ [Safe: 20-35]      │ │   │
│  📊    │  │ │ Acknowledge [✓]    │ │  │ │ 2 minutes ago      │ │   │
│Reports │  │ └────────────────────┘ │  │ └────────────────────┘ │   │
│        │  │ [Acknowledge All]      │  │ [View Full History]    │   │
└────────┘  └────────────────────────┘  └────────────────────────┘   │
            │  🔧 Maintenance Logs   │  │ 📡 Device Selector     │   │
            │  Last: WQ-... (calib)│  │ [Dropdown ▼] Device    │   │
            │  By: Alex Johnson    │  │ [Sensor Card Grid]     │   │
            └────────────────────────┘  └────────────────────────┘   │
└────────────────────────────────────────────────────────────────────┘
```

### 5.3 Page 3: Device Management (CRUD)

```
┌─────────────────────────────────────────────────────────────────────┐
│  📡 Device Management                                    [+ Add] [←]│
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────┐  ┌───────────────────────────────┐ │
│  │      📍 DEVICE MAP          │  │      DEVICE DETAILS           │ │
│  │                             │  │                               │ │
│  │   [Leaflet.js Map]          │  │  [▼ Select Device...    ]    │ │
│  │                             │  │                               │ │
│  │   ● WQ-Upstream (green)     │  │  Status:     🟢 Active       │ │
│  │   ● WQ-Midstream (green)    │  │  Name:       WQ-Upstream-Start │ │
│  │   ● WQ-Downstream (orange)  │  │  Location:   Upstream Start    │ │
│  │   ● Unassigned (gray)       │  │              — Upstream       │ │
│  │                             │  │  Coordinates: 8.34596°N,       │ │
│  │   Legend: 🟢 Active 🟠 Maint  │  │              124.89861°E      │ │
│  │           🔴 Offline ⚪ Unassign│  │  Last Active: Mar 22, 2025    │ │
│  │                             │  │                               │ │
│  └─────────────────────────────┘  │  📊 Latest Readings:          │ │
│                                     │  ┌────┐┌────┐┌────┐┌────┐  │ │
│  ┌────────────────────────────────┐ │  │🌡️  ││🧪  ││🌫️  ││💧  │  │ │
│  │     ALL DEVICES                │ │  │26.5││7.2 ││15.3││8.5 │  │ │
│  │  ┌──────────────────────────┐  │ │  │°C  ││pH  ││NTU ││mg/L│  │ │
│  │  │ Name    │Status│Location│Edit│Delete│ │  └────┘└────┘└────┘└────┘  │ │
│  │  ├─────────┼──────┼────────┼────┼──────┤ │                               │ │
│  │  │WQ-Up... │Active│Upstr...│✏️  │ 🗑️  │ │  [Edit Device] [Delete]      │ │
│  │  │WQ-Mid...│Maint │Midst...│✏️  │ 🗑️  │ │                               │ │
│  │  │WQ-Dow...│Active│Down... │✏️  │ 🗑️  │ │                               │ │
│  │  └──────────────────────────┘  │ └───────────────────────────────┘ │
│  └────────────────────────────────┘                                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 5.4 Page 4: Location Management (Form + Map)

```
┌─────────────────────────────────────────────────────────────────────┐
│  📍 Location Management                                [+ Add] [←]  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  [ADD / EDIT LOCATION]                                              │
│                                                                     │
│  ┌─────────────────────────────┐  ┌───────────────────────────────┐ │
│  │      INTERACTIVE MAP        │  │      LOCATION FORM            │ │
│  │                             │  │                               │ │
│  │   [Click to Pin Location]   │  │  Location Name:               │ │
│  │                             │  │  [Upstream Station 3    ]     │ │
│  │   Lat: 8.36890°N            │  │                               │ │
│  │   Lng: 124.86300°E          │  │  River Section:               │ │
│  │                             │  │  [▼ upstream            ]     │ │
│  │   (Manolo Fortich default)  │  │                               │ │
│  │                             │  │  Coordinates:                 │ │
│  │                             │  │  Latitude:  [8.36890    ]     │ │
│  │                             │  │  Longitude: [124.86300  ]     │ │
│  │                             │  │                               │ │
│  └─────────────────────────────┘  │  📡 Device Assignment:        │ │
│                                     │                               │ │
│                                     │  ☑️ WQ-Midstream-Start       │ │
│                                     │  ☐ WQ-Midstream-End          │ │
│                                     │  ☐ Weather-Central            │ │
│                                     │                               │ │
│                                     │  [💾 Save Location]           │ │
│                                     │                               │ │
│                                     └───────────────────────────────┘ │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────────┐  │
│  │                    ALL LOCATIONS                                │  │
│  │  Name          │ Section    │ Devices │ Coordinates    │ Actions│  │
│  │  Upstream Start│ Upstream   │ 2       │ 8.34596, 124.89│ Edit ✏️│  │
│  │  Midstream End │ Midstream  │ 3       │ 8.39487, 124.90│ Edit ✏️│  │
│  │  Downstream Sta│ Downstream │ 1       │ 8.41318, 124.91│ Edit ✏️│  │
│  └─────────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 6. FINAL PRESENTATION STRUCTURE

### 6.1 System Walkthrough (15 minutes)

| Segment | Duration | Content |
|---------|----------|---------|
| **Introduction** | 1 min | System purpose, Mangima River context, problem statement |
| **Architecture** | 2 min | Show 3-tier diagram, explain client-server separation |
| **Database Design** | 2 min | Show ER diagram, explain table relationships and FK constraints |
| **Live Demo: Authentication** | 1 min | Log in as different roles (admin/operator/researcher), show RBAC |
| **Live Demo: Dashboard** | 3 min | Show real-time map, sensor readings, alert panel, chart interactions |
| **Live Demo: Data Simulation** | 2 min | Enter sensor values, submit, show alert auto-generation |
| **Live Demo: CRUD Operations** | 2 min | Add device, assign location, edit status, delete with confirmation |
| **Live Demo: Export/Analysis** | 1 min | Export CSV, show statistical analysis for researchers |
| **Q&A Preparation** | 1 min | Summarize key technical decisions |

### 6.2 Technical Defense — Anticipated Q&A

**Q: How does the system handle concurrent sensor data submissions?**
> A: MySQL's ACID compliance ensures atomic INSERT operations. Each reading is independent; the threshold check and alert insertion occur within the same request lifecycle. For high-throughput scenarios, we could implement batch INSERTs and Redis queuing.

**Q: What happens if a sensor goes offline?**
> A: The `devices.last_active` timestamp tracks the most recent reading. If no data is received within a configurable threshold (e.g., 1 hour), the dashboard shows the device as potentially offline. Operators can mark devices as "maintenance" or "inactive" status.

**Q: How are alerts differentiated by severity?**
> A: Alerts use a severity calculation: `value < min * 0.8` or `value > max * 1.3` triggers "critical"; otherwise "warning" (high/low). The UI renders critical alerts with red badges and warning with orange.

**Q: Explain the data flow from sensor to dashboard.**
> A: Sensor → IoT Gateway → `POST dashboard.php?action=simulate` → Validate → `INSERT sensor_readings` → Threshold Check → `INSERT alerts` (if breached) → `UPDATE devices.last_active` → Client AJAX fetches fresh data → Chart.js/Leaflet.js render updates.

**Q: How is role-based access implemented?**
> A: PHP `$_SESSION['role']` stores the user's role enum. Each dashboard page (`admin/`, `operator/`, `researcher/`) includes role-appropriate UI elements. The database `users.role` field enforces this at the data level.

---

## APPENDIX: Technology Stack

| Layer | Technology | Version | Purpose |
|-------|-----------|---------|---------|
| Server | Apache HTTP Server | 2.4+ | Web server, PHP handler |
| Server | PHP | 8.x | Server-side logic, API endpoints |
| Database | MySQL/MariaDB | 10.4+ | Relational data persistence |
| Client | HTML5/CSS3 | — | Semantic markup, responsive styling |
| Client | JavaScript (ES6) | — | DOM manipulation, AJAX requests |
| Client | Chart.js | 4.x | Interactive time-series visualization |
| Client | Leaflet.js | 1.9+ | Interactive map with OpenStreetMap tiles |
| Client | Google Fonts | — | Typography (DM Sans, Space Grotesk) |
| API | JSON/CSV | — | Data exchange formats |
| Security | MySQLi Prepared Statements | — | SQL injection prevention |
| Security | Password Hashing | — | User credential protection |

---

*Document generated for GRASPS Performance Assessment*
*Course: Client-Server Architecture & Web Development*
*Institution: Northern Bukidnon State College*
*System: Aqua-Vision — Mangima River Water Quality Monitoring*
