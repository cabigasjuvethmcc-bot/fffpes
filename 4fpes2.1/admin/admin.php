<?php
require_once '../config.php';
requireRole('admin');

// Get admin info
$stmt = $pdo->prepare("SELECT u.full_name, u.department FROM users u WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

// Ensure deans table exists for joins below
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS deans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        employee_id VARCHAR(20) UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // If creation fails, the join below may error; we proceed and let error bubble if so
}

// Get all users with their role-specific information (includes dean employee IDs)
$stmt = $pdo->prepare("SELECT 
                        u.id, u.username, u.full_name, u.email, u.department, u.role, u.created_at,
                        f.employee_id, f.position, f.hire_date,
                        s.student_id, s.year_level, s.program,
                        d.employee_id AS dean_employee_id
                       FROM users u
                       LEFT JOIN faculty f ON u.id = f.user_id
                       LEFT JOIN students s ON u.id = s.user_id
                       LEFT JOIN deans d ON u.id = d.user_id
                       ORDER BY u.role, u.full_name");
$stmt->execute();
$all_users = $stmt->fetchAll();

// Get system statistics
$stmt = $pdo->prepare("SELECT 
                        (SELECT COUNT(*) FROM users WHERE role = 'student') as student_count,
                        (SELECT COUNT(*) FROM users WHERE role = 'faculty') as faculty_count,
                        (SELECT COUNT(*) FROM users WHERE role = 'dean') as dean_count,
                        (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
                        (SELECT COUNT(*) FROM evaluations WHERE status = 'submitted') as total_evaluations,
                        (SELECT COUNT(*) FROM evaluation_criteria WHERE is_active = 1) as active_criteria");
$stmt->execute();
$system_stats = $stmt->fetch();

// Get evaluation criteria for management
$stmt = $pdo->prepare("SELECT * FROM evaluation_criteria ORDER BY category, criterion");
$stmt->execute();
$criteria = $stmt->fetchAll();

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - System Management</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-color);
            font-size: 0.9rem;
        }
        
        .management-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .users-table th, .users-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .users-table th {
            background: var(--bg-color);
            font-weight: 600;
        }
        
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .role-student { background: var(--primary-color); color: white; }
        .role-faculty { background: var(--secondary-color); color: white; }
        .role-dean { background: var(--warning-color); color: white; }
        .role-admin { background: var(--danger-color); color: white; }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-small {
            padding: 0.25rem 0.75rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: var(--transition);
        }
        
        .btn-edit {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-delete {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-reset {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-small:hover {
            opacity: 0.8;
        }
        
        .form-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .submit-btn {
            background: var(--secondary-color);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .submit-btn:hover {
            background: var(--secondary-dark);
        }
        
        .criteria-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .criteria-info {
            flex: 1;
        }
        
        .criteria-category-header {
            background: var(--primary-color);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0 0.5rem 0;
            font-weight: 600;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .success-message, .error-message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .success-message {
            background: var(--secondary-color);
            color: white;
        }
        
        .error-message {
            background: var(--danger-color);
            color: white;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Admin Portal</h2>
            <a href="#" onclick="showSection('overview')">System Overview</a>
            <a href="#" onclick="showSection('users')">User Management</a>
            <a href="#" onclick="showSection('criteria')">Evaluation Criteria</a>
            <!-- Manage Subjects removed: handled by Department Admin -->
            <a href="#" onclick="showSection('reports')">System Reports</a>
            <a href="#" onclick="showSection('settings')">Settings</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($admin['full_name']); ?>!</h1>
                <p>System Administrator | Faculty Performance Evaluation System</p>
            </div>

            <!-- Overview Section -->
            <div id="overview-section" class="content-section">
                <h2>System Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['student_count']; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['faculty_count']; ?></div>
                        <div class="stat-label">Faculty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['dean_count']; ?></div>
                        <div class="stat-label">Deans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['admin_count']; ?></div>
                        <div class="stat-label">Admins</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['total_evaluations']; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $system_stats['active_criteria']; ?></div>
                        <div class="stat-label">Active Criteria</div>
                    </div>
                </div>

                <div class="management-section">
                    <h3>Quick Actions</h3>
                    <div class="action-buttons">
                        <button class="submit-btn" onclick="openAddUserModal()">Add New User</button>
                        <button class="submit-btn" onclick="openAddCriterionModal()">Add Evaluation Criterion</button>
                        <button class="submit-btn" onclick="generateSystemReport()">Generate System Report</button>
                    </div>
                </div>
            </div>

            <!-- User Management Section -->
            <div id="users-section" class="content-section" style="display: none;">
                <h2>User Management</h2>
                
                <div class="management-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>All Users</h3>
                        <button class="submit-btn" onclick="openAddUserModal()">Add New User</button>
                    </div>
                    
                    <div class="search-container">
                        <input type="text" id="user_search" placeholder="Search users by name, username, role, or department..." onkeyup="filterUsersTable()">
                    </div>
                    
                    <table class="users-table" id="users_table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Email</th>
                                <th>Additional Info</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['department']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ($user['role'] === 'faculty'): ?>
                                            ID: <?php echo htmlspecialchars($user['employee_id']); ?><br>
                                            Position: <?php echo htmlspecialchars($user['position']); ?>
                                        <?php elseif ($user['role'] === 'dean'): ?>
                                            ID: <?php echo htmlspecialchars($user['dean_employee_id']); ?>
                                        <?php elseif ($user['role'] === 'student'): ?>
                                            ID: <?php echo htmlspecialchars($user['student_id']); ?><br>
                                            Year: <?php echo htmlspecialchars($user['year_level']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-small btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">Edit</button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <button class="btn-small btn-delete" onclick="deleteUser(<?php echo $user['id']; ?>)">Delete</button>
                                            <?php endif; ?>
                                            <?php if (in_array($user['role'], ['student','faculty','dean'])): ?>
                                                <button class="btn-small btn-reset" onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'] ?: $user['username'])); ?>')">Reset</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Evaluation Criteria Section -->
            <div id="criteria-section" class="content-section" style="display: none;">
                <h2>Evaluation Criteria Management</h2>
                
                <div class="management-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>Evaluation Criteria</h3>
                        <button class="submit-btn" onclick="openAddCriterionModal()">Add New Criterion</button>
                    </div>
                    
                    <?php foreach ($grouped_criteria as $category => $category_criteria): ?>
                        <div class="criteria-category-header">
                            <?php echo htmlspecialchars($category); ?>
                        </div>
                        <?php foreach ($category_criteria as $criterion): ?>
                            <div class="criteria-item">
                                <div class="criteria-info">
                                    <strong><?php echo htmlspecialchars($criterion['criterion']); ?></strong>
                                    <?php if ($criterion['description']): ?>
                                        <br><small><?php echo htmlspecialchars($criterion['description']); ?></small>
                                    <?php endif; ?>
                                    <br><small>Weight: <?php echo $criterion['weight']; ?> | 
                                    Status: <?php echo $criterion['is_active'] ? 'Active' : 'Inactive'; ?></small>
                                </div>
                                <div class="action-buttons">
                                    <button class="btn-small btn-edit" onclick="editCriterion(<?php echo $criterion['id']; ?>)">Edit</button>
                                    <button class="btn-small btn-delete" onclick="deleteCriterion(<?php echo $criterion['id']; ?>)">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- System Reports Section -->
            <div id="reports-section" class="content-section" style="display: none;">
                <h2>System Reports</h2>
                
                <div class="management-section">
                    <h3>Available Reports</h3>
                    <div class="action-buttons">
                        <button class="submit-btn" onclick="generateUserReport()">User Activity Report</button>
                        <button class="submit-btn" onclick="generateEvaluationReport()">Evaluation Summary Report</button>
                        <button class="submit-btn" onclick="generatePerformanceReport()">Faculty Performance Report</button>
                        <button class="submit-btn" onclick="exportDatabase()">Export Database</button>
                    </div>
                </div>
            </div>

            <!-- Settings Section -->
            <div id="settings-section" class="content-section" style="display: none;">
                <h2>System Settings</h2>
                
                <div class="management-section">
                    <h3>System Configuration</h3>
                    <p>System settings and configuration options would be implemented here.</p>
                    
                    <div class="form-group">
                        <label>Academic Year:</label>
                        <input type="text" value="2023-2024" placeholder="Current academic year">
                    </div>
                    
                    <div class="form-group">
                        <label>Current Semester:</label>
                        <select>
                            <option value="1st Semester">1st Semester</option>
                            <option value="2nd Semester">2nd Semester</option>
                            <option value="Summer">Summer</option>
                        </select>
                    </div>
                    
                    <button class="submit-btn">Save Settings</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addUserModal')">&times;</span>
            <h2>Add New User</h2>
            <form id="addUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="role">Role:</label>
                    <select id="role" name="role" required onchange="toggleRoleFields()">
                        <option value="">-- Select Role --</option>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                        <option value="dean">Dean</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="form-group" id="usernameGroup">
                    <label for="username" id="usernameLabel">Username:</label>
                    <input type="text" id="username" name="username" required>
                    <small id="usernameHelp" style="color:#666; display:block; margin-top:6px;"></small>
                </div>

                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email">
                </div>

                <div class="form-group">
                    <label for="department">Department:</label>
                    <select id="department" name="department" required>
                        <option value="">-- Select Department --</option>
                        <option value="Technology">School of Technology</option>
                        <option value="Business">School of Business</option>
                        <option value="Education">School of Education</option>
                    </select>
                </div>

                <!-- Faculty-specific fields -->
                <div id="facultyFields" style="display: none;">
                    <div class="form-group">
                        <label for="employee_id">Employee ID (auto-generated):</label>
                        <input type="text" id="employee_id" name="employee_id" readonly placeholder="Auto-generated (F-001, F-002, ...)">
                        <small style="color:#666; display:block; margin-top:6px;">Will be assigned automatically when the account is created.</small>
                    </div>
                    <div class="form-group">
                        <label for="position">Position:</label>
                        <input type="text" id="position" name="position">
                    </div>
                    <div class="form-group">
                        <label for="hire_date">Hire Date:</label>
                        <input type="date" id="hire_date" name="hire_date">
                    </div>
                    <div class="form-group">
                        <label for="faculty_subjects">Subjects (based on Department):</label>
                        <select id="faculty_subjects" name="subjects[]" multiple size="6">
                            <!-- dynamically loaded from /api/subjects.php -->
                        </select>
                        <small style="color:#666; display:block; margin-top:6px;">Hold Ctrl (Windows) to select multiple subjects.</small>
                    </div>
                </div>

                <!-- Student-specific fields -->
                <div id="studentFields" style="display: none;">
                    <div class="form-group">
                        <label for="student_gender">Gender:</label>
                        <select id="student_gender" name="gender" required>
                            <option value="">-- Select Gender --</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                        <small style="color:#666; display:block; margin-top:6px;">Student ID will be auto-generated (222-XXX for Male, 221-XXX for Female)</small>
                    </div>
                    <div class="form-group">
                        <label for="year_level">Year Level:</label>
                        <select id="year_level" name="year_level">
                            <option value="">-- Select Year --</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="program">Program:</label>
                        <select id="program" name="program">
                            <option value="">-- Select Program --</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Add User</button>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editUserModal')">&times;</span>
            <h2>Edit User</h2>
            <form id="editUserForm">
                <input type="hidden" name="user_id" id="editUserId">
                
                <div class="form-group">
                    <label for="editFullName">Full Name:</label>
                    <input type="text" id="editFullName" name="full_name" required>
                </div>

                <div class="form-group">
                    <label for="editEmail">Email:</label>
                    <input type="email" id="editEmail" name="email">
                </div>

                <div class="form-group">
                    <label for="editDepartment">Department:</label>
                    <select id="editDepartment" name="department" required>
                        <option value="">-- Select Department --</option>
                        <option value="Technology">School of Technology</option>
                        <option value="Business">School of Business</option>
                        <option value="Education">School of Education</option>
                    </select>
                </div>

                <!-- Faculty-specific fields -->
                <div id="editFacultyFields" style="display: none;">
                    <div class="form-group">
                        <label for="editEmployeeId">Employee ID:</label>
                        <input type="text" id="editEmployeeId" name="employee_id">
                    </div>
                    <div class="form-group">
                        <label for="editPosition">Position:</label>
                        <input type="text" id="editPosition" name="position">
                    </div>
                    <div class="form-group">
                        <label for="editHireDate">Hire Date:</label>
                        <input type="date" id="editHireDate" name="hire_date">
                    </div>
                </div>

                <!-- Student-specific fields -->
                <div id="editStudentFields" style="display: none;">
                    <div class="form-group">
                        <label for="editStudentId">Student ID (auto-generated):</label>
                        <input type="text" id="editStudentId" name="student_id" readonly placeholder="Auto-generated">
                    </div>
                    <div class="form-group">
                        <label for="editYearLevel">Year Level:</label>
                        <select id="editYearLevel" name="year_level">
                            <option value="">-- Select Year --</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editProgram">Program:</label>
                        <input type="text" id="editProgram" name="program">
                    </div>
                </div>

                <button type="submit" class="submit-btn">Update User</button>
            </form>
        </div>
    </div>

    <!-- Add Criterion Modal -->
    <div id="addCriterionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addCriterionModal')">&times;</span>
            <h2>Add Evaluation Criterion</h2>
            <form id="addCriterionForm">
                <div class="form-group">
                    <label for="category">Category:</label>
                    <select id="category" name="category" required>
                        <option value="">-- Select Category --</option>
                        <option value="Teaching Effectiveness">Teaching Effectiveness</option>
                        <option value="Professional Development">Professional Development</option>
                        <option value="Research & Innovation">Research & Innovation</option>
                        <option value="Service & Leadership">Service & Leadership</option>
                        <option value="Student Engagement">Student Engagement</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="criterion">Criterion:</label>
                    <input type="text" id="criterion" name="criterion" required>
                </div>

                <div class="form-group">
                    <label for="description">Description:</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="weight">Weight (1-10):</label>
                    <input type="number" id="weight" name="weight" min="1" max="10" step="0.1" required>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="is_active" name="is_active" checked>
                        Active
                    </label>
                </div>

                <button type="submit" class="submit-btn">Add Criterion</button>
            </form>
        </div>
    </div>

    <!-- Edit Criterion Modal -->
    <div id="editCriterionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editCriterionModal')">&times;</span>
            <h2>Edit Evaluation Criterion</h2>
            <form id="editCriterionForm">
                <input type="hidden" name="id" id="editCriterionId">
                
                <div class="form-group">
                    <label for="editCategory">Category:</label>
                    <select id="editCategory" name="category" required>
                        <option value="">-- Select Category --</option>
                        <option value="Teaching Effectiveness">Teaching Effectiveness</option>
                        <option value="Professional Development">Professional Development</option>
                        <option value="Research & Innovation">Research & Innovation</option>
                        <option value="Service & Leadership">Service & Leadership</option>
                        <option value="Student Engagement">Student Engagement</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="editCriterion">Criterion:</label>
                    <input type="text" id="editCriterion" name="criterion" required>
                </div>

                <div class="form-group">
                    <label for="editDescription">Description:</label>
                    <textarea id="editDescription" name="description" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="editWeight">Weight (1-10):</label>
                    <input type="number" id="editWeight" name="weight" min="1" max="10" step="0.1" required>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="editIsActive" name="is_active">
                        Active
                    </label>
                </div>

                <button type="submit" class="submit-btn">Update Criterion</button>
            </form>
        </div>
    </div>

    <script>
        // CSRF token for POST actions
        const CSRF_TOKEN = '<?php echo generateCSRFToken(); ?>';
        // Navigation functions
        function showSection(sectionName) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.style.display = 'none';
                // Initialize role-based visibility for Add User modal on load
            if (typeof toggleRoleFields === 'function') {
                toggleRoleFields();
            }

        });
            
            const targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.style.display = 'block';
            }
        }

        // Logout function
        function logout() {
            fetch('../auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.href = '../index.php';
            });
        }

        // Modal functions
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'block';
            // Ensure role-specific fields and program options are initialized
            toggleRoleFields();
            const role = document.getElementById('role')?.value;
            const deptSel = document.getElementById('department');
            if (role === 'student' && deptSel) {
                populateProgramOptions(deptSel.value);
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Enable/disable all controls in a container and manage required flags
        function setSectionEnabled(containerId, enabled) {
            const el = document.getElementById(containerId);
            if (!el) return;
            el.querySelectorAll('input, select, textarea').forEach(ctrl => {
                if (enabled) {
                    ctrl.disabled = false;
                    if (ctrl.dataset.wasRequired === '1') {
                        ctrl.required = true;
                        delete ctrl.dataset.wasRequired;
                    }
                } else {
                    if (ctrl.required) ctrl.dataset.wasRequired = '1';
                    ctrl.required = false;
                    ctrl.disabled = true;
                }
            });
        }

        function toggleUsernameVisibility(show) {
            const usernameGroup = document.querySelector('#addUserForm .form-group label[for="username"]')?.parentElement;
            const usernameInput = document.getElementById('username');
            if (!usernameGroup || !usernameInput) return;
            usernameGroup.style.display = show ? 'block' : 'none';
            if (!show) {
                // Do not require username when hidden
                usernameInput.dataset.wasRequired = usernameInput.required ? '1' : '0';
                usernameInput.required = false;
            } else {
                if (usernameInput.dataset.wasRequired === '1') {
                    usernameInput.required = true;
                }
            }
        }

        function toggleRoleFields() {
            const role = document.getElementById('role').value;
            const facultyFields = document.getElementById('facultyFields');
            const studentFields = document.getElementById('studentFields');
            
            const showFaculty = role === 'faculty';
            const showStudent = role === 'student';

            facultyFields.style.display = showFaculty ? 'block' : 'none';
            studentFields.style.display = showStudent ? 'block' : 'none';

            // Prevent hidden sections from blocking form submit
            setSectionEnabled('facultyFields', showFaculty);
            setSectionEnabled('studentFields', showStudent);

            // When switching to faculty role, load subjects for current department
            if (showFaculty) {
                const deptSel = document.getElementById('department');
                if (deptSel) loadSubjects(deptSel.value, 'faculty_subjects');
            }
            // When switching to student role, populate program options for current department
            if (showStudent) {
                const deptSel = document.getElementById('department');
                if (deptSel) populateProgramOptions(deptSel.value);
            }
            // Hide username for students (ID auto-generated)
            toggleUsernameVisibility(!showStudent);
        }

        // Department-specific program lists (mirrors department admin pages)
        const PROGRAMS_BY_DEPT = {
            'Technology': [
                'Bachelor of Science in Information Technology',
                'Bachelor of Science in Computer Science',
                'Bachelor of Science in Software Engineering',
                'Bachelor of Science in Cybersecurity',
                'Bachelor of Science in Data Science',
                'Bachelor of Science in Web Development',
                'Associate in Computer Technology'
            ],
            'Business': [
                'Bachelor of Science in Business Administration',
                'Bachelor of Science in Accounting',
                'Bachelor of Science in Marketing',
                'Bachelor of Science in Finance',
                'Bachelor of Science in Human Resource Management',
                'Bachelor of Science in Entrepreneurship',
                'Bachelor of Science in International Business',
                'Master of Business Administration (MBA)'
            ],
            'Education': [
                'Bachelor of Elementary Education',
                'Bachelor of Secondary Education - English',
                'Bachelor of Secondary Education - Mathematics',
                'Bachelor of Secondary Education - Science',
                'Bachelor of Secondary Education - Social Studies',
                'Bachelor of Physical Education',
                'Bachelor of Special Needs Education',
                'Master of Arts in Education',
                'Master of Arts in Teaching'
            ]
        };

        function populateProgramOptions(department) {
            const progSel = document.getElementById('program');
            if (!progSel) return;
            progSel.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Select Program --';
            progSel.appendChild(placeholder);
            const list = PROGRAMS_BY_DEPT[department] || [];
            for (const p of list) {
                const opt = document.createElement('option');
                opt.value = p;
                opt.textContent = p;
                progSel.appendChild(opt);
            }
        }

        // User management functions
        function editUser(userId) {
            // Fetch user data first
            const formData = new FormData();
            formData.append('action', 'get_user');
            formData.append('user_id', userId);
            
            fetch('manage_users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    populateEditModal(data.user);
                    document.getElementById('editUserModal').style.display = 'block';
                } else {
                    alert('Error loading user data: ' + data.message);
                }
            })
            .catch(error => {
                alert('An error occurred while loading user data.');
            });
        }

        function deleteUser(userId) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete_user');
                formData.append('user_id', userId);
                
                fetch('manage_users.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('User deleted successfully!');
                        location.reload();
                    } else {
                        alert('Error deleting user: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred while deleting the user.');
                });
            }
        }

        // Reset password action
        function resetPassword(userId, displayName) {
            if (!confirm('Reset password to default (123) for ' + displayName + '?')) return;
            const formData = new FormData();
            formData.append('action', 'reset_password');
            formData.append('user_id', userId);
            formData.append('csrf_token', CSRF_TOKEN);
            fetch('manage_users.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('An error occurred while resetting the password.'));
        }

        // Criteria management functions
        function openAddCriterionModal() {
            document.getElementById('addCriterionModal').style.display = 'block';
        }

        function editCriterion(criterionId) {
            // Fetch criterion data first
            const formData = new FormData();
            formData.append('action', 'get_criterion');
            formData.append('id', criterionId);
            
            fetch('manage_criteria.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    populateEditCriterionModal(data.criterion);
                    document.getElementById('editCriterionModal').style.display = 'block';
                } else {
                    alert('Error loading criterion data: ' + data.message);
                }
            })
            .catch(error => {
                alert('An error occurred while loading criterion data.');
            });
        }

        function deleteCriterion(criterionId) {
            if (confirm('Are you sure you want to delete this criterion? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('action', 'delete_criterion');
                formData.append('id', criterionId);
                
                fetch('manage_criteria.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Criterion deleted successfully!');
                        location.reload();
                    } else {
                        alert('Error deleting criterion: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred while deleting the criterion.');
                });
            }
        }

        // Report functions
        function generateSystemReport() {
            window.open('reports/system_report.php', '_blank');
        }

        function generateUserReport() {
            window.open('reports/user_report.php', '_blank');
        }

        function generateEvaluationReport() {
            window.open('reports/evaluation_report.php', '_blank');
        }

        function generatePerformanceReport() {
            window.open('reports/performance_report.php', '_blank');
        }

        function exportDatabase() {
            if (confirm('This will export the entire database. Continue?')) {
                window.location.href = 'export_database.php';
            }
        }

        // Helper function to populate edit modal
        function populateEditModal(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFullName').value = user.full_name || '';
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editDepartment').value = user.department || '';
            
            // Show/hide role-specific fields
            const facultyFields = document.getElementById('editFacultyFields');
            const studentFields = document.getElementById('editStudentFields');
            
            if (user.role === 'faculty') {
                facultyFields.style.display = 'block';
                studentFields.style.display = 'none';
                
                document.getElementById('editEmployeeId').value = user.employee_id || '';
                document.getElementById('editPosition').value = user.position || '';
                document.getElementById('editHireDate').value = user.hire_date || '';
            } else if (user.role === 'student') {
                facultyFields.style.display = 'none';
                studentFields.style.display = 'block';
                
                document.getElementById('editStudentId').value = user.student_id || '';
                document.getElementById('editYearLevel').value = user.year_level || '';
                document.getElementById('editProgram').value = user.program || '';
            } else {
                facultyFields.style.display = 'none';
                studentFields.style.display = 'none';
            }
        }

        // Helper function to populate edit criterion modal
        function populateEditCriterionModal(criterion) {
            document.getElementById('editCriterionId').value = criterion.id;
            document.getElementById('editCategory').value = criterion.category || '';
            document.getElementById('editCriterion').value = criterion.criterion || '';
            document.getElementById('editDescription').value = criterion.description || '';
            document.getElementById('editWeight').value = criterion.weight || '';
            document.getElementById('editIsActive').checked = criterion.is_active == 1;
        }

        // Form submission
        document.addEventListener('DOMContentLoaded', function() {
            // Utility: Load subjects for a given department into a select (multi)
            window.loadSubjects = async function(dept, selectId) {
                const sel = document.getElementById(selectId);
                if (!sel) return;
                sel.innerHTML = '';
                if (!dept) return;
                try {
                    const res = await fetch(`../api/subjects.php?department=${encodeURIComponent(dept)}`);
                    const data = await res.json();
                    const items = (data && data.success && Array.isArray(data.data)) ? data.data : [];
                    if (items.length === 0) {
                        const opt = document.createElement('option');
                        opt.value = '';
                        opt.textContent = 'No subjects available';
                        sel.appendChild(opt);
                        return;
                    }
                    for (const s of items) {
                        const opt = document.createElement('option');
                        opt.value = s.code + '::' + s.name; // send both code and name
                        opt.textContent = `${s.code} - ${s.name}`;
                        sel.appendChild(opt);
                    }
                } catch(e) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'Failed to load subjects';
                    sel.appendChild(opt);
                }
            }

            // Wire department change to reload subjects for add-user form
            const deptSelect = document.getElementById('department');
            if (deptSelect) {
                deptSelect.addEventListener('change', function() {
                    const role = document.getElementById('role')?.value;
                    if (role === 'faculty') loadSubjects(this.value, 'faculty_subjects');
                    if (role === 'student') populateProgramOptions(this.value);
                });
            }

            // If role is preset to faculty while opening modal, ensure subjects load
            (function initSubjectsOnLoad(){
                const role = document.getElementById('role')?.value;
                const deptSel = document.getElementById('department');
                if (role === 'faculty' && deptSel) {
                    loadSubjects(deptSel.value, 'faculty_subjects');
                }
            })();

            // If role is student on open, preload program options for selected department
            (function initProgramsOnLoad(){
                const role = document.getElementById('role')?.value;
                const deptSel = document.getElementById('department');
                if (role === 'student' && deptSel) {
                    populateProgramOptions(deptSel.value);
                }
            })();
            const addUserForm = document.getElementById('addUserForm');
            if (addUserForm) {
                addUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('action', 'add_user');
                    
                    fetch('manage_users.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('User added successfully!');
                            closeModal('addUserModal');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        alert('An error occurred. Please try again.');
                    });
                });
            }

            // Edit user form submission
            const editUserForm = document.getElementById('editUserForm');
            if (editUserForm) {
                editUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('action', 'edit_user');
                    
                    fetch('manage_users.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('User updated successfully!');
                            closeModal('editUserModal');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        alert('An error occurred. Please try again.');
                    });
                });
            }

            // Add criterion form submission
            const addCriterionForm = document.getElementById('addCriterionForm');
            if (addCriterionForm) {
                addCriterionForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('action', 'add_criterion');
                    
                    fetch('manage_criteria.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Criterion added successfully!');
                            closeModal('addCriterionModal');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        alert('An error occurred. Please try again.');
                    });
                });
            }

            // Edit criterion form submission
            const editCriterionForm = document.getElementById('editCriterionForm');
            if (editCriterionForm) {
                editCriterionForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('action', 'update_criterion');
                    
                    fetch('manage_criteria.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Criterion updated successfully!');
                            closeModal('editCriterionModal');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        alert('An error occurred. Please try again.');
                    });
                });
            }

            // Show overview section by default
            showSection('overview');
        });

        // Search/Filter function for users table
        function filterUsersTable() {
            const searchInput = document.getElementById('user_search');
            const filter = searchInput.value.toLowerCase();
            const table = document.getElementById('users_table');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;

                // Search through name, username, role, department, and email columns
                for (let j = 0; j < 5; j++) {
                    if (cells[j]) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }

                // Show or hide the row based on search result
                if (found || filter === '') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }

        // Toggle fields based on selected role in Add User modal
        function toggleRoleFields() {
            const roleSel = document.getElementById('role');
            const usernameGroup = document.getElementById('usernameGroup');
            const usernameInput = document.getElementById('username');
            const facultyFields = document.getElementById('facultyFields');
            const studentFields = document.getElementById('studentFields');

            if (!roleSel) return;
            const role = roleSel.value;

            if (facultyFields) facultyFields.style.display = (role === 'faculty') ? 'block' : 'none';
            if (studentFields) studentFields.style.display = (role === 'student') ? 'block' : 'none';

            if (role === 'student' || role === 'faculty' || role === 'dean') {
                if (usernameGroup) usernameGroup.style.display = 'none';
                if (usernameInput) usernameInput.required = false;
            } else {
                if (usernameGroup) usernameGroup.style.display = 'block';
                if (usernameInput) usernameInput.required = true;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
