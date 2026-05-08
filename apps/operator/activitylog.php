<?php
/**
 * Aqua-Vision — Operator Activity Log
 * Operator-focused activity tracking
 * Location: apps/operator/activitylog.php
 */

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Operator Activity Error [$errno]: $errstr in $errfile:$errline");
    return true;
});
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once '../../database/config.php';
session_start();

// Check authentication and operator/admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: /Aqua-Vision/login.php');
    exit;
}

$allowedRoles = ['operator', 'admin'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    $_SESSION['error'] = 'You do not have permission to access this page.';
    if ($_SESSION['user_role'] === 'researcher') {
        header('Location: /Aqua-Vision/apps/researcher/dashboard.php');
    } else {
        header('Location: /Aqua-Vision/apps/admin/dashboard.php');
    }
    exit;
}

// ── Helper Functions ─────────────────────────────────────────────────────
function getOperatorActivityLogs($conn, $userId, $hours = 24) {
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

function getOperatorStatistics($conn, $userId, $hours = 24) {
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

// ── Page Data ─────────────────────────────────────────────────────────────
$currentPage = 'activity';
$hoursFilter = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
$userId = $_SESSION['user_id'];

$activityLogs = getOperatorActivityLogs($conn, $userId, $hoursFilter);
$stats = getOperatorStatistics($conn, $userId, $hoursFilter);

// Get session messages for toast
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Activity Log — Operator</title>
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
            --operator: #0891b2; --operator-bg: #cffafe;
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
        
        .filters {
            display: flex; gap: 12px; margin-bottom: 24px;
        }
        .filter-select {
            padding: 8px 14px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); background: var(--surface);
            font-size: 13px; cursor: pointer;
        }
        
        .content-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
        }
        
        .card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); overflow: hidden;
            box-shadow: 0 2px 8px rgba(15,40,84,0.04);
        }
        .card-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--operator-bg), white);
        }
        .card-title { 
            font-size: 15px; font-weight: 600; color: var(--operator);
            display: flex; align-items: center; gap: 8px;
        }
        .card-body { 
            padding: 0; max-height: 500px; overflow-y: auto;
        }
        
        .activity-item {
            display: flex; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid var(--border);
        }
        .activity-item:last-child { border-bottom: none; }
        
        .activity-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .activity-icon.alert { background: var(--good-bg); color: var(--good); }
        .activity-icon.maintenance { background: var(--warn-bg); color: var(--warn); }
        .activity-icon.status { background: var(--c4-soft); color: var(--c3); }
        
        .activity-content { flex: 1; }
        .activity-title { font-size: 14px; font-weight: 600; color: var(--c1); }
        .activity-desc { font-size: 13px; color: var(--text2); margin-top: 4px; }
        .activity-meta { 
            font-size: 11px; color: var(--text3); margin-top: 6px;
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        
        .badge {
            display: inline-flex; align-items: center;
            padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 500;
        }
        .badge.repair { background: var(--crit-bg); color: var(--crit); }
        .badge.calibration { background: var(--warn-bg); color: var(--warn); }
        .badge.cleaning { background: var(--good-bg); color: var(--good); }
        .badge.inspection { background: var(--c4); color: var(--c1); }
        
        .damage-badge {
            padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 500;
        }
        .damage-badge.low { background: var(--warn-bg); color: var(--warn); }
        .damage-badge.medium { background: #fed7aa; color: #92400e; }
        .damage-badge.high { background: var(--crit-bg); color: var(--crit); }
        
        .empty-state {
            text-align: center; padding: 40px 20px;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 16px; font-weight: 600; color: var(--c1); }
        
        .stats-bar {
            display: flex; gap: 20px; margin-bottom: 24px;
        }
        .stat-item {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 16px; background: var(--surface);
            border-radius: var(--radius-sm); border: 1px solid var(--border);
        }
        .stat-value { font-size: 20px; font-weight: 700; color: var(--c1); }
        .stat-label { font-size: 12px; color: var(--text3); }
        
        @media (max-width: 1200px) {
            .stats-bar { flex-wrap: wrap; }
        }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .content-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .main-content { padding: 16px 20px; }
            .card { padding: 16px; }
            .stats-bar { flex-direction: column; width: 100%; }
            .stat-item { width: 100%; }
        }
        @media (max-width: 480px) {
            .page-title { font-size: 20px; }
            .card { padding: 12px; }
            .main-content { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/operator_nav.php'; ?>
    <?php include __DIR__ . '/../../assets/toast.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">My Activity Log</h1>
                <p class="page-subtitle">View your operational activities and system interactions</p>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-label">Total Actions</div>
                <div class="stat-value"><?= number_format($stats['total_actions']) ?></div>
                <div class="stat-sub">Last <?= $hoursFilter ?> hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Alerts Resolved</div>
                <div class="stat-value"><?= number_format($stats['actions_by_type']['ALERT_ACKNOWLEDGED'] ?? 0) ?></div>
                <div class="stat-sub">Alerts handled</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Maintenance</div>
                <div class="stat-value"><?= number_format($stats['actions_by_type']['MAINTENANCE_LOGGED'] ?? 0) ?></div>
                <div class="stat-sub">Maintenance performed</div>
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
        <div class="filters">
            <select class="filter-select" onchange="window.location.href='?hours='+this.value">
                <option value="24" <?= $hoursFilter == 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                <option value="48" <?= $hoursFilter == 48 ? 'selected' : '' ?>>Last 48 Hours</option>
                <option value="72" <?= $hoursFilter == 72 ? 'selected' : '' ?>>Last 72 Hours</option>
                <option value="168" <?= $hoursFilter == 168 ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="720" <?= $hoursFilter == 720 ? 'selected' : '' ?>>Last 30 Days</option>
            </select>
        </div>
        
        <!-- Activity Log -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <span class="card-title">📝 My Activity Log</span>
                <span style="font-size: 13px; color: var(--text3); margin-left: auto;">
                    <?= count($activityLogs) ?> entries
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($activityLogs)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">�</div>
                        <div class="empty-state-title">No Activity</div>
                        <p style="color: var(--text3); font-size: 13px;">No activity recorded in the selected time range</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <div class="activity-item">
                            <div class="activity-icon alert">�</div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($log['action']) ?></div>
                                <div class="activity-desc"><?= htmlspecialchars($log['details']) ?></div>
                                <div class="activity-meta">
                                    <span>� <?= htmlspecialchars($log['username']) ?></span>
                                    <span>🕐 <?= date('M d, H:i:s', strtotime($log['created_at'])) ?></span>
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
        <?php if (!empty($success)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof showToast === 'function') {
                    showToast(<?= json_encode($success) ?>, 'success', 5000);
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>
