<?php
require_once 'includes/config.php';

// Add near top of file (once): include the helper
// include_once __DIR__ . '/includes/janitor-alerts-functions.php';

// Basic auth + janitor check
if (!isLoggedIn()) {
    header('Location: user-login.php');
    exit;
}
if (!isJanitor()) {
    header('Location: admin-dashboard.php');
    exit;
}

$janitorId = intval($_SESSION['janitor_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
$janitorDisplayName = $_SESSION['name'] ?? ($_SESSION['first_name'] ?? 'Janitor');

/**
 * Helper: check if column/table exists (PDO or mysqli)
 */
function column_exists_in_table($table, $column) {
    global $pdo, $conn;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $r = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
            return ($r && $r->rowCount() > 0);
        } else {
            $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
            return ($r && $r->num_rows > 0);
        }
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ensure janitor_alerts table exists.
 */
function ensure_janitor_alerts_table() {
    global $pdo, $conn;
    $exists = false;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $r = $pdo->query("SHOW TABLES LIKE 'janitor_alerts'");
            $exists = ($r && $r->rowCount() > 0);
        } else {
            $r = $conn->query("SHOW TABLES LIKE 'janitor_alerts'");
            $exists = ($r && $r->num_rows > 0);
        }
    } catch (Exception $e) {
        $exists = false;
    }

    if ($exists) return;

    // Create table
    $sql = "
      CREATE TABLE IF NOT EXISTS janitor_alerts (
        alert_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NULL,
        janitor_id INT NULL,
        bin_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (janitor_id),
        INDEX (bin_id),
        INDEX (notification_id),
        INDEX (is_archived)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec($sql);
    } else {
        $conn->query($sql);
    }
}

/**
 * Ensure janitor_alerts has is_archived column (try create if requested)
 */
function ensure_janitor_alerts_archive_column_exists($attemptCreate = false) {
    global $pdo, $conn;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $r = $pdo->query("SHOW COLUMNS FROM janitor_alerts LIKE 'is_archived'");
            if ($r && $r->rowCount() > 0) return true;
            if ($attemptCreate) {
                $pdo->exec("ALTER TABLE janitor_alerts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
                $r2 = $pdo->query("SHOW COLUMNS FROM janitor_alerts LIKE 'is_archived'");
                return ($r2 && $r2->rowCount() > 0);
            }
            return false;
        } else {
            $r = $conn->query("SHOW COLUMNS FROM janitor_alerts LIKE 'is_archived'");
            if ($r && $r->num_rows > 0) return true;
            if ($attemptCreate) {
                $conn->query("ALTER TABLE janitor_alerts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
                $r2 = $conn->query("SHOW COLUMNS FROM janitor_alerts LIKE 'is_archived'");
                return ($r2 && $r2->num_rows > 0);
            }
            return false;
        }
    } catch (Exception $e) {
        error_log("[janitor_alerts] archive column check failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Insert a janitor_alert record if not already present for same notification_id or same bin fingerprint.
 */
function insert_janitor_alert($params) {
    global $pdo, $conn;

    $notification_id = isset($params['notification_id']) ? $params['notification_id'] : null;
    $janitor_id = isset($params['janitor_id']) ? $params['janitor_id'] : null;
    $bin_id = isset($params['bin_id']) ? $params['bin_id'] : null;
    $title = isset($params['title']) ? $params['title'] : '';
    $message = isset($params['message']) ? $params['message'] : '';
    $is_read = isset($params['is_read']) ? (int)$params['is_read'] : 0;

    try {
        // Duplicate prevention
        if ($notification_id) {
            if (isset($pdo) && $pdo instanceof PDO) {
                $p = $pdo->prepare("SELECT alert_id FROM janitor_alerts WHERE notification_id = :nid AND janitor_id = :jid LIMIT 1");
                $p->execute([':nid' => $notification_id, ':jid' => $janitor_id]);
                if ($p->fetch()) return null;
            } else {
                $s = $conn->prepare("SELECT alert_id FROM janitor_alerts WHERE notification_id = ? AND janitor_id = ? LIMIT 1");
                $s->bind_param("ii", $notification_id, $janitor_id);
                $s->execute();
                $res = $s->get_result();
                if ($res && $res->fetch_assoc()) { $s->close(); return null; }
                $s->close();
            }
        } elseif ($bin_id) {
            $since = date('Y-m-d H:i:s', time() - 24*3600);
            if (isset($pdo) && $pdo instanceof PDO) {
                $p = $pdo->prepare("SELECT alert_id FROM janitor_alerts WHERE janitor_id = :jid AND bin_id = :bid AND title = :title AND created_at >= :since LIMIT 1");
                $p->execute([':jid' => $janitor_id, ':bid' => $bin_id, ':title' => $title, ':since' => $since]);
                if ($p->fetch()) return null;
            } else {
                $s = $conn->prepare("SELECT alert_id FROM janitor_alerts WHERE janitor_id = ? AND bin_id = ? AND title = ? AND created_at >= ? LIMIT 1");
                $s->bind_param("iiss", $janitor_id, $bin_id, $title, $since);
                $s->execute();
                $res = $s->get_result();
                if ($res && $res->fetch_assoc()) { $s->close(); return null; }
                $s->close();
            }
        }

        if (isset($pdo) && $pdo instanceof PDO) {
            $ins = $pdo->prepare("INSERT INTO janitor_alerts (notification_id, janitor_id, bin_id, title, message, is_read, is_archived, created_at) VALUES (:nid, :jid, :bid, :title, :msg, :isread, 0, NOW())");
            $ins->execute([
                ':nid' => $notification_id,
                ':jid' => $janitor_id,
                ':bid' => $bin_id,
                ':title' => $title,
                ':msg' => $message,
                ':isread' => $is_read
            ]);
            return (int)$pdo->lastInsertId();
        } else {
            $nidVal = $notification_id !== null ? intval($notification_id) : 'NULL';
            $jidVal = $janitor_id !== null ? intval($janitor_id) : 'NULL';
            $bidVal = $bin_id !== null ? intval($bin_id) : 'NULL';
            $titleEsc = $conn->real_escape_string($title);
            $msgEsc = $conn->real_escape_string($message);
            $isReadVal = intval($is_read);
            $sql = "INSERT INTO janitor_alerts (notification_id, janitor_id, bin_id, title, message, is_read, is_archived, created_at) VALUES ({$nidVal}, {$jidVal}, {$bidVal}, '{$titleEsc}', '{$msgEsc}', {$isReadVal}, 0, NOW())";
            $conn->query($sql);
            return $conn->insert_id ?? null;
        }
    } catch (Exception $e) {
        error_log("[janitor_alerts] insert error: " . $e->getMessage());
        return null;
    }
}

/**
 * Detect bins considered "full" for this janitor and insert janitor_alerts directly.
 */
function detect_and_create_bin_full_alerts_for_janitor($janitorId) {
    global $pdo, $conn;
    $candidates = [];

    try {
        if (column_exists_in_table('bins', 'is_full')) {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("SELECT bin_id, bin_code FROM bins WHERE is_full = 1 AND assigned_to = :jid");
                $stmt->execute([':jid' => $janitorId]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT bin_id, bin_code FROM bins WHERE is_full = 1 AND assigned_to = ?");
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $candidates[] = $r;
                $stmt->close();
            }
        } elseif (column_exists_in_table('bins', 'status')) {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("SELECT bin_id, bin_code FROM bins WHERE LOWER(status) IN ('full','overflow') AND assigned_to = :jid");
                $stmt->execute([':jid' => $janitorId]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $conn->prepare("SELECT bin_id, bin_code FROM bins WHERE (LOWER(status) = 'full' OR LOWER(status) = 'overflow') AND assigned_to = ?");
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $candidates[] = $r;
                $stmt->close();
            }
        } elseif (column_exists_in_table('bins', 'fill_level')) {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("SELECT bin_id, bin_code FROM bins WHERE fill_level >= 90 AND assigned_to = :jid");
                $stmt->execute([':jid' => $janitorId]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $threshold = 90;
                $stmt = $conn->prepare("SELECT bin_id, bin_code FROM bins WHERE fill_level >= ? AND assigned_to = ?");
                $stmt->bind_param("ii", $threshold, $janitorId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $candidates[] = $r;
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        // ignore errors
    }

    foreach ($candidates as $b) {
        $bin_id = intval($b['bin_id']);
        $bin_code = $b['bin_code'] ?? null;
        $binDisplay = $bin_code ? "Bin {$bin_code}" : "Bin #{$bin_id}";
        $title = "Bin full: {$binDisplay}";
        $message = "Assigned janitor needs to empty {$binDisplay}.";

        insert_janitor_alert([
            'notification_id' => null,
            'janitor_id' => $janitorId,
            'bin_id' => $bin_id,
            'title' => $title,
            'message' => $message,
            'is_read' => 0
        ]);
    }
}

/**
 * Import admin messages (from likely admin message tables) into janitor_alerts.
 */
function import_admin_messages_to_janitor_alerts($janitorId) {
    global $pdo, $conn;
    $candidateTables = ['admin_notifications', 'admin_messages', 'messages', 'contact_messages', 'contact', 'inquiries'];
    foreach ($candidateTables as $tbl) {
        try {
            $exists = false;
            if (isset($pdo) && $pdo instanceof PDO) {
                $r = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl));
                $exists = ($r && $r->rowCount() > 0);
            } else {
                $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tbl) . "'");
                $exists = ($r && $r->num_rows > 0);
            }
            if (!$exists) continue;

            $rows = [];
            if (isset($pdo) && $pdo instanceof PDO) {
                $cols = ['id','admin_id','janitor_id','target_janitor_id','title','subject','message','body','created_at','bin_id'];
                $selectCols = [];
                foreach ($cols as $c) {
                    if (column_exists_in_table($tbl, $c)) $selectCols[] = $c;
                }
                if (empty($selectCols)) continue;
                $select = implode(',', array_map(function($c){ return "COALESCE({$c}, '') AS {$c}"; }, $selectCols));
                $sql = "SELECT {$select} FROM {$tbl} ORDER BY created_at DESC LIMIT 200";
                $stmt = $pdo->query($sql);
                if ($stmt) $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $sql = "SELECT * FROM {$tbl} ORDER BY created_at DESC LIMIT 200";
                $res = $conn->query($sql);
                if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
            }

            foreach ($rows as $r) {
                $targeted = false;
                if (!empty($r['janitor_id']) && intval($r['janitor_id']) === $janitorId) $targeted = true;
                if (!empty($r['target_janitor_id']) && intval($r['target_janitor_id']) === $janitorId) $targeted = true;

                $title = trim($r['title'] ?? ($r['subject'] ?? ''));
                $message = trim($r['message'] ?? ($r['body'] ?? ''));
                $isAttention = false;
                $check = strtolower($title . ' ' . $message);
                if (strpos($check, 'attention') !== false || strpos($check, 'janitor') !== false || strpos($check, 'urgent') !== false) {
                    $isAttention = true;
                }

                if ($targeted || $isAttention) {
                    $bin_id = isset($r['bin_id']) ? (int)$r['bin_id'] : null;
                    $alertTitle = $title ?: 'Admin message';
                    $alertMsg = $message ?: '';
                    insert_janitor_alert([
                        'notification_id' => null,
                        'janitor_id' => $janitorId,
                        'bin_id' => $bin_id,
                        'title' => $alertTitle,
                        'message' => $alertMsg,
                        'is_read' => 0
                    ]);
                }
            }

        } catch (Exception $e) {
            // ignore and continue
        }
    }
}

// Prepare DB (create table if necessary)
ensure_janitor_alerts_table();

// Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_alerts') {
    header('Content-Type: application/json; charset=utf-8');

    // Before returning alerts, detect bin fullness and import admin messages (into janitor_alerts)
    detect_and_create_bin_full_alerts_for_janitor($janitorId);
    import_admin_messages_to_janitor_alerts($janitorId);

    $alerts = [];
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE (is_archived IS NULL OR is_archived = 0) ORDER BY created_at DESC LIMIT 1000");
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE (is_archived IS NULL OR is_archived = 0) ORDER BY created_at DESC LIMIT 1000");
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $alerts[] = $r;
            $stmt->close();
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'alerts' => [], 'error' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => true, 'alerts' => $alerts]);
    exit;
}

// Fetch archived alerts (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_archived') {
    header('Content-Type: application/json; charset=utf-8');
    $alerts = [];
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE is_archived = 1 ORDER BY created_at DESC LIMIT 1000");
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE is_archived = 1 ORDER BY created_at DESC LIMIT 1000");
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $alerts[] = $r;
            $stmt->close();
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'alerts' => [], 'error' => $e->getMessage()]);
        exit;
    }
    echo json_encode(['success' => true, 'alerts' => $alerts]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    try {
        // Mark single alert read
        if ($action === 'mark_read' && isset($_POST['alert_id'])) {
            $aid = intval($_POST['alert_id']);
            if ($aid <= 0) throw new Exception('Invalid alert id');

            // fetch the alert row first to get context
            $alertRow = null;
            if (isset($pdo) && $pdo instanceof PDO) {
                $p = $pdo->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message FROM janitor_alerts WHERE alert_id = ? AND janitor_id = ? LIMIT 1");
                $p->execute([$aid, $janitorId]);
                $alertRow = $p->fetch(PDO::FETCH_ASSOC);
            } else {
                $p = $conn->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message FROM janitor_alerts WHERE alert_id = ? AND janitor_id = ? LIMIT 1");
                $p->bind_param("ii", $aid, $janitorId);
                $p->execute();
                $res = $p->get_result();
                if ($res) $alertRow = $res->fetch_assoc();
                $p->close();
            }

            if (!$alertRow) throw new Exception('Alert not found or not authorized');

            // mark janitor_alert as read
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("UPDATE janitor_alerts SET is_read = 1 WHERE alert_id = ? AND janitor_id = ?");
                $stmt->execute([$aid, $janitorId]);
            } else {
                $stmt = $conn->prepare("UPDATE janitor_alerts SET is_read = 1 WHERE alert_id = ? AND janitor_id = ?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("ii", $aid, $janitorId);
                $stmt->execute();
                $stmt->close();
            }

            // Create an admin-facing notification so admins see the acknowledgement in notifications.php
            $bin_id = !empty($alertRow['bin_id']) ? intval($alertRow['bin_id']) : null;
            $origTitle = trim($alertRow['title'] ?? '');
            $origMessage = trim($alertRow['message'] ?? '');
            $notifTitle = 'Acknowledged: ' . ($origTitle ?: 'Alert');
            $notifMessage = $janitorDisplayName . ' acknowledged: ' . ($origTitle ?: $origMessage ?: 'an alert') . ($bin_id ? " (Bin #{$bin_id})" : '') . '.';

            try {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $r = $pdo->query("SHOW TABLES LIKE 'notifications'");
                    if ($r && $r->rowCount() > 0) {
                        $ins = $pdo->prepare("
                            INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, is_read, created_at)
                            VALUES (:admin_id, :janitor_id, :bin_id, :type, :title, :message, 0, NOW())
                        ");
                        $ins->execute([
                            ':admin_id' => null,
                            ':janitor_id' => $janitorId,
                            ':bin_id' => $bin_id,
                            ':type' => 'info',
                            ':title' => $notifTitle,
                            ':message' => $notifMessage
                        ]);
                    }
                } else {
                    $check = $conn->query("SHOW TABLES LIKE 'notifications'");
                    if ($check && $check->num_rows > 0) {
                        $titleEsc = $conn->real_escape_string($notifTitle);
                        $msgEsc = $conn->real_escape_string($notifMessage);
                        $binVal = $bin_id !== null ? intval($bin_id) : 'NULL';
                        $sql = "INSERT INTO notifications (admin_id, janitor_id, bin_id, notification_type, title, message, is_read, created_at)
                                VALUES (NULL, " . intval($janitorId) . ", {$binVal}, 'info', '{$titleEsc}', '{$msgEsc}', 0, NOW())";
                        $conn->query($sql);
                    }
                }
            } catch (Exception $e) {
                error_log("[janitor_alerts] failed to create admin notification on acknowledge: " . $e->getMessage());
            }

            echo json_encode(['success' => true, 'alert_id' => $aid]);
            exit;
        }

        // Mark all alerts read for this janitor
        if ($action === 'mark_all_read') {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("UPDATE janitor_alerts SET is_read = 1 WHERE janitor_id = ?");
                $stmt->execute([$janitorId]);
            } else {
                $stmt = $conn->prepare("UPDATE janitor_alerts SET is_read = 1 WHERE janitor_id = ?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // Clear all alerts for this janitor (delete)
        if ($action === 'clear_all') {
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("DELETE FROM janitor_alerts WHERE janitor_id = ?");
                $stmt->execute([$janitorId]);
            } else {
                $stmt = $conn->prepare("DELETE FROM janitor_alerts WHERE janitor_id = ?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // Archive all read alerts (for this janitor)
        if ($action === 'archive_all') {
            // ensure column exists
            $ok = ensure_janitor_alerts_archive_column_exists(true);
            if (!$ok) {
                throw new Exception('Archive column missing and could not be created. Please run: ALTER TABLE janitor_alerts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read');
            }
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("UPDATE janitor_alerts SET is_archived = 1 WHERE janitor_id = ? AND is_read = 1");
                $stmt->execute([$janitorId]);
            } else {
                $stmt = $conn->prepare("UPDATE janitor_alerts SET is_archived = 1 WHERE janitor_id = ? AND is_read = 1");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("i", $janitorId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['success' => true, 'message' => 'All read alerts archived']);
            exit;
        }

        // (We intentionally do not expose per-alert deletion UI anymore; delete endpoint remains for admin/maintenance use)
        if ($action === 'delete_alert' && isset($_POST['alert_id'])) {
            $aid = intval($_POST['alert_id']);
            if ($aid <= 0) throw new Exception('Invalid alert id');
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare("DELETE FROM janitor_alerts WHERE alert_id = ? AND janitor_id = ?");
                $stmt->execute([$aid, $janitorId]);
            } else {
                $stmt = $conn->prepare("DELETE FROM janitor_alerts WHERE alert_id = ? AND janitor_id = ?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param("ii", $aid, $janitorId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['success' => true, 'alert_id' => $aid]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// If we reached here, render the janitor alerts UI (page)
$recent_alerts = [];
$archived_alerts = [];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE (is_archived IS NULL OR is_archived = 0) ORDER BY created_at DESC LIMIT 200");
        $stmt->execute();
        $recent_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $pdo->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE is_archived = 1 ORDER BY created_at DESC LIMIT 200");
        $stmt2->execute();
        $archived_alerts = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE (is_archived IS NULL OR is_archived = 0) ORDER BY created_at DESC LIMIT 200");
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $recent_alerts[] = $r;
        $stmt->close();

        $stmt2 = $conn->prepare("SELECT alert_id, notification_id, janitor_id, bin_id, title, message, is_read, created_at FROM janitor_alerts WHERE is_archived = 1 ORDER BY created_at DESC LIMIT 200");
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($r = $res2->fetch_assoc()) $archived_alerts[] = $r;
        $stmt2->close();
    }
} catch (Exception $e) {
    $recent_alerts = [];
    $archived_alerts = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Janitor Alerts</title>
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/janitor-dashboard.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>.me-2 { margin-right: .5rem; }
    .archived-card { margin-top: 1.5rem; opacity: 0.95; }
    .archived-card .card-header h5 { color: #198754 !important; }
    .archived-card .card-header { cursor: pointer; user-select: none; }
    .archived-collapsed .card-body { display: none; }
    .archived-toggle-hint { font-size: 0.9rem; color: #6c757d; margin-left: 8px; }
  </style>
</head>
<body>
<?php include_once __DIR__ . '/includes/header-admin.php'; ?>

<div class="dashboard">
  <!-- Animated Background Circles -->
  <div class="background-circle background-circle-1"></div>
  <div class="background-circle background-circle-2"></div>
  <div class="background-circle background-circle-3"></div>

  <!-- Sidebar (restore janitor menu) -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h6 class="sidebar-title">Menu</h6>
    </div>
    <a href="janitor-dashboard.php" class="sidebar-item">
      <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
    </a>
    <a href="janitor-assigned-bins.php" class="sidebar-item">
      <i class="fa-solid fa-trash-alt"></i><span>Assigned Bins</span>
    </a>
    <a href="janitor-alerts.php" class="sidebar-item active">
      <i class="fa-solid fa-bell"></i><span>Alerts</span>
    </a>
    <a href="janitor-profile.php" class="sidebar-item">
      <i class="fa-solid fa-user"></i><span>My Profile</span>
    </a>
  </aside>

  <!-- Main content -->
  <main class="content">
    <div class="container py-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h1 class="h4">Alerts</h1>
          <p class="text-muted mb-0">Notifications about bins assigned to you and messages from admins.</p>
        </div>
        <div>
          <button class="btn btn-sm btn-outline-secondary" id="markAllReadBtn"><i class="fas fa-check-double me-1"></i>Mark All Read</button>
          <button class="btn btn-sm btn-outline-danger ms-2" id="clearAlertsBtn"><i class="fas fa-trash-alt me-1"></i>Clear All</button>
          <button class="btn btn-sm btn-outline-secondary ms-2" id="archiveAlertsBtn"><i class="fas fa-archive me-1"></i>Archive All</button>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table mb-0">
              <thead>
                <tr><th>Time</th><th>Title</th><th class="d-none d-md-table-cell">Message</th><th class="d-none d-lg-table-cell">Target</th><th class="text-end">Action</th></tr>
              </thead>
              <tbody id="alertsBody">
                <?php if (empty($recent_alerts)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">No alerts found</td></tr>
                <?php else: foreach ($recent_alerts as $a):
                  $time = htmlspecialchars($a['created_at'] ?? 'N/A');
                  $title = htmlspecialchars($a['title'] ?? 'Notification', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                  $message = htmlspecialchars($a['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                  $target = !empty($a['bin_id']) ? 'Bin #' . intval($a['bin_id']) : '-';
                  $isRead = intval($a['is_read'] ?? 0) === 1;
                  $aid = intval($a['alert_id'] ?? 0);
                ?>
                <tr class="<?php echo $isRead ? 'table-light' : ''; ?>" data-id="<?php echo $aid; ?>">
                  <td><?php echo $time; ?></td>
                  <td><?php echo $title; ?></td>
                  <td class="d-none d-md-table-cell"><small class="text-muted"><?php echo $message; ?></small></td>
                  <td class="d-none d-lg-table-cell"><?php echo $target; ?></td>
                  <td class="text-end">
                    <?php if ($isRead): ?>
                      <span class="text-muted small">Acknowledged</span>
                    <?php else: ?>
                      <button class="btn btn-sm btn-success ack-btn me-2" data-id="<?php echo $aid; ?>">Acknowledge</button>
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
            <i class="fa-solid fa-box-archive me-2"></i>Archived Alerts
            <span class="archived-toggle-hint" id="archHint"> (click to expand)</span>
          </h5>
        </div>

        <div class="card-body p-0" id="archivedCardBody" aria-hidden="true">
          <div class="table-responsive">
            <table class="table mb-0">
              <thead>
                <tr><th>Time</th><th>Title</th><th class="d-none d-md-table-cell">Message</th><th class="d-none d-lg-table-cell">Target</th><th class="text-end">Status</th></tr>
              </thead>
              <tbody id="archivedAlertsBody">
                <?php if (empty($archived_alerts)): ?>
                  <tr><td colspan="5" class="text-center py-4 text-muted">No archived alerts</td></tr>
                <?php else: foreach ($archived_alerts as $n):
                  $time = $n['created_at'] ?? null;
                  $timeDisplay = $time ? htmlspecialchars(date('Y-m-d H:i', strtotime($time))) : '-';
                  $title = htmlspecialchars($n['title'] ?? '');
                  $message = htmlspecialchars($n['message'] ?? '');
                  $target = !empty($n['bin_id']) ? ('Bin #' . intval($n['bin_id'])) : '-';
                  $isRead = (int)($n['is_read'] ?? 0) === 1;
                ?>
                <tr class="table-light" data-id="<?php echo (int)$n['alert_id']; ?>">
                  <td><?php echo $timeDisplay; ?></td>
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

    </div>
  </main>
</div>

<?php include_once __DIR__ . '/includes/footer-admin.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
(function(){
  function showToast(msg, type='info') {
    // site toast UI exists in layout; fallback to alert for simplicity
    try {
      const id = 'toast-' + Math.random().toString(36).slice(2, 9);
      const container = document.getElementById('notifToastContainer');
      if (container) {
        const div = document.createElement('div');
        div.id = id; div.className = 'toast bg-secondary text-white'; div.innerHTML = '<div class="d-flex"><div class="toast-body">' + msg + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
        container.appendChild(div);
        new bootstrap.Toast(document.getElementById(id)).show();
        return;
      }
    } catch(e){}
    alert(msg);
  }

  // Acknowledge single alert
  $(document).on('click', '.ack-btn', function(e){
    e.preventDefault();
    const $btn = $(this);
    const alertId = parseInt($btn.data('id') || 0, 10);
    if (!alertId) return;
    $btn.prop('disabled', true).text('Acknowledging...');
    $.post(window.location.pathname, { action: 'mark_read', alert_id: alertId }, function(resp){
      if (resp && resp.success) {
        const $row = $btn.closest('tr');
        $row.addClass('table-light');
        $btn.remove();
        $row.find('td.text-end').html('<span class="text-muted small">Acknowledged</span>');
        showToast('Acknowledged', 'success');
      } else {
        showToast((resp && resp.message) ? resp.message : 'Failed', 'danger');
        $btn.prop('disabled', false).text('Acknowledge');
      }
    }, 'json').fail(function(){ showToast('Server error', 'danger'); $btn.prop('disabled', false).text('Acknowledge'); });
  });

  // Mark all as read
  $('#markAllReadBtn').on('click', function(e){
    e.preventDefault();
    if (!confirm('Mark all alerts as read?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Marking...');
    $.post(window.location.pathname, { action: 'mark_all_read' }, function(resp){
      if (resp && resp.success) {
        $('#alertsBody tr').each(function(){ $(this).addClass('table-light'); $(this).find('.ack-btn').remove(); $(this).find('td.text-end').html('<span class="text-muted small">Acknowledged</span>'); });
      }
      $btn.prop('disabled', false).text('Mark All Read');
    }, 'json').fail(function(){ showToast('Server error', 'danger'); $btn.prop('disabled', false).text('Mark All Read'); });
  });

  // Clear all
  $('#clearAlertsBtn').on('click', function(e){
    e.preventDefault();
    if (!confirm('Clear all alerts?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Clearing...');
    $.post(window.location.pathname, { action: 'clear_all' }, function(resp){
      if (resp && resp.success) {
        $('#alertsBody').html('<tr><td colspan="5" class="text-center py-4 text-muted">No alerts found</td></tr>');
        $('#archivedAlertsBody').html('<tr><td colspan="5" class="text-center py-4 text-muted">No archived alerts</td></tr>');
      }
      $btn.prop('disabled', false).text('Clear All');
    }, 'json').fail(function(){ showToast('Server error', 'danger'); $btn.prop('disabled', false).text('Clear All'); });
  });

  // Archive all read alerts
  $('#archiveAlertsBtn').on('click', function(e){
    e.preventDefault();
    if (!confirm('Archive all alerts that have been acknowledged/read?')) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Archiving...');
    $.post(window.location.pathname, { action: 'archive_all' }, function(resp){
      if (resp && resp.success) {
        // move read rows to archived table
        $('#alertsBody tr.table-light').each(function(){
          const $tr = $(this);
          const cells = $tr.find('td').toArray().slice(0,4).map(td => $(td).html());
          const archHtml = '<tr class="table-light" data-id="'+($tr.data('id')||'')+'">'
            + '<td>' + (cells[0]||'-') + '</td>'
            + '<td>' + (cells[1]||'-') + '</td>'
            + '<td class="d-none d-md-table-cell"><small class="text-muted">' + (cells[2]||'') + '</small></td>'
            + '<td class="d-none d-lg-table-cell">' + (cells[3]||'-') + '</td>'
            + '<td class="text-end"><span class="text-muted small">Read</span></td>'
            + '</tr>';
          if ($('#archivedAlertsBody tr').length === 1 && $('#archivedAlertsBody tr td').first().hasClass('text-muted')) {
            $('#archivedAlertsBody').html(archHtml);
          } else {
            $('#archivedAlertsBody').prepend(archHtml);
          }
          $tr.remove();
        });
        if ($('#alertsBody tr').length === 0) {
          $('#alertsBody').html('<tr><td colspan="5" class="text-center py-4 text-muted">No alerts found</td></tr>');
        }
        showToast(resp.message || 'Archived read alerts', 'success');
      } else {
        showToast((resp && resp.message) ? resp.message : 'Failed to archive', 'danger');
      }
      $btn.prop('disabled', false).text('Archive All');
    }, 'json').fail(function(){ showToast('Server error', 'danger'); $btn.prop('disabled', false).text('Archive All'); });
  });

  // Polling: refresh active and archived lists
  let archivedVisible = false;

  function refreshActive() {
    $.get(window.location.pathname + '?action=get_alerts', function(resp){
      if (!resp || !resp.success) return;
      const alerts = resp.alerts || [];
      const tbody = $('#alertsBody');
      tbody.empty();
      if (!alerts.length) {
        tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted">No alerts found</td></tr>');
        return;
      }
      alerts.forEach(a => {
        const isRead = parseInt(a.is_read || 0, 10) === 1;
        const time = a.created_at ? a.created_at : 'N/A';
        const title = a.title || 'Notification';
        const message = a.message || '';
        const target = a.bin_id ? 'Bin #' + a.bin_id : '-';
        const aid = a.alert_id || '';
        const row = $('<tr>').toggleClass('table-light', isRead).attr('data-id', aid);
        row.append($('<td>').text(time));
        row.append($('<td>').text(title));
        row.append($('<td class="d-none d-md-table-cell">').html('<small class="text-muted">' + message + '</small>'));
        row.append($('<td class="d-none d-lg-table-cell">').text(target));
        const actionTd = $('<td class="text-end">');
        if (isRead) {
          actionTd.append($('<span class="text-muted small">').text('Acknowledged'));
        } else {
          actionTd.append($('<button class="btn btn-sm btn-success ack-btn me-2">').attr('data-id', aid).text('Acknowledge'));
        }
        row.append(actionTd);
        tbody.append(row);
      });
    }, 'json');
  }

  function refreshArchived() {
    if (!archivedVisible) return;
    $.get(window.location.pathname + '?action=get_archived', function(resp){
      if (!resp || !resp.success) return;
      const alerts = resp.alerts || [];
      const tbody = $('#archivedAlertsBody');
      tbody.empty();
      if (!alerts.length) {
        tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted">No archived alerts</td></tr>');
        return;
      }
      alerts.forEach(a => {
        const isRead = parseInt(a.is_read || 0, 10) === 1;
        const time = a.created_at ? a.created_at : 'N/A';
        const title = a.title || 'Notification';
        const message = a.message || '';
        const target = a.bin_id ? 'Bin #' + a.bin_id : '-';
        const aid = a.alert_id || '';
        const row = $('<tr class="table-light">').attr('data-id', aid);
        row.append($('<td>').text(time));
        row.append($('<td>').text(title));
        row.append($('<td class="d-none d-md-table-cell">').html('<small class="text-muted">' + message + '</small>'));
        row.append($('<td class="d-none d-lg-table-cell">').text(target));
        row.append($('<td class="text-end">').html('<span class="text-muted small">' + (isRead ? 'Read' : 'Unread') + '</span>'));
        tbody.append(row);
      });
    }, 'json');
  }

  // initial load + polling
  refreshActive();
  setInterval(function(){ refreshActive(); if (archivedVisible) refreshArchived(); }, 5000);

  // Toggle archived card visibility (collapsed by default)
  const $archHeader = $('#archivedCardHeader');
  $archHeader.on('click keypress', function(e) {
    if (e.type === 'keypress' && e.key !== 'Enter' && e.key !== ' ') return;
    const $archCard = $('#archivedCard');
    const $body = $('#archivedCardBody');
    const expanded = $archCard.hasClass('archived-collapsed') === false;
    if (expanded) {
      $archCard.addClass('archived-collapsed');
      $body.attr('aria-hidden', 'true');
      $('#archHint').text(' (click to expand)');
      archivedVisible = false;
    } else {
      $archCard.removeClass('archived-collapsed');
      $body.attr('aria-hidden', 'false');
      $('#archHint').text(' (click to collapse)');
      archivedVisible = true;
      refreshArchived();
    }
  });

})();
</script>
</body>
</html>
