<?php
require_once 'config.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Performance Evaluation System - Login</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="container" id="login-container">
    <h1>Faculty Performance Evaluation System</h1>
    <div class="login-form">
      <h2>Login</h2>
      <div id="login-error" class="error-message"></div>

      <form id="login-form">
        <!-- Role Selection -->
        <div class="form-group">
          <label for="role">Role:</label>
          <select id="role" name="role" required>
            <option value="">-- Select Role --</option>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="dean">Dean</option>
            <option value="department_admin">Department Admin</option>
            <option value="admin">System Admin</option>
          </select>
        </div>

        <div class="form-group">
          <label for="username">Username:</label>
          <input type="text" id="username" name="username" placeholder="Enter your username" required>
        </div>

        <div class="form-group">
          <label for="password">Password:</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" required>
        </div>

        <button type="submit" id="login-btn">Login</button>
      </form>
      
      <div style="margin-top: 0.75rem;">
        <a href="forgot_password_report.php" style="font-size: 0.9rem;">Report (Forgot Password)</a>
      </div>
      
      <div style="margin-top: 2rem; padding: 1rem; background: #f8f9fa; border-radius: 8px; font-size: 0.9rem;">
        <h4>Demo Credentials:</h4>
        <p><strong>System Admin:</strong> admin01 / password (Role: System Admin)</p>
        <p><strong>Technology Dept Admin:</strong> tech_admin / password (Role: Department Admin)</p>
        <p><strong>Education Dept Admin:</strong> edu_admin / password (Role: Department Admin)</p>
        <p><strong>Business Dept Admin:</strong> bus_admin / password (Role: Department Admin)</p>
        <p><strong>Faculty:</strong> faculty01 / password (Role: Faculty)</p>
        <p><strong>Student:</strong> STU001 / password (Role: Student)</p>
      </div>
    </div>
  </div>
  
  <script src="script.js"></script>
  
</body>
</html>