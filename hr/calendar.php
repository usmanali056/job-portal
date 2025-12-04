<?php
/**
 * JobNexus - HR Calendar / Interview Scheduling
 */

require_once '../config/config.php';
require_once '../classes/Database.php';
require_once '../classes/User.php';
require_once '../classes/Event.php';
require_once '../classes/Application.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== ROLE_HR) {
  header('Location: ' . BASE_URL . '/auth/login.php?redirect=hr/calendar');
  exit;
}

$db = Database::getInstance()->getConnection();
$userModel = new User();
$eventModel = new Event();

$hr = $userModel->findById($_SESSION['user_id']);

if (!$hr['is_verified']) {
  header('Location: ' . BASE_URL . '/hr/index.php');
  exit;
}

// Get current month/year for calendar
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');

// Validate month/year
if ($month < 1) {
  $month = 12;
  $year--;
}
if ($month > 12) {
  $month = 1;
  $year++;
}

$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$startingDay = date('w', $firstDay);
$monthName = date('F', $firstDay);

// Get events for this month
$startDate = date('Y-m-01', $firstDay);
$endDate = date('Y-m-t', $firstDay);

$stmt = $db->prepare("
    SELECT e.*, CONCAT(sp.first_name, ' ', sp.last_name) as seeker_name, j.title as job_title
    FROM events e
    LEFT JOIN seeker_profiles sp ON e.seeker_user_id = sp.user_id
    LEFT JOIN applications a ON e.application_id = a.id
    LEFT JOIN jobs j ON a.job_id = j.id
    WHERE e.hr_user_id = ? 
    AND e.event_date BETWEEN ? AND ?
    ORDER BY e.event_date ASC, e.event_time ASC
");
$stmt->execute([$_SESSION['user_id'], $startDate, $endDate]);
$events = $stmt->fetchAll();

// Group events by date
$eventsByDate = [];
foreach ($events as $event) {
  $date = $event['event_date'];
  if (!isset($eventsByDate[$date])) {
    $eventsByDate[$date] = [];
  }
  $eventsByDate[$date][] = $event;
}

// Get upcoming events for sidebar
$stmt = $db->prepare("
    SELECT e.*, CONCAT(sp.first_name, ' ', sp.last_name) as seeker_name, u.email as seeker_email, j.title as job_title
    FROM events e
    JOIN users u ON e.seeker_user_id = u.id
    LEFT JOIN seeker_profiles sp ON u.id = sp.user_id
    LEFT JOIN applications a ON e.application_id = a.id
    LEFT JOIN jobs j ON a.job_id = j.id
    WHERE e.hr_user_id = ? AND e.event_date >= CURDATE() AND e.status = 'scheduled'
    ORDER BY e.event_date ASC, e.event_time ASC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$upcomingEvents = $stmt->fetchAll();

// Get shortlisted candidates for scheduling dropdown
$stmt = $db->prepare("
    SELECT a.id as app_id, a.seeker_id, CONCAT(sp.first_name, ' ', sp.last_name) as full_name, j.id as job_id, j.title as job_title
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    LEFT JOIN seeker_profiles sp ON a.seeker_id = sp.user_id
    WHERE j.posted_by = ? AND a.status IN ('shortlisted', 'interview')
    ORDER BY a.applied_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$candidates = $stmt->fetchAll();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'create_event') {
    $eventData = [
      'hr_user_id' => $_SESSION['user_id'],
      'seeker_user_id' => (int) $_POST['seeker_id'],
      'application_id' => (int) ($_POST['application_id'] ?? 0) ?: null,
      'event_title' => trim($_POST['event_title'] ?? 'Interview'),
      'event_type' => $_POST['event_type'],
      'event_date' => $_POST['event_date'],
      'event_time' => $_POST['event_time'],
      'duration_minutes' => (int) $_POST['duration'],
      'location' => trim($_POST['location'] ?? ''),
      'meeting_link' => trim($_POST['meeting_link'] ?? ''),
      'description' => trim($_POST['notes'] ?? ''),
      'status' => 'scheduled'
    ];

    $eventId = $eventModel->create($eventData);

    if ($eventId) {
      // Update application status to 'interview'
      $stmt = $db->prepare("UPDATE applications SET status = 'interview' WHERE seeker_id = ? AND job_id = ?");
      $stmt->execute([$eventData['seeker_user_id'], (int) $_POST['job_id']]);

      $message = 'Interview scheduled successfully!';
      $messageType = 'success';

      // Refresh the page to show updated data
      header('Location: ' . BASE_URL . '/hr/calendar.php?month=' . $month . '&year=' . $year . '&scheduled=1');
      exit;
    } else {
      $message = 'Failed to schedule interview.';
      $messageType = 'error';
    }
  } elseif ($action === 'cancel_event') {
    $eventId = (int) $_POST['event_id'];

    $stmt = $db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ? AND hr_id = ?");
    if ($stmt->execute([$eventId, $_SESSION['user_id']])) {
      $message = 'Event cancelled.';
      $messageType = 'success';
    }
  } elseif ($action === 'complete_event') {
    $eventId = (int) $_POST['event_id'];

    $stmt = $db->prepare("UPDATE events SET status = 'completed' WHERE id = ? AND hr_id = ?");
    if ($stmt->execute([$eventId, $_SESSION['user_id']])) {
      $message = 'Event marked as completed.';
      $messageType = 'success';
    }
  }
}

if (isset($_GET['scheduled'])) {
  $message = 'Interview scheduled successfully!';
  $messageType = 'success';
}

$pageTitle = 'Calendar';
require_once '../includes/header.php';

// Get company info for sidebar
$stmt = $db->prepare("SELECT * FROM companies WHERE hr_user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="dashboard-container">
  <!-- Sidebar -->
  <aside class="dashboard-sidebar">
    <div class="sidebar-header">
      <div class="hr-avatar">
        <?php echo strtoupper(substr($company['company_name'] ?? 'HR', 0, 2)); ?>
      </div>
      <h3><?php echo htmlspecialchars($company['company_name'] ?? $hr['email']); ?></h3>
      <span class="role-badge hr">HR Manager</span>
    </div>

    <nav class="sidebar-nav">
      <a href="<?php echo BASE_URL; ?>/hr/index.php" class="nav-item">
        <i class="fas fa-tachometer-alt"></i>
        <span>Dashboard</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/jobs.php" class="nav-item">
        <i class="fas fa-briefcase"></i>
        <span>My Jobs</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/post-job.php" class="nav-item">
        <i class="fas fa-plus-circle"></i>
        <span>Post New Job</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/applications.php" class="nav-item">
        <i class="fas fa-file-alt"></i>
        <span>Applications</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/calendar.php" class="nav-item active">
        <i class="fas fa-calendar-alt"></i>
        <span>Calendar</span>
      </a>
      <a href="<?php echo BASE_URL; ?>/hr/company.php" class="nav-item">
        <i class="fas fa-building"></i>
        <span>Company Profile</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="<?php echo BASE_URL; ?>/auth/logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="dashboard-main">
    <div class="dashboard-header">
      <div class="header-left">
        <h1><i class="fas fa-calendar-alt"></i> Interview Calendar</h1>
        <p>Schedule and manage candidate interviews</p>
      </div>
      <div class="header-right">
        <button class="btn btn-primary" onclick="openScheduleModal()">
          <i class="fas fa-plus"></i> Schedule Interview
        </button>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <div class="calendar-layout">
      <!-- Main Calendar -->
      <div class="calendar-main">
        <div class="calendar-card">
          <div class="calendar-nav">
            <a href="?month=<?php echo $month - 1; ?>&year=<?php echo $year; ?>" class="nav-btn">
              <i class="fas fa-chevron-left"></i>
            </a>
            <h2><?php echo $monthName . ' ' . $year; ?></h2>
            <a href="?month=<?php echo $month + 1; ?>&year=<?php echo $year; ?>" class="nav-btn">
              <i class="fas fa-chevron-right"></i>
            </a>
          </div>

          <div class="calendar-grid">
            <div class="calendar-header">
              <span>Sun</span>
              <span>Mon</span>
              <span>Tue</span>
              <span>Wed</span>
              <span>Thu</span>
              <span>Fri</span>
              <span>Sat</span>
            </div>

            <div class="calendar-body">
              <?php
              $currentDay = 1;
              $today = date('Y-m-d');

              // Fill in empty cells before first day
              for ($i = 0; $i < $startingDay; $i++): ?>
                <div class="calendar-day empty"></div>
              <?php endfor;

              // Fill in days
              while ($currentDay <= $daysInMonth):
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $currentDay);
                $isToday = $dateStr === $today;
                $hasEvents = isset($eventsByDate[$dateStr]);
                ?>
                <div
                  class="calendar-day <?php echo $isToday ? 'today' : ''; ?> <?php echo $hasEvents ? 'has-events' : ''; ?>">
                  <span class="day-number"><?php echo $currentDay; ?></span>
                  <?php if ($hasEvents): ?>
                    <div class="day-events">
                      <?php foreach (array_slice($eventsByDate[$dateStr], 0, 2) as $event): ?>
                        <div class="event-dot <?php echo $event['event_type']; ?>"
                          title="<?php echo htmlspecialchars($event['seeker_name']); ?> - <?php echo date('g:i A', strtotime($event['event_time'])); ?>">
                        </div>
                      <?php endforeach; ?>
                      <?php if (count($eventsByDate[$dateStr]) > 2): ?>
                        <span class="more-events">+<?php echo count($eventsByDate[$dateStr]) - 2; ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <?php
                $currentDay++;
              endwhile;

              // Fill remaining cells
              $remainingCells = 7 - (($startingDay + $daysInMonth) % 7);
              if ($remainingCells < 7):
                for ($i = 0; $i < $remainingCells; $i++): ?>
                  <div class="calendar-day empty"></div>
                <?php endfor;
              endif;
              ?>
            </div>
          </div>

          <div class="calendar-legend">
            <span class="legend-item">
              <span class="legend-dot video"></span> Video Call
            </span>
            <span class="legend-item">
              <span class="legend-dot phone"></span> Phone
            </span>
            <span class="legend-item">
              <span class="legend-dot in-person"></span> In Person
            </span>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="calendar-sidebar">
        <div class="sidebar-card">
          <h3><i class="fas fa-calendar-check"></i> Upcoming Interviews</h3>

          <?php if (empty($upcomingEvents)): ?>
            <div class="empty-sidebar">
              <p>No upcoming interviews</p>
            </div>
          <?php else: ?>
            <div class="upcoming-list">
              <?php foreach ($upcomingEvents as $event): ?>
                <div class="upcoming-item">
                  <div class="event-date-badge">
                    <span class="day"><?php echo date('d', strtotime($event['event_date'])); ?></span>
                    <span class="month"><?php echo date('M', strtotime($event['event_date'])); ?></span>
                  </div>
                  <div class="event-details">
                    <h4><?php echo htmlspecialchars($event['seeker_name']); ?></h4>
                    <p class="job"><?php echo htmlspecialchars($event['job_title'] ?? 'Interview'); ?></p>
                    <div class="event-meta">
                      <span class="time">
                        <i class="fas fa-clock"></i>
                        <?php echo date('g:i A', strtotime($event['event_time'])); ?>
                      </span>
                      <span class="type <?php echo $event['event_type']; ?>">
                        <?php if ($event['event_type'] === 'video'): ?>
                          <i class="fas fa-video"></i>
                        <?php elseif ($event['event_type'] === 'phone'): ?>
                          <i class="fas fa-phone"></i>
                        <?php else: ?>
                          <i class="fas fa-building"></i>
                        <?php endif; ?>
                      </span>
                    </div>
                  </div>
                  <div class="event-actions">
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="action" value="complete_event">
                      <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                      <button type="submit" class="btn-icon success" title="Mark Complete">
                        <i class="fas fa-check"></i>
                      </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel this interview?');">
                      <input type="hidden" name="action" value="cancel_event">
                      <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                      <button type="submit" class="btn-icon danger" title="Cancel">
                        <i class="fas fa-times"></i>
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="sidebar-card tips-card">
          <h3><i class="fas fa-lightbulb"></i> Interview Tips</h3>
          <ul>
            <li>Send calendar invites 24-48 hours before</li>
            <li>Prepare questions in advance</li>
            <li>Test video/audio before video interviews</li>
            <li>Follow up within 24 hours</li>
          </ul>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Schedule Interview Modal -->
<div class="modal" id="scheduleModal">
  <div class="modal-backdrop" onclick="closeScheduleModal()"></div>
  <div class="modal-content modal-lg">
    <div class="modal-header">
      <h3><i class="fas fa-calendar-plus"></i> Schedule Interview</h3>
      <button class="btn-icon" onclick="closeScheduleModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_event">
      <div class="modal-body">
        <?php if (empty($candidates)): ?>
          <div class="empty-form-state">
            <i class="fas fa-users"></i>
            <h4>No Candidates to Schedule</h4>
            <p>Shortlist candidates from applications first before scheduling interviews.</p>
            <a href="<?php echo BASE_URL; ?>/hr/applications.php" class="btn btn-primary">
              View Applications
            </a>
          </div>
        <?php else: ?>
          <div class="form-group">
            <label for="candidate">Select Candidate <span class="required">*</span></label>
            <select id="candidate" name="seeker_id" class="form-control" required onchange="updateJobId(this)">
              <option value="">Choose a candidate...</option>
              <?php foreach ($candidates as $c): ?>
                <option value="<?php echo $c['seeker_id']; ?>" data-job="<?php echo $c['job_id']; ?>">
                  <?php echo htmlspecialchars($c['full_name']); ?> - <?php echo htmlspecialchars($c['job_title']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="job_id" id="job_id" value="">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="event_type">Interview Type <span class="required">*</span></label>
              <select id="event_type" name="event_type" class="form-control" required>
                <option value="video">Video Call</option>
                <option value="phone">Phone Call</option>
                <option value="in-person">In Person</option>
              </select>
            </div>
            <div class="form-group">
              <label for="duration">Duration <span class="required">*</span></label>
              <select id="duration" name="duration" class="form-control" required>
                <option value="30">30 minutes</option>
                <option value="45">45 minutes</option>
                <option value="60" selected>1 hour</option>
                <option value="90">1.5 hours</option>
                <option value="120">2 hours</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="event_date">Date <span class="required">*</span></label>
              <input type="date" id="event_date" name="event_date" class="form-control" required
                min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
              <label for="event_time">Time <span class="required">*</span></label>
              <input type="time" id="event_time" name="event_time" class="form-control" required>
            </div>
          </div>

          <div class="form-group">
            <label for="location">Location / Address</label>
            <input type="text" id="location" name="location" class="form-control"
              placeholder="Office address for in-person interviews">
          </div>

          <div class="form-group">
            <label for="meeting_link">Meeting Link</label>
            <input type="url" id="meeting_link" name="meeting_link" class="form-control"
              placeholder="https://zoom.us/j/... or Google Meet link">
          </div>

          <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" class="form-control" rows="3"
              placeholder="Any additional information for the candidate..."></textarea>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($candidates)): ?>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" onclick="closeScheduleModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-calendar-check"></i> Schedule Interview
          </button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<style>
  /* Calendar Page */
  .calendar-page {
    min-height: calc(100vh - 70px);
    padding: 2rem;
    margin-top: 70px;
    background: var(--bg-dark);
  }

  .calendar-container {
    max-width: 1300px;
    margin: 0 auto;
  }

  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
  }

  .page-header h1 {
    font-family: var(--font-heading);
    font-size: 2rem;
    margin-bottom: 0.25rem;
  }

  .page-header p {
    color: var(--text-muted);
  }

  /* Calendar Layout */
  .calendar-layout {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 2rem;
  }

  /* Calendar Card */
  .calendar-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid var(--border-color);
  }

  .calendar-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
  }

  .calendar-nav h2 {
    font-size: 1.5rem;
  }

  .nav-btn {
    width: 40px;
    height: 40px;
    background: var(--bg-dark);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    text-decoration: none;
    transition: all 0.3s ease;
  }

  .nav-btn:hover {
    background: var(--primary-color);
    color: var(--bg-dark);
  }

  /* Calendar Grid */
  .calendar-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 0.5rem;
  }

  .calendar-header span {
    text-align: center;
    padding: 0.75rem;
    color: var(--text-muted);
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
  }

  .calendar-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
  }

  .calendar-day {
    aspect-ratio: 1;
    background: var(--bg-dark);
    border-radius: 8px;
    padding: 0.5rem;
    position: relative;
    transition: all 0.3s ease;
  }

  .calendar-day.empty {
    background: transparent;
  }

  .calendar-day:not(.empty):hover {
    border: 1px solid var(--primary-color);
  }

  .calendar-day.today {
    background: rgba(0, 230, 118, 0.1);
  }

  .calendar-day.today .day-number {
    color: var(--primary-color);
    font-weight: 700;
  }

  .day-number {
    font-size: 0.9rem;
  }

  .day-events {
    display: flex;
    gap: 4px;
    margin-top: 4px;
    flex-wrap: wrap;
  }

  .event-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
  }

  .event-dot.video {
    background: #2196F3;
  }

  .event-dot.phone {
    background: #4CAF50;
  }

  .event-dot.in-person {
    background: #9C27B0;
  }

  .more-events {
    font-size: 0.65rem;
    color: var(--text-muted);
  }

  .calendar-legend {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
  }

  .legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--text-muted);
  }

  .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
  }

  .legend-dot.video {
    background: #2196F3;
  }

  .legend-dot.phone {
    background: #4CAF50;
  }

  .legend-dot.in-person {
    background: #9C27B0;
  }

  /* Sidebar */
  .calendar-sidebar {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
  }

  .sidebar-card {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid var(--border-color);
  }

  .sidebar-card h3 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    font-size: 1rem;
  }

  .sidebar-card h3 i {
    color: var(--primary-color);
  }

  .empty-sidebar {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
  }

  .upcoming-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .upcoming-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: var(--bg-dark);
    border-radius: 10px;
  }

  .event-date-badge {
    width: 50px;
    height: 50px;
    background: rgba(0, 230, 118, 0.1);
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .event-date-badge .day {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-color);
    line-height: 1;
  }

  .event-date-badge .month {
    font-size: 0.65rem;
    color: var(--text-muted);
    text-transform: uppercase;
  }

  .event-details {
    flex: 1;
    min-width: 0;
  }

  .event-details h4 {
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .event-details .job {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
  }

  .event-meta {
    display: flex;
    gap: 0.75rem;
    font-size: 0.75rem;
  }

  .event-meta .time {
    color: var(--primary-color);
  }

  .event-meta .type.video {
    color: #2196F3;
  }

  .event-meta .type.phone {
    color: #4CAF50;
  }

  .event-meta .type.in-person {
    color: #9C27B0;
  }

  .event-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  .event-actions .btn-icon {
    width: 28px;
    height: 28px;
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.75rem;
  }

  .event-actions .btn-icon.success:hover {
    background: #4CAF50;
    border-color: #4CAF50;
    color: white;
  }

  .event-actions .btn-icon.danger:hover {
    background: #F44336;
    border-color: #F44336;
    color: white;
  }

  /* Tips Card */
  .tips-card {
    background: rgba(255, 193, 7, 0.05);
    border-color: rgba(255, 193, 7, 0.2);
  }

  .tips-card h3 {
    color: #FFC107;
  }

  .tips-card ul {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  .tips-card li {
    padding: 0.5rem 0;
    padding-left: 1.5rem;
    position: relative;
    color: var(--text-muted);
    font-size: 0.85rem;
  }

  .tips-card li::before {
    content: '•';
    position: absolute;
    left: 0;
    color: var(--primary-color);
  }

  /* Modal */
  .modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    align-items: center;
    justify-content: center;
  }

  .modal.active {
    display: flex;
  }

  .modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(5px);
  }

  .modal-content {
    position: relative;
    background: var(--card-bg);
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid var(--border-color);
  }

  .modal-content.modal-lg {
    max-width: 600px;
  }

  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
  }

  .modal-header h3 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.25rem;
  }

  .modal-header h3 i {
    color: var(--primary-color);
  }

  .modal-body {
    padding: 1.5rem;
  }

  .modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
  }

  .empty-form-state {
    text-align: center;
    padding: 2rem;
  }

  .empty-form-state i {
    font-size: 3rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
  }

  .empty-form-state h4 {
    margin-bottom: 0.5rem;
  }

  .empty-form-state p {
    color: var(--text-muted);
    margin-bottom: 1rem;
  }

  /* Form Styles */
  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }

  .form-group {
    margin-bottom: 1rem;
  }

  .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
  }

  .required {
    color: var(--danger);
  }

  .form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--bg-dark);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-light);
    font-size: 1rem;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--primary-color);
  }

  /* Responsive */
  @media (max-width: 992px) {
    .calendar-layout {
      grid-template-columns: 1fr;
    }

    .calendar-sidebar {
      order: -1;
    }
  }

  @media (max-width: 768px) {
    .calendar-page {
      padding: 1rem;
    }

    .page-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 1rem;
    }

    .calendar-day {
      padding: 0.25rem;
      font-size: 0.8rem;
    }

    .form-row {
      grid-template-columns: 1fr;
    }
  }
</style>

<script>
  function openScheduleModal() {
    document.getElementById('scheduleModal').classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
    document.body.style.overflow = '';
  }

  function updateJobId(select) {
    const option = select.options[select.selectedIndex];
    document.getElementById('job_id').value = option.dataset.job || '';
  }

  // Close modal on escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeScheduleModal();
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>