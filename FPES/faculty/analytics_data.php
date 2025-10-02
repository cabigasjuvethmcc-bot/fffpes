<?php
require_once '../config.php';
requireRole('faculty');
header('Content-Type: application/json');

try {
    // Validate CSRF token (optional for GET; required for POST)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }
        $semester = trim($_POST['semester'] ?? '');
        $academic_year = trim($_POST['academic_year'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
    } else {
        $semester = trim($_GET['semester'] ?? '');
        $academic_year = trim($_GET['academic_year'] ?? '');
        $subject = trim($_GET['subject'] ?? '');
    }

    // Build filters
    $params = [ (int)$_SESSION['faculty_id'] ];
    // Only include Student → Faculty evaluations
    $where = [
        "e.faculty_id = ?",
        "e.status = 'submitted'",
        "(e.evaluator_role = 'student' OR e.student_id IS NOT NULL)"
    ];
    if ($semester !== '') { $where[] = 'e.semester = ?'; $params[] = $semester; }
    if ($academic_year !== '') { $where[] = 'e.academic_year = ?'; $params[] = $academic_year; }
    if ($subject !== '') { $where[] = 'e.subject = ?'; $params[] = $subject; }
    $whereSql = implode(' AND ', $where);

    // Summary stats: average rating across evaluations and total evaluators (distinct evaluations)
    $stmt = $pdo->prepare("SELECT 
        COUNT(DISTINCT e.id) AS evaluations_count,
        AVG(e.overall_rating) AS avg_overall_rating
      FROM evaluations e
      WHERE $whereSql");
    $stmt->execute($params);
    $summary = $stmt->fetch() ?: ['evaluations_count'=>0,'avg_overall_rating'=>null];

    // Criteria aggregates: avg per criterion and count
    $stmt = $pdo->prepare("SELECT 
        ec.category,
        ec.criterion,
        AVG(er.rating) AS avg_rating,
        COUNT(er.rating) AS response_count
      FROM evaluation_responses er
      JOIN evaluation_criteria ec ON ec.id = er.criterion_id
      JOIN evaluations e ON e.id = er.evaluation_id
      WHERE $whereSql
      GROUP BY ec.id, ec.category, ec.criterion
      ORDER BY ec.category, ec.id");
    $stmt->execute($params);
    $criteria = $stmt->fetchAll();

    // Optional time-series (average rating per evaluation submission date) for line chart
    $stmt = $pdo->prepare("SELECT 
        DATE(e.submitted_at) AS day,
        AVG(e.overall_rating) AS avg_rating,
        COUNT(*) AS evals
      FROM evaluations e
      WHERE $whereSql
      GROUP BY DATE(e.submitted_at)
      ORDER BY day");
    $stmt->execute($params);
    $timeseries = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'summary' => [
            'evaluations_count' => (int)($summary['evaluations_count'] ?? 0),
            'avg_overall_rating' => $summary['avg_overall_rating'] !== null ? (float)$summary['avg_overall_rating'] : null,
        ],
        'criteria' => $criteria,
        'timeseries' => $timeseries
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
