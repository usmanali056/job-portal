<?php
/**
 * JobNexus - Job Seeker Dashboard
 * Personal job search hub, applications tracking, and profile management
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Job.php';
require_once '../classes/Application.php';
require_once '../classes/SeekerProfile.php';
require_once '../classes/Event.php';

// Check authentication and role
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] !== ROLE_SEEKER) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=seeker');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User($db);
$jobModel = new Job($db);
$applicationModel = new Application($db);
$profileModel = new SeekerProfile($db);
$eventModel = new Event($db);

// Get current user info
$user = $userModel->findById($_SESSION['user_id']);
$profile = $profileModel->findByUserId($_SESSION['user_id']);

// Calculate profile completion
$profileCompletion = 20; // Base for having account
if ($profile) {
  if (!empty($profile['headline']))
    $profileCompletion += 10;
  if (!empty($profile['summary']))
    $profileCompletion += 15;
  if (!empty($profile['skills']))
    $profileCompletion += 15;
  if (!empty($profile['experience']))
    $profileCompletion += 15;
  if (!empty($profile['education']))
    $profileCompletion += 15;
  if (!empty($profile['resume_path']))
    $profileCompletion += 10;
}

// Dashboard Statistics
// My Applications
$stmt = $db->prepare("SELECT COUNT(*) as total FROM applications WHERE seeker_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$totalApplications = $stmt->fetch()['total'];

// Pending Applications
$stmt = $db->prepare("SELECT COUNT(*) as total FROM applications WHERE seeker_id = ? AND status = 'pending'");
$stmt->execute([$_SESSION['user_id']]);
$pendingApplications = $stmt->fetch()['total'];

// Shortlisted
$stmt = $db->prepare("SELECT COUNT(*) as total FROM applications WHERE seeker_id = ? AND status = 'shortlisted'");
$stmt->execute([$_SESSION['user_id']]);
$shortlistedCount = $stmt->fetch()['total'];

// Interview Scheduled
$stmt = $db->prepare("SELECT COUNT(*) as total FROM applications WHERE seeker_id = ? AND status = 'interview'");
$stmt->execute([$_SESSION['user_id']]);
$interviewCount = $stmt->fetch()['total'];

// Saved Jobs
$stmt = $db->prepare("SELECT COUNT(*) as total FROM saved_jobs WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$savedJobs = $stmt->fetch()['total'];

// Profile Views (simulated - would need tracking in production)
$profileViews = rand(5, 50);

// Upcoming Interviews
$stmt = $db->prepare("
    SELECT e.*, u.full_name as hr_name, c.name as company_name, j.title as job_title
    FROM events e
    JOIN users u ON e.hr_id = u.id
    LEFT JOIN companies c ON u.company_id = c.id
    LEFT JOIN jobs j ON e.job_id = j.id
    WHERE e.seeker_id = ? AND e.event_date >= CURDATE() AND e.status = 'scheduled'
    ORDER BY e.event_date ASC, e.event_time ASC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$upcomingInterviews = $stmt->fetchAll();

// Recent Applications
$stmt = $db->prepare("
    SELECT a.*, j.title as job_title, j.location, j.job_type, c.name as company_name, c.logo
    FROM applications a 
    JOIN jobs j ON a.job_id = j.id 
    LEFT JOIN companies c ON j.company_id = c.id
    WHERE a.seeker_id = ?
    ORDER BY a.applied_at DESC 
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$recentApplications = $stmt->fetchAll();

// Recommended Jobs (based on profile skills if available)
$recommendedJobs = [];
if ($profile && !empty($profile['skills'])) {
  $skills = json_decode($profile['skills'], true) ?: [];
  if (!empty($skills)) {
    $skillPatterns = array_map(function ($s) {
      return '%' . $s . '%'; }, array_slice($skills, 0, 5));
    $placeholders = str_repeat('j.skills LIKE ? OR ', count($skillPatterns) - 1) . 'j.skills LIKE ?';

    $sql = "SELECT j.*, c.name as company_name, c.logo 
                FROM jobs j 
                LEFT JOIN companies c ON j.company_id = c.id 
                WHERE j.status = 'active' AND j.deadline >= CURDATE() 
                AND ($placeholders)
                AND j.id NOT IN (SELECT job_id FROM applications WHERE seeker_id = ?)
                ORDER BY j.created_at DESC 
                LIMIT 6";

    $stmt = $db->prepare($sql);
    $params = array_merge($skillPatterns, [$_SESSION['user_id']]);
    $stmt->execute($params);
    $recommendedJobs = $stmt->fetchAll();
  }
}

// If no recommendations, get latest jobs
if (empty($recommendedJobs)) {
  $stmt = $db->prepare("
        SELECT j.*, c.name as company_name, c.logo 
        FROM jobs j 
        LEFT JOIN companies c ON j.company_id = c.id 
        WHERE j.status = 'active' AND j.deadline >= CURDATE()
        AND j.id NOT IN (SELECT job_id FROM applications WHERE seeker_id = ?)
        ORDER BY j.created_at DESC 
        LIMIT 6
    ");
  $stmt->execute([$_SESSION['user_id']]);
  $recommendedJobs = $stmt->fetchAll();
}

// Saved Jobs List
$stmt = $db->prepare("
    SELECT j.*, c.name as company_name, c.logo
    FROM saved_jobs sj
    JOIN jobs j ON sj.job_id = j.id
    LEFT JOIN companies c ON j.company_id = c.id
    WHERE sj.user_id = ?
    ORDER BY sj.saved_at DESC
    LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$savedJobsList = $stmt->fetchAll();

// Application Stats
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count 
    FROM applications 
    WHERE seeker_id = ?
    GROUP BY status
");
$stmt->execute([$_SESSION['user_id']]);
$appStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'My Dashboard';
require_once '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="seeker-avatar">
        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
      </div>
      <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
      <span class="role-badge seeker">Job Seeker</span>
      <?php if ($profile && $profile['headline']): ?>
        <p class="user-headline"><?php echo htmlspecialchars($profile['headline']); ?></p>
      <?php endif; ?>
    </div>

    <!-- Profile Completion -->
    <div class="profile-completion">
      <div class="completion-header">
        <span>Profile Strength</span>
        <span class="completion-percent"><?php echo $profileCompletion; ?>%</span>
      </div>
      <div class="completion-bar">
        <div class="completion-fill" style="width: <?php echo $profileCompletion; ?>%"></div>
      </div>
      <?php if ($profileCompletion < 100): ?>
        <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="completion-link">
          Complete your profile <i class="fas fa-arrow-right"></i>
        </a>
      <?php endif; ?>
    </div>

    <nav class="sidebar-nav">
      <a href="<?php echo BASE_URL; ?>/seeker/index.php" class="nav-item active">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="nav-item">
        <i class="fas fa-user"></i>
        <span>My Profile</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/applications.php" class="nav-item">
        <i class="fas fa-file-alt"></i>
        <span>My Applications</span>
        <?php if ($pendingApplications > 0): ?>
          <span class="badge"><?php echo $pendingApplications; ?></span>
        <?php endif; ?>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/saved-jobs.php" class="nav-item">
        <i class="fas fa-bookmark"></i>
        <span>Saved Jobs</span>
        <?php if ($savedJobs > 0): ?>
          <span class="badge info"><?php echo $savedJobs; ?></span>
        <?php endif; ?>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/calendar.php" class="nav-item">
        <i class="fas fa-calendar-alt"></i>
        <span>Calendar</span>
        <?php if (count($upcomingInterviews) > 0): ?>
          <span class="badge success"><?php echo count($upcomingInterviews); ?></span>
        <?php endif; ?>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/resume.php" class="nav-item">
        <i class="fas fa-file-pdf"></i>
        <span>Resume Builder</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/jobs" class="nav-item">
        <i class="fas fa-search"></i>
        <span>Browse Jobs</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/seeker/settings.php" class="nav-item">
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
        <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $user['full_name'])[0]); ?>!</h1>
        <p>Here's what's happening with your job search</p>
      </div>
      <div class="header-right">
        <a href="<?php echo BASE_URL; ?>/jobs" class="btn btn-primary">
          <i class="fas fa-search"></i> Find Jobs
        </a>
      </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
      <div class="stat-card primary">
        <div class="stat-icon">
          <i class="fas fa-paper-plane"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($totalApplications); ?></h3>
          <p>Applications Sent</p>
        </div>
        <div class="stat-footer">
          <a href="<?php echo BASE_URL; ?>/seeker/applications.php" class="stat-link">
            View all <i class="fas fa-arrow-right"></i>
          </a>
        </div>
      </div>

      <div class="stat-card warning">
        <div class="stat-icon">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($pendingApplications); ?></h3>
          <p>Under Review</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label">Awaiting response</span>
        </div>
      </div>

      <div class="stat-card success">
        <div class="stat-icon">
          <i class="fas fa-star"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($shortlistedCount); ?></h3>
          <p>Shortlisted</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label">Great progress!</span>
        </div>
      </div>

      <div class="stat-card purple">
        <div class="stat-icon">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($interviewCount); ?></h3>
          <p>Interviews</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label">Scheduled</span>
        </div>
      </div>

      <div class="stat-card info">
        <div class="stat-icon">
          <i class="fas fa-bookmark"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($savedJobs); ?></h3>
          <p>Saved Jobs</p>
        </div>
        <div class="stat-footer">
          <a href="<?php echo BASE_URL; ?>/seeker/saved-jobs.php" class="stat-link">
            View saved <i class="fas fa-arrow-right"></i>
          </a>
        </div>
      </div>

      <div class="stat-card secondary">
        <div class="stat-icon">
          <i class="fas fa-eye"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo number_format($profileViews); ?></h3>
          <p>Profile Views</p>
        </div>
        <div class="stat-footer">
          <span class="stat-label">This month</span>
        </div>
      </div>
    </div>

    <!-- Upcoming Interviews Alert -->
    <?php if (!empty($upcomingInterviews)): ?>
      <div class="interview-alert">
        <div class="alert-icon">
          <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="alert-content">
          <h3>Upcoming Interviews</h3>
          <p>You have <?php echo count($upcomingInterviews); ?> interview(s) scheduled</p>
        </div>
        <div class="alert-interviews">
          <?php foreach (array_slice($upcomingInterviews, 0, 2) as $interview): ?>
            <div class="mini-interview">
              <div class="interview-date-mini">
                <span class="day"><?php echo date('d', strtotime($interview['event_date'])); ?></span>
                <span class="month"><?php echo date('M', strtotime($interview['event_date'])); ?></span>
              </div>
              <div class="interview-info-mini">
                <strong><?php echo htmlspecialchars($interview['company_name']); ?></strong>
                <span><?php echo htmlspecialchars($interview['job_title'] ?? 'Interview'); ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <a href="<?php echo BASE_URL; ?>/seeker/calendar.php" class="btn btn-primary btn-sm">
          View Calendar
        </a>
      </div>
    <?php endif; ?>

    <!-- Application Status Pipeline -->
    <div class="dashboard-section">
      <div class="section-header">
        <h2><i class="fas fa-chart-line"></i> Application Journey</h2>
      </div>

      <div class="application-journey">
        <?php
        $journey = [
          'pending' => ['label' => 'Applied', 'icon' => 'paper-plane'],
          'reviewed' => ['label' => 'Reviewed', 'icon' => 'eye'],
          'shortlisted' => ['label' => 'Shortlisted', 'icon' => 'star'],
          'interview' => ['label' => 'Interview', 'icon' => 'calendar'],
          'offered' => ['label' => 'Offered', 'icon' => 'gift'],
          'hired' => ['label' => 'Hired', 'icon' => 'trophy']
        ];
        $total = array_sum($appStats) ?: 1;
        ?>

        <div class="journey-track">
          <?php foreach ($journey as $status => $info): ?>
            <?php $count = $appStats[$status] ?? 0; ?>
            <div class="journey-step <?php echo $count > 0 ? 'has-items' : ''; ?>">
              <div class="step-icon">
                <i class="fas fa-<?php echo $info['icon']; ?>"></i>
              </div>
              <div class="step-count"><?php echo $count; ?></div>
              <div class="step-label"><?php echo $info['label']; ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php
        $rejectedCount = $appStats['rejected'] ?? 0;
        if ($rejectedCount > 0):
          ?>
          <div class="rejected-note">
            <i class="fas fa-info-circle"></i>
            <?php echo $rejectedCount; ?> application(s) were not successful. Don't give up!
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Main Panels Grid -->
    <div class="dashboard-panels seeker-panels">
      <!-- Recent Applications -->
      <div class="dashboard-panel">
        <div class="panel-header">
          <h2><i class="fas fa-file-alt"></i> Recent Applications</h2>
          <a href="<?php echo BASE_URL; ?>/seeker/applications.php" class="btn btn-text">
            View All <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <div class="panel-content">
          <?php if (empty($recentApplications)): ?>
            <div class="empty-state">
              <i class="fas fa-paper-plane"></i>
              <h4>No applications yet</h4>
              <p>Start your job search journey today!</p>
              <a href="<?php echo BASE_URL; ?>/jobs" class="btn btn-primary btn-sm">Browse Jobs</a>
            </div>
          <?php else: ?>
            <div class="applications-timeline">
              <?php foreach ($recentApplications as $app): ?>
                <div class="timeline-item">
                  <div class="timeline-logo">
                    <?php if ($app['logo']): ?>
                      <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo $app['logo']; ?>" alt="">
                    <?php else: ?>
                      <i class="fas fa-building"></i>
                    <?php endif; ?>
                  </div>
                  <div class="timeline-content">
                    <h4><?php echo htmlspecialchars($app['job_title']); ?></h4>
                    <p class="company"><?php echo htmlspecialchars($app['company_name']); ?></p>
                    <div class="timeline-meta">
                      <span class="location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($app['location']); ?>
                      </span>
                      <span class="date">
                        Applied <?php echo date('M j', strtotime($app['applied_at'])); ?>
                      </span>
                    </div>
                  </div>
                  <div class="timeline-status">
                    <span class="status-badge <?php echo $app['status']; ?>">
                      <?php echo ucfirst($app['status']); ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Saved Jobs -->
      <div class="dashboard-panel">
        <div class="panel-header">
          <h2><i class="fas fa-bookmark"></i> Saved Jobs</h2>
          <a href="<?php echo BASE_URL; ?>/seeker/saved-jobs.php" class="btn btn-text">
            View All <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <div class="panel-content">
          <?php if (empty($savedJobsList)): ?>
            <div class="empty-state">
              <i class="fas fa-bookmark"></i>
              <h4>No saved jobs</h4>
              <p>Save jobs you're interested in for later</p>
              <a href="<?php echo BASE_URL; ?>/jobs" class="btn btn-primary btn-sm">Find Jobs</a>
            </div>
          <?php else: ?>
            <div class="saved-jobs-list">
              <?php foreach ($savedJobsList as $job): ?>
                <div class="saved-job-item">
                  <div class="saved-logo">
                    <?php if ($job['logo']): ?>
                      <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo $job['logo']; ?>" alt="">
                    <?php else: ?>
                      <i class="fas fa-building"></i>
                    <?php endif; ?>
                  </div>
                  <div class="saved-info">
                    <h4>
                      <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>">
                        <?php echo htmlspecialchars($job['title']); ?>
                      </a>
                    </h4>
                    <p><?php echo htmlspecialchars($job['company_name']); ?></p>
                  </div>
                  <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>"
                    class="btn btn-outline-primary btn-sm">
                    Apply
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Recommended Jobs -->
    <div class="dashboard-section">
      <div class="section-header">
        <h2><i class="fas fa-magic"></i> Recommended for You</h2>
        <a href="<?php echo BASE_URL; ?>/jobs" class="btn btn-outline-primary btn-sm">
          Browse All Jobs
        </a>
      </div>

      <?php if (empty($recommendedJobs)): ?>
        <div class="empty-state">
          <i class="fas fa-search"></i>
          <h4>No recommendations yet</h4>
          <p>Complete your profile to get personalized job recommendations</p>
          <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="btn btn-primary">Complete Profile</a>
        </div>
      <?php else: ?>
        <div class="recommended-jobs-grid">
          <?php foreach ($recommendedJobs as $job): ?>
            <div class="job-card-mini">
              <div class="job-card-header">
                <div class="company-logo-mini">
                  <?php if ($job['logo']): ?>
                    <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo $job['logo']; ?>" alt="">
                  <?php else: ?>
                    <i class="fas fa-building"></i>
                  <?php endif; ?>
                </div>
                <div class="job-card-title">
                  <h4>
                    <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>">
                      <?php echo htmlspecialchars($job['title']); ?>
                    </a>
                  </h4>
                  <p><?php echo htmlspecialchars($job['company_name']); ?></p>
                </div>
              </div>
              <div class="job-card-meta">
                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                <span class="job-type-badge <?php echo $job['job_type']; ?>">
                  <?php echo ucfirst(str_replace('-', ' ', $job['job_type'])); ?>
                </span>
              </div>
              <?php if ($job['salary_min'] || $job['salary_max']): ?>
                <div class="job-salary">
                  <i class="fas fa-dollar-sign"></i>
                  <?php
                  if ($job['salary_min'] && $job['salary_max']) {
                    echo number_format($job['salary_min']) . ' - ' . number_format($job['salary_max']);
                  } elseif ($job['salary_min']) {
                    echo 'From ' . number_format($job['salary_min']);
                  } else {
                    echo 'Up to ' . number_format($job['salary_max']);
                  }
                  ?>
                </div>
              <?php endif; ?>
              <div class="job-card-actions">
                <a href="<?php echo BASE_URL; ?>/jobs/view.php?id=<?php echo $job['id']; ?>"
                  class="btn btn-primary btn-sm btn-block">
                  View & Apply
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="dashboard-section">
      <div class="section-header">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
      </div>

      <div class="quick-actions-grid">
        <a href="<?php echo BASE_URL; ?>/jobs" class="quick-action-card">
          <div class="action-icon primary">
            <i class="fas fa-search"></i>
          </div>
          <span>Search Jobs</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="quick-action-card">
          <div class="action-icon info">
            <i class="fas fa-user-edit"></i>
          </div>
          <span>Edit Profile</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/seeker/resume.php" class="quick-action-card">
          <div class="action-icon purple">
            <i class="fas fa-file-alt"></i>
          </div>
          <span>Build Resume</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/seeker/applications.php" class="quick-action-card">
          <div class="action-icon warning">
            <i class="fas fa-list-alt"></i>
          </div>
          <span>Track Applications</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/seeker/calendar.php" class="quick-action-card">
          <div class="action-icon success">
            <i class="fas fa-calendar"></i>
          </div>
          <span>View Calendar</span>
        </a>

        <a href="<?php echo BASE_URL; ?>/seeker/settings.php" class="quick-action-card">
          <div class="action-icon secondary">
            <i class="fas fa-cog"></i>
          </div>
          <span>Settings</span>
        </a>
      </div>
    </div>
  </main>
</div>

<style>
  /* Seeker Dashboard Specific Styles */
  .seeker-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #9C27B0, #7B1FA2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 2rem;
    font-weight: 600;
    color: white;
  }

  .user-headline {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-top: 0.5rem;
    text-align: center;
  }

  /* Profile Completion */
  .profile-completion {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .completion-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
  }

  .completion-percent {
    color: var(--primary-color);
    font-weight: 600;
  }

  .completion-bar {
    height: 6px;
    background: var(--bg-dark);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 0.5rem;
  }

  .completion-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--primary-light));
    border-radius: 3px;
    transition: width 0.5s ease;
  }

  .completion-link {
    font-size: 0.8rem;
    color: var(--primary-color);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .completion-link:hover {
    text-decoration: underline;
  }

  /* Interview Alert */
  .interview-alert {
    background: linear-gradient(135deg, rgba(156, 39, 176, 0.1), rgba(0, 230, 118, 0.05));
    border: 1px solid rgba(156, 39, 176, 0.3);
    border-radius: 16px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    margin-bottom: 2rem;
  }

  .interview-alert .alert-icon {
    width: 60px;
    height: 60px;
    background: rgba(156, 39, 176, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9C27B0;
    font-size: 1.5rem;
  }

  .interview-alert .alert-content {
    flex: 1;
  }

  .interview-alert .alert-content h3 {
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
  }

  .interview-alert .alert-content p {
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  .alert-interviews {
    display: flex;
    gap: 1rem;
  }

  .mini-interview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: var(--card-bg);
    padding: 0.75rem 1rem;
    border-radius: 10px;
  }

  .interview-date-mini {
    text-align: center;
  }

  .interview-date-mini .day {
    display: block;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-color);
    line-height: 1;
  }

  .interview-date-mini .month {
    font-size: 0.7rem;
    color: var(--text-muted);
    text-transform: uppercase;
  }

  .interview-info-mini strong {
    display: block;
    font-size: 0.85rem;
  }

  .interview-info-mini span {
    font-size: 0.75rem;
    color: var(--text-muted);
  }

  /* Application Journey */
  .application-journey {
    padding: 1rem 0;
  }

  .journey-track {
    display: flex;
    justify-content: space-between;
    position: relative;
    padding: 0 1rem;
  }

  .journey-track::before {
    content: '';
    position: absolute;
    top: 30px;
    left: 60px;
    right: 60px;
    height: 3px;
    background: var(--border-color);
    z-index: 0;
  }

  .journey-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 1;
  }

  .journey-step .step-icon {
    width: 60px;
    height: 60px;
    background: var(--bg-dark);
    border: 3px solid var(--border-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
    transition: all 0.3s ease;
  }

  .journey-step.has-items .step-icon {
    background: rgba(0, 230, 118, 0.1);
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .journey-step .step-count {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-light);
  }

  .journey-step .step-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .rejected-note {
    text-align: center;
    margin-top: 1.5rem;
    padding: 0.75rem;
    background: rgba(244, 67, 54, 0.05);
    border-radius: 8px;
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .rejected-note i {
    color: #F44336;
    margin-right: 0.5rem;
  }

  /* Seeker Panels */
  .seeker-panels {
    grid-template-columns: 1fr 1fr;
  }

  /* Applications Timeline */
  .applications-timeline {
    padding: 0;
  }

  .timeline-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    transition: background 0.3s ease;
  }

  .timeline-item:hover {
    background: rgba(0, 230, 118, 0.02);
  }

  .timeline-item:last-child {
    border-bottom: none;
  }

  .timeline-logo {
    width: 45px;
    height: 45px;
    background: var(--bg-dark);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .timeline-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .timeline-logo i {
    color: var(--text-muted);
  }

  .timeline-content {
    flex: 1;
  }

  .timeline-content h4 {
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
  }

  .timeline-content .company {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
  }

  .timeline-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.75rem;
    color: var(--text-muted);
  }

  .timeline-meta i {
    margin-right: 0.25rem;
  }

  .timeline-status {
    text-align: right;
  }

  /* Saved Jobs List */
  .saved-jobs-list {
    padding: 0;
  }

  .saved-job-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .saved-job-item:last-child {
    border-bottom: none;
  }

  .saved-logo {
    width: 40px;
    height: 40px;
    background: var(--bg-dark);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .saved-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .saved-logo i {
    color: var(--text-muted);
  }

  .saved-info {
    flex: 1;
  }

  .saved-info h4 {
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
  }

  .saved-info h4 a {
    color: var(--text-light);
    text-decoration: none;
  }

  .saved-info h4 a:hover {
    color: var(--primary-color);
  }

  .saved-info p {
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  /* Recommended Jobs Grid */
  .recommended-jobs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
  }

  .job-card-mini {
    background: var(--bg-dark);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
  }

  .job-card-mini:hover {
    border-color: var(--primary-color);
    transform: translateY(-3px);
    box-shadow: 0 5px 20px rgba(0, 230, 118, 0.1);
  }

  .job-card-header {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .company-logo-mini {
    width: 45px;
    height: 45px;
    background: var(--card-bg);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .company-logo-mini img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .company-logo-mini i {
    color: var(--text-muted);
  }

  .job-card-title h4 {
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
  }

  .job-card-title h4 a {
    color: var(--text-light);
    text-decoration: none;
  }

  .job-card-title h4 a:hover {
    color: var(--primary-color);
  }

  .job-card-title p {
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  .job-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  .job-card-meta i {
    margin-right: 0.25rem;
    color: var(--primary-color);
  }

  .job-salary {
    color: var(--primary-color);
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 1rem;
  }

  .job-salary i {
    margin-right: 0.25rem;
  }

  .job-card-actions .btn-block {
    width: 100%;
  }

  /* Empty State */
  .empty-state {
    padding: 3rem;
    text-align: center;
  }

  .empty-state i {
    font-size: 3rem;
    color: var(--text-muted);
    opacity: 0.3;
    margin-bottom: 1rem;
  }

  .empty-state h4 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
  }

  .empty-state p {
    color: var(--text-muted);
    margin-bottom: 1rem;
  }

  /* Stat Link */
  .stat-link {
    color: var(--primary-color);
    text-decoration: none;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .stat-link:hover {
    text-decoration: underline;
  }

  /* Badge Variants */
  .nav-item .badge.info {
    background: #2196F3;
  }

  .nav-item .badge.success {
    background: #4CAF50;
  }

  /* Quick Action Colors */
  .quick-action-card .action-icon.primary {
    background: rgba(0, 230, 118, 0.1);
    color: var(--primary-color);
  }

  .quick-action-card .action-icon.info {
    background: rgba(33, 150, 243, 0.1);
    color: #2196F3;
  }

  .quick-action-card .action-icon.purple {
    background: rgba(156, 39, 176, 0.1);
    color: #9C27B0;
  }

  .quick-action-card .action-icon.warning {
    background: rgba(255, 193, 7, 0.1);
    color: #FFC107;
  }

  .quick-action-card .action-icon.success {
    background: rgba(76, 175, 80, 0.1);
    color: #4CAF50;
  }

  .quick-action-card .action-icon.secondary {
    background: rgba(158, 158, 158, 0.1);
    color: #9E9E9E;
  }

  .quick-action-card:hover .action-icon.primary {
    background: var(--primary-color);
    color: var(--bg-dark);
  }

  .quick-action-card:hover .action-icon.info {
    background: #2196F3;
    color: white;
  }

  .quick-action-card:hover .action-icon.purple {
    background: #9C27B0;
    color: white;
  }

  .quick-action-card:hover .action-icon.warning {
    background: #FFC107;
    color: var(--bg-dark);
  }

  .quick-action-card:hover .action-icon.success {
    background: #4CAF50;
    color: white;
  }

  .quick-action-card:hover .action-icon.secondary {
    background: #9E9E9E;
    color: white;
  }

  /* Responsive */
  @media (max-width: 1200px) {
    .seeker-panels {
      grid-template-columns: 1fr;
    }

    .interview-alert {
      flex-wrap: wrap;
    }

    .alert-interviews {
      width: 100%;
      margin-top: 1rem;
    }
  }

  @media (max-width: 768px) {
    .journey-track {
      flex-wrap: wrap;
      justify-content: center;
      gap: 1rem;
    }

    .journey-track::before {
      display: none;
    }

    .journey-step .step-icon {
      width: 50px;
      height: 50px;
      font-size: 1rem;
    }

    .interview-alert {
      flex-direction: column;
      text-align: center;
    }

    .alert-interviews {
      flex-direction: column;
    }

    .timeline-item {
      flex-direction: column;
      align-items: flex-start;
    }

    .timeline-status {
      width: 100%;
      text-align: left;
      margin-top: 0.5rem;
    }

    .recommended-jobs-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php require_once '../includes/footer.php'; ?>