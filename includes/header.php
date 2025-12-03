<?php
/**
 * JobNexus - Header Include
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../config/config.php';

// Get current user info if logged in
$currentUser = null;
$currentUserName = 'Guest';

if (isLoggedIn()) {
  require_once __DIR__ . '/../classes/User.php';
  require_once __DIR__ . '/../classes/SeekerProfile.php';
  require_once __DIR__ . '/../classes/Company.php';

  $userModel = new User();
  $currentUser = $userModel->findById(getCurrentUserId());

  if ($currentUser) {
    if ($currentUser['role'] === ROLE_SEEKER) {
      $profileModel = new SeekerProfile();
      $profile = $profileModel->findByUserId($currentUser['id']);
      if ($profile) {
        $currentUserName = $profile['first_name'] . ' ' . $profile['last_name'];
      }
    } elseif ($currentUser['role'] === ROLE_HR) {
      $companyModel = new Company();
      $company = $companyModel->findByHRUserId($currentUser['id']);
      if ($company) {
        $currentUserName = $company['company_name'];
      }
    } else {
      $currentUserName = 'Admin';
    }
  }
}

// Get flash message
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description"
    content="<?php echo SITE_TAGLINE; ?> - Find the best remote and on-site jobs from top companies worldwide.">
  <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' . SITE_NAME : SITE_NAME . ' - ' . SITE_TAGLINE; ?></title>

  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/images/favicon.svg">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Staatliches&family=Inter:wght@300;400;500;600;700;800&display=swap"
    rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Main Stylesheet -->
  <link rel="stylesheet" href="<?php echo CSS_URL; ?>style.css">

  <?php if (isset($additionalCSS)): ?>
    <?php foreach ($additionalCSS as $css): ?>
      <link rel="stylesheet" href="<?php echo $css; ?>">
    <?php endforeach; ?>
  <?php endif; ?>
</head>

<body>
  <!-- Navigation -->
  <nav class="navbar">
    <div class="navbar-container">
      <a href="<?php echo BASE_URL; ?>" class="navbar-brand">
        <span class="navbar-logo">Job<span>Nexus</span></span>
      </a>

      <ul class="navbar-menu">
        <li><a href="<?php echo BASE_URL; ?>"
            class="navbar-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">Home</a>
        </li>
        <li><a href="<?php echo BASE_URL; ?>/jobs/"
            class="navbar-link <?php echo strpos($_SERVER['REQUEST_URI'], '/jobs') !== false ? 'active' : ''; ?>">Find
            Jobs</a></li>
        <li><a href="<?php echo BASE_URL; ?>/companies.php" class="navbar-link">Companies</a></li>
        <?php if (isLoggedIn() && hasRole(ROLE_HR)): ?>
          <li><a href="<?php echo BASE_URL; ?>/hr/post-job.php" class="navbar-link">Post a Job</a></li>
        <?php endif; ?>
      </ul>

      <div class="navbar-actions">
        <?php if (isLoggedIn()): ?>
          <!-- Notifications -->
          <button class="btn btn-icon btn-ghost" title="Notifications">
            <i class="far fa-bell"></i>
          </button>

          <!-- User Dropdown -->
          <div class="dropdown">
            <button class="btn btn-ghost dropdown-toggle">
              <div class="avatar avatar-sm">
                <span class="initials"><?php echo getInitials($currentUserName); ?></span>
              </div>
              <span class="d-none d-md-inline"><?php echo sanitize($currentUserName); ?></span>
              <i class="fas fa-chevron-down"></i>
            </button>
            <div class="dropdown-menu">
              <?php if (hasRole(ROLE_ADMIN)): ?>
                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="dropdown-item">
                  <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
              <?php elseif (hasRole(ROLE_HR)): ?>
                <a href="<?php echo BASE_URL; ?>/hr/dashboard.php" class="dropdown-item">
                  <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>/hr/company-profile.php" class="dropdown-item">
                  <i class="fas fa-building"></i> Company Profile
                </a>
              <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/seeker/dashboard.php" class="dropdown-item">
                  <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="<?php echo BASE_URL; ?>/seeker/profile.php" class="dropdown-item">
                  <i class="fas fa-user"></i> My Profile
                </a>
                <a href="<?php echo BASE_URL; ?>/seeker/applications.php" class="dropdown-item">
                  <i class="fas fa-file-alt"></i> My Applications
                </a>
              <?php endif; ?>
              <div class="dropdown-divider"></div>
              <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="dropdown-item text-error">
                <i class="fas fa-sign-out-alt"></i> Logout
              </a>
            </div>
          </div>
        <?php else: ?>
          <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn btn-ghost">Sign In</a>
          <a href="<?php echo BASE_URL; ?>/auth/register.php" class="btn btn-primary">Get Started</a>
        <?php endif; ?>

        <button class="mobile-toggle">
          <i class="fas fa-bars"></i>
        </button>
      </div>
    </div>
  </nav>

  <!-- Toast Container -->
  <div class="toast-container">
    <?php if ($flash): ?>
      <div class="toast toast-<?php echo $flash['type']; ?> show">
        <i
          class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
        <span><?php echo sanitize($flash['message']); ?></span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Main Content Wrapper -->
  <main>