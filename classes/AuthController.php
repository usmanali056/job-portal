<?php
/**
 * JobNexus - Authentication Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/SeekerProfile.php';
require_once __DIR__ . '/../classes/Company.php';

class AuthController
{
  private User $userModel;
  private SeekerProfile $seekerModel;
  private Company $companyModel;

  public function __construct()
  {
    $this->userModel = new User();
    $this->seekerModel = new SeekerProfile();
    $this->companyModel = new Company();
  }

  /**
   * Handle Login
   */
  public function login(string $email, string $password): array
  {
    $errors = [];

    // Validate input
    if (empty($email)) {
      $errors[] = "Email is required";
    }
    if (empty($password)) {
      $errors[] = "Password is required";
    }

    if (!empty($errors)) {
      return ['success' => false, 'errors' => $errors];
    }

    // Find user
    $user = $this->userModel->findByEmail($email);

    if (!$user) {
      return ['success' => false, 'errors' => ['Invalid email or password']];
    }

    // Verify password
    if (!$this->userModel->verifyPassword($password, $user['password_hash'])) {
      return ['success' => false, 'errors' => ['Invalid email or password']];
    }

    // Check if active
    if (!$user['is_active']) {
      return ['success' => false, 'errors' => ['Your account has been deactivated']];
    }

    // Update last login
    $this->userModel->updateLastLogin($user['id']);

    // Set session
    $this->setSession($user);

    // Determine redirect URL
    $redirectUrl = $this->getRedirectUrl($user['role']);

    return [
      'success' => true,
      'user' => $user,
      'redirect' => $redirectUrl
    ];
  }

  /**
   * Handle Registration
   */
  public function register(array $data): array
  {
    $errors = $this->validateRegistration($data);

    if (!empty($errors)) {
      return ['success' => false, 'errors' => $errors];
    }

    // Check if email exists
    if ($this->userModel->findByEmail($data['email'])) {
      return ['success' => false, 'errors' => ['Email already registered']];
    }

    try {
      // Create user
      $userId = $this->userModel->create([
        'email' => $data['email'],
        'password' => $data['password'],
        'role' => $data['role']
      ]);

      if (!$userId) {
        throw new Exception("Failed to create user");
      }

      // Create role-specific profile
      if ($data['role'] === ROLE_SEEKER) {
        $this->seekerModel->create([
          'user_id' => $userId,
          'first_name' => $data['first_name'],
          'last_name' => $data['last_name']
        ]);
      } elseif ($data['role'] === ROLE_HR) {
        // Handle logo upload
        $logoPath = null;
        if (!empty($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
          $logoErrors = validateFileUpload($_FILES['company_logo'], ALLOWED_IMAGE_TYPES);
          if (empty($logoErrors)) {
            $logoPath = uploadFile($_FILES['company_logo'], LOGO_PATH, 'company_');
          }
        }

        $this->companyModel->create([
          'hr_user_id' => $userId,
          'company_name' => $data['company_name'],
          'logo' => $logoPath,
          'industry' => $data['industry'] ?? null,
          'website' => $data['website'] ?? null
        ]);
      }

      // Get created user
      $user = $this->userModel->findById($userId);

      // Set session
      $this->setSession($user);

      // Determine redirect
      $redirectUrl = $this->getRedirectUrl($user['role']);

      return [
        'success' => true,
        'user' => $user,
        'redirect' => $redirectUrl
      ];

    } catch (Exception $e) {
      error_log("Registration Error: " . $e->getMessage());
      return ['success' => false, 'errors' => ['Registration failed. Please try again.']];
    }
  }

  /**
   * Validate Registration Data
   */
  private function validateRegistration(array $data): array
  {
    $errors = [];

    // Email validation
    if (empty($data['email'])) {
      $errors[] = "Email is required";
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = "Invalid email format";
    }

    // Password validation
    if (empty($data['password'])) {
      $errors[] = "Password is required";
    } elseif (strlen($data['password']) < 8) {
      $errors[] = "Password must be at least 8 characters";
    }

    // Confirm password
    if ($data['password'] !== ($data['confirm_password'] ?? '')) {
      $errors[] = "Passwords do not match";
    }

    // Role validation
    if (empty($data['role']) || !in_array($data['role'], [ROLE_SEEKER, ROLE_HR])) {
      $errors[] = "Invalid role selected";
    }

    // Role-specific validation
    if ($data['role'] === ROLE_SEEKER) {
      if (empty($data['first_name'])) {
        $errors[] = "First name is required";
      }
      if (empty($data['last_name'])) {
        $errors[] = "Last name is required";
      }
    } elseif ($data['role'] === ROLE_HR) {
      if (empty($data['company_name'])) {
        $errors[] = "Company name is required";
      }
    }

    return $errors;
  }

  /**
   * Set user session
   */
  private function setSession(array $user): void
  {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['is_verified'] = $user['is_verified'];
    $_SESSION['logged_in'] = true;

    // Regenerate session ID for security
    session_regenerate_id(true);
  }

  /**
   * Get redirect URL based on role
   */
  private function getRedirectUrl(string $role): string
  {
    // Check for stored redirect
    if (!empty($_SESSION['redirect_after_login'])) {
      $redirect = $_SESSION['redirect_after_login'];
      unset($_SESSION['redirect_after_login']);
      return $redirect;
    }

    switch ($role) {
      case ROLE_ADMIN:
        return BASE_URL . '/admin/dashboard.php';
      case ROLE_HR:
        return BASE_URL . '/hr/dashboard.php';
      case ROLE_SEEKER:
        return BASE_URL . '/seeker/dashboard.php';
      default:
        return BASE_URL;
    }
  }

  /**
   * Logout
   */
  public function logout(): void
  {
    session_unset();
    session_destroy();

    // Start new session for flash message
    session_start();
    setFlash('success', 'You have been logged out successfully');

    redirect(BASE_URL . '/auth/login.php');
  }

  /**
   * Get current user details
   */
  public function getCurrentUser(): ?array
  {
    if (!isLoggedIn()) {
      return null;
    }

    $user = $this->userModel->findById(getCurrentUserId());

    if (!$user) {
      $this->logout();
      return null;
    }

    // Get role-specific profile
    if ($user['role'] === ROLE_SEEKER) {
      $user['profile'] = $this->seekerModel->findByUserId($user['id']);
    } elseif ($user['role'] === ROLE_HR) {
      $user['company'] = $this->companyModel->findByHRUserId($user['id']);
    }

    return $user;
  }
}
