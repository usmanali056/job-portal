<?php
/**
 * JobNexus - Company Model Class
 */

require_once __DIR__ . '/Database.php';

class Company
{
  private PDO $db;

  public function __construct()
  {
    $this->db = db();
  }

  /**
   * Create new company
   */
  public function create(array $data): ?int
  {
    $sql = "INSERT INTO companies (
            hr_user_id, company_name, slug, logo, website, email, phone,
            description, industry, company_size, headquarters, verification_status
        ) VALUES (
            :hr_user_id, :company_name, :slug, :logo, :website, :email, :phone,
            :description, :industry, :company_size, :headquarters, 'pending'
        )";

    $slug = generateSlug($data['company_name']);

    // Check if slug exists
    $checkSql = "SELECT COUNT(*) FROM companies WHERE slug = :slug";
    $stmt = $this->db->prepare($checkSql);
    $stmt->execute(['slug' => $slug]);
    if ($stmt->fetchColumn() > 0) {
      $slug .= '-' . uniqid();
    }

    $stmt = $this->db->prepare($sql);
    $stmt->execute([
      'hr_user_id' => $data['hr_user_id'],
      'company_name' => $data['company_name'],
      'slug' => $slug,
      'logo' => $data['logo'] ?? null,
      'website' => $data['website'] ?? null,
      'email' => $data['email'] ?? null,
      'phone' => $data['phone'] ?? null,
      'description' => $data['description'] ?? null,
      'industry' => $data['industry'] ?? null,
      'company_size' => $data['company_size'] ?? null,
      'headquarters' => $data['headquarters'] ?? null
    ]);

    return (int) $this->db->lastInsertId();
  }

  /**
   * Find company by ID
   */
  public function findById(int $id): ?array
  {
    $sql = "SELECT c.*, u.email as hr_email
                FROM companies c
                LEFT JOIN users u ON c.hr_user_id = u.id
                WHERE c.id = :id LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Find company by HR user ID
   */
  public function findByHRUserId(int $userId): ?array
  {
    $sql = "SELECT * FROM companies WHERE hr_user_id = :user_id LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Find company by slug
   */
  public function findBySlug(string $slug): ?array
  {
    $sql = "SELECT c.*, u.email as hr_email
                FROM companies c
                LEFT JOIN users u ON c.hr_user_id = u.id
                WHERE c.slug = :slug LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['slug' => $slug]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Update company
   */
  public function update(int $id, array $data): bool
  {
    $fields = [];
    $params = ['id' => $id];

    foreach ($data as $key => $value) {
      if ($key !== 'id') {
        $fields[] = "$key = :$key";
        $params[$key] = $value;
      }
    }

    $sql = "UPDATE companies SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute($params);
  }

  /**
   * Verify company
   */
  public function verify(int $id): bool
  {
    $sql = "UPDATE companies SET verification_status = 'verified', verified_at = NOW() WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    $result = $stmt->execute(['id' => $id]);

    if ($result) {
      // Also verify the HR user
      $company = $this->findById($id);
      if ($company) {
        $userSql = "UPDATE users SET is_verified = 1 WHERE id = :user_id";
        $userStmt = $this->db->prepare($userSql);
        $userStmt->execute(['user_id' => $company['hr_user_id']]);

        // Publish all pending jobs
        $jobSql = "UPDATE jobs SET status = 'active', published_at = NOW() 
                          WHERE company_id = :company_id AND status IN ('draft', 'pending')";
        $jobStmt = $this->db->prepare($jobSql);
        $jobStmt->execute(['company_id' => $id]);
      }
    }

    return $result;
  }

  /**
   * Reject company
   */
  public function reject(int $id, string $reason = ''): bool
  {
    $sql = "UPDATE companies SET verification_status = 'rejected', rejection_reason = :reason WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id, 'reason' => $reason]);
  }

  /**
   * Get pending companies
   */
  public function getPending(): array
  {
    $sql = "SELECT c.*, u.email as hr_email, u.created_at as user_created_at
                FROM companies c
                LEFT JOIN users u ON c.hr_user_id = u.id
                WHERE c.verification_status = 'pending'
                ORDER BY c.created_at DESC";
    $stmt = $this->db->query($sql);
    return $stmt->fetchAll();
  }

  /**
   * Get all companies
   */
  public function getAll(?string $status = null, int $page = 1, int $perPage = 10): array
  {
    $offset = ($page - 1) * $perPage;
    $where = "";
    $params = [];

    if ($status) {
      $where = "WHERE c.verification_status = :status";
      $params['status'] = $status;
    }

    // Get total
    $countSql = "SELECT COUNT(*) FROM companies c $where";
    $stmt = $this->db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Get companies
    $sql = "SELECT c.*, u.email as hr_email,
                       (SELECT COUNT(*) FROM jobs WHERE company_id = c.id) as jobs_count
                FROM companies c
                LEFT JOIN users u ON c.hr_user_id = u.id
                $where
                ORDER BY c.created_at DESC
                LIMIT :limit OFFSET :offset";

    $stmt = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'companies' => $stmt->fetchAll(),
      'total' => $total,
      'pages' => ceil($total / $perPage),
      'current_page' => $page
    ];
  }

  /**
   * Get company stats
   */
  public function getStats(): array
  {
    $stats = [];

    $stmt = $this->db->query("SELECT COUNT(*) FROM companies");
    $stats['total'] = $stmt->fetchColumn();

    $stmt = $this->db->query("SELECT COUNT(*) FROM companies WHERE verification_status = 'pending'");
    $stats['pending'] = $stmt->fetchColumn();

    $stmt = $this->db->query("SELECT COUNT(*) FROM companies WHERE verification_status = 'verified'");
    $stats['verified'] = $stmt->fetchColumn();

    return $stats;
  }

  /**
   * Get featured companies
   */
  public function getFeatured(int $limit = 6): array
  {
    $sql = "SELECT c.*, 
                       (SELECT COUNT(*) FROM jobs WHERE company_id = c.id AND status = 'active') as active_jobs
                FROM companies c
                WHERE c.verification_status = 'verified' AND c.is_featured = 1
                ORDER BY c.created_at DESC
                LIMIT :limit";
    $stmt = $this->db->prepare($sql);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }
}
