<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$role = strtolower($_GET['role'] ?? '');
$format = strtolower($_GET['format'] ?? 'csv');

$templates = [
  // Updated headers with optional Password column
  // Note: EmployeeID is optional for Faculty/Dean; system will auto-generate if left blank
  'student' => ['FirstName','LastName','Gender','Course','YearLevel','Password'],
  'faculty' => ['FirstName','LastName','EmployeeID','Department','AssignedSubjects','Password'],
  'dean'    => ['FirstName','LastName','EmployeeID','Department','Password'],
];

if (!isset($templates[$role])) {
  http_response_code(400);
  echo 'Invalid role';
  exit;
}

// Only System Admins can get dean template
$deptAdminList = ['Business','Education','Technology'];
$isSystemAdmin = !in_array($_SESSION['department'] ?? '', $deptAdminList, true);
if ($role === 'dean' && !$isSystemAdmin) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

if ($format !== 'csv') { $format = 'csv'; }
$filename = $role . '_template.' . $format;
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');
// Write instruction notes above headers (prefixed with # for clarity)
$notes = [
  'student' => [
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    "Columns: FirstName,LastName,Gender,Course,YearLevel,Password (optional). If Password is blank, the default 'password123' will be used."
  ],
  'faculty' => [
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    "Columns: FirstName,LastName,EmployeeID (optional),Department,AssignedSubjects (optional),Password (optional). If EmployeeID is blank, the system will auto-generate one. If Password is blank, 'password123' will be used."
  ],
  'dean' => [
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    "Columns: FirstName,LastName,EmployeeID (optional),Department,Password (optional). If EmployeeID is blank, the system will auto-generate one. If Password is blank, 'password123' will be used."
  ],
];
foreach (($notes[$role] ?? []) as $line) { fputcsv($out, ['# ' . $line]); }
// Blank line between notes and header
fputcsv($out, ['#']);

// Headers
fputcsv($out, $templates[$role]);

// Provide one sample row as a guide for formatting
$sample = [
  'student' => ['Juan','Dela Cruz','Male','BSIT','1st Year',''],
  'faculty' => ['Maria','Santos','FAC-001','Technology','CS101; CS102',''],
  'dean'    => ['Jose','Rizal','DEAN-001','Technology',''],
];
if (isset($sample[$role])) { fputcsv($out, $sample[$role]); }
fclose($out);
exit;
