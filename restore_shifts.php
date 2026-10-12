<?php
require_once 'db_con.php';
$weekStart = '2026-10-05';
$conn->beginTransaction();
$conn->prepare("DELETE FROM employee_shifts WHERE week_start = ?")->execute([$weekStart]);
$ins = $conn->prepare("INSERT INTO employee_shifts
    (employee_id, week_start, day_of_week, shift_row, branch, shift_label, shift_in, shift_out)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
// Shao Valdez (id: 8)
$ins->execute([8, $weekStart, 4, 'Morning Shift', 'Branch 1', 'Morning Shift', '06:00:00', '14:00:00']);
$ins->execute([8, $weekStart, 5, 'Afternoon Shift', 'Branch 1', 'Afternoon Shift', '14:00:00', '22:00:00']);
$conn->commit();
echo "Restored original week shifts.\n";
