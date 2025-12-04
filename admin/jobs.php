<?php
/**
 * JobNexus - Admin Jobs Management
 * Manage all job listings in the system
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Job.php';
require_once '../classes/Company.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_ADMIN) {
  header('Location: ../auth/login.php');
  exit;
}

$db = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $jobId = intval($_POST['job_id'] ?? 0);

  if ($action === 'update_status' && $jobId) {
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['draft', 'pending', 'active', 'paused', 'closed', 'expired'])) {
      $stmt = $db->prepare("UPDATE jobs SET status = ?, updated_at = NOW() WHERE id = ?");
      if ($stmt->execute([$status, $jobId])) {
        $message = 'Job status updated successfully.';
        $messageType = 'success';
      } else {
        $message = 'Error updating job status.';
        $messageType = 'error';
      }
    }
  }

  if ($action === 'toggle_featured' && $jobId) {
    $stmt = $db->prepare("UPDATE jobs SET is_featured = NOT is_featured, updated_at = NOW() WHERE id = ?");
    if ($stmt->execute([$jobId])) {
      $message = 'Job featured status toggled successfully.';
      $messageType = 'success';
    } else {
      $message = 'Error updating featured status.';
      $messageType = 'error';
    }
  }

  if ($action === 'toggle_urgent' && $jobId) {
    $stmt = $db->prepare("UPDATE jobs SET is_urgent = NOT is_urgent, updated_at = NOW() WHERE id = ?");
    if ($stmt->execute([$jobId])) {
      $message = 'Job urgent status toggled successfully.';
      $messageType = 'success';
    } else {
      $message = 'Error updating urgent status.';
      $messageType = 'error';
    }
  }

  if ($action === 'delete' && $jobId) {
    $stmt = $db->prepare("DELETE FROM jobs WHERE id = ?");
    if ($stmt->execute([$jobId])) {
      $message = 'Job deleted successfully.';
      $messageType = 'success';
    } else {
      $message = 'Error deleting job.';
      $messageType = 'error';
    }
  }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
$companyFilter = isset($_GET['company']) ? intval($_GET['company']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

if ($search) {
  $where[] = "(j.title LIKE ? OR c.company_name LIKE ? OR j.location LIKE ?)";
  $searchTerm = "%$search%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($statusFilter) {
  $where[] = "j.status = ?";
  $params[] = $statusFilter;
}

if ($typeFilter) {
  $where[] = "j.job_type = ?";
  $params[] = $typeFilter;
}

if ($companyFilter) {
  $where[] = "j.company_id = ?";
  $params[] = $companyFilter;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countSql = "SELECT COUNT(*) FROM jobs j LEFT JOIN companies c ON j.company_id = c.id WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalJobs = $countStmt->fetchColumn();
$totalPages = ceil($totalJobs / $perPage);

// Get jobs
$sql = "
    SELECT j.*, c.company_name, c.logo as company_logo,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count
    FROM jobs j
    LEFT JOIN companies c ON j.company_id = c.id
    WHERE $whereClause
    ORDER BY j.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all companies for filter dropdown
$companiesStmt = $db->query("SELECT id, company_name FROM companies ORDER BY company_name");
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM jobs
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = "Jobs Management - JobNexus Admin";
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
      <a href="<?php echo BASE_URL; ?>/admin/jobs.php" class="nav-item active">
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
        <h1><i class="fas fa-briefcase"></i> Jobs Management</h1>
        <p>Manage all job listings across the platform</p>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i
          class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
      <div class="stat-card primary">
        <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['total'] ?? 0); ?></h3>
          <p>Total Jobs</p>
        </div>
      </div>
      <div class="stat-card success">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['active'] ?? 0); ?></h3>
          <p>Active Jobs</p>
        </div>
      </div>
      <div class="stat-card warning">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['pending'] ?? 0); ?></h3>
          <p>Pending Review</p>
        </div>
      </div>
      <div class="stat-card info">
        <div class="stat-icon"><i class="fas fa-star"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['featured'] ?? 0); ?></h3>
          <p>Featured Jobs</p>
        </div>
      </div>
      <div class="stat-card secondary">
        <div class="stat-icon"><i class="fas fa-pause-circle"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['paused'] ?? 0); ?></h3>
          <p>Paused Jobs</p>
        </div>
      </div>
      <div class="stat-card purple">
        <div class="stat-icon"><i class="fas fa-calendar-plus"></i></div>
        <div class="stat-content">
          <h3><?php echo number_format($stats['today'] ?? 0); ?></h3>
          <p>Posted Today</p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filters-card glass-card">
      <form method="GET" class="filters-form">
        <div class="filter-group">
          <label for="search">Search</label>
          <input type="text" id="search" name="search" placeholder="Job title, company, or location..."
            value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All Statuses</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="paused" <?php echo $statusFilter === 'paused' ? 'selected' : ''; ?>>Paused</option>
            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option>
            <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
            <option value="draft" <?php echo $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
          </select>
        </div>
        <div class="filter-group">
          <label for="type">Job Type</label>
          <select id="type" name="type">
            <option value="">All Types</option>
            <option value="full-time" <?php echo $typeFilter === 'full-time' ? 'selected' : ''; ?>>Full-time</option>
            <option value="part-time" <?php echo $typeFilter === 'part-time' ? 'selected' : ''; ?>>Part-time</option>
            <option value="contract" <?php echo $typeFilter === 'contract' ? 'selected' : ''; ?>>Contract</option>
            <option value="internship" <?php echo $typeFilter === 'internship' ? 'selected' : ''; ?>>Internship</option>
            <option value="freelance" <?php echo $typeFilter === 'freelance' ? 'selected' : ''; ?>>Freelance</option>
          </select>
        </div>
        <div class="filter-group">
          <label for="company">Company</label>
          <select id="company" name="company">
            <option value="">All Companies</option>
            <?php foreach ($companies as $comp): ?>
              <option value="<?php echo $comp['id']; ?>" <?php echo $companyFilter === $comp['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($comp['company_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Filter
          </button>
          <a href="<?php echo BASE_URL; ?>/admin/jobs.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Clear
          </a>
        </div>
      </form>
    </div>

    <!-- Jobs Table -->
    <div class="data-table-card glass-card">
      <div class="table-header">
        <h3><i class="fas fa-list"></i> Job Listings (<?php echo number_format($totalJobs); ?>)</h3>
      </div>

      <?php if (empty($jobs)): ?>
        <div class="empty-state">
          <i class="fas fa-briefcase"></i>
          <h4>No Jobs Found</h4>
          <p>No jobs match your current filters.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Job</th>
                <th>Company</th>
                <th>Type</th>
                <th>Location</th>
                <th>Applications</th>
                <th>Status</th>
                <th>Posted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($jobs as $job): ?>
                <tr>
                  <td>
                    <div class="job-info">
                      <strong><?php echo htmlspecialchars($job['title']); ?></strong>
                      <div class="job-badges">
                        <?php if ($job['is_featured']): ?>
                          <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>
                        <?php if ($job['is_urgent']): ?>
                          <span class="badge badge-urgent"><i class="fas fa-bolt"></i> Urgent</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="company-cell">
                      <div class="company-logo-small">
                        <?php if ($job['company_logo']): ?>
                          <img
                            src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo htmlspecialchars($job['company_logo']); ?>"
                            alt="">
                        <?php else: ?>
                          <?php echo strtoupper(substr($job['company_name'] ?? 'C', 0, 1)); ?>
                        <?php endif; ?>
                      </div>
                      <span><?php echo htmlspecialchars($job['company_name'] ?? 'Unknown'); ?></span>
                    </div>
                  </td>
                  <td>
                    <span class="job-type-badge <?php echo $job['job_type']; ?>">
                      <?php echo ucfirst(str_replace('-', ' ', $job['job_type'])); ?>
                    </span>
                  </td>
                  <td>
                    <span class="location-cell">
                      <i class="fas fa-map-marker-alt"></i>
                      <?php echo htmlspecialchars($job['location'] ?? 'Not specified'); ?>
                    </span>
                  </td>
                  <td>
                    <span class="applications-count">
                      <i class="fas fa-users"></i> <?php echo number_format($job['application_count']); ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge status-<?php echo $job['status']; ?>">
                      <?php echo ucfirst($job['status']); ?>
                    </span>
                  </td>
                  <td>
                    <span class="date-cell">
                      <?php echo date('M j, Y', strtotime($job['created_at'])); ?>
                    </span>
                  </td>
                  <td>
                    <div class="action-buttons">
                      <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>" class="btn-icon"
                        title="View Job" target="_blank">
                        <i class="fas fa-eye"></i>
                      </a>
                      <button type="button" class="btn-icon" title="Change Status"
                        onclick="openStatusModal(<?php echo $job['id']; ?>, '<?php echo $job['status']; ?>')">
                        <i class="fas fa-edit"></i>
                      </button>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_featured">
                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                        <button type="submit" class="btn-icon <?php echo $job['is_featured'] ? 'active' : ''; ?>"
                          title="<?php echo $job['is_featured'] ? 'Remove Featured' : 'Mark as Featured'; ?>">
                          <i class="fas fa-star"></i>
                        </button>
                      </form>
                      <form method="POST" style="display: inline;"
                        onsubmit="return confirm('Are you sure you want to delete this job?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                        <button type="submit" class="btn-icon btn-danger" title="Delete Job">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div class="pagination">
            <?php if ($page > 1): ?>
              <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                <i class="fas fa-chevron-left"></i> Previous
              </a>
            <?php endif; ?>

            <div class="page-numbers">
              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                  class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                  <?php echo $i; ?>
                </a>
              <?php endfor; ?>
            </div>

            <?php if ($page < $totalPages): ?>
              <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                Next <i class="fas fa-chevron-right"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Status Change Modal -->
<div id="statusModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-edit"></i> Change Job Status</h3>
      <button type="button" class="modal-close" onclick="closeStatusModal()">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="job_id" id="modalJobId">
      <div class="modal-body">
        <div class="form-group">
          <label for="modalStatus">Select New Status</label>
          <select name="status" id="modalStatus" class="form-control">
            <option value="draft">Draft</option>
            <option value="pending">Pending Review</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="closed">Closed</option>
            <option value="expired">Expired</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Status</button>
      </div>
    </form>
  </div>
</div>

<style>
  /* Stats Grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
  }

  .stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 1rem;
    transition: all var(--transition-normal);
  }

  .stat-card::before {
    display: none;
  }

  .stat-card:hover {
    transform: translateY(-4px);
    border-color: var(--accent-primary);
    box-shadow: var(--shadow-glow);
  }

  .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-md);
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
    color: var(--accent-primary);
  }

  .stat-card.success .stat-icon {
    background: rgba(76, 175, 80, 0.15);
    color: #4caf50;
  }

  .stat-card.warning .stat-icon {
    background: rgba(255, 171, 64, 0.15);
    color: var(--warning);
  }

  .stat-card.info .stat-icon {
    background: rgba(64, 196, 255, 0.15);
    color: var(--info);
  }

  .stat-card.secondary .stat-icon {
    background: rgba(158, 158, 158, 0.15);
    color: #9e9e9e;
  }

  .stat-card.purple .stat-icon {
    background: rgba(156, 39, 176, 0.15);
    color: #9c27b0;
  }

  .stat-content h3 {
    font-size: 1.75rem;
    font-family: var(--font-heading);
    margin: 0 0 0.25rem;
    color: var(--text-primary);
  }

  .stat-content p {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin: 0;
  }

  /* Filters */
  .filters-card {
    margin-bottom: 2rem;
    padding: 1.5rem;
  }

  .filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
  }

  .filter-group {
    flex: 1;
    min-width: 150px;
  }

  .filter-group label {
    display: block;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .filter-group input,
  .filter-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.9rem;
    transition: all var(--transition-fast);
  }

  .filter-group input:focus,
  .filter-group select:focus {
    outline: none;
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px rgba(0, 230, 118, 0.1);
  }

  .filter-actions {
    display: flex;
    gap: 0.5rem;
  }

  /* Data Table */
  .data-table-card {
    padding: 0;
    overflow: hidden;
  }

  .table-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .table-header h3 {
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-primary);
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
    padding: 1rem 1.25rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
  }

  .data-table th {
    background: var(--bg-tertiary);
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
  }

  .data-table tr:hover {
    background: rgba(0, 230, 118, 0.03);
  }

  .job-info strong {
    display: block;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
  }

  .job-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
  }

  .badge {
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: var(--radius-full);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
  }

  .badge-featured {
    background: rgba(255, 193, 7, 0.15);
    color: #ffc107;
  }

  .badge-urgent {
    background: rgba(255, 82, 82, 0.15);
    color: var(--error);
  }

  .company-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .company-logo-small {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    color: var(--accent-primary);
    font-weight: 600;
    overflow: hidden;
  }

  .company-logo-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .job-type-badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: var(--radius-full);
    font-weight: 500;
  }

  .job-type-badge.full-time {
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
  }

  .job-type-badge.part-time {
    background: rgba(64, 196, 255, 0.15);
    color: var(--info);
  }

  .job-type-badge.contract {
    background: rgba(255, 171, 64, 0.15);
    color: var(--warning);
  }

  .job-type-badge.internship {
    background: rgba(156, 39, 176, 0.15);
    color: #ce93d8;
  }

  .job-type-badge.freelance {
    background: rgba(255, 82, 82, 0.15);
    color: var(--error);
  }

  .location-cell {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
  }

  .location-cell i {
    color: var(--text-muted);
    font-size: 0.8rem;
  }

  .applications-count {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-secondary);
  }

  .applications-count i {
    color: var(--accent-primary);
  }

  .status-badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: var(--radius-full);
    font-weight: 500;
  }

  .status-badge.status-active {
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
  }

  .status-badge.status-pending {
    background: rgba(255, 171, 64, 0.15);
    color: var(--warning);
  }

  .status-badge.status-paused {
    background: rgba(158, 158, 158, 0.15);
    color: #9e9e9e;
  }

  .status-badge.status-closed {
    background: rgba(255, 82, 82, 0.15);
    color: var(--error);
  }

  .status-badge.status-expired {
    background: rgba(100, 100, 100, 0.15);
    color: #666;
  }

  .status-badge.status-draft {
    background: rgba(64, 196, 255, 0.15);
    color: var(--info);
  }

  .date-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .action-buttons {
    display: flex;
    gap: 0.5rem;
  }

  .btn-icon {
    width: 34px;
    height: 34px;
    border-radius: var(--radius-sm);
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast);
  }

  .btn-icon:hover {
    background: var(--bg-hover);
    color: var(--accent-primary);
    border-color: var(--accent-primary);
  }

  .btn-icon.active {
    background: rgba(255, 193, 7, 0.15);
    color: #ffc107;
    border-color: #ffc107;
  }

  .btn-icon.btn-danger:hover {
    background: rgba(255, 82, 82, 0.15);
    color: var(--error);
    border-color: var(--error);
  }

  /* Pagination */
  .pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
  }

  .page-numbers {
    display: flex;
    gap: 0.25rem;
  }

  .page-link {
    padding: 0.5rem 1rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all var(--transition-fast);
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .page-link:hover {
    background: var(--bg-hover);
    color: var(--accent-primary);
    border-color: var(--accent-primary);
  }

  .page-link.active {
    background: var(--accent-primary);
    color: var(--text-inverse);
    border-color: var(--accent-primary);
  }

  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
  }

  .empty-state i {
    font-size: 4rem;
    color: var(--accent-primary);
    opacity: 0.25;
    margin-bottom: 1.5rem;
    display: block;
  }

  .empty-state h4 {
    color: var(--text-primary);
    margin-bottom: 0.5rem;
  }

  .empty-state p {
    color: var(--text-muted);
  }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }

  .modal.active {
    display: flex;
  }

  .modal-content {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 400px;
    overflow: hidden;
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-primary);
  }

  .modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 1.5rem;
    cursor: pointer;
    transition: color var(--transition-fast);
  }

  .modal-close:hover {
    color: var(--error);
  }

  .modal-body {
    padding: 1.5rem;
  }

  .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem;
    border-top: 1px solid var(--border-color);
  }

  .form-group {
    margin-bottom: 1rem;
  }

  .form-group label {
    display: block;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
  }

  .form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.95rem;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--accent-primary);
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

  .alert-warning {
    background: rgba(255, 171, 64, 0.15);
    border: 1px solid rgba(255, 171, 64, 0.25);
    color: var(--warning);
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .filters-form {
      flex-direction: column;
    }

    .filter-group {
      width: 100%;
    }
  }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .action-buttons {
      flex-wrap: wrap;
    }
  }
</style>

<script>
  function openStatusModal(jobId, currentStatus) {
    document.getElementById('modalJobId').value = jobId;
    document.getElementById('modalStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('active');
  }

  function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('active');
  }

  // Close modal when clicking outside
  document.getElementById('statusModal').addEventListener('click', function (e) {
    if (e.target === this) {
      closeStatusModal();
    }
  });

  // Close modal with Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeStatusModal();
    }
  });
</script>

<?php include '../includes/footer.php'; ?>