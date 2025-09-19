<?php
require_once '../config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        try {
            // Validate CSRF token
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }
            
            // Get and validate form data
            $username = sanitizeInput($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitizeInput($_POST['role'] ?? '');
            $full_name = sanitizeInput($_POST['full_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $department = sanitizeInput($_POST['department'] ?? '');
            // Normalize department values to canonical names used across the system
            $deptMap = [
                'School of Technology' => 'Technology',
                'School of Business' => 'Business',
                'School of Education' => 'Education',
                'SOT' => 'Technology',
                'SOB' => 'Business',
                'SOE' => 'Education',
            ];
            if (isset($deptMap[$department])) {
                $department = $deptMap[$department];
            }
            
            if (!$username || !$password || !$role || !$full_name || !$department) {
                throw new Exception('All required fields must be filled');
            }
            
            if (!in_array($role, ['student', 'faculty', 'dean', 'admin'])) {
                throw new Exception('Invalid role selected');
            }
            
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                throw new Exception('Username already exists');
            }
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Ensure mapping table exists BEFORE starting transaction (avoid implicit commits from DDL)
            if ($role === 'faculty') {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_subjects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        faculty_user_id INT NOT NULL,
                        department VARCHAR(100),
                        subject_code VARCHAR(50),
                        subject_name VARCHAR(255),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (faculty_user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (PDOException $e) {
                    // Non-fatal: subject assignment will be skipped if table missing
                }
                // Also ensure the subjects table exists BEFORE starting the transaction to avoid implicit commits
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        department VARCHAR(100) NOT NULL,
                        code VARCHAR(50) NOT NULL,
                        name VARCHAR(255) NOT NULL,
                        UNIQUE KEY unique_subject (department, code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (PDOException $e) {
                    // If this fails, subject validation/assignment will be skipped below
                }
            }

            // Start transaction
            $pdo->beginTransaction();
            
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, full_name, email, department) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hashed_password, $role, $full_name, $email, $department]);
            
            $user_id = $pdo->lastInsertId();
            
            // Insert role-specific data
            if ($role === 'faculty') {
                $employee_id = sanitizeInput($_POST['employee_id'] ?? '');
                $position = sanitizeInput($_POST['position'] ?? '');
                $hire_date = $_POST['hire_date'] ?? null;
                // Normalize empty employee_id to NULL
                if ($employee_id === '') {
                    $employee_id = null;
                }

                // Validate unique employee_id when provided
                if ($employee_id !== null) {
                    $check = $pdo->prepare("SELECT id FROM faculty WHERE employee_id = ? LIMIT 1");
                    $check->execute([$employee_id]);
                    if ($check->fetch()) {
                        throw new Exception('Employee ID already exists. Please use a unique Employee ID.');
                    }
                }

                $stmt = $pdo->prepare("INSERT INTO faculty (user_id, employee_id, position, hire_date) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $employee_id, $position, $hire_date]);

                // Table was ensured before transaction

                // Store selected subjects (if any), but validate strictly against allowed list from DB
                $subjects = $_POST['subjects'] ?? [];
                if (is_array($subjects) && !empty($subjects)) {
                    // Build allowed set from DB subjects for this department
                    $allowed = [];
                    $subStmt = $pdo->prepare("SELECT code, name FROM subjects WHERE department = ?");
                    $subStmt->execute([$department]);
                    foreach ($subStmt->fetchAll() as $row) {
                        $allowed[$row['code'] . '::' . $row['name']] = true;
                    }
                    // Validate every selected subject
                    foreach ($subjects as $sub) {
                        if (!is_string($sub) || $sub === '' || !isset($allowed[$sub])) {
                            throw new Exception('Invalid subject selection detected. Please choose from the available subjects list.');
                        }
                    }

                    // Insert after validation passes
                    $ins = $pdo->prepare("INSERT INTO faculty_subjects (faculty_user_id, department, subject_code, subject_name) VALUES (?, ?, ?, ?)");
                    foreach ($subjects as $sub) {
                        $parts = explode('::', $sub, 2);
                        $code = sanitizeInput($parts[0] ?? '');
                        $name = sanitizeInput($parts[1] ?? '');
                        $ins->execute([$user_id, $department, $code, $name]);
                    }
                }
                
            } elseif ($role === 'student') {
                $student_id = sanitizeInput($_POST['student_id'] ?? '');
                $year_level = sanitizeInput($_POST['year_level'] ?? '');
                $program = sanitizeInput($_POST['program'] ?? '');
                
                $stmt = $pdo->prepare("INSERT INTO students (user_id, student_id, year_level, program) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user_id, $student_id, $year_level, $program]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'User added successfully!'
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    elseif ($action === 'edit_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Get and validate form data
            $full_name = sanitizeInput($_POST['full_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $department = sanitizeInput($_POST['department'] ?? '');
            // Normalize department values to canonical names used across the system
            $deptMap = [
                'School of Technology' => 'Technology',
                'School of Business' => 'Business',
                'School of Education' => 'Education',
                'SOT' => 'Technology',
                'SOB' => 'Business',
                'SOE' => 'Education',
            ];
            if (isset($deptMap[$department])) {
                $department = $deptMap[$department];
            }
            
            if (!$full_name || !$department) {
                throw new Exception('Full name and department are required');
            }
            
            // Get user's current role
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Update user basic info
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, department = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $department, $user_id]);
            
            // Update role-specific data
            if ($user['role'] === 'faculty') {
                $employee_id = sanitizeInput($_POST['employee_id'] ?? '');
                $position = sanitizeInput($_POST['position'] ?? '');
                $hire_date = $_POST['hire_date'] ?? null;
                // Normalize empty employee_id to NULL
                if ($employee_id === '') {
                    $employee_id = null;
                }

                // Validate unique employee_id for other users when provided
                if ($employee_id !== null) {
                    $check = $pdo->prepare("SELECT id FROM faculty WHERE employee_id = ? AND user_id <> ? LIMIT 1");
                    $check->execute([$employee_id, $user_id]);
                    if ($check->fetch()) {
                        throw new Exception('Employee ID already exists. Please use a unique Employee ID.');
                    }
                }

                $stmt = $pdo->prepare("UPDATE faculty SET employee_id = ?, position = ?, hire_date = ? WHERE user_id = ?");
                $stmt->execute([$employee_id, $position, $hire_date, $user_id]);
                
            } elseif ($user['role'] === 'student') {
                $student_id = sanitizeInput($_POST['student_id'] ?? '');
                $year_level = sanitizeInput($_POST['year_level'] ?? '');
                $program = sanitizeInput($_POST['program'] ?? '');
                
                $stmt = $pdo->prepare("UPDATE students SET student_id = ?, year_level = ?, program = ? WHERE user_id = ?");
                $stmt->execute([$student_id, $year_level, $program, $user_id]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully!'
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    elseif ($action === 'get_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Get user with role-specific data
            $stmt = $pdo->prepare("SELECT 
                                    u.id, u.username, u.full_name, u.email, u.department, u.role,
                                    f.employee_id, f.position, f.hire_date,
                                    s.student_id, s.year_level, s.program
                                   FROM users u
                                   LEFT JOIN faculty f ON u.id = f.user_id
                                   LEFT JOIN students s ON u.id = s.user_id
                                   WHERE u.id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    elseif ($action === 'delete_user') {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            // Prevent admin from deleting themselves
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('You cannot delete your own account');
            }
            
            // Delete user (cascade will handle related records)
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully!'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    // Reset password to default '123' for Students, Faculty, and Deans
    elseif ($action === 'reset_password') {
        try {
            // Validate CSRF token
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                throw new Exception('Invalid security token');
            }

            $target_user_id = (int)($_POST['user_id'] ?? 0);
            if (!$target_user_id) {
                throw new Exception('Invalid user ID');
            }

            // Load target user info
            $stmt = $pdo->prepare("SELECT id, username, full_name, role, department FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$target_user_id]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new Exception('User not found');
            }

            // Only allow reset for Students, Faculty, and Deans (not Admins)
            if (!in_array($target['role'], ['student', 'faculty', 'dean'])) {
                throw new Exception('Password reset is only allowed for Students, Faculty, and Deans');
            }

            // Department scope enforcement
            $adminDept = $_SESSION['department'] ?? '';
            // Treat non-academic departments (e.g., IT Department) or empty as super-admin
            $isSuperAdmin = !in_array($adminDept, ['Technology', 'Education', 'Business']);
            if (!$isSuperAdmin) {
                if (strcasecmp($adminDept, (string)$target['department']) !== 0) {
                    throw new Exception('You can only reset passwords for users in your department');
                }
            }

            // Hash default password '123'
            $new_hash = password_hash('123', PASSWORD_DEFAULT);

            // Update password
            $upd = $pdo->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$new_hash, $target_user_id]);

            // Ensure audit_log table exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    actor_user_id INT NOT NULL,
                    actor_username VARCHAR(50) NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    target_user_id INT NOT NULL,
                    target_username VARCHAR(50) NOT NULL,
                    target_role VARCHAR(20) NOT NULL,
                    details TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (actor_user_id),
                    INDEX (target_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) {
                // Non-fatal; continue without blocking the reset
            }

            // Write audit log
            try {
                $al = $pdo->prepare("INSERT INTO audit_log (actor_user_id, actor_username, action, target_user_id, target_username, target_role, details) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $al->execute([
                    $_SESSION['user_id'] ?? 0,
                    $_SESSION['username'] ?? 'unknown',
                    'password_reset',
                    $target['id'],
                    $target['username'],
                    $target['role'],
                    'Password reset to default (123)'
                ]);
            } catch (PDOException $e) {
                // Ignore audit failures
            }

            // Success response with confirmation message
            $displayName = $target['full_name'] ?: ('User ID ' . $target['id']);
            echo json_encode([
                'success' => true,
                'message' => 'Password reset to default (123) for ' . $displayName
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    exit();
}

// If not POST request, redirect to admin dashboard
header('Location: admin.php');
exit();
?>
