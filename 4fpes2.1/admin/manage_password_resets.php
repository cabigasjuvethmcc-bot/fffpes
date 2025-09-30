<?php
// TEMP: strengthen error visibility during debugging (remove after)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// TEMP: send no-cache headers and invalidate opcache for this file
if (!headers_sent()) {
    @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @header('Pragma: no-cache');
}
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
require_once __DIR__ . '/../config.php';
// Inline access check with diagnostic (temporary)
if (!isLoggedIn()) {
    header('Location: /4fpes2.1/index.php');
    exit();
}
$__role = $_SESSION['role'] ?? '';
if (strtolower($__role) !== 'admin') {
    // Show a small inline message instead of blank page
    if (!headers_sent()) { echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Access</title></head><body>"; }
    echo '<div style="margin:16px; padding:12px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; font:14px/1.4 sans-serif;">Access denied. Current role: <b>' . htmlspecialchars($__role) . '</b>. Admin role required.</div>';
    echo '</body></html>';
    exit();
}

// TEMP DEBUG: confirm script execution and surface fatal errors
$__ts = date('Y-m-d H:i:s');
echo "<!-- MANAGE_RESETS_LOADED $__ts -->\n";
if (function_exists('error_log')) {
    @error_log("MANAGE_RESETS_LOADED $__ts - " . ($_SERVER['REQUEST_URI'] ?? ''));
}
if (!headers_sent()) {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo "<pre style=\"position:fixed;left:8px;bottom:8px;z-index:999999;background:#fff3cd;color:#111;border:1px solid #ffeeba;padding:8px;border-radius:6px;max-width:95%;max-height:40vh;overflow:auto;font:12px/1.4 monospace;\">";
            echo "Shutdown fatal error:\n" . htmlspecialchars(print_r($e, true));
            echo "</pre>";
        }
    });
}

// Ensure table exists (in case schema hasn't been updated yet)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        role ENUM('Student','Faculty','Dean') NOT NULL,
        status ENUM('Pending','Resolved') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // noop
}

// Fetch all requests (with error handling)
try {
    $stmt = $pdo->query("SELECT id, identifier, role, status, created_at FROM password_reset_requests ORDER BY status ASC, created_at DESC");
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    $requests = [];
    $admin_error = 'Database error while fetching requests: ' . $e->getMessage();
    if (function_exists('error_log')) {
        @error_log('manage_password_resets: select failed - ' . $e->getMessage());
    }
}

$csrf = generateCSRFToken();
// TEMP: request count marker for diagnostics
$__req_count = is_array($requests) ? count($requests) : (is_object($requests) ? 'obj' : (isset($requests) ? 'unknown' : 'unset'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Password Reset Requests</title>
  <link rel="stylesheet" href="../styles.css">
  <style>
    /* Page-scoped visibility hardening to avoid blank look */
    html, body { color:#111 !important; background: var(--bg-color, #f8fafc); }
    .container, .container *, .table, .table * { visibility: visible !important; opacity: 1 !important; color:#111 !important; }
    .actions { display:flex; gap:0.5rem; }
    .btn { padding:0.5rem 0.8rem; border:none; border-radius:6px; cursor:pointer; font-weight:600; }
    .btn-reset { background: var(--warning-color); color:#fff; }
    .btn-resolve { background: var(--secondary-color); color:#fff; }
    .btn-disabled { opacity:0.6; cursor:not-allowed; }
    .container { max-width: 1100px; margin: 2rem auto; background:#fff; border-radius:12px; box-shadow: var(--card-shadow); padding: 16px; }
    .header { display:flex; justify-content: space-between; align-items:center; margin-bottom:1rem; }
    .back-link { text-decoration:none; }
    .table { width:100%; border-collapse: collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow: var(--card-shadow); border:1px solid #e5e7eb; }
    .table th, .table td { padding: 0.85rem 1rem; border-bottom: 1px solid #e1e5e9; text-align:left; }
    .table th { background: var(--bg-color); font-weight:600; }
    .badge { padding:4px 10px; border-radius:14px; font-size:0.85rem; }
    .badge-pending { background:#ffe9c4; color:#7a4b00; }
    .badge-resolved { background:#daf5d9; color:#0f6b1b; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h2>Password Reset Requests</h2>
      <div>
        <a class="back-link" href="admin.php">← Back to Admin Dashboard</a>
      </div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Identifier</th>
          <th>Role</th>
          <th>Requested At</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (isset($admin_error)): ?>
          <tr><td colspan="6" style="color:#7c2d12; background:#fffbeb; border:1px solid #fcd34d; padding:12px;">Error: <?php echo htmlspecialchars($admin_error); ?></td></tr>
        <?php endif; ?>
        <?php if (!$requests): ?>
          <tr><td colspan="6" style="text-align:center; padding:1rem; color:#666;">No requests found</td></tr>
        <?php else: ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;"><?php echo (int)$r['id']; ?></td>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;"><?php echo htmlspecialchars($r['identifier']); ?></td>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;"><?php echo htmlspecialchars($r['role']); ?></td>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))); ?></td>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;">
                <?php if ($r['status'] === 'Resolved'): ?>
                  <span class="badge badge-resolved" style="background:#daf5d9; color:#0f6b1b; padding:4px 10px; border-radius:14px;">Resolved</span>
                <?php else: ?>
                  <span class="badge badge-pending" style="background:#ffe9c4; color:#7a4b00; padding:4px 10px; border-radius:14px;">Pending</span>
                <?php endif; ?>
              </td>
              <td style="border-bottom:1px solid #f3f4f6; padding:8px;">
                <div class="actions" style="display:flex; gap:8px;">
                  <form method="POST" action="reset_password.php" onsubmit="return confirm('Reset password to 123?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($r['identifier']); ?>">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($r['role']); ?>">
                    <button class="btn btn-reset<?php echo $r['status']==='Resolved' ? ' btn-disabled' : ''; ?>" <?php echo $r['status']==='Resolved' ? 'disabled' : ''; ?> type="submit" style="background:#f59e0b; color:#fff; border:none; padding:6px 10px; border-radius:6px;">Reset to 123</button>
                  </form>
                  <form method="POST" action="mark_resolved.php" onsubmit="return confirm('Mark as resolved?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                    <button class="btn btn-resolve<?php echo $r['status']==='Resolved' ? ' btn-disabled' : ''; ?>" <?php echo $r['status']==='Resolved' ? 'disabled' : ''; ?> type="submit" style="background:#10b981; color:#fff; border:none; padding:6px 10px; border-radius:6px;">Mark Resolved</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <div style="margin-top:8px; font:12px/1.2 monospace; color:#374151;">CONTAINER INNER MARKER (below table)</div>
  </div>
  <script>
    (function(){
      const c = document.getElementById('admin-reset-container');
      if (c) {
        console.log('[manage_password_resets] container childElementCount=', c.childElementCount, 'innerHTML length=', (c.innerHTML||'').length);
        c.style.display = 'block';
        c.style.visibility = 'visible';
        c.style.opacity = '1';
      }
    })();
  </script>
</body>
</html>
