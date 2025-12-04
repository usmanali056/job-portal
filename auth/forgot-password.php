<?php
/**
 * JobNexus - Forgot Password Page
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';

// Redirect if already logged in
if (isLoggedIn()) {
  redirect(BASE_URL);
}

$errors = [];
$success = false;
$email = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Verify CSRF token
  if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Invalid request. Please try again.';
  } else {
    $email = sanitize($_POST['email'] ?? '');

    if (empty($email)) {
      $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid email address.';
    } else {
      // Check if user exists
      $user = new User();
      $existingUser = $user->findByEmail($email);

      if ($existingUser) {
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store reset token in database
        $db = Database::getInstance()->getConnection();

        // First, delete any existing tokens for this user
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$existingUser['id']]);

        // Insert new token
        $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$existingUser['id'], hash('sha256', $token), $expires]);

        // In production, you would send an email here
        // For now, we'll just show a success message
        // The reset link would be: BASE_URL . '/auth/reset-password.php?token=' . $token

        $success = true;
      } else {
        // Don't reveal if email exists or not for security
        $success = true;
      }
    }
  }
}

$pageTitle = 'Forgot Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
  <div class="auth-container auth-single">
    <div class="auth-card">
      <div class="auth-header">
        <a href="<?php echo BASE_URL; ?>" class="logo">JobNexus</a>
        <div class="auth-icon">
          <i class="fas fa-key"></i>
        </div>
        <h1>Forgot Password?</h1>
        <p>No worries, we'll send you reset instructions</p>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i>
          <div>
            <p><strong>Check your email</strong></p>
            <p>If an account exists with <?php echo sanitize($email); ?>, we've sent password reset instructions.</p>
          </div>
        </div>

        <div class="auth-actions">
          <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i>
            Back to Sign In
          </a>
        </div>

        <p class="resend-text">
          Didn't receive the email?
          <a href="<?php echo BASE_URL; ?>/auth/forgot-password.php">Click to resend</a>
        </p>
      <?php else: ?>
        <?php if (!empty($errors)): ?>
          <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div>
              <?php foreach ($errors as $error): ?>
                <p><?php echo sanitize($error); ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" action="" class="auth-form" data-validate>
          <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

          <div class="form-group">
            <label for="email" class="form-label required">Email Address</label>
            <div class="input-group">
              <i class="fas fa-envelope input-icon"></i>
              <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email address"
                value="<?php echo sanitize($email); ?>" required autofocus>
            </div>
            <small class="form-hint">Enter the email address associated with your account</small>
          </div>

          <button type="submit" class="btn btn-primary">
            <span>Send Reset Link</span>
            <i class="fas fa-paper-plane"></i>
          </button>
        </form>

        <div class="auth-footer">
          <a href="<?php echo BASE_URL; ?>/auth/login.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Sign In
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
  /* Auth Page Layout */
  .auth-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-xl) var(--spacing-md);
    background: var(--bg-primary);
  }

  .auth-container.auth-single {
    max-width: 480px;
    width: 100%;
  }

  .auth-card {
    padding: var(--spacing-2xl);
    background: var(--bg-secondary);
    border-radius: var(--radius-xl);
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
  }

  /* Auth Header */
  .auth-header {
    text-align: center;
    margin-bottom: var(--spacing-xl);
  }

  .auth-header .logo {
    font-family: var(--font-heading);
    font-size: 2rem;
    color: var(--accent-primary);
    margin-bottom: var(--spacing-lg);
    display: block;
  }

  .auth-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(0, 230, 118, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto var(--spacing-lg);
    font-size: 2rem;
    color: var(--accent-primary);
  }

  .auth-header h1 {
    font-size: 1.75rem;
    margin-bottom: var(--spacing-xs);
    color: var(--text-primary);
  }

  .auth-header p {
    color: var(--text-muted);
  }

  /* Form Styles */
  .auth-form .form-group {
    margin-bottom: var(--spacing-lg);
  }

  .auth-form label {
    display: block;
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: var(--spacing-xs);
  }

  .auth-form .input-group {
    position: relative;
    display: flex;
    align-items: center;
  }

  .auth-form .input-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
    z-index: 1;
  }

  .auth-form .form-control {
    width: 100%;
    padding: 0.875rem 1rem 0.875rem 2.75rem;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all var(--transition-fast);
  }

  .auth-form .form-control:focus {
    outline: none;
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px rgba(0, 230, 118, 0.15);
  }

  .auth-form .form-control::placeholder {
    color: var(--text-muted);
  }

  .form-hint {
    display: block;
    margin-top: var(--spacing-xs);
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  /* Submit Button */
  .auth-form .btn-primary {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
    font-weight: 600;
    background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
    border: none;
    border-radius: var(--radius-md);
    color: var(--bg-primary);
    cursor: pointer;
    transition: all var(--transition-fast);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
  }

  .auth-form .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 230, 118, 0.4);
  }

  /* Alert Styles */
  .alert {
    padding: var(--spacing-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-lg);
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
  }

  .alert i {
    font-size: 1.25rem;
    margin-top: 2px;
  }

  .alert-error {
    background: rgba(244, 67, 54, 0.1);
    border: 1px solid rgba(244, 67, 54, 0.3);
    color: #f44336;
  }

  .alert-success {
    background: rgba(0, 230, 118, 0.1);
    border: 1px solid rgba(0, 230, 118, 0.3);
    color: var(--accent-primary);
  }

  .alert-success p {
    margin: 0;
    line-height: 1.5;
  }

  .alert-success p:first-child {
    margin-bottom: var(--spacing-xs);
  }

  /* Auth Actions */
  .auth-actions {
    margin-top: var(--spacing-lg);
  }

  .auth-actions .btn-primary {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
    font-weight: 600;
    background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
    border: none;
    border-radius: var(--radius-md);
    color: var(--bg-primary);
    cursor: pointer;
    transition: all var(--transition-fast);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    text-decoration: none;
  }

  .auth-actions .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 230, 118, 0.4);
  }

  /* Resend Text */
  .resend-text {
    text-align: center;
    margin-top: var(--spacing-lg);
    color: var(--text-muted);
    font-size: 0.9rem;
  }

  .resend-text a {
    color: var(--accent-primary);
    font-weight: 500;
  }

  .resend-text a:hover {
    text-decoration: underline;
  }

  /* Auth Footer */
  .auth-footer {
    text-align: center;
    margin-top: var(--spacing-xl);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--border-color);
  }

  .back-link {
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-size: 0.9rem;
    transition: color var(--transition-fast);
  }

  .back-link:hover {
    color: var(--accent-primary);
  }

  /* Responsive */
  @media (max-width: 768px) {
    .auth-page {
      padding: var(--spacing-md);
    }

    .auth-card {
      padding: var(--spacing-xl);
    }
  }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>