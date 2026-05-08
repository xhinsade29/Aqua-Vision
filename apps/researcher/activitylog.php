<?php
/**
 * Aqua-Vision — Researcher Activity Log
 * View historical sensor data and trends
 * Location: apps/researcher/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Researcher Activity Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// Check authentication and researcher role
if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
    exit;
}

$allowedRoles = ['researcher', 'admin'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    exit;
}

// ── Helper Functions ─────────────────────────────────────────────────────────

function getResearcherActivityLogs($conn, $userId, $hours = 24) {
    $sql = "SELECT sl.log_id, sl.action, sl.details, sl.ip_address, sl.created_at,
                   u.username, u.full_name
            FROM system_logs sl
            JOIN users u ON u.user_id = sl.user_id
            WHERE sl.user_id = ? 
              AND sl.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sl.created_at DESC
            LIMIT 200";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $hours);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getResearcherStatistics($conn, $userId, $hours = 24) {
    $stats = [];
    
    // Total actions
    $sql = "SELECT COUNT(*) as cnt FROM system_logs 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $hours);
    $stmt->execute();
    $stats['total_actions'] = $stmt->get_result()->fetch_assoc()['cnt'];
    
    // Actions by type
    $sql = "SELECT action, COUNT(*) as cnt FROM system_logs 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY action";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $hours);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['actions_by_type'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['actions_by_type'][$row['action']] = $row['cnt'];
    }
    
    // Last activity
    $sql = "SELECT MAX(created_at) as last FROM system_logs 
            WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stats['last_activity'] = $stmt->get_result()->fetch_assoc()['last'];
    
    return $stats;
}

// ── API: Fetch Data ──────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'fetch') {
    error_reporting(0); ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    
    $hours = intval($_GET['hours'] ?? 24);
    $userId = $_SESSION['user_id'];
    
    $activityLogs = getResearcherActivityLogs($conn, $userId, $hours);
    $stats = getResearcherStatistics($conn, $userId, $hours);
    
    echo json_encode([
        'ok' => true,
        'activity_logs' => $activityLogs,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_NUMERIC_CHECK);
    exit;
}

// ── Page Data ─────────────────────────────────────────────────────────────────
$currentPage = 'history';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$userId = $_SESSION['user_id'];

// Get data
$activityLogs = getResearcherActivityLogs($conn, $userId, $hoursFilter);
$stats = getResearcherStatistics($conn, $userId, $hoursFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log — Aqua-Vision Researcher</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
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
            --sidebar-w: 240px;
        }
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: var(--bg); 
            min-height: 100vh;
            margin-left: var(--sidebar-w);
        }
        
        .main-content { padding: 24px; max-width: 1400px; }
        
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .page-title { 
            font-family: 'Space Grotesk', sans-serif; 
            font-size: 28px; font-weight: 700; color: var(--c1); 
        }
        .page-subtitle { 
            font-size: 14px; color: var(--text2); margin-top: 4px; 
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px; border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 28px; font-weight: 700; color: var(--c1); margin-top: 8px; }
        .stat-sub { font-size: 12px; color: var(--text2); margin-top: 4px; }
        
        /* Filters */
        .filter-bar {
            display: flex; gap: 12px; align-items: center;
            background: var(--surface); padding: 16px 20px;
            border-radius: var(--radius); border: 1px solid var(--border);
            margin-bottom: 24px; flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 8px; }
        .filter-label { font-size: 12px; color: var(--text3); font-weight: 500; }
        .filter-select {
            padding: 8px 12px; border: 2px solid var(--border);
            border-radius: var(--radius-sm); font-size: 13px;
            background: var(--surface); color: var(--text);
            cursor: pointer; min-width: 140px;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; cursor: pointer;
            border: none; text-decoration: none; transition: all 0.2s;
        }
        .btn-primary { background: var(--c2); color: white; }
        .btn-outline { background: var(--surface); color: var(--text2); border: 1px solid var(--border); }
        
        /* Timeline */
        .timeline-card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
        }
        .timeline-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .timeline-body {
            max-height: 600px; overflow-y: auto;
        }
        
        .timeline-item {
            display: flex; gap: 16px; padding: 16px 20px;
            border-bottom: 1px solid var(--border); transition: background 0.2s;
        }
        .timeline-item:hover { background: var(--bg); }
        .timeline-item:last-child { border-bottom: none; }
        
        .timeline-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .timeline-icon.reading { background: #dbeafe; }
        .timeline-icon.alert-critical { background: var(--crit-bg); color: var(--crit); }
        .timeline-icon.alert-high { background: var(--warn-bg); color: var(--warn); }
        
        .timeline-content { flex: 1; min-width: 0; }
        .timeline-title {
            font-size: 14px; font-weight: 600; color: var(--c1);
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .timeline-desc { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .timeline-meta {
            font-size: 11px; color: var(--text3); margin-top: 6px;
            display: flex; gap: 16px; flex-wrap: wrap;
        }
        .timeline-meta span { display: flex; align-items: center; gap: 4px; }
        
        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 500;
        }
        .badge-normal { background: var(--good-bg); color: var(--good); }
        .badge-warning { background: var(--warn-bg); color: var(--warn); }
        .badge-critical { background: var(--crit-bg); color: var(--crit); }
        .badge-section {
            padding: 2px 8px; border-radius: 4px;
            font-size: 10px; text-transform: uppercase;
        }
        .badge-upstream { background: #dbeafe; color: #1e40af; }
        .badge-midstream { background: #fef3c7; color: #92400e; }
        .badge-downstream { background: #fee2e2; color: #991b1b; }
        
        /* Empty State */
        .empty-state {
            text-align: center; padding: 60px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-state-title { font-size: 18px; font-weight: 600; color: var(--c1); margin-bottom: 8px; }
        
        /* Value Display */
        .value-display {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px; font-weight: 600; color: var(--c1);
        }
        .value-unit { font-size: 12px; color: var(--text3); font-weight: 400; }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .main-content { padding: 16px 20px; }
            .card { padding: 16px; }
            .filter-bar { flex-direction: column; align-items: flex-start; gap: 8px; }
            .filter-bar .btn { width: 100%; justify-content: center; }
        }
        @media (max-width: 480px) {
            .page-title { font-size: 20px; }
            .card { padding: 12px; }
            .main-content { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/researcher_nav.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">My Activity Log</h1>
                <p class="page-subtitle">View your research activities and system interactions</p>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Actions</div>
                <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
                <div class="stat-sub">Last <?= $hoursFilter ?> hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Logins</div>
                <div class="stat-value"><?= number_format($stats['actions_by_type']['LOGIN'] ?? 0) ?></div>
                <div class="stat-sub">Session starts</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Data Analysis</div>
                <div class="stat-value"><?= number_format(($stats['actions_by_type']['DATA_ANALYSIS'] ?? 0) + ($stats['actions_by_type']['REPORT_GENERATED'] ?? 0)) ?></div>
                <div class="stat-sub">Reports & analysis</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Last Activity</div>
                <div class="stat-value" style="font-size: 18px; margin-top: 12px;">
                    <?= $stats['last_activity'] ? date('M d, H:i', strtotime($stats['last_activity'])) : 'Never' ?>
                </div>
                <div class="stat-sub"><?= timeAgo($stats['last_activity']) ?></div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Time Range:</span>
                <select class="filter-select" onchange="updateFilter('hours', this.value)">
                    <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                    <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                    <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="720" <?= $hoursFilter == 720 ? 'selected' : '' ?>>Last 30 Days</option>
                </select>
            </div>
            <a href="activitylog.php" class="btn btn-outline">Reset</a>
        </div>
        
        <!-- Timeline -->
        <div class="timeline-card">
            <div class="timeline-header">
                <span class="card-title">My Activity Log</span>
                <span style="font-size: 13px; color: var(--text3);">
                    <?= count($activityLogs) ?> entries
                </span>
            </div>
            <div class="timeline-body">
                <?php if (empty($activityLogs)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <div class="empty-state-title">No Activity</div>
                        <p style="color: var(--text2);">No activity recorded in the selected time range</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon reading">�</div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    <?= htmlspecialchars($log['action']) ?>
                                    <span class="badge badge-normal">Logged</span>
                                </div>
                                <div class="timeline-desc"><?= htmlspecialchars($log['details']) ?></div>
                                <div class="timeline-meta">
                                    <span>� <?= htmlspecialchars($log['username']) ?></span>
                                    <span>🕐 <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></span>
                                    <?php if ($log['ip_address']): ?>
                                        <span>🌐 <?= htmlspecialchars($log['ip_address']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function updateFilter(key, value) {
            const url = new URL(window.location.href);
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
            window.location.href = url.toString();
        }
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            fetch('?action=fetch&hours=<?= $hoursFilter ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        // Could update stats dynamically here
                    }
                })
                .catch(console.error);
        }, 30000);
    </script>
</body>
</html>
<?php
function timeAgo($datetime) {
    if (!$datetime) return 'No data';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    return floor($diff / 86400) . ' days ago';
}
?>
