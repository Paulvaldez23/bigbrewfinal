<?php
require_once 'db_con.php';

// Check attendance date range
$dates = $conn->query("
    SELECT 
        CASE 
            WHEN DAY(attendance_date) <= 15 THEN CONCAT(DATE_FORMAT(attendance_date, '%Y-%m-01'), ' to ', DATE_FORMAT(attendance_date, '%Y-%m-15'))
            ELSE CONCAT(DATE_FORMAT(attendance_date, '%Y-%m-16'), ' to ', DATE_FORMAT(LAST_DAY(attendance_date), '%Y-%m-%d'))
        END as cutoff_range,
        CASE 
            WHEN DAY(attendance_date) <= 15 THEN CONCAT(DATE_FORMAT(attendance_date, '%b 01 - 15, %Y'))
            ELSE CONCAT(DATE_FORMAT(attendance_date, '%b 16 - '), DATE_FORMAT(LAST_DAY(attendance_date), '%d, %Y'))
        END as cutoff_display,
        COUNT(DISTINCT employee_id) as employees,
        SUM(hours_worked) as total_hours,
        SUM(hours_worked * COALESCE(e.hourly_rate, 40)) as estimated_gross
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    GROUP BY cutoff_range, cutoff_display
    ORDER BY cutoff_range ASC
")->fetchAll();

print_r($dates);

unlink(__FILE__);
