<?php
/**
 * JobNexus - Companies Directory
 * Browse all verified companies
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/Company.php';

$db = Database::getInstance()->getConnection();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Filters
$filters = [
  'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
  'industry' => isset($_GET['industry']) ? trim($_GET['industry']) : '',
  'size' => isset($_GET['size']) ? trim($_GET['size']) : '',
  'sort' => isset($_GET['sort']) ? trim($_GET['sort']) : 'jobs'
];

// Build query
$where = ["verification_status = 'verified'"];
$params = [];

if ($filters['search']) {
  $where[] = "(company_name LIKE ? OR description LIKE ? OR headquarters LIKE ?)";
  $searchTerm = "%" . $filters['search'] . "%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($filters['industry']) {
  $where[] = "industry = ?";
  $params[] = $filters['industry'];
}

if ($filters['size']) {
  $where[] = "company_size = ?";
  $params[] = $filters['size'];
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM companies WHERE $whereClause");
$countStmt->execute($params);
$totalCompanies = $countStmt->fetchColumn();
$totalPages = ceil($totalCompanies / $perPage);

// Sorting
$orderBy = "active_jobs DESC, c.company_name ASC";
switch ($filters['sort']) {
  case 'name_asc':
    $orderBy = "c.company_name ASC";
    break;
  case 'name_desc':
    $orderBy = "c.company_name DESC";
    break;
  case 'newest':
    $orderBy = "c.created_at DESC";
    break;
  case 'jobs':
  default:
    $orderBy = "active_jobs DESC, c.company_name ASC";
    break;
}

// Get companies with job counts
$sql = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM jobs WHERE company_id = c.id AND status = 'active') as active_jobs
    FROM companies c
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get industries for filter
$industriesStmt = $db->query("SELECT DISTINCT industry FROM companies WHERE verification_status = 'verified' AND industry IS NOT NULL AND industry != '' ORDER BY industry");
$industries = $industriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get company sizes for filter
$sizes = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5000+'];

$pageTitle = "Companies";
include '../includes/header.php';
?>

<div class="jobs-page companies-page">
    <div class="container">
        <!-- Search Header -->
        <div class="jobs-header">
            <div class="jobs-header-content">
                <h1>Discover Top Companies</h1>
                <?php if (!empty($filters['search'])): ?>
                  <p class="search-results-info">
                    <span class="results-count"><?php echo number_format($totalCompanies); ?></span>
                    <?php echo $totalCompanies === 1 ? 'company' : 'companies'; ?> found for
                    "<span class="search-term"><?php echo sanitize($filters['search']); ?></span>"
                  </p>
                <?php else: ?>
                  <p><?php echo number_format($totalCompanies); ?> verified companies hiring now</p>
                <?php endif; ?>
                </div>
                </div>
                <!-- Main Search Form -->
                <form class="search-form" method="GET" action="">
                  <div class="search-box">
                    <div class="search-input-group">
                    <i class="fas fa-building"></i>
                    <input 
                        type="text" 
                        name="search" 
                        class="search-input" 
                        placeholder="Company name, industry, or location..."
                        value="<?php echo sanitize($filters['search']); ?>">
                    </div>
                <button type="submit" class="btn btn-primary search-btn">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
        
        <div class="jobs-layout">
            <!-- Filters Sidebar -->
            <aside class="filters-sidebar">
                <form id="filtersForm" method="GET" action="">
                    <input type="hidden" name="search" value="<?php echo sanitize($filters['search']); ?>">
                  <!-- Industry Filter -->
                  <div class="filter-section">
                    <h3 class="filter-title">
                      <i class="fas fa-industry"></i> Industry
                    </h3>
                    <div class="filter-options">
                      <label class="filter-option">
                        <input type="radio" name="industry" value="" <?php echo !$filters['industry'] ? 'checked' : ''; ?>>
                        <span>All Industries</span>
                      </label>
                      <?php foreach ($industries as $ind): ?>
                                    <label class="filter-option">
                                      <input type="radio" name="industry" value="<?php echo sanitize($ind); ?>" <?php echo $filters['industry'] === $ind ? 'checked' : ''; ?>>
                                    <span><?php echo sanitize($ind); ?></span>
                                  </label>
                                  <?php endforeach; ?>
                        </div>
                        </div>
                    <!-- Company Size Filter -->
                    <div class="filter-section">
                        <h3 class="filter-title">
                            <i class="fas fa-users"></i> Company Size
                        </h3>
                        <div class="filter-options">
                            <label class="filter-option">
                                <input type="radio" name="size" value="" <?php echo !$filters['size'] ? 'checked' : ''; ?>>
                              <span>All Sizes</span>
                            </label>
                            <?php foreach ($sizes as $sz): ?>
                                    <label class="filter-option">
                                      <input type="radio" name="size" value="<?php echo $sz; ?>" <?php echo $filters['size'] === $sz ? 'checked' : ''; ?>>
                                    <span><?php echo $sz; ?> employees</span>
                                  </label>
                                  <?php endforeach; ?>
                        </div>
                        </div>
                    <button type="submit" class="btn btn-primary btn-block">Apply Filters</button>
                    <a href="<?php echo BASE_URL; ?>/companies/" class="btn btn-ghost btn-block mt-2">Clear All</a>
                        </form>
            </aside>
            
            <!-- Companies List -->
            <div class="jobs-content">
                <!-- Sort Bar -->
                <div class="jobs-toolbar">
                    <p class="jobs-count">
                        Showing <?php echo count($companies); ?> of <?php echo number_format($totalCompanies); ?> companies
                </p>
                <div class="jobs-sort">
                  <label>Sort by:</label>
                  <select name="sort" onchange="updateSort(this.value)">
                    <option value="jobs" <?php echo $filters['sort'] === 'jobs' ? 'selected' : ''; ?>>Most Open Jobs</option>
                    <option value="name_asc" <?php echo $filters['sort'] === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                    <option value="name_desc" <?php echo $filters['sort'] === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                    <option value="newest" <?php echo $filters['sort'] === 'newest' ? 'selected' : ''; ?>>Recently Added</option>
                  </select>
                </div>
              </div>
                
                <!-- Companies Grid -->
                <?php if (!empty($companies)): ?>
                  <div class="companies-grid">
                    <?php foreach ($companies as $company): ?>
                          <a href="<?php echo BASE_URL; ?>/companies/profile.php?id=<?php echo $company['id']; ?>"
                        class="company-card <?php echo $company['is_featured'] ? 'featured' : ''; ?>">
                        <?php if ($company['is_featured']): ?>
                          <span class="company-badge featured">Featured</span>
                        <?php endif; ?>
                        <?php if ($company['active_jobs'] > 0): ?>
                          <span class="company-badge hiring">
                            <i class="fas fa-briefcase"></i> <?php echo $company['active_jobs']; ?>
                            <?php echo $company['active_jobs'] === 1 ? 'job' : 'jobs'; ?>
                          </span>
                        <?php endif; ?>
                        <div class="company-card-header">
                                    <div class="company-card-logo">
                                      <?php if ($company['logo']): ?>
                                                        <img src="<?php echo LOGO_URL . sanitize($company['logo']); ?>" alt="<?php echo sanitize($company['company_name']); ?>">
                                                  <?php else: ?>
                                                        <span class="initials"><?php echo getInitials($company['company_name']); ?></span>
                                                  <?php endif; ?>
                                                  </div>
                                    <div class="company-card-info">
                                        <h3 class="company-card-name"><?php echo sanitize($company['company_name']); ?></h3>
                                      <?php if ($company['industry']): ?>
                                        <p class="company-card-industry"><?php echo sanitize($company['industry']); ?></p>
                                      <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($company['description']): ?>
                                  <p class="company-card-description">
                                    <?php echo sanitize(substr($company['description'], 0, 120)); ?>
                                    <?php echo strlen($company['description']) > 120 ? '...' : ''; ?>
                                  </p>
                                <?php endif; ?>
                                    <div class="company-card-meta">
                                  <?php if ($company['headquarters']): ?>
                                  <span class="meta-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                            <?php echo sanitize($company['headquarters']); ?>
                                            </span>
                                            <?php endif; ?>
                                    <?php if ($company['company_size']): ?>
                                        <span class="meta-item">
                                          <i class="fas fa-users"></i>
                                            <?php echo sanitize($company['company_size']); ?>
                                            </span>
                                            <?php endif; ?>
                                </div>
                                
                                <div class="company-card-footer">
                                  <?php if ($company['website']): ?>
                                    <span class="company-website">
                                      <i class="fas fa-globe"></i> Website
                                    </span>
                                  <?php endif; ?>
                                  <span class="view-profile">
                                    View Profile <i class="fas fa-arrow-right"></i>
                                  </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                              <div class="pagination">
                                <?php if ($page > 1): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="pagination-item">
                                        <i class="fas fa-chevron-left"></i>
                                      </a>
                                      <?php endif; ?>
                                      
                                      <?php
                                      $startPage = max(1, $page - 2);
                                      $endPage = min($totalPages, $page + 2);

                                if ($startPage > 1): ?>
                                  <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-item">1</a>
                                  <?php if ($startPage > 2): ?>
                                      <span class="pagination-item disabled">...</span>
                                  <?php endif; ?>
                                <?php endif; ?>
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                  class="pagination-item <?php echo $i === $page ? 'active' : ''; ?>">
                                  <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <span class="pagination-item disabled">...</span>
                                <?php endif; ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"
                                  class="pagination-item"><?php echo $totalPages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="pagination-item">
                                        <i class="fas fa-chevron-right"></i>
                                      </a>
                                      <?php endif; ?>
                                      </div>
                                      <?php endif; ?>
                <?php else: ?>
                <div class="no-results">
                  <div class="no-results-icon">
                    <i class="fas fa-building"></i>
                  </div>
                  <?php if (!empty($filters['search'])): ?>
                    <h3>No companies found for "<?php echo sanitize($filters['search']); ?>"</h3>
                    <p>We couldn't find any companies matching your search. Try these suggestions:</p>
                    <ul class="search-suggestions">
                      <li><i class="fas fa-check text-accent"></i> Check for spelling errors</li>
                      <li><i class="fas fa-check text-accent"></i> Try more general keywords</li>
                      <li><i class="fas fa-check text-accent"></i> Remove some filters</li>
                      <li><i class="fas fa-check text-accent"></i> Browse all companies</li>
                    </ul>
                  <?php else: ?>
                  <h3>No companies found</h3>
                  <p>Try adjusting your filter criteria to find more companies.</p>
                        <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/companies/" class="btn btn-primary" style="margin-top: var(--spacing-lg);">
                            <i class="fas fa-refresh"></i> Clear All Filters
                          </a>
                          </div>
                          <?php endif; ?>
                          </div>
                          </div>
                          </div>
</div>

<style>
/* Page Layout - Matching Jobs Page */
.jobs-page {
    padding: 100px 0 var(--spacing-3xl);
    min-height: 100vh;
}

.jobs-header {
    text-align: center;
    margin-bottom: var(--spacing-xl);
}

.jobs-header h1 {
    font-size: 2.5rem;
    margin-bottom: var(--spacing-sm);
}

.jobs-header p {
    color: var(--text-secondary);
    font-size: 1.1rem;
}

.search-form {
    max-width: 800px;
    margin: 0 auto var(--spacing-xl);
}

.search-form .search-box {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-xl);
    padding: 0.5rem;
    display: flex;
    gap: var(--spacing-sm);
}

.search-input-group {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
}

.search-input-group i {
    position: absolute;
    left: 1rem;
    color: var(--text-muted);
}

.search-input-group .search-input {
    width: 100%;
    padding: 1rem 1rem 1rem 3rem;
    border: none;
    background: transparent;
    color: var(--text-primary);
    font-size: 1rem;
}

.search-input-group .search-input::placeholder {
    color: var(--text-muted);
}

.search-input-group .search-input:focus {
    outline: none;
}

.search-btn {
    white-space: nowrap;
    padding: 1rem 2rem;
}

.jobs-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: var(--spacing-xl);
}

/* Filters Sidebar */
.filters-sidebar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    height: fit-content;
    position: sticky;
    top: 90px;
}

.filter-section {
    margin-bottom: var(--spacing-lg);
    padding-bottom: var(--spacing-lg);
    border-bottom: 1px solid var(--border-color);
}

.filter-section:last-of-type {
    border-bottom: none;
    margin-bottom: var(--spacing-md);
    padding-bottom: 0;
}

.filter-title {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: var(--spacing-md);
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.filter-title i {
    color: var(--accent-primary);
}

.filter-options {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
    max-height: 250px;
    overflow-y: auto;
}

.filter-option {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
    padding: var(--spacing-xs) 0;
    font-size: 0.9rem;
    color: var(--text-secondary);
    transition: color var(--transition-fast);
}

.filter-option:hover {
    color: var(--text-primary);
}

.filter-option input[type="radio"] {
    accent-color: var(--accent-primary);
    width: 16px;
    height: 16px;
}

.filter-option span {
    flex: 1;
}

/* Content Area */
.jobs-content {
    min-height: 500px;
}

.jobs-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
    padding-bottom: var(--spacing-md);
    border-bottom: 1px solid var(--border-color);
}

.jobs-count {
    color: var(--text-secondary);
    font-size: 0.95rem;
}

.jobs-sort {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.jobs-sort label {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.jobs-sort select {
    background: var(--bg-tertiary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 0.5rem 1rem;
    color: var(--text-primary);
    font-size: 0.9rem;
    cursor: pointer;
}

.jobs-sort select:focus {
    outline: none;
    border-color: var(--accent-primary);
}

/* Companies Grid */
.companies-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: var(--spacing-lg);
}

.company-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: all 0.3s ease;
    min-height: 280px;
}

.company-card:hover {
    border-color: var(--accent-primary);
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0, 230, 118, 0.15);
}

.company-card.featured {
    border-color: rgba(0, 230, 118, 0.3);
    background: linear-gradient(135deg, rgba(0, 230, 118, 0.05) 0%, transparent 100%);
}

.company-badge {
    position: absolute;
    top: var(--spacing-md);
    right: var(--spacing-md);
    padding: 0.25rem 0.75rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    z-index: 1;
}

.company-badge.featured {
    background: var(--accent-primary);
    color: var(--bg-primary);
}

.company-badge.hiring {
    background: rgba(0, 230, 118, 0.15);
    color: var(--accent-primary);
    position: static;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-bottom: var(--spacing-sm);
    width: fit-content;
}

.company-card-header {
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.company-card-logo {
    width: 64px;
    height: 64px;
    border-radius: var(--radius-md);
    background: var(--bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    border: 1px solid var(--border-color);
}

.company-card-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.company-card-logo .initials {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    color: var(--accent-primary);
}

.company-card-info {
    flex: 1;
    min-width: 0;
}

.company-card-name {
    font-size: 1.15rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: var(--text-primary);
    line-height: 1.3;
}

.company-card-industry {
    font-size: 0.85rem;
    color: var(--accent-primary);
    font-weight: 500;
}

.company-card-description {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: var(--spacing-md);
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.company-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm) var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.company-card-meta .meta-item {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.company-card-meta .meta-item i {
    color: var(--accent-primary);
    font-size: 0.7rem;
}

.company-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--border-color);
    margin-top: auto;
}

.company-website {
    font-size: 0.8rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.view-profile {
    font-size: 0.85rem;
    color: var(--accent-primary);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: gap 0.2s ease;
}

.company-card:hover .view-profile {
    gap: 0.75rem;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: var(--spacing-xs);
    margin-top: var(--spacing-xl);
}

.pagination-item {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 var(--spacing-sm);
    border-radius: var(--radius-md);
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all var(--transition-fast);
}

.pagination-item:hover:not(.disabled):not(.active) {
    border-color: var(--accent-primary);
    color: var(--accent-primary);
}

.pagination-item.active {
    background: var(--accent-primary);
    border-color: var(--accent-primary);
    color: var(--bg-primary);
    font-weight: 600;
}

.pagination-item.disabled {
    cursor: default;
    opacity: 0.5;
}

/* No Results */
.no-results {
    text-align: center;
    padding: var(--spacing-3xl) var(--spacing-xl);
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px dashed var(--border-color);
}

.no-results-icon {
    font-size: 4rem;
    color: var(--text-muted);
    margin-bottom: var(--spacing-lg);
}

.no-results h3 {
    font-size: 1.5rem;
    margin-bottom: var(--spacing-sm);
    color: var(--text-primary);
}

.no-results p {
    color: var(--text-secondary);
    margin-bottom: var(--spacing-lg);
}

.search-suggestions {
    text-align: left;
    max-width: 350px;
    margin: 0 auto var(--spacing-lg);
    list-style: none;
    padding: 0;
}

.search-suggestions li {
    color: var(--text-secondary);
    padding: var(--spacing-xs) 0;
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.search-suggestions li i {
    color: var(--accent-primary);
}

/* Responsive */
@media (max-width: 1024px) {
    .jobs-layout {
        grid-template-columns: 1fr;
    }
    
    .filters-sidebar {
        position: static;
        order: -1;
    }
}

@media (max-width: 768px) {
    .jobs-header h1 {
        font-size: 1.75rem;
    }
    
    .companies-grid {
        grid-template-columns: 1fr;
    }
    
    .search-form .search-box {
        flex-direction: column;
    }
    
    .search-btn {
        width: 100%;
    }
    
    .jobs-toolbar {
        flex-direction: column;
        gap: var(--spacing-md);
        align-items: stretch;
    }
    
    .jobs-sort {
        justify-content: space-between;
    }
}
</style>

<script>
function updateSort(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', value);
    window.location.href = url.toString();
}

// Auto-submit filter form on radio change
document.querySelectorAll('.filter-option input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('filtersForm').submit();
    });
});
</script>

<?php include '../includes/footer.php'; ?>
