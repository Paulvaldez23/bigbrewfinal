<?php
require_once 'db_con.php';

$weekStart = '2026-10-05';

// Check current counts for this week
$stmt = $conn->prepare("SELECT branch, COUNT(*) as c FROM employee_shifts WHERE week_start = ? GROUP BY branch");
$stmt->execute([$weekStart]);
echo "Current shifts in DB for week $weekStart:\n";
while ($row = $stmt->fetch()) {
    echo "- " . $row['branch'] . ": " . $row['c'] . " shifts\n";
}

// Simulate inserting both Branch 1 and Branch 2 shifts
$empStmt = $conn->query("SELECT id FROM employees LIMIT 2");
$emps = $empStmt->fetchAll();
if (count($emps) >= 2) {
    $e1 = (int)$emps[0]['id'];
    $e2 = (int)$emps[1]['id'];

    $conn->beginTransaction();
    $del = $conn->prepare("DELETE FROM employee_shifts WHERE week_start = ?");
    $del->execute([$weekStart]);

    $ins = $conn->prepare("INSERT INTO employee_shifts
        (employee_id, week_start, day_of_week, shift_row, branch, shift_label, shift_in, shift_out)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Branch 1 shift
    $ins->execute([$e1, $weekStart, 4, 'Morning Shift', 'Branch 1', 'Morning Shift', '06:00:00', '14:00:00']);
    // Branch 2 shift
    $ins->execute([$e2, $weekStart, 5, 'Afternoon Shift', 'Branch 2', 'Afternoon Shift', '14:00:00', '22:00:00']);

    $conn->commit();

    echo "\nAfter saving both Branch 1 & Branch 2:\n";
    $stmt->execute([$weekStart]);
    while ($row = $stmt->fetch()) {
        echo "- " . $row['branch'] . ": " . $row['c'] . " shifts\n";
    }
}
