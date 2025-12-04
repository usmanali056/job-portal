<?php
/**
 * JobNexus - Job Search API
 * AJAX endpoint for job searching and filtering
 */

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../classes/Database.php';

$db = Database::getInstance()->getConnection();

// Get parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$jobType = isset($_GET['job_type']) ? trim($_GET['job_type']) : '';
$salaryMin = isset($_GET['salary_min']) ? intval($_GET['salary_min']) : 0;
$salaryMax = isset($_GET['salary_max']) ? intval($_GET['salary_max']) : 0;
$experienceLevel = isset($_GET['experience_level']) ? trim($_GET['experience_level']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? min(50, max(1, intval($_GET['per_page']))) : 10;

// Build query
$where = ["j.status = 'active'", "c.verification_status = 'verified'"];
$params = [];

if ($search) {
  $where[] = "(j.title LIKE ? OR j.description LIKE ? OR c.company_name LIKE ? OR j.requirements LIKE ?)";
  $searchTerm = "%$search%";
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
  $params[] = $searchTerm;
}

if ($category) {
  $where[] = "j.category = ?";
  $params[] = $category;
}

if ($location) {
  $where[] = "(j.location LIKE ? OR c.location LIKE ?)";
  $params[] = "%$location%";
  $params[] = "%$location%";
}

if ($jobType) {
  $where[] = "j.job_type = ?";
  $params[] = $jobType;
}

if ($salaryMin > 0) {
  $where[] = "j.salary_max >= ?";
  $params[] = $salaryMin;
}

if ($salaryMax > 0) {
  $where[] = "j.salary_min <= ?";
  $params[] = $salaryMax;
}

if ($experienceLevel) {
  $where[] = "j.experience_level = ?";
  $params[] = $experienceLevel;
}

$whereClause = implode(' AND ', $where);
$offset = ($page - 1) * $perPage;

// Get total count
$countSql = "SELECT COUNT(*) FROM jobs j JOIN companies c ON j.company_id = c.id WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalJobs = $countStmt->fetchColumn();
$totalPages = ceil($totalJobs / $perPage);

// Get jobs
$sql = "
    SELECT j.*, c.company_name, c.logo as company_logo, c.headquarters as company_location,
           (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE $whereClause
    ORDER BY j.is_featured DESC, j.created_at DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format response
$formattedJobs = array_map(function ($job) {
  return [
    'id' => (int) $job['id'],
    'title' => $job['title'],
    'company' => [
      'name' => $job['company_name'],
      'logo' => $job['company_logo'] ? '/uploads/logos/' . $job['company_logo'] : null,
      'location' => $job['company_location']
    ],
    'location' => $job['location'],
    'job_type' => $job['job_type'],
    'category' => $job['category'],
    'experience_level' => $job['experience_level'],
    'salary' => [
      'min' => $job['salary_min'] ? (int) $job['salary_min'] : null,
      'max' => $job['salary_max'] ? (int) $job['salary_max'] : null
    ],
    'description' => substr($job['description'], 0, 200) . '...',
    'is_featured' => (bool) $job['is_featured'],
    'deadline' => $job['application_deadline'],
    'created_at' => $job['created_at'],
    'application_count' => (int) $job['application_count'],
    'url' => '/jobs/view.php?id=' . $job['id']
  ];
}, $jobs);

echo json_encode([
  'success' => true,
  'data' => [
    'jobs' => $formattedJobs,
    'pagination' => [
      'current_page' => $page,
      'per_page' => $perPage,
      'total_jobs' => (int) $totalJobs,
      'total_pages' => $totalPages,
      'has_more' => $page < $totalPages
    ]
  ]
]);
