<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$role = strtolower($_GET['role'] ?? '');
$format = strtolower($_GET['format'] ?? 'csv');

$templates = [
  // Unified headers: clean and consistent across roles
  // System will generate usernames/IDs automatically; no Password column in templates
  'student' => ['FirstName','LastName','Gender','Course','YearLevel'],
  'faculty' => ['FirstName','LastName','Department'],
  'dean'    => ['FirstName','LastName','Department'],
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
fputcsv($out, $templates[$role]);
// Provide one sample row as a guide for formatting
$sample = [
  'student' => ['Juan','Dela Cruz','Male','BSIT','1st Year'],
  'faculty' => ['Maria','Santos','Technology'],
  'dean'    => ['Jose','Rizal','Technology'],
];
if (isset($sample[$role])) { fputcsv($out, $sample[$role]); }
fclose($out);
exit;
