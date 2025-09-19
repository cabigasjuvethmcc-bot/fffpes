<?php
require_once '../config.php';
requireRole('student');

// Get student info
$stmt = $pdo->prepare("SELECT s.*, u.full_name, u.department FROM students s 
                       JOIN users u ON s.user_id = u.id 
                       WHERE s.id = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get enrolled subjects for this student with assigned faculty
// Uses the junction table created during enrollment: student_faculty_subjects
// Note: junction table stores faculty_user_id, while evaluations expects faculty.id
$enrollments = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_faculty_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_user_id INT NOT NULL,
        faculty_user_id INT NOT NULL,
        subject_code VARCHAR(50) DEFAULT NULL,
        subject_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assignment (student_user_id, faculty_user_id, subject_code, subject_name),
        INDEX idx_student (student_user_id),
        INDEX idx_faculty (faculty_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $pdo->prepare("SELECT 
            sfs.subject_code, 
            sfs.subject_name, 
            fu.id AS faculty_user_id,
            f.id AS faculty_id,
            fu.full_name AS faculty_name,
            fu.department AS faculty_department,
            f.position AS faculty_position
        FROM student_faculty_subjects sfs
        JOIN users fu ON fu.id = sfs.faculty_user_id AND fu.role = 'faculty'
        JOIN faculty f ON f.user_id = fu.id
        WHERE sfs.student_user_id = ?
        ORDER BY sfs.subject_name, fu.full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $enrollments = $stmt->fetchAll();
} catch (PDOException $e) {
    $enrollments = [];
}

// Get evaluation criteria
$stmt = $pdo->prepare("SELECT * FROM evaluation_criteria WHERE is_active = 1 ORDER BY category, criterion");
$stmt->execute();
$criteria = $stmt->fetchAll();

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}

// Get student's evaluations
$stmt = $pdo->prepare("SELECT e.*, u.full_name as faculty_name, f.position 
                       FROM evaluations e 
                       JOIN faculty f ON e.faculty_id = f.id 
                       JOIN users u ON f.user_id = u.id 
                       WHERE e.student_id = ? 
                       ORDER BY e.created_at DESC");
$stmt->execute([$_SESSION['student_id']]);
$evaluations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Faculty Performance Evaluation</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="student.css">
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Student Portal</h2>
            <a href="#" onclick="showSection('evaluate')">Evaluate Faculty</a>
            <a href="#" onclick="showSection('history')">My Evaluations</a>
            <a href="#" onclick="showSection('profile')">Profile</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($student['full_name']); ?>!</h1>
                <p>Student ID: <?php echo htmlspecialchars($student['student_id']); ?> | Program: <?php echo htmlspecialchars($student['program']); ?></p>
            </div>

            <!-- Evaluate Faculty Section -->
            <div id="evaluate-section" class="content-section">
                <h2>Evaluate Faculty Performance</h2>
                <div class="evaluation-form-container">
                    <form id="evaluation-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <h3 class="section-title">Subject & Faculty Selection</h3>

                        <?php if (empty($enrollments)): ?>
                            <div class="error-message">No enrolled subjects found. Please contact your department to ensure your subjects and assigned faculty are set up.</div>
                        <?php else: ?>
                            <div class="form-group">
                                <label for="enrollment_select">Select Subject & Faculty:</label>
                                <select id="enrollment_select" required>
                                    <option value="">-- Select Subject and Faculty --</option>
                                    <?php foreach ($enrollments as $en): ?>
                                        <option 
                                            value="<?php echo (int)$en['faculty_id']; ?>"
                                            data-faculty-id="<?php echo (int)$en['faculty_id']; ?>"
                                            data-faculty-user-id="<?php echo (int)$en['faculty_user_id']; ?>"
                                            data-subject="<?php echo htmlspecialchars($en['subject_name']); ?>"
                                            data-subject-code="<?php echo htmlspecialchars($en['subject_code'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(($en['subject_code'] ? $en['subject_code'] . ' - ' : '') . $en['subject_name']); ?> — 
                                            <?php echo htmlspecialchars($en['faculty_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- Hidden fields populated based on selection to match backend contract -->
                                <input type="hidden" id="faculty_id" name="faculty_id" value="">
                                <input type="hidden" id="subject" name="subject" value="">
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="semester">Semester:</label>
                            <select id="semester" name="semester" required>
                                <option value="">-- Select Semester --</option>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="academic_year">Academic Year:</label>
                            <input type="text" id="academic_year" name="academic_year" placeholder="e.g., 2023-2024" required>
                        </div>

                        <div class="evaluation-criteria">
                            <h3 class="section-title">Evaluation Criteria</h3>
                            <p>Rate each criterion from 1 (Poor) to 5 (Excellent)</p>
                            
                            <?php foreach ($grouped_criteria as $category => $category_criteria): ?>
                                <div class="criteria-category">
                                    <h4><?php echo htmlspecialchars($category); ?></h4>
                                    <?php foreach ($category_criteria as $criterion): ?>
                                        <div class="criterion-item">
                                            <label><?php echo htmlspecialchars($criterion['criterion']); ?></label>
                                            <div class="rating-scale">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <label class="rating-option">
                                                        <input type="radio" name="rating_<?php echo $criterion['id']; ?>" value="<?php echo $i; ?>" required>
                                                        <span><?php echo $i; ?></span>
                                                    </label>
                                                <?php endfor; ?>
                                            </div>
                                            <textarea name="comment_<?php echo $criterion['id']; ?>" placeholder="Optional comment" rows="2"></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h3 class="section-title">Additional Comments</h3>
                        <div class="form-group">
                            <label for="overall_comments">Overall Comments:</label>
                            <textarea id="overall_comments" name="overall_comments" rows="4" placeholder="Share your overall thoughts about this faculty member's performance"></textarea>
                        </div>

                        <button type="submit" class="submit-btn">Submit Evaluation</button>
                    </form>
                </div>
            </div>

            <!-- Evaluation History Section -->
            <div id="history-section" class="content-section" style="display: none;">
                <h2>My Evaluations</h2>
                <div class="evaluations-list">
                    <?php if (empty($evaluations)): ?>
                        <p>You haven't submitted any evaluations yet.</p>
                    <?php else: ?>
                        <table class="evaluations-table">
                            <thead>
                                <tr>
                                    <th>Faculty</th>
                                    <th>Subject</th>
                                    <th>Semester</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $eval): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($eval['faculty_name']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['subject']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['semester']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['academic_year']); ?></td>
                                        <td><span class="status-<?php echo $eval['status']; ?>"><?php echo ucfirst($eval['status']); ?></span></td>
                                        <td><?php echo date('M j, Y', strtotime($eval['submitted_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="content-section" style="display: none;">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-group">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($student['full_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Student ID:</label>
                        <span><?php echo htmlspecialchars($student['student_id']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Program:</label>
                        <span><?php echo htmlspecialchars($student['program']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Year Level:</label>
                        <span><?php echo htmlspecialchars($student['year_level']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Department:</label>
                        <span><?php echo htmlspecialchars($student['department']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="student.js"></script>
</body>
</html>
