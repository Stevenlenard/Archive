<?php
require_once 'includes/config.php';

// Access: allow logged-in janitors/admins for POST AJAX actions; otherwise redirect
if (!isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    } else {
        header('Location: admin-login.php');
        exit;
    }
}

// Escape helper
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ensure the database has an is_archived column on notifications.
 * If $tryCreate is true this will attempt to add the column.
 * Returns true if the column exists (or was created), false otherwise.
 */
function ensure_archive_column_exists($tryCreate = false, $pdo = null, $conn = null): bool {
    try {
        if ($pdo && $pdo instanceof PDO) {
            $r = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
            if ($r && $r->rowCount() > 0) return true;
            if ($tryCreate) {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
                $r2 = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
                return ($r2 && $r2->rowCount() > 0);
            }
            return false;
        } elseif ($conn) {
            $r = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
            if ($r && $r->num_rows > 0) return true;
            if ($tryCreate) {
                $conn->query("ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
                $r2 = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_archived'");
                return ($r2 && $r2->num_rows > 0);
            }
            return false;
        }
        return false;
    } catch (Throwable $t) {
        error_log("[notifications] ensure_archive_column_exists error: " . $t->getMessage());
        return false;
    }
}

/**
 * Helper wrapper that prefers PDO ($pdo from includes/config.php) else uses $conn (mysqli).
 * Many of the original code paths already handle both; we reuse that approach below.
 */

// --------------------
// AJAX action handlers
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = $_POST['action'];

        // Mark all as read
        if ($action === 'mark_all_read') {
            if (isset($pdo) && $pdo instanceof PDO) {
                $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0")->execute();
            } else {
                $conn->query("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
            }
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            exit;
        }

        // Mark single notification read (requires notification_id)
        if ($action === 'mark_read') {
            if (empty($_POST['notification_id'])) throw new Exception('Missing notification_id');
            $id = (int)$_POST['notification_id'];
            if ($id <= 0) throw new Exception('Invalid notification id');

            if (isset($pdo) && $pdo instanceof PDO) {
                $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?")->execute([$id]);
            } else {
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Notification marked as read', 'notification_id' => $id]);
            exit;
        }

        // Bin cleaned: create admin-visible notification (not marked read)
        if ($action === 'bin_cleaned') {
            $bin_id = isset($_POST['bin_id']) && $_POST['bin_id'] !== '' ? intval($_POST['bin_id']) : null;
            if (!$bin_id) throw new Exception('Missing bin_id');

            $janitor_id = isset($_POST['janitor_id']) && $_POST['janitor_id'] !== '' ? intval($_POST['janitor_id']) : null;
            if (empty($janitor_id) && function_exists('isJanitor') && isJanitor()) {
                $janitor_id = function_exists('getCurrentUserId') ? (int)getCurrentUserId() : null;
            }

            // Look up bin code and janitor name for nicer message
            $bin_code = null;
            $janitor_name = null;
            if (isset($pdo) && $pdo instanceof PDO) {
                $st = $pdo->prepare("SELECT bin_code FROM bins WHERE bin_id = ? LIMIT 1");
                $st->execute([$bin_id]);
                $b = $st->fetch(PDO::FETCH_ASSOC);
                if ($b) $bin_code = $b['bin_code'] ?? null;

                if ($janitor_id) {
                    $stj = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, email FROM janitors WHERE janitor_id = ? LIMIT 1");
                    $stj->execute([$janitor_id]);
                    $jr = $stj->fetch(PDO::FETCH_ASSOC);
                    if ($jr) $janitor_name = trim($jr['name'] ?: ($jr['email'] ?? 'Janitor #' . $janitor_id));
                }
            } else {
                $res = $conn->query("SELECT bin_code FROM bins WHERE bin_id = " . intval($bin_id) . " LIMIT 1");
                if ($res && $r = $res->fetch_assoc()) $bin_code = $r['bin_code'] ?? null;
                if ($janitor_id) {
                    if ($stj = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, email FROM janitors WHERE janitor_id = ? LIMIT 1")) {
                        $stj->bind_param("i", $janitor_id);
                        $stj->execute();
                        $jr = $stj->get_result()->fetch_assoc();
                        if ($jr) $janitor_name = trim($jr['name'] ?: ($jr['email'] ?? 'Janitor #' . $janitor_id));
                        $stj->close();
                    }
                }
            }

            $binDisplay = $bin_code ? "Bin {$bin_code}" : "Bin #{$bin_id}";
            $janitorDisplay = $janitor_name ? $janitor_name : ($janitor_id ? "Janitor #{$janitor_id}" : 'A janitor');

            $title = "Bin cleaned: {$binDisplay}";
            $message = "{$janitorDisplay} cleaned {$binDisplay}.";

            if (isset($pdo) && $pdo instanceof PDO) {
                $ins = $pdo->prepare("
                    INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, is_read, created_at)
                    VALUES (NULL, :janitor_id, :bin_id, 'success', :title, :message, 0, NOW())
                ");
                $ins->execute([
                    ':janitor_id' => $janitor_id,
                    ':bin_id' => $bin_id,
                    ':title' => $title,
                    ':message' => $message
                ]);
                $newId = (int)$pdo->lastInsertId();
            } else {
                $titleEsc = $conn->real_escape_string($title);
                $msgEsc = $conn->real_escape_string($message);
                $binVal = intval($bin_id);
                $janVal = $janitor_id ? intval($janitor_id) : 'NULL';
                $sql = "INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, is_read, created_at)
                        VALUES (NULL, " . ($janVal === 'NULL' ? 'NULL' : $janVal) . ", {$binVal}, 'success', '{$titleEsc}', '{$msgEsc}', 0, NOW())";
                $conn->query($sql);
                $newId = $conn->insert_id ?? null;
            }

            echo json_encode(['success' => true, 'message' => 'Bin cleaned notification created', 'notification_id' => $newId]);
            exit;
        }

        // Clear all notifications (delete)
        if ($action === 'clear_all') {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("DELETE FROM notifications");
                $stmt->execute();
            } else {
                $conn->query("DELETE FROM notifications");
            }
            echo json_encode(['success' => true, 'message' => 'All notifications cleared']);
            exit;
        }

        // Archive all read notifications
        if ($action === 'archive_all') {
            // Try to ensure archive column exists; if not possible, instruct user.
            $ok = ensure_archive_column_exists(true, isset($pdo)?$pdo:null, isset($conn)?$conn:null);
            if (!$ok) {
                throw new Exception('Archive column missing and could not be created. Please run: ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read');
            }
            if (isset($pdo) && $pdo instanceof PDO) {
                $pdo->prepare("UPDATE notifications SET is_archived = 1 WHERE is_read = 1")->execute();
            } else {
                $conn->query("UPDATE notifications SET is_archived = 1 WHERE is_read = 1");
            }
            echo json_encode(['success' => true, 'message' => 'All read notifications archived']);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// AJAX GET endpoints for fetching active or archived lists
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    try {
        $out = [];
        $hasNotificationsTable = false;
        if (isset($pdo) && $pdo instanceof PDO) {
            $r = $pdo->query("SHOW TABLES LIKE 'notifications'");
            $hasNotificationsTable = ($r && $r->rowCount() > 0);
        } else {
            $r = $conn->query("SHOW TABLES LIKE 'notifications'");
            $hasNotificationsTable = ($r && $r->num_rows > 0);
        }

        if ($hasNotificationsTable) {
            $supportsArchive = ensure_archive_column_exists(false, isset($pdo)?$pdo:null, isset($conn)?$conn:null);
            $wantArchived = ($action === 'fetch_archived');
            $archiveCondition = $supportsArchive ? ($wantArchived ? "WHERE is_archived = 1" : "WHERE (is_archived IS NULL OR is_archived = 0)") : "";

            $limit = 200;
            if (isset($pdo) && $pdo instanceof PDO) {
                $sql = "
                    SELECT n.notification_id, n.admin_id, n.janitor_id, n.bin_id, n.notification_type,
                           n.title, n.message, n.is_read, n.created_at,
                           b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
                    FROM notifications n
                    LEFT JOIN bins b ON n.bin_id = b.bin_id
                    LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
                    {$archiveCondition}
                    ORDER BY n.created_at DESC
                    LIMIT {$limit}
                ";
                $stmt = $pdo->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } else {
                $sql = "
                    SELECT n.notification_id, n.admin_id, n.janitor_id, n.bin_id, n.notification_type,
                           n.title, n.message, n.is_read, n.created_at,
                           b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
                    FROM notifications n
                    LEFT JOIN bins b ON n.bin_id = b.bin_id
                    LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
                    {$archiveCondition}
                    ORDER BY n.created_at DESC
                    LIMIT {$limit}
                ";
                $rows = [];
                $res = $conn->query($sql);
                if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
            }

            foreach ($rows as $r) {
                $out[] = [
                    'notification_id' => isset($r['notification_id']) ? (int)$r['notification_id'] : null,
                    'title' => $r['title'] ?? '',
                    'message' => $r['message'] ?? '',
                    'is_read' => isset($r['is_read']) ? (int)$r['is_read'] : 0,
                    'created_at' => $r['created_at'] ?? null,
                    'bin_id' => isset($r['bin_id']) ? (int)$r['bin_id'] : null,
                    'bin_code' => $r['bin_code'] ?? null,
                    'janitor_id' => isset($r['janitor_id']) ? (int)$r['janitor_id'] : null,
                    'janitor_name' => $r['janitor_name'] ?? null,
                    'notification_type' => $r['notification_type'] ?? 'info'
                ];
            }
        }

        echo json_encode(['success' => true, 'notifications' => $out]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -----------------------------
// Server-side render (page)
// -----------------------------
$notifications = [];
$archived_notifications = [];

try {
    $supportsArchive = ensure_archive_column_exists(false, isset($pdo)?$pdo:null, isset($conn)?$conn:null);

    // Active
    $whereActive = $supportsArchive ? "WHERE (is_archived IS NULL OR is_archived = 0)" : "";
    $limit = 1000;
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query("
            SELECT n.*, b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
            FROM notifications n
            LEFT JOIN bins b ON n.bin_id = b.bin_id
            LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
            {$whereActive}
            ORDER BY n.created_at DESC
            LIMIT {$limit}
        ");
        $notifications = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $res = $conn->query("
            SELECT n.*, b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
            FROM notifications n
            LEFT JOIN bins b ON n.bin_id = b.bin_id
            LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
            {$whereActive}
            ORDER BY n.created_at DESC
            LIMIT {$limit}
        ");
        if ($res) while ($r = $res->fetch_assoc()) $notifications[] = $r;
    }

    // Archived (if supported)
    if ($supportsArchive) {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->query("
                SELECT n.*, b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
                FROM notifications n
                LEFT JOIN bins b ON n.bin_id = b.bin_id
                LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
                WHERE is_archived = 1
                ORDER BY n.created_at DESC
                LIMIT {$limit}
            ");
            $archived_notifications = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $res = $conn->query("
                SELECT n.*, b.bin_code, CONCAT(j.first_name,' ',j.last_name) AS janitor_name
                FROM notifications n
                LEFT JOIN bins b ON n.bin_id = b.bin_id
                LEFT JOIN janitors j ON n.janitor_id = j.janitor_id
                WHERE is_archived = 1
                ORDER BY n.created_at DESC
                LIMIT {$limit}
            ");
            if ($res) while ($r = $res->fetch_assoc()) $archived_notifications[] = $r;
        }
    }
} catch (Exception $e) {
    error_log('[notifications] render error: ' . $e->getMessage());
    $notifications = [];
    $archived_notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Trashbin Admin</title>
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/janitor-dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    #notifToastContainer { position: fixed; top: 1rem; right: 1rem; z-index: 2000; }
    .table-light td { opacity: 0.9; }
    .archived-card { margin-top: 1.5rem; opacity: 0.95; }
    /* Make the Archived header green and match the "Recent Reports" style */
    .archived-card .card-header h5 { color: #198754 !important; }
    .archived-card .card-header { cursor: pointer; user-select: none; }
    .archived-collapsed .card-body { display: none; }
    .archived-toggle-hint { font-size: 0.9rem; color: #6c757d; margin-left: 8px; }
  </style>
</head>
<body>
<?php include_once __DIR__ . '/includes/header-admin.php'; ?>

<div class="dashboard">
  <div class="background-circle background-circle-1"></div>
  <div class="background-circle background-circle-2"></div>
  <div class="background-circle background-circle-3"></div>

  <!-- Restored full sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header d-none d-md-block"><h6 class="sidebar-title">Menu</h6></div>
    <a href="admin-dashboard.php" class="sidebar-item"><i class="fa-solid fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="bins.php" class="sidebar-item"><i class="fa-solid fa-trash-alt"></i><span>Bins</span></a>
    <a href="janitors.php" class="sidebar-item"><i class="fa-solid fa-users"></i><span>Maintenance Staff</span></a>
    <a href="reports.php" class="sidebar-item"><i class="fa-solid fa-chart-line"></i><span>Reports</span></a>
    <a href="notifications.php" class="sidebar-item active"><i class="fa-solid fa-bell"></i><span>Notifications</span></a>
    <a href="profile.php" class="sidebar-item"><i class="fa-solid fa-user"></i><span>My Profile</span></a>
  </aside>

  <main class="content">
    <div class="section-header d-flex justify-content-between align-items-start">
      <div>
        <h1 class="page-title">Notifications & Logs</h1>
        <p class="page-subtitle">System notifications and activity logs</p>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <button class="btn btn-sm btn-outline-secondary" id="markAllReadBtn"><i class="fas fa-check-double me-1"></i>Mark All as Read</button>
        <button class="btn btn-sm btn-outline-danger" id="clearNotificationsBtn"><i class="fas fa-trash-alt me-1"></i>Clear All</button>
        <button class="btn btn-sm btn-outline-secondary" id="archiveReadBtn"><i class="fas fa-archive me-1"></i>Archive all</button>
      </div>
    </div>

    <div id="notifToastContainer" aria-live="polite" aria-atomic="true"></div>

    <div class="card mt-3">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Type</th>
                <th>Title</th>
                <th class="d-none d-md-table-cell">Message</th>
                <th class="d-none d-lg-table-cell">Target</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody id="notificationsTableBody">
              <?php if (empty($notifications)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No notifications found</td></tr>
              <?php else: foreach ($notifications as $n):
                $time = $n['created_at'] ?? null;
                $timeDisplay = $time ? e(date('Y-m-d H:i', strtotime($time))) : '-';
                $type = e($n['notification_type'] ?? 'info');
                $title = e($n['title'] ?? '');
                $message = e($n['message'] ?? '');
                $target = '-';
                if (!empty($n['bin_id'])) $target = e($n['bin_code'] ?? ("Bin #{$n['bin_id']}"));
                elseif (!empty($n['janitor_id'])) $target = e($n['janitor_name'] ?? ("Janitor #{$n['janitor_id']}"));
                $nid = !empty($n['notification_id']) ? (int)$n['notification_id'] : null;
                $isRead = (int)($n['is_read'] ?? 0) === 1;
              ?>
              <tr class="<?php echo $isRead ? 'table-light' : ''; ?>" data-id="<?php echo $nid ?? ''; ?>">
                <td><?php echo $timeDisplay; ?></td>
                <td><?php echo ucfirst($type); ?></td>
                <td><?php echo $title; ?></td>
                <td class="d-none d-md-table-cell"><small class="text-muted"><?php echo $message; ?></small></td>
                <td class="d-none d-lg-table-cell"><?php echo $target; ?></td>
                <td class="text-end">
                  <?php if ($nid !== null && !$isRead): ?>
                    <button class="btn btn-sm btn-success mark-read-btn" data-id="<?php echo $nid; ?>"><i class="fas fa-check me-1"></i>Read</button>
                  <?php else: ?>
                    <span class="text-muted small">Read</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Archived (collapsed by default) -->
    <div class="card archived-card archived-collapsed" id="archivedCard">
      <div class="card-header" id="archivedCardHeader" role="button" aria-expanded="false" tabindex="0">
        <h5 class="mb-0 text-success">
          <i class="fa-solid fa-box-archive me-2"></i>Archived Notifications
          <span class="archived-toggle-hint" id="archHint"> (click to expand)</span>
        </h5>
      </div>

      <div class="card-body p-0" id="archivedCardBody" aria-hidden="true">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>Time</th>
                <th>Type</th>
                <th>Title</th>
                <th class="d-none d-md-table-cell">Message</th>
                <th class="d-none d-lg-table-cell">Target</th>
                <th class="text-end">Status</th>
              </tr>
            </thead>
            <tbody id="archivedNotificationsTableBody">
              <?php if (empty($archived_notifications)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No archived notifications</td></tr>
              <?php else: foreach ($archived_notifications as $n):
                $time = $n['created_at'] ?? null;
                $timeDisplay = $time ? e(date('Y-m-d H:i', strtotime($time))) : '-';
                $type = e($n['notification_type'] ?? 'info');
                $title = e($n['title'] ?? '');
                $message = e($n['message'] ?? '');
                $target = '-';
                if (!empty($n['bin_id'])) $target = e($n['bin_code'] ?? ("Bin #{$n['bin_id']}"));
                elseif (!empty($n['janitor_id'])) $target = e($n['janitor_name'] ?? ("Janitor #{$n['janitor_id']}"));
                $isRead = (int)($n['is_read'] ?? 0) === 1;
              ?>
              <tr class="table-light" data-id="<?php echo (int)$n['notification_id']; ?>">
                <td><?php echo $timeDisplay; ?></td>
                <td><?php echo ucfirst($type); ?></td>
                <td><?php echo $title; ?></td>
                <td class="d-none d-md-table-cell"><small class="text-muted"><?php echo $message; ?></small></td>
                <td class="d-none d-lg-table-cell"><?php echo $target; ?></td>
                <td class="text-end"><span class="text-muted small"><?php echo $isRead ? 'Read' : 'Unread'; ?></span></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </main>
</div>

<?php include_once __DIR__ . '/includes/footer-admin.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  function showToast(msg, type = 'info') {
    const id = 'toast-' + Math.random().toString(36).slice(2, 9);
    const bg = { info: 'bg-info text-white', success: 'bg-success text-white', danger: 'bg-danger text-white', warning: 'bg-warning text-dark' }[type] || 'bg-secondary text-white';
    const html = `<div id="${id}" class="toast ${bg}" role="status" data-bs-delay="3000"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
    $('#notifToastContainer').append(html);
    const el = document.getElementById(id);
    new bootstrap.Toast(el).show();
    el.addEventListener('hidden.bs.toast', function(){ el.remove(); });
  }

  // Mark single notification (requires data-id)
  $(document).on('click', '.mark-read-btn', function () {
    const $btn = $(this);
    const id = $btn.data('id') || '';
    if (!id) {
      showToast('Notification id missing', 'warning');
      return;
    }
    $btn.prop('disabled', true).text('Marking...');
    $.post('notifications.php', { action: 'mark_read', notification_id: id }, function(resp) {
      if (resp && resp.success) {
        const $row = $btn.closest('tr');
        $row.addClass('table-light');
        $row.find('.mark-read-btn').remove();
        // update action cell to "Read"
        $row.find('td.text-end').html('<span class="text-muted small">Read</span>');
        showToast(resp.message || 'Marked as read', 'success');
      } else {
        showToast((resp && resp.message) ? resp.message : 'Failed to mark', 'danger');
        $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Read');
      }
    }, 'json').fail(function(xhr) {
      const msg = xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Server error';
      showToast(msg, 'danger');
      $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Read');
    });
  });

  // Mark all as read
  $('#markAllReadBtn').on('click', function() {
    if (!confirm('Mark all as read?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Marking all...');
    $.post('notifications.php', { action: 'mark_all_read' }, function(r) {
      if (r && r.success) {
        $('#notificationsTableBody tr').each(function() {
          $(this).addClass('table-light');
          $(this).find('.mark-read-btn').remove();
          $(this).find('td.text-end').html('<span class="text-muted small">Read</span>');
        });
        showToast('All notifications marked as read', 'success');
      } else {
        showToast((r && r.message) ? r.message : 'Failed', 'danger');
      }
    }, 'json').always(function(){ $btn.prop('disabled', false).html('<i class="fas fa-check-double me-1"></i>Mark All as Read'); });
  });

  // Clear all
  $('#clearNotificationsBtn').on('click', function() {
    if (!confirm('Clear all notifications? This will delete them permanently.')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Clearing...');
    $.post('notifications.php', { action: 'clear_all' }, function(r) {
      if (r && r.success) {
        $('#notificationsTableBody').html('<tr><td colspan="6" class="text-center py-4 text-muted">No notifications found</td></tr>');
        $('#archivedNotificationsTableBody').html('<tr><td colspan="6" class="text-center py-4 text-muted">No archived notifications</td></tr>');
        showToast('Notifications cleared', 'success');
      } else {
        showToast((r && r.message) ? r.message : 'Failed', 'danger');
      }
    }, 'json').always(function(){ $btn.prop('disabled', false).html('<i class="fas fa-trash-alt me-1"></i>Clear All'); });
  });

  // Archive all read notifications
  $('#archiveReadBtn').on('click', function() {
    if (!confirm('Archive all notifications that have been marked read?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Archiving...');
    $.post('notifications.php', { action: 'archive_all' }, function(r) {
      if (r && r.success) {
        // Move read rows to archived table (UI only)
        $('#notificationsTableBody tr.table-light').each(function() {
          const $tr = $(this);
          const cells = $tr.find('td').toArray().slice(0,5).map(td => $(td).html());
          const archHtml = '<tr class="table-light" data-id="'+($tr.data('id')||'')+'">'
            + '<td>' + (cells[0]||'-') + '</td>'
            + '<td>' + (cells[1]||'-') + '</td>'
            + '<td>' + (cells[2]||'-') + '</td>'
            + '<td class="d-none d-md-table-cell"><small class="text-muted">' + (cells[3]||'') + '</small></td>'
            + '<td class="d-none d-lg-table-cell">' + (cells[4]||'-') + '</td>'
            + '<td class="text-end"><span class="text-muted small">Read</span></td>'
            + '</tr>';
          if ($('#archivedNotificationsTableBody tr').length === 1 && $('#archivedNotificationsTableBody tr td').first().hasClass('text-muted')) {
            $('#archivedNotificationsTableBody').html(archHtml);
          } else {
            $('#archivedNotificationsTableBody').prepend(archHtml);
          }
          $tr.remove();
        });
        if ($('#notificationsTableBody tr').length === 0) {
          $('#notificationsTableBody').html('<tr><td colspan="6" class="text-center py-4 text-muted">No notifications found</td></tr>');
        }
        showToast(r.message || 'Archived read notifications', 'success');
      } else {
        showToast((r && r.message) ? r.message : 'Failed to archive', 'danger');
      }
    }, 'json').always(function(){ $btn.prop('disabled', false).html('<i class="fas fa-archive me-1"></i>Archive all'); });
  });

  // Polling: refresh active and archived lists
  let archivedVisible = false;

  function refreshActive() {
    $.getJSON('notifications.php', { action: 'fetch' })
      .done(function(resp) {
        if (!resp || !resp.success || !Array.isArray(resp.notifications)) return;
        const $tbody = $('#notificationsTableBody');
        if (resp.notifications.length === 0) {
          $tbody.html('<tr><td colspan="6" class="text-center py-4 text-muted">No notifications found</td></tr>');
          return;
        }
        const rows = resp.notifications.map(function(n){
          const isRead = parseInt(n.is_read || 0, 10) === 1;
          const time = n.created_at ? new Date(n.created_at).toLocaleString() : '-';
          let target = '-';
          if (n.bin_id) target = n.bin_code ? n.bin_code : 'Bin #' + n.bin_id;
          else if (n.janitor_id) target = n.janitor_name ? n.janitor_name : 'Janitor #' + n.janitor_id;
          else if (n.admin_id) target = 'Admin #' + (n.admin_id || '');
          const actions = !isRead ? '<button class="btn btn-sm btn-success mark-read-btn" data-id="'+n.notification_id+'"><i class="fas fa-check me-1"></i>Read</button>' : '<span class="text-muted small">Read</span>';
          return '<tr class="'+(isRead ? 'table-light' : '')+'" data-id="'+n.notification_id+'">'
            + '<td>' + time + '</td>'
            + '<td>' + (n.notification_type ? (n.notification_type.charAt(0).toUpperCase() + n.notification_type.slice(1)) : 'Info') + '</td>'
            + '<td>' + $('<div/>').text(n.title || '').html() + '</td>'
            + '<td class="d-none d-md-table-cell"><small class="text-muted">' + $('<div/>').text(n.message || '').html() + '</small></td>'
            + '<td class="d-none d-lg-table-cell">' + $('<div/>').text(target).html() + '</td>'
            + '<td class="text-end">' + actions + '</td>'
            + '</tr>';
        }).join('');
        $tbody.html(rows);
      });
  }

  function refreshArchived() {
    if (!archivedVisible) return; // only fetch archived when visible
    $.getJSON('notifications.php', { action: 'fetch_archived' })
      .done(function(resp) {
        if (!resp || !resp.success || !Array.isArray(resp.notifications)) return;
        const $tbody = $('#archivedNotificationsTableBody');
        if (resp.notifications.length === 0) {
          $tbody.html('<tr><td colspan="6" class="text-center py-4 text-muted">No archived notifications</td></tr>');
          return;
        }
        const rows = resp.notifications.map(function(n){
          const time = n.created_at ? new Date(n.created_at).toLocaleString() : '-';
          let target = '-';
          if (n.bin_id) target = n.bin_code ? n.bin_code : 'Bin #' + n.bin_id;
          else if (n.janitor_id) target = n.janitor_name ? n.janitor_name : 'Janitor #' + n.janitor_id;
          else if (n.admin_id) target = 'Admin #' + (n.admin_id || '');
          const isRead = parseInt(n.is_read || 0, 10) === 1;
          return '<tr class="table-light" data-id="'+n.notification_id+'">'
            + '<td>' + time + '</td>'
            + '<td>' + (n.notification_type ? (n.notification_type.charAt(0).toUpperCase() + n.notification_type.slice(1)) : 'Info') + '</td>'
            + '<td>' + $('<div/>').text(n.title || '').html() + '</td>'
            + '<td class="d-none d-md-table-cell"><small class="text-muted">' + $('<div/>').text(n.message || '').html() + '</small></td>'
            + '<td class="d-none d-lg-table-cell">' + $('<div/>').text(target).html() + '</td>'
            + '<td class="text-end"><span class="text-muted small">' + (isRead ? 'Read' : 'Unread') + '</span></td>'
            + '</tr>';
        }).join('');
        $tbody.html(rows);
      });
  }

  // initial load + polling
  refreshActive();
  // archived is hidden by default, only refresh when user expands
  setInterval(function(){ refreshActive(); refreshArchived(); }, 5000);

  // Toggle archived card visibility (collapsed by default)
  const $archHeader = $('#archivedCardHeader');
  $archHeader.on('click keypress', function(e) {
    if (e.type === 'keypress' && e.key !== 'Enter' && e.key !== ' ') return;
    const $archCard = $('#archivedCard');
    const $body = $('#archivedCardBody');
    const expanded = $archCard.hasClass('archived-collapsed') === false;
    if (expanded) {
      // collapse
      $archCard.addClass('archived-collapsed');
      $body.attr('aria-hidden', 'true');
      $('#archHint').text(' (click to expand)');
      archivedVisible = false;
    } else {
      // expand
      $archCard.removeClass('archived-collapsed');
      $body.attr('aria-hidden', 'false');
      $('#archHint').text(' (click to collapse)');
      archivedVisible = true;
      // refresh archived immediately when opening
      refreshArchived();
    }
  });

})();
</script>
</body>
</html>