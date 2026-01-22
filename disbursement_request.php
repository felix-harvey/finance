<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

try {
    // Database connection
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Authentication check
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: index.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_disbursement') {
        createDisbursement($db, $user_id, $user['name']);
    }
    
    // Handle edit disbursement request
    if (isset($_POST['action']) && $_POST['action'] === 'edit_disbursement') {
        editDisbursement($db, $user_id);
    }
    
    // Handle mark all as read
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// Handle delete request
if (isset($_GET['delete_id'])) {
    deleteDisbursement($db, $_GET['delete_id'], $user_id);
}

// Load notifications from database
function loadNotificationsFromDatabase(PDO $db, int $user_id): array {
    // Check if user_notifications table exists, if not create it
    try {
        $stmt = $db->query("SELECT 1 FROM user_notifications LIMIT 1");
    } catch (PDOException $e) {
        // Table doesn't exist, create it
        $db->exec("CREATE TABLE user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            notification_type VARCHAR(50),
            title VARCHAR(255),
            message TEXT,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");
        
        // Insert default notifications for this user
        $defaultNotifications = [
            ['success', 'Request Disbursed', 'Your disbursement request DISB-20250128-0001 has been disbursed.'],
            ['warning', 'Pending Review', 'New disbursement request requires your approval.'],
            ['info', 'System Update', 'New features added to the disbursement module.'],
        ];
        
        $insertStmt = $db->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL ? DAY)");
        
        $timeIntervals = [0, 0, 1]; // Time offsets for the default notifications
        
        foreach ($defaultNotifications as $index => $notification) {
            $isRead = ($index === 2); // Last one is read by default
            $insertStmt->execute([
                $user_id, 
                $notification[0], 
                $notification[1], 
                $notification[2], 
                $isRead,
                $timeIntervals[$index]
            ]);
        }
    }
    
    // Load notifications from database
    $stmt = $db->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $dbNotifications = $stmt->fetchAll();
    
    // Convert to the format expected by the frontend
    $notifications = [];
    foreach ($dbNotifications as $notification) {
        $notifications[] = [
            'type' => $notification['notification_type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'time' => getTimeAgo($notification['created_at']),
            'read' => (bool)$notification['is_read'],
            'db_id' => $notification['id']
        ];
    }
    
    return $notifications;
}

// Helper function to format time ago
function getTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $timeDiff = time() - $time;
    
    if ($timeDiff < 60) {
        return 'Just now';
    } elseif ($timeDiff < 3600) {
        $minutes = floor($timeDiff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($timeDiff < 86400) {
        $hours = floor($timeDiff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        $days = floor($timeDiff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
}

// Always load notifications from database to ensure they're current
$_SESSION['notifications'] = loadNotificationsFromDatabase($db, $user_id);

function createDisbursement(PDO $db, int $user_id, string $user_name): void {
    $requested_by = trim($_POST['requested_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    
    if (empty($requested_by) || empty($department) || empty($description) || $amount <= 0) {
        $_SESSION['error'] = "Please fill in all fields with valid data.";
        return;
    }
    
    try {
        $request_id = generateRequestId($db);
        
        $stmt = $db->prepare("INSERT INTO disbursement_requests (request_id, requested_by, requested_by_name, department, description, amount, status, date_requested) VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())");
        
        $stmt->execute([$request_id, $user_id, $requested_by, $department, $description, $amount]);
        
        // Add notification for new disbursement request to database
        addNotificationToDatabase(
            $db,
            $user_id,
            'info',
            'New Disbursement Request',
            "Your disbursement request {$request_id} for {$department} has been submitted successfully."
        );
        
        $_SESSION['success'] = "Disbursement request created successfully!";
        
        // Reload notifications to include the new one
        $_SESSION['notifications'] = loadNotificationsFromDatabase($db, $user_id);
        
        // REDIRECT TO PENDING DISBURSEMENTS PAGE INSTEAD OF STAYING HERE
        header("Location: pending_disbursements.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error creating disbursement request: " . $e->getMessage();
    }
}

function editDisbursement(PDO $db, int $user_id): void {
    $request_id = trim($_POST['request_id'] ?? '');
    $requested_by = trim($_POST['requested_by'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    
    if (empty($request_id) || empty($requested_by) || empty($department) || empty($description) || $amount <= 0) {
        $_SESSION['error'] = "Please fill in all fields with valid data.";
        return;
    }
    
    try {
        // Check if the request belongs to the current user and is still pending
        $checkStmt = $db->prepare("SELECT * FROM disbursement_requests WHERE request_id = ? AND requested_by = ? AND status = 'Pending'");
        $checkStmt->execute([$request_id, $user_id]);
        $request = $checkStmt->fetch();
        
        if (!$request) {
            $_SESSION['error'] = "You can only edit your own pending disbursement requests.";
            return;
        }
        
        // Update the request
        $stmt = $db->prepare("UPDATE disbursement_requests SET requested_by_name = ?, department = ?, description = ?, amount = ? WHERE request_id = ? AND requested_by = ?");
        
        $stmt->execute([$requested_by, $department, $description, $amount, $request_id, $user_id]);
        
        // Add notification for edited disbursement request to database
        addNotificationToDatabase(
            $db,
            $user_id,
            'info',
            'Disbursement Request Updated',
            "Your disbursement request {$request_id} has been updated successfully."
        );
        
        $_SESSION['success'] = "Disbursement request updated successfully!";
        
        // Reload notifications to include the new one
        $_SESSION['notifications'] = loadNotificationsFromDatabase($db, $user_id);
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error updating disbursement request: " . $e->getMessage();
    }
}

// New function to add notification to database
function addNotificationToDatabase(PDO $db, int $user_id, string $type, string $title, string $message): void {
    $stmt = $db->prepare("INSERT INTO user_notifications (user_id, notification_type, title, message, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$user_id, $type, $title, $message]);
}

// Keep the old addNotification function for backward compatibility, but it now uses database
function addNotification(string $type, string $title, string $message, string $time): void {
    global $db, $user_id;
    addNotificationToDatabase($db, $user_id, $type, $title, $message);
}

function deleteDisbursement(PDO $db, string $request_id, int $user_id): void {
    try {
        // Check if the request belongs to the current user
        $checkStmt = $db->prepare("SELECT * FROM disbursement_requests WHERE request_id = ? AND requested_by = ?");
        $checkStmt->execute([$request_id, $user_id]);
        $request = $checkStmt->fetch();
        
        if (!$request) {
            $_SESSION['error'] = "You can only delete your own disbursement requests.";
            return;
        }
        
        // Only allow deletion of pending requests
        if ($request['status'] !== 'Pending') {
            $_SESSION['error'] = "You can only delete pending disbursement requests.";
            return;
        }
        
        // Delete the request
        $stmt = $db->prepare("DELETE FROM disbursement_requests WHERE request_id = ? AND requested_by = ?");
        $stmt->execute([$request_id, $user_id]);
        
        // Add notification for deleted disbursement request to database
        addNotificationToDatabase(
            $db,
            $user_id,
            'warning',
            'Disbursement Request Deleted',
            "Your disbursement request {$request_id} has been deleted."
        );
        
        $_SESSION['success'] = "Disbursement request deleted successfully!";
        
        // Reload notifications to include the new one
        $_SESSION['notifications'] = loadNotificationsFromDatabase($db, $user_id);
        
        // Redirect to prevent duplicate actions
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting disbursement request: " . $e->getMessage();
    }
}

function generateRequestId(PDO $db): string {
    $prefix = "DISB";
    $date = date("Ymd");
    
    // Get the latest request ID for today
    $stmt = $db->prepare("SELECT request_id FROM disbursement_requests WHERE request_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '-' . $date . '-%']);
    $latest = $stmt->fetch();
    
    if ($latest) {
        // Extract the number part and increment
        $parts = explode('-', $latest['request_id']);
        $last_num = (int)end($parts);
        $sequence = $last_num + 1;
    } else {
        $sequence = 1;
    }
    
    // Format the sequence number with leading zeros
    $formatted_sequence = sprintf("%04d", $sequence);
    
    return $prefix . '-' . $date . '-' . $formatted_sequence;
}

// Get disbursement requests data (only for current user) - ONLY DISBURSED REQUESTS
function getDisbursementRequests(PDO $db, int $user_id): array {
    $sql = "SELECT dr.*, u.name AS user_name, u2.name AS approved_by_name
            FROM disbursement_requests dr
            LEFT JOIN users u ON dr.requested_by = u.id
            LEFT JOIN users u2 ON dr.approved_by = u2.id
            WHERE dr.requested_by = ? AND dr.status = 'Approved'
            ORDER BY dr.date_requested DESC, dr.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

$disbursement_requests = getDisbursementRequests($db, $user_id);

// Calculate unread notification count
$unreadCount = 0;
if (isset($_SESSION['notifications'])) {
    foreach ($_SESSION['notifications'] as $notification) {
        if (!$notification['read']) {
            $unreadCount++;
        }
    }
}

// Check if all notifications are read
$allNotificationsRead = true;
if (isset($_SESSION['notifications'])) {
    foreach ($_SESSION['notifications'] as $notification) {
        if (!$notification['read']) {
            $allNotificationsRead = false;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disbursement Request - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
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
        /* ... (keep all your existing CSS styles) ... */
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
        .status-completed {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
        }
        .status-pending {
            background-color: rgba(251, 191, 36, 0.1);
            color: #F59E0B;
        }
        .status-disbursed {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
        }
        .status-rejected {
            background-color: rgba(229, 62, 62, 0.1);
            color: #E53E3E;
        }
        #sidebar {
            transition: transform 0.3s ease-in-out;
            background-color: #2f855A;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        #hamburger-btn {
            display: block !important;
        }
        
        @media (max-width: 768px) {
            #sidebar {
                transform: translateX(-100%);
                position: fixed;
                height: 100%;
                z-index: 40;
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
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2f855A;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #2f855A;
            box-shadow: 0 0 0 3px rgba(47, 133, 90, 0.2);
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
        }
        
        .btn-primary {
            background-color: #2f855A;
            color: white;
            border: none;
        }
        
        .btn-primary:hover {
            background-color: #28644c;
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
            border: none;
        }
        
        .btn-secondary:hover {
            background-color: #d1d5db;
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
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            border: 1px solid;
        }
        
        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border: 1px solid #DC2626;
        }
        
        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }

        .action-btn.edit {
            background-color: #EFF6FF;
            color: #3B82F6;
            border: 1px solid #3B82F6;
        }

        .action-btn.edit:hover {
            background-color: #3B82F6;
            color: white;
        }

        .action-btn.edit:disabled {
            background-color: #e5e7eb;
            color: #9ca3af;
            border: 1px solid #d1d5db;
            cursor: not-allowed;
        }

        .action-btn.edit:disabled:hover {
            background-color: #e5e7eb;
            color: #9ca3af;
            transform: none;
            box-shadow: none;
        }
        
        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* Number visibility toggle styles */
        .stat-value {
            transition: all 0.3s ease;
        }
        
        .visibility-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: 0.5rem;
            color: #6b7280;
            transition: color 0.3s ease;
        }
        
        .visibility-toggle:hover {
            color: #374151;
        }
        
        .hidden-amount {
            letter-spacing: 2px;
            font-family: monospace;
        }
        
        .amount-cell {
            display: flex;
            align-items: center;
        }
        
        /* Notification styles */
        .notification-item.unread {
            background-color: #f8fafc;
            border-left: 3px solid #3b82f6;
        }
        
        .notification-item.read {
            background-color: white;
            border-left: 3px solid transparent;
        }
        
        .notification-dot {
            transition: all 0.3s ease;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #ef4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="bg-gray-bg">
    <!-- Overlay for mobile sidebar -->
    <div class="overlay" id="overlay"></div>
    
    <!-- Modal for Create Disbursement -->
    <div id="create-disbursement-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Create Disbursement Request</h2>
            <form id="disbursement-form" method="POST">
                <input type="hidden" name="action" value="create_disbursement">
                <div class="form-group">
                    <label class="form-label">Requested By</label>
                    <input type="text" name="requested_by" class="form-input" placeholder="Enter requestor name (e.g., John Doe, Marketing Team)" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Department</label>
                    <select name="department" class="form-input" required>
                        <option value="">Select Department</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Operations">Operations</option>
                        <option value="IT">IT</option>
                        <option value="HR">HR</option>
                        <option value="Finance">Finance</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Enter description" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" name="amount" class="form-input" placeholder="Enter amount" step="0.01" min="0" required>
                </div>
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for Edit Disbursement -->
    <div id="edit-disbursement-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Edit Disbursement Request</h2>
            <form id="edit-disbursement-form" method="POST">
                <input type="hidden" name="action" value="edit_disbursement">
                <input type="hidden" id="edit-request-id" name="request_id">
                
                <div class="form-group">
                    <label class="form-label">Requested By</label>
                    <input type="text" name="requested_by" id="edit-requested-by" class="form-input" placeholder="Enter requestor name" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Department</label>
                    <select name="department" id="edit-department" class="form-input" required>
                        <option value="">Select Department</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Operations">Operations</option>
                        <option value="IT">IT</option>
                        <option value="HR">HR</option>
                        <option value="Finance">Finance</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit-description" class="form-input" rows="3" placeholder="Enter description" required></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" name="amount" id="edit-amount" class="form-input" placeholder="Enter amount" step="0.01" min="0" required>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Update Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for View Rejection Reason (REMOVED) -->
    
    <!-- Modal for user profile -->
    <div id="profile-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">User Profile</h2>
            <div class="flex items-center mb-6">
                <i class="fa-solid fa-user text-[40px] bg-primary-green text-white px-3 py-3 rounded-full"></i>
                <div class="ml-4">
                    <h3 class="text-lg font-bold" id="profile-name"><?php echo htmlspecialchars($user['name']); ?></h3>
                    <p class="text-gray-500"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></p>
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

    <!-- Modal for Notifications -->
    <div id="notification-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            <div class="space-y-4 max-h-96 overflow-y-auto" id="notification-list">
                <!-- Dynamic notifications from session -->
                <?php if (isset($_SESSION['notifications']) && !empty($_SESSION['notifications'])): ?>
                    <?php foreach ($_SESSION['notifications'] as $index => $notification): ?>
                        <div class="p-3 border border-gray-200 rounded-lg notification-item <?php echo $notification['read'] ? 'read' : 'unread'; ?>" data-index="<?php echo $index; ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h4 class="font-medium text-<?php 
                                        echo $notification['type'] === 'success' ? 'green' : 
                                             ($notification['type'] === 'warning' ? 'yellow' : 
                                             ($notification['type'] === 'error' ? 'red' : 'blue')); ?>-600">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                    </h4>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($notification['time']); ?></p>
                                </div>
                                <span class="w-2 h-2 <?php echo $notification['read'] ? 'bg-gray-400' : (
                                    $notification['type'] === 'success' ? 'bg-green-500' : 
                                    ($notification['type'] === 'warning' ? 'bg-yellow-500' : 
                                    ($notification['type'] === 'error' ? 'bg-red-500' : 'bg-blue-500'))
                                ); ?> rounded-full mt-2 notification-dot"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-gray-500">
                        No notifications found.
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-6 pt-4 border-t border-gray-200">
                <button id="mark-all-read" class="btn btn-secondary w-full" <?php echo $allNotificationsRead ? 'disabled' : ''; ?>>
                    <?php echo $allNotificationsRead ? 'All Notifications Read' : 'Mark All as Read'; ?>
                </button>
            </div>
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
                    <p class="text-xs text-white/90 mt-1">Microfinancial Management System 1</p>
                </div>
                
                <!-- Navigation -->
                <div class="flex-1 overflow-y-auto px-2 py-4">
                    <div class="space-y-6">
                        <!-- Main Menu Item -->
                        <a href="dashboard8.php" class="sidebar-item py-3 px-4 rounded-lg cursor-pointer mx-2 flex items-center">
                            <i class='bx bx-home text-white mr-3'></i>
                            <span class="text-sm font-medium text-white">FINANCIAL</span>
                        </a>
                        
                        <!-- Disbursement Section -->
                        <div class="py-2 mx-2">
                            <div class="flex items-center justify-between mb-1 sidebar-category py-2 px-3 rounded cursor-pointer hover:bg-hover-state" data-category="disbursement">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Disbursement</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow' data-category="disbursement"></i>
                            </div>
                            <div class="submenu active" id="disbursement-submenu">
                                <a href="disbursement_request.php" class="submenu-item active">Disbursement History</a>
                                <a href="pending_disbursements.php" class="submenu-item">Pending Disbursements</a>
                                <a href="disbursement_reports.php" class="submenu-item">Disbursement Reports</a>
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
                            <div class="submenu mt-1" id="budget-submenu">
                                <a href="budget_proposal.php" class="submenu-item transition-colors duration-200">Budget Proposal</a>
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
            <!-- Header -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Disbursement History</h1>
                        <p class="text-sm text-white/90">View your disbursement request history</p>
                    </div>
                </div>
                <div class="flex items-center space-x-1">
                    <!-- Visibility Toggle Button -->
                    <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                        <i class="fa-solid fa-eye-slash text-xl text-white"></i>
                    </button>
                    <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                        <i class="fa-solid fa-bell text-xl text-white"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notification-badge" id="notification-badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Display success/error messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success mb-4">
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error mb-4">
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <!-- My Requests Section - ONLY DISBURSED REQUESTS -->
                <div class="bg-white rounded-xl p-6 card-shadow">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-xl font-bold text-dark-text">Disbursement History</h2>
                            <div class="text-sm text-gray-500 mt-1">
                                Total: <span id="total-count"><?php echo count($disbursement_requests); ?></span> disbursed requests
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table" id="requests-table">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Department</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Date Requested</th>
                                    <th>Date Disbursed</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($disbursement_requests) > 0): ?>
                                    <?php foreach ($disbursement_requests as $request): ?>
                                    <tr>
                                        <td class="font-medium"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                        <td><?php echo htmlspecialchars($request['department']); ?></td>
                                        <td class="max-w-xs truncate"><?php echo htmlspecialchars($request['description']); ?></td>
                                        <td class="font-semibold">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount" data-value="₱<?php echo number_format((float)$request['amount'], 2); ?>">
                                                    ********
                                                </span>
                                                <button class="visibility-toggle" onclick="toggleAmountVisibility(this)">
                                                    <i class="fa-solid fa-eye-slash"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($request['date_requested'])); ?></td>
                                        <td>
                                            <?php if (!empty($request['date_approved'])): ?>
                                                <?php echo date('M j, Y', strtotime($request['date_approved'])); ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-disbursed">
                                                Disbursed
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-gray-500">
                                            No disbursement history found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
        // JavaScript functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Hamburger menu functionality
            const hamburgerBtn = document.getElementById('hamburger-btn');
            const closeSidebar = document.getElementById('close-sidebar');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            const mainContent = document.getElementById('main-content');

            if (hamburgerBtn && sidebar && overlay && closeSidebar && mainContent) {
                function toggleSidebar() {
                    if (window.innerWidth < 769) {
                        sidebar.classList.toggle('active');
                        overlay.classList.toggle('active');
                    } else {
                        sidebar.classList.toggle('hidden');
                        mainContent.classList.toggle('full-width');
                    }
                }

                hamburgerBtn.addEventListener('click', toggleSidebar);
                closeSidebar.addEventListener('click', function() {
                    if (window.innerWidth < 769) {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    } else {
                        sidebar.classList.add('hidden');
                        mainContent.classList.add('full-width');
                    }
                });

                overlay.addEventListener('click', function() {
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
                    
                    submenu.classList.toggle('active');
                    arrow.classList.toggle('rotate-180');
                });
            });

            // Modal functionality
            const profileBtn = document.getElementById('profile-btn');
            const notificationBtn = document.getElementById('notification-btn');
            const profileModal = document.getElementById('profile-modal');
            const notificationModal = document.getElementById('notification-modal');
            const createDisbursementModal = document.getElementById('create-disbursement-modal');
            const editDisbursementModal = document.getElementById('edit-disbursement-modal');
            const closeButtons = document.querySelectorAll('.close-modal');

            // Profile button click
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    profileModal.style.display = 'block';
                });
            }

            // Notification button click
            if (notificationBtn && notificationModal) {
                notificationBtn.addEventListener('click', function() {
                    notificationModal.style.display = 'block';
                });
            }

            // Close buttons functionality
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    profileModal.style.display = 'none';
                    notificationModal.style.display = 'none';
                    createDisbursementModal.style.display = 'none';
                    editDisbursementModal.style.display = 'none';
                });
            });

            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === profileModal) {
                    profileModal.style.display = 'none';
                }
                if (event.target === notificationModal) {
                    notificationModal.style.display = 'none';
                }
                if (event.target === createDisbursementModal) {
                    createDisbursementModal.style.display = 'none';
                }
                if (event.target === editDisbursementModal) {
                    editDisbursementModal.style.display = 'none';
                }
            });

            // Logout button functionality
            const logoutBtn = document.getElementById('logout-btn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to logout?')) {
                        window.location.href = '?logout=true';
                    }
                });
            }

            // Form submission
            const disbursementForm = document.getElementById('disbursement-form');
            if (disbursementForm) {
                disbursementForm.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    // Show loading state
                    submitBtn.innerHTML = '<div class="spinner"></div>Processing...';
                    submitBtn.disabled = true;
                });
            }

            // Edit form submission
            const editDisbursementForm = document.getElementById('edit-disbursement-form');
            if (editDisbursementForm) {
                editDisbursementForm.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    
                    // Show loading state
                    submitBtn.innerHTML = '<div class="spinner"></div>Updating...';
                    submitBtn.disabled = true;
                });
            }

            // Mark All as Read functionality with AJAX
            const markAllReadBtn = document.getElementById('mark-all-read');

            if (markAllReadBtn && !markAllReadBtn.disabled) {
                markAllReadBtn.addEventListener('click', function() {
                    // Send AJAX request to mark all as read
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_read'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI without page refresh
                            const notificationItems = document.querySelectorAll('.notification-item');
                            notificationItems.forEach(item => {
                                item.classList.remove('unread');
                                item.classList.add('read');
                                
                                // Change dot color to gray
                                const dot = item.querySelector('.notification-dot');
                                if (dot) {
                                    dot.className = 'w-2 h-2 bg-gray-400 rounded-full mt-2 notification-dot';
                                }
                            });
                            
                            // Update button
                            markAllReadBtn.textContent = 'All Notifications Read';
                            markAllReadBtn.disabled = true;
                            
                            // Remove notification badge
                            const notificationBadge = document.getElementById('notification-badge');
                            if (notificationBadge) {
                                notificationBadge.remove();
                            }
                            
                            // Show confirmation
                            showNotification('All notifications marked as read', 'success');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Error marking notifications as read', 'error');
                    });
                });
            }
            
            function showNotification(message, type = 'info') {
                // Create toast notification
                const toast = document.createElement('div');
                toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                    type === 'success' ? 'bg-green-500 text-white' : 
                    type === 'error' ? 'bg-red-500 text-white' : 
                    'bg-blue-500 text-white'
                }`;
                toast.textContent = message;
                
                document.body.appendChild(toast);
                
                // Remove toast after 3 seconds
                setTimeout(() => {
                    toast.remove();
                }, 3000);
            }

            // Initialize all amounts as hidden
            const amountSpans = document.querySelectorAll('.amount-value');
            amountSpans.forEach(span => {
                span.textContent = '********';
                span.classList.add('hidden-amount');
            });

            // Keep disbursement menu open by default
            const disbursementSubmenu = document.getElementById('disbursement-submenu');
            const disbursementArrow = document.querySelector('.category-arrow[data-category="disbursement"]');
            if (disbursementSubmenu && disbursementArrow) {
                disbursementSubmenu.classList.add('active');
                disbursementArrow.classList.add('rotate-180');
            }
        });

        // Open create modal function
        function openCreateModal() {
            document.getElementById('create-disbursement-modal').style.display = 'block';
        }

        // Amount visibility toggle functionality
        let amountsVisible = false;
        
        function toggleAmountVisibility(button) {
            const amountSpan = button.parentElement.querySelector('.amount-value');
            const icon = button.querySelector('i');
            
            if (amountsVisible) {
                // Hide amount
                amountSpan.textContent = '********';
                amountSpan.classList.add('hidden-amount');
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                // Show amount
                const actualAmount = amountSpan.getAttribute('data-value');
                amountSpan.textContent = actualAmount;
                amountSpan.classList.remove('hidden-amount');
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            
            amountsVisible = !amountsVisible;
        }

        // Global visibility toggle
        document.getElementById('visibility-toggle').addEventListener('click', function() {
            const toggleButtons = document.querySelectorAll('.visibility-toggle');
            const globalIcon = this.querySelector('i');
            
            amountsVisible = !amountsVisible;
            
            toggleButtons.forEach(button => {
                const amountSpan = button.parentElement.querySelector('.amount-value');
                const icon = button.querySelector('i');
                
                if (amountsVisible) {
                    // Show all amounts
                    const actualAmount = amountSpan.getAttribute('data-value');
                    amountSpan.textContent = actualAmount;
                    amountSpan.classList.remove('hidden-amount');
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                } else {
                    // Hide all amounts
                    amountSpan.textContent = '********';
                    amountSpan.classList.add('hidden-amount');
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            });
            
            // Update global toggle icon
            if (amountsVisible) {
                globalIcon.classList.remove('fa-eye-slash');
                globalIcon.classList.add('fa-eye');
            } else {
                globalIcon.classList.remove('fa-eye');
                globalIcon.classList.add('fa-eye-slash');
            }
        });
    </script>
</body>
</html>