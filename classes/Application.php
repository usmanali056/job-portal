<?php
/**
 * JobNexus - Application Model Class
 */

require_once __DIR__ . '/Database.php';

class Application
{
  private PDO $db;

  public function __construct()
  {
    $this->db = db();
  }

  /**
   * Create new application
   */
  public function create(array $data): ?int
  {
    // Check if already applied
    if ($this->hasApplied($data['job_id'], $data['seeker_id'])) {
      return null;
    }

    $sql = "INSERT INTO applications (job_id, seeker_id, cover_letter, resume_file, status)
                VALUES (:job_id, :seeker_id, :cover_letter, :resume_file, 'applied')";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([
      'job_id' => $data['job_id'],
      'seeker_id' => $data['seeker_id'],
      'cover_letter' => $data['cover_letter'] ?? null,
      'resume_file' => $data['resume_file'] ?? null
    ]);

    $appId = (int) $this->db->lastInsertId();

    // Update job applications count
    $sql = "UPDATE jobs SET applications_count = applications_count + 1 WHERE id = :job_id";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['job_id' => $data['job_id']]);

    return $appId;
  }

  /**
   * Check if seeker has applied to job
   */
  public function hasApplied(int $jobId, int $seekerId): bool
  {
    $sql = "SELECT COUNT(*) FROM applications WHERE job_id = :job_id AND seeker_id = :seeker_id";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['job_id' => $jobId, 'seeker_id' => $seekerId]);
    return $stmt->fetchColumn() > 0;
  }

  /**
   * Find application by ID
   */
  public function findById(int $id): ?array
  {
    $sql = "SELECT a.*, 
                       j.title as job_title, j.slug as job_slug, j.company_id,
                       sp.first_name, sp.last_name, sp.headline, sp.phone, sp.location,
                       sp.profile_photo, sp.resume_file_path, sp.linkedin_url, sp.github_url,
                       u.email as seeker_email,
                       c.company_name, c.logo as company_logo
                FROM applications a
                LEFT JOIN jobs j ON a.job_id = j.id
                LEFT JOIN users u ON a.seeker_id = u.id
                LEFT JOIN seeker_profiles sp ON u.id = sp.user_id
                LEFT JOIN companies c ON j.company_id = c.id
                WHERE a.id = :id LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
  }

  /**
   * Get applications for a job
   */
  public function getByJob(int $jobId, ?string $status = null): array
  {
    $where = "a.job_id = :job_id";
    $params = ['job_id' => $jobId];

    if ($status) {
      $where .= " AND a.status = :status";
      $params['status'] = $status;
    }

    $sql = "SELECT a.*, 
                       sp.first_name, sp.last_name, sp.headline, sp.profile_photo,
                       sp.resume_file_path, sp.profile_completion,
                       u.email as seeker_email
                FROM applications a
                LEFT JOIN users u ON a.seeker_id = u.id
                LEFT JOIN seeker_profiles sp ON u.id = sp.user_id
                WHERE $where
                ORDER BY a.applied_at DESC";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
  }

  /**
   * Get applications by seeker
   */
  public function getBySeeker(int $seekerId, int $page = 1, int $perPage = 10): array
  {
    $offset = ($page - 1) * $perPage;

    $countSql = "SELECT COUNT(*) FROM applications WHERE seeker_id = :seeker_id";
    $stmt = $this->db->prepare($countSql);
    $stmt->execute(['seeker_id' => $seekerId]);
    $total = $stmt->fetchColumn();

    $sql = "SELECT a.*, 
                       j.title as job_title, j.slug as job_slug, j.location_type, j.job_type,
                       c.company_name, c.logo as company_logo
                FROM applications a
                LEFT JOIN jobs j ON a.job_id = j.id
                LEFT JOIN companies c ON j.company_id = c.id
                WHERE a.seeker_id = :seeker_id
                ORDER BY a.applied_at DESC
                LIMIT :limit OFFSET :offset";

    $stmt = $this->db->prepare($sql);
    $stmt->bindValue('seeker_id', $seekerId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'applications' => $stmt->fetchAll(),
      'total' => $total,
      'pages' => ceil($total / $perPage),
      'current_page' => $page
    ];
  }

  /**
   * Get applications for company
   */
  public function getByCompany(int $companyId, ?string $status = null, int $page = 1, int $perPage = 10): array
  {
    $offset = ($page - 1) * $perPage;

    $where = "j.company_id = :company_id";
    $params = ['company_id' => $companyId];

    if ($status) {
      $where .= " AND a.status = :status";
      $params['status'] = $status;
    }

    $countSql = "SELECT COUNT(*) FROM applications a
                     LEFT JOIN jobs j ON a.job_id = j.id
                     WHERE $where";
    $stmt = $this->db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    $sql = "SELECT a.*, 
                       j.title as job_title, j.slug as job_slug,
                       sp.first_name, sp.last_name, sp.headline, sp.profile_photo,
                       u.email as seeker_email
                FROM applications a
                LEFT JOIN jobs j ON a.job_id = j.id
                LEFT JOIN seeker_profiles sp ON a.seeker_id = sp.id
                LEFT JOIN users u ON sp.user_id = u.id
                WHERE $where
                ORDER BY a.applied_at DESC
                LIMIT :limit OFFSET :offset";

    $stmt = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'applications' => $stmt->fetchAll(),
      'total' => $total,
      'pages' => ceil($total / $perPage),
      'current_page' => $page
    ];
  }

  /**
   * Update application status
   */
  public function updateStatus(int $id, string $status, ?string $notes = null): bool
  {
    $sql = "UPDATE applications SET status = :status, status_notes = :notes, updated_at = NOW()";

    // Add timestamp for specific status changes
    if ($status === 'viewed') {
      $sql .= ", viewed_at = NOW()";
    } elseif ($status === 'shortlisted') {
      $sql .= ", shortlisted_at = NOW()";
    } elseif ($status === 'interview') {
      $sql .= ", interview_at = NOW()";
    }

    $sql .= " WHERE id = :id";

    $stmt = $this->db->prepare($sql);
    return $stmt->execute([
      'id' => $id,
      'status' => $status,
      'notes' => $notes
    ]);
  }

  /**
   * Update HR notes
   */
  public function updateNotes(int $id, string $notes): bool
  {
    $sql = "UPDATE applications SET hr_notes = :notes WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id, 'notes' => $notes]);
  }

  /**
   * Update rating
   */
  public function updateRating(int $id, int $rating): bool
  {
    $sql = "UPDATE applications SET rating = :rating WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id, 'rating' => $rating]);
  }

  /**
   * Withdraw application
   */
  public function withdraw(int $id, int $seekerId): bool
  {
    $sql = "UPDATE applications SET status = 'withdrawn' 
                WHERE id = :id AND seeker_id = :seeker_id AND status = 'applied'";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id, 'seeker_id' => $seekerId]);
  }

  /**
   * Get application stats
   */
  public function getStats(?int $companyId = null, ?int $seekerId = null): array
  {
    if ($companyId) {
      $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN a.status = 'applied' THEN 1 ELSE 0 END) as applied,
                        SUM(CASE WHEN a.status = 'viewed' THEN 1 ELSE 0 END) as viewed,
                        SUM(CASE WHEN a.status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
                        SUM(CASE WHEN a.status = 'interview' THEN 1 ELSE 0 END) as interview,
                        SUM(CASE WHEN a.status = 'hired' THEN 1 ELSE 0 END) as hired,
                        SUM(CASE WHEN a.status = 'rejected' THEN 1 ELSE 0 END) as rejected
                    FROM applications a
                    LEFT JOIN jobs j ON a.job_id = j.id
                    WHERE j.company_id = :company_id";
      $stmt = $this->db->prepare($sql);
      $stmt->execute(['company_id' => $companyId]);
    } elseif ($seekerId) {
      $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) as applied,
                        SUM(CASE WHEN status = 'viewed' THEN 1 ELSE 0 END) as viewed,
                        SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
                        SUM(CASE WHEN status = 'interview' THEN 1 ELSE 0 END) as interview,
                        SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) as hired,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                    FROM applications WHERE seeker_id = :seeker_id";
      $stmt = $this->db->prepare($sql);
      $stmt->execute(['seeker_id' => $seekerId]);
    } else {
      $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) as applied,
                        SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
                        SUM(CASE WHEN status = 'interview' THEN 1 ELSE 0 END) as interview,
                        SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) as hired
                    FROM applications";
      $stmt = $this->db->query($sql);
    }

    return $stmt->fetch();
  }

  /**
   * Get recent applications
   */
  public function getRecent(int $limit = 5, ?int $companyId = null): array
  {
    $where = "";
    $params = [];

    if ($companyId) {
      $where = "WHERE j.company_id = :company_id";
      $params['company_id'] = $companyId;
    }

    $sql = "SELECT a.*, 
                       j.title as job_title,
                       sp.first_name, sp.last_name, sp.profile_photo,
                       c.company_name
                FROM applications a
                LEFT JOIN jobs j ON a.job_id = j.id
                LEFT JOIN seeker_profiles sp ON a.seeker_id = sp.id
                LEFT JOIN companies c ON j.company_id = c.id
                $where
                ORDER BY a.applied_at DESC
                LIMIT :limit";

    $stmt = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
  }
}
