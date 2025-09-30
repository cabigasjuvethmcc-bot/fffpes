<?php
// Enable error display during debugging (set to 0 for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// Best-effort: ensure the password_reset_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        role ENUM('Student','Faculty','Dean') NOT NULL,
        status ENUM('Pending','Resolved') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // Log but do not expose details to user
    if (function_exists('error_log')) {
        @error_log('forgot_password_report: table ensure failed - ' . $e->getMessage());
    }
}

// If logged in, still allow reporting (no redirect)

$message = '';
$error = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $identifier = sanitizeInput($_POST['identifier'] ?? '');
        $role = sanitizeInput($_POST['role'] ?? '');
        //
        // Normalize role to expected ENUM case: Student, Faculty, Dean
        $role_map = [
            'student' => 'Student',
            'faculty' => 'Faculty',
            'dean' => 'Dean',
            'Student' => 'Student',
            'Faculty' => 'Faculty',
            'Dean' => 'Dean',
        ];
        $role_enum = $role_map[$role] ?? '';

        if (empty($identifier) || empty($role_enum)) {
            $error = 'Identifier and Role are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO password_reset_requests (identifier, role) VALUES (?, ?)");
                $stmt->execute([$identifier, $role_enum]);
                $message = 'Your password reset request has been submitted to the System Admin.';
            } catch (PDOException $e) {
                if (function_exists('error_log')) {
                    @error_log('forgot_password_report: insert failed - ' . $e->getMessage());
                }
                $error = 'Failed to submit request. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Report (Forgot Password)</title>
  <link rel="stylesheet" href="styles.css" />
  <style>
    /* Page-specific minimal styles to ensure visibility */
    body { background:#f8fafc; color:#111; }
    .forgot-wrapper { max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
    .forgot-card { background:#fff; border-radius:12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); padding: 20px; }
    .forgot-card h2 { margin: 0 0 12px 0; }
    .forgot-card .form-group { margin-bottom: 1rem; }
    .forgot-card input[type="text"], .forgot-card select { width:100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 6px; }
    .forgot-card .submit-btn { padding: 8px 14px; border-radius: 8px; }
  </style>
</head>
<body>
  <div class="forgot-wrapper">
    <div class="forgot-card">
    <h2>Report (Forgot Password)</h2>
    <p style="color:#555;">Enter your Student ID or Employee ID and select your role. The System Admin will reset your password and notify you.</p>

    <?php if (!empty($message)): ?>
      <div class="success-message" style="display:block;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="error-message" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form class="forgot-card" method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>" />
      <div class="form-group">
        <label for="identifier">Student ID / Employee ID</label>
        <input type="text" id="identifier" name="identifier" placeholder="e.g., STU001 or FAC001" required />
      </div>
      <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role" required>
          <option value="">-- Select Role --</option>
          <option value="Student">Student</option>
          <option value="Faculty">Faculty</option>
          <option value="Dean">Dean</option>
        </select>
      </div>
      <button type="submit" class="submit-btn">Submit Report</button>
      <a href="index.php" style="margin-left:1rem;">Back to Login</a>
    </form>
    </div>
  </div>
</body>
</html>
