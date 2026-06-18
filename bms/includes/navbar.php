<?php
require_once __DIR__ . '/../config/paths.php';

// Start session and setup
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token generation
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

// Database connection
require_once BASE_PATH . 'includes/db_connection.php';

// User data retrieval
$user = null;

if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("
        SELECT u.*, p.name as position_name 
        FROM users u 
        LEFT JOIN positions p ON u.position_id = p.id 
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
}

// Get user initials for avatar fallback
function getUserInitials($name) {
    $parts = explode(' ', trim($name ?? ''));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper($part[0] ?? '');
    }
    return $initials ?: 'U';
}
?>

<style>
.navbar-glass {
    position: fixed;
    top: 0;
    right: 0;
    /* On desktop, offset by sidebar width */
    left: 260px;
    height: 72px;
    z-index: 1039;
    display: flex;
    align-items: center;
    padding: 0 1.5rem;
    /* Glassmorphism */
    background: rgba(255, 255, 255, 0.72);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.45);
    box-shadow: 
        0 1px 3px rgba(0, 0, 0, 0.04),
        0 4px 24px rgba(0, 0, 0, 0.03);
    transition: left 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                background 0.3s ease,
                box-shadow 0.3s ease;
}

/* When sidebar is collapsed on desktop */
.sb-sidenav-toggled .navbar-glass {
    left: 0;
}

/* ── Sidebar toggle button ────────────────────────────────── */
.nav-toggle-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    border: none;
    background: transparent;
    color: #475569;
    font-size: 16px;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
}

.nav-toggle-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 12px;
    background: rgba(11, 51, 84, 0.08);
    transform: scale(0);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-toggle-btn:hover::before {
    transform: scale(1);
}

.nav-toggle-btn:hover {
    color: #0b3354;
}

.nav-toggle-btn:hover i {
    transform: scale(1.12);
}

.nav-toggle-btn i {
    position: relative;
    z-index: 1;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-toggle-btn:active::before {
    background: rgba(11, 51, 84, 0.14);
}

/* ── Spacer ───────────────────────────────────────────────── */
.nav-spacer { flex: 1; }

/* ── Right action buttons ─────────────────────────────────── */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* ── Icon action buttons (notifications, search, etc.) ───── */
.nav-action-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    text-decoration: none;
}

.nav-action-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 12px;
    background: rgba(11, 51, 84, 0.08);
    transform: scale(0);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-action-btn:hover::before {
    transform: scale(1);
}

.nav-action-btn:hover {
    color: #0b3354;
}

.nav-action-btn:hover i {
    transform: scale(1.1);
}

.nav-action-btn i {
    position: relative;
    z-index: 1;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Notification badge dot */
.nav-action-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.9);
    z-index: 2;
    animation: badgePulse 2s ease-in-out infinite;
}

@keyframes badgePulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.8; }
}

/* ── Divider ──────────────────────────────────────────────── */
.nav-separator {
    width: 1px;
    height: 28px;
    background: linear-gradient(
        to bottom,
        transparent,
        rgba(148, 163, 184, 0.25),
        transparent
    );
    margin: 0 8px;
    flex-shrink: 0;
}

/* ── User dropdown toggle ─────────────────────────────────── */
.nav-user-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 10px 6px 6px;
    border-radius: 14px;
    border: 1px solid transparent;
    background: transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.nav-user-toggle::after {
    display: none !important;
}

.nav-user-toggle:hover,
.nav-user-toggle.show {
    background: rgba(11, 51, 84, 0.06);
    border-color: rgba(11, 51, 84, 0.1);
}

/* ── Avatar ───────────────────────────────────────────────── */
.nav-avatar {
    width: 36px;
    height: 36px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.5px;
    background: linear-gradient(135deg, #0b3354, #1a5a8a);
    flex-shrink: 0;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                box-shadow 0.25s ease;
    box-shadow: 0 2px 8px rgba(11, 51, 84, 0.25);
}

.nav-user-toggle:hover .nav-avatar {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(11, 51, 84, 0.35);
}

/* ── User info text ───────────────────────────────────────── */
.nav-user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    line-height: 1.2;
}

.nav-user-name {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-user-role {
    font-size: 11px;
    font-weight: 500;
    color: #94a3b8;
    white-space: nowrap;
}

/* ── Chevron ──────────────────────────────────────────────── */
.nav-chevron {
    font-size: 9px;
    color: #94a3b8;
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                color 0.2s ease;
    flex-shrink: 0;
}

.nav-user-toggle[aria-expanded="true"] .nav-chevron {
    transform: rotate(180deg);
    color: #0b3354;
}

/* ── Dropdown menu ────────────────────────────────────────── */
.nav-dropdown-menu {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow:
        0 4px 6px rgba(0, 0, 0, 0.04),
        0 10px 40px rgba(0, 0, 0, 0.08);
    padding: 6px;
    overflow: hidden;
    min-width: 200px;
    margin-top: 8px !important;
    animation: dropdownFadeIn 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

@keyframes dropdownFadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.nav-dropdown-menu::before { display: none; }

/* ── Dropdown header (user info inside menu) ─────────────── */
.nav-dropdown-header {
    padding: 14px 16px 12px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.6);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.nav-dropdown-avatar {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #0b3354, #1a5a8a);
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(11, 51, 84, 0.3);
}

.nav-dropdown-user-info {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
    min-width: 0;
}

.nav-dropdown-user-name {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-dropdown-user-role {
    font-size: 11.5px;
    color: #94a3b8;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 1px;
}

.nav-dropdown-user-role .role-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #22c55e;
    flex-shrink: 0;
}

/* ── Dropdown items ───────────────────────────────────────── */
.nav-dropdown-item {
    padding: 6px 10px;
    color: #475569;
    font-size: 12.5px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 8px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    background: transparent;
    text-decoration: none;
    position: relative;
}

.nav-dropdown-item:hover,
.nav-dropdown-item:focus {
    background: rgba(11, 51, 84, 0.06) !important;
    color: #1e293b;
}

.nav-dropdown-item .dd-icon {
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    background: #f1f5f9;
    font-size: 11px;
    color: #64748b;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.nav-dropdown-item:hover .dd-icon {
    background: rgba(11, 51, 84, 0.1);
    color: #0b3354;
}

.nav-dropdown-item .dd-text {
    flex: 1;
}

.nav-dropdown-item .dd-shortcut {
    font-size: 10.5px;
    color: #cbd5e1;
    font-weight: 500;
    letter-spacing: 0.5px;
}

/* ── Danger item ──────────────────────────────────────────── */
.nav-dropdown-item.item-danger {
    color: #ef4444;
}

.nav-dropdown-item.item-danger:hover,
.nav-dropdown-item.item-danger:focus {
    background: rgba(239, 68, 68, 0.06) !important;
    color: #dc2626;
}

.nav-dropdown-item.item-danger .dd-icon {
    background: #fef2f2;
    color: #ef4444;
}

.nav-dropdown-item.item-danger:hover .dd-icon {
    background: #fee2e2;
    color: #dc2626;
}

/* ── Dropdown divider ─────────────────────────────────────── */
.nav-dropdown-divider {
    height: 1px;
    background: rgba(226, 232, 240, 0.5);
    margin: 3px 6px;
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE — Tablet & Mobile
   ═══════════════════════════════════════════════════════════════ */

/* Tablet */
@media (max-width: 991.98px) {
    .navbar-glass {
        left: 0 !important;
        padding: 0 1rem;
    }

    .nav-user-info {
        display: none !important;
    }

    .nav-chevron {
        display: none !important;
    }

    .nav-dropdown-menu {
        min-width: 220px;
    }
}

/* Mobile */
@media (max-width: 575.98px) {
    .navbar-glass {
        height: 64px;
        padding: 0 0.75rem;
    }

    .nav-action-btn {
        width: 36px;
        height: 36px;
        border-radius: 10px;
    }

    .nav-toggle-btn {
        width: 36px;
        height: 36px;
        border-radius: 10px;
    }

    .nav-separator {
        height: 24px;
        margin: 0 4px;
    }

    .nav-dropdown-menu {
        position: fixed !important;
        top: 64px !important;
        left: 8px !important;
        right: 8px !important;
        transform: none !important;
        min-width: auto;
        width: auto;
        border-radius: 14px;
    }
}
</style>

<?php require_once BASE_PATH . 'includes/loader.php'; ?>

<nav class="navbar-glass" id="navbarGlass">
    <!-- Sidebar Toggle -->
    <button class="nav-toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Spacer -->
    <div class="nav-spacer"></div>

    <?php if($user): ?>
    <!-- Right section -->
    <div class="nav-actions">
        <!-- User Dropdown -->
        <div class="dropdown">
            <a class="nav-user-toggle dropdown-toggle" href="#" role="button"
               id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="nav-avatar"><?= getUserInitials($user['name']) ?></div>
                <div class="nav-user-info d-none d-lg-flex">
                    <span class="nav-user-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                    <span class="nav-user-role"><?= htmlspecialchars($user['position_name'] ?? 'Staff') ?></span>
                </div>
                <i class="fas fa-chevron-down nav-chevron d-none d-lg-block"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end nav-dropdown-menu" aria-labelledby="userDropdown">
                <li>
                    <a class="dropdown-item nav-dropdown-item" href="<?= BASE_URL ?>modules/settings/company_settings.php">
                        <span class="dd-icon"><i class="fas fa-cog"></i></span>
                        <span class="dd-text">Settings</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider nav-dropdown-divider"></li>
                <li>
                    <a class="dropdown-item nav-dropdown-item item-danger" href="<?= BASE_URL ?>modules/auth/logout.php">
                        <span class="dd-icon"><i class="fas fa-arrow-right-from-bracket"></i></span>
                        <span class="dd-text">Sign Out</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</nav>