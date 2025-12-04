<?php
/**
 * JobNexus - Create Company Profile
 * HR/Recruiter company registration
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Company.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_HR) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=hr/create-company');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User();
$companyModel = new Company();

$hr = $userModel->findById($_SESSION['user_id']);

// Check if HR already has a company
$stmt = $db->prepare("SELECT * FROM companies WHERE hr_user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$existingCompany = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingCompany) {
  header('Location: ' . BASE_URL . '/hr/company.php');
  exit;
}

$message = '';
$messageType = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate inputs
  $companyName = trim($_POST['company_name'] ?? '');
  $industry = trim($_POST['industry'] ?? '');
  $companySize = $_POST['company_size'] ?? '';
  $foundedYear = trim($_POST['founded_year'] ?? '');
  $headquarters = trim($_POST['headquarters'] ?? '');
  $website = trim($_POST['website'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $description = trim($_POST['description'] ?? '');

  // Validation
  if (empty($companyName)) {
    $errors[] = 'Company name is required.';
  }
  if (empty($industry)) {
    $errors[] = 'Industry is required.';
  }
  if (empty($headquarters)) {
    $errors[] = 'Headquarters location is required.';
  }

  if (empty($errors)) {
    $slug = generateSlug($companyName) . '-' . uniqid();

    $stmt = $db->prepare("
      INSERT INTO companies (
        hr_user_id, company_name, slug, industry, company_size, founded_year,
        headquarters, website, email, phone, description, verification_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");

    $foundedYearValue = !empty($foundedYear) ? (int) $foundedYear : null;

    if (
      $stmt->execute([
        $_SESSION['user_id'],
        $companyName,
        $slug,
        $industry,
        $companySize ?: null,
        $foundedYearValue,
        $headquarters,
        $website ?: null,
        $email ?: null,
        $phone ?: null,
        $description ?: null
      ])
    ) {
      header('Location: ' . BASE_URL . '/hr/company.php?created=1');
      exit;
    } else {
      $message = 'Failed to create company. Please try again.';
      $messageType = 'error';
    }
  } else {
    $message = implode('<br>', $errors);
    $messageType = 'error';
  }
}

$pageTitle = 'Create Company Profile';
require_once '../includes/header.php';
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="hr-avatar">
        <i class="fas fa-building"></i>
      </div>
      <h3><?php echo htmlspecialchars($hr['email']); ?></h3>
      <span class="role-badge hr">HR Manager</span>
    </div>

    <nav class="sidebar-nav">
      <a href="<?php echo BASE_URL; ?>/hr/index.php" class="nav-item">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/create-company.php" class="nav-item active">
        <i class="fas fa-building"></i>
        <span>Create Company</span>
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
      <div>
        <h1>Create Company Profile</h1>
        <p>Set up your company to start posting jobs</p>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <div class="info-card">
      <div class="info-icon">
        <i class="fas fa-info-circle"></i>
      </div>
      <div class="info-content">
        <h4>Company Verification Required</h4>
        <p>After creating your company profile, it will be reviewed by our admin team. Once verified, you'll be able to
          post jobs and manage applications.</p>
      </div>
    </div>

    <div class="form-card">
      <form method="POST" class="company-form">
        <div class="form-section">
          <h3><i class="fas fa-building"></i> Basic Information</h3>

          <div class="form-group">
            <label for="company_name">Company Name <span class="required">*</span></label>
            <input type="text" id="company_name" name="company_name" class="form-control"
              value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>" required>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="industry">Industry <span class="required">*</span></label>
              <select id="industry" name="industry" class="form-control" required>
                <option value="">Select Industry</option>
                <option value="Technology" <?php echo ($_POST['industry'] ?? '') === 'Technology' ? 'selected' : ''; ?>>
                  Technology</option>
                <option value="Healthcare" <?php echo ($_POST['industry'] ?? '') === 'Healthcare' ? 'selected' : ''; ?>>
                  Healthcare</option>
                <option value="Finance" <?php echo ($_POST['industry'] ?? '') === 'Finance' ? 'selected' : ''; ?>>Finance
                </option>
                <option value="Education" <?php echo ($_POST['industry'] ?? '') === 'Education' ? 'selected' : ''; ?>>
                  Education</option>
                <option value="Manufacturing" <?php echo ($_POST['industry'] ?? '') === 'Manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                <option value="Retail" <?php echo ($_POST['industry'] ?? '') === 'Retail' ? 'selected' : ''; ?>>Retail
                </option>
                <option value="Marketing" <?php echo ($_POST['industry'] ?? '') === 'Marketing' ? 'selected' : ''; ?>>
                  Marketing</option>
                <option value="Consulting" <?php echo ($_POST['industry'] ?? '') === 'Consulting' ? 'selected' : ''; ?>>
                  Consulting</option>
                <option value="Real Estate" <?php echo ($_POST['industry'] ?? '') === 'Real Estate' ? 'selected' : ''; ?>>
                  Real Estate</option>
                <option value="Hospitality" <?php echo ($_POST['industry'] ?? '') === 'Hospitality' ? 'selected' : ''; ?>>
                  Hospitality</option>
                <option value="Media" <?php echo ($_POST['industry'] ?? '') === 'Media' ? 'selected' : ''; ?>>Media
                </option>
                <option value="Other" <?php echo ($_POST['industry'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other
                </option>
              </select>
            </div>

            <div class="form-group">
              <label for="company_size">Company Size</label>
              <select id="company_size" name="company_size" class="form-control">
                <option value="">Select Size</option>
                <option value="1-10" <?php echo ($_POST['company_size'] ?? '') === '1-10' ? 'selected' : ''; ?>>1-10
                  employees</option>
                <option value="11-50" <?php echo ($_POST['company_size'] ?? '') === '11-50' ? 'selected' : ''; ?>>11-50
                  employees</option>
                <option value="51-200" <?php echo ($_POST['company_size'] ?? '') === '51-200' ? 'selected' : ''; ?>>51-200
                  employees</option>
                <option value="201-500" <?php echo ($_POST['company_size'] ?? '') === '201-500' ? 'selected' : ''; ?>>
                  201-500 employees</option>
                <option value="501-1000" <?php echo ($_POST['company_size'] ?? '') === '501-1000' ? 'selected' : ''; ?>>
                  501-1000 employees</option>
                <option value="1000+" <?php echo ($_POST['company_size'] ?? '') === '1000+' ? 'selected' : ''; ?>>1000+
                  employees</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="founded_year">Founded Year</label>
              <input type="number" id="founded_year" name="founded_year" class="form-control" min="1800"
                max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($_POST['founded_year'] ?? ''); ?>">
            </div>

            <div class="form-group">
              <label for="headquarters">Headquarters <span class="required">*</span></label>
              <input type="text" id="headquarters" name="headquarters" class="form-control" placeholder="City, Country"
                value="<?php echo htmlspecialchars($_POST['headquarters'] ?? ''); ?>" required>
            </div>
          </div>
        </div>

        <div class="form-section">
          <h3><i class="fas fa-address-card"></i> Contact Information</h3>

          <div class="form-row">
            <div class="form-group">
              <label for="website">Website</label>
              <input type="url" id="website" name="website" class="form-control" placeholder="https://www.example.com"
                value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>">
            </div>

            <div class="form-group">
              <label for="email">Company Email</label>
              <input type="email" id="email" name="email" class="form-control" placeholder="contact@company.com"
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
          </div>

          <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" class="form-control" placeholder="+1 (555) 123-4567"
              value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
          </div>
        </div>

        <div class="form-section">
          <h3><i class="fas fa-file-alt"></i> Company Description</h3>

          <div class="form-group">
            <label for="description">About Your Company</label>
            <textarea id="description" name="description" class="form-control" rows="6"
              placeholder="Tell potential candidates about your company, culture, and what makes it a great place to work..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-check"></i> Create Company Profile
          </button>
        </div>
      </form>
    </div>
  </main>
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

  .hr-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #2196F3, #1976D2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 2rem;
    color: white;
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

  .role-badge.hr {
    background: rgba(33, 150, 243, 0.2);
    color: #2196F3;
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

  .alert-error {
    background: rgba(244, 67, 54, 0.1);
    border: 1px solid rgba(244, 67, 54, 0.3);
    color: #F44336;
  }

  .info-card {
    display: flex;
    gap: 1rem;
    padding: 1.25rem;
    background: rgba(33, 150, 243, 0.1);
    border: 1px solid rgba(33, 150, 243, 0.3);
    border-radius: 12px;
    margin-bottom: 2rem;
  }

  .info-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: rgba(33, 150, 243, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2196F3;
  }

  .info-content h4 {
    color: #2196F3;
    margin-bottom: 0.25rem;
  }

  .info-content p {
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  .form-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 2rem;
    border: 1px solid var(--border-color);
  }

  .form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid var(--border-color);
  }

  .form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 1rem;
    padding-bottom: 0;
  }

  .form-section h3 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
    color: var(--text-light);
  }

  .form-section h3 i {
    color: var(--primary-color);
  }

  .form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
  }

  .form-group {
    margin-bottom: 1.25rem;
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

  textarea.form-control {
    resize: vertical;
    min-height: 120px;
  }

  select.form-control {
    cursor: pointer;
  }

  .form-actions {
    display: flex;
    justify-content: flex-end;
    padding-top: 1rem;
  }

  .btn {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
    border: none;
    font-size: 0.95rem;
  }

  .btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: var(--bg-dark);
  }

  .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0, 230, 118, 0.3);
  }

  .btn-lg {
    padding: 1rem 2rem;
    font-size: 1rem;
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

    .hr-avatar {
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

    .form-row {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php require_once '../includes/footer.php'; ?>