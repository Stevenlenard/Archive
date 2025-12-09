
-- Create Database
/* CREATE DATABASE IF NOT EXISTS trashbin_management;
USE trashbin_management; */
select * From admins;

DELIMITER //

CREATE TRIGGER trigger_notify_janitor_update
AFTER UPDATE ON janitors
FOR EACH ROW
BEGIN
  -- Check if updated_at has changed (and avoid on first INSERT)
  IF NEW.updated_at <> OLD.updated_at THEN
    INSERT INTO notifications (
      admin_id,
      janitor_id,
      notification_type,
      title,
      message,
      is_read,
      created_at
    )
    VALUES (
      NULL,
      NEW.janitor_id,
      'info',
      CONCAT('Profile updated for ', NEW.first_name, ' ', NEW.last_name),
      CONCAT('Maintenance staff "', NEW.first_name, ' ', NEW.last_name, '" (ID: ', NEW.janitor_id, ') updated their profile at ', NEW.updated_at),
      0,
      NOW()
    );
  END IF;
END;
//

DELIMITER ;


CREATE TABLE IF NOT EXISTS admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    employee_id VARCHAR(50) UNIQUE,
    profile_picture VARCHAR(255),
    reset_token_hash VARCHAR(64) NULL DEFAULT NULL,
    reset_token_expires_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS janitors (
    janitor_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    employee_id VARCHAR(50) UNIQUE,
    profile_picture VARCHAR(255),
    reset_token_hash VARCHAR(64) NULL DEFAULT NULL,
    reset_token_expires_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS bins (
    bin_id INT AUTO_INCREMENT PRIMARY KEY,
    bin_code VARCHAR(50) UNIQUE NOT NULL,
    location VARCHAR(255) NOT NULL,
    type ENUM('General', 'Recyclable', 'Organic') NOT NULL DEFAULT 'General',
    capacity INT NOT NULL DEFAULT 0 COMMENT 'Capacity percentage (0-100)',
    status ENUM('empty', 'needs_attention', 'full', 'half_full') NOT NULL DEFAULT 'empty',
    assigned_to INT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    installation_date DATE,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES janitors(janitor_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_location (location),
    INDEX idx_assigned_to (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bins 
MODIFY COLUMN status 
ENUM('empty', 'half_full', 'needs_attention', 'full') 
NOT NULL DEFAULT 'empty';

SELECT * FROM janitors;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    janitor_id INT NULL,
    bin_id INT,
    notification_type ENUM('critical', 'warning', 'info', 'success') NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
    FOREIGN KEY (janitor_id) REFERENCES janitors(janitor_id) ON DELETE CASCADE,
    FOREIGN KEY (bin_id) REFERENCES bins(bin_id) ON DELETE CASCADE,
    INDEX idx_is_read (is_read),
    INDEX idx_notification_type (notification_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(255) NOT NULL,
    report_type ENUM('collections', 'performance', 'status', 'revenue', 'custom') NOT NULL,
    generated_by INT NOT NULL,
    date_from DATE,
    date_to DATE,
    report_data JSON,
    format ENUM('pdf', 'excel', 'csv') NOT NULL DEFAULT 'pdf',
    status ENUM('generating', 'completed', 'failed') NOT NULL DEFAULT 'generating',
    file_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES admins(admin_id) ON DELETE CASCADE,
    INDEX idx_generated_by (generated_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reports
ADD COLUMN description TEXT AFTER report_name;

ALTER TABLE reports
MODIFY COLUMN  status ENUM('generating', 'completed', 'failed') NOT NULL DEFAULT 'generating';

ALTER TABLE reports 
MODIFY COLUMN format ENUM('excel', 'csv') NOT NULL DEFAULT 'excel';


CREATE TABLE IF NOT EXISTS activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    janitor_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL,
    FOREIGN KEY (janitor_id) REFERENCES janitors(janitor_id) ON DELETE SET NULL,
    INDEX idx_admin_id (admin_id),
    INDEX idx_janitor_id (janitor_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add persistent sessions table for login persistence

SELECT * FROM janitor_alerts;
DELETE FROM bins WHERE bin_id = 5;

-- Create janitor_alerts table

CREATE TABLE IF NOT EXISTS `janitor_alerts` (
  `alert_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` INT NULL,            -- optional reference to an original notifications row
  `source` ENUM('bin','admin') NOT NULL DEFAULT 'bin', -- where the alert originated
  `admin_id` INT NULL,                   -- admin who created the admin alert (if any)
  `janitor_id` INT NOT NULL,             -- janitor who should see this alert (required)
  `bin_id` INT NULL,                     -- relevant bin (nullable)
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT,
  `severity` ENUM('info','warning','attention','critical') NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  -- Prevent creating the same notification->janitor duplicate
  UNIQUE KEY `ux_notification_janitor` (`notification_id`, `janitor_id`),
  -- Fast lookups
  INDEX `idx_janitor` (`janitor_id`),
  INDEX `idx_bin` (`bin_id`),
  INDEX `idx_notification` (`notification_id`),
  INDEX `idx_source_severity` (`source`, `severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `janitor_alerts`
ADD CONSTRAINT `fk_janitor_alerts_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`notification_id`) ON DELETE SET NULL ON UPDATE CASCADE,
ADD CONSTRAINT `fk_janitor_alerts_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins`(`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
ADD CONSTRAINT `fk_janitor_alerts_janitor` FOREIGN KEY (`janitor_id`) REFERENCES `janitors`(`janitor_id`) ON DELETE CASCADE ON UPDATE CASCADE,
ADD CONSTRAINT `fk_janitor_alerts_bin` FOREIGN KEY (`bin_id`) REFERENCES `bins`(`bin_id`) ON DELETE SET NULL ON UPDATE CASCADE;

INSERT INTO `janitor_alerts` (`notification_id`, `source`, `admin_id`, `janitor_id`, `bin_id`, `title`, `message`, `severity`, `is_read`, `created_at`)
SELECT
  n.notification_id,
  CASE WHEN n.admin_id IS NOT NULL THEN 'admin' ELSE 'bin' END AS source,
  n.admin_id,
  COALESCE(n.janitor_id, b.assigned_to) AS janitor_id,
  n.bin_id,
  n.title,
  n.message,
  CASE
    WHEN LOWER(n.notification_type) IN ('critical','alert') THEN 'critical'
    WHEN LOWER(n.notification_type) IN ('attention','warning') THEN 'attention'
    ELSE 'info'
  END AS severity,
  COALESCE(n.is_read, 0) AS is_read,
  n.created_at
FROM notifications n
LEFT JOIN bins b ON n.bin_id = b.bin_id
WHERE COALESCE(n.janitor_id, b.assigned_to) IS NOT NULL
  AND (
       n.bin_id IS NOT NULL
       OR LOWER(n.notification_type) IN ('attention','alert','critical')
       OR LOWER(n.title) LIKE '%attention%'
       OR LOWER(n.message) LIKE '%attention%'
       OR LOWER(n.title) LIKE '%bin%'
       OR LOWER(n.message) LIKE '%bin%'
  )
ON DUPLICATE KEY UPDATE
  -- avoid duplicate import; keep existing row if ux_notification_janitor unique key exists
  `alert_id` = `alert_id`;

CREATE TABLE IF NOT EXISTS auth_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'janitor') NOT NULL,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_user_type (user_type),
    INDEX idx_expires_at (expires_at),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add token columns to admins table
ALTER TABLE admins ADD COLUMN IF NOT EXISTS auth_token VARCHAR(255) NULL UNIQUE;

-- Add token columns to janitors table  
ALTER TABLE janitors ADD COLUMN IF NOT EXISTS auth_token VARCHAR(255) NULL UNIQUE;


-- ==================================================
-- INSERT SAMPLE DATA
-- ==================================================

-- Insert Admin User
-- Password: password
	/* INSERT INTO admins (
		first_name,
		last_name,
		email,
		phone,
		password,
		status,
		employee_id
	) VALUES (
		'Admin',
		'User',
		'admin@gmail.com',
		'+1 (555) 000-0000',
		'$2y$10$zrso/wR/n/AIPhvxa1oReOLFVS0aLAUAD/6wNbUbYJwdpBgjvzb62',
		'active',
		'ADM-001'
	);


 DROP TABLE bin_status_history;
-- tasks
-- bin_status_history
	


-- Default password for all users: password

-- Insert Sample Janitors
 INSERT INTO janitors (
    first_name,
    last_name,  
    email,
    phone,
    password,
    status,
    employee_id
) VALUES
('John', 'Doe', 'john@gmail.com', '+1 (555) 123-4567', '$2y$10$zrso/wR/n/AIPhvxa1oReOLFVS0aLAUAD/6wNbUbYJwdpBgjvzb62', 'active', 'JAN-001'),
('Jane', 'Smith', 'jane@gmail.com', '+1 (555) 234-5678', '$2y$10$zrso/wR/n/AIPhvxa1oReOLFVS0aLAUAD/6wNbUbYJwdpBgjvzb62', 'active', 'JAN-002'),
('Bob', 'Johnson', 'bob@gmail.com', '+1 (555) 345-6789', '$2y$10$zrso/wR/n/AIPhvxa1oReOLFVS0aLAUAD/6wNbUbYJwdpBgjvzb62', 'active', 'JAN-003');

-- Insert Sample Bins 
DELETE FROM reports WHERE report_id IN (1, 2);
SELECT * FROM reports;

SET SQL_SAFE_UPDATES = 0;
DELETE FROM bins;

INSERT INTO bins (bin_code, location, type, capacity, status, assigned_to) VALUES
('BIN-001', 'CICS Building - 5th Floor', 'General', 95, 'full', 2),
('BIN-002', 'HEB Building - 1st and 2nd Floor', 'General', 10, 'empty', 3),
('BIN-003', 'HEB Building - 3rd to 5th Floor', 'Organic', 80, 'needs_attention', 4),
('BIN-004', 'GZB Building -  1st to 3rd Floor', 'General', 45, 'needs_attention', 2),
('BIN-005', 'OB Building - 1st and 2nd Floor', 'Recyclable', 60, 'needs_attention', 3),
('BIN-006', 'OB Building - 3rd and 4th Floor', 'General', 5, 'empty', 4),
('BIN-007', 'OB Building - Library Area', 'Organic', 70, 'needs_attention', 2);

-- Insert Sample Collections SELECT * FROM collections;
SELECT * FROM notifications;

SET SQL_SAFE_UPDATES = 0;
DELETE FROM bins;SELECT * FROM bins;

INSERT INTO collections (bin_id, janitor_id, action_type, status, notes) VALUES
(2, 3, 'emptied', 'completed', 'Regular emptying completed successfully'),
(6, 4, 'emptied', 'completed', 'Empty bin service'),
(8, 3, 'cleaning', 'completed', 'Cleaned and sanitized the bin');

-- Insert Sample Tasks 
SELECT * FROM activity_logs;

INSERT INTO tasks (bin_id, janitor_id, task_type, priority, status, notes) VALUES
(1, 2, 'empty', 'critical', 'pending', 'Full bin needs immediate attention'),
(3, 4, 'inspection', 'medium', 'pending', 'Routine inspection required'),
(7, 2, 'maintenance', 'high', 'pending', 'Bin mechanism needs checking'),
(9, 4, 'empty', 'critical', 'pending', 'Overflow detected');

-- Insert Sample Notifications SELECT * FROM notifications;

INSERT INTO notifications (user_id, bin_id, notification_type, title, message, is_read) VALUES
(1, 1, 'critical', 'Bin Full Alert', 'Bin BIN-001 is FULL - Immediate action required', FALSE),
(1, 3, 'warning', 'Bin Capacity Warning', 'Bin BIN-003 capacity at 80% - Schedule emptying soon', FALSE),
(1, 2, 'info', 'Bin Emptied', 'Bin BIN-002 emptied successfully', TRUE),
(2, 1, 'critical', 'Assigned Bin Full', 'Your assigned bin BIN-001 is full and needs attention', FALSE),
(3, 4, 'warning', 'Inspection Due', 'Bin BIN-004 scheduled for inspection', FALSE);

-- Insert Sample Activity Logs
INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description) VALUES
(1, 'login', 'user', 1, 'User logged in'),
(2, 'update', 'bin', 2, 'Updated bin status'),
(3, 'create', 'collection', 2, 'Recorded bin collection'),
(4, 'create', 'task', 3, 'Assigned new task to janitor');

-- ==================================================
-- CREATE VIEWS
-- ==================================================

-- View: Active Janitors with their bin counts
CREATE OR REPLACE VIEW v_active_janitors AS
SELECT 
    u.user_id,
    u.first_name,
    u.last_name,
    u.email,
    u.phone,
    u.status,
    COUNT(b.bin_id) as assigned_bins_count
FROM users u
LEFT JOIN bins b ON u.user_id = b.assigned_to
WHERE u.role = 'janitor' AND u.status = 'active'
GROUP BY u.user_id;

-- View: Bin Statistics
CREATE OR REPLACE VIEW v_bin_statistics AS
SELECT 
    status,
    type,
    COUNT(*) as bin_count,
    AVG(capacity) as avg_capacity
FROM bins
GROUP BY status, type;

-- View: Recent Collections
CREATE OR REPLACE VIEW v_recent_collections AS
SELECT 
    c.collection_id,
    b.bin_code,
    b.location,
    CONCAT(u.first_name, ' ', u.last_name) as janitor_name,
    c.action_type,
    c.status,
    c.collected_at
FROM collections c
JOIN bins b ON c.bin_id = b.bin_id
JOIN users u ON c.janitor_id = u.user_id
ORDER BY c.collected_at DESC
LIMIT 50; */

-- ==================================================
-- END OF DATABASE SCHEMA
-- Note: Stored Procedures and Triggers removed for phpMyAdmin compatibility
-- These can be added later manually if needed
-- ==================================================