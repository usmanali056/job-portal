<?php
/**
 * JobNexus - HR Candidate Search
 * Search and browse job seeker profiles
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Company.php';

// Check authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_HR) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=hr/candidates');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User();

// Get HR's company
$stmt = $db->prepare("SELECT * FROM companies WHERE hr_user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company || $company['verification_status'] !== 'verified') {
  header('Location: ' . BASE_URL . '/hr/index.php');
  exit;
}

$hr = $userModel->findById($_SESSION['user_id']);

// Search filters
$searchQuery = trim($_GET['q'] ?? '');
$skillFilter = trim($_GET['skill'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$experienceFilter = $_GET['experience'] ?? '';

// Build search query
$sql = "
  SELECT sp.*, u.email, u.created_at as joined_at,
         (SELECT COUNT(*) FROM applications a 
          JOIN jobs j ON a.job_id = j.id 
          WHERE a.seeker_id = u.id AND j.company_id = ?) as applications_to_company
  FROM seeker_profiles sp
  JOIN users u ON sp.user_id = u.id
  WHERE u.role = 'seeker' AND u.is_active = 1
";
$params = [$company['id']];

if ($searchQuery) {
  $sql .= " AND (sp.first_name LIKE ? OR sp.last_name LIKE ? OR sp.headline LIKE ? OR sp.skills LIKE ?)";
  $searchLike = "%$searchQuery%";
  $params[] = $searchLike;
  $params[] = $searchLike;
  $params[] = $searchLike;
  $params[] = $searchLike;
}

if ($skillFilter) {
  $sql .= " AND sp.skills LIKE ?";
  $params[] = "%$skillFilter%";
}

if ($locationFilter) {
  $sql .= " AND sp.location LIKE ?";
  $params[] = "%$locationFilter%";
}

$sql .= " ORDER BY sp.updated_at DESC LIMIT 50";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get popular skills for filter suggestions
$stmt = $db->query("SELECT skills FROM seeker_profiles WHERE skills IS NOT NULL AND skills != '' AND skills != '[]' LIMIT 100");
$allSkills = $stmt->fetchAll(PDO::FETCH_COLUMN);
$skillsArray = [];
foreach ($allSkills as $skillsJson) {
  $parsed = json_decode($skillsJson, true);
  if (is_array($parsed)) {
    $skillsArray = array_merge($skillsArray, $parsed);
  }
}
$popularSkills = array_slice(array_count_values($skillsArray), 0, 10);
arsort($popularSkills);
$popularSkills = array_keys($popularSkills);

$pageTitle = 'Search Candidates';
require_once '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="hr-avatar">
        <?php echo strtoupper(substr($company['company_name'] ?? 'HR', 0, 2)); ?>
      </div>
      <h3><?php echo htmlspecialchars($company['company_name'] ?? $hr['email']); ?></h3>
      <span class="role-badge hr">HR Manager</span>
    </div>

    <nav class="sidebar-nav">
      <a href="<?php echo BASE_URL; ?>/hr/index.php" class="nav-item">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/jobs.php" class="nav-item">
        <i class="fas fa-briefcase"></i>
        <span>My Jobs</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/post-job.php" class="nav-item">
        <i class="fas fa-plus-circle"></i>
        <span>Post New Job</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/applications.php" class="nav-item">
        <i class="fas fa-file-alt"></i>
        <span>Applications</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/candidates.php" class="nav-item active">
        <i class="fas fa-users"></i>
        <span>Candidates</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/calendar.php" class="nav-item">
        <i class="fas fa-calendar-alt"></i>
        <span>Calendar</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/company.php" class="nav-item">
        <i class="fas fa-building"></i>
        <span>Company Profile</span>
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
        <h1><i class="fas fa-users"></i> Search Candidates</h1>
        <p>Find talented professionals for your open positions</p>
      </div>
    </div>

    <!-- Search Filters -->
    <div class="glass-card filters-card">
      <form method="GET" class="filters-form">
        <div class="filter-group search-group">
          <i class="fas fa-search"></i>
          <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($searchQuery); ?>"
            placeholder="Search by name, headline, or skills...">
        </div>

        <div class="filter-group">
          <input type="text" name="skill" class="form-control" value="<?php echo htmlspecialchars($skillFilter); ?>"
            placeholder="Skill (e.g., Python, React)">
        </div>

        <div class="filter-group">
          <input type="text" name="location" class="form-control"
            value="<?php echo htmlspecialchars($locationFilter); ?>" placeholder="Location">
        </div>

        <div class="filter-actions">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Search
          </button>
          <?php if ($searchQuery || $skillFilter || $locationFilter): ?>
            <a href="<?php echo BASE_URL; ?>/hr/candidates.php" class="btn btn-outline">
              <i class="fas fa-times"></i> Clear
            </a>
          <?php endif; ?>
        </div>
      </form>

      <?php if (!empty($popularSkills)): ?>
        <div class="popular-skills">
          <span class="skills-label">Popular skills:</span>
          <?php foreach ($popularSkills as $skill): ?>
            <a href="?skill=<?php echo urlencode($skill); ?>" class="skill-tag">
              <?php echo htmlspecialchars($skill); ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Results -->
    <?php if (empty($candidates)): ?>
      <div class="empty-state glass-card">
        <div class="empty-icon">
          <i class="fas fa-users"></i>
        </div>
        <h2>No Candidates Found</h2>
        <?php if ($searchQuery || $skillFilter || $locationFilter): ?>
          <p>Try adjusting your search criteria</p>
          <a href="<?php echo BASE_URL; ?>/hr/candidates.php" class="btn btn-primary">
            Clear Filters
          </a>
        <?php else: ?>
          <p>No job seekers have created profiles yet</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="candidates-grid">
        <?php foreach ($candidates as $candidate): ?>
          <div class="candidate-card glass-card">
            <div class="candidate-header">
              <div class="candidate-info">
                <h3><?php echo htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']); ?></h3>
                <?php if ($candidate['headline']): ?>
                  <p class="candidate-headline"><?php echo htmlspecialchars($candidate['headline']); ?></p>
                <?php endif; ?>
                <?php if ($candidate['location']): ?>
                  <span class="candidate-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($candidate['location']); ?>
                  </span>
                <?php endif; ?>
              </div>
              <?php if ($candidate['applications_to_company'] > 0): ?>
                <span class="applied-badge">
                  <i class="fas fa-check"></i> Applied
                </span>
              <?php endif; ?>
            </div>

            <?php if (!empty($candidate['bio'])): ?>
              <p class="candidate-summary">
                <?php echo htmlspecialchars(substr($candidate['bio'], 0, 150)); ?>
                <?php echo strlen($candidate['bio']) > 150 ? '...' : ''; ?>
              </p>
            <?php endif; ?>

            <?php if ($candidate['skills']): ?>
              <div class="candidate-skills">
                <?php
                $skillsArr = json_decode($candidate['skills'], true);
                if (is_array($skillsArr)):
                  $displaySkills = array_slice($skillsArr, 0, 5);
                  foreach ($displaySkills as $skill):
                    ?>
                    <span class="skill-badge"><?php echo htmlspecialchars($skill); ?></span>
                  <?php endforeach; ?>
                  <?php
                  $totalSkills = count($skillsArr);
                  if ($totalSkills > 5):
                    ?>
                    <span class="skill-more">+<?php echo $totalSkills - 5; ?> more</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="candidate-footer">
              <span class="joined-date">
                <i class="fas fa-calendar"></i>
                Joined <?php echo date('M Y', strtotime($candidate['joined_at'])); ?>
              </span>
              <a href="<?php echo BASE_URL; ?>/hr/seeker-profile.php?id=<?php echo $candidate['user_id']; ?>"
                class="btn btn-outline btn-sm">
                <i class="fas fa-user"></i> View Profile
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="results-info">
        <p>Showing <?php echo count($candidates); ?> candidate<?php echo count($candidates) !== 1 ? 's' : ''; ?></p>
      </div>
    <?php endif; ?>

  </main>
</div>

<style>
  .filters-card {
    padding: 1.5rem;
    margin-bottom: 2rem;
  }

  .filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
  }

  .filter-group {
    flex: 1;
    min-width: 180px;
  }

  .filter-group.search-group {
    flex: 2;
    position: relative;
  }

  .filter-group.search-group>i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
  }

  .filter-group.search-group input {
    padding-left: 2.75rem;
  }

  .filter-actions {
    display: flex;
    gap: 0.5rem;
  }

  .popular-skills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    align-items: center;
  }

  .skills-label {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-right: 0.5rem;
  }

  .skill-tag {
    padding: 0.25rem 0.75rem;
    background: rgba(0, 230, 118, 0.1);
    border: 1px solid rgba(0, 230, 118, 0.3);
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--primary-color);
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .skill-tag:hover {
    background: rgba(0, 230, 118, 0.2);
  }

  /* Candidates Grid */
  .candidates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
  }

  .candidate-card {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .candidate-header {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
  }

  .candidate-info {
    flex: 1;
    min-width: 0;
  }

  .candidate-info h3 {
    font-size: 1.1rem;
    margin: 0 0 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .candidate-headline {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0 0 0.5rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .candidate-location {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .candidate-location i {
    color: var(--primary-color);
  }

  .applied-badge {
    padding: 0.25rem 0.75rem;
    background: rgba(0, 230, 118, 0.15);
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--primary-color);
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  .candidate-summary {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.5;
    margin: 0;
  }

  .candidate-skills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .skill-badge {
    padding: 0.25rem 0.6rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    font-size: 0.75rem;
    color: var(--text-secondary);
  }

  .skill-more {
    font-size: 0.75rem;
    color: var(--text-muted);
  }

  .candidate-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    margin-top: auto;
  }

  .joined-date {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .joined-date i {
    color: var(--primary-color);
  }

  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
  }

  .empty-state .empty-icon {
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2rem;
    color: var(--text-muted);
  }

  .empty-state h2 {
    margin-bottom: 0.5rem;
  }

  .empty-state p {
    color: var(--text-muted);
    margin-bottom: 1.5rem;
  }

  .results-info {
    text-align: center;
    color: var(--text-muted);
    font-size: 0.875rem;
  }

  /* Button Styles */
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
    font-size: 0.9rem;
    text-decoration: none;
  }

  .btn-primary {
    background: linear-gradient(135deg, #00E676, #00C853);
    color: #000 !important;
    box-shadow: 0 4px 15px rgba(0, 230, 118, 0.3);
  }

  .btn-primary:hover {
    background: linear-gradient(135deg, #00ff88, #00E676);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 230, 118, 0.4);
  }

  .btn-outline {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
  }

  .btn-outline:hover {
    border-color: #00E676;
    color: #00E676;
  }

  .btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.8rem;
  }

  .form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    font-size: 0.9rem;
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

  /* Responsive */
  @media (max-width: 768px) {
    .candidates-grid {
      grid-template-columns: 1fr;
    }

    .filter-group {
      min-width: 100%;
    }

    .filter-group.search-group {
      flex: 1 1 100%;
    }
  }
</style>

<?php require_once '../includes/footer.php'; ?>