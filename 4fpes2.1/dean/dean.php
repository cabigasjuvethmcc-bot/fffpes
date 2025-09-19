<?php
require_once '../config.php';
requireRole('dean');

// Get dean info
$stmt = $pdo->prepare("SELECT u.full_name, u.department FROM users u WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$dean = $stmt->fetch();

// Get overall statistics
$stmt = $pdo->prepare("SELECT 
                        COUNT(DISTINCT e.id) as total_evaluations,
                        COUNT(DISTINCT e.faculty_id) as evaluated_faculty,
                        COUNT(DISTINCT f.id) as total_faculty,
                        AVG(e.overall_rating) as avg_rating
                       FROM evaluations e 
                       RIGHT JOIN faculty f ON e.faculty_id = f.id AND e.status = 'submitted'");
$stmt->execute();
$overall_stats = $stmt->fetch();

// Get faculty performance summary
$stmt = $pdo->prepare("SELECT 
                        f.id, u.full_name, u.department, f.position,
                        COUNT(e.id) as evaluation_count,
                        AVG(e.overall_rating) as avg_rating,
                        MIN(e.overall_rating) as min_rating,
                        MAX(e.overall_rating) as max_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                       GROUP BY f.id, u.full_name, u.department, f.position
                       ORDER BY avg_rating DESC");
$stmt->execute();
$faculty_performance = $stmt->fetchAll();

// Get department-wise statistics
$stmt = $pdo->prepare("SELECT 
                        u.department,
                        COUNT(DISTINCT f.id) as faculty_count,
                        COUNT(e.id) as evaluation_count,
                        AVG(e.overall_rating) as avg_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                       GROUP BY u.department
                       ORDER BY avg_rating DESC");
$stmt->execute();
$department_stats = $stmt->fetchAll();

// Get evaluation trends by semester
$stmt = $pdo->prepare("SELECT 
                        academic_year, semester,
                        COUNT(*) as evaluation_count,
                        AVG(overall_rating) as avg_rating
                       FROM evaluations 
                       WHERE status = 'submitted'
                       GROUP BY academic_year, semester
                       ORDER BY academic_year DESC, semester");
$stmt->execute();
$semester_trends = $stmt->fetchAll();

// Get top and bottom performing faculty
$stmt = $pdo->prepare("SELECT 
                        u.full_name, u.department, f.position,
                        AVG(e.overall_rating) as avg_rating,
                        COUNT(e.id) as evaluation_count
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                       GROUP BY f.id, u.full_name, u.department, f.position
                       HAVING COUNT(e.id) >= 3
                       ORDER BY avg_rating DESC
                       LIMIT 10");
$stmt->execute();
$top_performers = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT 
                        u.full_name, u.department, f.position,
                        AVG(e.overall_rating) as avg_rating,
                        COUNT(e.id) as evaluation_count
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                       GROUP BY f.id, u.full_name, u.department, f.position
                       HAVING COUNT(e.id) >= 3
                       ORDER BY avg_rating ASC
                       LIMIT 5");
$stmt->execute();
$bottom_performers = $stmt->fetchAll();

// Get criteria performance across all faculty
$stmt = $pdo->prepare("SELECT 
                        ec.category, ec.criterion,
                        AVG(er.rating) as avg_rating,
                        COUNT(er.rating) as response_count
                       FROM evaluation_responses er
                       JOIN evaluation_criteria ec ON er.criterion_id = ec.id
                       JOIN evaluations e ON er.evaluation_id = e.id
                       WHERE e.status = 'submitted'
                       GROUP BY ec.id, ec.category, ec.criterion
                       ORDER BY ec.category, avg_rating DESC");
$stmt->execute();
$criteria_performance = $stmt->fetchAll();

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria_performance as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard - Faculty Performance Analytics</title>
    <link rel="stylesheet" href="../styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .chart-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .performance-table th, .performance-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .performance-table th {
            background: var(--bg-color);
            font-weight: 600;
        }
        
        .rating-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .rating-excellent { background: var(--secondary-color); color: white; }
        .rating-good { background: var(--primary-color); color: white; }
        .rating-average { background: var(--warning-color); color: white; }
        .rating-poor { background: var(--danger-color); color: white; }
        .rating-none { background: var(--gray-color); color: white; }
        
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .criteria-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .criteria-category {
            margin-bottom: 1.5rem;
        }
        
        .criteria-category h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .criterion-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .criterion-item:last-child {
            border-bottom: none;
        }
        
        .export-buttons {
            margin-bottom: 2rem;
        }
        
        .export-btn {
            background: var(--secondary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            margin-right: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .export-btn:hover {
            background: var(--secondary-dark);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Dean Portal</h2>
            <a href="#" onclick="showSection('overview')">Overview</a>
            <a href="#" onclick="showSection('faculty')">Faculty Performance</a>
            <a href="#" onclick="showSection('departments')">Department Analytics</a>
            <a href="#" onclick="showSection('trends')">Trends & Reports</a>
            <a href="#" onclick="showSection('criteria')">Criteria Analysis</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($dean['full_name']); ?>!</h1>
                <p>Dean of <?php echo htmlspecialchars($dean['department']); ?> | Faculty Performance Analytics Dashboard</p>
            </div>

            <!-- Overview Section -->
            <div id="overview-section" class="content-section">
                <h2>System Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['total_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['evaluated_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Faculty Evaluated</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['total_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Total Faculty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['avg_rating'] ? number_format($overall_stats['avg_rating'], 2) : 'N/A'; ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                </div>

                <div class="two-column">
                    <div class="chart-container">
                        <h3>Top Performers</h3>
                        <?php if (!empty($top_performers)): ?>
                            <table class="performance-table">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($top_performers, 0, 5) as $performer): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($performer['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($performer['department']); ?></td>
                                            <td>
                                                <span class="rating-badge rating-excellent">
                                                    <?php echo number_format($performer['avg_rating'], 2); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No performance data available yet.</p>
                        <?php endif; ?>
                    </div>

                    <div class="chart-container">
                        <h3>Department Performance</h3>
                        <canvas id="departmentChart" width="400" height="300"></canvas>
                    </div>
                </div>
            </div>

            <!-- Faculty Performance Section -->
            <div id="faculty-section" class="content-section" style="display: none;">
                <h2>Faculty Performance Analysis</h2>
                
                <div class="search-container">
                    <input type="text" id="faculty_search" placeholder="Search faculty by name, department, or position..." onkeyup="filterFacultyTable()">
                </div>

                <div class="export-buttons">
                    <button class="export-btn" onclick="exportToCSV()">Export to CSV</button>
                    <button class="export-btn" onclick="generateReport()">Generate Report</button>
                </div>

                <table class="performance-table" id="faculty_table">
                    <thead>
                        <tr>
                            <th>Faculty Name</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Evaluations</th>
                            <th>Average Rating</th>
                            <th>Min Rating</th>
                            <th>Max Rating</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faculty_performance as $faculty): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($faculty['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($faculty['department']); ?></td>
                                <td><?php echo htmlspecialchars($faculty['position']); ?></td>
                                <td><?php echo $faculty['evaluation_count']; ?></td>
                                <td>
                                    <?php if ($faculty['avg_rating']): ?>
                                        <span class="rating-badge rating-<?php 
                                            $rating = $faculty['avg_rating'];
                                            if ($rating >= 4.5) echo 'excellent';
                                            elseif ($rating >= 3.5) echo 'good';
                                            elseif ($rating >= 2.5) echo 'average';
                                            else echo 'poor';
                                        ?>">
                                            <?php echo number_format($faculty['avg_rating'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-badge rating-none">No Data</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $faculty['min_rating'] ? number_format($faculty['min_rating'], 2) : 'N/A'; ?></td>
                                <td><?php echo $faculty['max_rating'] ? number_format($faculty['max_rating'], 2) : 'N/A'; ?></td>
                                <td>
                                    <?php if ($faculty['evaluation_count'] == 0): ?>
                                        <span style="color: var(--danger-color);">Not Evaluated</span>
                                    <?php elseif ($faculty['evaluation_count'] < 3): ?>
                                        <span style="color: var(--warning-color);">Needs More Data</span>
                                    <?php else: ?>
                                        <span style="color: var(--secondary-color);">Sufficient Data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Department Analytics Section -->
            <div id="departments-section" class="content-section" style="display: none;">
                <h2>Department Analytics</h2>
                
                <div class="section-header">
                    <h2>Faculty Performance Overview</h2>
                    <p>Comprehensive performance analysis of all faculty members</p>
                </div>

                <div class="search-container">
                    <input type="text" id="faculty_search" placeholder="Search faculty by name, department, or position..." onkeyup="filterFacultyTable()">
                </div>

                <table class="performance-table" id="faculty_table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Faculty Count</th>
                            <th>Total Evaluations</th>
                            <th>Average Rating</th>
                            <th>Performance Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($department_stats as $dept): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td><?php echo $dept['faculty_count']; ?></td>
                                <td><?php echo $dept['evaluation_count']; ?></td>
                                <td>
                                    <?php if ($dept['avg_rating']): ?>
                                        <?php echo number_format($dept['avg_rating'], 2); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($dept['avg_rating']): ?>
                                        <span class="rating-badge rating-<?php 
                                            $rating = $dept['avg_rating'];
                                            if ($rating >= 4.5) echo 'excellent';
                                            elseif ($rating >= 3.5) echo 'good';
                                            elseif ($rating >= 2.5) echo 'average';
                                            else echo 'poor';
                                        ?>">
                                            <?php 
                                                if ($rating >= 4.5) echo 'Excellent';
                                                elseif ($rating >= 3.5) echo 'Good';
                                                elseif ($rating >= 2.5) echo 'Average';
                                                else echo 'Needs Improvement';
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-badge rating-none">No Data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Trends & Reports Section -->
            <div id="trends-section" class="content-section" style="display: none;">
                <h2>Trends & Reports</h2>
                
                <?php if (!empty($semester_trends)): ?>
                <div class="chart-container">
                    <h3>Evaluation Trends by Semester</h3>
                    <canvas id="trendsChart" width="400" height="200"></canvas>
                </div>
                <?php endif; ?>

                <div class="two-column">
                    <div class="chart-container">
                        <h3>Faculty Needing Attention</h3>
                        <?php if (!empty($bottom_performers)): ?>
                            <table class="performance-table">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bottom_performers as $performer): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($performer['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($performer['department']); ?></td>
                                            <td>
                                                <span class="rating-badge rating-<?php 
                                                    $rating = $performer['avg_rating'];
                                                    if ($rating >= 3.5) echo 'good';
                                                    elseif ($rating >= 2.5) echo 'average';
                                                    else echo 'poor';
                                                ?>">
                                                    <?php echo number_format($performer['avg_rating'], 2); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>All faculty are performing well!</p>
                        <?php endif; ?>
                    </div>

                    <div class="chart-container">
                        <h3>Semester Performance Summary</h3>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Evaluations</th>
                                    <th>Avg Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($semester_trends as $trend): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($trend['academic_year']); ?></td>
                                        <td><?php echo htmlspecialchars($trend['semester']); ?></td>
                                        <td><?php echo $trend['evaluation_count']; ?></td>
                                        <td><?php echo number_format($trend['avg_rating'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Criteria Analysis Section -->
            <div id="criteria-section" class="content-section" style="display: none;">
                <h2>Evaluation Criteria Analysis</h2>
                
                <div class="chart-container">
                    <h3>Overall Criteria Performance</h3>
                    <canvas id="criteriaChart" width="400" height="200"></canvas>
                </div>

                <div class="criteria-section">
                    <?php foreach ($grouped_criteria as $category => $criteria): ?>
                        <div class="criteria-category">
                            <h4><?php echo htmlspecialchars($category); ?></h4>
                            <?php foreach ($criteria as $criterion): ?>
                                <div class="criterion-item">
                                    <span><?php echo htmlspecialchars($criterion['criterion']); ?></span>
                                    <span class="rating-badge rating-<?php 
                                        $rating = $criterion['avg_rating'];
                                        if ($rating >= 4.5) echo 'excellent';
                                        elseif ($rating >= 3.5) echo 'good';
                                        elseif ($rating >= 2.5) echo 'average';
                                        else echo 'poor';
                                    ?>">
                                        <?php echo number_format($criterion['avg_rating'], 2); ?> 
                                        (<?php echo $criterion['response_count']; ?> responses)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Navigation functions
        function showSection(sectionName) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.style.display = 'none';
            });
            
            const targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.style.display = 'block';
            }
        }

        // Faculty search functionality
        function filterFacultyTable() {
            const searchInput = document.getElementById('faculty_search');
            const table = document.getElementById('faculty_table');
            const searchTerm = searchInput.value.toLowerCase();
            
            if (!table) return;
            
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let shouldShow = false;
                
                // Search in name, department, and position columns (0, 1, 2)
                for (let j = 0; j < 3; j++) {
                    if (cells[j] && cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        shouldShow = true;
                        break;
                    }
                }
                
                rows[i].style.display = shouldShow ? '' : 'none';
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

        // Export functions
        function exportToCSV() {
            alert('CSV export functionality would be implemented here');
        }

        function generateReport() {
            alert('Report generation functionality would be implemented here');
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Department performance chart
            <?php if (!empty($department_stats)): ?>
            const deptCtx = document.getElementById('departmentChart');
            if (deptCtx) {
                new Chart(deptCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($d) { return "'" . addslashes($d['department']) . "'"; }, $department_stats)); ?>],
                        datasets: [{
                            data: [<?php echo implode(',', array_map(function($d) { return $d['avg_rating'] ?: 0; }, $department_stats)); ?>],
                            backgroundColor: [
                                'rgba(52, 152, 219, 0.8)',
                                'rgba(46, 204, 113, 0.8)',
                                'rgba(155, 89, 182, 0.8)',
                                'rgba(241, 196, 15, 0.8)',
                                'rgba(231, 76, 60, 0.8)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Department comparison chart
            <?php if (!empty($department_stats)): ?>
            const deptCompCtx = document.getElementById('departmentComparisonChart');
            if (deptCompCtx) {
                new Chart(deptCompCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($d) { return "'" . addslashes($d['department']) . "'"; }, $department_stats)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_map(function($d) { return $d['avg_rating'] ?: 0; }, $department_stats)); ?>],
                            backgroundColor: 'rgba(46, 204, 113, 0.8)',
                            borderColor: 'rgb(46, 204, 113)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Trends chart
            <?php if (!empty($semester_trends)): ?>
            const trendsCtx = document.getElementById('trendsChart');
            if (trendsCtx) {
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($t) { return "'" . $t['semester'] . " " . $t['academic_year'] . "'"; }, $semester_trends)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($semester_trends, 'avg_rating')); ?>],
                            borderColor: 'rgb(52, 152, 219)',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Criteria performance chart
            <?php if (!empty($criteria_performance)): ?>
            const criteriaCtx = document.getElementById('criteriaChart');
            if (criteriaCtx) {
                new Chart(criteriaCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($c) { return "'" . addslashes($c['criterion']) . "'"; }, array_slice($criteria_performance, 0, 10))); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column(array_slice($criteria_performance, 0, 10), 'avg_rating')); ?>],
                            backgroundColor: 'rgba(155, 89, 182, 0.8)',
                            borderColor: 'rgb(155, 89, 182)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Show overview section by default
            showSection('overview');
        });
    </script>
</body>
</html>
