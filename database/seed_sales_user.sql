USE workpulse; 
INSERT INTO users (employee_id, full_name, email, password, mobile, department, designation, role, is_field_sales, daily_meeting_target, base_lat, base_lng, base_address, registered_lat, registered_lng, registered_address, status)
VALUES ('EMP-SALES-001', 'Vikram Sharma', 'sales@yopmail.com', '$2y$10$RgT9yJIgUJOKuVc1tDRi9OIvMTyWE46zn0kxLQwBGXtRWpFntSfPG', '9876543299', 'Field Sales', 'Senior Sales Executive', 'sales_executive', 1, 5, 21.14580000, 79.08820000, 'WorkPulse HQ, Nagpur', 21.14580000, 79.08820000, 'WorkPulse HQ, Nagpur', 'active')
ON DUPLICATE KEY UPDATE 
    role = 'sales_executive',
    is_field_sales = 1,
    daily_meeting_target = 5,
    base_lat = 21.14580000,
    base_lng = 79.08820000,
    base_address = 'WorkPulse HQ, Nagpur';
SELECT id, full_name, email, role, is_field_sales, daily_meeting_target, base_lat, base_lng FROM users WHERE email = 'sales@yopmail.com';
