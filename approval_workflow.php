<?php
declare(strict_types=1);
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', "1");

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Initialize hide numbers session variable
if (!isset($_SESSION['hide_numbers'])) {
    $_SESSION['hide_numbers'] = false;
}

// Toggle hide numbers state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_hide_numbers'])) {
    $_SESSION['hide_numbers'] = !$_SESSION['hide_numbers'];
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'hide_numbers' => $_SESSION['hide_numbers']]);
        exit;
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

try {
    require_once __DIR__ . '/database.php';
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Load current user
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    if (!$user) {
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Initialize data arrays
$pending_approvals = [];
$my_approvals = [];
$approval_history = [];

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notification_read'])) {
    try {
        $notification_id = (int)$_POST['notification_id'];
        
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$notification_id, $user_id]);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $_SESSION['success_message'] = "Notification marked as read!";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
        
    } catch (Exception $e) {
        error_log("Mark notification read error: " . $e->getMessage());
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error_message'] = "Error marking notification as read: " . $e->getMessage();
    }
}

// Handle marking all notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
    try {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND (is_read = 0 OR is_read IS NULL)");
        $stmt->execute([$user_id]);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        
        $_SESSION['success_message'] = "All notifications marked as read!";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
        
    } catch (Exception $e) {
        error_log("Mark all notifications read error: " . $e->getMessage());
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error_message'] = "Error marking notifications as read: " . $e->getMessage();
    }
}

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['approve_proposal']) || isset($_POST['reject_proposal'])) {
            $proposal_id = (int)$_POST['proposal_id'];
            $comments = trim($_POST['comments'] ?? '');
            $action = isset($_POST['approve_proposal']) ? 'Approved' : 'Rejected';
            
            // First, verify the proposal exists and is in submitted status
            $verify_stmt = $db->prepare("
                SELECT id, title, submitted_by, status 
                FROM budget_proposals 
                WHERE id = ? AND status = 'Submitted'
            ");
            $verify_stmt->execute([$proposal_id]);
            $proposal = $verify_stmt->fetch();
            
            if (!$proposal) {
                throw new Exception("Proposal not found or not in submitted status");
            }
            
            // Record approval/rejection
$stmt = $db->prepare("
    INSERT INTO workflow_approvals 
    (proposal_id, approver_id, action, comments, step_completed) 
    VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([$proposal_id, $user_id, $action, $comments, 1]); // Set step_completed to 1
            
            // Update proposal status
            $new_status = $action === 'Approved' ? 'Approved' : 'Rejected';
            $date_field = $action === 'Approved' ? 'approved_date' : 'rejected_date';
            
            $update_stmt = $db->prepare("
                UPDATE budget_proposals 
                SET status = ?, $date_field = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$new_status, $proposal_id]);
            
            // Create notification for submitter
            $message = $action === 'Approved' 
                ? "Your budget proposal '{$proposal['title']}' has been approved!" 
                : "Your budget proposal '{$proposal['title']}' has been rejected." . ($comments ? " Comments: $comments" : "");
            
            $notify_stmt = $db->prepare("
                INSERT INTO notifications (user_id, message, type) 
                VALUES (?, ?, ?)
            ");
            $notify_type = $action === 'Approved' ? 'success' : 'warning';
            $notify_stmt->execute([$proposal['submitted_by'], $message, $notify_type]);
            
            $_SESSION['success_message'] = "Proposal " . strtolower($action) . " successfully!";
        }
        
    } catch (Exception $e) {
        error_log("Approval workflow error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error processing request: " . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission
    header("Location: approval_workflow.php");
    exit;
}

// Load data based on user role and permissions
try {
    // Handle proposal filtering
    $filter_proposal_id = $_GET['proposal_id'] ?? null;
    
    // Get proposals pending approval - FIXED QUERY
    $pending_stmt = $db->prepare("
        SELECT 
            bp.*, 
            u.name as submitter_name,
            d.name as department_name,
            COUNT(bi.id) as item_count,
            COALESCE(SUM(bi.total_cost), 0) as total_amount
        FROM budget_proposals bp
        JOIN users u ON bp.submitted_by = u.id
        JOIN departments d ON bp.department = d.id
        LEFT JOIN budget_items bi ON bp.id = bi.proposal_id
        WHERE bp.status = 'Submitted'
        GROUP BY bp.id, u.name, d.name
        ORDER BY bp.submitted_date DESC
    ");
    $pending_stmt->execute();
    $pending_approvals = $pending_stmt->fetchAll();
    
    // Apply proposal filter if specified
    if ($filter_proposal_id) {
        $pending_approvals = array_filter($pending_approvals, function($proposal) use ($filter_proposal_id) {
            return $proposal['id'] == $filter_proposal_id;
        });
    }

    // Get approvals made by current user - FIXED QUERY
    try {
        $my_approvals_stmt = $db->prepare("
            SELECT 
                bp.id, bp.title, bp.submitted_date, bp.status,
                u.name as submitter_name, 
                d.name as department_name,
                COUNT(bi.id) as item_count, 
                COALESCE(SUM(bi.total_cost), 0) as total_amount,
                wa.action, wa.comments, wa.approved_at
            FROM workflow_approvals wa
            JOIN budget_proposals bp ON wa.proposal_id = bp.id
            JOIN users u ON bp.submitted_by = u.id
            JOIN departments d ON bp.department = d.id
            LEFT JOIN budget_items bi ON bp.id = bi.proposal_id
            WHERE wa.approver_id = ?
            GROUP BY bp.id, u.name, d.name, wa.id, wa.action, wa.comments, wa.approved_at
            ORDER BY wa.approved_at DESC
            LIMIT 20
        ");
        $my_approvals_stmt->execute([$user_id]);
        $my_approvals = $my_approvals_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("My approvals query error: " . $e->getMessage());
        $my_approvals = [];
    }

    // Get approval history - FIXED QUERY
    try {
        $history_stmt = $db->prepare("
            SELECT 
                bp.id, bp.title, bp.submitted_date, bp.status, bp.updated_at,
                u.name as submitter_name, 
                d.name as department_name,
                COALESCE(SUM(bi.total_cost), 0) as total_amount
            FROM budget_proposals bp
            JOIN users u ON bp.submitted_by = u.id
            JOIN departments d ON bp.department = d.id
            LEFT JOIN budget_items bi ON bp.id = bi.proposal_id
            WHERE bp.status IN ('Approved', 'Rejected')
            AND (bp.submitted_by = ? OR EXISTS (
                SELECT 1 FROM workflow_approvals wa2 
                WHERE wa2.proposal_id = bp.id AND wa2.approver_id = ?
            ))
            GROUP BY bp.id, u.name, d.name
            ORDER BY bp.updated_at DESC
            LIMIT 50
        ");
        $history_stmt->execute([$user_id, $user_id]);
        $approval_history = $history_stmt->fetchAll();
    } catch (Exception $e) {
        error_log("History query error: " . $e->getMessage());
        $approval_history = [];
    }

} catch (Exception $e) {
    error_log("Data load error: " . $e->getMessage());
    $_SESSION['error_message'] = "Error loading approval data: " . $e->getMessage();
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// Safe output function
function safe_output($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars((string)$value);
}

// Safe number format function with hide numbers support
function safe_number_format($value, $decimals = 2, $default = '0.00') {
    if ($value === null || $value === '') {
        return $default;
    }
    
    // Ensure the value is a float
    $float_value = (float)$value;
    
    // Check if it's a valid number
    if (!is_numeric($float_value)) {
        return $default;
    }
    
    // Check if we should hide numbers
    if (isset($_SESSION['hide_numbers']) && $_SESSION['hide_numbers']) {
        return str_repeat('*', 6); // Show asterisks instead of numbers
    }
    
    return number_format($float_value, $decimals);
}

// Function to format amounts with hide/show capability
function format_amount($value, $decimals = 2) {
    if (!isset($_SESSION['hide_numbers']) || !$_SESSION['hide_numbers']) {
        return '₱' . number_format((float)$value, $decimals);
    } else {
        return '₱' . str_repeat('*', 6); // Show asterisks for hidden amounts
    }
}

// Function to get notifications
function getNotifications(PDO $db, int $user_id): array {
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL)
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
        return [];
    }
}

// Fetch notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, fn($n) => empty($n['is_read']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Workflow | Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-green': '#28644c',
                        'sidebar-green': '#2f855A',
                        'white': '#ffffff',
                        'gray-bg': '#f3f4f6',
                        'notification-red': '#ef4444',
                        'hover-state': 'rgba(255, 255, 255, 0.3)',
                        'dark-text': '#1f2937',
                    }
                }
            }
        }
    </script>
    <style>
        .eye-toggle-btn {
            background: transparent;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }
        
        .eye-toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-1px);
        }
        
        .hidden-numbers {
            letter-spacing: 2px;
            font-family: monospace;
        }

        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
        }

        .hamburger-line {
            width: 24px;
            height: 3px;
            background-color: #FFFFFF;
            margin: 4px 0;
            transition: all 0.3s;
        }
        .sidebar-item.active {
            background-color: rgba(255, 255, 255, 0.2);
            border-left: 4px solid white;
        }
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .card-shadow {
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 9999px;
        }
        .status-pending {
            background-color: rgba(245, 158, 11, 0.1);
            color: #D97706;
        }
        .status-approved {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16A34A;
        }
        .status-rejected {
            background-color: rgba(239, 68, 68, 0.1);
            color: #DC2626;
        }
        .status-revision {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3B82F6;
        }
        .status-submitted {
            background-color: rgba(156, 163, 175, 0.1);
            color: #6B7280;
        }
        #sidebar {
            transition: transform 0.3s ease-in-out;
            background-color: #2f855A;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            z-index: 40;
        }
        
        #hamburger-btn {
            display: block !important;
        }
        
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(-100%);
                position: fixed;
                height: 100%;
                min-height: 100vh;
            }
            #sidebar.active {
                transform: translateX(0);
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 30;
            }
            .overlay.active {
                display: block;
            }
        }
        
        @media (min-width: 769px) {
            #sidebar {
                transform: translateX(0);
            }
            #sidebar.hidden {
                transform: translateX(-100%);
                position: fixed;
            }
            .overlay {
                display: none;
            }
            #main-content.full-width {
                margin-left: 0;
                width: 100%;
            }
        }
        
        .sidebar-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #2f855A;
        }
        
        .main-footer {
            background-color: #28644c;
            color: white;
            padding: 1.5rem;
            margin-top: auto;
        }

        html, body {
            height: 100%;
        }
        
        .page-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar-content {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .submenu.active {
            max-height: 500px;
        }
        
        .submenu-item {
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            display: block;
            text-decoration: none;
        }
        
        .submenu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .submenu-item.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
        }
        
        .rotate-180 {
            transform: rotate(180deg);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .close-modal {
    color: #aaa;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    transition: all 0.2s;
}

.close-modal:hover {
    color: black;
    background-color: #f3f4f6;
}
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #2f855A;
            box-shadow: 0 0 0 3px rgba(47, 133, 90, 0.2);
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background-color: #2f855A;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #28644c;
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .btn-secondary:hover {
            background-color: #d1d5db;
        }
        
        .btn-success {
            background-color: #10B981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
        }
        
        .btn-warning {
            background-color: #F59E0B;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #D97706;
        }
        
        .btn-danger {
            background-color: #EF4444;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #DC2626;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th, .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background-color: #f9fafb;
            font-weight: 500;
            color: #374151;
        }
        
        .action-btn {
            padding: 0.4rem 0.8rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            cursor: pointer;
            border: 1px solid;
            background: white;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .action-btn.approve {
            background-color: #D1FAE5;
            color: #059669;
            border-color: #059669;
        }
        
        .action-btn.approve:hover {
            background-color: #A7F3D0;
            color: #047857;
        }
        
        .action-btn.reject {
            background-color: #FEE2E2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.reject:hover {
            background-color: #FECACA;
            color: #B91C1C;
        }
        
        .metric-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        
        .tab-container {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            color: #6b7280;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #2f855A;
        }
        
        .tab.active {
            border-bottom-color: #2f855A;
            color: #2f855A;
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Ensure buttons are clickable */
        button, .btn, .action-btn, .tab {
            cursor: pointer;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        /* Prevent modal from blocking content when hidden */
        .modal[style*="display: none"] {
            z-index: -1;
        }

        .approval-item {
            transition: background-color 0.2s;
        }
        
        .approval-item:hover {
            background-color: #f9fafb;
        }
        
        .priority-high {
            border-left: 4px solid #EF4444;
        }
        
        .priority-medium {
            border-left: 4px solid #F59E0B;
        }
        
        .priority-low {
            border-left: 4px solid #10B981;
        }
        
        .filter-notice {
            background-color: #EFF6FF;
            border: 1px solid #3B82F6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .notification-item {
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f8fafc;
        }
        
        .mark-read-btn {
            background: transparent;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mark-read-btn:hover {
            background-color: #10B981;
            color: white;
            border-color: #10B981;
        }
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for user profile -->
    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">User Profile</h2>
            <div class="flex items-center mb-6">
                <i class="fa-solid fa-user text-[40px] bg-primary-green text-white px-3 py-3 rounded-full"></i>
                <div class="ml-4">
                    <h3 class="text-lg font-bold" id="profile-name"><?= safe_output($user['name']) ?></h3>
                    <p class="text-gray-500"><?= ucfirst(safe_output($user['role'])) ?></p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <h4 class="font-medium mb-2">Account Settings</h4>
                    <button class="btn btn-secondary w-full mb-2">Edit Profile</button>
                    <button class="btn btn-secondary w-full mb-2">Change Password</button>
                </div>
                <div>
                    <h4 class="font-medium mb-2">System</h4>
                    <button class="btn btn-secondary w-full mb-2">Preferences</button>
                    <button class="btn btn-secondary w-full" id="logout-btn">Logout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for notifications -->
<div id="notification-modal" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">Notifications</h2>
            <div class="flex items-center space-x-2">
                <button id="mark-all-read-btn" class="btn btn-secondary text-sm">
                    <i class="fa-solid fa-check-double mr-1"></i>Mark All as Read
                </button>
                <span class="close-modal">&times;</span>
            </div>
        </div>
        <div id="notification-list">
            <div class="text-center text-gray-500 py-4">Loading notifications...</div>
        </div>
    </div>
</div>

    <!-- Modal for approval actions -->
    <div id="approval-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4" id="approval-modal-title">Approve Proposal</h2>
            <form method="POST" id="approval-form">
                <input type="hidden" name="proposal_id" id="modal-proposal-id">
                <div class="form-group">
                    <label class="form-label">Comments</label>
                    <textarea name="comments" class="form-textarea" rows="4" placeholder="Enter your comments or feedback..."></textarea>
                </div>
                <div class="flex space-x-2 mt-6">
                    <button type="button" class="btn btn-secondary flex-1" onclick="closeModal('approval-modal')">Cancel</button>
                    <button type="submit" name="approve_proposal" class="btn btn-success flex-1" id="approve-btn">
                        <i class="fa-solid fa-check mr-2"></i>Approve
                    </button>
                    <button type="submit" name="reject_proposal" class="btn btn-danger flex-1" id="reject-btn">
                        <i class="fa-solid fa-times mr-2"></i>Reject
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Page Container -->
    <div class="page-container">
        <!-- Sidebar -->
        <div id="sidebar" class="w-64 flex flex-col fixed md:relative">
            <div class="sidebar-content">
                <div class="p-6 bg-sidebar-green">
                    <div class="flex justify-between items-center">
                        <h1 class="text-xl font-bold text-white flex items-center">
                            <i class='bx bx-wallet-alt text-white mr-2'></i>
                            Dashboard
                        </h1>
                        <button id="close-sidebar" class="text-white">
                            <i class='bx bx-x text-2xl'></i>
                        </button>
                    </div>
                    <p class="text-xs text-white/90 mt-1">Microfinancial Management System</p>
                </div>
                
                <!-- Navigation -->
                <div class="flex-1 overflow-y-auto px-2 py-4">
                    <div class="space-y-4">
                        <!-- Main Menu Item -->
                        <a href="dashboard8.php" class="sidebar-item py-3 px-4 rounded-lg cursor-pointer mx-2 flex items-center hover:bg-hover-state transition-colors duration-200">
                            <i class='bx bx-home text-white mr-3 text-lg'></i>
                            <span class="text-sm font-medium text-white">FINANCIAL</span>
                        </a>
                     
                        <!-- Disbursement Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="disbursement">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Disbursement</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="disbursement"></i>
                            </div>
                            <div class="submenu mt-1" id="disbursement-submenu">
                                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement Request</a>
                                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>
                                <a href="approved_disbursements.php" class="submenu-item transition-colors duration-200">Approved Disbursements</a>
                                <a href="rejected_disbursements.php" class="submenu-item transition-colors duration-200">Rejected Disbursements</a>
                                <a href="disbursement_reports.php" class="submenu-item transition-colors duration-200">Disbursement Reports</a>
                            </div>
                        </div>
                        
                        <!-- General Ledger Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ledger">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">General Ledger</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ledger"></i>
                            </div>
                            <div class="submenu mt-1" id="ledger-submenu">
                                <a href="chart_of_accounts.php" class="submenu-item transition-colors duration-200">Chart of Accounts</a>
                                <a href="journal_entry.php" class="submenu-item transition-colors duration-200">Journal Entry</a>
                                <a href="ledger_table.php" class="submenu-item transition-colors duration-200">Ledger Table</a>
                            </div>
                        </div>
                        
                        <!-- AP/AR Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ap-ar">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">AP/AR</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ap-ar"></i>
                            </div>
                            <div class="submenu mt-1" id="ap-ar-submenu">
                                <a href="vendors_customers.php" class="submenu-item transition-colors duration-200">Payable/Receivable</a>
                                <a href="invoices.php" class="submenu-item transition-colors duration-200">Invoices</a>
                                <a href="payment_entry.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="aging_reports.php" class="submenu-item transition-colors duration-200">Aging Reports</a>
                            </div>
                        </div>
                   
                        <!-- Collection Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="collection">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Collection</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="collection"></i>
                            </div>
                            <div class="submenu mt-1" id="collection-submenu">
                                <a href="payment_entry_collection.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="receipt_generation.php" class="submenu-item transition-colors duration-200">Receipt Generation</a>
                                <a href="collection_dashboard.php" class="submenu-item transition-colors duration-200">Collection Dashboard</a>
                                <a href="outstanding_balances.php" class="submenu-item transition-colors duration-200">Outstanding Balances</a>
                                <a href="collection_reports.php" class="submenu-item transition-colors duration-200">Collection Reports</a>
                            </div>
                        </div>

                        <!-- Budget Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="budget">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Budget Management</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="budget"></i>
                            </div>
                            <div class="submenu mt-1 active" id="budget-submenu">
                                <a href="budget_proposal.php" class="submenu-item transition-colors duration-200">Budget Proposal</a>
                                <a href="approval_workflow.php" class="submenu-item active transition-colors duration-200">Approval Workflow</a>
                                <a href="budget_vs_actual.php" class="submenu-item transition-colors duration-200">Budget vs Actual</a>
                                <a href="budget_reports.php" class="submenu-item transition-colors duration-200">Budget Reports</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer inside sidebar -->
                <div class="p-4 text-center text-xs text-white/80 border-t border-white/10 mt-auto">
                    <p>© 2025 Financial Dashboard. All rights reserved.</p>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div id="main-content" class="flex-1 overflow-y-auto flex flex-col">
            <!-- Header - MODIFIED to include eye toggle button -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Approval Workflow</h1>
                        <p class="text-sm text-white/90">Manage and track budget proposal approvals</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <!-- Number Visibility Toggle Button -->
                    <form method="POST" id="hide-numbers-form" class="inline">
                        <input type="hidden" name="toggle_hide_numbers" value="1">
                        <button type="submit" class="eye-toggle-btn" title="<?php echo $_SESSION['hide_numbers'] ? 'Show Numbers' : 'Hide Numbers'; ?>">
                            <i class='bx <?php echo $_SESSION['hide_numbers'] ? 'bx-show' : 'bx-hide'; ?> text-xl'></i>
                        </button>
                    </form>
                    
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center hidden" id="notification-badge">3</span>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200 hover:bg-green-600 rounded">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?= safe_output($user['name']) ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <?= safe_output($_SESSION['success_message']) ?>
                        <?php unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?= safe_output($_SESSION['error_message']) ?>
                        <?php unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>

                <!-- Filter Notice -->
                <?php if (isset($_GET['proposal_id'])): ?>
                    <div class="filter-notice mb-4">
                        <div class="flex items-center">
                            <i class="fas fa-filter text-blue-500 mr-2"></i>
                            <span class="text-blue-700">
                                Showing results for Proposal ID: <strong><?= safe_output($_GET['proposal_id']) ?></strong>
                                <a href="approval_workflow.php" class="ml-2 text-blue-500 hover:text-blue-700 underline">Show all proposals</a>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Stats - MODIFIED to use format_amount function -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card">
                        <div class="stat-value text-primary-green"><?= count($pending_approvals) ?></div>
                        <div class="stat-label">Pending My Approval</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-blue-600"><?= count($my_approvals) ?></div>
                        <div class="stat-label">My Decisions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-orange-600">
                            <?= count(array_filter($my_approvals, fn($a) => $a['action'] === 'Approved')) ?>
                        </div>
                        <div class="stat-label">Approved by Me</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-green-600">
                            <?= count(array_filter($approval_history, fn($h) => $h['status'] === 'Approved')) ?>
                        </div>
                        <div class="stat-label">Total Approved</div>
                    </div>
                </div>

                <!-- Tabs for different views -->
                <div class="tab-container">
                    <div class="tab active" data-tab="pending">Pending Approval</div>
                    <div class="tab" data-tab="my-approvals">My Decisions</div>
                    <div class="tab" data-tab="history">Approval History</div>
                </div>

                <!-- Pending Approval Tab - MODIFIED to use format_amount function -->
                <div class="tab-content active" id="pending-tab">
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Proposals Pending My Approval</h3>
                        <?php if (empty($pending_approvals)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-inbox text-4xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No proposals pending your approval</p>
                                <p class="text-sm">All caught up! New proposals will appear here when they reach your approval stage.</p>
                                <?php if (isset($_GET['proposal_id'])): ?>
                                    <p class="text-sm mt-2">
                                        The proposal you're looking for might have been approved, rejected, or is awaiting approval from someone else.
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Proposal Title</th>
                                            <th>Department</th>
                                            <th>Submitter</th>
                                            <th>Amount</th>
                                            <th>Days Pending</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_approvals as $proposal): 
                                            $days_pending = floor((time() - strtotime($proposal['submitted_date'])) / (60 * 60 * 24));
                                            $priority = $days_pending > 7 ? 'high' : ($days_pending > 3 ? 'medium' : 'low');
                                        ?>
                                            <tr class="approval-item priority-<?= $priority ?>">
                                                <td class="font-medium"><?= safe_output($proposal['title']) ?></td>
                                                <td><?= safe_output($proposal['department_name']) ?></td>
                                                <td><?= safe_output($proposal['submitter_name']) ?></td>
                                                <td class="font-semibold <?php echo $_SESSION['hide_numbers'] ? 'amount-masked' : ''; ?>">
                                                    <?= format_amount((float)$proposal['total_amount'], 2) ?>
                                                </td>
                                                <td>
                                                    <span class="<?= $days_pending > 7 ? 'text-red-600 font-semibold' : ($days_pending > 3 ? 'text-orange-600' : 'text-gray-600') ?>">
                                                        <?= $days_pending ?> days
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="action-btn approve" onclick="openApprovalModal(<?= $proposal['id'] ?>, 'approve')">
                                                        <i class="fa-solid fa-check mr-1"></i>Approve
                                                    </button>
                                                    <button class="action-btn reject" onclick="openApprovalModal(<?= $proposal['id'] ?>, 'reject')">
                                                        <i class="fa-solid fa-times mr-1"></i>Reject
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- My Approvals Tab - MODIFIED to use format_amount function -->
                <div class="tab-content" id="my-approvals-tab">
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">My Approval Decisions</h3>
                        <?php if (empty($my_approvals)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-clipboard-check text-4xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No approval decisions made yet</p>
                                <p class="text-sm">Your approval decisions will appear here once you start reviewing proposals.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Proposal Title</th>
                                            <th>Department</th>
                                            <th>Submitter</th>
                                            <th>Amount</th>
                                            <th>My Action</th>
                                            <th>Date</th>
                                            <th>Comments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($my_approvals as $approval): ?>
                                            <tr class="approval-item">
                                                <td class="font-medium"><?= safe_output($approval['title']) ?></td>
                                                <td><?= safe_output($approval['department_name']) ?></td>
                                                <td><?= safe_output($approval['submitter_name']) ?></td>
                                                <td class="font-semibold <?php echo $_SESSION['hide_numbers'] ? 'amount-masked' : ''; ?>">
                                                    <?= format_amount((float)$approval['total_amount'], 2) ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($approval['action']) ?>">
                                                        <?= safe_output($approval['action']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M j, Y g:i A', strtotime($approval['approved_at'])) ?></td>
                                                <td class="max-w-xs truncate"><?= safe_output($approval['comments']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Approval History Tab - MODIFIED to use format_amount function -->
                <div class="tab-content" id="history-tab">
                    <div class="metric-card">
                        <h3 class="text-lg font-semibold mb-4">Approval History</h3>
                        <?php if (empty($approval_history)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fa-solid fa-history text-4xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No approval history found</p>
                                <p class="text-sm">Approval history will appear here once proposals go through the workflow.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Proposal Title</th>
                                            <th>Department</th>
                                            <th>Submitter</th>
                                            <th>Amount</th>
                                            <th>Final Status</th>
                                            <th>Completed Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approval_history as $history): ?>
                                            <tr class="approval-item">
                                                <td class="font-medium"><?= safe_output($history['title']) ?></td>
                                                <td><?= safe_output($history['department_name']) ?></td>
                                                <td><?= safe_output($history['submitter_name']) ?></td>
                                                <td class="font-semibold <?php echo $_SESSION['hide_numbers'] ? 'amount-masked' : ''; ?>">
                                                    <?= format_amount((float)$history['total_amount'], 2) ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($history['status']) ?>">
                                                        <?= safe_output($history['status']) ?>
                                                    </span>
                                                </td>
                                                <td><?= date('M j, Y', strtotime($history['updated_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <footer class="main-footer">
                <div class="text-center">
                    <p class="text-sm">© 2025 Financial Dashboard. All rights reserved.</p>
                    <p class="text-xs mt-1 opacity-80">Powered by Microfinancial Management System</p>
                </div>
            </footer>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded - initializing event listeners');
            
            // Sidebar functionality
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const closeSidebar = document.getElementById('close-sidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            const mainContent = document.getElementById('main-content');

            function toggleSidebar() {
                console.log('Toggle sidebar clicked');
                if (window.innerWidth < 769) {
                    sidebar.classList.toggle('active');
                    overlay.classList.toggle('active');
                } else {
                    sidebar.classList.toggle('hidden');
                    mainContent.classList.toggle('full-width');
                }
            }

            if (hamburgerBtn) {
                hamburgerBtn.addEventListener('click', toggleSidebar);
            }

            if (closeSidebar) {
                closeSidebar.addEventListener('click', function() {
                    console.log('Close sidebar clicked');
                    if (window.innerWidth < 769) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    } else {
                        sidebar.classList.add('hidden');
                        mainContent.classList.add('full-width');
                    }
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function() {
                    console.log('Overlay clicked');
                    if (window.innerWidth < 769) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    }
                });
            }

            // Sidebar submenu functionality
            const categoryToggles = document.querySelectorAll('.sidebar-category');
            categoryToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const category = this.getAttribute('data-category');
                    const submenu = document.getElementById(`${category}-submenu`);
                    const arrow = document.querySelector(`.category-arrow[data-category="${category}"]`);
                    
                    if (submenu) {
                        submenu.classList.toggle('active');
                    }
                    if (arrow) {
                        arrow.classList.toggle('rotate-180');
                    }
                });
            });

            // Modal functionality
            const notificationBtn = document.getElementById('notification-btn');
            const notificationModal = document.getElementById('notification-modal');
            const profileBtn = document.getElementById('profile-btn');
            const profileModal = document.getElementById('profile-modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            const logoutBtn = document.getElementById('logout-btn');

            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    notificationModal.style.display = 'block';
                    loadNotifications();
                });
            }

            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    profileModal.style.display = 'block';
                });
            }

            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    closeAllModals();
                });
            });

            window.addEventListener('click', function(event) {
                if (event.target.classList.contains('modal')) {
                    closeAllModals();
                }
            });

            // Tab functionality
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs and tab contents
                    tabs.forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    const tabContent = document.getElementById(`${tabId}-tab`);
                    if (tabContent) {
                        tabContent.classList.add('active');
                    }
                });
            });

            // Handle hide numbers form submission with AJAX
            const hideNumbersForm = document.getElementById('hide-numbers-form');
            if (hideNumbersForm) {
                hideNumbersForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    
                    const button = this.querySelector('button[type="submit"]');
                    const originalHtml = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin text-xl"></i>';
                    button.disabled = true;
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update button icon based on new state
                            const icon = button.querySelector('i');
                            if (data.hide_numbers) {
                                icon.className = 'bx bx-show text-xl';
                                button.title = 'Show Numbers';
                            } else {
                                icon.className = 'bx bx-hide text-xl';
                                button.title = 'Hide Numbers';
                            }
                            
                            // Reload the page to update all number displays
                            window.location.reload();
                        } else {
                            alert('Error toggling number visibility');
                            button.innerHTML = originalHtml;
                            button.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error toggling number visibility');
                        button.innerHTML = originalHtml;
                        button.disabled = false;
                    });
                });
            }

            // Load notifications with mark as read functionality
            function loadNotifications() {
                const notifications = <?php echo json_encode($notifications ?? []); ?>;
                const notificationList = document.getElementById('notification-list');
                const notificationBadge = document.getElementById('notification-badge');
                
                if (notificationBadge) {
                    const unreadCount = <?php echo count($unread_notifications ?? []); ?>;
                    if (unreadCount > 0) {
                        notificationBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                        notificationBadge.classList.remove('hidden');
                    } else {
                        notificationBadge.classList.add('hidden');
                    }
                }
                
                if (notificationList) {
                    notificationList.innerHTML = '';
                    
                    if (notifications.length === 0) {
                        notificationList.innerHTML = '<div class="text-center text-gray-500 py-4">No notifications</div>';
                    } else {
                        const unreadNotifications = notifications.filter(n => !n.is_read);
                        const readNotifications = notifications.filter(n => n.is_read);
                        
                        // Show unread notifications first
                        if (unreadNotifications.length > 0) {
                            const unreadSection = document.createElement('div');
                            unreadSection.className = 'mb-4';
                            unreadSection.innerHTML = '<h3 class="font-semibold text-gray-700 mb-2">Unread</h3>';
                            
                            unreadNotifications.forEach(notification => {
                                const notificationEl = createNotificationElement(notification, false);
                                unreadSection.appendChild(notificationEl);
                            });
                            
                            notificationList.appendChild(unreadSection);
                        }
                        
                        // Show read notifications
                        if (readNotifications.length > 0) {
                            const readSection = document.createElement('div');
                            readSection.innerHTML = '<h3 class="font-semibold text-gray-700 mb-2">Read</h3>';
                            
                            readNotifications.forEach(notification => {
                                const notificationEl = createNotificationElement(notification, true);
                                readSection.appendChild(notificationEl);
                            });
                            
                            notificationList.appendChild(readSection);
                        }
                    }
                }
            }

            // Create notification element with mark as read button
            function createNotificationElement(notification, isRead) {
                const notificationEl = document.createElement('div');
                notificationEl.className = `p-3 border-b border-gray-200 notification-item ${isRead ? 'bg-gray-50' : 'bg-white'}`;
                
                const readBadge = isRead ? 
                    '<span class="text-xs text-green-600 bg-green-100 px-2 py-1 rounded ml-2">Read</span>' : 
                    '<span class="text-xs text-blue-600 bg-blue-100 px-2 py-1 rounded ml-2">New</span>';
                
                const markReadButton = !isRead ? 
                    `<button class="mark-read-btn text-xs text-gray-500 hover:text-green-600 ml-2" data-id="${notification.id}">
                        <i class="fa-solid fa-check mr-1"></i>Mark as Read
                    </button>` : 
                    '';
                
                notificationEl.innerHTML = `
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-medium flex items-center">
                                ${notification.message || 'Notification'}
                                ${readBadge}
                            </div>
                            <div class="text-sm text-gray-500 mt-1">${new Date(notification.created_at).toLocaleDateString()} at ${new Date(notification.created_at).toLocaleTimeString()}</div>
                        </div>
                        <div class="flex space-x-2">
                            ${markReadButton}
                        </div>
                    </div>
                `;
                
                return notificationEl;
            }

            // Handle mark as read button clicks
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('mark-read-btn') || e.target.closest('.mark-read-btn')) {
                    const button = e.target.classList.contains('mark-read-btn') ? e.target : e.target.closest('.mark-read-btn');
                    const notificationId = button.getAttribute('data-id');
                    
                    markNotificationAsRead(notificationId, button);
                }
            });

            // Handle mark all as read
            const markAllReadBtn = document.getElementById('mark-all-read-btn');
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function() {
                    markAllNotificationsAsRead();
                });
            }

            // Mark single notification as read
            function markNotificationAsRead(notificationId, button) {
                const originalHtml = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>';
                button.disabled = true;
                
                const formData = new FormData();
                formData.append('mark_notification_read', '1');
                formData.append('notification_id', notificationId);
                formData.append('ajax', '1');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove the notification element or update its appearance
                        const notificationElement = button.closest('.border-b');
                        if (notificationElement) {
                            // Update to read appearance
                            notificationElement.className = 'p-3 border-b border-gray-200 bg-gray-50 notification-item';
                            
                            // Remove mark as read button
                            const markReadBtn = notificationElement.querySelector('.mark-read-btn');
                            if (markReadBtn) {
                                markReadBtn.remove();
                            }
                            
                            // Update badge to "Read"
                            const badge = notificationElement.querySelector('.text-xs');
                            if (badge) {
                                badge.textContent = 'Read';
                                badge.className = 'text-xs text-green-600 bg-green-100 px-2 py-1 rounded ml-2';
                            }
                        }
                        
                        // Update notification badge count
                        updateNotificationBadge();
                        
                    } else {
                        alert('Error marking notification as read');
                        button.innerHTML = originalHtml;
                        button.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error marking notification as read');
                    button.innerHTML = originalHtml;
                    button.disabled = false;
                });
            }

            // Mark all notifications as read
            function markAllNotificationsAsRead() {
                const markAllBtn = document.getElementById('mark-all-read-btn');
                const originalHtml = markAllBtn.innerHTML;
                markAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>';
                markAllBtn.disabled = true;
                
                const formData = new FormData();
                formData.append('mark_all_notifications_read', '1');
                formData.append('ajax', '1');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload notifications to reflect changes
                        loadNotifications();
                        
                        // Update notification badge
                        updateNotificationBadge();
                        
                        // Show success message
                        showTemporaryMessage('All notifications marked as read!', 'success');
                    } else {
                        alert('Error marking all notifications as read');
                    }
                    
                    markAllBtn.innerHTML = originalHtml;
                    markAllBtn.disabled = false;
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error marking all notifications as read');
                    markAllBtn.innerHTML = originalHtml;
                    markAllBtn.disabled = false;
                });
            }

            // Update notification badge count
            function updateNotificationBadge() {
                const notificationBadge = document.getElementById('notification-badge');
                if (notificationBadge) {
                    // Count remaining unread notifications in the list
                    const unreadNotifications = document.querySelectorAll('.mark-read-btn');
                    const unreadCount = unreadNotifications.length;
                    
                    if (unreadCount > 0) {
                        notificationBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                        notificationBadge.classList.remove('hidden');
                    } else {
                        notificationBadge.classList.add('hidden');
                    }
                }
            }

            // Show temporary message
            function showTemporaryMessage(message, type = 'success') {
                const messageEl = document.createElement('div');
                messageEl.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                    type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
                }`;
                messageEl.textContent = message;
                
                document.body.appendChild(messageEl);
                
                setTimeout(() => {
                    messageEl.remove();
                }, 3000);
            }

            loadNotifications();

            // Logout functionality
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }
        });

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function closeAllModals() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.display = 'none';
            });
        }

        // Approval workflow functions
        function openApprovalModal(proposalId, action) {
            const modal = document.getElementById('approval-modal');
            const form = document.getElementById('approval-form');
            const title = document.getElementById('approval-modal-title');
            const proposalIdInput = document.getElementById('modal-proposal-id');
            
            // Hide all action buttons first
            document.getElementById('approve-btn').style.display = 'none';
            document.getElementById('reject-btn').style.display = 'none';
            
            // Show only the relevant button and set title
            proposalIdInput.value = proposalId;
            
            switch(action) {
                case 'approve':
                    title.textContent = 'Approve Proposal';
                    document.getElementById('approve-btn').style.display = 'block';
                    break;
                case 'reject':
                    title.textContent = 'Reject Proposal';
                    document.getElementById('reject-btn').style.display = 'block';
                    break;
            }
            
            modal.style.display = 'block';
        }

        // Auto-refresh pending approvals every 30 seconds
        setInterval(() => {
            // In a real implementation, this would refresh the pending approvals list
            console.log('Auto-refreshing pending approvals...');
        }, 30000);
    </script>
</body>
</html>