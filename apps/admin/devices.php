<?php
/**
 * Device & Location Management
 * Unified interface for managing monitoring equipment and stations
 */

require_once __DIR__ . '/../../database/config.php';

// Initialize session and check login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Handle actions
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle API requests
if ($action === 'get_readings') {
    $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
    
    if ($deviceId > 0) {
        // Fetch latest sensor readings for this device
        $readings = getLatestDeviceReadings($conn, $deviceId);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'readings' => $readings
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid device ID'
        ]);
    }
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleFormSubmission($conn, $_POST);
}

// Get data for current view
$device = ($action === 'edit' && $id) ? getDeviceById($conn, $id) : null;
$devices = ($action === 'list') ? getAllDevices($conn) : [];
$locations = ($action === 'list') ? getAllLocations($conn) : []; // For location assignment dropdown

$currentPage = 'devices';
include __DIR__ . '/../../assets/navigation.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device & Location Management - Aqua-Vision</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #1a56db;
            --primary-dark: #0e3a8a;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-700);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: var(--radius);
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: white;
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
        }
        
        .btn-secondary:hover {
            background: var(--gray-50);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
        }
        
        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .data-table th {
            background: var(--gray-50);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray-500);
        }
        
        .data-table tr:hover {
            background: var(--gray-50);
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 9999px;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.875rem;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #86efac;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .modal-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        
        /* Map */
        #location-map {
            height: 400px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        /* Device List */
        .device-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .device-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .device-item:last-child {
            border-bottom: none;
        }
        
        .device-info {
            flex: 1;
        }
        
        .device-name {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .device-location {
            font-size: 0.75rem;
            color: var(--gray-500);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📡 Device Management</h1>
            <div class="header-actions" style="display: flex; gap: 0.5rem;">
                <a href="?action=add" class="btn btn-primary">+ Add Device</a>
            </div>
        </div>
        
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($action === 'add' || $action === 'edit'): ?>
            <!-- Device Form -->
            <div class="card">
                <div class="card-header">
                    <h3><?= $action === 'add' ? 'Add New Device' : 'Edit Device' ?></h3>
                </div>
                <div class="card-body" style="padding: 1.25rem;">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?= $action ?>">
                        <?php if ($action === 'edit'): ?>
                            <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label>Device Name *</label>
                            <input type="text" name="device_name" value="<?= $device ? htmlspecialchars($device['device_name']) : '' ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?= $device && $device['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="maintenance" <?= $device && $device['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="inactive" <?= $device && $device['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Location</label>
                            <select name="location_id">
                                <option value="">Unassigned</option>
                                <?php
                                $locationsResult = $conn->query("SELECT location_id, location_name FROM locations ORDER BY location_name");
                                while ($loc = $locationsResult->fetch_assoc()):
                                ?>
                                    <option value="<?= $loc['location_id'] ?>" <?= $device && $device['location_id'] == $loc['location_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc['location_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">Save Device</button>
                            <a href="?action=list" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            
        <?php elseif ($action === 'delete'): ?>
            <!-- Delete Confirmation -->
            <div class="modal" onclick="if(event.target === this) window.location.href='?action=list'">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Confirm Delete</h3>
                        <a href="?action=list" style="text-decoration: none; font-size: 1.5rem;">&times;</a>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this device?</p>
                        <p><strong><?= $device ? htmlspecialchars($device['device_name']) : '' ?></strong></p>
                        <p style="color: var(--danger); font-size: 0.875rem;">This action cannot be undone.</p>
                    </div>
                    <div class="modal-actions" style="padding: 1rem; border-top: 1px solid var(--gray-200);">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="device_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                            <a href="?action=list" class="btn btn-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Device Management with Map and Details -->
            <div style="display: grid; grid-template-columns: 1fr 400px; gap: 1.5rem;">
                <!-- Device Map Overview -->
                <div class="card">
                    <div class="card-header">
                        <h3>📍 Device Locations Overview</h3>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span class="badge badge-info"><?= count($devices) ?> devices</span>
                            <button onclick="toggleDeviceMap()" class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.75rem;">
                                <span id="deviceMapToggleText">Hide Map</span>
                            </button>
                        </div>
                    </div>
                    <div id="deviceMapContainer" style="padding: 1.25rem;">
                        <div id="devices-overview-map" style="height: 500px; border-radius: var(--radius); border: 1px solid var(--gray-200);"></div>
                        <div style="display: flex; gap: 1rem; margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-500);">
                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #059669;"></span>
                                Active
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #3b82f6;"></span>
                                Maintenance
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #dc2626;"></span>
                                Inactive
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.25rem;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: #9ca3af;"></span>
                                Unassigned
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Device Details Panel -->
                <div class="card" style="margin: 0;">
                    <div class="card-header">
                        <h3 style="font-size: 1rem;">📋 Device Details</h3>
                    </div>
                    <div id="deviceDetailsPanel" style="padding: 1rem;">
                        <!-- Device Statistics -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                            <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--primary);"><?= count($devices) ?></div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">Total Devices</div>
                            </div>
                            <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                <?php 
                                $activeCount = count(array_filter($devices, fn($d) => $d['status'] === 'active'));
                                ?>
                                <div style="font-size: 1.5rem; font-weight: 600; color: var(--success);"><?= $activeCount ?></div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">Active</div>
                            </div>
                        </div>
                        
                        <!-- Device Selection -->
                        <div style="margin-bottom: 1rem;">
                            <div style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.75rem;">Select Device</div>
                            <select id="deviceSelector" onchange="selectDevice(this.value)" 
                                    style="width: 100%; padding: 0.5rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.875rem;">
                                <?php foreach ($devices as $device): ?>
                                    <option value="<?= $device['device_id'] ?>" 
                                            <?= isset($devices[0]) && $device['device_id'] === $devices[0]['device_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($device['device_name']) ?> 
                                        <?= $device['status'] === 'active' ? '🟢' : ($device['status'] === 'maintenance' ? '🔵' : '🔴') ?>
                                        <?= $device['location_name'] ? '• ' . htmlspecialchars($device['location_name']) : '• Unassigned' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Selected Device Details -->
                        <div id="selectedDeviceDetails">
                            <?php if (!empty($devices)): ?>
                                <?php $firstDevice = $devices[0]; ?>
                                <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius);">
                                    <div style="font-weight: 600; margin-bottom: 0.5rem;"><?= htmlspecialchars($firstDevice['device_name']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                        Status: <span class="badge badge-<?= $firstDevice['status'] === 'active' ? 'success' : ($firstDevice['status'] === 'maintenance' ? 'warning' : 'danger') ?>"><?= ucfirst($firstDevice['status']) ?></span>
                                    </div>
                                    <?php if ($firstDevice['location_name']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                            Location: <?= htmlspecialchars($firstDevice['location_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($firstDevice['river_section']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                            Section: <?= ucfirst($firstDevice['river_section']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($firstDevice['last_active']): ?>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 1rem;">
                                            Last Active: <?= date('M d, H:i', strtotime($firstDevice['last_active'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Last Sensor Readings -->
                                    <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                        <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Last Sensor Readings</div>
                                        <div id="deviceReadings-<?= $firstDevice['device_id'] ?>" style="font-size: 0.75rem; color: var(--gray-500);">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌡️ Temp:</span>
                                                    <span style="font-weight: 500;">--°C</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🧪 pH:</span>
                                                    <span style="font-weight: 500;">--</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌫️ Turb:</span>
                                                    <span style="font-weight: 500;">-- NTU</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>💧 DO:</span>
                                                    <span style="font-weight: 500;">-- mg/L</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>🌊 Level:</span>
                                                    <span style="font-weight: 500;">-- m</span>
                                                </div>
                                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                                    <span>� Sed:</span>
                                                    <span style="font-weight: 500;">-- mg/L</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                                    No devices available. Click "Add Device" to create one.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Devices Table -->
            <div class="card">
                <div class="card-header">
                    <h3>📡 All Monitoring Devices</h3>
                    <span class="badge badge-info"><?= count($devices) ?> total</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Device Name</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>River Section</th>
                                <th>Last Active</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 2rem;">
                                        No devices found. Click "Add Device" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $device): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($device['device_name']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $device['status'] === 'active' ? 'success' : ($device['status'] === 'maintenance' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($device['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= $device['location_name'] ? htmlspecialchars($device['location_name']) : '—' ?></td>
                                        <td><?= $device['river_section'] ? ucfirst($device['river_section']) : '—' ?></td>
                                        <td><?= $device['last_active'] ? date('M d, H:i', strtotime($device['last_active'])) : 'Never' ?></td>
                                        <td style="display: flex; gap: 0.5rem;">
                                            <a href="?action=edit&id=<?= $device['device_id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.75rem;">Edit</a>
                                            <a href="?action=delete&id=<?= $device['device_id'] ?>" class="btn btn-danger" style="padding: 0.25rem 0.75rem;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
                // Device map and details functionality
                let deviceOverviewMap = null;
                let deviceMapVisible = true;
                let selectedDeviceId = <?= !empty($devices) ? $devices[0]['device_id'] : 'null' ?>;
                const devicesData = <?= json_encode($devices, JSON_NUMERIC_CHECK) ?>;
                
                function initializeDeviceMap() {
                    const devices = devicesData;
                    
                    if (devices.length === 0) {
                        document.getElementById('deviceMapContainer').style.display = 'none';
                        return;
                    }
                    
                    // Filter devices with locations
                    const devicesWithLocations = devices.filter(d => d.latitude && d.longitude);
                    
                    if (devicesWithLocations.length === 0) {
                        document.getElementById('deviceMapContainer').innerHTML = 
                            '<div style="text-align: center; padding: 2rem; color: var(--gray-500);">No devices with assigned locations found.</div>';
                        return;
                    }
                    
                    // Calculate center point
                    const avgLat = devicesWithLocations.reduce((sum, d) => sum + d.latitude, 0) / devicesWithLocations.length;
                    const avgLng = devicesWithLocations.reduce((sum, d) => sum + d.longitude, 0) / devicesWithLocations.length;
                    
                    deviceOverviewMap = L.map('devices-overview-map').setView([avgLat, avgLng], 12);
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                        attribution: '© OpenStreetMap contributors',
                        subdomains: 'abcd'
                    }).addTo(deviceOverviewMap);
                    
                    // Add markers for each device
                    devicesWithLocations.forEach(device => {
                        const color = getDeviceStatusColor(device.status);
                        
                        const marker = L.circleMarker([device.latitude, device.longitude], {
                            radius: 10,
                            fillColor: color,
                            color: '#fff',
                            weight: 2,
                            fillOpacity: 0.9
                        }).addTo(deviceOverviewMap);
                        
                        // Highlight selected device
                        if (device.device_id === selectedDeviceId) {
                            marker.setStyle({
                                radius: 14,
                                weight: 3,
                                fillOpacity: 1.0
                            });
                        }
                        
                        // Popup content
                        const popupContent = `
                            <div style="font-family: 'Inter', sans-serif; min-width: 200px;">
                                <div style="font-weight: 600; margin-bottom: 8px;">${device.device_name}</div>
                                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                    Status: <span style="color: ${color}; font-weight: 500;">${device.status}</span>
                                </div>
                                ${device.location_name ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                        Location: ${device.location_name}
                                    </div>
                                ` : ''}
                                ${device.river_section ? `
                                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">
                                        ${device.river_section.charAt(0).toUpperCase() + device.river_section.slice(1)} Section
                                    </div>
                                ` : ''}
                                <div style="font-size: 11px; font-family: monospace; color: #9ca3af; margin-bottom: 12px;">
                                    ${device.latitude.toFixed(5)}°N, ${device.longitude.toFixed(5)}°E
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <a href="?action=edit&id=${device.device_id}" 
                                       style="flex: 1; text-align: center; padding: 4px 8px; background: #f3f4f6; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                        Edit
                                    </a>
                                    <a href="?action=delete&id=${device.device_id}" 
                                       style="flex: 1; text-align: center; padding: 4px 8px; background: #fee2e2; color: #dc2626; border-radius: 4px; text-decoration: none; font-size: 11px;">
                                        Delete
                                    </a>
                                </div>
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                        
                        // Click handler for marker
                        marker.on('click', function() {
                            selectDevice(device.device_id);
                        });
                    });
                    
                    // Fit map to show all markers
                    if (devicesWithLocations.length > 0) {
                        const bounds = L.latLngBounds(devicesWithLocations.map(d => [d.latitude, d.longitude]));
                        deviceOverviewMap.fitBounds(bounds.pad(0.1));
                    }
                }
                
                function getDeviceStatusColor(status) {
                    const colors = {
                        'active': '#059669',
                        'maintenance': '#3b82f6', 
                        'inactive': '#dc2626'
                    };
                    return colors[status] || '#9ca3af';
                }
                
                function selectDevice(deviceId) {
                    selectedDeviceId = deviceId;
                    
                    // Update dropdown selector
                    const dropdown = document.getElementById('deviceSelector');
                    if (dropdown) {
                        dropdown.value = deviceId;
                    }
                    
                    // Update details panel
                    const device = devicesData.find(d => d.device_id === deviceId);
                    if (device) {
                        updateDeviceDetails(device);
                    }
                    
                    // Update map marker highlighting
                    if (deviceOverviewMap) {
                        deviceOverviewMap.eachLayer(layer => {
                            if (layer instanceof L.CircleMarker) {
                                layer.setStyle({
                                    radius: 10,
                                    weight: 2,
                                    fillOpacity: 0.9
                                });
                            }
                        });
                        
                        // Highlight selected marker
                        const devicesWithLocations = devicesData.filter(d => d.latitude && d.longitude);
                        const selectedDevice = devicesWithLocations.find(d => d.device_id === deviceId);
                        if (selectedDevice) {
                            deviceOverviewMap.eachLayer(layer => {
                                if (layer instanceof L.CircleMarker) {
                                    const latlng = layer.getLatLng();
                                    if (Math.abs(latlng.lat - selectedDevice.latitude) < 0.0001 && 
                                        Math.abs(latlng.lng - selectedDevice.longitude) < 0.0001) {
                                        layer.setStyle({
                                            radius: 14,
                                            weight: 3,
                                            fillOpacity: 1.0
                                        });
                                    }
                                }
                            });
                        }
                    }
                }
                
                function updateDeviceDetails(device) {
                    const detailsHtml = `
                        <div style="background: var(--gray-50); padding: 1rem; border-radius: var(--radius);">
                            <div style="font-weight: 600; margin-bottom: 0.5rem;">${device.device_name}</div>
                            <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                Status: <span class="badge badge-${device.status === 'active' ? 'success' : (device.status === 'maintenance' ? 'warning' : 'danger')}">${device.status.charAt(0).toUpperCase() + device.status.slice(1)}</span>
                            </div>
                            ${device.location_name ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Location: ${device.location_name}
                                </div>
                            ` : ''}
                            ${device.river_section ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">
                                    Section: ${device.river_section.charAt(0).toUpperCase() + device.river_section.slice(1)}
                                </div>
                            ` : ''}
                            ${device.last_active ? `
                                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 1rem;">
                                    Last Active: ${new Date(device.last_active).toLocaleDateString('en-PH', {month: 'short', day: 'numeric'})}, ${new Date(device.last_active).toLocaleTimeString('en-PH', {hour: '2-digit', minute: '2-digit', hour12: false})}
                                </div>
                            ` : ''}
                            
                            <!-- Last Sensor Readings -->
                            <div style="border-top: 1px solid var(--gray-200); padding-top: 0.75rem; margin-top: 0.75rem;">
                                <div style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-600);">Last Sensor Readings</div>
                                <div id="deviceReadings-${device.device_id}" style="font-size: 0.75rem; color: var(--gray-500);">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌡️ Temp:</span>
                                            <span style="font-weight: 500;">--°C</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🧪 pH:</span>
                                            <span style="font-weight: 500;">--</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌫️ Turb:</span>
                                            <span style="font-weight: 500;">-- NTU</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>💧 DO:</span>
                                            <span style="font-weight: 500;">-- mg/L</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>🌊 Level:</span>
                                            <span style="font-weight: 500;">-- m</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                            <span>� Sed:</span>
                                            <span style="font-weight: 500;">-- mg/L</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('selectedDeviceDetails').innerHTML = detailsHtml;
                    
                    // Fetch and display latest sensor readings for this device
                    fetchDeviceReadings(device.device_id);
                }
                
                function fetchDeviceReadings(deviceId) {
                    // Fetch the latest readings for this device directly from database
                    fetch(`devices.php?action=get_readings&device_id=${deviceId}&_=${Date.now()}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.readings) {
                                updateDeviceReadingsDisplay(deviceId, data.readings);
                            }
                        })
                        .catch(error => {
                            console.log('Could not fetch device readings:', error);
                        });
                }
                
                function updateDeviceReadingsDisplay(deviceId, readings) {
                    const readingsElement = document.getElementById(`deviceReadings-${deviceId}`);
                    if (!readingsElement) return;
                    
                    const sensors = [
                        { key: 'temperature', icon: '🌡️', unit: '°C', format: v => v?.toFixed(1) },
                        { key: 'ph_level', icon: '🧪', unit: '', format: v => v?.toFixed(2) },
                        { key: 'turbidity', icon: '🌫️', unit: 'NTU', format: v => v?.toFixed(1) },
                        { key: 'dissolved_oxygen', icon: '💧', unit: 'mg/L', format: v => v?.toFixed(1) },
                        { key: 'water_level', icon: '🌊', unit: 'm', format: v => v?.toFixed(2) },
                        { key: 'sediments', icon: '🟤', unit: 'mg/L', format: v => v?.toFixed(0) }
                    ];
                    
                    const readingsHtml = sensors.map(sensor => {
                        const value = readings[sensor.key];
                        const displayValue = value !== null && value !== undefined ? 
                            sensor.format(value) + sensor.unit : '--' + sensor.unit;
                        
                        return `
                            <div style="display: flex; justify-content: space-between; padding: 0.25rem 0;">
                                <span>${sensor.icon} ${sensor.key.replace('_', ' ').charAt(0).toUpperCase() + sensor.key.slice(1).replace('_', ' ')}:</span>
                                <span style="font-weight: 500;">${displayValue}</span>
                            </div>
                        `;
                    }).join('');
                    
                    readingsElement.innerHTML = `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            ${readingsHtml}
                        </div>
                    `;
                }
                
                function toggleDeviceMap() {
                    const container = document.getElementById('deviceMapContainer');
                    const toggleText = document.getElementById('deviceMapToggleText');
                    
                    if (deviceMapVisible) {
                        container.style.display = 'none';
                        toggleText.textContent = 'Show Map';
                        deviceMapVisible = false;
                    } else {
                        container.style.display = 'block';
                        toggleText.textContent = 'Hide Map';
                        deviceMapVisible = true;
                        
                        // Reinitialize map if needed
                        if (!deviceOverviewMap) {
                            setTimeout(initializeDeviceMap, 100);
                        }
                    }
                }
                
                // Initialize map when page loads
                document.addEventListener('DOMContentLoaded', function() {
                    initializeDeviceMap();
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Handle form submissions
 */
function handleFormSubmission($conn, $data) {
    $action = $data['action'] ?? '';
    
    try {
        if ($action === 'add' || $action === 'edit') {
            handleDeviceSubmission($conn, $data);
        } elseif ($action === 'delete') {
            handleDeviceDelete($conn, $data);
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/**
 * Handle device add/edit
 */
function handleDeviceSubmission($conn, $data) {
    $deviceName = trim($data['device_name'] ?? '');
    $status = $data['status'] ?? 'inactive';
    $locationId = !empty($data['location_id']) ? (int)$data['location_id'] : null;
    
    if (empty($deviceName)) {
        throw new Exception('Device name is required');
    }
    
    if (isset($data['device_id']) && $data['device_id'] > 0) {
        // Update existing device
        $stmt = $conn->prepare("UPDATE devices SET device_name = ?, status = ?, location_id = ? WHERE device_id = ?");
        $stmt->bind_param("ssii", $deviceName, $status, $locationId, $data['device_id']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Device updated successfully';
        } else {
            throw new Exception('Failed to update device: ' . $conn->error);
        }
    } else {
        // Add new device
        $stmt = $conn->prepare("INSERT INTO devices (device_name, status, location_id, last_active) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssi", $deviceName, $status, $locationId);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Device added successfully';
        } else {
            throw new Exception('Failed to add device: ' . $conn->error);
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Handle device deletion
 */
function handleDeviceDelete($conn, $data) {
    $deviceId = (int)$data['device_id'];
    
    if ($deviceId <= 0) {
        throw new Exception('Invalid device ID');
    }
    
    // Check if device has readings
    $result = $conn->query("SELECT COUNT(*) as count FROM sensor_readings sr 
                            JOIN sensors s ON s.sensor_id = sr.sensor_id 
                            WHERE s.device_id = $deviceId");
    $readingsCount = $result->fetch_assoc()['count'];
    
    if ($readingsCount > 0) {
        throw new Exception("Cannot delete device: It has $readingsCount sensor readings. Delete readings first.");
    }
    
    $stmt = $conn->prepare("DELETE FROM devices WHERE device_id = ?");
    $stmt->bind_param("i", $deviceId);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Device deleted successfully';
    } else {
        throw new Exception('Failed to delete device: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Get all devices with location info and coordinates
 */
function getAllDevices($conn) {
    $sql = "SELECT d.*, l.location_name, l.river_section, l.latitude, l.longitude 
            FROM devices d 
            LEFT JOIN locations l ON l.location_id = d.location_id 
            ORDER BY d.device_name";
    
    $result = $conn->query($sql);
    $devices = [];
    
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
    
    return $devices;
}

/**
 * Get all locations for dropdown
 */
function getAllLocations($conn) {
    $sql = "SELECT location_id, location_name, river_section 
            FROM locations 
            ORDER BY location_name";
    
    $result = $conn->query($sql);
    $locations = [];
    
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    
    return $locations;
}

/**
 * Get device by ID
 */
function getDeviceById($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM devices WHERE device_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get latest sensor readings for a device
 */
function getLatestDeviceReadings($conn, $deviceId) {
    // Get all sensors for this device
    $sensorsQuery = $conn->prepare("SELECT sensor_id, sensor_type FROM sensors WHERE device_id = ?");
    $sensorsQuery->bind_param("i", $deviceId);
    $sensorsQuery->execute();
    $sensorsResult = $sensorsQuery->get_result();
    
    $readings = [];
    $sensorTypes = ['temperature', 'ph_level', 'turbidity', 'dissolved_oxygen', 'water_level', 'sediments'];
    
    // Initialize all sensor types to null
    foreach ($sensorTypes as $type) {
        $readings[$type] = null;
    }
    
    // Fetch latest reading for each sensor
    while ($sensor = $sensorsResult->fetch_assoc()) {
        $sensorId = $sensor['sensor_id'];
        $sensorType = $sensor['sensor_type'];
        
        // Get the latest reading for this sensor
        $readingQuery = $conn->prepare("
            SELECT value, timestamp 
            FROM sensor_readings 
            WHERE sensor_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ");
        $readingQuery->bind_param("i", $sensorId);
        $readingQuery->execute();
        $readingResult = $readingQuery->get_result();
        
        if ($reading = $readingResult->fetch_assoc()) {
            $readings[$sensorType] = floatval($reading['value']);
        }
    }
    
    return $readings;
}
?>