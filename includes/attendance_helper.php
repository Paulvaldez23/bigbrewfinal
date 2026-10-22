<?php
function _is_ole2_file($filePath) {
    if (!file_exists($filePath) || filesize($filePath) < 8) return false;
    $h = fopen($filePath, 'rb');
    if (!$h) return false;
    $sig = fread($h, 4);
    fclose($h);
    return $sig === "\xD0\xCF\x11\xE0";
}
function _is_zip_file($filePath) {
    if (!file_exists($filePath) || filesize($filePath) < 4) return false;
    $h = fopen($filePath, 'rb');
    if (!$h) return false;
    $sig = fread($h, 4);
    fclose($h);
    return $sig === "PK\x03\x04";
}

function convert_xls_to_xlsx($srcFile, $dstFile = null) {
    if (!file_exists($srcFile)) return false;
    if (!class_exists('COM', false)) return false;
    if ($dstFile === null) {
        $dstFile = dirname($srcFile) . DIRECTORY_SEPARATOR . 'converted_' . basename($srcFile) . '.xlsx';
    }
    if (file_exists($dstFile)) @unlink($dstFile);

    $progIds = ['Ket.Application', 'Excel.Application', 'WPS.Application', 'ET.Application'];
    $app = null;
    foreach ($progIds as $pid) {
        try { $app = new COM($pid); if ($app) break; } catch (Throwable $e) { $app = null; }
    }
    if (!$app) return false;
    try {
        $app->Visible = false;
        try { $app->DisplayAlerts = false; } catch (Throwable $ex) {}
        $workbooks = $app->Workbooks;
        $wb = $workbooks->Open(realpath($srcFile));
        $xlOpenXMLWorkbook = 51;
        try {
            $wb->SaveAs($dstFile, $xlOpenXMLWorkbook);
        } catch (Throwable $e) {
            try {
                $wb->SaveCopyAs($dstFile);
            } catch (Throwable $ex2) {
                try { $wb->Close(false); } catch (Throwable $ex3) {}
                try { $app->Quit(); } catch (Throwable $ex4) {}
                return false;
            }
        }
        try { $wb->Close(false); } catch (Throwable $ex) {}
        try { $app->Quit(); } catch (Throwable $ex) {}
        unset($wb, $workbooks, $app);
        usleep(200000);
        return file_exists($dstFile) ? $dstFile : false;
    } catch (Throwable $e) {
        try { if (isset($wb)) { $wb->Close(false); } } catch (Throwable $ex) {}
        try { if (isset($app)) { $app->Quit(); unset($app); } } catch (Throwable $ex) {}
        return false;
    }
}

function parse_biometric_csv($filePath) {
    $rows = [];
    if (!file_exists($filePath)) return $rows;

    $handle = fopen($filePath, 'r');
    if ($handle === false) return $rows;

    $header = fgetcsv($handle);
    if ($header === false) { fclose($handle); return $rows; }
    $header = array_map(function($h){ return strtolower(trim(preg_replace('/[^a-z0-9_]/i','_', $h))); }, $header);

    $norm = [];
    foreach ($header as $i => $key) {
        if (in_array($key, ['employee_id','emp_id','empid','id','user_id','employeeid'])) $norm['employee_id'] = $i;
        elseif (in_array($key, ['date','attendance_date','att_date','log_date'])) $norm['date'] = $i;
        elseif (in_array($key, ['time_in','timein','check_in','checkin','in','clock_in','first_in'])) $norm['time_in'] = $i;
        elseif (in_array($key, ['time_out','timeout','check_out','checkout','out','clock_out','last_out'])) $norm['time_out'] = $i;
        elseif (in_array($key, ['check_time','punch_time','time','datetime'])) $norm['time'] = $i;
        elseif (in_array($key, ['name','employee_name','full_name','emp_name'])) $norm['name'] = $i;
    }

    $grouped = [];
    while (($data = fgetcsv($handle)) !== false) {
        $rec = [];
        foreach ($norm as $k => $i) { $rec[$k] = isset($data[$i]) ? trim($data[$i]) : null; }

        if (!empty($rec['time']) && empty($rec['time_in']) && empty($rec['time_out'])) {
            $dt = $rec['time'];
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2}(:\d{2})?)/', $dt, $m)) {
                if (empty($rec['date'])) $rec['date'] = $m[1];
                $t = $m[2];
                $key = ($rec['employee_id'] ?? 'x') . '|' . $rec['date'];
                if (!isset($grouped[$key])) $grouped[$key] = ['employee_id'=>$rec['employee_id']??null,'name'=>$rec['name']??null,'date'=>$rec['date'],'punches'=>[]];
                $grouped[$key]['punches'][] = $t;
                continue;
            }
        }
        $rows[] = $rec;
    }
    fclose($handle);

    foreach ($grouped as $g) {
        sort($g['punches']);
        $rows[] = [
            'employee_id' => $g['employee_id'],
            'name' => $g['name'],
            'date' => $g['date'],
            'time_in' => $g['punches'][0] ?? null,
            'time_out' => end($g['punches']) ?: null
        ];
    }
    return $rows;
}

function _xlsx_get_inline_str($cell) {
    $text = '';
    $ns = $cell->getDocNamespaces();
    $def = $ns[''] ?? null;
    $ch = $def ? $cell->children($def) : $cell->children();
    if (isset($ch->is)) {
        $isNs = $ch->is->getDocNamespaces();
        $isDef = $isNs[''] ?? null;
        $isCh = $isDef ? $ch->is->children($isDef) : $ch->is->children();
        if (isset($isCh->t)) { $text = (string)$isCh->t; }
        elseif (isset($isCh->r)) {
            foreach ($isCh->r as $r) {
                $rNs = $r->getDocNamespaces();
                $rDef = $rNs[''] ?? null;
                $rc = $rDef ? $r->children($rDef) : $r->children();
                if (isset($rc->t)) $text .= (string)$rc->t;
            }
        }
    }
    return $text;
}

function _xlsx_read_cell($cell, $sharedStrings) {
    $ns = $cell->getDocNamespaces();
    $def = $ns[''] ?? null;
    $attrs = $cell->attributes();
    $type = isset($attrs['t']) ? (string)$attrs['t'] : '';
    if ($type === 'inlineStr') {
        return _xlsx_get_inline_str($cell);
    }
    $ch = $def ? $cell->children($def) : $cell->children();
    if (!isset($ch->v)) return '';
    $v = (string)$ch->v;
    if ($type === 's') {
        return isset($sharedStrings[(int)$v]) ? $sharedStrings[(int)$v] : '';
    }
    return $v;
}

function _xlsx_col_index($colLetter) {
    $letters = str_split(strtoupper($colLetter));
    $num = 0;
    foreach ($letters as $l) { $num = $num * 26 + (ord($l) - 64); }
    return $num - 1;
}

function _excel_serial_to_time($val) {
    $v = trim((string)$val);
    if ($v === '') return null;
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v, $m)) {
        $t = $v;
        if (substr_count($t, ':') === 1) $t .= ':00';
        return $t;
    }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?\s+\d{1,2}:\d{2}(:\d{2})?$/', $v)) return $v;
    if (is_numeric($v)) {
        $frac = (float)$v;
        if ($frac < 0 || $frac >= 2) return null;
        if ($frac < 0) $frac = 0;
        $totalSeconds = (int)round($frac * 86400);
        $h = floor($totalSeconds / 3600);
        $m = floor(($totalSeconds % 3600) / 60);
        $s = $totalSeconds % 60;
        if ($h >= 24) $h = 23;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
    $parts = preg_split('/\s+/', $v, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) >= 1) {
        $allNumeric = true;
        $outParts = [];
        foreach ($parts as $p) {
            if (!is_numeric($p)) { $allNumeric = false; break; }
            $t = _excel_serial_to_time($p);
            if ($t === null) { $allNumeric = false; break; }
            $outParts[] = $t;
        }
        if ($allNumeric) return implode(' ', $outParts);
    }
    return null;
}

function _cells_have_time_content($cells) {
    foreach ($cells as $c) {
        $raw = trim((string)$c);
        if ($raw === '') continue;
        if (preg_match('/\d{1,2}:\d{2}/', $raw)) return true;
        if (is_numeric($raw)) {
            $f = (float)$raw;
            if ($f >= 0 && $f < 2) return true;
        }
    }
    return false;
}


function _xlsx_load_raw_rows($filePath, $sheetNum = 1) {
    $rows = [];
    if (!file_exists($filePath) || !class_exists('ZipArchive')) return $rows;
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return $rows;
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $sx = simplexml_load_string($ssXml);
        if ($sx) {
            $ns = $sx->getDocNamespaces();
            $defNs = $ns[''] ?? null;
            $children = $defNs ? $sx->children($defNs) : $sx->children();
            foreach ($children as $tag => $el) {
                if ($tag === 'si') {
                    $text = '';
                    $elNs = $el->getDocNamespaces();
                    $elDef = $elNs[''] ?? null;
                    $elCh = $elDef ? $el->children($elDef) : $el->children();
                    if (isset($elCh->t)) { $text = (string)$elCh->t; }
                    elseif (isset($elCh->r)) { foreach ($elCh->r as $r) { $rNs = $r->getDocNamespaces(); $rDef = $rNs[''] ?? null; $rc = $rDef ? $r->children($rDef) : $r->children(); if (isset($rc->t)) $text .= (string)$rc->t; } }
                    $sharedStrings[] = $text;
                }
            }
        }
    }
    $sheetXml = $zip->getFromName("xl/worksheets/sheet$sheetNum.xml");
    $zip->close();
    if (!$sheetXml) return $rows;
    $sx = simplexml_load_string($sheetXml);
    if (!$sx) return $rows;
    $ns = $sx->getDocNamespaces();
    $defNs = $ns[''] ?? null;
    $sheetData = null;
    $sxCh = $defNs ? $sx->children($defNs) : $sx->children();
    foreach ($sxCh as $tag => $ch) {
        if ($tag === 'sheetData') { $sheetData = $ch; break; }
    }
    if (!$sheetData) return $rows;
    $sheetCh = $defNs ? $sheetData->children($defNs) : $sheetData->children();
    foreach ($sheetCh as $tag => $rowEl) {
        if ($tag !== 'row') continue;
        $rowAttrs = $rowEl->attributes();
        $rowIdx = isset($rowAttrs['r']) ? (int)$rowAttrs['r'] : count($rows) + 1;
        $rows[$rowIdx] = [];
        $rowCh = $defNs ? $rowEl->children($defNs) : $rowEl->children();
        foreach ($rowCh as $ctag => $c) {
            if ($ctag !== 'c') continue;
            $cAttrs = $c->attributes();
            $ref = isset($cAttrs['r']) ? (string)$cAttrs['r'] : '';
            if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                $col = _xlsx_col_index($m[1]);
                $val = _xlsx_read_cell($c, $sharedStrings);
                $rows[$rowIdx][$col] = $val;
            }
        }
    }
    ksort($rows);
    return $rows;
}

function _xlsx_pick_best_sheet($filePath) {
    $bestSheet = 1; $bestScore = -1; $bestInfo = [];
    for ($s = 1; $s <= 8; $s++) {
        $rows = _xlsx_load_raw_rows($filePath, $s);
        if (!$rows) continue;
        $score = 0; $info = ['idRows' => 0, 'dayRows' => 0, 'timeRows' => 0, 'nonEmpty' => 0];
        $dayNamePat = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Lun|Mar|Mie|Jue|Vie|Sab|Dom)$/i';
        $rangeDateFound = false;
        foreach ($rows as $rIdx => $cells) {
            $col0 = trim($cells[0] ?? '');
            $nc = 0; $dayMatch = 0; $dayNumMatch = 0;
            foreach ($cells as $i => $v) {
                $vv = trim((string)$v);
                if ($vv === '') continue;
                $nc++;
                if (preg_match($dayNamePat, $vv)) $dayMatch++;
                if (preg_match('/^\d{1,2}$/', $vv) && (int)$vv >= 1 && (int)$vv <= 31) $dayNumMatch++;
            }
            if ($nc >= 5) $info['nonEmpty'] += $nc;
            if (strcasecmp($col0, 'ID') === 0) { $info['idRows']++; $score += 40; }
            $ds = '[\-\.\/\s]';
            if (preg_match('/(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})\s*[-~to]+\s*(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})/', trim($cells[0] ?? '').' '.trim($cells[1] ?? ''))) { $rangeDateFound = true; $score += 20; }
            if ($dayMatch >= 5 && empty($col0)) { $info['dayRows']++; $score += 30; }
            if ($dayNumMatch >= 5 && empty($col0)) { $score += 25; $info['dayRows']++; }
            if (empty($col0) && _cells_have_time_content($cells)) { $info['timeRows']++; $score += 50; }
        }
        if ($rangeDateFound && $info['idRows'] >= 1 && $info['timeRows'] >= 1) $score += 100;
        if ($score > $bestScore) { $bestScore = $score; $bestSheet = $s; $bestInfo = $info; }
    }
    return ['sheet' => $bestSheet, 'score' => $bestScore, 'info' => $bestInfo];
}

function _set_last_parse_info($info) {
    $GLOBALS['_BB_LAST_PARSE_INFO'] = $info;
}
function _get_last_parse_info() {
    return $GLOBALS['_BB_LAST_PARSE_INFO'] ?? null;
}

function parse_biometric_xlsx($filePath) {
    $records = [];
    if (!file_exists($filePath) || !class_exists('ZipArchive')) return $records;

    $sheetPick = _xlsx_pick_best_sheet($filePath);
    $bestSheet = $sheetPick['sheet'];
    $rows = _xlsx_load_raw_rows($filePath, $bestSheet);
    if (!$rows) { _set_last_parse_info(['sheet' => $bestSheet] + $sheetPick); return $records; }
    $parseInfo = [
        'sheet' => $bestSheet,
        'sheet_score' => $sheetPick['score'],
        'sheet_info'  => $sheetPick['info'],
    ];

    $startDate = null; $endDate = null;
    // Separator: one or more of -, ., /, or whitespace between date parts
    $ds = '[\-\.\/\s]+';
    foreach ($rows as $rIdx => $cells) {
        foreach ($cells as $colIdx => $cellVal) {
            $cv = trim((string)$cellVal);
            if (strlen($cv) < 8) continue;
            // Full range: YYYY[sep]MM[sep]DD ~ YYYY[sep]MM[sep]DD
            if (preg_match('/(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})\s*[-~]+\s*(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})/i', $cv, $m)) {
                $startDate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                $endDate   = sprintf('%04d-%02d-%02d', $m[4], $m[5], $m[6]);
                break 2;
            }
        }
        // Also try col0+col1 joined
        $joined = trim($cells[0] ?? '').' '.trim($cells[1] ?? '');
        if (preg_match('/(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})\s*[-~]+\s*(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})/i', $joined, $m)) {
            $startDate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            $endDate   = sprintf('%04d-%02d-%02d', $m[4], $m[5], $m[6]);
            break;
        }
        // Single date fallback
        if (!$startDate && !empty($cells[1])) {
            $cv = trim((string)$cells[1]);
            if (preg_match('/(\d{4})'.$ds.'(\d{1,2})'.$ds.'(\d{1,2})/', $cv, $m)) {
                $startDate = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                break;
            }
        }
    }

    $allReportFormat = _detect_all_report_format($rows);

    if ($allReportFormat['is_all_report']) {
        $parseInfo['format'] = 'all_report_matrix';
        $parseInfo['all_report_fmt'] = $allReportFormat;
        _set_last_parse_info($parseInfo);
        return _parse_all_report_matrix($rows, $allReportFormat);
    }

    $employees = [];
    $lastEmpIdx = null;
    $dayNamePattern = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Lun|Mar|Mie|Jue|Vie|Sab|Dom)$/i';
    $parseInfo['format'] = 'classic_per_emp_block';

    foreach ($rows as $rIdx => $cells) {
        $col0 = trim($cells[0] ?? '');
        $col1 = trim($cells[1] ?? '');

        if (strcasecmp($col0, 'ID') === 0) {
            $empId = '';
            $empName = null;
            $nameLabelIdx = null;
            // Find 'Name' label position
            foreach ($cells as $i => $v) {
                $vv = trim($v);
                if (strcasecmp($vv, 'Name') === 0) { $nameLabelIdx = $i; break; }
            }
            // Name value: first non-empty cell AFTER the Name label (may have blanks between label and value)
            if ($nameLabelIdx !== null) {
                foreach ($cells as $i => $v) {
                    if ($i <= $nameLabelIdx) continue;
                    $vv = trim((string)$v);
                    if ($vv === '') continue;
                    // Stop if we hit another known label (Dept, etc.)
                    if (preg_match('/^(Dept\.?|Department|Section|Period|Not\s*Set)/i', $vv)) break;
                    $empName = $vv;
                    break;
                }
            }
            // Find employee ID: scan for first non-empty, non-label value after col0
            $skipLabels = ['name','dept','dept.','not set','department','section','period'];
            foreach ($cells as $i => $v) {
                if ($i === 0) continue;
                $vv = trim((string)$v);
                if ($vv === '') continue;
                if (in_array(mb_strtolower($vv), $skipLabels)) continue;
                if (preg_match('/(name|dept|department|section|set)/i', $vv) && strlen($vv) <= 20) continue;
                $empId = $vv;
                break;
            }
            $employees[] = [
                'emp_id' => $empId,
                'name' => $empName,
                'day_numbers' => [],
                'day_names' => [],
                'time_rows' => []
            ];
            $lastEmpIdx = count($employees) - 1;
            continue;
        }

        if ($lastEmpIdx !== null) {
            $countDayNum = 0; $countDayName = 0;
            // Check ALL columns including col0 for day numbers/names
            foreach ($cells as $i => $v) {
                $vv = trim((string)$v);
                if ($vv === '') continue;
                if (preg_match('/^\d{1,2}$/', $vv) && (int)$vv >= 1 && (int)$vv <= 31) $countDayNum++;
                if (preg_match($dayNamePattern, $vv)) $countDayName++;
            }

            // Day number row: col0 is blank OR is itself a day number (1-31), and >=3 day numbers found
            $col0IsBlank = ($col0 === '');
            $col0IsDayNum = (preg_match('/^\d{1,2}$/', $col0) && (int)$col0 >= 1 && (int)$col0 <= 31);
            $col0IsDayName = (bool)preg_match($dayNamePattern, $col0);
            $col0IsTime = (bool)preg_match('/\d{1,2}:\d{2}/', $col0) || (is_numeric($col0) && (float)$col0 >= 0 && (float)$col0 < 2 && $col0 !== '');

            if (($col0IsBlank || $col0IsDayNum) && $countDayNum >= 3 && $countDayNum >= $countDayName) {
                $nums = [];
                foreach ($cells as $i => $v) {
                    $v = trim($v);
                    if (preg_match('/^\d{1,2}$/', $v) && (int)$v >= 1 && (int)$v <= 31) $nums[$i] = (int)$v;
                }
                if ($nums) { $employees[$lastEmpIdx]['day_numbers'] = $nums; }
                continue;
            }

            // Day name row: col0 is blank OR is itself a day name
            if (($col0IsBlank || $col0IsDayName) && $countDayName >= 3 && $countDayName > $countDayNum) {
                $names = [];
                foreach ($cells as $i => $v) {
                    $v = trim($v);
                    if (preg_match($dayNamePattern, $v)) $names[$i] = $v;
                }
                if ($names) { $employees[$lastEmpIdx]['day_names'] = $names; }
                continue;
            }

            // Time row: col0 is blank OR col0 itself contains time content
            if (($col0IsBlank || $col0IsTime) && _cells_have_time_content($cells)) {
                $employees[$lastEmpIdx]['time_rows'][] = $cells;
                continue;
            }
        }
    }

    $startDT = $startDate ? new DateTime($startDate) : null;
    $parseInfo['employees_found'] = count($employees);

    foreach ($employees as $emp) {
        if (empty($emp['day_numbers'])) continue;
        $colsByDay = [];
        foreach ($emp['day_numbers'] as $colIdx => $dayNum) {
            $colsByDay[$dayNum] = $colIdx;
        }
        foreach ($colsByDay as $dayNum => $colIdx) {
            $dateStr = null;
            if ($startDT) {
                $d = clone $startDT;
                $targetDay = $dayNum;
                $currentDay = (int)$d->format('j');
                $offset = $targetDay - $currentDay;
                $d->modify("+$offset days");
                $dateStr = $d->format('Y-m-d');
            }
            $rawAll = [];
            foreach ($emp['time_rows'] as $trow) {
                $raw = isset($trow[$colIdx]) ? trim((string)$trow[$colIdx]) : '';
                if ($raw === '') continue;
                $converted = _excel_serial_to_time($raw);
                if ($converted !== null) $rawAll[] = $converted;
            }
            if (!$rawAll) continue;

            $punches = [];
            foreach ($rawAll as $rawStr) {
                $pp = preg_split('/[\s;,\r\n\t]+/', $rawStr, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($pp as $p) {
                    if (preg_match('/^(\d{1,2}:\d{2}(:\d{2})?)$/', trim($p), $m)) {
                        $t = $m[1];
                        if (substr_count($t, ':') === 1) $t .= ':00';
                        $punches[] = $t;
                    }
                }
            }
            if (!$punches) continue;

            sort($punches);
            $records[] = [
                'employee_id' => $emp['emp_id'],
                'name' => $emp['name'],
                'date' => $dateStr,
                'day_num' => $dayNum,
                'time_in' => $punches[0] ?? null,
                'time_out' => count($punches) > 1 ? end($punches) : null,
                'punches' => count($punches)
            ];
        }
    }
    $parseInfo['records_parsed'] = count($records);
    _set_last_parse_info($parseInfo);
    return $records;
}

function _parse_date_header($txt) {
    $t = trim((string)$txt);
    if ($t === '') return null;
    $t = preg_replace('/\s+(Mon|Tue|Wed|Thu|Fri|Sat|Sun|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Lun|Mar|Mie|Jue|Vie|Sab|Dom)$/i', '', $t);
    $t = trim($t);
    $y = $mo = $d = null;
    if (preg_match('/^(\d{4})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})$/', $t, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
    elseif (preg_match('/^(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{4})$/', $t, $m)) { $mo = (int)$m[1]; $d = (int)$m[2]; $y = (int)$m[3]; }
    elseif (preg_match('/^(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{2})$/', $t, $m)) { $mo = (int)$m[1]; $d = (int)$m[2]; $y = 2000 + (int)$m[3]; if ($y > (int)date('Y') + 1) $y -= 100; }
    elseif (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+(\d{1,2})\s*[,\/\-]?\s*(\d{4})$/i', $t, $m)) {
        $mo = array_search(ucfirst(strtolower($m[1])), ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
        if ($mo !== false) { $mo++; $d = (int)$m[2]; $y = (int)$m[3]; }
    }
    elseif (preg_match('/^(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s*[,\/\-]?\s*(\d{4})$/i', $t, $m)) {
        $mo = array_search(ucfirst(strtolower($m[2])), ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
        if ($mo !== false) { $mo++; $d = (int)$m[1]; $y = (int)$m[3]; }
    }
    if ($y && $mo && $d && checkdate($mo, $d, $y)) {
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return null;
}

function _detect_all_report_format($rows) {
    $result = ['is_all_report' => false, 'header_row_idx' => null, 'id_col' => null, 'name_col' => null, 'date_cols' => []];
    $idPat = '/(ID|EMP\s*ID|EMPLOYEE\s*ID|USER\s*ID|NO\.|BADGENUMBER|BADGE\s*NO\.?|EMPNO|EMPLOYEE\s*NO\.?|\bNO\b|^\s*#\s*$)/i';
    $namePat = '/(NAME|EMP\s*NAME|EMPLOYEE\s*NAME|USER\s*NAME|USERNAME|EMPNAME|FULL\s*NAME|EMPLOYEE\s*FULLNAME)/i';
    $bestScore = 0;
    $bestHit = null;

    foreach ($rows as $rIdx => $cells) {
        $normalized = [];
        $maxC = 0;
        foreach ($cells as $i => $v) { $normalized[$i] = trim((string)$v); if ($i > $maxC) $maxC = $i; }
        if ($maxC < 4) continue;

        $hasId = null;
        $hasName = null;
        $dateColIso = [];
        $nonEmpty = 0;
        foreach ($normalized as $i => $v) {
            if ($v === '') continue;
            $nonEmpty++;
            if ($hasId === null && preg_match($idPat, $v) && strlen($v) <= 40) { $hasId = $i; continue; }
            if ($hasName === null && preg_match($namePat, $v) && strlen($v) <= 40) { $hasName = $i; continue; }
            $iso = _parse_date_header($v);
            if ($iso !== null) { $dateColIso[$i] = $iso; continue; }
            if (preg_match('/^\d{1,2}$/', $v) && (int)$v >= 1 && (int)$v <= 31) {
            }
        }
        $dateCount = count($dateColIso);
        if ($hasId !== null && $hasName !== null && $dateCount >= 2 && $dateCount > $bestScore) {
            $bestScore = $dateCount;
            $bestHit = ['r' => $rIdx, 'id' => $hasId, 'name' => $hasName, 'dates' => $dateColIso];
        }
        if ($bestScore >= 5) break;
    }

    if ($bestHit !== null) {
        $result['is_all_report'] = true;
        $result['header_row_idx'] = $bestHit['r'];
        $result['id_col'] = $bestHit['id'];
        $result['name_col'] = $bestHit['name'];
        $result['date_cols'] = $bestHit['dates'];
    }
    return $result;
}

function _parse_all_report_matrix($rows, $fmt) {
    $records = [];
    $headerSeen = false;
    foreach ($rows as $rIdx => $cells) {
        if (!$headerSeen) {
            if ($rIdx == $fmt['header_row_idx']) $headerSeen = true;
            continue;
        }
        if ($rIdx <= $fmt['header_row_idx']) continue;
        $cellsNorm = [];
        foreach ($cells as $i => $v) $cellsNorm[$i] = trim((string)$v);
        $empId = $cellsNorm[$fmt['id_col']] ?? '';
        $empName = $cellsNorm[$fmt['name_col']] ?? '';
        // Only strip leading non-alphanumeric symbols (like #, -) but preserve letter+number prefixes (EMP001, E001, etc.)
        $empId = preg_replace('/^[#\-\s]+/', '', $empId);
        if ($empId === '' && $empName === '') continue;
        if (preg_match('/^(TOTAL|SUBTOTAL|SUMMARY|REPORT|DEPARTMENT|COMPANY|USER\s*COUNT)$/i', $empName)) continue;
        if (preg_match('/^(TOTAL|SUBTOTAL|SUMMARY)$/i', $empId)) continue;

        foreach ($fmt['date_cols'] as $colIdx => $dateIso) {
            $cellVal = $cellsNorm[$colIdx] ?? '';
            if ($cellVal === '') continue;
            $punches = [];
            $pp = preg_split('/[\s;,\r\n\t]+/', $cellVal, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($pp as $p) {
                $conv = _excel_serial_to_time($p);
                if ($conv !== null) {
                    $parts = preg_split('/\s+/', $conv, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($parts as $t) {
                        if (preg_match('/^(\d{1,2}:\d{2}(:\d{2})?)$/', trim($t), $m2)) {
                            $t2 = $m2[1];
                            if (substr_count($t2, ':') === 1) $t2 .= ':00';
                            $punches[] = $t2;
                        }
                    }
                }
            }
            if (!$punches) continue;
            sort($punches);
            $records[] = [
                'employee_id' => $empId,
                'name' => $empName,
                'date' => $dateIso,
                'time_in' => $punches[0],
                'time_out' => count($punches) > 1 ? end($punches) : null,
                'punches' => count($punches),
            ];
        }
    }
    return $records;
}

function format_time($time) {
    if (!$time) return '-';
    try {
        $dt = new DateTime($time);
        return $dt->format('h:i A');
    } catch (Exception $e) { return $time; }
}
function format_date($date) {
    if (!$date) return '-';
    try {
        $dt = new DateTime($date);
        return $dt->format('M d, Y');
    } catch (Exception $e) { return $date; }
}
function get_attendance_status($time_in, $shift_start) {
    if (!$time_in || !$shift_start) return 'N/A';
    try {
        $in = new DateTime($time_in); $st = new DateTime($shift_start);
        if ($in > $st) {
            $diff = $in->getTimestamp() - $st->getTimestamp();
            $mins = floor($diff / 60);
            return "Late ($mins mins)";
        }
    } catch (Exception $e) {}
    return 'On Time';
}

function calculate_hours_worked($time_in, $time_out) {
    if (!$time_in || !$time_out) return null;
    try {
        $in_dt = new DateTime($time_in);
        $out_dt = new DateTime($time_out);
        if ($out_dt < $in_dt) {
            $out_dt->modify('+1 day');
        }
        $diff = $out_dt->getTimestamp() - $in_dt->getTimestamp();
        if ($diff < 0) return null;
        $hours = $diff / 3600;

        // Big Brew shift duration is 9 hours with a 1-hour unpaid lunch break.
        // For shifts of 5 hours or more, deduct 1 hour for lunch break.
        if ($hours >= 5.0) {
            $hours = max(0, $hours - 1.0);
        }

        return round($hours, 2);
    } catch (Throwable $e) { return null; }
}

/**
 * Calculate payable regular hours based on Big Brew store policy:
 * - Standard daily work is 8.00 hours (9-hour shift minus 1-hour unpaid lunch break).
 * - Shortfall of 5 minutes or less (1 to 5 minutes): Grace period (0 deduction, counted as full 8.00 hours).
 * - Shortfall of 6 to 30 minutes: Deducts 30 minutes (0.5 hr, or hourly rate / 2) -> 7.50 hours paid.
 * - Shortfall of 31 to 60 minutes: Deducts 1.0 hour -> 7.00 hours paid.
 * - Each 30-minute block beyond grace period deducts 0.5 hour.
 */
function calculate_regular_hours($hours_worked, $time_in = null, $time_out = null) {
    if ($hours_worked === null || (float)$hours_worked <= 0) return 0.00;
    $h = (float)$hours_worked;
    if ($h >= 8.00) return 8.00;

    $shortfall_mins = null;
    if ($time_in && $time_out) {
        try {
            $in_dt = new DateTime($time_in);
            $out_dt = new DateTime($time_out);
            if ($out_dt < $in_dt) $out_dt->modify('+1 day');
            $diff_sec = $out_dt->getTimestamp() - $in_dt->getTimestamp();
            if ($diff_sec >= 18000) { // 5 hours or more -> deduct 1 hour (3600s) lunch
                $diff_sec -= 3600;
            }
            $shortfall_sec = 28800 - $diff_sec; // 8 hours = 28800s
            if ($shortfall_sec <= 0) return 8.00;
            $shortfall_mins = $shortfall_sec / 60.0;
        } catch (Throwable $e) {}
    }

    if ($shortfall_mins === null) {
        $shortfall_mins = round((8.00 - $h) * 60, 2);
    }

    // 5 minutes or less short: Count as full 8.00 hours (5-minute grace period)
    if ($shortfall_mins <= 5.0) {
        return 8.00;
    }

    // 6 to 30 minutes short: deduct 30 minutes (0.5 hour) -> 7.50 hours
    // Beyond 30 minutes: deduct by 30-minute blocks
    $deduction_hours = ceil($shortfall_mins / 30.0) * 0.5;
    $reg = max(0.00, 8.00 - $deduction_hours);
    return round($reg, 2);
}

