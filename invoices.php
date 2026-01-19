<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Initialize session notifications if not set
if (!isset($_SESSION['ap_ar_notifications'])) {
    $_SESSION['ap_ar_notifications'] = [];
}

// Handle mark as read notifications request
if (isset($_GET['mark_notifications_read'])) {
    // Mark all notifications as read
    foreach ($_SESSION['ap_ar_notifications'] as &$notification) {
        $notification['read'] = true;
    }
    echo json_encode(['success' => true]);
    exit;
}

// Add session variable to track number visibility
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false;
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    header("Location: " . str_replace("?toggle_numbers=1", "", $_SERVER['REQUEST_URI']));
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Database connection error: " . $e->getMessage());
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

// Function to add a notification when invoice is created
function addInvoiceNotification($invoice_number, $invoice_type, $amount) {
    $type_display = $invoice_type === 'Receivable' ? 'Accounts Receivable' : 'Accounts Payable';
    $notification = [
        'id' => uniqid(),
        'type' => 'invoice',
        'message' => "New {$type_display} invoice created: {$invoice_number} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'invoices.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to add a notification when invoice is updated
function addInvoiceUpdateNotification($invoice_number, $invoice_type, $status) {
    $type_display = $invoice_type === 'Receivable' ? 'Accounts Receivable' : 'Accounts Payable';
    $notification = [
        'id' => uniqid(),
        'type' => 'invoice',
        'message' => "{$type_display} invoice updated: {$invoice_number} - Status changed to {$status}",
        'timestamp' => time(),
        'link' => 'invoices.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to get unread notification count - AP/AR only (session-based)
function getUnreadNotificationCount(): int {
    $unread_count = 0;
    foreach ($_SESSION['ap_ar_notifications'] as $notification) {
        // Check if 'read' key exists and is false, or if it doesn't exist (treat as unread)
        if (!isset($notification['read']) || $notification['read'] === false) {
            $unread_count++;
        }
    }
    return $unread_count;
}

// Function to get notifications - AP/AR only (session-based)
function getNotifications(): array {
    return $_SESSION['ap_ar_notifications'];
}

// Function to format numbers with asterisks if hidden
function formatNumber($number, $show_numbers = false) {
    if ($show_numbers) {
        return '₱' . number_format((float)$number, 2);
    } else {
        return '₱' . str_repeat('*', max(6, min(12, strlen(number_format((float)$number, 2)))));
    }
}

// Function to format count (for total invoices, overdue count) - ALWAYS show actual numbers
function formatCount($count) {
    return $count; // Always show actual count, never hidden
}

// Get invoices data with filters - UPDATED
function getInvoices(PDO $db, ?string $type = null, ?string $status = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person, bc.email, bc.phone,
                   COALESCE(SUM(p.amount), 0) as amount_paid,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE 1=1";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $sql .= " AND i.status = ?";
        $params[] = $status;
    }
    
    if ($start_date) {
        $sql .= " AND i.issue_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND i.issue_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY i.id
              ORDER BY i.issue_date DESC, i.id DESC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching invoices: " . $e->getMessage());
        return [];
    }
}

// Get business contacts for dropdown - UPDATED
function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT contact_id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    if ($type) {
        $sql .= " AND type = ?";
    }
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($type ? [$type] : []);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching business contacts: " . $e->getMessage());
        return [];
    }
}

// Generate next invoice number
function generateInvoiceNumber(PDO $db, string $type): string {
    $prefix = $type === 'Receivable' ? 'INV' : 'V-INV';
    $year = date('Y');
    
    try {
        $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_num 
                FROM invoices 
                WHERE invoice_number LIKE ? AND YEAR(issue_date) = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prefix . '-' . $year . '-%', $year]);
        $result = $stmt->fetch();
        
        $next_num = ($result['max_num'] ?? 0) + 1;
        return $prefix . '-' . $year . '-' . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating invoice number: " . $e->getMessage());
        // Fallback
        $count = $db->query("SELECT COUNT(*) as count FROM invoices WHERE type = '$type' AND YEAR(issue_date) = '$year'")->fetch()['count'];
        $next_num = (int)$count + 1;
        return $prefix . '-' . $year . '-' . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF protection
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security validation failed";
        header("Location: invoices.php");
        exit;
    }
    
    if ($action === 'add_invoice') {
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $contact_id = $_POST['contact_id'] ?? '';
        $type = $_POST['type'] ?? '';
        $issue_date = $_POST['issue_date'] ?? '';
        $due_date = $_POST['due_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($invoice_number)) $errors[] = "Invoice number is required";
        if (empty($contact_id)) $errors[] = "Valid contact is required";
        if (!in_array($type, ['Receivable', 'Payable'])) $errors[] = "Invalid invoice type";
        if (empty($issue_date)) $errors[] = "Issue date is required";
        if (empty($due_date)) $errors[] = "Due date is required";
        if ($amount <= 0) $errors[] = "Amount must be greater than 0";
        
        if (!empty($errors)) {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: invoices.php");
            exit;
        }
        
        try {
            $sql = "INSERT INTO invoices (invoice_number, contact_id, type, issue_date, due_date, amount, description, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')";
            $stmt = $db->prepare($sql);
            $stmt->execute([$invoice_number, $contact_id, $type, $issue_date, $due_date, $amount, $description]);
            
            // Add notification for new invoice
            addInvoiceNotification($invoice_number, $type, $amount);
            
            $_SESSION['success'] = "Invoice created successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error adding invoice: " . $e->getMessage());
            $_SESSION['error'] = "Error creating invoice: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
    
    if ($action === 'update_invoice') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $contact_id = $_POST['contact_id'] ?? '';
        $type = $_POST['type'] ?? '';
        $issue_date = $_POST['issue_date'] ?? '';
        $due_date = $_POST['due_date'] ?? '';
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? '';
        
        if ($invoice_id <= 0) {
            $_SESSION['error'] = "Invalid invoice ID";
            header("Location: invoices.php");
            exit;
        }
        
        try {
            // Get current invoice details to check if status changed
            $current_sql = "SELECT status, type FROM invoices WHERE id = ?";
            $current_stmt = $db->prepare($current_sql);
            $current_stmt->execute([$invoice_id]);
            $current_invoice = $current_stmt->fetch();
            
            $sql = "UPDATE invoices SET invoice_number = ?, contact_id = ?, type = ?, issue_date = ?, 
                    due_date = ?, amount = ?, description = ?, status = ? 
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$invoice_number, $contact_id, $type, $issue_date, $due_date, $amount, $description, $status, $invoice_id]);
            
            // Add notification if status changed
            if ($current_invoice && $current_invoice['status'] !== $status) {
                addInvoiceUpdateNotification($invoice_number, $type, $status);
            }
            
            $_SESSION['success'] = "Invoice updated successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error updating invoice: " . $e->getMessage());
            $_SESSION['error'] = "Error updating invoice: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
    
    if ($action === 'update_invoice_status') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($invoice_id <= 0) {
            $_SESSION['error'] = "Invalid invoice ID";
            header("Location: invoices.php");
            exit;
        }
        
        try {
            // Get current invoice details for notification
            $current_sql = "SELECT invoice_number, type FROM invoices WHERE id = ?";
            $current_stmt = $db->prepare($current_sql);
            $current_stmt->execute([$invoice_id]);
            $current_invoice = $current_stmt->fetch();
            
            $sql = "UPDATE invoices SET status = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$status, $invoice_id]);
            
            // Add notification for status change
            if ($current_invoice) {
                addInvoiceUpdateNotification($current_invoice['invoice_number'], $current_invoice['type'], $status);
            }
            
            $_SESSION['success'] = "Invoice status updated successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error updating invoice status: " . $e->getMessage());
            $_SESSION['error'] = "Error updating invoice status: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
    
    if ($action === 'delete_invoice') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        
        if ($invoice_id <= 0) {
            $_SESSION['error'] = "Invalid invoice ID";
            header("Location: invoices.php");
            exit;
        }
        
        try {
            // Check if invoice has payments
            $check_sql = "SELECT COUNT(*) as payment_count FROM payments WHERE invoice_id = ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->execute([$invoice_id]);
            $result = $check_stmt->fetch();
            
            if ($result['payment_count'] > 0) {
                $_SESSION['error'] = "Cannot delete invoice with payment history";
                header("Location: invoices.php");
                exit;
            }
            
            $sql = "DELETE FROM invoices WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$invoice_id]);
            
            $_SESSION['success'] = "Invoice deleted successfully!";
            header("Location: invoices.php");
            exit;
        } catch (PDOException $e) {
            error_log("Error deleting invoice: " . $e->getMessage());
            $_SESSION['error'] = "Error deleting invoice: " . $e->getMessage();
            header("Location: invoices.php");
            exit;
        }
    }
}

// Update overdue invoices automatically
try {
    $update_sql = "UPDATE invoices SET status = 'Overdue' 
                   WHERE due_date < CURDATE() 
                   AND status = 'Pending' 
                   AND status != 'Paid'";
    $db->exec($update_sql);
} catch (PDOException $e) {
    error_log("Error updating overdue invoices: " . $e->getMessage());
}

// Get filter values from request
$invoice_type = $_GET['type'] ?? null;
$invoice_status = $_GET['status'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$invoices = getInvoices($db, $invoice_type, $invoice_status, $start_date, $end_date);
$vendors = getBusinessContacts($db, 'Vendor');
$customers = getBusinessContacts($db, 'Customer');
$all_contacts = getBusinessContacts($db);

// Get invoice details for edit - UPDATED
function getInvoiceDetails(PDO $db, int $invoice_id): array {
    try {
        $sql = "SELECT i.*, bc.name as contact_name, bc.type as contact_type
                FROM invoices i
                LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
                WHERE i.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$invoice_id]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching invoice details: " . $e->getMessage());
        return [];
    }
}

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Check for success/error messages
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Handle payment success redirect
if (isset($_GET['payment_success']) && isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $_SESSION['success'] = "Payment recorded successfully! Invoice status updated.";
    header("Location: invoices.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Financial Dashboard</title>
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
        /* All existing styles remain the same, adding notification styles */
        
        /* Notification Styles */
        .notification-btn {
            position: relative;
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
        
        .notification-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
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
            font-weight: bold;
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }

        .notification-dropdown.active {
            display: block;
        }

        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #1f2937;
        }

        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background-color 0.2s;
            color: #1f2937;
        }

        .notification-item:hover {
            background-color: #f9fafb;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background-color: #f0f9ff;
            border-left: 3px solid #3b82f6;
        }

        .notification-message {
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
            color: #1f2937;
            font-weight: 500;
        }

        .notification-time {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .mark-read-btn {
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }

        .mark-read-btn:hover {
            background-color: #f3f4f6;
        }

        .no-notifications {
            padding: 2rem 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }

        /* Rest of your existing CSS styles remain exactly the same */
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
        .status-overdue {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
        }
        .status-paid {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
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
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
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
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            border: 1px solid;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.view:hover {
            background-color: #1D4ED8;
            color: white;
        }
        
        .action-btn.edit {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.edit:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }

        .action-btn.pay {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.pay:hover {
            background-color: #0369A1;
            color: white;
        }

        .invoice-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
        }
        
        .amount-positive {
            color: #10B981;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #EF4444;
            font-weight: 600;
        }
        
        .overdue-invoice {
            background-color: #FEF2F2;
            border-left: 4px solid #EF4444;
        }
        
        .due-soon-invoice {
            background-color: #FFFBEB;
            border-left: 4px solid #F59E0B;
        }
        
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
    
    <!-- Modal for Add/Edit Invoice -->
    <div id="invoice-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4" id="invoice-modal-title">Create New Invoice</h2>
            <form id="invoice-form" method="POST">
                <input type="hidden" name="action" id="invoice-action" value="add_invoice">
                <input type="hidden" name="invoice_id" id="invoice-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Invoice Number *</label>
                        <input type="text" name="invoice_number" id="invoice_number" class="form-input" required readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Invoice Type *</label>
                        <select name="type" id="invoice-type" class="form-input" required onchange="updateInvoiceNumber()">
                            <option value="">Select Type</option>
                            <option value="Receivable">Accounts Receivable (Customer)</option>
                            <option value="Payable">Accounts Payable (Vendor)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Contact *</label>
                    <select name="contact_id" id="contact-id" class="form-input" required>
                        <option value="">Select Contact</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Issue Date *</label>
                        <input type="date" name="issue_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date *</label>
                        <input type="date" name="due_date" class="form-input" required>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Amount *</label>
                    <input type="number" name="amount" class="form-input" step="0.01" min="0" required>
                </div>
                
                <div class="form-group mb-6">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Enter invoice description"></textarea>
                </div>
                
                <div id="status-field" class="form-group mb-4" style="display: none;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input">
                        <option value="Pending">Pending</option>
                        <option value="Paid">Paid</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Save Invoice</button>
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
                    <p class="text-xs text-white/90 mt-1">Microfinancial Management System 1</p>
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
                                <a href="financial_reports.php" class="submenu-item transition-colors duration-200">Financial Reports</a>
                            </div>
                        </div>
                        
                        <!-- AP/AR Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ap-ar">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">AP/AR</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ap-ar"></i>
                            </div>
                            <div class="submenu active mt-1" id="ap-ar-submenu">
                                <a href="vendors_customers.php" class="submenu-item transition-colors duration-200">Payable/Receivable</a>
                                <a href="invoices.php" class="submenu-item active transition-colors duration-200">Invoices</a>
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
                                <a href="approval_workflow.php" class="submenu-item transition-colors duration-200">Approval Workflow</a>
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
            <!-- Header -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Invoices</h1>
                        <p class="text-sm text-white/90">Manage accounts receivable and payable invoices</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <!-- Number Visibility Toggle Button -->
                    <a href="?toggle_numbers=1" class="eye-toggle-btn" title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                        <i class='bx <?php echo $_SESSION['show_numbers'] ? 'bx-hide' : 'bx-show'; ?> text-xl'></i>
                    </a>
                    
                    <!-- Notification Button with Dropdown -->
                    <div class="relative">
                        <button id="notification-btn" class="notification-btn" title="AP/AR Notifications">
                            <i class="fa-solid fa-bell text-xl text-white"></i>
                            <?php if ($notification_count > 0): ?>
                                <span class="notification-badge"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notification-dropdown" class="notification-dropdown">
                            <div class="notification-header">
                                <span>Notifications</span>
                                <?php if ($notification_count > 0): ?>
                                    <button id="mark-all-read" class="mark-read-btn">Mark All as Read</button>
                                <?php endif; ?>
                            </div>
                            <div id="notification-list">
                                <?php if (count($notifications) > 0): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item <?php echo (!isset($notification['read']) || !$notification['read']) ? 'unread' : ''; ?>" onclick="window.location.href='<?php echo htmlspecialchars($notification['link']); ?>'">
                                        <div class="notification-message">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </div>
                                        <div class="notification-time">
                                            <?php echo date('M j, Y g:i A', $notification['timestamp']); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-notifications">
                                        No AP/AR notifications
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <!-- Rest of the content remains exactly the same -->
            <div class="p-6 flex-1">
                <!-- Success and Error Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary Cards - UPDATED: Total Invoices and Overdue show numbers only, not hidden -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="invoice-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Invoices</p>
                                <p class="text-2xl font-bold">
                                    <!-- Total Invoices: Show actual count, never hidden -->
                                    <?php echo formatCount(count($invoices)); ?>
                                </p>
                            </div>
                            <i class='bx bx-receipt text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="invoice-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Outstanding</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $outstanding = array_sum(array_column($invoices, 'outstanding_balance'));
                                        echo formatNumber($outstanding, $_SESSION['show_numbers']);
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-time text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="invoice-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Overdue</p>
                                <p class="text-2xl font-bold">
                                    <!-- Overdue: Show actual count, never hidden -->
                                    <?php 
                                        $overdue = count(array_filter($invoices, fn($inv) => $inv['status'] === 'Overdue'));
                                        echo formatCount($overdue);
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-error-circle text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="invoice-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Paid This Month</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $paid_this_month = array_sum(array_column($invoices, 'amount_paid'));
                                        echo formatNumber($paid_this_month, $_SESSION['show_numbers']);
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-check-circle text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Filter Invoices</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-input">
                                <option value="">All Types</option>
                                <option value="Receivable" <?php echo $invoice_type === 'Receivable' ? 'selected' : ''; ?>>Accounts Receivable</option>
                                <option value="Payable" <?php echo $invoice_type === 'Payable' ? 'selected' : ''; ?>>Accounts Payable</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-input">
                                <option value="">All Status</option>
                                <option value="Pending" <?php echo $invoice_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Paid" <?php echo $invoice_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="Overdue" <?php echo $invoice_status === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="Cancelled" <?php echo $invoice_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="form-group flex items-end space-x-2">
                            <button type="submit" class="btn btn-primary flex-1">
                                <i class='bx bx-filter-alt mr-2'></i>Apply
                            </button>
                            <a href="invoices.php" class="btn btn-secondary">
                                <i class='bx bx-reset mr-2'></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Invoices Table -->
                <div class="bg-white rounded-xl card-shadow">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-dark-text">Invoice Management</h3>
                            <button class="btn btn-primary" onclick="openInvoiceModal()">
                                <i class='bx bx-plus mr-2'></i>Create Invoice
                            </button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Contact</th>
                                        <th>Type</th>
                                        <th>Issue Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Paid</th>
                                        <th>Outstanding</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($invoices) > 0): ?>
                                        <?php foreach ($invoices as $invoice): 
                                            $is_overdue = strtotime($invoice['due_date']) < time() && $invoice['status'] === 'Pending';
                                            $row_class = $is_overdue ? 'overdue-invoice' : '';
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td class="font-mono font-medium"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                            <td>
                                                <div class="font-medium"><?php echo htmlspecialchars($invoice['contact_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($invoice['contact_person']); ?></div>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'AR' : 'AP'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($invoice['issue_date']); ?></td>
                                            <td>
                                                <div class="<?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?>">
                                                    <?php echo htmlspecialchars($invoice['due_date']); ?>
                                                </div>
                                            </td>
                                            <td class="font-medium <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)$invoice['amount'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)$invoice['amount_paid'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)$invoice['outstanding_balance'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_class = match($invoice['status']) {
                                                    'Paid' => 'status-paid',
                                                    'Overdue' => 'status-overdue',
                                                    'Cancelled' => 'status-rejected',
                                                    default => 'status-pending'
                                                };
                                                ?>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars($invoice['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap gap-2">
                                                    <?php if ($invoice['outstanding_balance'] > 0 && $invoice['status'] !== 'Cancelled'): ?>
                                                    <button class="action-btn pay" title="Record Payment" onclick="recordPayment(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-credit-card mr-1'></i>Pay
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-gray-500">
                                                No invoices found. <button class="text-primary-green hover:underline" onclick="openInvoiceModal()">Create your first invoice</button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats - UPDATED: Show actual numbers for counts -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Invoice Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span>Total Invoices:</span>
                                <span class="font-bold">
                                    <!-- Total Invoices: Show actual count, never hidden -->
                                    <?php echo formatCount(count($invoices)); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Total Amount Paid:</span>
                                <span class="font-bold amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber(array_sum(array_column($invoices, 'amount_paid')), $_SESSION['show_numbers']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Total Outstanding:</span>
                                <span class="font-bold amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber(array_sum(array_column($invoices, 'outstanding_balance')), $_SESSION['show_numbers']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Overdue Invoices:</span>
                                <span class="font-bold">
                                    <!-- Overdue Invoices: Show actual count, never hidden -->
                                    <?php echo formatCount(count(array_filter($invoices, fn($inv) => $inv['status'] === 'Overdue'))); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button class="btn btn-primary w-full text-left" onclick="openInvoiceModal()">
                                <i class='bx bx-plus mr-2'></i>Create New Invoice
                            </button>
                            <button class="btn btn-secondary w-full text-left">
                                <i class='bx bx-import mr-2'></i>Import Invoices
                            </button>
                            <button class="btn btn-secondary w-full text-left">
                                <i class='bx bx-export mr-2'></i>Export Invoices
                            </button>
                            <button class="btn btn-secondary w-full text-left">
                                <i class='bx bx-envelope mr-2'></i>Send Reminders
                            </button>
                        </div>
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
        const profileModal = document.getElementById('profile-modal');
        const invoiceModal = document.getElementById('invoice-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                profileModal.style.display = 'none';
                invoiceModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
            }
            if (event.target === invoiceModal) {
                invoiceModal.style.display = 'none';
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

        // Notification functionality
        const notificationBtn = document.getElementById('notification-btn');
        const notificationDropdown = document.getElementById('notification-dropdown');
        
        if (notificationBtn && notificationDropdown) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('active');
            });
            
            // Close notification dropdown when clicking outside
            document.addEventListener('click', function() {
                notificationDropdown.classList.remove('active');
            });
            
            // Prevent dropdown from closing when clicking inside it
            notificationDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
        
        // Mark all as read functionality
        const markAllReadBtn = document.getElementById('mark-all-read');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Make an AJAX call to mark notifications as read
                fetch('?mark_notifications_read=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI - remove unread styles and badge
                            const unreadItems = document.querySelectorAll('.notification-item.unread');
                            unreadItems.forEach(item => {
                                item.classList.remove('unread');
                            });
                            
                            // Update badge count
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.remove();
                            }
                            
                            // Hide the mark all read button
                            markAllReadBtn.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notifications as read:', error);
                    });
            });
        }

        // Form submission handling
        const invoiceForm = document.getElementById('invoice-form');
        if (invoiceForm) {
            invoiceForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div>Saving...';
                submitBtn.disabled = true;
            });
        }

        // Set due date to 30 days from today by default
        const dueDateInput = document.querySelector('input[name="due_date"]');
        if (dueDateInput && !dueDateInput.value) {
            const today = new Date();
            const dueDate = new Date(today);
            dueDate.setDate(today.getDate() + 30);
            dueDateInput.value = dueDate.toISOString().split('T')[0];
        }
    });

    // Invoice functions
    function openInvoiceModal(invoiceId = null) {
        const modal = document.getElementById('invoice-modal');
        const title = document.getElementById('invoice-modal-title');
        const action = document.getElementById('invoice-action');
        const invoiceIdInput = document.getElementById('invoice-id');
        const statusField = document.getElementById('status-field');
        
        if (invoiceId) {
            title.textContent = 'Edit Invoice';
            action.value = 'update_invoice';
            invoiceIdInput.value = invoiceId;
            statusField.style.display = 'block';
            
            // Load invoice data via AJAX
            fetch(`?get_invoice_details=1&invoice_id=${invoiceId}`)
                .then(response => response.json())
                .then(invoice => {
                    if (invoice) {
                        document.getElementById('invoice_number').value = invoice.invoice_number;
                        document.getElementById('invoice-type').value = invoice.type;
                        document.querySelector('input[name="issue_date"]').value = invoice.issue_date;
                        document.querySelector('input[name="due_date"]').value = invoice.due_date;
                        document.querySelector('input[name="amount"]').value = invoice.amount;
                        document.querySelector('textarea[name="description"]').value = invoice.description || '';
                        document.querySelector('select[name="status"]').value = invoice.status;
                        
                        updateContactOptions(invoice.type);
                        setTimeout(() => {
                            document.getElementById('contact-id').value = invoice.contact_id;
                        }, 100);
                    }
                })
                .catch(error => {
                    console.error('Error loading invoice details:', error);
                });
        } else {
            title.textContent = 'Create New Invoice';
            action.value = 'add_invoice';
            invoiceIdInput.value = '';
            statusField.style.display = 'none';
            document.getElementById('invoice-form').reset();
            updateInvoiceNumber();
        }
        
        modal.style.display = 'block';
    }

    function updateInvoiceNumber() {
        const type = document.getElementById('invoice-type').value;
        const numberField = document.getElementById('invoice_number');
        
        if (type) {
            const prefix = type === 'Receivable' ? 'INV' : 'V-INV';
            const year = new Date().getFullYear();
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            numberField.value = `${prefix}-${year}-${random}`;
        } else {
            numberField.value = '';
        }
        
        updateContactOptions(type);
    }

    function updateContactOptions(type) {
        const contactSelect = document.getElementById('contact-id');
        contactSelect.innerHTML = '<option value="">Select Contact</option>';
        
        <?php if (count($customers) > 0): ?>
        if (type === 'Receivable') {
            <?php foreach ($customers as $customer): ?>
            contactSelect.innerHTML += `<option value="<?php echo $customer['contact_id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['contact_id'] . ')'); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($vendors) > 0): ?>
        if (type === 'Payable') {
            <?php foreach ($vendors as $vendor): ?>
            contactSelect.innerHTML += `<option value="<?php echo $vendor['contact_id']; ?>"><?php echo htmlspecialchars($vendor['name'] . ' (' . $vendor['contact_id'] . ')'); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($all_contacts) > 0): ?>
        if (!type) {
            <?php foreach ($all_contacts as $contact): ?>
            contactSelect.innerHTML += `<option value="<?php echo $contact['contact_id']; ?>"><?php echo htmlspecialchars($contact['name'] . ' (' . $contact['contact_id'] . ') - ' . $contact['type']); ?></option>`;
            <?php endforeach; ?>
        }
        <?php endif; ?>
    }

    function editInvoice(invoiceId) {
        openInvoiceModal(invoiceId);
    }

    function recordPayment(invoiceId) {
        // Add loading state
        const btn = event.target;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<div class="spinner"></div>Redirecting...';
        btn.disabled = true;
        
        // Verify invoice exists before redirecting
        fetch(`payment_entry.php?get_invoice_details=1&invoice_id=${invoiceId}`)
            .then(response => response.json())
            .then(invoice => {
                if (invoice && invoice.id) {
                    // Add from_invoice parameter to know where we came from
                    window.location.href = `payment_entry.php?invoice_id=${invoiceId}&from_invoice=1`;
                } else {
                    alert('Error: Invoice not found');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading invoice details');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
    }

    function deleteInvoice(invoiceId, invoiceNumber) {
        if (confirm(`Are you sure you want to delete invoice "${invoiceNumber}"? This action cannot be undone.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_invoice';
            form.appendChild(actionInput);
            
            const invoiceIdInput = document.createElement('input');
            invoiceIdInput.type = 'hidden';
            invoiceIdInput.name = 'invoice_id';
            invoiceIdInput.value = invoiceId;
            form.appendChild(invoiceIdInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Pre-fill contact and type when coming from vendors_customers
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const contactId = urlParams.get('contact_id');
        const type = urlParams.get('type');
        const fromContact = urlParams.get('from_contact');
        
        if (fromContact && contactId && type) {
            // Auto-open the invoice modal and pre-fill the form
            setTimeout(() => {
                openInvoiceModal();
                
                // Set the type and contact
                document.getElementById('invoice-type').value = type;
                updateInvoiceNumber();
                updateContactOptions(type);
                
                // Set the contact after a short delay to ensure options are loaded
                setTimeout(() => {
                    document.getElementById('contact-id').value = contactId;
                }, 500);
            }, 1000);
        }
    });
    </script>
</body>
</html>