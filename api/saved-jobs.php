<?php
/**
 * JobNexus - Saved Jobs API
 * AJAX endpoint for saving/unsaving jobs
 */

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../classes/Database.php';

$db = Database::getInstance()->getConnection();

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_SEEKER) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Authentication required']);
  exit;
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
  case 'GET':
    // Get all saved jobs
    $stmt = $db->prepare("
            SELECT j.*, c.company_name, c.logo as company_logo, sj.saved_at
            FROM saved_jobs sj
            JOIN jobs j ON sj.job_id = j.id
            JOIN companies c ON j.company_id = c.id
            WHERE sj.user_id = ?
            ORDER BY sj.saved_at DESC
        ");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $jobs]);
    break;

  case 'POST':
    $input = json_decode(file_get_contents('php://input'), true);
    $jobId = isset($input['job_id']) ? intval($input['job_id']) : 0;
    $action = $input['action'] ?? 'toggle';

    if (!$jobId) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Job ID required']);
      exit;
    }

    // Check if job exists
    $stmt = $db->prepare("SELECT id FROM jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    if (!$stmt->fetch()) {
      http_response_code(404);
      echo json_encode(['success' => false, 'message' => 'Job not found']);
      exit;
    }

    // Check if already saved
    $stmt = $db->prepare("SELECT id FROM saved_jobs WHERE user_id = ? AND job_id = ?");
    $stmt->execute([$userId, $jobId]);
    $isSaved = $stmt->fetch();

    if ($action === 'toggle') {
      if ($isSaved) {
        // Unsave
        $stmt = $db->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
        $stmt->execute([$userId, $jobId]);
        echo json_encode(['success' => true, 'saved' => false, 'message' => 'Job removed from saved list']);
      } else {
        // Save
        $stmt = $db->prepare("INSERT INTO saved_jobs (user_id, job_id, saved_at) VALUES (?, ?, NOW())");
        $stmt->execute([$userId, $jobId]);
        echo json_encode(['success' => true, 'saved' => true, 'message' => 'Job saved successfully']);
      }
    } elseif ($action === 'save') {
      if (!$isSaved) {
        $stmt = $db->prepare("INSERT INTO saved_jobs (user_id, job_id, saved_at) VALUES (?, ?, NOW())");
        $stmt->execute([$userId, $jobId]);
      }
      echo json_encode(['success' => true, 'saved' => true, 'message' => 'Job saved successfully']);
    } elseif ($action === 'unsave') {
      if ($isSaved) {
        $stmt = $db->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
        $stmt->execute([$userId, $jobId]);
      }
      echo json_encode(['success' => true, 'saved' => false, 'message' => 'Job removed from saved list']);
    }
    break;

  case 'DELETE':
    $jobId = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;

    if (!$jobId) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Job ID required']);
      exit;
    }

    $stmt = $db->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
    $stmt->execute([$userId, $jobId]);

    echo json_encode(['success' => true, 'message' => 'Job removed from saved list']);
    break;

  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
