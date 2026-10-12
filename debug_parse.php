<?php
require_once 'db_con.php';
require_once __DIR__ . '/includes/attendance_helper.php';

// Test with a sample xlsx - we'll dump what the parser sees
$filePath = null;

// Check if a file was uploaded
if (isset($_FILES['testfile']) && $_FILES['testfile']['error'] === UPLOAD_ERR_OK) {
    $filePath = $_FILES['testfile']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['testfile']['name'], PATHINFO_EXTENSION));

    $isOle = _is_ole2_file($filePath);
    $isZip = _is_zip_file($filePath);

    echo "<h2>File Info</h2>";
    echo "Extension: $ext<br>";
    echo "Is OLE2 (legacy .xls): " . ($isOle ? 'YES' : 'NO') . "<br>";
    echo "Is ZIP/XLSX: " . ($isZip ? 'YES' : 'NO') . "<br>";

    $parsedFile = $filePath;
    if ($isOle) {
        $converted = convert_xls_to_xlsx($filePath);
        if ($converted) {
            $parsedFile = $converted;
            echo "Converted to XLSX: YES<br>";
        } else {
            echo "Conversion FAILED<br>";
            exit;
        }
    }

    echo "<h2>Sheet Detection</h2>";
    for ($s = 1; $s <= 5; $s++) {
        $rows = _xlsx_load_raw_rows($parsedFile, $s);
        if (!$rows) { echo "Sheet $s: EMPTY<br>"; continue; }
        echo "Sheet $s: " . count($rows) . " rows<br>";
    }

    $sheetPick = _xlsx_pick_best_sheet($parsedFile);
    $bestSheet = $sheetPick['sheet'];
    echo "<br>Best Sheet: #$bestSheet (score: {$sheetPick['score']})<br>";

    $rows = _xlsx_load_raw_rows($parsedFile, $bestSheet);

    echo "<h2>Raw Rows from Best Sheet (first 30)</h2>";
    echo "<table border='1' style='border-collapse:collapse;font-size:12px;'>";
    echo "<tr><th>Row#</th>";
    $maxC = 0;
    foreach (array_slice($rows, 0, 30, true) as $rc) {
        if ($rc) { $mc = max(array_keys($rc)); if ($mc > $maxC) $maxC = $mc; }
    }
    for ($i = 0; $i <= min($maxC, 20); $i++) echo "<th>Col$i</th>";
    echo "</tr>";
    $cnt = 0;
    foreach ($rows as $rIdx => $cells) {
        if ($cnt++ >= 30) break;
        echo "<tr><td><b>$rIdx</b></td>";
        for ($i = 0; $i <= min($maxC, 20); $i++) {
            $v = isset($cells[$i]) ? htmlspecialchars(mb_substr(trim($cells[$i]), 0, 30)) : '';
            // Highlight
            $bg = '';
            if ($v !== '') {
                if (strtolower($v) === 'id') $bg = 'background:#4e73df;color:white;';
                elseif (preg_match('/\d{1,2}:\d{2}/', $v)) $bg = 'background:#1cc88a;color:white;';
                elseif (preg_match('/^\d{1,2}$/', $v) && (int)$v >= 1 && (int)$v <= 31) $bg = 'background:#f6c23e;';
                elseif (preg_match('/(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i', $v)) $bg = 'background:#36b9cc;color:white;';
                elseif (preg_match('/\d{4}/', $v)) $bg = 'background:#e74a3b;color:white;';
            }
            echo "<td style='padding:2px 4px;$bg'>$v</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    echo "<h2>_detect_all_report_format result</h2>";
    $fmt = _detect_all_report_format($rows);
    echo "<pre>" . print_r($fmt, true) . "</pre>";

    echo "<h2>Parse Result</h2>";
    $records = parse_biometric_xlsx($parsedFile);
    $info = _get_last_parse_info();
    echo "<pre>Parse Info: " . print_r($info, true) . "</pre>";
    echo "Records parsed: " . count($records) . "<br>";
    if ($records) {
        echo "<table border='1' style='border-collapse:collapse;font-size:12px;'>";
        echo "<tr>";
        foreach (array_keys($records[0]) as $h) echo "<th>$h</th>";
        echo "</tr>";
        foreach (array_slice($records, 0, 20) as $r) {
            echo "<tr>";
            foreach ($r as $v) echo "<td style='padding:2px 4px;'>" . htmlspecialchars((string)$v) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    // Check employee matching
    echo "<h2>Employee ID Matching Test</h2>";
    $employees = $conn->query("SELECT id, employee_id, first_name, last_name, CONCAT(first_name,' ',last_name) as full_name FROM employees")->fetchAll();
    echo "<table border='1' style='border-collapse:collapse;font-size:12px;'>";
    echo "<tr><th>DB id</th><th>employee_id</th><th>full_name</th></tr>";
    foreach ($employees as $e) {
        echo "<tr><td>{$e['id']}</td><td>{$e['employee_id']}</td><td>{$e['full_name']}</td></tr>";
    }
    echo "</table>";

    if ($parsedFile !== $filePath && file_exists($parsedFile)) @unlink($parsedFile);
}
?>
<!DOCTYPE html>
<html>
<head><title>Debug Parse</title></head>
<body>
<h1>Biometric Parser Debug</h1>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="testfile" accept=".xls,.xlsx,.csv">
    <button type="submit">Test Parse</button>
</form>
</body>
</html>
