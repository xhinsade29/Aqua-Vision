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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleFormSubmission($conn, $_POST);
}

// Get data for current view
$device = ($action === 'edit' && $id) ? getDeviceById($conn, $id) : null;
$location = ($action === 'edit_location' && $id) ? getLocationById($conn, $id) : null;
$devices = ($action === 'list') ? getAllDevices($conn) : [];
$locations = ($action === 'list') ? getAllLocations($conn) : [];
$unassignedDevices = ($action === 'add_location' || $action === 'edit_location') ? getUnassignedDevices($conn) : [];

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
            <h1>📡 Device & Location Management</h1>
            <div class="header-actions" style="display: flex; gap: 0.5rem;">
                <a href="?action=add" class="btn btn-primary">+ Add Device</a>
                <a href="?action=add_location" class="btn btn-primary" style="background: var(--warning);">📍 Add Location</a>
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
            
        <?php elseif ($action === 'add_location' || $action === 'edit_location'): ?>
            <!-- Location Form with Map -->
            <div class="card">
                <div class="card-header">
                    <h3><?= $action === 'add_location' ? 'Add New Location' : 'Edit Location' ?></h3>
                </div>
                <div class="card-body" style="padding: 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 300px; gap: 1.5rem;">
                        <!-- Map -->
                        <div>
                            <div id="location-map"></div>
                            <p style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.5rem;">
                                💡 Click on the map to set coordinates, or drag the marker
                            </p>
                        </div>
                        
                        <!-- Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="<?= $action ?>">
                            <?php if ($action === 'edit_location'): ?>
                                <input type="hidden" name="location_id" value="<?= $location['location_id'] ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label>Location Name *</label>
                                <input type="text" name="location_name" value="<?= $location ? htmlspecialchars($location['location_name']) : '' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>River Section</label>
                                <select name="river_section" required>
                                    <option value="upstream" <?= $location && $location['river_section'] === 'upstream' ? 'selected' : '' ?>>Upstream</option>
                                    <option value="midstream" <?= $location && $location['river_section'] === 'midstream' ? 'selected' : '' ?>>Midstream</option>
                                    <option value="downstream" <?= $location && $location['river_section'] === 'downstream' ? 'selected' : '' ?>>Downstream</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Latitude</label>
                                <input type="number" id="latitude" name="latitude" step="0.000001" 
                                       value="<?= $location ? $location['latitude'] : '8.368900' ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Longitude</label>
                                <input type="number" id="longitude" name="longitude" step="0.000001" 
                                       value="<?= $location ? $location['longitude'] : '124.863000' ?>" required>
                            </div>
                            
                            <!-- Device Assignment -->
                            <?php if (!empty($unassignedDevices) || ($action === 'edit_location' && $location)): ?>
                                <div class="form-group">
                                    <label>Assign Devices</label>
                                    <div style="border: 1px solid var(--gray-200); border-radius: var(--radius); max-height: 200px; overflow-y: auto;">
                                        <?php
                                        $assignedIds = [];
                                        if ($action === 'edit_location' && $location) {
                                            $assignedResult = $conn->query("SELECT device_id FROM devices WHERE location_id = {$location['location_id']}");
                                            while ($row = $assignedResult->fetch_assoc()) {
                                                $assignedIds[] = $row['device_id'];
                                            }
                                        }
                                        
                                        foreach ($unassignedDevices as $device):
                                            $isAssigned = in_array($device['device_id'], $assignedIds);
                                        ?>
                                            <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--gray-200); cursor: pointer;">
                                                <input type="checkbox" name="assign_devices[]" value="<?= $device['device_id'] ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                                <span style="flex: 1;"><?= htmlspecialchars($device['device_name']) ?></span>
                                                <span class="badge badge-<?= $device['status'] === 'active' ? 'success' : ($device['status'] === 'maintenance' ? 'warning' : 'danger') ?>">
                                                    <?= ucfirst($device['status']) ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                                <button type="submit" class="btn btn-primary">Save Location</button>
                                <a href="?action=list" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                        
                        <!-- Readings Panel -->
                        <div class="card" style="margin: 0;">
                            <div class="card-header" style="padding: 0.75rem 1rem;">
                                <h3 style="font-size: 0.875rem; margin: 0;">📊 Live Readings</h3>
                            </div>
                            <div class="card-body" style="padding: 1rem;">
                                <!-- Readings Stats -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                                    <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                        <div style="font-size: 1.5rem; font-weight: 600; color: var(--primary);">0</div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">Readings</div>
                                    </div>
                                    <div style="text-align: center; padding: 0.75rem; background: var(--gray-50); border-radius: var(--radius);">
                                        <div style="font-size: 1.5rem; font-weight: 600; color: var(--danger);">0</div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500);">Alerts</div>
                                    </div>
                                </div>
                                
                                <!-- Last Read -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Last Read</div>
                                    <div style="font-size: 0.875rem; font-weight: 500;">—</div>
                                </div>
                                
                                <!-- Latest Values -->
                                <div style="margin-bottom: 1rem;">
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">Latest Values</div>
                                    <div style="font-size: 0.875rem; color: var(--gray-500);">—</div>
                                </div>
                                
                                <!-- Simulation Log -->
                                <div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.5rem;">Simulation Log</div>
                                    <div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: var(--radius); padding: 0.75rem; font-size: 0.75rem; color: var(--gray-500);">
                                        Monitoring stopped — press ▶ Start to begin.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
                // Initialize map
                const lat = parseFloat(document.getElementById('latitude').value);
                const lng = parseFloat(document.getElementById('longitude').value);
                
                const map = L.map('location-map').setView([lat, lng], 13);
                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                    attribution: '© OpenStreetMap',
                    subdomains: 'abcd'
                }).addTo(map);
                
                // Add draggable marker
                const marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                
                // Update form when marker is dragged
                marker.on('dragend', function(e) {
                    const pos = e.target.getLatLng();
                    document.getElementById('latitude').value = pos.lat.toFixed(6);
                    document.getElementById('longitude').value = pos.lng.toFixed(6);
                });
                
                // Update marker when map is clicked
                map.on('click', function(e) {
                    marker.setLatLng(e.latlng);
                    document.getElementById('latitude').value = e.latlng.lat.toFixed(6);
                    document.getElementById('longitude').value = e.latlng.lng.toFixed(6);
                });
                
                // Update marker when coordinates change manually
                document.getElementById('latitude').addEventListener('change', updateMarker);
                document.getElementById('longitude').addEventListener('change', updateMarker);
                
                function updateMarker() {
                    const newLat = parseFloat(document.getElementById('latitude').value);
                    const newLng = parseFloat(document.getElementById('longitude').value);
                    if (!isNaN(newLat) && !isNaN(newLng)) {
                        marker.setLatLng([newLat, newLng]);
                        map.setView([newLat, newLng]);
                    }
                }
            </script>
            
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
            
        <?php elseif ($action === 'delete_location'): ?>
            <!-- Delete Location Confirmation -->
            <div class="modal" onclick="if(event.target === this) window.location.href='?action=list'">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Confirm Delete Location</h3>
                        <a href="?action=list" style="text-decoration: none; font-size: 1.5rem;">&times;</a>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this location?</p>
                        <p><strong><?= $location ? htmlspecialchars($location['location_name']) : '' ?></strong></p>
                        <?php if (!empty($assignedDevices)): ?>
                            <p style="color: var(--danger); font-size: 0.875rem;">
                                ⚠️ This location has <?= count($assignedDevices) ?> device(s) assigned. 
                                Please reassign or delete them first.
                            </p>
                        <?php else: ?>
                            <p style="color: var(--danger); font-size: 0.875rem;">This action cannot be undone.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-actions" style="padding: 1rem; border-top: 1px solid var(--gray-200);">
                        <?php if (empty($assignedDevices)): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="delete_location">
                                <input type="hidden" name="location_id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-danger">Delete</button>
                                <a href="?action=list" class="btn btn-secondary">Cancel</a>
                            </form>
                        <?php else: ?>
                            <a href="?action=list" class="btn btn-primary">Back to List</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <!-- List View -->
            <div class="card">
                <div class="card-header">
                    <h3>📡 All Devices</h3>
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
            
            <!-- Locations Table -->
            <div class="card">
                <div class="card-header">
                    <h3>📍 Monitoring Locations</h3>
                    <a href="?action=add_location" class="btn btn-primary">+ Add Location</a>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Location Name</th>
                                <th>River Section</th>
                                <th>Coordinates</th>
                                <th>Devices</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($locations)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem;">
                                        No locations found. Click "Add Location" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($locations as $loc): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($loc['location_name']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-<?= $loc['river_section'] === 'upstream' ? 'success' : ($loc['river_section'] === 'midstream' ? 'warning' : 'danger') ?>">
                                                <?= ucfirst($loc['river_section']) ?>
                                            </span>
                                        </td>
                                        <td style="font-family: monospace; font-size: 0.75rem;">
                                            <?= number_format($loc['latitude'], 5) ?>°N, <?= number_format($loc['longitude'], 5) ?>°E
                                        </td>
                                        <td><?= $loc['device_count'] ?> device(s)</td>
                                        <td style="display: flex; gap: 0.5rem;">
                                            <a href="?action=edit_location&id=<?= $loc['location_id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.75rem;">Edit</a>
                                            <a href="?action=delete_location&id=<?= $loc['location_id'] ?>" class="btn btn-danger" style="padding: 0.25rem 0.75rem;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
        } elseif ($action === 'add_location' || $action === 'edit_location') {
            handleLocationSubmission($conn, $data);
        } elseif ($action === 'delete_location') {
            handleLocationDelete($conn, $data);
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
 * Handle location add/edit
 */
function handleLocationSubmission($conn, $data) {
    $locationName = trim($data['location_name'] ?? '');
    $riverSection = $data['river_section'] ?? '';
    $latitude = (float)($data['latitude'] ?? 0);
    $longitude = (float)($data['longitude'] ?? 0);
    $assignDevices = $data['assign_devices'] ?? [];
    
    if (empty($locationName)) {
        throw new Exception('Location name is required');
    }
    
    if (isset($data['location_id']) && $data['location_id'] > 0) {
        // Update existing location
        $locationId = (int)$data['location_id'];
        $stmt = $conn->prepare("UPDATE locations SET location_name = ?, river_section = ?, latitude = ?, longitude = ? WHERE location_id = ?");
        $stmt->bind_param("ssddi", $locationName, $riverSection, $latitude, $longitude, $locationId);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update location: ' . $conn->error);
        }
        
        // Update device assignments
        $conn->query("UPDATE devices SET location_id = NULL WHERE location_id = $locationId");
        if (!empty($assignDevices)) {
            $ids = implode(',', array_map('intval', $assignDevices));
            $conn->query("UPDATE devices SET location_id = $locationId WHERE device_id IN ($ids)");
        }
        
        $_SESSION['success'] = 'Location updated successfully';
    } else {
        // Add new location
        $stmt = $conn->prepare("INSERT INTO locations (location_name, river_section, latitude, longitude) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssdd", $locationName, $riverSection, $latitude, $longitude);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to add location: ' . $conn->error);
        }
        
        $locationId = $conn->insert_id;
        
        // Assign devices
        if (!empty($assignDevices)) {
            $ids = implode(',', array_map('intval', $assignDevices));
            $conn->query("UPDATE devices SET location_id = $locationId WHERE device_id IN ($ids)");
        }
        
        $_SESSION['success'] = 'Location added successfully';
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Handle location deletion
 */
function handleLocationDelete($conn, $data) {
    $locationId = (int)$data['location_id'];
    
    if ($locationId <= 0) {
        throw new Exception('Invalid location ID');
    }
    
    // Check if location has devices
    $result = $conn->query("SELECT COUNT(*) as count FROM devices WHERE location_id = $locationId");
    $deviceCount = $result->fetch_assoc()['count'];
    
    if ($deviceCount > 0) {
        throw new Exception("Cannot delete location: It has $deviceCount device(s) assigned. Reassign devices first.");
    }
    
    $stmt = $conn->prepare("DELETE FROM locations WHERE location_id = ?");
    $stmt->bind_param("i", $locationId);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Location deleted successfully';
    } else {
        throw new Exception('Failed to delete location: ' . $conn->error);
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Get all devices with location info
 */
function getAllDevices($conn) {
    $sql = "SELECT d.*, l.location_name, l.river_section 
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
 * Get all locations with device counts
 */
function getAllLocations($conn) {
    $sql = "SELECT l.*, COUNT(d.device_id) as device_count 
            FROM locations l 
            LEFT JOIN devices d ON d.location_id = l.location_id 
            GROUP BY l.location_id 
            ORDER BY l.river_section, l.location_name";
    
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
 * Get location by ID
 */
function getLocationById($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM locations WHERE location_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get unassigned devices
 */
function getUnassignedDevices($conn) {
    $result = $conn->query("SELECT device_id, device_name, status 
                           FROM devices 
                           WHERE location_id IS NULL 
                           ORDER BY device_name");
    $devices = [];
    
    while ($row = $result->fetch_assoc()) {
        $devices[] = $row;
    }
    
    return $devices;
}
?>