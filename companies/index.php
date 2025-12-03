<?php
/**
 * JobNexus - Companies Directory
 * Browse all verified companies
 */

session_start();
require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/Company.php';

$db = Database::getInstance()->getConnection();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$industry = isset($_GET['industry']) ? trim($_GET['industry']) : '';
$size = isset($_GET['size']) ? trim($_GET['size']) : '';

// Build query
$where = ["is_verified = 1"];
$params = [];

if ($search) {
  $where[] = "(name LIKE ? OR description LIKE ? OR location LIKE ?)";
  $searchTerm = "%$search%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($industry) {
  $where[] = "industry = ?";
  $params[] = $industry;
}

if ($size) {
  $where[] = "company_size = ?";
  $params[] = $size;
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM companies WHERE $whereClause");
$countStmt->execute($params);
$totalCompanies = $countStmt->fetchColumn();
$totalPages = ceil($totalCompanies / $perPage);

// Get companies with job counts
$sql = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM jobs WHERE company_id = c.id AND status = 'active') as active_jobs
    FROM companies c
    WHERE $whereClause
    ORDER BY active_jobs DESC, c.name ASC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get industries for filter
$industriesStmt = $db->query("SELECT DISTINCT industry FROM companies WHERE is_verified = 1 AND industry IS NOT NULL ORDER BY industry");
$industries = $industriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get company sizes for filter
$sizesStmt = $db->query("SELECT DISTINCT company_size FROM companies WHERE is_verified = 1 AND company_size IS NOT NULL ORDER BY company_size");
$sizes = $sizesStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Companies - JobNexus";
include '../includes/header.php';
?>

<div class="companies-page">
  <!-- Hero Section -->
  <section class="page-hero">
    <div class="container">
      <h1>Discover Top Companies</h1>
      <p>Explore verified employers and find your next opportunity</p>

      <!-- Search Form -->
      <form class="search-form" method="GET">
        <div class="search-input-group">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search companies..."
            value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
      </form>
    </div>
  </section>

  <div class="container">
    <div class="page-content">
      <!-- Filters Sidebar -->
      <aside class="filters-sidebar">
        <div class="glass-card">
          <h3>Filters</h3>

          <form method="GET" id="filterForm">
            <?php if ($search): ?>
              <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <?php endif; ?>

            <div class="filter-group">
              <label>Industry</label>
              <select name="industry" onchange="document.getElementById('filterForm').submit()">
                <option value="">All Industries</option>
                <?php foreach ($industries as $ind): ?>
                  <option value="<?php echo htmlspecialchars($ind); ?>" <?php echo $industry === $ind ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ind); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <label>Company Size</label>
              <select name="size" onchange="document.getElementById('filterForm').submit()">
                <option value="">All Sizes</option>
                <?php foreach ($sizes as $sz): ?>
                  <option value="<?php echo htmlspecialchars($sz); ?>" <?php echo $size === $sz ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sz); ?> employees
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if ($industry || $size): ?>
              <a href="index.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
                class="btn btn-outline btn-block">
                Clear Filters
              </a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Stats -->
        <div class="glass-card stats-card">
          <div class="stat">
            <span class="stat-number"><?php echo $totalCompanies; ?></span>
            <span class="stat-label">Companies</span>
          </div>
        </div>
      </aside>

      <!-- Companies Grid -->
      <main class="companies-main">
        <?php if ($search || $industry || $size): ?>
          <div class="search-results-info">
            <span>Showing <?php echo count($companies); ?> of <?php echo $totalCompanies; ?> companies</span>
            <?php if ($search): ?>
              <span class="search-term">for "<?php echo htmlspecialchars($search); ?>"</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (empty($companies)): ?>
          <div class="glass-card empty-state">
            <i class="fas fa-building"></i>
            <h2>No Companies Found</h2>
            <p>Try adjusting your search or filters</p>
            <a href="index.php" class="btn btn-primary">View All Companies</a>
          </div>
        <?php else: ?>
          <div class="companies-grid">
            <?php foreach ($companies as $company): ?>
              <div class="company-card">
                <div class="company-card-header">
                  <div class="company-logo">
                    <?php if ($company['logo']): ?>
                      <img src="../uploads/logos/<?php echo htmlspecialchars($company['logo']); ?>"
                        alt="<?php echo htmlspecialchars($company['name']); ?>">
                    <?php else: ?>
                      <span><?php echo strtoupper(substr($company['name'], 0, 2)); ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($company['active_jobs'] > 0): ?>
                    <span class="hiring-badge">
                      <i class="fas fa-briefcase"></i>
                      <?php echo $company['active_jobs']; ?> open
                    </span>
                  <?php endif; ?>
                </div>

                <h3 class="company-name">
                  <a href="profile.php?id=<?php echo $company['id']; ?>">
                    <?php echo htmlspecialchars($company['name']); ?>
                  </a>
                </h3>

                <?php if ($company['industry']): ?>
                  <span class="company-industry">
                    <i class="fas fa-industry"></i>
                    <?php echo htmlspecialchars($company['industry']); ?>
                  </span>
                <?php endif; ?>

                <?php if ($company['location']): ?>
                  <span class="company-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($company['location']); ?>
                  </span>
                <?php endif; ?>

                <?php if ($company['description']): ?>
                  <p class="company-excerpt">
                    <?php echo substr(htmlspecialchars($company['description']), 0, 100); ?>...
                  </p>
                <?php endif; ?>

                <div class="company-card-footer">
                  <?php if ($company['company_size']): ?>
                    <span class="company-size">
                      <i class="fas fa-users"></i>
                      <?php echo htmlspecialchars($company['company_size']); ?>
                    </span>
                  <?php endif; ?>

                  <a href="profile.php?id=<?php echo $company['id']; ?>" class="btn btn-outline btn-sm">
                    View Profile
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <div class="pagination">
              <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&industry=<?php echo urlencode($industry); ?>&size=<?php echo urlencode($size); ?>"
                  class="btn btn-icon">
                  <i class="fas fa-chevron-left"></i>
                </a>
              <?php endif; ?>

              <?php
              $startPage = max(1, $page - 2);
              $endPage = min($totalPages, $page + 2);

              if ($startPage > 1) {
                echo '<a href="?page=1&search=' . urlencode($search) . '&industry=' . urlencode($industry) . '&size=' . urlencode($size) . '" class="btn btn-page">1</a>';
                if ($startPage > 2) {
                  echo '<span class="pagination-ellipsis">...</span>';
                }
              }

              for ($i = $startPage; $i <= $endPage; $i++) {
                $activeClass = $i === $page ? 'active' : '';
                echo '<a href="?page=' . $i . '&search=' . urlencode($search) . '&industry=' . urlencode($industry) . '&size=' . urlencode($size) . '" class="btn btn-page ' . $activeClass . '">' . $i . '</a>';
              }

              if ($endPage < $totalPages) {
                if ($endPage < $totalPages - 1) {
                  echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $totalPages . '&search=' . urlencode($search) . '&industry=' . urlencode($industry) . '&size=' . urlencode($size) . '" class="btn btn-page">' . $totalPages . '</a>';
              }
              ?>

              <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&industry=<?php echo urlencode($industry); ?>&size=<?php echo urlencode($size); ?>"
                  class="btn btn-icon">
                  <i class="fas fa-chevron-right"></i>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </main>
    </div>
  </div>
</div>

<style>
  .companies-page {
    min-height: 100vh;
    padding-bottom: 4rem;
  }

  .page-hero {
    background: linear-gradient(135deg, rgba(0, 230, 118, 0.15) 0%, rgba(0, 230, 118, 0.02) 100%);
    padding: 4rem 0;
    text-align: center;
    margin-bottom: 3rem;
  }

  .page-hero h1 {
    font-size: 2.5rem;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
  }

  .page-hero p {
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.1rem;
    margin-bottom: 2rem;
  }

  .search-form {
    display: flex;
    max-width: 600px;
    margin: 0 auto;
    gap: 0.75rem;
  }

  .search-input-group {
    flex: 1;
    position: relative;
  }

  .search-input-group i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.5);
  }

  .search-input-group input {
    width: 100%;
    padding: 1rem 1rem 1rem 3rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    font-size: 1rem;
  }

  .search-input-group input:focus {
    border-color: var(--primary-color);
    outline: none;
  }

  .page-content {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
  }

  .filters-sidebar .glass-card {
    margin-bottom: 1.5rem;
  }

  .filters-sidebar h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  }

  .filter-group {
    margin-bottom: 1.25rem;
  }

  .filter-group label {
    display: block;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .filter-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    cursor: pointer;
  }

  .filter-group select:focus {
    border-color: var(--primary-color);
    outline: none;
  }

  .stats-card {
    text-align: center;
    padding: 1.5rem !important;
  }

  .stats-card .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-color);
    display: block;
  }

  .stats-card .stat-label {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .search-results-info {
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.6);
  }

  .search-term {
    color: var(--primary-color);
  }

  .companies-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
  }

  .company-card {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 1rem;
    padding: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.05);
    transition: all 0.3s ease;
  }

  .company-card:hover {
    border-color: rgba(0, 230, 118, 0.3);
    transform: translateY(-4px);
    box-shadow: 0 10px 40px rgba(0, 230, 118, 0.1);
  }

  .company-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
  }

  .company-logo {
    width: 60px;
    height: 60px;
    border-radius: 0.75rem;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .company-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .company-logo span {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-color);
  }

  .hiring-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    background: rgba(0, 230, 118, 0.2);
    color: var(--primary-color);
    border-radius: 2rem;
    font-size: 0.7rem;
    font-weight: 600;
  }

  .company-name {
    margin: 0 0 0.75rem;
    font-size: 1.1rem;
  }

  .company-name a {
    color: var(--text-primary);
    text-decoration: none;
    transition: color 0.3s ease;
  }

  .company-name a:hover {
    color: var(--primary-color);
  }

  .company-industry,
  .company-location {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 0.5rem;
  }

  .company-industry i,
  .company-location i {
    color: var(--primary-color);
    width: 16px;
  }

  .company-excerpt {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
    margin: 1rem 0;
    line-height: 1.5;
  }

  .company-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
  }

  .company-size {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.5);
  }

  .company-size i {
    color: var(--primary-color);
  }

  /* Empty State */
  .empty-state {
    text-align: center;
    padding: 4rem 2rem;
  }

  .empty-state i {
    font-size: 4rem;
    color: rgba(255, 255, 255, 0.2);
    margin-bottom: 1.5rem;
  }

  .empty-state h2 {
    margin: 0 0 0.5rem;
    color: var(--text-primary);
  }

  .empty-state p {
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 1.5rem;
  }

  /* Pagination */
  .pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 3rem;
  }

  .btn-page {
    min-width: 40px;
    height: 40px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 0.5rem;
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .btn-page:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
  }

  .btn-page.active {
    background: var(--primary-color);
    color: #000;
    border-color: var(--primary-color);
  }

  .pagination-ellipsis {
    color: rgba(255, 255, 255, 0.5);
    padding: 0 0.5rem;
  }

  /* Responsive */
  @media (max-width: 1024px) {
    .page-content {
      grid-template-columns: 1fr;
    }

    .filters-sidebar {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
  }

  @media (max-width: 768px) {
    .filters-sidebar {
      grid-template-columns: 1fr;
    }

    .search-form {
      flex-direction: column;
    }

    .companies-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php include '../includes/footer.php'; ?>