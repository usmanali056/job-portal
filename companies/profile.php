<?php
/**
 * JobNexus - Company Profile Page
 * Public view of company details and job listings
 */

session_start();
require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/Company.php';
require_once '../classes/Job.php';

$db = Database::getInstance()->getConnection();
$companyModel = new Company($db);
$jobModel = new Job($db);

// Get company ID from URL
$companyId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$companyId) {
  header('Location: ../index.php');
  exit;
}

// Get company details
$company = $companyModel->getById($companyId);

if (!$company || !$company['is_verified']) {
  // Show 404 or redirect
  header('Location: ../index.php');
  exit;
}

// Get active jobs for this company
$stmt = $db->prepare("
    SELECT j.*, 
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count
    FROM jobs j 
    WHERE j.company_id = ? AND j.status = 'active'
    ORDER BY j.created_at DESC
");
$stmt->execute([$companyId]);
$activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get company statistics
$stmt = $db->prepare("SELECT COUNT(*) FROM jobs WHERE company_id = ? AND status = 'active'");
$stmt->execute([$companyId]);
$totalActiveJobs = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT a.user_id) 
    FROM applications a 
    JOIN jobs j ON a.job_id = j.id 
    WHERE j.company_id = ?
");
$stmt->execute([$companyId]);
$totalApplicants = $stmt->fetchColumn();

// Check if user has saved any jobs from this company
$savedJobs = [];
if (isset($_SESSION['user_id']) && $_SESSION['role'] === ROLE_SEEKER) {
  $stmt = $db->prepare("
        SELECT job_id FROM saved_jobs 
        WHERE user_id = ? AND job_id IN (SELECT id FROM jobs WHERE company_id = ?)
    ");
  $stmt->execute([$_SESSION['user_id'], $companyId]);
  $savedJobs = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$pageTitle = htmlspecialchars($company['name']) . " - JobNexus";
include '../includes/header.php';
?>

<div class="company-profile-page">
  <!-- Company Header -->
  <div class="company-hero">
    <div class="container">
      <div class="company-hero-content">
        <div class="company-logo-large">
          <?php if ($company['logo']): ?>
            <img src="../uploads/logos/<?php echo htmlspecialchars($company['logo']); ?>"
              alt="<?php echo htmlspecialchars($company['name']); ?>">
          <?php else: ?>
            <span><?php echo strtoupper(substr($company['name'], 0, 2)); ?></span>
          <?php endif; ?>
        </div>
        <div class="company-hero-info">
          <div class="company-badges">
            <span class="verified-badge">
              <i class="fas fa-check-circle"></i> Verified Company
            </span>
            <?php if ($company['industry']): ?>
              <span class="industry-badge"><?php echo htmlspecialchars($company['industry']); ?></span>
            <?php endif; ?>
          </div>
          <h1><?php echo htmlspecialchars($company['name']); ?></h1>
          <div class="company-meta">
            <?php if ($company['location']): ?>
              <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($company['location']); ?></span>
            <?php endif; ?>
            <?php if ($company['website']): ?>
              <a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank">
                <i class="fas fa-globe"></i> Website
              </a>
            <?php endif; ?>
            <?php if ($company['company_size']): ?>
              <span><i class="fas fa-users"></i> <?php echo htmlspecialchars($company['company_size']); ?>
                employees</span>
            <?php endif; ?>
          </div>
          <div class="company-stats-mini">
            <div class="stat-item">
              <span class="stat-value"><?php echo $totalActiveJobs; ?></span>
              <span class="stat-label">Open Positions</span>
            </div>
            <div class="stat-item">
              <span class="stat-value"><?php echo $totalApplicants; ?>+</span>
              <span class="stat-label">Applicants</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="company-content-grid">
      <!-- Main Content -->
      <div class="company-main">
        <!-- About Section -->
        <section class="glass-card company-section">
          <h2><i class="fas fa-building"></i> About <?php echo htmlspecialchars($company['name']); ?></h2>
          <div class="about-content">
            <?php if ($company['description']): ?>
              <?php echo nl2br(htmlspecialchars($company['description'])); ?>
            <?php else: ?>
              <p class="placeholder-text">This company hasn't added a description yet.</p>
            <?php endif; ?>
          </div>
        </section>

        <!-- Open Positions -->
        <section class="glass-card company-section">
          <div class="section-header">
            <h2><i class="fas fa-briefcase"></i> Open Positions</h2>
            <span class="job-count"><?php echo count($activeJobs); ?> jobs</span>
          </div>

          <?php if (empty($activeJobs)): ?>
            <div class="empty-state">
              <i class="fas fa-briefcase"></i>
              <p>No open positions at the moment</p>
              <p class="subtext">Check back later for new opportunities</p>
            </div>
          <?php else: ?>
            <div class="jobs-list">
              <?php foreach ($activeJobs as $job): ?>
                <?php
                $isSaved = in_array($job['id'], $savedJobs);
                $deadline = $job['deadline'] ? new DateTime($job['deadline']) : null;
                $daysLeft = $deadline ? (new DateTime())->diff($deadline)->days : null;
                ?>
                <div class="job-list-item">
                  <div class="job-main-info">
                    <h3>
                      <a href="../jobs/view.php?id=<?php echo $job['id']; ?>">
                        <?php echo htmlspecialchars($job['title']); ?>
                      </a>
                    </h3>
                    <div class="job-details">
                      <span class="job-type <?php echo $job['job_type']; ?>">
                        <i class="fas fa-briefcase"></i>
                        <?php echo ucfirst(str_replace('-', ' ', $job['job_type'])); ?>
                      </span>
                      <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['location']); ?></span>
                      <?php if ($job['salary_min'] || $job['salary_max']): ?>
                        <span class="salary">
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
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="job-tags">
                      <span class="category-tag"><?php echo htmlspecialchars($job['category']); ?></span>
                      <?php if ($job['experience_level']): ?>
                        <span class="experience-tag"><?php echo ucfirst($job['experience_level']); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="job-actions">
                    <?php if ($deadline): ?>
                      <span class="deadline <?php echo $daysLeft <= 3 ? 'urgent' : ''; ?>">
                        <i class="fas fa-clock"></i>
                        <?php echo $daysLeft; ?> days left
                      </span>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === ROLE_SEEKER): ?>
                      <button class="save-btn <?php echo $isSaved ? 'active' : ''; ?>"
                        onclick="toggleSave(<?php echo $job['id']; ?>, this)">
                        <i class="fas fa-heart"></i>
                      </button>
                    <?php endif; ?>
                    <a href="../jobs/view.php?id=<?php echo $job['id']; ?>" class="btn btn-primary btn-sm">
                      View & Apply
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- Sidebar -->
      <aside class="company-sidebar">
        <!-- Company Info Card -->
        <div class="glass-card sidebar-card">
          <h3>Company Info</h3>
          <ul class="info-list">
            <?php if ($company['industry']): ?>
              <li>
                <i class="fas fa-industry"></i>
                <div>
                  <strong>Industry</strong>
                  <span><?php echo htmlspecialchars($company['industry']); ?></span>
                </div>
              </li>
            <?php endif; ?>
            <?php if ($company['company_size']): ?>
              <li>
                <i class="fas fa-users"></i>
                <div>
                  <strong>Company Size</strong>
                  <span><?php echo htmlspecialchars($company['company_size']); ?> employees</span>
                </div>
              </li>
            <?php endif; ?>
            <?php if ($company['founded_year']): ?>
              <li>
                <i class="fas fa-calendar-alt"></i>
                <div>
                  <strong>Founded</strong>
                  <span><?php echo htmlspecialchars($company['founded_year']); ?></span>
                </div>
              </li>
            <?php endif; ?>
            <?php if ($company['location']): ?>
              <li>
                <i class="fas fa-map-marker-alt"></i>
                <div>
                  <strong>Headquarters</strong>
                  <span><?php echo htmlspecialchars($company['location']); ?></span>
                </div>
              </li>
            <?php endif; ?>
          </ul>
        </div>

        <!-- Contact Card -->
        <div class="glass-card sidebar-card">
          <h3>Get in Touch</h3>
          <div class="contact-links">
            <?php if ($company['website']): ?>
              <a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank" class="contact-link">
                <i class="fas fa-globe"></i>
                <span>Visit Website</span>
              </a>
            <?php endif; ?>
            <?php if ($company['email']): ?>
              <a href="mailto:<?php echo htmlspecialchars($company['email']); ?>" class="contact-link">
                <i class="fas fa-envelope"></i>
                <span><?php echo htmlspecialchars($company['email']); ?></span>
              </a>
            <?php endif; ?>
            <?php if ($company['phone']): ?>
              <a href="tel:<?php echo htmlspecialchars($company['phone']); ?>" class="contact-link">
                <i class="fas fa-phone"></i>
                <span><?php echo htmlspecialchars($company['phone']); ?></span>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Social Links -->
        <?php if ($company['linkedin'] || $company['twitter'] || $company['facebook']): ?>
          <div class="glass-card sidebar-card">
            <h3>Follow Us</h3>
            <div class="social-links">
              <?php if ($company['linkedin']): ?>
                <a href="<?php echo htmlspecialchars($company['linkedin']); ?>" target="_blank" class="social-btn linkedin">
                  <i class="fab fa-linkedin"></i>
                </a>
              <?php endif; ?>
              <?php if ($company['twitter']): ?>
                <a href="<?php echo htmlspecialchars($company['twitter']); ?>" target="_blank" class="social-btn twitter">
                  <i class="fab fa-twitter"></i>
                </a>
              <?php endif; ?>
              <?php if ($company['facebook']): ?>
                <a href="<?php echo htmlspecialchars($company['facebook']); ?>" target="_blank" class="social-btn facebook">
                  <i class="fab fa-facebook"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Similar Companies -->
        <?php
        $stmt = $db->prepare("
                    SELECT id, name, logo, location 
                    FROM companies 
                    WHERE is_verified = 1 AND id != ? AND industry = ?
                    LIMIT 3
                ");
        $stmt->execute([$companyId, $company['industry']]);
        $similarCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($similarCompanies)): ?>
          <div class="glass-card sidebar-card">
            <h3>Similar Companies</h3>
            <div class="similar-companies-list">
              <?php foreach ($similarCompanies as $similar): ?>
                <a href="profile.php?id=<?php echo $similar['id']; ?>" class="similar-company-item">
                  <div class="company-logo-small">
                    <?php if ($similar['logo']): ?>
                      <img src="../uploads/logos/<?php echo htmlspecialchars($similar['logo']); ?>" alt="">
                    <?php else: ?>
                      <?php echo strtoupper(substr($similar['name'], 0, 2)); ?>
                    <?php endif; ?>
                  </div>
                  <div class="company-mini-info">
                    <strong><?php echo htmlspecialchars($similar['name']); ?></strong>
                    <span><?php echo htmlspecialchars($similar['location'] ?? 'Location not specified'); ?></span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>

<style>
  .company-profile-page {
    min-height: 100vh;
    padding-bottom: 4rem;
  }

  .company-hero {
    background: linear-gradient(135deg, rgba(0, 230, 118, 0.15) 0%, rgba(0, 230, 118, 0.02) 100%);
    padding: 4rem 0;
    margin-bottom: 3rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  }

  .company-hero-content {
    display: flex;
    align-items: flex-start;
    gap: 2rem;
  }

  .company-logo-large {
    width: 120px;
    height: 120px;
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
  }

  .company-logo-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .company-logo-large span {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--primary-color);
  }

  .company-badges {
    display: flex;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
  }

  .verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 600;
  }

  .industry-badge {
    padding: 0.375rem 0.75rem;
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.8);
    border-radius: 2rem;
    font-size: 0.75rem;
  }

  .company-hero-info h1 {
    font-size: 2.5rem;
    margin: 0 0 1rem;
    color: var(--text-primary);
  }

  .company-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
  }

  .company-meta span,
  .company-meta a {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
  }

  .company-meta a:hover {
    color: var(--primary-color);
  }

  .company-meta i {
    color: var(--primary-color);
  }

  .company-stats-mini {
    display: flex;
    gap: 2rem;
  }

  .stat-item {
    text-align: center;
  }

  .stat-value {
    display: block;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--primary-color);
  }

  .stat-label {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  /* Content Grid */
  .company-content-grid {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 2rem;
  }

  .company-section {
    margin-bottom: 2rem;
  }

  .company-section h2 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.25rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .company-section h2 i {
    color: var(--primary-color);
  }

  .about-content {
    color: rgba(255, 255, 255, 0.8);
    line-height: 1.8;
  }

  .placeholder-text {
    color: rgba(255, 255, 255, 0.4);
    font-style: italic;
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .section-header h2 {
    margin: 0;
    padding: 0;
    border: none;
  }

  .job-count {
    padding: 0.375rem 0.75rem;
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 600;
  }

  /* Jobs List */
  .jobs-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .job-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 0.75rem;
    border: 1px solid rgba(255, 255, 255, 0.05);
    transition: all 0.3s ease;
  }

  .job-list-item:hover {
    border-color: rgba(0, 230, 118, 0.3);
    background: rgba(255, 255, 255, 0.05);
  }

  .job-main-info h3 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
  }

  .job-main-info h3 a {
    color: var(--text-primary);
    text-decoration: none;
    transition: color 0.3s ease;
  }

  .job-main-info h3 a:hover {
    color: var(--primary-color);
  }

  .job-details {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 0.75rem;
  }

  .job-details span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
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

  .salary {
    color: var(--primary-color) !important;
    font-weight: 600;
  }

  .job-tags {
    display: flex;
    gap: 0.5rem;
  }

  .job-tags span {
    padding: 0.25rem 0.5rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 0.25rem;
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.6);
  }

  .job-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .deadline {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .deadline.urgent {
    color: #ffc107;
  }

  .save-btn {
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

  /* Sidebar */
  .sidebar-card {
    margin-bottom: 1.5rem;
  }

  .sidebar-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
  }

  .info-list {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .info-list li {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
  }

  .info-list li:last-child {
    border-bottom: none;
  }

  .info-list li i {
    color: var(--primary-color);
    width: 20px;
    margin-top: 0.25rem;
  }

  .info-list li div {
    flex: 1;
  }

  .info-list li strong {
    display: block;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 0.25rem;
  }

  .info-list li span {
    color: var(--text-primary);
    font-size: 0.9rem;
  }

  .contact-links {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .contact-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 0.5rem;
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .contact-link:hover {
    background: rgba(0, 230, 118, 0.1);
  }

  .contact-link i {
    color: var(--primary-color);
  }

  .social-links {
    display: flex;
    gap: 0.75rem;
  }

  .social-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    text-decoration: none;
    transition: transform 0.3s ease;
  }

  .social-btn:hover {
    transform: scale(1.1);
  }

  .social-btn.linkedin {
    background: #0077b5;
  }

  .social-btn.twitter {
    background: #1da1f2;
  }

  .social-btn.facebook {
    background: #1877f2;
  }

  .similar-companies-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  .similar-company-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 0.5rem;
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .similar-company-item:hover {
    background: rgba(0, 230, 118, 0.1);
  }

  .company-logo-small {
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    font-size: 0.75rem;
    color: var(--primary-color);
    font-weight: 600;
  }

  .company-logo-small img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .company-mini-info strong {
    display: block;
    color: var(--text-primary);
    font-size: 0.875rem;
  }

  .company-mini-info span {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 3rem;
  }

  .empty-state i {
    font-size: 3rem;
    color: rgba(255, 255, 255, 0.2);
    margin-bottom: 1rem;
  }

  .empty-state p {
    color: rgba(255, 255, 255, 0.6);
    margin: 0;
  }

  .empty-state .subtext {
    color: rgba(255, 255, 255, 0.4);
    font-size: 0.875rem;
    margin-top: 0.5rem;
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .company-content-grid {
      grid-template-columns: 1fr;
    }

    .company-sidebar {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.5rem;
    }

    .company-sidebar .sidebar-card {
      margin-bottom: 0;
    }
  }

  @media (max-width: 768px) {
    .company-hero-content {
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .company-meta {
      justify-content: center;
    }

    .company-stats-mini {
      justify-content: center;
    }

    .company-sidebar {
      grid-template-columns: 1fr;
    }

    .job-list-item {
      flex-direction: column;
      align-items: flex-start;
      gap: 1rem;
    }

    .job-actions {
      width: 100%;
      justify-content: flex-end;
    }
  }
</style>

<script>
  function toggleSave(jobId, btn) {
    <?php if (!isset($_SESSION['user_id'])): ?>
      window.location.href = '../auth/login.php';
      return;
    <?php endif; ?>

    const formData = new FormData();
    formData.append('ajax_action', 'toggle');
    formData.append('job_id', jobId);

    fetch('../seeker/saved-jobs.php', {
      method: 'POST',
      body: formData
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          btn.classList.toggle('active', data.saved);
          showNotification(data.message, 'success');
        }
      })
      .catch(error => {
        showNotification('Error updating saved job', 'error');
      });
  }
</script>

<?php include '../includes/footer.php'; ?>