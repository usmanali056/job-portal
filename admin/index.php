<?php
/**
 * JobNexus - Admin Dashboard
 * Central control panel for platform administrators
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Job.php';
require_once '../classes/Company.php';
require_once '../classes/Application.php';

// Check authentication and role
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_ADMIN) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=admin');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User();
$jobModel = new Job();
$companyModel = new Company();
$applicationModel = new Application();

// Get current admin info
$admin = $userModel->findById($_SESSION['user_id']);

// Dashboard Statistics
// Total Users
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role != '" . ROLE_ADMIN . "'");
$totalUsers = $stmt->fetch()['total'];

// Total Seekers
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = '" . ROLE_SEEKER . "'");
$totalSeekers = $stmt->fetch()['total'];

// Total HR/Recruiters
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = '" . ROLE_HR . "'");
$totalHR = $stmt->fetch()['total'];

// Pending HR Verifications (based on company verification status)
$stmt = $db->query("SELECT COUNT(*) as total FROM companies WHERE verification_status = 'pending'");
$pendingVerifications = $stmt->fetch()['total'];

// Total Jobs
$stmt = $db->query("SELECT COUNT(*) as total FROM jobs");
$totalJobs = $stmt->fetch()['total'];

// Active Jobs
$stmt = $db->query("SELECT COUNT(*) as total FROM jobs WHERE status = 'active' AND (application_deadline IS NULL OR application_deadline >= CURDATE())");
$activeJobs = $stmt->fetch()['total'];

// Total Applications
$stmt = $db->query("SELECT COUNT(*) as total FROM applications");
$totalApplications = $stmt->fetch()['total'];

// Total Companies
$stmt = $db->query("SELECT COUNT(*) as total FROM companies");
$totalCompanies = $stmt->fetch()['total'];

// Verified Companies
$stmt = $db->query("SELECT COUNT(*) as total FROM companies WHERE verification_status = 'verified'");
$verifiedCompanies = $stmt->fetch()['total'];

// New Users This Week
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$newUsersWeek = $stmt->fetch()['total'];

// New Applications This Week
$stmt = $db->query("SELECT COUNT(*) as total FROM applications WHERE applied_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$newAppsWeek = $stmt->fetch()['total'];

// Pending Company Verifications
$stmt = $db->query("
    SELECT c.*, u.email as hr_email 
    FROM companies c 
    JOIN users u ON c.hr_user_id = u.id 
    WHERE c.verification_status = 'pending' 
    ORDER BY c.created_at DESC 
    LIMIT 10
");
$pendingHRRequests = $stmt->fetchAll();

// Recent Users
$stmt = $db->query("
    SELECT u.*, 
           CASE 
               WHEN u.role = 'admin' THEN 'Admin'
               WHEN u.role = 'hr' THEN 'HR'
               WHEN u.role = 'seeker' THEN 'Seeker'
           END as role_name
    FROM users u 
    ORDER BY u.created_at DESC 
    LIMIT 10
");
$recentUsers = $stmt->fetchAll();

// Recent Jobs
$stmt = $db->query("
    SELECT j.*, c.company_name 
    FROM jobs j 
    LEFT JOIN companies c ON j.company_id = c.id 
    ORDER BY j.created_at DESC 
    LIMIT 10
");
$recentJobs = $stmt->fetchAll();

// Application Stats by Status
$stmt = $db->query("
    SELECT status, COUNT(*) as count 
    FROM applications 
    GROUP BY status
");
$appStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle Actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'verify_company' || $action === 'verify_hr') {
    $companyId = (int) ($_POST['company_id'] ?? 0);

    // If user_id is passed instead, get the company for that HR user
    if (!$companyId && isset($_POST['user_id'])) {
      $userId = (int) $_POST['user_id'];
      $stmt = $db->prepare("SELECT id FROM companies WHERE hr_user_id = ?");
      $stmt->execute([$userId]);
      $companyRow = $stmt->fetch();
      $companyId = $companyRow ? $companyRow['id'] : 0;
    }

    if ($companyId) {
      $stmt = $db->prepare("UPDATE companies SET verification_status = 'verified', verified_at = NOW() WHERE id = ?");
      if ($stmt->execute([$companyId])) {
        // Also verify the HR user (hr_user_id is in companies table, not company_id in users)
        $stmt = $db->prepare("UPDATE users SET is_verified = 1 WHERE id IN (SELECT hr_user_id FROM companies WHERE id = ?)");
        $stmt->execute([$companyId]);

        $message = 'Company verified successfully!';
        $messageType = 'success';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?verified=1');
        exit;
      }
    }
  } elseif ($action === 'reject_company' || $action === 'reject_hr') {
    $companyId = (int) ($_POST['company_id'] ?? 0);
    $reason = sanitize($_POST['reason'] ?? 'Application rejected by administrator');

    // If user_id is passed instead, get the company for that HR user
    if (!$companyId && isset($_POST['user_id'])) {
      $userId = (int) $_POST['user_id'];
      $stmt = $db->prepare("SELECT id FROM companies WHERE hr_user_id = ?");
      $stmt->execute([$userId]);
      $companyRow = $stmt->fetch();
      $companyId = $companyRow ? $companyRow['id'] : 0;
    }

    if ($companyId) {
      $stmt = $db->prepare("UPDATE companies SET verification_status = 'rejected', rejection_reason = ? WHERE id = ?");
      if ($stmt->execute([$reason, $companyId])) {
        $message = 'Company application rejected.';
        $messageType = 'warning';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?rejected=1');
        exit;
      }
    }
  } elseif ($action === 'feature_company') {
    $companyId = (int) $_POST['company_id'];
    $stmt = $db->prepare("UPDATE companies SET is_featured = 1 WHERE id = ?");
    $stmt->execute([$companyId]);
    $message = 'Company featured successfully!';
    $messageType = 'success';
  } elseif ($action === 'delete_user') {
    $userId = (int) $_POST['user_id'];
    if ($userId !== $_SESSION['user_id']) {
      $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
      if ($stmt->execute([$userId])) {
        $message = 'User deleted successfully.';
        $messageType = 'success';
      }
    }
  } elseif ($action === 'delete_job') {
    $jobId = (int) $_POST['job_id'];
    $stmt = $db->prepare("DELETE FROM jobs WHERE id = ?");
    if ($stmt->execute([$jobId])) {
      $message = 'Job deleted successfully.';
      $messageType = 'success';
    }
  }
}

// Check for query params
if (isset($_GET['verified'])) {
  $message = 'HR account verified successfully!';
  $messageType = 'success';
} elseif (isset($_GET['rejected'])) {
  $message = 'HR account rejected.';
  $messageType = 'warning';
}

$pageTitle = 'Admin Dashboard';
require_once '../includes/header.php';
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
      <a href="<?php echo BASE_URL; ?>/admin/index.php" class="nav-item active">
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
      <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="nav-item">
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
        <h1>Admin Dashboard</h1>
        <p>Welcome back, <?php echo htmlspecialchars($admin['email'] ?? 'Admin'); ?>!</p>
      </div>
      <div class="header-right">
        <span class="date-display">
          <i class="fas fa-calendar-alt"></i>
          <?php echo date('l, F j, Y'); ?>
        </span>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
      <div class="stat-card primary">
        <div class="stat-icon">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalUsers); ?></h3>
          <p>Total Users</p>
        </div>
        <div class="stat-footer">
          <span class="stat-change positive">
            <i class="fas fa-arrow-up"></i> <?php echo $newUsersWeek; ?> this week
          </span>
        </div>
      </div>

      <div class="stat-card success">
        <div class="stat-icon">
          <i class="fas fa-user-tie"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalSeekers); ?></h3>
          <p>Job Seekers</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label">Active seekers</span>
        </div>
      </div>

      <div class="stat-card info">
        <div class="stat-icon">
          <i class="fas fa-user-cog"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalHR); ?></h3>
          <p>HR/Recruiters</p>
        </div>
        <div class="stat-footer">
          <?php if ($pendingVerifications > 0): ?>
            <span class="stat-change warning">
              <i class="fas fa-clock"></i> <?php echo $pendingVerifications; ?> pending
            </span>
          <?php else: ?>
            <span class="stat-label">All verified</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="stat-card warning">
        <div class="stat-icon">
          <i class="fas fa-briefcase"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalJobs); ?></h3>
          <p>Total Jobs</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label"><?php echo $activeJobs; ?> active</span>
        </div>
      </div>

      <div class="stat-card secondary">
        <div class="stat-icon">
          <i class="fas fa-file-alt"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalApplications); ?></h3>
          <p>Applications</p>
        </div>
        <div class="stat-footer">
          <span class="stat-change positive">
            <i class="fas fa-arrow-up"></i> <?php echo $newAppsWeek; ?> this week
          </span>
        </div>
      </div>

      <div class="stat-card purple">
        <div class="stat-icon">
          <i class="fas fa-building"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalCompanies); ?></h3>
          <p>Companies</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label"><?php echo $verifiedCompanies; ?> verified</span>
        </div>
      </div>
    </div>

    <!-- Verification Center -->
    <?php if (count($pendingHRRequests) > 0): ?>
      <div class="dashboard-section verification-center">
        <div class="section-header">
          <h2><i class="fas fa-user-check"></i> Pending HR Verifications</h2>
          <span class="badge-count"><?php echo count($pendingHRRequests); ?> pending</span>
        </div>

        <div class="verification-cards">
          <?php foreach ($pendingHRRequests as $request): ?>
            <div class="verification-card">
              <div class="verification-header">
                <div class="company-logo-placeholder">
                  <?php if (!empty($request['logo'])): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo htmlspecialchars($request['logo']); ?>" alt="Logo">
                  <?php else: ?>
                    <i class="fas fa-building"></i>
                  <?php endif; ?>
                </div>
                <div class="company-info">
                  <h4><?php echo htmlspecialchars($request['company_name'] ?? 'Unknown Company'); ?></h4>
                  <p class="hr-email"><i class="fas fa-user"></i> <?php echo htmlspecialchars($request['hr_email'] ?? ''); ?></p>
                </div>
                <span class="pending-badge">
                  <i class="fas fa-clock"></i> Pending
                </span>
              </div>

              <div class="verification-details">
                <div class="detail-row">
                  <div class="detail-item">
                    <i class="fas fa-industry"></i>
                    <span><strong>Industry:</strong> <?php echo htmlspecialchars($request['industry'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="detail-item">
                      <i class="fas fa-users"></i>
                      <span><strong>Size:</strong> <?php echo htmlspecialchars($request['company_size'] ?? 'Not specified'); ?></span>
                    </div>
                    </div>
                    <div class="detail-row">
                      <div class="detail-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><strong>Location:</strong> <?php echo htmlspecialchars($request['headquarters'] ?? 'Not specified'); ?></span>
                      </div>
                      <div class="detail-item">
                        <i class="fas fa-globe"></i>
                        <span><strong>Website:</strong>
                          <?php if (!empty($request['website'])): ?>
                            <a href="<?php echo htmlspecialchars($request['website']); ?>"
                              target="_blank"><?php echo htmlspecialchars($request['website']); ?></a>
                          <?php else: ?>
                            Not provided
                          <?php endif; ?>
                        </span>
                      </div>
                    </div>
                    <div class="detail-row">
                      <div class="detail-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span><strong>Registered:</strong>
                          <?php echo date('M j, Y \a\t g:i A', strtotime($request['created_at'])); ?></span>
                      </div>
                      <div class="detail-item">
                        <i class="fas fa-envelope"></i>
                        <span><strong>Email:</strong>
                          <?php echo htmlspecialchars($request['email'] ?? $request['hr_email'] ?? 'N/A'); ?></span>
                      </div>
                    </div>
                <?php if (!empty($request['description'])): ?>
                <div class="detail-row full-width">
                    <div class="detail-item">
                      <i class="fas fa-info-circle"></i>
                      <span><strong>About:</strong>
                        <?php echo htmlspecialchars(substr($request['description'], 0, 200)); ?>
                        <?php echo strlen($request['description']) > 200 ? '...' : ''; ?></span>
                      </div>
                  </div>
                <?php endif; ?>
                </div>

              <div class="verification-actions">
                <form method="POST" class="action-form verify-form">
                  <input type="hidden" name="action" value="verify_company">
                  <input type="hidden" name="company_id" value="<?php echo $request['id']; ?>">
                  <button type="submit" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Verify Company
                  </button>
                </form>
                <button type="button" class="btn btn-outline-danger" onclick="showRejectModal(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars(addslashes($request['company_name'] ?? '')); ?>')">
                  <i class="fas fa-times-circle"></i> Reject
                </button>
                <a href="<?php echo BASE_URL; ?>/companies/profile.php?id=<?php echo $request['id']; ?>" target="_blank"
                  class="btn btn-outline-secondary">
                  <i class="fas fa-external-link-alt"></i> View Profile
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
    <div class="dashboard-section verification-center">
      <div class="section-header">
        <h2><i class="fas fa-user-check"></i> Pending HR Verifications</h2>
      </div>
      <div class="empty-state-small">
        <i class="fas fa-check-double"></i>
        <p>No pending verification requests. All caught up!</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Reject Modal -->
    <div class="modal-overlay" id="rejectModal" style="display: none;">
      <div class="modal-box">
        <div class="modal-header">
          <h3><i class="fas fa-times-circle"></i> Reject Company</h3>
          <button type="button" class="modal-close" onclick="hideRejectModal()">&times;</button>
        </div>
        <form method="POST" id="rejectForm">
          <input type="hidden" name="action" value="reject_company">
          <input type="hidden" name="company_id" id="reject_company_id" value="">
          <div class="modal-body">
            <p>You are about to reject: <strong id="reject_company_name"></strong></p>
            <div class="form-group">
              <label for="reason">Reason for Rejection <span class="required">*</span></label>
              <textarea name="reason" id="reason" rows="4" class="form-control" required placeholder="Please provide a reason for rejecting this company registration..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideRejectModal()">Cancel</button>
            <button type="submit" class="btn btn-danger">
              <i class="fas fa-times"></i> Reject Company
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Dashboard Panels -->
    <div class="dashboard-panels">
      <!-- Recent Users Panel -->
      <div class="dashboard-panel">
        <div class="panel-header">
          <h2><i class="fas fa-user-plus"></i> Recent Users</h2>
          <a href="<?php echo BASE_URL; ?>/admin/users.php" class="btn btn-text">
            View All <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <div class="panel-content">
          <table class="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentUsers as $user): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm">
                        <?php echo strtoupper(substr($user['email'], 0, 1)); ?>
                      </div>
                      <?php echo htmlspecialchars($user['email']); ?>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td>
                    <span class="role-badge <?php echo strtolower($user['role_name']); ?>">
                      <?php echo $user['role_name']; ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($user['is_verified']): ?>
                      <span class="status-badge verified">Verified</span>
                    <?php else: ?>
                      <span class="status-badge pending">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent Jobs Panel -->
      <div class="dashboard-panel">
        <div class="panel-header">
          <h2><i class="fas fa-briefcase"></i> Recent Jobs</h2>
          <a href="<?php echo BASE_URL; ?>/admin/jobs.php" class="btn btn-text">
            View All <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <div class="panel-content">
          <table class="data-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Company</th>
                <th>Type</th>
                <th>Status</th>
                <th>Posted</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentJobs as $job): ?>
                <tr>
                  <td>
                    <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>" class="job-link">
                      <?php echo htmlspecialchars($job['title']); ?>
                    </a>
                  </td>
                  <td><?php echo htmlspecialchars($job['company_name']); ?></td>
                  <td>
                    <span class="job-type-badge <?php echo $job['job_type']; ?>">
                      <?php echo ucfirst(str_replace('-', ' ', $job['job_type'])); ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge <?php echo $job['status']; ?>">
                      <?php echo ucfirst($job['status']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($job['created_at'])); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Application Stats -->
    <div class="dashboard-section">
      <div class="section-header">
        <h2><i class="fas fa-chart-pie"></i> Application Statistics</h2>
      </div>

      <div class="stats-bars">
        <?php
        $statuses = [
          'pending' => ['label' => 'Pending', 'icon' => 'clock', 'color' => 'warning'],
          'reviewed' => ['label' => 'Reviewed', 'icon' => 'eye', 'color' => 'info'],
          'shortlisted' => ['label' => 'Shortlisted', 'icon' => 'star', 'color' => 'primary'],
          'interview' => ['label' => 'Interview', 'icon' => 'calendar', 'color' => 'purple'],
          'offered' => ['label' => 'Offered', 'icon' => 'gift', 'color' => 'success'],
          'hired' => ['label' => 'Hired', 'icon' => 'check-circle', 'color' => 'success'],
          'rejected' => ['label' => 'Rejected', 'icon' => 'times-circle', 'color' => 'danger']
        ];

        $maxCount = max(array_values($appStats) ?: [1]);
        ?>

        <?php foreach ($statuses as $status => $info): ?>
          <?php $count = $appStats[$status] ?? 0; ?>
          <div class="stat-bar-item">
            <div class="stat-bar-header">
              <span class="stat-bar-label">
                <i class="fas fa-<?php echo $info['icon']; ?>"></i>
                <?php echo $info['label']; ?>
              </span>
              <span class="stat-bar-value"><?php echo number_format($count); ?></span>
            </div>
            <div class="stat-bar-track">
              <div class="stat-bar-fill <?php echo $info['color']; ?>"
                style="width: <?php echo $maxCount > 0 ? ($count / $maxCount) * 100 : 0; ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="dashboard-section">
      <div class="section-header">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
      </div>

      <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>/admin/users.php?action=add" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-user-plus"></i>
          </div>
          <span>Add User</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/admin/companies.php?action=add" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-building"></i>
          </div>
          <span>Add Company</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/admin/verifications.php" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-check-double"></i>
          </div>
          <span>Verify HRs</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-download"></i>
          </div>
          <span>Export Data</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-cog"></i>
          </div>
          <span>Settings</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/admin/logs.php" class="quick-action-card">
          <div class="action-icon">
            <i class="fas fa-history"></i>
          </div>
          <span>Activity Logs</span>
        </a>
      </div>
    </div>
  </main>
</div>

<style>
  /* Dashboard Layout */
  .dashboard-container {
    display: flex;
    min-height: calc(100vh - 70px);
    margin-top: 70px;
  }

  /* Sidebar */
  .dashboard-sidebar {
    width: 280px;
    background: linear-gradient(180deg, #121212 0%, #0a0a0a 50%, #121212 100%);
    border-right: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    flex-direction: column;
    position: fixed;
    height: calc(100vh - 70px);
    overflow-y: auto;
  }

  .sidebar-header {
    padding: 2rem;
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    background: rgba(0, 0, 0, 0.3);
  }

  .admin-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 2rem;
    color: var(--bg-dark);
  }

  .sidebar-header h3 {
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
  }

  .role-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
  }

  .role-badge.admin {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: var(--bg-dark);
  }

  .role-badge.hr {
    background: rgba(33, 150, 243, 0.2);
    color: #2196F3;
  }

  .role-badge.seeker {
    background: rgba(156, 39, 176, 0.2);
    color: #9C27B0;
  }

  .sidebar-nav {
    flex: 1;
    padding: 1rem 0;
  }

  .nav-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 2rem;
    color: var(--text-muted);
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .nav-item:hover {
    background: rgba(0, 230, 118, 0.05);
    color: var(--text-light);
  }

  .nav-item.active {
    background: rgba(0, 230, 118, 0.1);
    color: var(--primary-color);
  }

  .nav-item i {
    width: 20px;
    text-align: center;
  }

  .nav-item .badge {
    margin-left: auto;
    background: var(--danger);
    color: white;
    padding: 0.2rem 0.5rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
  }

  .sidebar-footer {
    padding: 1rem 2rem;
    border-top: 1px solid var(--border-color);
  }

  .logout-btn {
    display: flex;
    align-items: center;
    gap: 1rem;
    color: var(--danger);
    text-decoration: none;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    transition: all 0.3s ease;
  }

  .logout-btn:hover {
    background: rgba(244, 67, 54, 0.1);
  }

  /* Main Content */
  .dashboard-main {
    flex: 1;
    margin-left: 280px;
    padding: 2rem;
    background: var(--bg-dark);
  }

  .dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
  }

  .dashboard-header h1 {
    font-family: var(--font-heading);
    font-size: 2rem;
    margin-bottom: 0.25rem;
  }

  .dashboard-header p {
    color: var(--text-muted);
  }

  .date-display {
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  .date-display i {
    margin-right: 0.5rem;
    color: var(--primary-color);
  }

  /* Stats Grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
  }

  .stat-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 1rem;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
  }

  .stat-card:hover {
    transform: translateY(-5px);
    border-color: rgba(0, 230, 118, 0.3);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  }

  .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .stat-content {
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .stat-card.primary .stat-icon {
    background: rgba(0, 230, 118, 0.15);
    color: var(--primary-color);
  }

  .stat-card.success .stat-icon {
    background: rgba(76, 175, 80, 0.15);
    color: #4CAF50;
  }

  .stat-card.info .stat-icon {
    background: rgba(33, 150, 243, 0.15);
    color: #2196F3;
  }

  .stat-card.warning .stat-icon {
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
  }

  .stat-card.secondary .stat-icon {
    background: rgba(158, 158, 158, 0.15);
    color: #9E9E9E;
  }

  .stat-card.purple .stat-icon {
    background: rgba(156, 39, 176, 0.15);
    color: #9C27B0;
  }

  .stat-content h3 {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
  }

  .stat-content p {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .stat-footer {
    width: 100%;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-color);
  }

  .stat-change {
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .stat-change.positive {
    color: var(--primary-color);
  }

  .stat-change.warning {
    color: var(--warning);
  }

  .stat-label {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  /* Dashboard Sections */
  .dashboard-section {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    border: 1px solid var(--border-color);
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
  }

  .section-header h2 {
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .section-header h2 i {
    color: var(--primary-color);
  }

  /* Verification Cards */
  .verification-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
  }

  .verification-card {
    background: var(--bg-dark);
    border-radius: 12px;
    padding: 1.25rem;
    border: 1px solid var(--border-color);
  }

  .verification-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .verification-header .user-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #2196F3, #1976D2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
  }

  .verification-header .user-info h4 {
    font-size: 1rem;
    margin-bottom: 0.25rem;
  }

  .verification-header .user-info p {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .verification-details {
    margin-bottom: 1rem;
  }

  .detail-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
  }

  .detail-item i {
    width: 16px;
    color: var(--primary-color);
  }

  .verification-actions {
    display: flex;
    gap: 0.5rem;
  }

  /* Dashboard Panels */
  .dashboard-panels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
  }

  .dashboard-panel {
    background: var(--card-bg);
    border-radius: 16px;
    border: 1px solid var(--border-color);
    overflow: hidden;
  }

  .panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .panel-header h2 {
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .panel-header h2 i {
    color: var(--primary-color);
  }

  .btn-text {
    color: var(--primary-color);
    background: none;
    border: none;
    font-size: 0.9rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
  }

  .btn-text:hover {
    color: var(--primary-light);
  }

  .panel-content {
    padding: 0;
    overflow-x: auto;
  }

  /* Data Table */
  .data-table {
    width: 100%;
    border-collapse: collapse;
  }

  .data-table th,
  .data-table td {
    padding: 1rem 1.5rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
  }

  .data-table th {
    background: rgba(0, 0, 0, 0.2);
    color: var(--text-muted);
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .data-table tbody tr:hover {
    background: rgba(0, 230, 118, 0.02);
  }

  .data-table tbody tr:last-child td {
    border-bottom: none;
  }

  .user-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .avatar-sm {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bg-dark);
    font-weight: 600;
    font-size: 0.85rem;
  }

  .job-link {
    color: var(--text-light);
    text-decoration: none;
    transition: color 0.3s ease;
  }

  .job-link:hover {
    color: var(--primary-color);
  }

  .job-type-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: capitalize;
  }

  .job-type-badge.full-time {
    background: rgba(0, 230, 118, 0.1);
    color: var(--primary-color);
  }

  .job-type-badge.part-time {
    background: rgba(33, 150, 243, 0.1);
    color: #2196F3;
  }

  .job-type-badge.contract {
    background: rgba(255, 193, 7, 0.1);
    color: #FFC107;
  }

  .job-type-badge.internship {
    background: rgba(156, 39, 176, 0.1);
    color: #9C27B0;
  }

  .job-type-badge.remote {
    background: rgba(0, 188, 212, 0.1);
    color: #00BCD4;
  }

  .status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
  }

  .status-badge.verified,
  .status-badge.active {
    background: rgba(76, 175, 80, 0.1);
    color: #4CAF50;
  }

  .status-badge.pending {
    background: rgba(255, 193, 7, 0.1);
    color: #FFC107;
  }

  .status-badge.closed {
    background: rgba(244, 67, 54, 0.1);
    color: #F44336;
  }

  .status-badge.draft {
    background: rgba(158, 158, 158, 0.1);
    color: #9E9E9E;
  }

  /* Stats Bars */
  .stats-bars {
    display: grid;
    gap: 1rem;
  }

  .stat-bar-item {
    padding: 0.5rem 0;
  }

  .stat-bar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
  }

  .stat-bar-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  .stat-bar-label i {
    width: 16px;
  }

  .stat-bar-value {
    font-weight: 600;
    color: var(--text-light);
  }

  .stat-bar-track {
    height: 8px;
    background: var(--bg-dark);
    border-radius: 4px;
    overflow: hidden;
  }

  .stat-bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.5s ease;
  }

  .stat-bar-fill.primary {
    background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
  }

  .stat-bar-fill.success {
    background: linear-gradient(90deg, #4CAF50, #66BB6A);
  }

  .stat-bar-fill.info {
    background: linear-gradient(90deg, #2196F3, #42A5F5);
  }

  .stat-bar-fill.warning {
    background: linear-gradient(90deg, #FFC107, #FFCA28);
  }

  .stat-bar-fill.danger {
    background: linear-gradient(90deg, #F44336, #EF5350);
  }

  .stat-bar-fill.purple {
    background: linear-gradient(90deg, #9C27B0, #AB47BC);
  }

  /* Quick Actions */
  .quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
  }

  .quick-action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 1.5rem;
    background: var(--bg-dark);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    text-decoration: none;
    color: var(--text-light);
    transition: all 0.3s ease;
  }

  .quick-action-card:hover {
    border-color: var(--primary-color);
    transform: translateY(-3px);
    box-shadow: 0 5px 20px rgba(0, 230, 118, 0.1);
  }

  .quick-action-card .action-icon {
    width: 50px;
    height: 50px;
    background: rgba(0, 230, 118, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color);
    font-size: 1.25rem;
  }

  .quick-action-card:hover .action-icon {
    background: var(--primary-color);
    color: var(--bg-dark);
  }

  .quick-action-card span {
    font-size: 0.9rem;
    font-weight: 500;
  }

  /* Alerts */
  .alert {
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .alert i {
    font-size: 1.25rem;
  }

  .alert-success {
    background: rgba(76, 175, 80, 0.1);
    border: 1px solid rgba(76, 175, 80, 0.3);
    color: #4CAF50;
  }

  .alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border: 1px solid rgba(255, 193, 7, 0.3);
    color: #FFC107;
  }

  .alert-danger {
    background: rgba(244, 67, 54, 0.1);
    border: 1px solid rgba(244, 67, 54, 0.3);
    color: #F44336;
  }

  /* Responsive */
  @media (max-width: 1200px) {
    .dashboard-panels {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 992px) {
    .dashboard-sidebar {
      width: 70px;
      overflow: visible;
    }

    .sidebar-header h3,
    .sidebar-header .role-badge,
    .nav-item span,
    .logout-btn span {
      display: none;
    }

    .nav-item {
      justify-content: center;
      padding: 1rem;
    }

    .nav-item .badge {
      position: absolute;
      top: 5px;
      right: 10px;
    }

    .dashboard-main {
      margin-left: 70px;
    }

    .admin-avatar {
      width: 50px;
      height: 50px;
      font-size: 1.25rem;
    }
  }

  @media (max-width: 768px) {
    .dashboard-container {
      flex-direction: column;
    }

    .dashboard-sidebar {
      width: 100%;
      height: auto;
      position: relative;
      flex-direction: row;
      flex-wrap: wrap;
    }

    .sidebar-header {
      display: none;
    }

    .sidebar-nav {
      display: flex;
      overflow-x: auto;
      padding: 0.5rem;
    }

    .nav-item {
      padding: 0.75rem 1rem;
      border-left: none;
      border-bottom: 3px solid transparent;
    }

    .nav-item.active {
      border-left: none;
      border-bottom-color: var(--primary-color);
    }

    .sidebar-footer {
      display: none;
    }

    .dashboard-main {
      margin-left: 0;
      padding: 1rem;
    }

    .dashboard-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 1rem;
    }

    .stats-grid {
      grid-template-columns: 1fr;
    }

    .verification-cards {
      grid-template-columns: 1fr;
    }

    .quick-actions-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  /* Verification Card Enhanced Styles */
  .verification-center .badge-count {
    background: rgba(255, 193, 7, 0.2);
    color: #FFC107;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
  }

  .company-logo-placeholder {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #2196F3, #1976D2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    flex-shrink: 0;
  }

  .company-logo-placeholder img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 12px;
  }

  .verification-header {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
  }

  .verification-header .company-info {
    flex: 1;
    min-width: 150px;
  }

  .verification-header .company-info h4 {
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
    color: var(--text-light);
  }

  .verification-header .hr-email {
    color: var(--text-muted);
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .verification-header .hr-email i {
    color: var(--primary-color);
  }

  .pending-badge {
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
  }

  .verification-details .detail-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-bottom: 0.75rem;
  }

  .verification-details .detail-row.full-width {
    grid-template-columns: 1fr;
  }

  .verification-details .detail-item {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    color: var(--text-muted);
    font-size: 0.85rem;
    line-height: 1.4;
  }

  .verification-details .detail-item i {
    color: var(--primary-color);
    width: 16px;
    margin-top: 2px;
    flex-shrink: 0;
  }

  .verification-details .detail-item strong {
    color: var(--text-light);
  }

  .verification-details .detail-item a {
    color: var(--primary-color);
    text-decoration: none;
  }

  .verification-details .detail-item a:hover {
    text-decoration: underline;
  }

  .verification-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
  }

  .verification-actions .action-form {
    margin: 0;
  }

  .btn-success {
    background: linear-gradient(135deg, #4CAF50, #45a049);
    color: white;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
  }

  .btn-success:hover {
    background: linear-gradient(135deg, #45a049, #3d8b40);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
  }

  .btn-outline-danger {
    background: transparent;
    color: #F44336;
    border: 1px solid #F44336;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
  }

  .btn-outline-danger:hover {
    background: rgba(244, 67, 54, 0.1);
    transform: translateY(-2px);
  }

  .btn-outline-secondary {
    background: transparent;
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    padding: 0.6rem 1rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .btn-outline-secondary:hover {
    border-color: var(--text-light);
    color: var(--text-light);
    transform: translateY(-2px);
  }

  .empty-state-small {
    text-align: center;
    padding: 3rem;
    color: var(--text-muted);
  }

  .empty-state-small i {
    font-size: 3rem;
    color: var(--primary-color);
    margin-bottom: 1rem;
    display: block;
  }

  .empty-state-small p {
    font-size: 1rem;
  }

  /* Modal Styles */
  .modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 1rem;
    backdrop-filter: blur(4px);
  }

  .modal-box {
    background: var(--card-bg);
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    border: 1px solid var(--border-color);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .modal-header h3 {
    font-size: 1.15rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #F44336;
  }

  .modal-header h3 i {
    color: #F44336;
  }

  .modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
    padding: 0;
    transition: color 0.3s ease;
  }

  .modal-close:hover {
    color: var(--text-light);
  }

  .modal-body {
    padding: 1.5rem;
  }

  .modal-body p {
    margin-bottom: 1.25rem;
    color: var(--text-muted);
  }

  .modal-body p strong {
    color: var(--text-light);
  }

  .form-group {
    margin-bottom: 1rem;
  }

  .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--text-light);
  }

  .form-group label .required {
    color: #F44336;
  }

  .form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-dark);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-light);
    font-family: inherit;
    font-size: 0.95rem;
    resize: vertical;
    transition: all 0.3s ease;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 230, 118, 0.1);
  }

  .form-control::placeholder {
    color: var(--text-muted);
  }

  .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    background: rgba(0, 0, 0, 0.2);
    border-radius: 0 0 16px 16px;
  }

  .btn-secondary {
    background: var(--bg-dark);
    color: var(--text-light);
    border: 1px solid var(--border-color);
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
  }

  .btn-secondary:hover {
    background: rgba(255, 255, 255, 0.05);
  }

  .btn-danger {
    background: linear-gradient(135deg, #F44336, #d32f2f);
    color: white;
    border: none;
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
  }

  .btn-danger:hover {
    background: linear-gradient(135deg, #d32f2f, #c62828);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
  }
</style>

<script>
  // Modal Functions
  function showRejectModal(companyId, companyName) {
    document.getElementById('reject_company_id').value = companyId;
    document.getElementById('reject_company_name').textContent = companyName;
    document.getElementById('rejectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function hideRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.getElementById('reason').value = '';
    document.body.style.overflow = 'auto';
  }

  // Close modal on outside click
  document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
      hideRejectModal();
    }
  });

  // Close modal on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      hideRejectModal();
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>