<?php
// CLI diagnostic - run: php diagnostic.php "path/to/AllReport.xls"
require_once __DIR__ . '/includes/attendance_helper.php';

$file = $argv[1] ?? null;
if (!$file || !file_exists($file)) {
    die("Usage: php diagnostic.php <path-to-xls-or-xlsx>\n");
}

$isOle = _is_ole2_file($file);
$isZip = _is_zip_file($file);
echo "=== FILE INFO ===\n";
echo "File: $file\n";
echo "Is OLE2 (.xls): " . ($isOle ? 'YES' : 'NO') . "\n";
echo "Is ZIP (.xlsx): " . ($isZip ? 'YES' : 'NO') . "\n\n";

$parsedFile = $file;
if ($isOle) {
    echo "Converting .xls → .xlsx via WPS/Excel COM...\n";
    $converted = convert_xls_to_xlsx($file);
    if ($converted) {
        $parsedFile = $converted;
        echo "Converted to: $converted\n\n";
    } else {
        die("Conversion FAILED — WPS/Excel COM not available.\n");
    }
}

// Sheet pick
echo "=== SHEET PICKING ===\n";
$pick = _xlsx_pick_best_sheet($parsedFile);
echo "Best sheet: #{$pick['sheet']} (score: {$pick['score']})\n\n";

$bestSheet = $pick['sheet'];
$rows = _xlsx_load_raw_rows($parsedFile, $bestSheet);
echo "=== RAW ROWS (sheet #{$bestSheet}, first 30) ===\n";
$cnt = 0;
foreach ($rows as $rIdx => $cells) {
    if ($cnt++ >= 30) break;
    $parts = [];
    for ($i = 0; $i <= min(20, max(array_keys($cells) + [0])); $i++) {
        $v = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        $parts[] = ($v === '') ? '·' : "[$v]";
    }
    echo "Row $rIdx: " . implode(' ', $parts) . "\n";
}

echo "\n=== DATE RANGE DETECTION ===\n";
$ds = '[\-\.\/\s]+';
$startDate = null; $endDate = null;
foreach ($rows as $rIdx => $cells) {
    foreach ($cells as $colIdx => $cellVal) {
        $cv = trim((string)$cellVal);
        if (strlen($cv) < 8) continue;
        if (preg_match('/(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})\s*[-~]+\s*(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})/i', $cv, $m)) {
            $startDate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            $endDate   = sprintf('%04d-%02d-%02d', $m[4], $m[5], $m[6]);
            echo "Found range in Row $rIdx Col $colIdx: \"$cv\"\n";
            echo "Start: $startDate  End: $endDate\n";
            break 2;
        }
    }
}
if (!$startDate) echo "WARNING: No date range found!\n";

echo "\n=== ALL REPORT FORMAT DETECTION ===\n";
$fmt = _detect_all_report_format($rows);
echo "is_all_report: " . ($fmt['is_all_report'] ? 'YES' : 'NO') . "\n";
if ($fmt['is_all_report']) {
    echo "header_row_idx: {$fmt['header_row_idx']}\n";
    echo "id_col: {$fmt['id_col']}\n";
    echo "name_col: {$fmt['name_col']}\n";
    echo "date_cols count: " . count($fmt['date_cols']) . "\n";
}

echo "\n=== FULL PARSE ===\n";
$records = parse_biometric_xlsx($parsedFile);
$info = _get_last_parse_info();
echo "Format: " . ($info['format'] ?? 'unknown') . "\n";
echo "Employees found: " . ($info['employees_found'] ?? '?') . "\n";
echo "Records parsed: " . count($records) . "\n";

if ($records) {
    echo "\nFirst 10 records:\n";
    echo str_pad('employee_id', 15) . str_pad('name', 20) . str_pad('date', 12) . str_pad('time_in', 10) . str_pad('time_out', 10) . "\n";
    echo str_repeat('-', 67) . "\n";
    foreach (array_slice($records, 0, 10) as $r) {
        echo str_pad($r['employee_id'] ?? '', 15)
           . str_pad($r['name'] ?? '', 20)
           . str_pad($r['date'] ?? '', 12)
           . str_pad($r['time_in'] ?? '', 10)
           . str_pad($r['time_out'] ?? '', 10)
           . "\n";
    }
}

if ($parsedFile !== $file && file_exists($parsedFile)) @unlink($parsedFile);
echo "\nDone.\n";
