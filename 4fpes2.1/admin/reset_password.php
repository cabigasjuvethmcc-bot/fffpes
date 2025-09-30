<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_password_resets.php');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    header('Location: manage_password_resets.php');
    exit();
}

$request_id = (int)($_POST['request_id'] ?? 0);
$identifier = sanitizeInput($_POST['identifier'] ?? '');
$role = sanitizeInput($_POST['role'] ?? ''); // Expected: Student | Faculty | Dean (any case)

if (!$request_id || !$identifier || !$role) {
    header('Location: manage_password_resets.php');
}

try {
    $pdo->beginTransaction();

    // Normalize role to canonical case and map to table/id field
    $role_map = [
        'student' => 'Student',
        'faculty' => 'Faculty',
        'dean' => 'Dean',
        'Student' => 'Student',
        'Faculty' => 'Faculty',
        'Dean' => 'Dean',
    ];
    $role_norm = $role_map[$role] ?? $role_map[strtolower($role)] ?? '';

    // Map role to table and id field
    $lookup = [
        'Student' => ['table' => 'students', 'id_field' => 'student_id'],
        'Faculty' => ['table' => 'faculty', 'id_field' => 'employee_id'],
        'Dean'    => ['table' => 'deans',    'id_field' => 'employee_id'],
    ];

    if (!isset($lookup[$role_norm])) {
        throw new Exception('Invalid role');
    }

    $info = $lookup[$role_norm];

    // Ensure deans table exists if role is Dean (some setups create it lazily)
    if ($role_norm === 'Dean') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(20) UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    // Find the user_id via join on the role-specific table
    $sql = "SELECT u.id AS user_id
            FROM users u
            INNER JOIN {$info['table']} t ON u.id = t.user_id
            WHERE t.{$info['id_field']} = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identifier]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new Exception('Identifier not found');
    }

    $user_id = (int)$row['user_id'];

    // Reset password to '123' (hashed)
    $new_hash = password_hash('123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->execute([$new_hash, $user_id]);

    // Mark the request as Resolved
    $stmt = $pdo->prepare('UPDATE password_reset_requests SET status = "Resolved" WHERE id = ?');
    $stmt->execute([$request_id]);

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

header('Location: manage_password_resets.php');
exit();
