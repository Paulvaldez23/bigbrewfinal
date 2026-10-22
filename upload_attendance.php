<?php
require_once 'includes/auth_check.php';
require_once 'db_con.php';
require_once __DIR__ . '/includes/attendance_helper.php';
require_login();

$page_title = 'Big Brew - Upload Biometrics';
$active_nav = 'upload';

$message = '';
$messageType = '';
$failedSamplesHtml = '';
$uploadResult = null;
$previewRows = [];
$rawDebugRows = [];
$parserFormatInfo = '';
$debugSheet = 1;
$recentUploads = [];
$user = current_user();

if ($conn) {
    try {
        $recentUploads = $conn->query("
            SELECT l.*, u.username
            FROM biometric_upload_logs l
            LEFT JOIN admin_users u ON l.uploaded_by = u.id
            ORDER BY l.uploaded_at DESC LIMIT 10
        ")->fetchAll();
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview' || $action === 'import') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message = "Please upload a valid file (CSV or XLSX from biometric machine).";
            $messageType = 'danger';
        } else {
            $file = $_FILES['csv_file']['tmp_name'];
            $fileName = $_FILES['csv_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!file_exists($file)) {
                $message = "Error reading uploaded file.";
                $messageType = 'danger';
            } else {
                $parsedFile = $file;
                $rows = [];
                $isOle = _is_ole2_file($file);
                $isZip = _is_zip_file($file);

                // LEGACY .xls (OLE2 binary): auto-convert to xlsx via WPS/Excel COM if available
                if ($isOle) {
                    $converted = convert_xls_to_xlsx($file);
                    if ($converted) {
                        $parsedFile = $converted;
                        $isZip = true;
                        $message = "Converted legacy .xls file to .xlsx before parsing. ";
                        $messageType = 'info';
                    } else {
                        $message = "Legacy .xls file detected but WPS/Excel is not available for auto-convert. Please open the file in WPS Office and save as .xlsx (Excel Workbook), then upload again.";
                        $messageType = 'danger';
                        $rows = [];
                    }
                }

                if (!empty($parsedFile) && ($parsedFile !== $file || !$isOle)) {
                    $rows = parse_biometric_xlsx($parsedFile);
                    $lastInfo = _get_last_parse_info();
                    $bestSheet = $lastInfo['sheet'] ?? 1;
                    $debugSheet = $bestSheet;
                    $allRaw = _xlsx_load_raw_rows($parsedFile, $bestSheet);
                    $rawDebugRows = array_slice($allRaw, 0, 20, true);
                    $fmt = _detect_all_report_format($allRaw);
                    $si = $lastInfo['sheet_info'] ?? [];
                    $infoBits = [];
                    $infoBits[] = "📂 Sheet <strong>#{$bestSheet}</strong> (score: " . ((int)($lastInfo['sheet_score'] ?? 0)) . ")";
                    if (!empty($lastInfo['format'])) {
                        $fmtNice = $lastInfo['format'] === 'all_report_matrix' ? "<span class='text-success'><strong>ALL REPORT TABLE</strong></span>" : ($lastInfo['format'] === 'classic_per_emp_block' ? "<span class='text-primary'><strong>PER-EMPLOYEE BLOCK (ZKteco-style)</strong></span>" : htmlspecialchars($lastInfo['format']));
                        $infoBits[] = "Format: $fmtNice";
                    }
                    if (isset($lastInfo['employees_found'])) $infoBits[] = "Employees found: <strong>" . (int)$lastInfo['employees_found'] . "</strong>";
                    $infoBits[] = "Parsed records: <strong>" . count($rows) . "</strong>";
                    if (!empty($si)) {
                        $mini = [];
                        if (!empty($si['idRows'])) $mini[] = "{$si['idRows']} ID-rows";
                        if (!empty($si['dayRows'])) $mini[] = "{$si['dayRows']} day-header-rows";
                        if (!empty($si['timeRows'])) $mini[] = "{$si['timeRows']} time-rows";
                        if ($mini) $infoBits[] = "<span class='text-muted small'>(" . implode(' · ', $mini) . ")</span>";
                    }
                    $parserFormatInfo = implode(' &nbsp;•&nbsp; ', $infoBits);
                    if ($fmt['is_all_report']) {
                        $parserFormatInfo .= "<br><span class='text-success small'>All-Report header → row #{$fmt['header_row_idx']} · ID col {$fmt['id_col']} · Name col {$fmt['name_col']} · " . count($fmt['date_cols']) . " date cols</span>";
                    }
                    if (!$rows && !$isOle && $ext !== 'csv') {
                        $message = ($message ? $message : '') . " XLSX parser returned empty rows. $parserFormatInfo";
                        $messageType = $messageType === 'danger' ? 'danger' : 'warning';
                    }
                } elseif (!$isOle && !$isZip) {
                    // Safe CSV-like file only
                    $rows = parse_biometric_csv($file);
                    $parserFormatInfo = "Used <strong>CSV Columnar Format</strong>.";
                }
                if ($parsedFile !== $file && $parsedFile && file_exists($parsedFile)) {
                    @unlink($parsedFile);
                }

                $previewRows = array_slice($rows, 0, 10);

                if ($action === 'import' && $conn && !empty($rows)) {
                    $total = count($rows);
                    $imported = 0;
                    $failed = 0;
                    $failedSamples = [];
                    $nameMap = [];
                    $numericIdMap = [];
                    $lastNameMap = [];
                    try {
                        foreach ($conn->query("SELECT id, employee_id, first_name, last_name, CONCAT(first_name, ' ', last_name) as full_name FROM employees")->fetchAll() as $e) {
                            $id1 = mb_strtolower(trim($e['employee_id']));
                            $id2 = mb_strtolower(trim($e['full_name']));
                            $nameMap[$id1] = ['id' => $e['id']];
                            $nameMap[$id2] = ['id' => $e['id']];
                            $fn = trim($e['first_name']); $ln = trim($e['last_name']);
                            if ($fn && $ln) {
                                $lastNameMap[mb_strtolower($ln)][] = ['id' => $e['id'], 'first' => mb_strtolower($fn), 'full' => $id2];
                            }
                            $num = preg_replace('/[^0-9]/', '', (string)$e['employee_id']);
                            if ($num !== '') { $numericIdMap[ltrim($num, '0')] = $e['id']; }
                        }
                    } catch (Exception $e) {}

                    foreach ($rows as $row) {
                        try {
                            $empIdRaw = trim($row['employee_id'] ?? $row['emp_id'] ?? $row['id'] ?? '');
                            $empNameRaw = trim($row['name'] ?? '');
                            $date = $row['date'] ?? $row['attendance_date'] ?? null;
                            $tin = $row['time_in'] ?? $row['checkin'] ?? $row['in'] ?? null;
                            $tout = $row['time_out'] ?? $row['checkout'] ?? $row['out'] ?? null;
                            if (!$date) { $failed++; $failedSamples[] = "$empNameRaw($empIdRaw) [no date]"; continue; }

                            $empDbId = null;
                            $lookupKeys = [];
                            if ($empIdRaw !== '') $lookupKeys[] = mb_strtolower($empIdRaw);
                            if ($empNameRaw !== '') $lookupKeys[] = mb_strtolower($empNameRaw);
                            if ($empNameRaw !== '') {
                                $parts = preg_split('/[\s,]+/', $empNameRaw, -1, PREG_SPLIT_NO_EMPTY);
                                if (count($parts) >= 2) {
                                    $lookupKeys[] = mb_strtolower($parts[0] . ' ' . end($parts));
                                    $lookupKeys[] = mb_strtolower(end($parts) . ' ' . $parts[0]);
                                }
                                if (count($parts) >= 3) {
                                    $lookupKeys[] = mb_strtolower($parts[0] . ' ' . $parts[count($parts)-1]);
                                    $lookupKeys[] = mb_strtolower($parts[count($parts)-1] . ' ' . $parts[0]);
                                }
                            }
                            foreach ($lookupKeys as $k) {
                                if (isset($nameMap[$k])) { $empDbId = $nameMap[$k]['id']; break; }
                            }

                            if (!$empDbId && $empIdRaw !== '') {
                                $num = preg_replace('/[^0-9]/', '', $empIdRaw);
                                if ($num !== '' && isset($numericIdMap[ltrim($num, '0')])) {
                                    $empDbId = $numericIdMap[ltrim($num, '0')];
                                }
                            }

                            // Try common prefix variants (EMP001 vs 001 vs E001)
                            if (!$empDbId && $empIdRaw !== '') {
                                $numOnly = ltrim(preg_replace('/[^0-9]/', '', $empIdRaw), '0');
                                $prefixVariants = [];
                                if ($numOnly !== '') {
                                    $prefixVariants[] = $numOnly;
                                    $prefixVariants[] = 'EMP' . $numOnly;
                                    $prefixVariants[] = 'EMP' . str_pad($numOnly, 3, '0', STR_PAD_LEFT);
                                    $prefixVariants[] = 'E' . $numOnly;
                                    $prefixVariants[] = 'E' . str_pad($numOnly, 3, '0', STR_PAD_LEFT);
                                }
                                foreach ($prefixVariants as $variant) {
                                    $vKey = mb_strtolower($variant);
                                    if (isset($nameMap[$vKey])) { $empDbId = $nameMap[$vKey]['id']; break; }
                                    if (isset($numericIdMap[ltrim($variant, '0')])) { $empDbId = $numericIdMap[ltrim($variant, '0')]; break; }
                                }
                            }

                            if (!$empDbId && $empNameRaw !== '') {
                                $clean = preg_replace('/[^A-Za-z\s]/', '', $empNameRaw);
                                $clean = trim(preg_replace('/\s+/', ' ', $clean));
                                $parts = preg_split('/\s+/', mb_strtolower($clean), -1, PREG_SPLIT_NO_EMPTY);
                                if ($parts) {
                                    $lastTried = end($parts);
                                    if (isset($lastNameMap[$lastTried])) {
                                        $cands = $lastNameMap[$lastTried];
                                        foreach ($cands as $c) {
                                            foreach ($parts as $tok) {
                                                if (strlen($tok) >= 2 && strpos($c['first'], $tok) === 0) {
                                                    $empDbId = $c['id']; break 2;
                                                }
                                            }
                                        }
                                    }
                                    if (!$empDbId && count($parts) >= 2 && isset($lastNameMap[$parts[0]])) {
                                        $cands = $lastNameMap[$parts[0]];
                                        foreach ($cands as $c) {
                                            foreach ($parts as $tok) {
                                                if (strlen($tok) >= 2 && strpos($c['first'], $tok) === 0) {
                                                    $empDbId = $c['id']; break 2;
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (!$empDbId) {
                                try {
                                    $empStmt = $conn->prepare("SELECT id FROM employees WHERE
                                        employee_id = ? OR TRIM(LEADING '0' FROM employee_id) = TRIM(LEADING '0' FROM ?) OR
                                        CONCAT(first_name, ' ', last_name) = ? OR CONCAT(last_name, ' ', first_name) = ?
                                        LIMIT 1");
                                    $numericId = preg_replace('/[^0-9]/', '', $empIdRaw);
                                    $empStmt->execute([$empIdRaw, $numericId ?: $empIdRaw, $empNameRaw, $empNameRaw]);
                                    $emp = $empStmt->fetch();
                                    if ($emp) { $empDbId = $emp['id']; }
                                } catch (Exception $e) {}
                            }

                            if (!$empDbId) {
                                $failed++;
                                if (count($failedSamples) < 5) $failedSamples[] = "{$empNameRaw} (ID:{$empIdRaw}) @ {$date}";
                                continue;
                            }

                            // Intelligent multi-machine punch merge (collect all punches and pair earliest IN with latest OUT)
                            $checkStmt = $conn->prepare("SELECT id, time_in, time_out FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1");
                            $checkStmt->execute([$empDbId, $date]);
                            $existing = $checkStmt->fetch();

                            if ($existing) {
                                $existingIn = $existing['time_in'] ?: null;
                                $existingOut = $existing['time_out'] ?: null;

                                // Collect all distinct punches across both files / machines
                                $allPunches = array_values(array_unique(array_filter([$existingIn, $existingOut, $tin, $tout])));
                                sort($allPunches);

                                if (count($allPunches) >= 2) {
                                    $finalIn = $allPunches[0];
                                    $finalOut = end($allPunches);
                                } elseif (count($allPunches) === 1) {
                                    $finalIn = $allPunches[0];
                                    $finalOut = null;
                                } else {
                                    $finalIn = null;
                                    $finalOut = null;
                                }

                                $finalHours = calculate_hours_worked($finalIn, $finalOut);
                                $regH = calculate_regular_hours($finalHours);

                                $upd = $conn->prepare("UPDATE attendance SET time_in = ?, time_out = ?, hours_worked = ?, regular_hours = ?, source = 'biometric' WHERE id = ?");
                                $upd->execute([$finalIn, $finalOut, $finalHours, $regH, $existing['id']]);
                                $imported++;
                            } else {
                                $hoursWorked = calculate_hours_worked($tin, $tout);
                                $regH = calculate_regular_hours($hoursWorked);
                                $ins = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, hours_worked, regular_hours, source) VALUES (?, ?, ?, ?, ?, ?, 'biometric')");
                                $ins->execute([$empDbId, $date, $tin, $tout, $hoursWorked, $regH]);
                                $imported++;
                            }
                        } catch (Exception $e) {
                            $failed++;
                        }
                    }

                    try {
                        $nowManila = date('Y-m-d H:i:s');
                        $logStmt = $conn->prepare("INSERT INTO biometric_upload_logs (uploaded_at, uploaded_by, file_name, total_rows, imported_rows, failed_rows, status) VALUES (?, ?, ?, ?, ?, ?, 'done')");
                        $logStmt->execute([$nowManila, $user['id'], $fileName, $total, $imported, $failed]);
                    } catch (Exception $e) {}

                    $message = ($message ? $message . ' | ' : '') . "Import complete: $imported imported, $failed failed out of $total rows.";
                    if ($failed > 0 && $failedSamples) {
                        $samplesHtml = [];
                        foreach ($failedSamples as $s) { $samplesHtml[] = htmlspecialchars($s); }
                        $failedSamplesHtml = implode('<br>', $samplesHtml);
                    }
                    $messageType = ($failed == 0) ? 'success' : 'warning';
                } elseif ($action === 'import' && empty($rows) && $messageType !== 'danger') {
                    $message = ($message ? $message : 'No parseable rows found. Check that the file is a valid biometric Attendance Logs export.');
                    $messageType = 'warning';
                } elseif ($action === 'preview' && empty($rows) && $messageType !== 'danger' && $messageType !== 'info') {
                    $message = "No parseable rows found in preview. File may be empty or unrecognized format.";
                    $messageType = 'warning';
                } elseif ($action === 'preview') {
                    $m = "Preview loaded: " . count($rows) . " rows found.";
                    $message = $message ? ($message . ' ' . $m) : $m;
                    if (!$messageType) $messageType = 'info';
                }
            }
        }
    }
}

require 'includes/head.php';
require 'includes/layoutSidenav_nav.php';
?>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Upload Biometrics</h1>
    </div>
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($failedSamplesHtml): ?>
    <div class="card mb-4 border-warning shadow-sm">
        <div class="card-header bg-warning text-dark py-2">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <strong>First <?php echo substr_count($failedSamplesHtml, '<br>') + 1; ?> Failed Records</strong>
            <small class="ml-2">(employee not found in Employees table — check if employee ID/name matches)</small>
        </div>
        <div class="card-body py-3">
            <code class="text-dark small bg-light p-2 d-inline-block" style="white-space:normal;max-width:100%;"><?php echo $failedSamplesHtml; ?></code>
            <div class="mt-3 small text-muted">
                <i class="fas fa-info-circle mr-1"></i>
                Tip: Make sure each employee's <strong>Employee ID</strong> or <strong>Full Name (First Last / Last First)</strong> in the Employees table exactly matches the All Report file.
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Upload Biometric Logs</h6></div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Biometric Export File (CSV or XLSX)</label>
                            <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required class="form-control">
                            <small class="form-text text-muted">
                                Accepted formats:
                                <ul class="mb-0 mt-1">
                                    <li><strong>XLSX Attendance Logs</strong> (from biometric machine) — matrix format with ID/Name rows, date columns, IN/OUT times.</li>
                                    <li><strong>CSV</strong> — columns: <code>employee_id, date, time_in, time_out</code> (or similar).</li>
                                </ul>
                            </small>
                        </div>
                        <button type="submit" name="action" value="preview" class="btn btn-secondary btn-icon-split m-1">
                            <span class="icon text-white-50"><i class="fas fa-eye"></i></span>
                            <span class="text">Preview Only</span>
                        </button>
                        <button type="submit" name="action" value="import" class="btn btn-primary btn-icon-split m-1">
                            <span class="icon text-white-50"><i class="fas fa-upload"></i></span>
                            <span class="text">Preview &amp; Import</span>
                        </button>
                    </form>
                </div>
            </div>
            <?php if ($parserFormatInfo): ?>
            <div class="card shadow mb-4 border-info">
                <div class="card-header bg-info text-white py-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>Parser Format Info</strong>
                </div>
                <div class="card-body py-2 small"><?php echo $parserFormatInfo; ?></div>
            </div>
            <?php endif; ?>

            <?php if ($previewRows): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-check-circle mr-1 text-success"></i>Parsed Records Preview (First 10 of <?php echo count($rows ?? []); ?>)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:400px; overflow-y:auto;">
                        <table class="table table-sm table-bordered mb-0">
                            <thead><tr><?php foreach (array_keys($previewRows[0]) as $h): ?><th class="small"><?php echo htmlspecialchars($h); ?></th><?php endforeach; ?></tr></thead>
                            <tbody><?php foreach ($previewRows as $r): ?><tr><?php foreach ($r as $k => $v): ?><td class="small"><?php if (in_array($k, ['time_in','time_out'])) { echo format_time($v); } elseif ($k === 'date') { echo format_date($v); } else { echo htmlspecialchars($v ?? ''); } ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rawDebugRows): ?>
            <div class="card shadow mb-4 border-warning">
                <div class="card-header bg-warning text-dark py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-search mr-1"></i><strong>Raw Spreadsheet Preview — Sheet #<?php echo (int)$debugSheet; ?> (First <?php echo count($rawDebugRows); ?> rows) — DEBUG</strong></span>
                    <small class="text-muted">Use this to see actual headers & data in the file</small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:460px; overflow:auto;">
                        <table class="table table-sm table-bordered mb-0 text-nowrap" style="font-size:.75rem;">
                            <thead class="bg-dark text-white sticky-top">
                                <tr>
                                    <th class="px-2 py-1" style="position:sticky;left:0;z-index:2;background:#343a40;">Row#</th>
                                    <?php
                                    $maxCol = 0;
                                    foreach ($rawDebugRows as $rc) { $mc = max(array_keys($rc)); if ($mc > $maxCol) $maxCol = $mc; }
                                    for ($ci = 0; $ci <= min($maxCol, 40); $ci++): ?>
                                    <th class="px-2 py-1">Col <?php echo $ci; ?></th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rawDebugRows as $rIdx => $cells): ?>
                                <tr>
                                    <td class="font-weight-bold text-white bg-secondary px-2 py-1" style="position:sticky;left:0;z-index:1;"><?php echo (int)$rIdx; ?></td>
                                    <?php for ($ci = 0; $ci <= min($maxCol, 40); $ci++):
                                        $v = isset($cells[$ci]) ? trim((string)$cells[$ci]) : '';
                                        $cls = '';
                                        if ($v !== '') {
                                            $iso = _parse_date_header($v);
                                            if ($iso !== null) { $cls = 'bg-success text-white font-weight-bold'; }
                                            elseif (preg_match('/(ID|BADGENUMBER|NAME|EMPLOYEE)/i', $v) && strlen($v) <= 40) { $cls = 'bg-primary text-white font-weight-bold'; }
                                        }
                                    ?>
                                    <td class="px-2 py-1 <?php echo $cls; ?>"><?php echo $v === '' ? '<span class="text-muted opacity-30">·</span>' : htmlspecialchars(mb_substr($v, 0, 60)); ?></td>
                                    <?php endfor; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer py-2 px-3 small bg-light">
                        <i class="fas fa-lightbulb mr-1 text-warning"></i>
                        Legend: <span class="bg-primary text-white px-2 py-1 font-weight-bold">ID/Name Header</span> •
                        <span class="bg-success text-white px-2 py-1 font-weight-bold">Date Column ✅</span> •
                        Row# with the colored cells = the actual HEADER ROW used for parsing.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Recent Uploads</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead><tr><th>Date</th><th>File</th><th>User</th><th>Imported</th><th>Failed</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentUploads as $l): ?>
                                <tr>
                                    <td class="small"><?php echo date('M d, Y h:i A', strtotime($l['uploaded_at'])); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($l['file_name']); ?></td>
                                    <td><?php echo htmlspecialchars($l['username'] ?? ''); ?></td>
                                    <td><?php echo $l['imported_rows']; ?></td>
                                    <td><?php echo $l['failed_rows']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$recentUploads): ?>
                                <tr><td colspan="5" class="text-center text-gray-500 py-3 small">No uploads yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require 'includes/footer.php';
require 'includes/footercdn.php';
