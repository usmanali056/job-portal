<?php
/**
 * JobNexus - Admin Settings
 * System configuration and settings management
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_ADMIN) {
  header('Location: ../auth/login.php');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User();

// Get current admin info
$admin = $userModel->findById($_SESSION['user_id']);

$message = '';
$messageType = '';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // Update Admin Password
  if ($action === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
      $message = 'All password fields are required.';
      $messageType = 'error';
    } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
      $message = 'Current password is incorrect.';
      $messageType = 'error';
    } elseif (strlen($newPassword) < 8) {
      $message = 'New password must be at least 8 characters.';
      $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
      $message = 'New passwords do not match.';
      $messageType = 'error';
    } else {
      $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
      $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
      if ($stmt->execute([$hashedPassword, $_SESSION['user_id']])) {
        $message = 'Password updated successfully.';
        $messageType = 'success';
      } else {
        $message = 'Error updating password.';
        $messageType = 'error';
      }
    }
    $activeTab = 'security';
  }

  // Update Admin Email
  if ($action === 'update_email') {
    $newEmail = trim($_POST['new_email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($newEmail) || empty($password)) {
      $message = 'Email and password are required.';
      $messageType = 'error';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
      $message = 'Please enter a valid email address.';
      $messageType = 'error';
    } elseif (!password_verify($password, $admin['password_hash'])) {
      $message = 'Password is incorrect.';
      $messageType = 'error';
    } else {
      // Check if email already exists
      $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
      $stmt->execute([$newEmail, $_SESSION['user_id']]);
      if ($stmt->fetch()) {
        $message = 'This email is already in use.';
        $messageType = 'error';
      } else {
        $stmt = $db->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$newEmail, $_SESSION['user_id']])) {
          $_SESSION['user_email'] = $newEmail;
          $admin['email'] = $newEmail;
          $message = 'Email updated successfully.';
          $messageType = 'success';
        } else {
          $message = 'Error updating email.';
          $messageType = 'error';
        }
      }
    }
    $activeTab = 'security';
  }

  // Clear Application Cache / Maintenance Actions
  if ($action === 'clear_expired_jobs') {
    $stmt = $db->prepare("UPDATE jobs SET status = 'expired' WHERE status = 'active' AND application_deadline < CURDATE()");
    $stmt->execute();
    $affected = $stmt->rowCount();
    $message = "Marked $affected expired jobs.";
    $messageType = 'success';
    $activeTab = 'maintenance';
  }

  if ($action === 'clear_old_sessions') {
    // Clear remember tokens older than 30 days
    $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE last_login < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $affected = $stmt->rowCount();
    $message = "Cleared $affected old session tokens.";
    $messageType = 'success';
    $activeTab = 'maintenance';
  }

  if ($action === 'cleanup_applications') {
    // Mark old pending applications as expired (older than 90 days)
    $stmt = $db->prepare("UPDATE applications SET status = 'withdrawn' WHERE status = 'applied' AND applied_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $affected = $stmt->rowCount();
    $message = "Cleaned up $affected stale applications.";
    $messageType = 'success';
    $activeTab = 'maintenance';
  }
}

// Get system statistics for maintenance tab
$systemStats = [];

// Database size estimation
$stmt = $db->query("SELECT COUNT(*) FROM users");
$systemStats['total_users'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM jobs");
$systemStats['total_jobs'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM applications");
$systemStats['total_applications'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM companies");
$systemStats['total_companies'] = $stmt->fetchColumn();

// Expired jobs count
$stmt = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'active' AND application_deadline < CURDATE()");
$systemStats['expired_jobs'] = $stmt->fetchColumn();

// Stale applications (older than 90 days, still pending)
$stmt = $db->query("SELECT COUNT(*) FROM applications WHERE status = 'applied' AND applied_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$systemStats['stale_applications'] = $stmt->fetchColumn();

$pageTitle = "Settings - JobNexus Admin";
include '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="admin-avatar">
        <?php echo strtoupper(substr($_SESSION['user_email'] ?? 'A', 0, 1)); ?>
      </div>
      <h3><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Admin'); ?></h3>
      <span class="role-badge admin">Administrator</span>
    </div>

    <nav class="sidebar-nav">
      <a href="<?php echo BASE_URL; ?>/admin/index.php" class="nav-item">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/admin/users.php" class="nav-item">
        <i class="fas fa-users"></i>
        <span>Manage Users</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/admin/companies.php" class="nav-item">
        <i class="fas fa-building"></i>
        <span>Companies</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/admin/jobs.php" class="nav-item">
        <i class="fas fa-briefcase"></i>
        <span>All Jobs</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="nav-item">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="nav-item active">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="dashboard-main">
    <div class="dashboard-header">
      <div class="header-left">
        <h1><i class="fas fa-cog"></i> Settings</h1>
        <p>Configure system settings and preferences</p>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <!-- Settings Tabs -->
    <div class="settings-container">
      <div class="settings-tabs">
        <a href="?tab=general" class="tab-link <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
          <i class="fas fa-sliders-h"></i> General
        </a>
        <a href="?tab=security" class="tab-link <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
          <i class="fas fa-shield-alt"></i> Security
        </a>
        <a href="?tab=maintenance" class="tab-link <?php echo $activeTab === 'maintenance' ? 'active' : ''; ?>">
          <i class="fas fa-tools"></i> Maintenance
        </a>
        <a href="?tab=about" class="tab-link <?php echo $activeTab === 'about' ? 'active' : ''; ?>">
          <i class="fas fa-info-circle"></i> About
        </a>
      </div>

      <div class="settings-content">
        <!-- General Settings Tab -->
        <?php if ($activeTab === 'general'): ?>
          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-globe"></i> Site Information</h2>
              <p>Basic information about your job portal</p>
            </div>

            <div class="info-card glass-card">
              <div class="info-grid">
                <div class="info-item">
                  <label>Site Name</label>
                  <span class="info-value"><?php echo SITE_NAME; ?></span>
                </div>
                <div class="info-item">
                  <label>Tagline</label>
                  <span class="info-value"><?php echo SITE_TAGLINE; ?></span>
                </div>
                <div class="info-item">
                  <label>Base URL</label>
                  <span class="info-value"><?php echo BASE_URL; ?></span>
                </div>
                <div class="info-item">
                  <label>Timezone</label>
                  <span class="info-value"><?php echo date_default_timezone_get(); ?></span>
                </div>
              </div>
              <div class="info-note">
                <i class="fas fa-info-circle"></i>
                <span>To change these settings, edit the <code>config/config.php</code> file.</span>
              </div>
            </div>
          </div>

          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-upload"></i> File Upload Settings</h2>
              <p>Configure file upload limits and allowed types</p>
            </div>

            <div class="info-card glass-card">
              <div class="info-grid">
                <div class="info-item">
                  <label>Max File Size</label>
                  <span class="info-value"><?php echo (MAX_FILE_SIZE / 1024 / 1024); ?> MB</span>
                </div>
                <div class="info-item">
                  <label>Allowed Resume Types</label>
                  <span class="info-value"><?php echo implode(', ', ALLOWED_RESUME_TYPES); ?></span>
                </div>
                <div class="info-item">
                  <label>Allowed Image Types</label>
                  <span class="info-value"><?php echo implode(', ', ALLOWED_IMAGE_TYPES); ?></span>
                </div>
                <div class="info-item">
                  <label>Upload Directory</label>
                  <span class="info-value">/uploads/</span>
                </div>
              </div>
            </div>
          </div>

          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-list-ol"></i> Pagination Settings</h2>
              <p>Items per page for different sections</p>
            </div>

            <div class="info-card glass-card">
              <div class="info-grid">
                <div class="info-item">
                  <label>Jobs Per Page</label>
                  <span class="info-value"><?php echo JOBS_PER_PAGE; ?></span>
                </div>
                <div class="info-item">
                  <label>Applications Per Page</label>
                  <span class="info-value"><?php echo APPLICATIONS_PER_PAGE; ?></span>
                </div>
                <div class="info-item">
                  <label>Users Per Page</label>
                  <span class="info-value"><?php echo USERS_PER_PAGE; ?></span>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Security Tab -->
        <?php if ($activeTab === 'security'): ?>
          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-user-shield"></i> Account Security</h2>
              <p>Manage your admin account credentials</p>
            </div>

            <!-- Current Admin Info -->
            <div class="info-card glass-card">
              <h3><i class="fas fa-user"></i> Current Account</h3>
              <div class="info-grid">
                <div class="info-item">
                  <label>Email</label>
                  <span class="info-value"><?php echo htmlspecialchars($admin['email']); ?></span>
                </div>
                <div class="info-item">
                  <label>Role</label>
                  <span class="info-value badge-admin">Administrator</span>
                </div>
                <div class="info-item">
                  <label>Last Login</label>
                  <span
                    class="info-value"><?php echo $admin['last_login'] ? date('M j, Y g:i A', strtotime($admin['last_login'])) : 'N/A'; ?></span>
                </div>
                <div class="info-item">
                  <label>Account Created</label>
                  <span class="info-value"><?php echo date('M j, Y', strtotime($admin['created_at'])); ?></span>
                </div>
              </div>
            </div>

            <!-- Change Email -->
            <div class="form-card glass-card">
              <h3><i class="fas fa-envelope"></i> Change Email Address</h3>
              <form method="POST">
                <input type="hidden" name="action" value="update_email">
                <div class="form-group">
                  <label for="new_email">New Email Address</label>
                  <input type="email" id="new_email" name="new_email" class="form-control" placeholder="Enter new email"
                    required>
                </div>
                <div class="form-group">
                  <label for="email_password">Current Password</label>
                  <input type="password" id="email_password" name="password" class="form-control"
                    placeholder="Enter your password to confirm" required>
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-save"></i> Update Email
                </button>
              </form>
            </div>

            <!-- Change Password -->
            <div class="form-card glass-card">
              <h3><i class="fas fa-key"></i> Change Password</h3>
              <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                  <label for="current_password">Current Password</label>
                  <input type="password" id="current_password" name="current_password" class="form-control"
                    placeholder="Enter current password" required>
                </div>
                <div class="form-group">
                  <label for="new_password">New Password</label>
                  <input type="password" id="new_password" name="new_password" class="form-control"
                    placeholder="Enter new password (min 8 characters)" required minlength="8">
                </div>
                <div class="form-group">
                  <label for="confirm_password">Confirm New Password</label>
                  <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                    placeholder="Confirm new password" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-lock"></i> Change Password
                </button>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <!-- Maintenance Tab -->
        <?php if ($activeTab === 'maintenance'): ?>
          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-database"></i> System Statistics</h2>
              <p>Overview of database records</p>
            </div>

            <div class="stats-grid maintenance-stats">
              <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                  <h3><?php echo number_format($systemStats['total_users']); ?></h3>
                  <p>Total Users</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-building"></i></div>
                <div class="stat-content">
                  <h3><?php echo number_format($systemStats['total_companies']); ?></h3>
                  <p>Companies</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
                <div class="stat-content">
                  <h3><?php echo number_format($systemStats['total_jobs']); ?></h3>
                  <p>Jobs</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-content">
                  <h3><?php echo number_format($systemStats['total_applications']); ?></h3>
                  <p>Applications</p>
                </div>
              </div>
            </div>
          </div>

          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-broom"></i> Cleanup Actions</h2>
              <p>Maintenance tasks to keep your system clean</p>
            </div>

            <div class="maintenance-actions">
              <!-- Expire Old Jobs -->
              <div class="action-card glass-card">
                <div class="action-info">
                  <div class="action-icon warning">
                    <i class="fas fa-hourglass-end"></i>
                  </div>
                  <div class="action-details">
                    <h4>Mark Expired Jobs</h4>
                    <p>Mark jobs past their deadline as expired. Found
                      <strong><?php echo $systemStats['expired_jobs']; ?></strong> jobs to update.
                    </p>
                  </div>
                </div>
                <form method="POST" onsubmit="return confirm('Are you sure you want to mark expired jobs?')">
                  <input type="hidden" name="action" value="clear_expired_jobs">
                  <button type="submit" class="btn btn-warning" <?php echo $systemStats['expired_jobs'] == 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-clock"></i> Mark Expired
                  </button>
                </form>
              </div>

              <!-- Clean Up Applications -->
              <div class="action-card glass-card">
                <div class="action-info">
                  <div class="action-icon info">
                    <i class="fas fa-archive"></i>
                  </div>
                  <div class="action-details">
                    <h4>Clean Up Stale Applications</h4>
                    <p>Mark applications older than 90 days as withdrawn. Found
                      <strong><?php echo $systemStats['stale_applications']; ?></strong> stale applications.
                    </p>
                  </div>
                </div>
                <form method="POST" onsubmit="return confirm('Are you sure you want to clean up stale applications?')">
                  <input type="hidden" name="action" value="cleanup_applications">
                  <button type="submit" class="btn btn-info" <?php echo $systemStats['stale_applications'] == 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-broom"></i> Clean Up
                  </button>
                </form>
              </div>

              <!-- Clear Old Sessions -->
              <div class="action-card glass-card">
                <div class="action-info">
                  <div class="action-icon secondary">
                    <i class="fas fa-user-clock"></i>
                  </div>
                  <div class="action-details">
                    <h4>Clear Old Session Tokens</h4>
                    <p>Remove remember-me tokens from inactive users (30+ days). Improves security.</p>
                  </div>
                </div>
                <form method="POST" onsubmit="return confirm('Are you sure you want to clear old session tokens?')">
                  <input type="hidden" name="action" value="clear_old_sessions">
                  <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-eraser"></i> Clear Tokens
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div class="settings-section">
            <div class="section-header">
              <h2><i class="fas fa-server"></i> Server Information</h2>
              <p>Technical details about your server</p>
            </div>

            <div class="info-card glass-card">
              <div class="info-grid">
                <div class="info-item">
                  <label>PHP Version</label>
                  <span class="info-value"><?php echo phpversion(); ?></span>
                </div>
                <div class="info-item">
                  <label>Server Software</label>
                  <span class="info-value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></span>
                </div>
                <div class="info-item">
                  <label>Database</label>
                  <span class="info-value">MySQL <?php echo $db->getAttribute(PDO::ATTR_SERVER_VERSION); ?></span>
                </div>
                <div class="info-item">
                  <label>Document Root</label>
                  <span class="info-value"><?php echo $_SERVER['DOCUMENT_ROOT']; ?></span>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- About Tab -->
        <?php if ($activeTab === 'about'): ?>
          <div class="settings-section">
            <div class="about-header">
              <div class="about-logo">
                <span class="logo-text">Job<span class="accent">Nexus</span></span>
              </div>
              <p class="about-tagline"><?php echo SITE_TAGLINE; ?></p>
            </div>

            <div class="info-card glass-card about-card">
              <h3><i class="fas fa-info-circle"></i> About JobNexus</h3>
              <p>
                JobNexus is a premium job portal platform designed to connect talented professionals
                with their dream careers. Our platform provides powerful tools for job seekers,
                recruiters, and administrators to streamline the hiring process.
              </p>

              <div class="feature-list">
                <h4>Key Features:</h4>
                <ul>
                  <li><i class="fas fa-check"></i> Advanced job search and filtering</li>
                  <li><i class="fas fa-check"></i> Company verification system</li>
                  <li><i class="fas fa-check"></i> Application tracking</li>
                  <li><i class="fas fa-check"></i> Interview scheduling</li>
                  <li><i class="fas fa-check"></i> Resume builder</li>
                  <li><i class="fas fa-check"></i> Analytics dashboard</li>
                </ul>
              </div>

              <div class="version-info">
                <span class="version-badge">Version 1.0.0</span>
                <span class="copyright">© <?php echo date('Y'); ?> JobNexus. All rights reserved.</span>
              </div>
            </div>

            <div class="info-card glass-card">
              <h3><i class="fas fa-code"></i> Technology Stack</h3>
              <div class="tech-stack">
                <div class="tech-item">
                  <i class="fab fa-php"></i>
                  <span>PHP 8+</span>
                </div>
                <div class="tech-item">
                  <i class="fas fa-database"></i>
                  <span>MySQL</span>
                </div>
                <div class="tech-item">
                  <i class="fab fa-html5"></i>
                  <span>HTML5</span>
                </div>
                <div class="tech-item">
                  <i class="fab fa-css3-alt"></i>
                  <span>CSS3</span>
                </div>
                <div class="tech-item">
                  <i class="fab fa-js"></i>
                  <span>JavaScript</span>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<style>
  /* Settings Container */
  .settings-container {
    display: flex;
    gap: 2rem;
  }

  /* Tabs */
  .settings-tabs {
    width: 220px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  .tab-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    text-decoration: none;
    transition: all var(--transition-fast);
    font-weight: 500;
  }

  .tab-link:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
    border-color: var(--border-light);
  }

  .tab-link.active {
    background: rgba(0, 230, 118, 0.1);
    border-color: var(--accent-primary);
    color: var(--accent-primary);
  }

  .tab-link i {
    font-size: 1rem;
    width: 20px;
    text-align: center;
  }

  /* Settings Content */
  .settings-content {
    flex: 1;
    min-width: 0;
  }

  .settings-section {
    margin-bottom: 2.5rem;
  }

  .section-header {
    margin-bottom: 1.5rem;
  }

  .section-header h2 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.35rem;
    font-family: var(--font-heading);
    color: var(--text-primary);
    margin-bottom: 0.5rem;
  }

  .section-header h2 i {
    color: var(--accent-primary);
  }

  .section-header p {
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  /* Info Card */
  .info-card {
    padding: 1.5rem;
    margin-bottom: 1.5rem;
  }

  .info-card h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color);
  }

  .info-card h3 i {
    color: var(--accent-primary);
  }

  .info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
  }

  .info-item {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
  }

  .info-item label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .info-value {
    color: var(--text-primary);
    font-size: 0.95rem;
    font-weight: 500;
  }

  .info-note {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 1.25rem;
    padding: 1rem;
    background: rgba(64, 196, 255, 0.1);
    border-radius: var(--radius-md);
    color: var(--info);
    font-size: 0.85rem;
  }

  .info-note code {
    background: rgba(0, 0, 0, 0.3);
    padding: 0.2rem 0.5rem;
    border-radius: var(--radius-sm);
    font-family: monospace;
  }

  /* Form Card */
  .form-card {
    padding: 1.5rem;
    margin-bottom: 1.5rem;
  }

  .form-card h3 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color);
  }

  .form-card h3 i {
    color: var(--accent-primary);
  }

  .form-group {
    margin-bottom: 1.25rem;
  }

  .form-group label {
    display: block;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
  }

  .form-control {
    width: 100%;
    max-width: 400px;
    padding: 0.75rem 1rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all var(--transition-fast);
  }

  .form-control:focus {
    outline: none;
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px rgba(0, 230, 118, 0.1);
  }

  .badge-admin {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: rgba(156, 39, 176, 0.15);
    color: #ce93d8;
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    font-weight: 500;
  }

  /* Maintenance Stats */
  .maintenance-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
  }

  .maintenance-stats .stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 1rem;
  }

  .maintenance-stats .stat-card::before {
    display: none;
  }

  .maintenance-stats .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-md);
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .maintenance-stats .stat-content {
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .maintenance-stats .stat-content h3 {
    font-size: 1.75rem;
    font-family: var(--font-heading);
    margin: 0 0 0.25rem;
    color: var(--text-primary);
  }

  .maintenance-stats .stat-content p {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin: 0;
  }

  /* Maintenance Actions */
  .maintenance-actions {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .action-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
  }

  .action-info {
    display: flex;
    align-items: center;
    gap: 1.25rem;
  }

  .action-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .action-icon.warning {
    background: rgba(255, 171, 64, 0.15);
    color: var(--warning);
  }

  .action-icon.info {
    background: rgba(64, 196, 255, 0.15);
    color: var(--info);
  }

  .action-icon.secondary {
    background: rgba(158, 158, 158, 0.15);
    color: #9e9e9e;
  }

  .action-details h4 {
    color: var(--text-primary);
    margin: 0 0 0.25rem;
    font-size: 1rem;
  }

  .action-details p {
    color: var(--text-muted);
    margin: 0;
    font-size: 0.85rem;
  }

  .action-details strong {
    color: var(--accent-primary);
  }

  .btn-warning {
    background: rgba(255, 171, 64, 0.15);
    color: var(--warning);
    border: 1px solid rgba(255, 171, 64, 0.3);
  }

  .btn-warning:hover:not(:disabled) {
    background: var(--warning);
    color: var(--text-inverse);
  }

  .btn-info {
    background: rgba(64, 196, 255, 0.15);
    color: var(--info);
    border: 1px solid rgba(64, 196, 255, 0.3);
  }

  .btn-info:hover:not(:disabled) {
    background: var(--info);
    color: var(--text-inverse);
  }

  .btn-secondary {
    background: rgba(158, 158, 158, 0.15);
    color: #9e9e9e;
    border: 1px solid rgba(158, 158, 158, 0.3);
  }

  .btn-secondary:hover:not(:disabled) {
    background: #9e9e9e;
    color: var(--text-inverse);
  }

  .btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* About Tab */
  .about-header {
    text-align: center;
    margin-bottom: 2rem;
  }

  .about-logo {
    margin-bottom: 0.75rem;
  }

  .logo-text {
    font-family: var(--font-heading);
    font-size: 3rem;
    color: var(--text-primary);
    letter-spacing: 0.05em;
  }

  .logo-text .accent {
    color: var(--accent-primary);
  }

  .about-tagline {
    color: var(--text-muted);
    font-size: 1.1rem;
  }

  .about-card p {
    color: var(--text-secondary);
    line-height: 1.7;
    margin-bottom: 1.5rem;
  }

  .feature-list h4 {
    color: var(--text-primary);
    margin-bottom: 1rem;
    font-size: 0.95rem;
  }

  .feature-list ul {
    list-style: none;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem;
  }

  .feature-list li {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
  }

  .feature-list li i {
    color: var(--accent-primary);
    font-size: 0.8rem;
  }

  .version-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
  }

  .version-badge {
    padding: 0.35rem 0.75rem;
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    font-weight: 500;
  }

  .copyright {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .tech-stack {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
  }

  .tech-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-secondary);
    font-size: 0.9rem;
  }

  .tech-item i {
    font-size: 1.25rem;
    color: var(--accent-primary);
  }

  /* Alert */
  .alert {
    padding: 1rem 1.25rem;
    border-radius: var(--radius-md);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .alert-success {
    background: rgba(0, 230, 118, 0.15);
    border: 1px solid rgba(0, 230, 118, 0.25);
    color: var(--accent-primary);
  }

  .alert-error {
    background: rgba(255, 82, 82, 0.15);
    border: 1px solid rgba(255, 82, 82, 0.25);
    color: var(--error);
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .settings-container {
      flex-direction: column;
    }

    .settings-tabs {
      width: 100%;
      flex-direction: row;
      flex-wrap: wrap;
    }

    .tab-link {
      flex: 1;
      min-width: 120px;
      justify-content: center;
    }
  }

  @media (max-width: 768px) {
    .action-card {
      flex-direction: column;
      gap: 1rem;
      text-align: center;
    }

    .action-info {
      flex-direction: column;
    }

    .version-info {
      flex-direction: column;
      gap: 0.75rem;
      text-align: center;
    }
  }
</style>

<?php include '../includes/footer.php'; ?>