<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

header('Content-Type: application/json');

// Helpers
function json_fail($message) {
  echo json_encode(['success' => false, 'message' => $message]);
  exit;
}

function normalize_header($h) { return strtolower(trim($h)); }

function read_csv_rows($tmpPath) {
  $rows = [];
  if (($handle = fopen($tmpPath, 'r')) !== false) {
    $headers = fgetcsv($handle);
    if ($headers === false) { return [[], []]; }
    $map = array_map('normalize_header', $headers);
    while (($data = fgetcsv($handle)) !== false) {
      $row = [];
      foreach ($map as $i => $key) { $row[$key] = $data[$i] ?? null; }
      $rows[] = $row;
    }
    fclose($handle);
    return [$map, $rows];
  }
  return [[], []];
}

// Very basic XLSX reader for first worksheet (best effort)
function read_xlsx_rows($tmpPath) {
  if (!class_exists('ZipArchive')) { return [[], []]; }
  $zip = new ZipArchive();
  if ($zip->open($tmpPath) !== true) { return [[], []]; }
  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
  $shared = [];
  if ($sharedStringsXml) {
    $sx = simplexml_load_string($sharedStringsXml);
    foreach ($sx->si as $si) {
      // concatenate t nodes within si
      $texts = [];
      foreach ($si->t as $t) { $texts[] = (string)$t; }
      if (empty($texts) && isset($si->r)) {
        foreach ($si->r as $r) { $texts[] = (string)$r->t; }
      }
      $shared[] = implode('', $texts);
    }
  }
  if (!$sheetXml) { $zip->close(); return [[], []]; }
  $sx = simplexml_load_string($sheetXml);
  $sx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $rows = [];
  foreach ($sx->sheetData->row as $r) {
    $row = [];
    foreach ($r->c as $c) {
      $t = (string)$c['t'];
      $v = isset($c->v) ? (string)$c->v : '';
      if ($t === 's') { // shared string
        $idx = is_numeric($v) ? intval($v) : -1;
        $row[] = $idx >= 0 && isset($shared[$idx]) ? $shared[$idx] : '';
      } else {
        $row[] = $v;
      }
    }
    $rows[] = $row;
  }
  $zip->close();
  if (empty($rows)) { return [[], []]; }
  // First row headers
  $headers = array_map('normalize_header', $rows[0]);
  $assocRows = [];
  for ($i = 1; $i < count($rows); $i++) {
    $assoc = [];
    foreach ($headers as $idx => $key) { $assoc[$key] = $rows[$i][$idx] ?? null; }
    $assocRows[] = $assoc;
  }
  return [$headers, $assocRows];
}

// ID generation helpers for Faculty/Deans
function extract_numeric_from_id($id, $prefix) {
  $pattern = '/^' . preg_quote($prefix, '/') . '0*(\d+)$/i';
  if (preg_match($pattern, $id, $m)) { return (int)$m[1]; }
  return null;
}

function collect_used_numbers(PDO $pdo, $role) {
  // Determine source table and prefix (hyphenated)
  $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
  $table  = ($role === 'faculty') ? 'faculty' : 'deans';
  $nums = [];
  // From role table
  try {
    $stmt = $pdo->query("SELECT employee_id FROM $table");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $n = extract_numeric_from_id((string)$row['employee_id'], $prefix);
      if ($n !== null) { $nums[$n] = true; }
    }
  } catch (PDOException $e) { /* ignore if table missing; created later */ }
  // From users.username as well (since username == employee_id for these roles)
  try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE role = ? AND username LIKE ?");
    $stmt->execute([$role, $prefix.'%']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $n = extract_numeric_from_id((string)$row['username'], $prefix);
      if ($n !== null) { $nums[$n] = true; }
    }
  } catch (PDOException $e) { /* ignore */ }
  // Return sorted unique numeric keys
  $keys = array_keys($nums);
  sort($keys, SORT_NUMERIC);
  return $keys;
}

function allocate_next_id(PDO $pdo, $role) {
  $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
  $pad = 3;
  $used = collect_used_numbers($pdo, $role);
  // Find smallest missing positive integer
  $candidate = 1;
  $i = 0; $len = count($used);
  while ($i < $len) {
    if ($used[$i] === $candidate) { $candidate++; $i++; }
    elseif ($used[$i] < $candidate) { $i++; }
    else { break; } // found a gap at $candidate
  }
  // Compose ID
  $id = sprintf('%s%0'.(int)$pad.'d', $prefix, $candidate);
  return [$id, $candidate, $prefix, $pad];
}

function format_id_ranges(array $numbers, $prefix, $pad = 3) {
  if (empty($numbers)) return '';
  sort($numbers, SORT_NUMERIC);
  $ranges = [];
  $start = $numbers[0];
  $prev = $start;
  for ($i = 1; $i < count($numbers); $i++) {
    $n = $numbers[$i];
    if ($n === $prev + 1) { $prev = $n; continue; }
    // close range
    if ($start === $prev) { $ranges[] = sprintf('%s%0'.(int)$pad.'d', $prefix, $start); }
    else { $ranges[] = sprintf('%s%0'.(int)$pad.'d–%s%0'.(int)$pad.'d', $prefix, $start, $prefix, $prev); }
    $start = $prev = $n;
  }
  // last range
  if ($start === $prev) { $ranges[] = sprintf('%s%0'.(int)$pad.'d', $prefix, $start); }
  else { $ranges[] = sprintf('%s%0'.(int)$pad.'d–%s%0'.(int)$pad.'d', $prefix, $start, $prefix, $prev); }
  return implode(', ', $ranges);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_fail('Invalid method'); }
  if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { json_fail('Invalid CSRF token'); }

  $role = strtolower(trim($_POST['role'] ?? ''));
  if (!in_array($role, ['student','faculty','dean'], true)) { json_fail('Invalid role'); }

  // Determine admin scope
  $deptAdminList = ['Business','Education','Technology'];
  $adminDept = $_SESSION['department'] ?? '';
  $isSystemAdmin = !in_array($adminDept, $deptAdminList, true);
  if (!$isSystemAdmin && $role === 'dean') { json_fail('Department Admins cannot upload Deans'); }

  $applyDept = '';
  if ($isSystemAdmin) {
    $applyDept = trim($_POST['department'] ?? '');
    if ($role === 'student' && $applyDept === '') {
      // optional; students may also include department in file, but template doesn't have it
      // keep empty and will fallback per-row if provided (ignored for student)
    }
  } else {
    $applyDept = $adminDept; // Enforce department for dept admins
  }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { json_fail('No file uploaded'); }
  $file = $_FILES['file'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $tmpPath = $file['tmp_name'];

  // Parse
  if ($ext === 'csv') {
    list($headers, $rows) = read_csv_rows($tmpPath);
  } elseif ($ext === 'xlsx') {
    list($headers, $rows) = read_xlsx_rows($tmpPath);
  } else {
    json_fail('Unsupported file type. Use .csv or .xlsx');
  }
  if (empty($headers)) { json_fail('File appears empty or unreadable'); }

  // Ensure role-specific required columns (new minimal templates)
  $required = [
    // Headers normalized to lowercase by parser
    'student' => ['firstname','lastname','gender','course','yearlevel'],
    'faculty' => ['firstname','lastname','department'],
    'dean'    => ['firstname','lastname','department'],
  ];
  $req = $required[$role];
  $missing = array_diff($req, $headers);
  if (!empty($missing)) { json_fail('Missing required columns: ' . implode(', ', $missing)); }

  // Ensure role tables exist (best effort)
  try {
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS deans ( id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, employee_id VARCHAR(20) UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Ensure must_change_password exists on users
    $GLOBALS['pdo']->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    // Shared ID sequence tracker (keeps last allocated number). Note: we still fill gaps by scanning.
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS id_sequences (
      role ENUM('faculty','dean') PRIMARY KEY,
      last_num INT NOT NULL DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  } catch (PDOException $e) { /* ignore */ }

  $pdo->beginTransaction();
  $ok = 0; $skipped = 0; $errors = [];
  $summaryData = [
    'student' => ['male' => 0, 'female' => 0, 'male_first' => null, 'male_last' => null, 'female_first' => null, 'female_last' => null],
    'faculty' => ['count' => 0, 'nums' => []],
    'dean' => ['count' => 0, 'nums' => []],
  ];

  // Helpers for student ID generation by gender
  $formatStudentId = function($prefix, $num) {
    return sprintf('%s-%03d', $prefix, (int)$num);
  };
  $nextStudentNumber = function($gender) use ($pdo) {
    $prefix = (strcasecmp($gender, 'male') === 0) ? '222' : '221';
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(student_id, 5) AS UNSIGNED)) AS maxn FROM students WHERE student_id LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $row = $stmt->fetch();
    $max = (int)($row['maxn'] ?? 0);
    return [$prefix, $max + 1];
  };

  foreach ($rows as $index => $row) {
    // Trim all values
    foreach ($row as $k => $v) { $row[$k] = trim((string)$v); }

    // Row-level validation
    $reason = '';
    foreach ($req as $col) { if ($col !== 'password' && empty($row[$col])) { $reason = 'Missing field: ' . $col; break; } }

    // Determine department
    $rowDept = $applyDept;
    if ($role === 'faculty' || $role === 'dean') { $rowDept = $row['department'] ?? $applyDept; }

    if (!$isSystemAdmin) {
      if (strcasecmp($rowDept, $adminDept) !== 0) { $reason = 'Department mismatch'; }
    }

    if ($reason !== '') { $skipped++; $errors[] = [$index+2, $reason]; continue; }

    try {
      // Prepare role-specific IDs and username rules
      $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
      $emailVal = $row['email'] ?? null; // optional/unused in new templates
      $passwordPlain = $row['password'] ?? '';
      if ($passwordPlain === '') { $passwordPlain = 'changeme123'; }
      $mustChange = ($passwordPlain === 'changeme123') ? 1 : 0;

      if ($role === 'student') {
        $gender = strtolower($row['gender'] ?? '');
        if (!in_array($gender, ['male','female'], true)) { throw new Exception('Invalid gender (must be Male or Female)'); }
        list($prefix, $next) = $nextStudentNumber($gender);
        $studentId = $formatStudentId($prefix, $next);

        // Uniqueness checks
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM students WHERE student_id = ?');
        $stmt->execute([$studentId]);
        if ($stmt->fetch()['c'] > 0) { throw new Exception('Duplicate generated student_id'); }

        $username = $studentId; // username equals Student ID
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()['c'] > 0) { throw new Exception('Duplicate username'); }

        $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
        $insertUser = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, department, must_change_password) VALUES (?,?,?,?,?,?,?)');
        $insertUser->execute([$username, $hash, $role, $fullName, $emailVal, $rowDept, $mustChange]);
        $userId = (int)$pdo->lastInsertId();

        $program = $row['course'];
        $yr = $row['yearlevel'];
        $stmt = $pdo->prepare('INSERT INTO students (user_id, student_id, year_level, program) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $studentId, $yr, $program]);

        // Update summary
        if (strcasecmp($gender,'male')===0) {
          $summaryData['student']['male']++;
          $summaryData['student']['male_first'] = $summaryData['student']['male_first'] ?: $studentId;
          $summaryData['student']['male_last'] = $studentId;
        } else {
          $summaryData['student']['female']++;
          $summaryData['student']['female_first'] = $summaryData['student']['female_first'] ?: $studentId;
          $summaryData['student']['female_last'] = $studentId;
        }
      } elseif ($role === 'faculty' || $role === 'dean') {
        // Auto-generate next available ID (fill gaps first)
        list($genId, $num, $prefix, $pad) = allocate_next_id($pdo, $role);

        // Ensure uniqueness at time of allocation; if collision, iterate forward
        while (true) {
          // Check users
          $stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
          $stmt->execute([$genId]);
          $userDup = ($stmt->fetch()['c'] ?? 0) > 0;
          // Check role table
          if ($role === 'faculty') {
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM faculty WHERE employee_id = ?');
          } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM deans WHERE employee_id = ?');
          }
          $stmt->execute([$genId]);
          $roleDup = ($stmt->fetch()['c'] ?? 0) > 0;
          if (!$userDup && !$roleDup) { break; }
          // advance to next number
          $num++;
          $genId = sprintf('%s%0'.(int)$pad.'d', $prefix, $num);
        }

        $username = $genId;

        $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
        $insertUser = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, department, must_change_password) VALUES (?,?,?,?,?,?,?)');
        $insertUser->execute([$username, $hash, $role, $fullName, $emailVal, $rowDept, $mustChange]);
        $userId = (int)$pdo->lastInsertId();

        if ($role === 'faculty') {
          $stmt = $pdo->prepare('INSERT INTO faculty (user_id, employee_id) VALUES (?,?)');
          $stmt->execute([$userId, $genId]);
          // Update shared sequence tracker
          try {
            $pdo->prepare("INSERT INTO id_sequences (role, last_num) VALUES ('faculty', ?) ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))")
                ->execute([$num]);
          } catch (PDOException $e) { /* non-fatal */ }
          $summaryData['faculty']['count']++;
          $summaryData['faculty']['nums'][] = $num;
        } else {
          $stmt = $pdo->prepare('INSERT INTO deans (user_id, employee_id) VALUES (?,?)');
          $stmt->execute([$userId, $genId]);
          // Update shared sequence tracker
          try {
            $pdo->prepare("INSERT INTO id_sequences (role, last_num) VALUES ('dean', ?) ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))")
                ->execute([$num]);
          } catch (PDOException $e) { /* non-fatal */ }
          $summaryData['dean']['count']++;
          $summaryData['dean']['nums'][] = $num;
        }
      }

      $ok++;
    } catch (Exception $ex) {
      $skipped++;
      $errors[] = [$index+2, $ex->getMessage()];
      continue;
    }
  }

  $pdo->commit();

  // Error report file if any
  $errorReportPath = '';
  if ($skipped > 0) {
    $dir = __DIR__ . '/../reports';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $fname = 'bulk_errors_' . date('Ymd_His') . '.csv';
    $full = $dir . '/' . $fname;
    $fp = fopen($full, 'w');
    fputcsv($fp, ['row_number','reason']);
    foreach ($errors as $er) { fputcsv($fp, $er); }
    fclose($fp);
    // Build a web path like '/FPES/reports/<file>' to avoid incorrect '../' resolution
    $apiWebDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // e.g., /FPES/api
    $baseWeb = rtrim(str_replace('\\','/', dirname($apiWebDir)), '/'); // -> /FPES
    if ($baseWeb === '' || $baseWeb === '.') { $baseWeb = '/'; }
    $errorReportPath = $baseWeb . '/reports/' . $fname;
  }

  // Build detailed summary
  $summary = sprintf('%d records uploaded successfully, %d errors found', $ok, $skipped);
  if ($role === 'student') {
    $m = $summaryData['student']['male'];
    $f = $summaryData['student']['female'];
    if ($m > 0 || $f > 0) {
      $details = [];
      if ($m > 0) { $details[] = sprintf('%d Male: IDs %s to %s', $m, $summaryData['student']['male_first'] ?? '-', $summaryData['student']['male_last'] ?? '-'); }
      if ($f > 0) { $details[] = sprintf('%d Female: IDs %s to %s', $f, $summaryData['student']['female_first'] ?? '-', $summaryData['student']['female_last'] ?? '-'); }
      $summary .= ' — ' . implode(', ', $details);
    }
  } elseif ($role === 'faculty') {
    $ranges = format_id_ranges($summaryData['faculty']['nums'], 'FAC-', 3);
    if ($summaryData['faculty']['count'] > 0) {
      $summary .= sprintf(' — %d Faculty added (IDs %s)', $summaryData['faculty']['count'], $ranges);
    } else {
      $summary .= ' — 0 Faculty added';
    }
  } elseif ($role === 'dean') {
    $ranges = format_id_ranges($summaryData['dean']['nums'], 'DEAN-', 3);
    if ($summaryData['dean']['count'] > 0) {
      $summary .= sprintf(' — %d Deans added (IDs %s)', $summaryData['dean']['count'], $ranges);
    } else {
      $summary .= ' — 0 Deans added';
    }
  }
  echo json_encode([
    'success' => true,
    'summary' => $summary,
    'error_report' => $errorReportPath,
  ]);
  exit;
} catch (Exception $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  json_fail('Server error');
}
