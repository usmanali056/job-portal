<?php
/**
 * JobNexus - Applications API
 * AJAX endpoint for managing applications
 */

header('Content-Type: application/json');

session_start();
require_once '../config/config.php';
require_once '../classes/Database.php';

$db = Database::getInstance()->getConnection();

// Check authentication
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Authentication required']);
  exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$method = $_SERVER['REQUEST_METHOD'];

// Handle different HTTP methods
switch ($method) {
  case 'GET':
    handleGet($db, $userId, $role);
    break;
  case 'POST':
    handlePost($db, $userId, $role);
    break;
  case 'PUT':
    handlePut($db, $userId, $role);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handleGet($db, $userId, $role)
{
  $action = $_GET['action'] ?? 'list';

  if ($action === 'list') {
    if ($role === ROLE_SEEKER) {
      // Get seeker's applications
      $stmt = $db->prepare("
                SELECT a.*, j.title as job_title, j.location as job_location,
                       c.name as company_name, c.logo as company_logo
                FROM applications a
                JOIN jobs j ON a.job_id = j.id
                JOIN companies c ON j.company_id = c.id
                WHERE a.user_id = ?
                ORDER BY a.applied_at DESC
            ");
      $stmt->execute([$userId]);
    } elseif ($role === ROLE_HR) {
      // Get applications for HR's company jobs
      $jobId = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;

      if ($jobId) {
        $stmt = $db->prepare("
                    SELECT a.*, u.first_name, u.last_name, u.email,
                           sp.headline, sp.resume_file
                    FROM applications a
                    JOIN users u ON a.user_id = u.id
                    LEFT JOIN seeker_profiles sp ON u.id = sp.user_id
                    JOIN jobs j ON a.job_id = j.id
                    JOIN companies c ON j.company_id = c.id
                    JOIN users hr ON hr.company_id = c.id AND hr.id = ?
                    WHERE a.job_id = ?
                    ORDER BY a.applied_at DESC
                ");
        $stmt->execute([$userId, $jobId]);
      } else {
        $stmt = $db->prepare("
                    SELECT a.*, j.title as job_title, u.first_name, u.last_name, u.email
                    FROM applications a
                    JOIN users u ON a.user_id = u.id
                    JOIN jobs j ON a.job_id = j.id
                    JOIN companies c ON j.company_id = c.id
                    JOIN users hr ON hr.company_id = c.id AND hr.id = ?
                    ORDER BY a.applied_at DESC
                    LIMIT 50
                ");
        $stmt->execute([$userId]);
      }
    } else {
      http_response_code(403);
      echo json_encode(['success' => false, 'message' => 'Access denied']);
      return;
    }

    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $applications]);
  }

  if ($action === 'detail') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if (!$id) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Application ID required']);
      return;
    }

    // Get application with all details
    $stmt = $db->prepare("
            SELECT a.*, j.title as job_title, j.description as job_description,
                   c.name as company_name,
                   u.first_name, u.last_name, u.email, u.phone,
                   sp.*
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            JOIN companies c ON j.company_id = c.id
            JOIN users u ON a.user_id = u.id
            LEFT JOIN seeker_profiles sp ON u.id = sp.user_id
            WHERE a.id = ?
        ");
    $stmt->execute([$id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
      http_response_code(404);
      echo json_encode(['success' => false, 'message' => 'Application not found']);
      return;
    }

    echo json_encode(['success' => true, 'data' => $application]);
  }
}

function handlePost($db, $userId, $role)
{
  if ($role !== ROLE_SEEKER) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only job seekers can apply']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);
  $jobId = isset($input['job_id']) ? intval($input['job_id']) : 0;
  $coverLetter = trim($input['cover_letter'] ?? '');

  if (!$jobId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Job ID required']);
    return;
  }

  // Check if already applied
  $stmt = $db->prepare("SELECT id FROM applications WHERE user_id = ? AND job_id = ?");
  $stmt->execute([$userId, $jobId]);
  if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'You have already applied for this job']);
    return;
  }

  // Check if job exists and is active
  $stmt = $db->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'active'");
  $stmt->execute([$jobId]);
  if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Job not found or no longer accepting applications']);
    return;
  }

  // Create application
  $stmt = $db->prepare("
        INSERT INTO applications (user_id, job_id, cover_letter, status, applied_at)
        VALUES (?, ?, ?, 'pending', NOW())
    ");

  if ($stmt->execute([$userId, $jobId, $coverLetter])) {
    $applicationId = $db->lastInsertId();
    echo json_encode([
      'success' => true,
      'message' => 'Application submitted successfully',
      'data' => ['id' => $applicationId]
    ]);
  } else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error submitting application']);
  }
}

function handlePut($db, $userId, $role)
{
  if ($role !== ROLE_HR && $role !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);
  $applicationId = isset($input['id']) ? intval($input['id']) : 0;
  $status = trim($input['status'] ?? '');
  $notes = trim($input['notes'] ?? '');

  if (!$applicationId || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Application ID and status required']);
    return;
  }

  $validStatuses = ['pending', 'reviewed', 'shortlisted', 'interview', 'offered', 'hired', 'rejected'];
  if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    return;
  }

  // Verify HR has access to this application
  if ($role === ROLE_HR) {
    $stmt = $db->prepare("
            SELECT a.id FROM applications a
            JOIN jobs j ON a.job_id = j.id
            JOIN companies c ON j.company_id = c.id
            JOIN users u ON u.company_id = c.id
            WHERE a.id = ? AND u.id = ?
        ");
    $stmt->execute([$applicationId, $userId]);
    if (!$stmt->fetch()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'message' => 'Access denied']);
      return;
    }
  }

  // Update application
  $stmt = $db->prepare("UPDATE applications SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");

  if ($stmt->execute([$status, $notes, $applicationId])) {
    echo json_encode(['success' => true, 'message' => 'Application updated successfully']);
  } else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error updating application']);
  }
}
