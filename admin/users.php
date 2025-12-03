<?php
/**
 * JobNexus - Admin User Management
 * Manage all users in the system
 */

session_start();
require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_ADMIN) {
  header('Location: ../auth/login.php');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User($db);

$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $targetUserId = intval($_POST['user_id'] ?? 0);

  if ($action === 'update_status' && $targetUserId) {
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['active', 'inactive', 'suspended'])) {
      $stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
      if ($stmt->execute([$status, $targetUserId])) {
        $message = 'User status updated successfully.';
        $messageType = 'success';
      } else {
        $message = 'Error updating user status.';
        $messageType = 'error';
      }
    }
  }

  if ($action === 'update_role' && $targetUserId) {
    $newRole = intval($_POST['role'] ?? 0);
    if (in_array($newRole, [ROLE_ADMIN, ROLE_HR, ROLE_SEEKER])) {
      $stmt = $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?");
      if ($stmt->execute([$newRole, $targetUserId])) {
        $message = 'User role updated successfully.';
        $messageType = 'success';
      } else {
        $message = 'Error updating user role.';
        $messageType = 'error';
      }
    }
  }

  if ($action === 'delete' && $targetUserId) {
    // Don't allow deleting yourself
    if ($targetUserId === $_SESSION['user_id']) {
      $message = 'You cannot delete your own account.';
      $messageType = 'error';
    } else {
      // Soft delete or hard delete
      $stmt = $db->prepare("UPDATE users SET status = 'deleted', updated_at = NOW() WHERE id = ?");
      if ($stmt->execute([$targetUserId])) {
        $message = 'User deleted successfully.';
        $messageType = 'success';
      } else {
        $message = 'Error deleting user.';
        $messageType = 'error';
      }
    }
  }
}

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? intval($_GET['role']) : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["status != 'deleted'"];
$params = [];

if ($search) {
  $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
  $searchTerm = "%$search%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($roleFilter) {
  $where[] = "role = ?";
  $params[] = $roleFilter;
}

if ($statusFilter) {
  $where[] = "status = ?";
  $params[] = $statusFilter;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereClause");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Get users
$sql = "
    SELECT u.*, c.name as company_name
    FROM users u
    LEFT JOIN companies c ON u.company_id = c.id
    WHERE $whereClause
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN role = 3 THEN 1 ELSE 0 END) as seekers,
        SUM(CASE WHEN role = 2 THEN 1 ELSE 0 END) as hr,
        SUM(CASE WHEN role = 1 THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
    FROM users WHERE status != 'deleted'
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = "User Management - JobNexus Admin";
include '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="user-avatar admin">
        <?php echo strtoupper(substr($_SESSION['first_name'], 0, 1) . substr($_SESSION['last_name'], 0, 1)); ?>
      </div>
      <div class="user-info">
        <h3><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h3>
        <span class="role-badge admin">Administrator</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <a href="index.php" class="nav-item">
        <i class="fas fa-chart-line"></i>
        <span>Dashboard</span>
      </a>
      <a href="users.php" class="nav-item active">
        <i class="fas fa-users"></i>
        <span>Users</span>
      </a>
      <a href="companies.php" class="nav-item">
        <i class="fas fa-building"></i>
        <span>Companies</span>
      </a>
      <a href="jobs.php" class="nav-item">
        <i class="fas fa-briefcase"></i>
        <span>Jobs</span>
      </a>
      <a href="reports.php" class="nav-item">
        <i class="fas fa-chart-bar"></i>
        <span>Reports</span>
      </a>
      <a href="settings.php" class="nav-item">
        <i class="fas fa-cog"></i>
        <span>Settings</span>
      </a>
    </nav>
  </aside>

  <!-- Main Content -->
  <main class="dashboard-main">
    <div class="dashboard-header">
      <div>
        <h1>User Management</h1>
        <p class="subtitle">Manage all users and their access levels</p>
      </div>
      <button class="btn btn-primary" onclick="openModal('addUserModal')">
        <i class="fas fa-user-plus"></i> Add User
      </button>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo number_format($stats['total']); ?></span>
          <span class="stat-label">Total Users</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon seeker">
          <i class="fas fa-user-tie"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo number_format($stats['seekers']); ?></span>
          <span class="stat-label">Job Seekers</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon hr">
          <i class="fas fa-user-cog"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo number_format($stats['hr']); ?></span>
          <span class="stat-label">HR Managers</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon success">
          <i class="fas fa-user-plus"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo number_format($stats['today']); ?></span>
          <span class="stat-label">New Today</span>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="glass-card filter-section">
      <form method="GET" class="filters-form">
        <div class="filter-group">
          <input type="text" name="search" placeholder="Search users..."
            value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
          <select name="role">
            <option value="">All Roles</option>
            <option value="<?php echo ROLE_SEEKER; ?>" <?php echo $roleFilter === ROLE_SEEKER ? 'selected' : ''; ?>>Job
              Seekers</option>
            <option value="<?php echo ROLE_HR; ?>" <?php echo $roleFilter === ROLE_HR ? 'selected' : ''; ?>>HR Managers
            </option>
            <option value="<?php echo ROLE_ADMIN; ?>" <?php echo $roleFilter === ROLE_ADMIN ? 'selected' : ''; ?>>
              Administrators</option>
          </select>
        </div>
        <div class="filter-group">
          <select name="status">
            <option value="">All Status</option>
            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
          </select>
        </div>
        <button type="submit" class="btn btn-outline">
          <i class="fas fa-search"></i> Search
        </button>
        <?php if ($search || $roleFilter || $statusFilter): ?>
          <a href="users.php" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Users Table -->
    <div class="glass-card">
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>User</th>
              <th>Email</th>
              <th>Role</th>
              <th>Company</th>
              <th>Status</th>
              <th>Joined</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="7" class="empty-table">
                  <i class="fas fa-users"></i>
                  <p>No users found</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="user-avatar-small">
                        <?php echo strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1)); ?>
                      </div>
                      <div class="user-details">
                        <strong><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></strong>
                        <span class="user-id">ID: <?php echo $u['id']; ?></span>
                      </div>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($u['email']); ?></td>
                  <td>
                    <?php
                    $roleLabels = [
                      ROLE_ADMIN => ['Admin', 'admin'],
                      ROLE_HR => ['HR Manager', 'hr'],
                      ROLE_SEEKER => ['Seeker', 'seeker']
                    ];
                    $roleInfo = $roleLabels[$u['role']] ?? ['Unknown', 'default'];
                    ?>
                    <span class="role-badge <?php echo $roleInfo[1]; ?>"><?php echo $roleInfo[0]; ?></span>
                  </td>
                  <td>
                    <?php echo $u['company_name'] ? htmlspecialchars($u['company_name']) : '-'; ?>
                  </td>
                  <td>
                    <span class="status-badge <?php echo $u['status']; ?>">
                      <?php echo ucfirst($u['status']); ?>
                    </span>
                  </td>
                  <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                  <td>
                    <div class="action-buttons">
                      <button class="btn btn-icon" onclick="viewUser(<?php echo $u['id']; ?>)" title="View">
                        <i class="fas fa-eye"></i>
                      </button>
                      <button class="btn btn-icon" onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)"
                        title="Edit">
                        <i class="fas fa-edit"></i>
                      </button>
                      <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                        <button class="btn btn-icon danger"
                          onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>')"
                          title="Delete">
                          <i class="fas fa-trash"></i>
                        </button>
                      <?php endif; ?>
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
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo urlencode($statusFilter); ?>"
              class="btn btn-icon">
              <i class="fas fa-chevron-left"></i>
            </a>
          <?php endif; ?>

          <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

          <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo urlencode($statusFilter); ?>"
              class="btn btn-icon">
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Edit User</h3>
      <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form id="editUserForm" method="POST">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="user_id" id="editUserId">

        <div class="form-group">
          <label>User</label>
          <input type="text" id="editUserName" disabled>
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status" id="editUserStatus">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModal('editUserModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>

      <hr style="margin: 1.5rem 0; border-color: rgba(255,255,255,0.1);">

      <form method="POST">
        <input type="hidden" name="action" value="update_role">
        <input type="hidden" name="user_id" id="editUserIdRole">

        <div class="form-group">
          <label>Change Role</label>
          <select name="role" id="editUserRole">
            <option value="<?php echo ROLE_SEEKER; ?>">Job Seeker</option>
            <option value="<?php echo ROLE_HR; ?>">HR Manager</option>
            <option value="<?php echo ROLE_ADMIN; ?>">Administrator</option>
          </select>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-warning">Update Role</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
  <div class="modal-content modal-sm">
    <div class="modal-header">
      <h3>Confirm Delete</h3>
      <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
      <p class="text-muted">This action cannot be undone.</p>

      <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" id="deleteUserId">

        <div class="form-actions">
          <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
  }

  .stat-card {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 1rem;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border: 1px solid rgba(255, 255, 255, 0.05);
  }

  .stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 0.75rem;
    background: rgba(0, 230, 118, 0.15);
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .stat-icon.seeker {
    background: rgba(100, 181, 246, 0.15);
    color: #64b5f6;
  }

  .stat-icon.hr {
    background: rgba(186, 104, 200, 0.15);
    color: #ba68c8;
  }

  .stat-icon.success {
    background: rgba(0, 230, 118, 0.15);
    color: var(--primary-color);
  }

  .stat-number {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
  }

  .stat-label {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .filter-section {
    margin-bottom: 2rem;
  }

  .filters-form {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
  }

  .filter-group input,
  .filter-group select {
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    min-width: 200px;
  }

  .filter-group input:focus,
  .filter-group select:focus {
    border-color: var(--primary-color);
    outline: none;
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
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  }

  .data-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.5);
    font-weight: 600;
  }

  .data-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.02);
  }

  .user-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .user-avatar-small {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .user-details strong {
    display: block;
    color: var(--text-primary);
  }

  .user-id {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.4);
  }

  .role-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.7rem;
    font-weight: 600;
  }

  .role-badge.admin {
    background: rgba(244, 67, 54, 0.2);
    color: #f44336;
  }

  .role-badge.hr {
    background: rgba(186, 104, 200, 0.2);
    color: #ba68c8;
  }

  .role-badge.seeker {
    background: rgba(100, 181, 246, 0.2);
    color: #64b5f6;
  }

  .status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.7rem;
    font-weight: 600;
  }

  .status-badge.active {
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
  }

  .status-badge.inactive {
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.5);
  }

  .status-badge.suspended {
    background: rgba(244, 67, 54, 0.2);
    color: #f44336;
  }

  .action-buttons {
    display: flex;
    gap: 0.5rem;
  }

  .btn-icon.danger {
    color: #f44336;
  }

  .btn-icon.danger:hover {
    background: rgba(244, 67, 54, 0.2);
  }

  .empty-table {
    text-align: center;
    padding: 3rem !important;
  }

  .empty-table i {
    font-size: 2rem;
    color: rgba(255, 255, 255, 0.2);
    margin-bottom: 0.75rem;
  }

  .empty-table p {
    color: rgba(255, 255, 255, 0.5);
  }

  .pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
  }

  .page-info {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.875rem;
  }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }

  .modal.active {
    display: flex;
  }

  .modal-content {
    background: var(--bg-secondary);
    border-radius: 1rem;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
  }

  .modal-sm {
    max-width: 400px;
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .modal-header h3 {
    margin: 0;
  }

  .modal-close {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.5);
    font-size: 1.5rem;
    cursor: pointer;
  }

  .modal-body {
    padding: 1.5rem;
  }

  .form-group {
    margin-bottom: 1rem;
  }

  .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.7);
  }

  .form-group input,
  .form-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
  }

  .form-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
  }

  .btn-warning {
    background: #ffc107;
    color: #000;
  }

  .btn-danger {
    background: #f44336;
    color: #fff;
  }

  .text-muted {
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.875rem;
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .stats-grid {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  @media (max-width: 768px) {
    .stats-grid {
      grid-template-columns: 1fr;
    }

    .filters-form {
      flex-direction: column;
    }

    .filter-group input,
    .filter-group select {
      width: 100%;
    }
  }
</style>

<script>
  function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
  }

  function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
  }

  function viewUser(userId) {
    // In a real app, this would open a detailed view
    window.location.href = 'user-detail.php?id=' + userId;
  }

  function editUser(userData) {
    const user = typeof userData === 'string' ? JSON.parse(userData) : userData;

    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUserIdRole').value = user.id;
    document.getElementById('editUserName').value = user.first_name + ' ' + user.last_name;
    document.getElementById('editUserStatus').value = user.status;
    document.getElementById('editUserRole').value = user.role;

    openModal('editUserModal');
  }

  function confirmDelete(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    openModal('deleteModal');
  }

  // Close modal on outside click
  document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function (e) {
      if (e.target === this) {
        this.classList.remove('active');
      }
    });
  });
</script>

<?php include '../includes/footer.php'; ?>