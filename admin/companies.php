<?php
/**
 * JobNexus - Admin Companies Management
 * View and manage all registered companies
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Company.php';

// Check authentication and role
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_ADMIN) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=admin/companies');
  exit;
}

$db = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $companyId = (int) ($_POST['company_id'] ?? 0);

  if ($action === 'verify' && $companyId) {
    $stmt = $db->prepare("UPDATE companies SET verification_status = 'verified', verified_at = NOW() WHERE id = ?");
    if ($stmt->execute([$companyId])) {
      // Also verify the HR user
      $stmt = $db->prepare("UPDATE users SET is_verified = 1 WHERE id IN (SELECT hr_user_id FROM companies WHERE id = ?)");
      $stmt->execute([$companyId]);
      $message = 'Company verified successfully!';
      $messageType = 'success';
    }
  } elseif ($action === 'reject' && $companyId) {
    $reason = trim($_POST['reason'] ?? 'Rejected by administrator');
    $stmt = $db->prepare("UPDATE companies SET verification_status = 'rejected', rejection_reason = ? WHERE id = ?");
    if ($stmt->execute([$reason, $companyId])) {
      $message = 'Company rejected.';
      $messageType = 'warning';
    }
  } elseif ($action === 'feature' && $companyId) {
    $stmt = $db->prepare("UPDATE companies SET is_featured = 1 WHERE id = ?");
    if ($stmt->execute([$companyId])) {
      $message = 'Company featured successfully!';
      $messageType = 'success';
    }
  } elseif ($action === 'unfeature' && $companyId) {
    $stmt = $db->prepare("UPDATE companies SET is_featured = 0 WHERE id = ?");
    if ($stmt->execute([$companyId])) {
      $message = 'Company unfeatured.';
      $messageType = 'success';
    }
  } elseif ($action === 'delete' && $companyId) {
    $stmt = $db->prepare("DELETE FROM companies WHERE id = ?");
    if ($stmt->execute([$companyId])) {
      $message = 'Company deleted successfully.';
      $messageType = 'success';
    }
  }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$industryFilter = isset($_GET['industry']) ? trim($_GET['industry']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

if ($search) {
  $where[] = "(c.company_name LIKE ? OR c.email LIKE ? OR u.email LIKE ?)";
  $searchTerm = "%$search%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($statusFilter) {
  $where[] = "c.verification_status = ?";
  $params[] = $statusFilter;
}

if ($industryFilter) {
  $where[] = "c.industry = ?";
  $params[] = $industryFilter;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countSql = "SELECT COUNT(*) FROM companies c LEFT JOIN users u ON c.hr_user_id = u.id WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalCompanies = $countStmt->fetchColumn();
$totalPages = ceil($totalCompanies / $perPage);

// Get companies
$sql = "
    SELECT c.*, u.email as hr_email,
           (SELECT COUNT(*) FROM jobs WHERE company_id = c.id) as job_count,
           (SELECT COUNT(*) FROM jobs WHERE company_id = c.id AND status = 'active') as active_jobs
    FROM companies c
    LEFT JOIN users u ON c.hr_user_id = u.id
    WHERE $whereClause
    ORDER BY c.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured
    FROM companies
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get industries for filter
$industriesStmt = $db->query("SELECT DISTINCT industry FROM companies WHERE industry IS NOT NULL AND industry != '' ORDER BY industry");
$industries = $industriesStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Company Management - JobNexus Admin";
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
      <a href="<?php echo BASE_URL; ?>/admin/companies.php" class="nav-item active">
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
        <h1><i class="fas fa-building"></i> Company Management</h1>
        <p>Manage all registered companies and their verification status</p>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card primary">
        <div class="stat-icon">
          <i class="fas fa-building"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['total']); ?></h3>
          <p>Total Companies</p>
        </div>
      </div>
      <div class="stat-card success">
        <div class="stat-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['verified']); ?></h3>
          <p>Verified</p>
        </div>
      </div>
      <div class="stat-card warning">
        <div class="stat-icon">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['pending']); ?></h3>
          <p>Pending</p>
        </div>
      </div>
      <div class="stat-card info">
        <div class="stat-icon">
          <i class="fas fa-star"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['featured']); ?></h3>
          <p>Featured</p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-card glass-card">
      <form method="GET" class="filters-form">
        <div class="filter-group">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" placeholder="Search companies..."
            value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All Status</option>
            <option value="verified" <?php echo $statusFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
          </select>
        </div>
        <div class="filter-group">
          <label for="industry">Industry</label>
          <select id="industry" name="industry">
            <option value="">All Industries</option>
            <?php foreach ($industries as $industry): ?>
              <option value="<?php echo htmlspecialchars($industry); ?>" <?php echo $industryFilter === $industry ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($industry); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Filter
          </button>
          <?php if ($search || $statusFilter || $industryFilter): ?>
            <a href="<?php echo BASE_URL; ?>/admin/companies.php" class="btn btn-secondary">
              <i class="fas fa-times"></i> Clear
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Companies Table -->
    <div class="data-table-card glass-card">
      <div class="table-header">
        <h3><i class="fas fa-list"></i> Company Listings (<?php echo number_format($totalCompanies); ?>)</h3>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Company</th>
              <th>Industry</th>
              <th>HR Contact</th>
              <th>Jobs</th>
              <th>Status</th>
              <th>Registered</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($companies)): ?>
              <tr>
                <td colspan="7" class="empty-state">
                  <i class="fas fa-building"></i>
                  <p>No companies found</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($companies as $company): ?>
                <tr>
                  <td>
                    <div class="company-cell">
                      <div class="company-logo-small">
                        <?php if ($company['logo']): ?>
                          <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo htmlspecialchars($company['logo']); ?>"
                            alt="">
                        <?php else: ?>
                          <?php echo strtoupper(substr($company['company_name'], 0, 1)); ?>
                        <?php endif; ?>
                      </div>
                      <div class="company-info">
                        <strong><?php echo htmlspecialchars($company['company_name']); ?></strong>
                        <?php if ($company['is_featured']): ?>
                          <span class="featured-badge"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>
                        <span class="company-location">
                          <i class="fas fa-map-marker-alt"></i>
                          <?php echo htmlspecialchars($company['headquarters'] ?? 'N/A'); ?>
                        </span>
                      </div>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($company['industry'] ?? 'N/A'); ?></td>
                  <td>
                    <a href="mailto:<?php echo htmlspecialchars($company['hr_email']); ?>" class="email-link">
                      <?php echo htmlspecialchars($company['hr_email']); ?>
                    </a>
                  </td>
                  <td>
                    <span class="job-count">
                      <?php echo $company['active_jobs']; ?> / <?php echo $company['job_count']; ?>
                    </span>
                    <small>active/total</small>
                  </td>
                  <td>
                    <span class="status-badge <?php echo $company['verification_status']; ?>">
                      <?php echo ucfirst($company['verification_status']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($company['created_at'])); ?></td>
                  <td>
                    <div class="action-buttons">
                      <a href="<?php echo BASE_URL; ?>/companies/profile.php?id=<?php echo $company['id']; ?>"
                        class="btn-icon" title="View Profile" target="_blank">
                        <i class="fas fa-external-link-alt"></i>
                      </a>

                      <?php if ($company['verification_status'] === 'pending'): ?>
                        <form method="POST" style="display: inline;">
                          <input type="hidden" name="action" value="verify">
                          <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                          <button type="submit" class="btn-icon success" title="Verify">
                            <i class="fas fa-check"></i>
                          </button>
                        </form>
                        <button type="button" class="btn-icon danger" title="Reject"
                          onclick="showRejectModal(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars(addslashes($company['company_name'])); ?>')">
                          <i class="fas fa-times"></i>
                        </button>
                      <?php endif; ?>

                      <?php if ($company['verification_status'] === 'verified'): ?>
                        <?php if ($company['is_featured']): ?>
                          <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="unfeature">
                            <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                            <button type="submit" class="btn-icon warning" title="Remove Featured">
                              <i class="fas fa-star"></i>
                            </button>
                          </form>
                        <?php else: ?>
                          <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="feature">
                            <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                            <button type="submit" class="btn-icon" title="Make Featured">
                              <i class="far fa-star"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>

                      <button type="button" class="btn-icon danger" title="Delete"
                        onclick="confirmDelete(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars(addslashes($company['company_name'])); ?>')">
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&industry=<?php echo urlencode($industryFilter); ?>"
              class="pagination-btn">
              <i class="fas fa-chevron-left"></i>
            </a>
          <?php endif; ?>

          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&industry=<?php echo urlencode($industryFilter); ?>"
              class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
              <?php echo $i; ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>&industry=<?php echo urlencode($industryFilter); ?>"
              class="pagination-btn">
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal" style="display: none;">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-times-circle"></i> Reject Company</h3>
      <button type="button" class="modal-close" onclick="hideRejectModal()">&times;</button>
    </div>
    <form method="POST" id="rejectForm">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="company_id" id="reject_company_id" value="">
      <div class="modal-body">
        <p>You are about to reject: <strong id="reject_company_name"></strong></p>
        <div class="form-group">
          <label for="reason">Reason for Rejection <span class="required">*</span></label>
          <textarea name="reason" id="reason" rows="4" class="form-control" required
            placeholder="Please provide a reason for rejecting this company..."></textarea>
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

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal" style="display: none;">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-trash"></i> Delete Company</h3>
      <button type="button" class="modal-close" onclick="hideDeleteModal()">&times;</button>
    </div>
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="company_id" id="delete_company_id" value="">
      <div class="modal-body">
        <p>Are you sure you want to delete <strong id="delete_company_name"></strong>?</p>
        <p class="warning-text"><i class="fas fa-exclamation-triangle"></i> This will also delete all jobs posted by
          this company. This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="hideDeleteModal()">Cancel</button>
        <button type="submit" class="btn btn-danger">
          <i class="fas fa-trash"></i> Delete Company
        </button>
      </div>
    </form>
  </div>
</div>

<style>
  .dashboard-container {
    display: flex;
    min-height: calc(100vh - 70px);
    margin-top: 70px;
  }

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
    font-size: 1rem;
    word-break: break-all;
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
    font-size: 1.75rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .dashboard-header h1 i {
    color: var(--primary-color);
  }

  .dashboard-header p {
    color: var(--text-muted);
    margin-top: 0.25rem;
  }

  .alert {
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
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

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.25rem;
    margin-bottom: 1.5rem;
  }

  .stat-card {
    background: var(--card-bg);
    border-radius: 12px;
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
    transform: translateY(-4px);
    border-color: rgba(0, 230, 118, 0.3);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  }

  .stat-icon {
    width: 50px;
    height: 50px;
    background: rgba(0, 230, 118, 0.15);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color);
    font-size: 1.25rem;
  }

  .stat-icon.success {
    background: rgba(76, 175, 80, 0.15);
    color: #4CAF50;
  }

  .stat-icon.warning {
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
  }

  .stat-icon.featured {
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
  }

  .stat-content {
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .stat-number {
    display: block;
    font-size: 1.75rem;
    font-weight: 700;
  }

  .stat-label {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .filters-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid var(--border-color);
  }

  .filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
  }

  .filter-group {
    flex: 1;
    min-width: 200px;
  }

  .form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-dark);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-light);
    font-size: 0.9rem;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--primary-color);
  }

  .btn {
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: none;
    transition: all 0.3s ease;
    text-decoration: none;
    font-size: 0.9rem;
  }

  .btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: var(--bg-dark);
  }

  .btn-secondary {
    background: var(--bg-dark);
    color: var(--text-light);
    border: 1px solid var(--border-color);
  }

  .btn-danger {
    background: linear-gradient(135deg, #F44336, #d32f2f);
    color: white;
  }

  .table-card {
    background: var(--card-bg);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    overflow: hidden;
  }

  .table-responsive {
    overflow-x: auto;
  }

  .data-table {
    width: 100%;
    border-collapse: collapse;
  }

  .data-table th,
  .data-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
  }

  .data-table th {
    background: rgba(0, 0, 0, 0.2);
    color: var(--text-muted);
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
  }

  .data-table tbody tr:hover {
    background: rgba(0, 230, 118, 0.02);
  }

  .company-cell {
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .company-logo {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #2196F3, #1976D2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
  }

  .company-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 10px;
  }

  .company-info strong {
    display: block;
    margin-bottom: 0.25rem;
  }

  .company-location {
    color: var(--text-muted);
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
  }

  .featured-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    margin-left: 0.5rem;
  }

  .email-link {
    color: var(--primary-color);
    text-decoration: none;
  }

  .email-link:hover {
    text-decoration: underline;
  }

  .job-count {
    font-weight: 600;
  }

  .job-count+small {
    display: block;
    color: var(--text-muted);
    font-size: 0.75rem;
  }

  .status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .status-badge.verified {
    background: rgba(76, 175, 80, 0.15);
    color: #4CAF50;
  }

  .status-badge.pending {
    background: rgba(255, 193, 7, 0.15);
    color: #FFC107;
  }

  .status-badge.rejected {
    background: rgba(244, 67, 54, 0.15);
    color: #F44336;
  }

  .action-buttons {
    display: flex;
    gap: 0.5rem;
  }

  .btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-muted);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
  }

  .btn-icon:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .btn-icon.success {
    border-color: #4CAF50;
    color: #4CAF50;
  }

  .btn-icon.success:hover {
    background: rgba(76, 175, 80, 0.1);
  }

  .btn-icon.danger {
    border-color: #F44336;
    color: #F44336;
  }

  .btn-icon.danger:hover {
    background: rgba(244, 67, 54, 0.1);
  }

  .btn-icon.warning {
    border-color: #FFC107;
    color: #FFC107;
  }

  .empty-state {
    text-align: center;
    padding: 3rem !important;
    color: var(--text-muted);
  }

  .empty-state i {
    font-size: 3rem;
    margin-bottom: 1rem;
    display: block;
    color: var(--primary-color);
  }

  .pagination {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    padding: 1.5rem;
  }

  .pagination-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .pagination-btn:hover,
  .pagination-btn.active {
    border-color: var(--primary-color);
    color: var(--primary-color);
    background: rgba(0, 230, 118, 0.1);
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
  }

  .modal-box {
    background: var(--card-bg);
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    border: 1px solid var(--border-color);
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .modal-header h3 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #F44336;
  }

  .modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 1.5rem;
    cursor: pointer;
  }

  .modal-body {
    padding: 1.5rem;
  }

  .modal-body .form-group {
    margin-top: 1rem;
  }

  .modal-body .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
  }

  .modal-body .form-group .required {
    color: #F44336;
  }

  .modal-body textarea.form-control {
    resize: vertical;
  }

  .warning-text {
    color: #FFC107;
    background: rgba(255, 193, 7, 0.1);
    padding: 0.75rem;
    border-radius: 8px;
    font-size: 0.9rem;
    margin-top: 1rem;
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

  @media (max-width: 992px) {
    .dashboard-sidebar {
      width: 70px;
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

    .dashboard-main {
      margin-left: 70px;
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
    }

    .sidebar-footer {
      display: none;
    }

    .dashboard-main {
      margin-left: 0;
      padding: 1rem;
    }

    .filters-form {
      flex-direction: column;
    }

    .filter-group {
      width: 100%;
    }
  }
</style>

<script>
  function showRejectModal(companyId, companyName) {
    document.getElementById('reject_company_id').value = companyId;
    document.getElementById('reject_company_name').textContent = companyName;
    document.getElementById('rejectModal').style.display = 'flex';
  }

  function hideRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.getElementById('reason').value = '';
  }

  function confirmDelete(companyId, companyName) {
    document.getElementById('delete_company_id').value = companyId;
    document.getElementById('delete_company_name').textContent = companyName;
    document.getElementById('deleteModal').style.display = 'flex';
  }

  function hideDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
  }

  // Close modals on outside click
  document.getElementById('rejectModal').addEventListener('click', function (e) {
    if (e.target === this) hideRejectModal();
  });
  document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) hideDeleteModal();
  });

  // Close modals on Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      hideRejectModal();
      hideDeleteModal();
    }
  });
</script>

<?php include '../includes/footer.php'; ?>