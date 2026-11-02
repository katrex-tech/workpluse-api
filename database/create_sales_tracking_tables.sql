-- 1. Alter users table to add field sales columns if not present
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS is_field_sales TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS daily_meeting_target INT NOT NULL DEFAULT 5,
ADD COLUMN IF NOT EXISTS base_lat DECIMAL(10, 8) NULL,
ADD COLUMN IF NOT EXISTS base_lng DECIMAL(11, 8) NULL,
ADD COLUMN IF NOT EXISTS base_address VARCHAR(255) NULL;

-- 2. Create sales_meetings table
CREATE TABLE IF NOT EXISTS sales_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    client_phone VARCHAR(20) NULL,
    meeting_purpose VARCHAR(200) NULL,
    notes TEXT NULL,
    photo_path VARCHAR(255) NOT NULL,
    watermarked_photo_path VARCHAR(255) NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    location_address VARCHAR(255) NOT NULL,
    check_in_time DATETIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT NULL,
    review_remarks VARCHAR(255) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_time (user_id, check_in_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create sales_tracking_logs table
CREATE TABLE IF NOT EXISTS sales_tracking_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    accuracy FLOAT NULL,
    speed FLOAT NULL,
    battery_level INT NULL,
    is_mock TINYINT(1) DEFAULT 0,
    recorded_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create sales_location_alerts table
CREATE TABLE IF NOT EXISTS sales_location_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    alert_type ENUM('gps_disabled', 'app_killed', 'mock_location', 'geofence_violation') NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    message VARCHAR(255) NOT NULL,
    is_resolved TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alert_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create sales_logout_requests table
CREATE TABLE IF NOT EXISTS sales_logout_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_date DATE NOT NULL,
    target_meetings INT NOT NULL,
    completed_meetings INT NOT NULL,
    approved_meetings INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT NULL,
    review_remarks VARCHAR(255) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_req_user_date (user_id, request_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Add 'field_sales' to menu_modules for quick access
INSERT INTO menu_modules (module_key, title, subtitle, icon, color, route, badge, is_active, sort_order)
VALUES ('field_sales', 'Field Sales', 'Client Meetings & Live GPS Route', 'directions_walk', '#0284C7', 'field_sales', 'LIVE', 1, 9)
ON DUPLICATE KEY UPDATE 
    title = 'Field Sales',
    subtitle = 'Client Meetings & Live GPS Route',
    icon = 'directions_walk',
    color = '#0284C7',
    route = 'field_sales',
    badge = 'LIVE',
    is_active = 1;
