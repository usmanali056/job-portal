<?php
/**
 * JobNexus - User Model Class
 */

require_once __DIR__ . '/Database.php';

class User
{
  private PDO $db;

  public function __construct()
  {
    $this->db = db();
  }

  /**
   * Create new user
   */
  public function create(array $data): ?int
  {
    $sql = "INSERT INTO users (email, password_hash, role, is_verified, is_active) 
                VALUES (:email, :password_hash, :role, :is_verified, 1)";

    $stmt = $this->db->prepare($sql);
    $stmt->execute([
      'email' => $data['email'],
      'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
      'role' => $data['role'],
      'is_verified' => $data['role'] === ROLE_SEEKER ? 1 : 0
    ]);

    return (int) $this->db->lastInsertId();
  }

  /**
   * Find user by email
   */
  public function findByEmail(string $email): ?array
  {
    $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    return $user ?: null;
  }

  /**
   * Find user by ID
   */
  public function findById(int $id): ?array
  {
    $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
  }

  /**
   * Verify password
   */
  public function verifyPassword(string $password, string $hash): bool
  {
    return password_verify($password, $hash);
  }

  /**
   * Update last login
   */
  public function updateLastLogin(int $userId): void
  {
    $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(['id' => $userId]);
  }

  /**
   * Get all users with pagination
   */
  public function getAll(int $page = 1, int $perPage = USERS_PER_PAGE, ?string $role = null): array
  {
    $offset = ($page - 1) * $perPage;

    $where = "";
    $params = [];

    if ($role) {
      $where = "WHERE role = :role";
      $params['role'] = $role;
    }

    // Get total count
    $countSql = "SELECT COUNT(*) FROM users $where";
    $stmt = $this->db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Get users
    $sql = "SELECT id, email, role, is_verified, is_active, created_at, last_login 
                FROM users $where 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";

    $stmt = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
      'users' => $stmt->fetchAll(),
      'total' => $total,
      'pages' => ceil($total / $perPage),
      'current_page' => $page
    ];
  }

  /**
   * Verify HR account
   */
  public function verifyAccount(int $userId): bool
  {
    $sql = "UPDATE users SET is_verified = 1 WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $userId]);
  }

  /**
   * Get pending HR accounts
   */
  public function getPendingHR(): array
  {
    $sql = "SELECT u.*, c.company_name, c.logo, c.industry, c.website, c.verification_status
                FROM users u
                LEFT JOIN companies c ON u.id = c.hr_user_id
                WHERE u.role = 'hr' AND u.is_verified = 0
                ORDER BY u.created_at DESC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  /**
   * Get dashboard stats
   */
  public function getStats(): array
  {
    $stats = [];

    // Total users
    $stmt = $this->db->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = $stmt->fetchColumn();

    // By role
    $stmt = $this->db->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    $roles = $stmt->fetchAll();
    foreach ($roles as $role) {
      $stats[$role['role'] . '_count'] = $role['count'];
    }

    // New users today
    $stmt = $this->db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
    $stats['new_today'] = $stmt->fetchColumn();

    return $stats;
  }

  /**
   * Update user
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

    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute($params);
  }

  /**
   * Delete user
   */
  public function delete(int $id): bool
  {
    $sql = "DELETE FROM users WHERE id = :id";
    $stmt = $this->db->prepare($sql);
    return $stmt->execute(['id' => $id]);
  }
}
