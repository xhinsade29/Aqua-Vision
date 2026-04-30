<?php
/**
 * Aqua-Vision — Researcher Navigation Sidebar
 * Location: apps/researcher/researcher_nav.php
 */
$currentPage = $currentPage ?? 'researcher-dashboard';

$logoSrc = '/Aqua-Vision/assets/logo.png';
?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Space+Grotesk:wght@400;500;600&display=swap');

  :root {
    --c1:          #0F2854;
    --c2:          #1C4D8D;
    --c3:          #4988C4;
    --c4:          #BDE8F5;
    --c4-soft:     rgba(189,232,245,0.13);
    --c4-hover:    rgba(189,232,245,0.20);
    --sidebar-w:   240px;
    --radius:      14px;
    --radius-sm:   8px;
    --transition:  0.22s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .av-sidebar {
    width: var(--sidebar-w);
    min-height: 100vh;
    background: linear-gradient(180deg, #0F2854 0%, #0a1f42 100%);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    border-right: 1px solid rgba(189,232,245,0.08);
    z-index: 100;
    font-family: 'DM Sans', sans-serif;
    overflow-y: auto;
  }

  .av-sidebar::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%234988C4' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
  }

  .av-logo-area {
    padding: 18px 16px 16px;
    border-bottom: 1px solid rgba(189,232,245,0.08);
    position: relative;
  }

  .av-logo-row {
    display: flex;
    align-items: center;
    gap: 11px;
  }

  .av-logo-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    background: transparent;
    border: 1.5px solid rgba(189,232,245,0.15);
    padding: 0;
    position: relative;
    z-index: 1;
  }

  .av-logo-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 50%;
  }

  .av-logo-text {
    display: flex;
    flex-direction: column;
    line-height: 1;
  }

  .av-logo-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--c4);
    letter-spacing: 0.02em;
  }

  .av-logo-sub {
    font-size: 10px;
    color: rgba(189,232,245,0.45);
    font-weight: 400;
    margin-top: 3px;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .av-status-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    background: rgba(189,232,245,0.07);
    border: 1px solid rgba(189,232,245,0.12);
    border-radius: 20px;
    padding: 5px 10px;
    width: fit-content;
  }

  .av-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #5cd67e;
    box-shadow: 0 0 0 2px rgba(92,214,126,0.3);
    animation: av-pulse 2s infinite;
  }

  @keyframes av-pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(92,214,126,0.30); }
    50%       { box-shadow: 0 0 0 4px rgba(92,214,126,0.15); }
  }

  .av-status-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(189,232,245,0.7);
    letter-spacing: 0.04em;
  }

  .av-role-badge {
    margin-top: 8px;
    padding: 4px 12px;
    background: rgba(5, 150, 105, 0.2);
    border: 1px solid rgba(5, 150, 105, 0.3);
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    color: #059669;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    width: fit-content;
  }

  .av-nav-section {
    padding: 16px 12px 4px;
    flex: 1;
    position: relative;
  }

  .av-section-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(189,232,245,0.3);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    padding: 0 8px;
    margin-bottom: 4px;
  }

  .av-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition), transform var(--transition);
    position: relative;
    margin-bottom: 1px;
    user-select: none;
    text-decoration: none;
    border: 1px solid transparent;
  }

  .av-nav-item:hover { background: var(--c4-hover); }
  .av-nav-item:active { transform: scale(0.98); }

  .av-nav-item.active {
    background: linear-gradient(90deg, rgba(73,136,196,0.30), rgba(73,136,196,0.10));
    border-color: rgba(73,136,196,0.30);
  }

  .av-nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 3px;
    background: var(--c4);
    border-radius: 0 4px 4px 0;
  }

  .av-nav-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: rgba(189,232,245,0.06);
    transition: background var(--transition);
  }

  .av-nav-item.active .av-nav-icon { background: rgba(73,136,196,0.35); }
  .av-nav-icon svg { width: 14px; height: 14px; }

  .av-nav-label-wrap { flex: 1; }

  .av-nav-label {
    font-size: 13px;
    font-weight: 500;
    color: rgba(189,232,245,0.65);
    transition: color var(--transition);
    line-height: 1;
  }

  .av-nav-sublabel { font-size: 10px; color: rgba(189,232,245,0.30); margin-top: 2px; }
  .av-nav-item.active .av-nav-label { color: var(--c4); }

  .av-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 10px;
    background: #e85555;
    color: #fff;
    min-width: 18px;
    text-align: center;
    line-height: 14px;
  }

  .av-badge.info {
    background: rgba(73,136,196,0.40);
    color: var(--c4);
  }

  .av-nav-divider {
    height: 1px;
    background: rgba(189,232,245,0.07);
    margin: 10px 12px;
  }

  .av-researcher-section {
    padding: 8px 12px 12px;
    border-top: 1px solid rgba(189,232,245,0.07);
    position: relative;
  }

  .av-researcher-label {
    font-size: 10px;
    font-weight: 500;
    color: rgba(189,232,245,0.25);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    padding: 0 8px;
    margin-bottom: 4px;
  }

  .av-user-footer {
    padding: 12px;
    border-top: 1px solid rgba(189,232,245,0.07);
    position: relative;
  }

  .av-user-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition);
  }

  .av-user-card:hover { background: var(--c4-soft); }

  .av-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--c2), var(--c3));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--c4);
    border: 1.5px solid rgba(189,232,245,0.20);
    flex-shrink: 0;
  }

  .av-user-info { flex: 1; }
  .av-user-name { font-size: 12px; font-weight: 500; color: rgba(189,232,245,0.80); }
  .av-user-role { font-size: 10px; color: rgba(189,232,245,0.35); margin-top: 1px; }

  .av-user-menu-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    opacity: 0.4;
    width: 20px;
    height: 20px;
  }

  .av-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--c4); }

  body { margin-left: var(--sidebar-w); }
</style>

<nav class="av-sidebar" id="av-sidebar" aria-label="Researcher navigation">
  <div class="av-logo-area">
    <div class="av-logo-row">
      <div class="av-logo-icon">
        <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>"
             alt="Aqua-Vision logo"
             onerror="this.style.display='none'">
      </div>
      <div class="av-logo-text">
        <span class="av-logo-title">Aqua-Vision</span>
        <span class="av-logo-sub">Research Portal</span>
      </div>
    </div>
    <div class="av-role-badge">🔬 Researcher</div>
    <div class="av-status-pill" role="status" aria-live="polite">
      <div class="av-status-dot"></div>
      <span class="av-status-label">Data Collection Active</span>
    </div>
  </div>

  <div class="av-nav-section">
    <div class="av-section-label" aria-hidden="true">Research</div>

    <a href="/Aqua-Vision/apps/researcher/dashboard.php"
       class="av-nav-item <?= $currentPage === 'researcher-dashboard' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'researcher-dashboard' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <rect x="1" y="1" width="6" height="6" rx="1.5" fill="#BDE8F5"/>
          <rect x="9" y="1" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
          <rect x="1" y="9" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
          <rect x="9" y="9" width="6" height="6" rx="1.5" fill="#BDE8F5" opacity="0.5"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Dashboard</div>
        <div class="av-nav-sublabel">Data analysis & trends</div>
      </div>
    </a>

    <a href="/Aqua-Vision/apps/researcher/activitylog.php"
       class="av-nav-item <?= $currentPage === 'history' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'history' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <circle cx="8" cy="8" r="5.5" stroke="#4988C4" stroke-width="1.4"/>
          <path d="M8 5v3.5l2.5 1.5" stroke="#4988C4" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Activity Logs</div>
        <div class="av-nav-sublabel">Historical sensor data</div>
      </div>
    </a>

    <a href="/Aqua-Vision/apps/researcher/reports.php"
       class="av-nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'reports' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <path d="M2 12 Q8 8, 14 12" stroke="#8B4513" stroke-width="1.5" fill="none"/>
          <circle cx="4" cy="11" r="1" fill="#8B4513"/>
          <circle cx="8" cy="10" r="1" fill="#8B4513"/>
          <circle cx="12" cy="11" r="1" fill="#8B4513"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Reports</div>
        <div class="av-nav-sublabel">Generate & export reports</div>
      </div>
    </a>

    <div class="av-nav-divider" role="separator"></div>

    <div class="av-section-label" aria-hidden="true">Analysis</div>

    <a href="/Aqua-Vision/apps/researcher/trends.php"
       class="av-nav-item <?= $currentPage === 'trends' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'trends' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <path d="M2 12 L6 8 L10 10 L14 4" stroke="#4988C4" stroke-width="1.5" stroke-linecap="round" fill="none"/>
          <circle cx="14" cy="4" r="1.5" fill="#4988C4"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Trend Analysis</div>
        <div class="av-nav-sublabel">Long-term patterns</div>
      </div>
    </a>

    <a href="/Aqua-Vision/apps/researcher/export.php"
       class="av-nav-item <?= $currentPage === 'export' ? 'active' : '' ?>"
       aria-current="<?= $currentPage === 'export' ? 'page' : 'false' ?>">
      <div class="av-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none">
          <path d="M8 2v8M4 6l4-4 4 4" stroke="#4988C4" stroke-width="1.5" stroke-linecap="round"/>
          <path d="M2 10v3a1 1 0 001 1h10a1 1 0 001-1v-3" stroke="#4988C4" stroke-width="1.5"/>
        </svg>
      </div>
      <div class="av-nav-label-wrap">
        <div class="av-nav-label">Export Data</div>
        <div class="av-nav-sublabel">Download for analysis</div>
      </div>
    </a>
  </div>

  <div class="av-user-footer">
    <div class="av-user-card" role="button" tabindex="0" aria-label="Logout" onclick="showLogoutModal()">
      <div class="av-avatar" aria-hidden="true"><?= strtoupper(substr($_SESSION['user_name'] ?? 'R', 0, 1)) ?></div>
      <div class="av-user-info">
        <div class="av-user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Researcher') ?></div>
        <div class="av-user-role"><?= ucfirst($_SESSION['user_role'] ?? 'Researcher') ?> • Click to logout</div>
      </div>
      <div class="av-user-menu-btn" aria-hidden="true">
        <div class="av-dot"></div>
        <div class="av-dot"></div>
        <div class="av-dot"></div>
      </div>
    </div>
  </div>

</nav>

<!-- Logout Modal -->
<div id="logoutModal" class="modal-overlay" style="display:none">
  <div class="modal-content">
    <div class="modal-header">
      <h3 class="modal-title">Confirm Logout</h3>
      <button class="modal-close" onclick="hideLogoutModal()" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to logout from Aqua-Vision?</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="hideLogoutModal()">Cancel</button>
      <a href="/Aqua-Vision/logout.php" class="btn btn-primary">Logout</a>
    </div>
  </div>
</div>

<style>
  .modal-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    backdrop-filter: blur(4px);
  }

  .modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease;
  }

  @keyframes modalSlideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }

  .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
  }

  .modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    font-family: 'DM Sans', sans-serif;
  }

  .modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: background 0.2s;
  }

  .modal-close:hover {
    background: #f3f4f6;
    color: #1f2937;
  }

  .modal-body {
    padding: 24px;
    color: #4b5563;
    font-size: 14px;
    font-family: 'DM Sans', sans-serif;
    line-height: 1.5;
  }

  .modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 16px 24px;
    border-top: 1px solid #e5e7eb;
  }

  .btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .btn-secondary {
    background: #f3f4f6;
    color: #374151;
  }

  .btn-secondary:hover {
    background: #e5e7eb;
  }

  .btn-primary {
    background: #ef4444;
    color: white;
  }

  .btn-primary:hover {
    background: #dc2626;
  }
</style>

<script>
function showLogoutModal() {
  document.getElementById('logoutModal').style.display = 'flex';
}

function hideLogoutModal() {
  document.getElementById('logoutModal').style.display = 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    hideLogoutModal();
  }
});

// Close modal when clicking overlay
document.getElementById('logoutModal').addEventListener('click', function(e) {
  if (e.target === this) {
    hideLogoutModal();
  }
});
</script>

<!-- Toast Notifications -->
<?php include __DIR__ . '/../../assets/toast.php'; ?>
