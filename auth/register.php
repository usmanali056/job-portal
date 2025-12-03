<?php
/**
 * JobNexus - Registration Page
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuthController.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(BASE_URL);
}

$errors = [];
$formData = [
    'email' => '',
    'role' => $_GET['role'] ?? ROLE_SEEKER,
    'first_name' => '',
    'last_name' => '',
    'company_name' => '',
    'industry' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $formData = [
            'email' => sanitize($_POST['email'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? '',
            'role' => sanitize($_POST['role'] ?? ROLE_SEEKER),
            'first_name' => sanitize($_POST['first_name'] ?? ''),
            'last_name' => sanitize($_POST['last_name'] ?? ''),
            'company_name' => sanitize($_POST['company_name'] ?? ''),
            'industry' => sanitize($_POST['industry'] ?? ''),
            'website' => sanitize($_POST['website'] ?? ''),
            'agree_terms' => isset($_POST['agree_terms'])
        ];
        
        // Terms agreement check
        if (!$formData['agree_terms']) {
            $errors[] = 'You must agree to the Terms of Service';
        }
        
        if (empty($errors)) {
            $auth = new AuthController();
            $result = $auth->register($formData);
            
            if ($result['success']) {
                setFlash('success', 'Account created successfully! Welcome to JobNexus.');
                redirect($result['redirect']);
            } else {
                $errors = $result['errors'];
            }
        }
    }
}

// Industries list
$industries = [
    'Technology', 'Healthcare', 'Finance', 'Education', 'Manufacturing',
    'Retail', 'Hospitality', 'Construction', 'Transportation', 'Media',
    'Real Estate', 'Legal', 'Non-profit', 'Government', 'Other'
];

$pageTitle = 'Create Account';
include __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-container auth-register">
        <div class="auth-card">
            <div class="auth-header">
                <h1>Create Account</h1>
                <p>Join JobNexus to start your journey</p>
            </div>
            
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
            
            <!-- Role Selector -->
            <div class="role-selector">
                <label class="role-option <?php echo $formData['role'] === ROLE_SEEKER ? 'active' : ''; ?>">
                    <input type="radio" name="role" value="seeker" <?php echo $formData['role'] === ROLE_SEEKER ? 'checked' : ''; ?> onchange="toggleRole(this.value)">
                    <div class="role-content">
                        <i class="fas fa-user-tie"></i>
                        <span>Job Seeker</span>
                        <small>Find your dream job</small>
                    </div>
                </label>
                <label class="role-option <?php echo $formData['role'] === ROLE_HR ? 'active' : ''; ?>">
                    <input type="radio" name="role" value="hr" <?php echo $formData['role'] === ROLE_HR ? 'checked' : ''; ?> onchange="toggleRole(this.value)">
                    <div class="role-content">
                        <i class="fas fa-building"></i>
                        <span>Employer</span>
                        <small>Hire top talent</small>
                    </div>
                </label>
            </div>
            
            <form method="POST" action="" class="auth-form" data-validate enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" id="roleInput" name="role" value="<?php echo sanitize($formData['role']); ?>">
                
                <!-- Job Seeker Fields -->
                <div id="seekerFields" class="role-fields <?php echo $formData['role'] === ROLE_SEEKER ? 'active' : ''; ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label required">First Name</label>
                            <input 
                                type="text" 
                                id="first_name" 
                                name="first_name" 
                                class="form-control" 
                                placeholder="John"
                                value="<?php echo sanitize($formData['first_name']); ?>"
                            >
                        </div>
                        <div class="form-group">
                            <label for="last_name" class="form-label required">Last Name</label>
                            <input 
                                type="text" 
                                id="last_name" 
                                name="last_name" 
                                class="form-control" 
                                placeholder="Doe"
                                value="<?php echo sanitize($formData['last_name']); ?>"
                            >
                        </div>
                    </div>
                </div>
                
                <!-- Employer Fields -->
                <div id="hrFields" class="role-fields <?php echo $formData['role'] === ROLE_HR ? 'active' : ''; ?>">
                    <div class="form-group">
                        <label for="company_name" class="form-label required">Company Name</label>
                        <input 
                            type="text" 
                            id="company_name" 
                            name="company_name" 
                            class="form-control" 
                            placeholder="Your Company Inc."
                            value="<?php echo sanitize($formData['company_name']); ?>"
                        >
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="industry" class="form-label">Industry</label>
                            <select id="industry" name="industry" class="form-control">
                                <option value="">Select Industry</option>
                                <?php foreach ($industries as $industry): ?>
                                    <option value="<?php echo $industry; ?>" <?php echo $formData['industry'] === $industry ? 'selected' : ''; ?>>
                                        <?php echo $industry; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="website" class="form-label">Website</label>
                            <input 
                                type="url" 
                                id="website" 
                                name="website" 
                                class="form-control" 
                                placeholder="https://example.com"
                            >
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_logo" class="form-label">Company Logo</label>
                        <div class="file-upload">
                            <input type="file" id="company_logo" name="company_logo" class="file-upload-input" accept="image/*">
                            <label for="company_logo" class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Upload Logo</span>
                                <small>PNG, JPG up to 2MB</small>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Common Fields -->
                <div class="form-group">
                    <label for="email" class="form-label required">Email Address</label>
                    <div class="input-icon">
                        <i class="fas fa-envelope"></i>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="you@example.com"
                            value="<?php echo sanitize($formData['email']); ?>"
                            required
                        >
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="form-label required">Password</label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control" 
                                placeholder="Min. 8 characters"
                                required
                                minlength="8"
                            >
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password" class="form-label required">Confirm Password</label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="form-control" 
                                placeholder="Confirm password"
                                required
                                data-match="#password"
                            >
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="agree_terms" class="form-check-input" required>
                        <span>I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a></span>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    Create Account <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            
            <p class="auth-footer">
                Already have an account? 
                <a href="<?php echo BASE_URL; ?>/auth/login.php">Sign in</a>
            </p>
        </div>
        
        <div class="auth-visual">
            <div class="auth-visual-content">
                <div class="auth-visual-icon">
                    <i class="fas fa-rocket"></i>
                </div>
                <h2>Start Your Journey</h2>
                <p id="visualText">Create your profile and get discovered by top employers worldwide.</p>
                
                <div class="auth-features">
                    <div class="auth-feature" id="feature1">
                        <i class="fas fa-check-circle"></i>
                        <span>Access to 50,000+ job listings</span>
                    </div>
                    <div class="auth-feature" id="feature2">
                        <i class="fas fa-check-circle"></i>
                        <span>Build your professional resume</span>
                    </div>
                    <div class="auth-feature" id="feature3">
                        <i class="fas fa-check-circle"></i>
                        <span>Get personalized job recommendations</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.auth-register .auth-card {
    max-height: 90vh;
    overflow-y: auto;
}

.role-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-xl);
}

.role-option {
    cursor: pointer;
}

.role-option input {
    display: none;
}

.role-content {
    padding: var(--spacing-lg);
    background: var(--bg-tertiary);
    border: 2px solid var(--border-color);
    border-radius: var(--radius-lg);
    text-align: center;
    transition: all var(--transition-fast);
}

.role-option:hover .role-content {
    border-color: var(--border-light);
}

.role-option.active .role-content,
.role-option input:checked + .role-content {
    border-color: var(--accent-primary);
    background: rgba(0, 230, 118, 0.05);
}

.role-content i {
    font-size: 2rem;
    color: var(--accent-primary);
    margin-bottom: var(--spacing-sm);
    display: block;
}

.role-content span {
    display: block;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.role-content small {
    color: var(--text-muted);
    font-size: 0.8rem;
}

.role-fields {
    display: none;
}

.role-fields.active {
    display: block;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-md);
}

.file-upload {
    position: relative;
}

.file-upload-input {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
}

.file-upload-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-xl);
    background: var(--bg-tertiary);
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.file-upload-label:hover {
    border-color: var(--accent-primary);
}

.file-upload-label i {
    font-size: 1.5rem;
    color: var(--accent-primary);
    margin-bottom: var(--spacing-sm);
}

.file-upload-label small {
    color: var(--text-muted);
    font-size: 0.8rem;
}

.auth-features {
    text-align: left;
    margin-top: var(--spacing-xl);
}

.auth-feature {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-sm) 0;
    color: var(--text-secondary);
}

.auth-feature i {
    color: var(--accent-primary);
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function toggleRole(role) {
    document.getElementById('roleInput').value = role;
    
    // Update role options
    document.querySelectorAll('.role-option').forEach(opt => {
        opt.classList.toggle('active', opt.querySelector('input').value === role);
    });
    
    // Toggle form fields
    document.getElementById('seekerFields').classList.toggle('active', role === 'seeker');
    document.getElementById('hrFields').classList.toggle('active', role === 'hr');
    
    // Update requirements
    document.getElementById('first_name').required = role === 'seeker';
    document.getElementById('last_name').required = role === 'seeker';
    document.getElementById('company_name').required = role === 'hr';
    
    // Update visual content
    const visualText = document.getElementById('visualText');
    const features = {
        seeker: [
            'Access to 50,000+ job listings',
            'Build your professional resume',
            'Get personalized job recommendations'
        ],
        hr: [
            'Post unlimited job listings',
            'Access our talent database',
            'Track and manage applications'
        ]
    };
    
    if (role === 'hr') {
        visualText.textContent = 'Find the perfect candidates for your team with our powerful recruitment tools.';
    } else {
        visualText.textContent = 'Create your profile and get discovered by top employers worldwide.';
    }
    
    const featuresList = features[role] || features.seeker;
    featuresList.forEach((text, index) => {
        document.getElementById('feature' + (index + 1)).querySelector('span').textContent = text;
    });
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    const initialRole = document.getElementById('roleInput').value;
    toggleRole(initialRole);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
