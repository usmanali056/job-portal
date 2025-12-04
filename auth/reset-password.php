<?php
/**
 * JobNexus - Reset Password Page
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
$validToken = false;
$token = $_GET['token'] ?? '';

// Validate token
if (!empty($token)) {
  $db = Database::getInstance()->getConnection();
  $hashedToken = hash('sha256', $token);

  $stmt = $db->prepare("
        SELECT pr.*, u.email 
        FROM password_resets pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0
    ");
  $stmt->execute([$hashedToken]);
  $resetRequest = $stmt->fetch();

  if ($resetRequest) {
    $validToken = true;
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
  // Verify CSRF token
  if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Invalid request. Please try again.';
  } else {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate password
    if (empty($password)) {
      $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
      $errors[] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
      $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
      // Update password
      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

      $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
      $stmt->execute([$hashedPassword, $resetRequest['user_id']]);

      // Mark token as used
      $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
      $stmt->execute([$hashedToken]);

      $success = true;
    }
  }
}

$pageTitle = 'Reset Password';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
  <div class="auth-container auth-single">
    <div class="auth-card">
      <div class="auth-header">
        <a href="<?php echo BASE_URL; ?>" class="logo">JobNexus</a>
        <?php if ($success): ?>
          <div class="auth-icon success">
            <i class="fas fa-check"></i>
          </div>
          <h1>Password Reset!</h1>
          <p>Your password has been successfully updated</p>
        <?php elseif ($validToken): ?>
          <div class="auth-icon">
            <i class="fas fa-lock"></i>
          </div>
          <h1>Set New Password</h1>
          <p>Create a strong password for your account</p>
        <?php else: ?>
          <div class="auth-icon error">
            <i class="fas fa-times"></i>
          </div>
          <h1>Invalid Link</h1>
          <p>This password reset link is invalid or has expired</p>
        <?php endif; ?>
      </div>

      <?php if ($success): ?>
        <div class="success-message">
          <p>You can now sign in with your new password.</p>
        </div>

        <div class="auth-actions">
          <a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn btn-primary">
            <span>Sign In</span>
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>
      <?php elseif ($validToken): ?>
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
            <label for="password" class="form-label required">New Password</label>
            <div class="input-group">
              <i class="fas fa-lock input-icon"></i>
              <input type="password" id="password" name="password" class="form-control" placeholder="Min. 8 characters"
                required minlength="8">
              <button type="button" class="btn-toggle-password" onclick="togglePassword('password')">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <div class="password-strength">
              <div class="strength-bar">
                <div class="strength-fill" id="strengthFill"></div>
              </div>
              <span class="strength-text" id="strengthText">Password strength</span>
            </div>
          </div>

          <div class="form-group">
            <label for="confirm_password" class="form-label required">Confirm Password</label>
            <div class="input-group">
              <i class="fas fa-lock input-icon"></i>
              <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                placeholder="Confirm your password" required>
              <button type="button" class="btn-toggle-password" onclick="togglePassword('confirm_password')">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>

          <div class="password-requirements">
            <p class="requirements-title">Password must contain:</p>
            <ul>
              <li id="req-length"><i class="fas fa-circle"></i> At least 8 characters</li>
              <li id="req-upper"><i class="fas fa-circle"></i> One uppercase letter</li>
              <li id="req-lower"><i class="fas fa-circle"></i> One lowercase letter</li>
              <li id="req-number"><i class="fas fa-circle"></i> One number</li>
            </ul>
          </div>

          <button type="submit" class="btn btn-primary">
            <span>Reset Password</span>
            <i class="fas fa-check"></i>
          </button>
        </form>
      <?php else: ?>
        <div class="error-message">
          <p>The password reset link you followed is either invalid or has expired. Please request a new one.</p>
        </div>

        <div class="auth-actions">
          <a href="<?php echo BASE_URL; ?>/auth/forgot-password.php" class="btn btn-primary">
            <span>Request New Link</span>
            <i class="fas fa-redo"></i>
          </a>
        </div>

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

  .auth-icon.success {
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
  }

  .auth-icon.error {
    background: rgba(244, 67, 54, 0.15);
    color: #f44336;
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
    padding: 0.875rem 3rem 0.875rem 2.75rem;
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

  /* Password Toggle */
  .btn-toggle-password {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    transition: color var(--transition-fast);
  }

  .btn-toggle-password:hover {
    color: var(--accent-primary);
  }

  /* Password Strength */
  .password-strength {
    margin-top: var(--spacing-sm);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
  }

  .strength-bar {
    flex: 1;
    height: 4px;
    background: var(--bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
  }

  .strength-fill {
    height: 100%;
    width: 0;
    transition: all var(--transition-fast);
    border-radius: 2px;
  }

  .strength-fill.weak {
    width: 25%;
    background: #f44336;
  }

  .strength-fill.fair {
    width: 50%;
    background: #ff9800;
  }

  .strength-fill.good {
    width: 75%;
    background: #2196f3;
  }

  .strength-fill.strong {
    width: 100%;
    background: var(--accent-primary);
  }

  .strength-text {
    font-size: 0.75rem;
    color: var(--text-muted);
    min-width: 80px;
  }

  /* Password Requirements */
  .password-requirements {
    background: var(--bg-tertiary);
    border-radius: var(--radius-md);
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
  }

  .requirements-title {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: var(--spacing-sm);
  }

  .password-requirements ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-xs);
  }

  .password-requirements li {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
  }

  .password-requirements li i {
    font-size: 0.5rem;
  }

  .password-requirements li.valid {
    color: var(--accent-primary);
  }

  .password-requirements li.valid i {
    font-size: 0.75rem;
  }

  .password-requirements li.valid i::before {
    content: "\f00c";
  }

  /* Submit Button */
  .auth-form .btn-primary,
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

  .auth-form .btn-primary:hover,
  .auth-actions .btn-primary:hover {
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

  /* Success/Error Messages */
  .success-message,
  .error-message {
    text-align: center;
    padding: var(--spacing-lg);
    background: var(--bg-tertiary);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-lg);
  }

  .success-message p,
  .error-message p {
    color: var(--text-secondary);
    line-height: 1.6;
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

    .password-requirements ul {
      grid-template-columns: 1fr;
    }
  }
</style>

<script>
  function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = input.parentElement.querySelector('.btn-toggle-password i');

    if (input.type === 'password') {
      input.type = 'text';
      button.classList.remove('fa-eye');
      button.classList.add('fa-eye-slash');
    } else {
      input.type = 'password';
      button.classList.remove('fa-eye-slash');
      button.classList.add('fa-eye');
    }
  }

  // Password strength checker
  document.getElementById('password')?.addEventListener('input', function () {
    const password = this.value;
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');

    // Check requirements
    const hasLength = password.length >= 8;
    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);

    // Update requirement indicators
    document.getElementById('req-length').classList.toggle('valid', hasLength);
    document.getElementById('req-upper').classList.toggle('valid', hasUpper);
    document.getElementById('req-lower').classList.toggle('valid', hasLower);
    document.getElementById('req-number').classList.toggle('valid', hasNumber);

    // Calculate strength
    let strength = 0;
    if (hasLength) strength++;
    if (hasUpper) strength++;
    if (hasLower) strength++;
    if (hasNumber) strength++;
    if (password.length >= 12) strength++;
    if (/[!@#$%^&*]/.test(password)) strength++;

    // Update UI
    fill.className = 'strength-fill';
    if (password.length === 0) {
      fill.style.width = '0';
      text.textContent = 'Password strength';
    } else if (strength <= 2) {
      fill.classList.add('weak');
      text.textContent = 'Weak';
    } else if (strength <= 3) {
      fill.classList.add('fair');
      text.textContent = 'Fair';
    } else if (strength <= 4) {
      fill.classList.add('good');
      text.textContent = 'Good';
    } else {
      fill.classList.add('strong');
      text.textContent = 'Strong';
    }
  });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>