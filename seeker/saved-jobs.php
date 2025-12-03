<?php
/**
 * JobNexus - Saved Jobs
 * View and manage saved/bookmarked jobs
 */

session_start();
require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/Job.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_SEEKER) {
  header('Location: ../auth/login.php');
  exit;
}

$db = Database::getInstance()->getConnection();
$job = new Job($db);

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle unsave action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($_POST['action'] === 'unsave' && isset($_POST['job_id'])) {
    $jobId = intval($_POST['job_id']);

    $stmt = $db->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
    if ($stmt->execute([$userId, $jobId])) {
      $message = 'Job removed from saved list.';
      $messageType = 'info';
    } else {
      $message = 'Error removing job.';
      $messageType = 'error';
    }
  }
}

// Handle AJAX save/unsave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
  header('Content-Type: application/json');

  $jobId = intval($_POST['job_id']);
  $action = $_POST['ajax_action'];

  if ($action === 'toggle') {
    // Check if already saved
    $stmt = $db->prepare("SELECT id FROM saved_jobs WHERE user_id = ? AND job_id = ?");
    $stmt->execute([$userId, $jobId]);

    if ($stmt->fetch()) {
      // Unsave
      $stmt = $db->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
      $stmt->execute([$userId, $jobId]);
      echo json_encode(['success' => true, 'saved' => false, 'message' => 'Job removed from saved list']);
    } else {
      // Save
      $stmt = $db->prepare("INSERT INTO saved_jobs (user_id, job_id, saved_at) VALUES (?, ?, NOW())");
      $stmt->execute([$userId, $jobId]);
      echo json_encode(['success' => true, 'saved' => true, 'message' => 'Job saved successfully']);
    }
  }
  exit;
}

// Get saved jobs with details
$stmt = $db->prepare("
    SELECT j.*, c.name as company_name, c.logo as company_logo, c.location as company_location,
           sj.saved_at,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id AND user_id = ?) as applied
    FROM saved_jobs sj
    JOIN jobs j ON sj.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    WHERE sj.user_id = ?
    ORDER BY sj.saved_at DESC
");
$stmt->execute([$userId, $userId]);
$savedJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get job categories for filtering
$categories = [];
foreach ($savedJobs as $sj) {
  if (!in_array($sj['category'], $categories)) {
    $categories[] = $sj['category'];
  }
}

// Get stats
$totalSaved = count($savedJobs);
$activeJobs = count(array_filter($savedJobs, fn($j) => $j['status'] === 'active'));
$appliedJobs = count(array_filter($savedJobs, fn($j) => $j['applied'] > 0));
$expiredJobs = count(array_filter($savedJobs, fn($j) => $j['status'] !== 'active'));

$pageTitle = "Saved Jobs - JobNexus";
include '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="user-avatar">
        <?php echo strtoupper(substr($_SESSION['first_name'], 0, 1) . substr($_SESSION['last_name'], 0, 1)); ?>
      </div>
      <div class="user-info">
        <h3><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h3>
        <span class="role-badge seeker">Job Seeker</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <a href="index.php" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
      </a>
      <a href="profile.php" class="nav-item">
        <i class="fas fa-user"></i>
        <span>My Profile</span>
      </a>
      <a href="applications.php" class="nav-item">
        <i class="fas fa-file-alt"></i>
        <span>Applications</span>
      </a>
      <a href="calendar.php" class="nav-item">
        <i class="fas fa-calendar-alt"></i>
        <span>Interviews</span>
      </a>
      <a href="saved-jobs.php" class="nav-item active">
        <i class="fas fa-heart"></i>
        <span>Saved Jobs</span>
      </a>
      <a href="../jobs/index.php" class="nav-item">
        <i class="fas fa-search"></i>
        <span>Browse Jobs</span>
      </a>
    </nav>
  </aside>

  <!-- Main Content -->
  <main class="dashboard-main">
    <div class="dashboard-header">
      <div>
        <h1>Saved Jobs</h1>
        <p class="subtitle">Jobs you've bookmarked for later</p>
      </div>
      <a href="../jobs/index.php" class="btn btn-primary">
        <i class="fas fa-search"></i> Find More Jobs
      </a>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="stats-grid mini">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-heart"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo $totalSaved; ?></span>
          <span class="stat-label">Total Saved</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon success">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo $activeJobs; ?></span>
          <span class="stat-label">Active Jobs</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon info">
          <i class="fas fa-paper-plane"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo $appliedJobs; ?></span>
          <span class="stat-label">Applied</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon warning">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
          <span class="stat-number"><?php echo $expiredJobs; ?></span>
          <span class="stat-label">Expired</span>
        </div>
      </div>
    </div>

    <?php if (empty($savedJobs)): ?>
      <!-- Empty State -->
      <div class="glass-card empty-state-large">
        <div class="empty-icon">
          <i class="fas fa-heart"></i>
        </div>
        <h2>No Saved Jobs Yet</h2>
        <p>Start exploring and save jobs that interest you. They'll appear here for easy access.</p>
        <a href="../jobs/index.php" class="btn btn-primary btn-lg">
          <i class="fas fa-search"></i> Browse Jobs
        </a>
      </div>
    <?php else: ?>
      <!-- Filter Bar -->
      <div class="filter-bar">
        <div class="filter-group">
          <label>Category:</label>
          <select id="filterCategory" onchange="filterJobs()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Status:</label>
          <select id="filterStatus" onchange="filterJobs()">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="applied">Applied</option>
            <option value="expired">Expired</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Sort By:</label>
          <select id="sortBy" onchange="filterJobs()">
            <option value="saved_desc">Recently Saved</option>
            <option value="saved_asc">Oldest Saved</option>
            <option value="deadline">Deadline Soon</option>
            <option value="salary">Highest Salary</option>
          </select>
        </div>
      </div>

      <!-- Saved Jobs Grid -->
      <div class="jobs-grid" id="savedJobsGrid">
        <?php foreach ($savedJobs as $sj): ?>
          <?php
          $isActive = $sj['status'] === 'active';
          $isApplied = $sj['applied'] > 0;
          $deadline = $sj['deadline'] ? new DateTime($sj['deadline']) : null;
          $daysLeft = $deadline ? (new DateTime())->diff($deadline)->days : null;
          $isExpiringSoon = $deadline && $daysLeft <= 3 && $isActive;
          ?>
          <div class="job-card saved-job-card <?php echo !$isActive ? 'expired' : ''; ?>"
            data-category="<?php echo htmlspecialchars($sj['category']); ?>"
            data-status="<?php echo $isActive ? ($isApplied ? 'applied' : 'active') : 'expired'; ?>"
            data-saved="<?php echo strtotime($sj['saved_at']); ?>"
            data-deadline="<?php echo $sj['deadline'] ? strtotime($sj['deadline']) : ''; ?>"
            data-salary="<?php echo $sj['salary_max'] ?? 0; ?>">

            <!-- Save Button -->
            <button class="save-btn active" onclick="toggleSave(<?php echo $sj['id']; ?>, this)" title="Remove from saved">
              <i class="fas fa-heart"></i>
            </button>

            <!-- Status Badges -->
            <div class="job-badges">
              <?php if (!$isActive): ?>
                <span class="badge danger">Expired</span>
              <?php endif; ?>
              <?php if ($isApplied): ?>
                <span class="badge success">Applied</span>
              <?php endif; ?>
              <?php if ($isExpiringSoon): ?>
                <span class="badge warning">Expires Soon</span>
              <?php endif; ?>
            </div>

            <!-- Company Info -->
            <div class="company-header">
              <div class="company-logo">
                <?php if ($sj['company_logo']): ?>
                  <img src="../uploads/logos/<?php echo htmlspecialchars($sj['company_logo']); ?>"
                    alt="<?php echo htmlspecialchars($sj['company_name']); ?>">
                <?php else: ?>
                  <span><?php echo strtoupper(substr($sj['company_name'], 0, 2)); ?></span>
                <?php endif; ?>
              </div>
              <div class="company-info">
                <h4><?php echo htmlspecialchars($sj['company_name']); ?></h4>
                <span class="location">
                  <i class="fas fa-map-marker-alt"></i>
                  <?php echo htmlspecialchars($sj['location']); ?>
                </span>
              </div>
            </div>

            <!-- Job Details -->
            <h3 class="job-title">
              <a href="../jobs/view.php?id=<?php echo $sj['id']; ?>">
                <?php echo htmlspecialchars($sj['title']); ?>
              </a>
            </h3>

            <div class="job-meta">
              <span class="job-type <?php echo $sj['job_type']; ?>">
                <i class="fas fa-briefcase"></i>
                <?php echo ucfirst(str_replace('-', ' ', $sj['job_type'])); ?>
              </span>
              <span class="job-category">
                <i class="fas fa-tag"></i>
                <?php echo htmlspecialchars($sj['category']); ?>
              </span>
            </div>

            <?php if ($sj['salary_min'] || $sj['salary_max']): ?>
              <div class="salary-range">
                <i class="fas fa-dollar-sign"></i>
                <?php
                if ($sj['salary_min'] && $sj['salary_max']) {
                  echo number_format($sj['salary_min']) . ' - ' . number_format($sj['salary_max']);
                } elseif ($sj['salary_min']) {
                  echo 'From ' . number_format($sj['salary_min']);
                } else {
                  echo 'Up to ' . number_format($sj['salary_max']);
                }
                ?>
                <span class="salary-period">/year</span>
              </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="job-card-footer">
              <div class="saved-info">
                <i class="fas fa-bookmark"></i>
                Saved <?php echo date('M d, Y', strtotime($sj['saved_at'])); ?>
              </div>

              <?php if ($isActive && !$isApplied): ?>
                <a href="../jobs/view.php?id=<?php echo $sj['id']; ?>" class="btn btn-primary btn-sm">
                  Apply Now
                </a>
              <?php elseif ($isApplied): ?>
                <a href="applications.php" class="btn btn-outline btn-sm">
                  View Status
                </a>
              <?php else: ?>
                <span class="expired-label">No longer accepting applications</span>
              <?php endif; ?>
            </div>

            <?php if ($deadline && $isActive): ?>
              <div class="deadline-bar">
                <span class="deadline-text">
                  <i class="fas fa-hourglass-half"></i>
                  <?php echo $daysLeft; ?> days left to apply
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- No Results -->
      <div class="no-results" id="noResults" style="display: none;">
        <i class="fas fa-search"></i>
        <p>No jobs match your current filters</p>
        <button class="btn btn-outline" onclick="clearFilters()">Clear Filters</button>
      </div>
    <?php endif; ?>
  </main>
</div>

<style>
  .stats-grid.mini {
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
  }

  .stats-grid.mini .stat-card {
    padding: 1rem;
  }

  .stats-grid.mini .stat-number {
    font-size: 1.5rem;
  }

  .stats-grid.mini .stat-label {
    font-size: 0.75rem;
  }

  .filter-bar {
    display: flex;
    gap: 1.5rem;
    padding: 1rem 1.5rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 0.75rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
  }

  .filter-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .filter-group label {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.6);
  }

  .filter-group select {
    padding: 0.5rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    font-size: 0.875rem;
    cursor: pointer;
  }

  .filter-group select:focus {
    border-color: var(--primary-color);
    outline: none;
  }

  .jobs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
  }

  .saved-job-card {
    position: relative;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 1rem;
    padding: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.05);
    transition: all 0.3s ease;
  }

  .saved-job-card:hover {
    border-color: rgba(0, 230, 118, 0.3);
    transform: translateY(-4px);
    box-shadow: 0 10px 40px rgba(0, 230, 118, 0.1);
  }

  .saved-job-card.expired {
    opacity: 0.6;
  }

  .saved-job-card.expired:hover {
    opacity: 0.8;
  }

  .save-btn {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: rgba(255, 255, 255, 0.5);
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .save-btn.active {
    color: #ff6b6b;
    background: rgba(255, 107, 107, 0.2);
  }

  .save-btn:hover {
    transform: scale(1.1);
  }

  .job-badges {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
  }

  .badge {
    font-size: 0.625rem;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    text-transform: uppercase;
    font-weight: 700;
  }

  .badge.success {
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
  }

  .badge.warning {
    background: rgba(255, 193, 7, 0.2);
    color: #ffc107;
  }

  .badge.danger {
    background: rgba(244, 67, 54, 0.2);
    color: #f44336;
  }

  .company-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .company-logo {
    width: 48px;
    height: 48px;
    border-radius: 0.5rem;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .company-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .company-logo span {
    font-size: 1rem;
    font-weight: 700;
    color: var(--primary-color);
  }

  .company-info h4 {
    margin: 0;
    font-size: 0.875rem;
    color: var(--text-primary);
  }

  .company-info .location {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .job-title {
    margin: 0 0 1rem;
    font-size: 1.125rem;
  }

  .job-title a {
    color: var(--text-primary);
    text-decoration: none;
    transition: color 0.3s ease;
  }

  .job-title a:hover {
    color: var(--primary-color);
  }

  .job-meta {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .job-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.6);
    padding: 0.25rem 0.75rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 1rem;
  }

  .job-type.full-time {
    color: var(--primary-color);
  }

  .job-type.part-time {
    color: #64b5f6;
  }

  .job-type.contract {
    color: #ffc107;
  }

  .job-type.remote {
    color: #ba68c8;
  }

  .salary-range {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 1rem;
  }

  .salary-period {
    font-size: 0.75rem;
    font-weight: 400;
    color: rgba(255, 255, 255, 0.5);
  }

  .job-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
  }

  .saved-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .saved-info i {
    color: var(--primary-color);
  }

  .expired-label {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.4);
    font-style: italic;
  }

  .deadline-bar {
    margin-top: 1rem;
    padding: 0.5rem;
    background: rgba(255, 193, 7, 0.1);
    border-radius: 0.5rem;
    text-align: center;
  }

  .deadline-text {
    font-size: 0.75rem;
    color: #ffc107;
  }

  /* Empty State */
  .empty-state-large {
    text-align: center;
    padding: 4rem 2rem;
  }

  .empty-icon {
    width: 120px;
    height: 120px;
    margin: 0 auto 2rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .empty-icon i {
    font-size: 3rem;
    color: rgba(255, 255, 255, 0.2);
  }

  .empty-state-large h2 {
    margin: 0 0 1rem;
    color: var(--text-primary);
  }

  .empty-state-large p {
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 2rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
  }

  /* No Results */
  .no-results {
    text-align: center;
    padding: 4rem 2rem;
  }

  .no-results i {
    font-size: 3rem;
    color: rgba(255, 255, 255, 0.2);
    margin-bottom: 1rem;
  }

  .no-results p {
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 1rem;
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .stats-grid.mini {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  @media (max-width: 768px) {
    .filter-bar {
      flex-direction: column;
    }

    .filter-group {
      width: 100%;
    }

    .filter-group select {
      flex: 1;
    }

    .jobs-grid {
      grid-template-columns: 1fr;
    }

    .job-card-footer {
      flex-direction: column;
      gap: 1rem;
      align-items: stretch;
    }
  }
</style>

<script>
  function toggleSave(jobId, btn) {
    const formData = new FormData();
    formData.append('ajax_action', 'toggle');
    formData.append('job_id', jobId);

    fetch('saved-jobs.php', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          if (!data.saved) {
            // Remove the card with animation
            const card = btn.closest('.saved-job-card');
            card.style.opacity = '0';
            card.style.transform = 'scale(0.8)';
            setTimeout(() => {
              card.remove();
              checkEmpty();
            }, 300);
          }
          showNotification(data.message, 'success');
        }
      })
      .catch(error => {
        showNotification('Error updating saved job', 'error');
      });
  }

  function filterJobs() {
    const category = document.getElementById('filterCategory').value;
    const status = document.getElementById('filterStatus').value;
    const sortBy = document.getElementById('sortBy').value;

    const cards = document.querySelectorAll('.saved-job-card');
    const grid = document.getElementById('savedJobsGrid');
    let visibleCount = 0;

    // Convert to array for sorting
    const cardsArray = Array.from(cards);

    // Sort
    cardsArray.sort((a, b) => {
      switch (sortBy) {
        case 'saved_desc':
          return parseInt(b.dataset.saved) - parseInt(a.dataset.saved);
        case 'saved_asc':
          return parseInt(a.dataset.saved) - parseInt(b.dataset.saved);
        case 'deadline':
          const deadlineA = parseInt(a.dataset.deadline) || Infinity;
          const deadlineB = parseInt(b.dataset.deadline) || Infinity;
          return deadlineA - deadlineB;
        case 'salary':
          return parseInt(b.dataset.salary) - parseInt(a.dataset.salary);
        default:
          return 0;
      }
    });

    // Re-append sorted cards
    cardsArray.forEach(card => grid.appendChild(card));

    // Filter
    cardsArray.forEach(card => {
      const matchCategory = !category || card.dataset.category === category;
      const matchStatus = !status || card.dataset.status === status;

      if (matchCategory && matchStatus) {
        card.style.display = '';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });

    // Show/hide no results
    document.getElementById('noResults').style.display = visibleCount === 0 ? 'block' : 'none';
  }

  function clearFilters() {
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('sortBy').value = 'saved_desc';
    filterJobs();
  }

  function checkEmpty() {
    const cards = document.querySelectorAll('.saved-job-card');
    if (cards.length === 0) {
      location.reload();
    }
  }
</script>

<?php include '../includes/footer.php'; ?>