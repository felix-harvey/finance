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

// Enhanced error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Add session variable to track number visibility
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false; // Default to hidden
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    // Redirect to same page without the toggle parameter
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
    echo "Database connection error. Please try again later.";
    exit;
}

// Enhanced authentication check
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Enhanced logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

// Enhanced user loading with better error handling
try {
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    
    if (!$user) {
        header("Location: login.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("User loading error: " . $e->getMessage());
    header("Location: login.php");
    exit;
}

// Function to add a notification when contact is created
function addContactNotification($contact_name, $contact_type) {
    $notification = [
        'id' => uniqid(),
        'type' => 'contact',
        'message' => "New {$contact_type} contact added: {$contact_name}",
        'timestamp' => time(),
        'link' => 'vendors_customers.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to add notification for budget approval
function addBudgetApprovalNotification($proposal_title, $amount) {
    $notification = [
        'id' => uniqid(),
        'type' => 'budget',
        'message' => "Budget approved: {$proposal_title} (₱" . number_format($amount, 2) . ") added to AR",
        'timestamp' => time(),
        'link' => 'vendors_customers.php',
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
        if (!$notification['read']) {
            $unread_count++;
        }
    }
    return $unread_count;
}

// Function to get notifications - AP/AR only (session-based)
function getNotifications(): array {
    return $_SESSION['ap_ar_notifications'];
}

// Function to mark all notifications as read
function markAllNotificationsAsRead() {
    foreach ($_SESSION['ap_ar_notifications'] as &$notification) {
        $notification['read'] = true;
    }
}

// Function to format numbers with asterisks if hidden
function formatNumber($number, $show_numbers = false) {
    if ($show_numbers) {
        return '₱' . number_format((float)$number, 2);
    } else {
        $formatted = number_format((float)$number, 2);
        $length = strlen($formatted);
        // Use a fixed pattern for hidden numbers
        return '₱' . str_repeat('*', max(6, min(12, $length)));
    }
}

// Function to format count (for total AP/AR)
function formatCount($count, $show_numbers = false) {
    if ($show_numbers) {
        return $count;
    } else {
        return str_repeat('*', strlen((string)$count));
    }
}

// FIXED: Enhanced vendor data function with CORRECTED balance calculation
function getVendors(PDO $db): array {
    $sql = "SELECT 
                bc.*,
                -- CORRECTED: For AP, net balance should be NEGATIVE (you owe money)
                -- Formula: (Total payments made) - (Total invoices from vendors)
                (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM payments 
                    WHERE contact_id = bc.id AND type = 'Make' AND status = 'Completed'
                ) - 
                (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Payable'
                ) as net_balance,
                
                -- Outstanding balance (unpaid invoices only)
                COALESCE(SUM(CASE WHEN i.status != 'Paid' THEN i.amount - COALESCE(p.total_paid, 0) ELSE 0 END), 0) as outstanding_balance,
                
                COUNT(DISTINCT i.id) as total_invoices,
                COUNT(DISTINCT p_inv.id) as paid_invoices,
                (SELECT COUNT(*) FROM payments WHERE contact_id = bc.id AND type = 'Make') as payment_count,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE contact_id = bc.id AND type = 'Make' AND status = 'Completed') as total_payments,
                (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE contact_id = bc.id AND type = 'Payable') as total_invoiced
            FROM business_contacts bc
            LEFT JOIN invoices i ON bc.id = i.contact_id AND i.type = 'Payable'
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) as total_paid 
                FROM payments 
                WHERE type = 'Make' AND status = 'Completed'
                GROUP BY invoice_id
            ) p ON i.id = p.invoice_id
            LEFT JOIN invoices p_inv ON bc.id = p_inv.contact_id AND p_inv.status = 'Paid'
            WHERE bc.status = 'Active' AND bc.type = 'Vendor'
            GROUP BY bc.id
            ORDER BY bc.name";
    
    try {
        return $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching vendors: " . $e->getMessage());
        return [];
    }
}

// MODIFIED: Fetches ALL customers including budget allocations
function getCustomers(PDO $db): array {
    $sql = "SELECT 
                bc.*,
                -- CORRECTED: For AR, net balance should be POSITIVE (customers owe you money)
                -- Formula: (Total invoices to customers) - (Total payments received)
                (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM invoices 
                    WHERE contact_id = bc.id AND type = 'Receivable'
                ) - 
                (
                    SELECT COALESCE(SUM(amount), 0) 
                    FROM payments 
                    WHERE contact_id = bc.id AND type = 'Receive' AND status = 'Completed'
                ) as net_balance,
                
                -- Outstanding balance (unpaid invoices only)
                COALESCE(SUM(CASE WHEN i.status != 'Paid' THEN i.amount - COALESCE(p.total_paid, 0) ELSE 0 END), 0) as outstanding_balance,
                
                COUNT(DISTINCT i.id) as total_invoices,
                COUNT(DISTINCT p_inv.id) as paid_invoices,
                (SELECT COUNT(*) FROM payments WHERE contact_id = bc.id AND type = 'Receive') as payment_count,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE contact_id = bc.id AND type = 'Receive' AND status = 'Completed') as total_payments,
                (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE contact_id = bc.id AND type = 'Receivable') as total_invoiced
            FROM business_contacts bc
            LEFT JOIN invoices i ON bc.id = i.contact_id AND i.type = 'Receivable'
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) as total_paid 
                FROM payments 
                WHERE type = 'Receive' AND status = 'Completed'
                GROUP BY invoice_id
            ) p ON i.id = p.invoice_id
            LEFT JOIN invoices p_inv ON bc.id = p_inv.contact_id AND p_inv.status = 'Paid'
            WHERE bc.status = 'Active' AND bc.type = 'Customer'
            -- REMOVED exclusion of 'System Generated' to include Budget Allocations
            GROUP BY bc.id
            ORDER BY bc.name";
    
    try {
        return $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching customers: " . $e->getMessage());
        return [];
    }
}

// NEW: Get total budget allocations (from approved proposals)
function getTotalBudgetAllocations(PDO $db): float {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) as total_budget 
            FROM budget_proposals 
            WHERE status = 'Approved'";
    
    try {
        $result = $db->query($sql)->fetch();
        return (float)$result['total_budget'];
    } catch (PDOException $e) {
        error_log("Error fetching total budget: " . $e->getMessage());
        return 0.0;
    }
}

// NEW: Get recent budget approvals
function getRecentBudgetApprovals(PDO $db): array {
    $sql = "SELECT 
                bp.title,
                bp.total_amount,
                d.name as department_name,
                bp.approval_date,
                u.name as approved_by
            FROM budget_proposals bp
            LEFT JOIN departments d ON bp.department = d.id
            LEFT JOIN users u ON bp.approved_by = u.id
            WHERE bp.status = 'Approved'
            ORDER BY bp.approval_date DESC
            LIMIT 5";
    
    try {
        return $db->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching recent budget approvals: " . $e->getMessage());
        return [];
    }
}

// Function to create budget allocation contact - SIMPLIFIED VERSION
function createBudgetAllocationContact(PDO $db, string $department_name, string $proposal_title, float $amount): ?int {
    try {
        // Generate a unique contact ID for budget allocation
        $prefix = 'AR-BUDGET-';
        
        // Get the highest existing number
        $sql = "SELECT MAX(CAST(SUBSTRING(contact_id, 11) AS UNSIGNED)) as max_num 
                FROM business_contacts 
                WHERE contact_id LIKE ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $result = $stmt->fetch();
        
        $next_num = ($result['max_num'] ?? 0) + 1;
        $contact_id = $prefix . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
        
        // Insert the budget allocation contact - using only existing columns
$sql = "INSERT INTO business_contacts 
        (contact_id, name, contact_person, email, phone, type, status, created_at) 
        VALUES (?, ?, 'Admin', 'microfinancial25@gmail.com', '-', 'Customer', 'Active', NOW())";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$contact_id, $department_name . ' Budget']);
        
        $new_contact_id = $db->lastInsertId();
        
        return $new_contact_id;
        
    } catch (PDOException $e) {
        error_log("Error creating budget allocation contact: " . $e->getMessage());
        return null;
    }
}

// Enhanced ID generation function
function generateContactId(PDO $db, string $type): string {
    $prefix = $type === 'Vendor' ? 'AP-' : 'AR-';
    
    try {
        // Get the highest existing number for better sequence
        $sql = "SELECT MAX(CAST(SUBSTRING(contact_id, 4) AS UNSIGNED)) as max_num 
                FROM business_contacts 
                WHERE type = ? AND contact_id LIKE ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$type, $prefix . '%']);
        $result = $stmt->fetch();
        
        $next_num = ($result['max_num'] ?? 0) + 1;
        return $prefix . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating contact ID: " . $e->getMessage());
        // Fallback to count-based ID with prepared statement
        $count_sql = "SELECT COUNT(*) as count FROM business_contacts WHERE type = ?";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute([$type]);
        $count = $count_stmt->fetch()['count'];
        $next_num = (int)$count + 1;
        return $prefix . str_pad((string)$next_num, 3, '0', STR_PAD_LEFT);
    }
}

// Enhanced input validation function
function validateContactInput(array $data): array {
    $errors = [];
    
    $name = trim($data['company_name'] ?? '');
    $contact_person = trim($data['contact_person'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    
    if (empty($name) || strlen($name) < 2) {
        $errors[] = "Company name must be at least 2 characters long";
    }
    
    if (empty($contact_person) || strlen($contact_person) < 2) {
        $errors[] = "Contact person must be at least 2 characters long";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Valid email address is required";
    }
    
    if (!empty($phone) && !preg_match('/^[\d\s\-\+\(\)]{10,}$/', $phone)) {
        $errors[] = "Phone number format is invalid";
    }
    
    return $errors;
}

// Generate CSRF token for security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Enhanced form submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // CSRF protection
    if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security validation failed";
        header("Location: vendors_customers.php");
        exit;
    }
    
    if ($action === 'add_vendor' || $action === 'update_vendor') {
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Enhanced validation
        $validation_errors = validateContactInput($_POST);
        if (!empty($validation_errors)) {
            $_SESSION['error'] = implode("<br>", $validation_errors);
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            if ($action === 'add_vendor') {
                $contact_id = generateContactId($db, 'Vendor');
                
                $sql = "INSERT INTO business_contacts (contact_id, name, contact_person, email, phone, type, status) 
                        VALUES (?, ?, ?, ?, ?, 'Vendor', 'Active')";
                $stmt = $db->prepare($sql);
                $stmt->execute([$contact_id, $name, $contact_person, $email, $phone]);
                
                // Add notification for new AP contact
                addContactNotification($name, 'Accounts Payable');
                
                $_SESSION['success'] = "Accounts Payable contact added successfully!";
                
            } else { // update_vendor
                if ($vendor_id === 0) {
                    throw new Exception("Invalid contact ID");
                }
                
                $sql = "UPDATE business_contacts SET name = ?, contact_person = ?, email = ?, phone = ? 
                        WHERE id = ? AND type = 'Vendor'";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $contact_person, $email, $phone, $vendor_id]);
                
                $_SESSION['success'] = "Accounts Payable contact updated successfully!";
            }
            
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Vendor operation error: " . $e->getMessage());
            $_SESSION['error'] = "Error processing contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        } catch (Exception $e) {
            error_log("Vendor validation error: " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
    
    if ($action === 'add_customer' || $action === 'update_customer') {
        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Enhanced validation
        $validation_errors = validateContactInput($_POST);
        if (!empty($validation_errors)) {
            $_SESSION['error'] = implode("<br>", $validation_errors);
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            if ($action === 'add_customer') {
                $contact_id = generateContactId($db, 'Customer');
                
                $sql = "INSERT INTO business_contacts (contact_id, name, contact_person, email, phone, type, status) 
                        VALUES (?, ?, ?, ?, ?, 'Customer', 'Active')";
                $stmt = $db->prepare($sql);
                $stmt->execute([$contact_id, $name, $contact_person, $email, $phone]);
                
                // Add notification for new AR contact
                addContactNotification($name, 'Accounts Receivable');
                
                $_SESSION['success'] = "Accounts Receivable contact added successfully!";
                
            } else { // update_customer
                if ($customer_id === 0) {
                    throw new Exception("Invalid contact ID");
                }
                
                $sql = "UPDATE business_contacts SET name = ?, contact_person = ?, email = ?, phone = ? 
                        WHERE id = ? AND type = 'Customer'";
                $stmt = $db->prepare($sql);
                $stmt->execute([$name, $contact_person, $email, $phone, $customer_id]);
                
                $_SESSION['success'] = "Accounts Receivable contact updated successfully!";
            }
            
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Customer operation error: " . $e->getMessage());
            $_SESSION['error'] = "Error processing contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        } catch (Exception $e) {
            error_log("Customer validation error: " . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
    
    if ($action === 'delete_contact') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        $contact_type = $_POST['contact_type'] ?? '';
        
        if ($contact_id === 0 || !in_array($contact_type, ['Vendor', 'Customer'])) {
            $_SESSION['error'] = "Invalid contact data";
            header("Location: vendors_customers.php");
            exit;
        }
        
        try {
            // Check if contact has outstanding invoices before deletion
            $check_sql = "SELECT COUNT(*) as invoice_count FROM invoices 
                         WHERE contact_id = ? AND status != 'Paid'";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->execute([$contact_id]);
            $result = $check_stmt->fetch();
            
            if ($result['invoice_count'] > 0) {
                $_SESSION['error'] = "Cannot delete contact with outstanding invoices. Please resolve invoices first.";
                header("Location: vendors_customers.php");
                exit;
            }
            
            $sql = "UPDATE business_contacts SET status = 'Inactive' WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$contact_id]);
            
            $success_message = $contact_type === 'Vendor' ? 'Accounts Payable contact deleted successfully!' : 'Accounts Receivable contact deleted successfully!';
            $_SESSION['success'] = $success_message;
            header("Location: vendors_customers.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Contact deletion error: " . $e->getMessage());
            $_SESSION['error'] = "Error deleting contact: " . $e->getMessage();
            header("Location: vendors_customers.php");
            exit;
        }
    }
}

// Get all data
$vendors = getVendors($db);
$customers = getCustomers($db); // Now returns ALL AR contacts including budgets
$total_budget_allocations = getTotalBudgetAllocations($db); // NEW: Total approved budgets
$recent_budget_approvals = getRecentBudgetApprovals($db); // NEW: Recent budget approvals

// FIXED: Calculate total balances with CORRECTED logic
$total_ap_balance = 0;
$total_ar_balance = 0;
// Removed separate total_budget_balance logic

foreach ($vendors as $vendor) {
    // AP: Should be NEGATIVE (you owe vendors)
    $total_ap_balance += (float)$vendor['net_balance'];
}

foreach ($customers as $customer) {
    // AR: Should be POSITIVE (customers owe you) - use absolute value for display
    // Includes budget allocations now
    $total_ar_balance += abs((float)$customer['net_balance']);
}

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Check for success/error messages from session
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Check if there's a budget approval notification to add
if (isset($_GET['budget_approved']) && isset($_GET['proposal_title']) && isset($_GET['amount'])) {
    addBudgetApprovalNotification($_GET['proposal_title'], (float)$_GET['amount']);
    header("Location: vendors_customers.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Payable & Receivable - Financial Dashboard</title>
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
        /* Improved Notification Styles */
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

        .notification-footer {
            padding: 0.75rem;
            border-top: 1px solid #e5e7eb;
            text-align: center;
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

        

        /* Rest of your existing CSS styles */
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
            margin: 0;
        }
        
        .page-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
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
            border: 3px solid #f3f4f6;
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
            max-width: 600px;
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
        
        .status-approved {
            background-color: rgba(104, 211, 145, 0.1);
            color: #68D391;
        }
        
        .status-rejected {
            background-color: rgba(229, 62, 62, 0.1);
            color: #E53E3E;
        }
        
        .status-pending {
            background-color: rgba(251, 191, 36, 0.1);
            color: #F59E0B;
        }
        
        .status-overdue {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
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

        .action-btn.record {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.record:hover {
            background-color: #1D4ED8;
            color: white;
        }

        .tab-container {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
            color: #6b7280;
        }
        
        .tab.active {
            color: #2f855A;
            border-bottom-color: #2f855A;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .contact-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
        }
        
        .balance-positive {
            color: #10B981;
            font-weight: 600;
        }
        
        .balance-negative {
            color: #EF4444;
            font-weight: 600;
        }
        
        .credit-limit-warning {
            background-color: #FEF3C7;
            border: 1px solid #F59E0B;
            border-radius: 0.375rem;
            padding: 0.5rem;
            margin: 0.5rem 0;
        }
        
        /* Number visibility toggle styles */
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
        
        /* Amount error animation */
        .amount-error {
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body class="bg-gray-bg flex flex-col min-h-screen">
    <div class="overlay" id="overlay"></div>
    
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
    
    <div id="vendor-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4" id="vendor-modal-title">Add New Accounts Payable Contact</h2>
            <form id="vendor-form" method="POST">
                <input type="hidden" name="action" id="vendor-action" value="add_vendor">
                <input type="hidden" name="vendor_id" id="vendor-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Company Name *</label>
                        <input type="text" name="company_name" class="form-input" required minlength="2">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Person *</label>
                        <input type="text" name="contact_person" class="form-input" required minlength="2">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" pattern="[\d\s\-\+\(\)]{10,}">
                    </div>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Save Contact</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="customer-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4" id="customer-modal-title">Add New Accounts Receivable Contact</h2>
            <form id="customer-form" method="POST">
                <input type="hidden" name="action" id="customer-action" value="add_customer">
                <input type="hidden" name="customer_id" id="customer-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Company Name *</label>
                        <input type="text" name="company_name" class="form-input" required minlength="2">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contact Person *</label>
                        <input type="text" name="contact_person" class="form-input" required minlength="2">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" pattern="[\d\s\-\+\(\)]{10,}">
                    </div>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Save Contact</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="flex flex-1">
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
                
                <div class="flex-1 overflow-y-auto px-2 py-4">
                    <div class="space-y-4">
                        <a href="dashboard8.php" class="sidebar-item py-3 px-4 rounded-lg cursor-pointer mx-2 flex items-center hover:bg-hover-state transition-colors duration-200">
                            <i class='bx bx-home text-white mr-3 text-lg'></i>
                            <span class="text-sm font-medium text-white">FINANCIAL</span>
                        </a>
                        
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="disbursement">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">Disbursement</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="disbursement"></i>
                            </div>
                            <div class="submenu mt-1" id="disbursement-submenu">
                                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement History</a>
                                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>

                                <a href="disbursement_reports.php" class="submenu-item transition-colors duration-200">Disbursement Reports</a>
                            </div>
                        </div>

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
                        
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ap-ar">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">AP/AR</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ap-ar"></i>
                            </div>
                            <div class="submenu active mt-1" id="ap-ar-submenu">
                                <a href="vendors_customers.php" class="submenu-item active transition-colors duration-200">Payable/Receivable</a>
                                <a href="invoices.php" class="submenu-item transition-colors duration-200">Invoices</a>
                                <a href="payment_entry.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="aging_reports.php" class="submenu-item transition-colors duration-200">Aging Reports</a>
                            </div>
                        </div>
                        
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
                
                <div class="p-4 text-center text-xs text-white/80 border-t border-white/10 mt-auto">
                    <p>© 2025 Financial Dashboard. All rights reserved.</p>
                </div>
            </div>
        </div>
        
        <div id="main-content" class="flex-1 flex flex-col min-h-screen">
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Accounts Payable & Receivable</h1>
                        <p class="text-sm text-white/90">Manage accounts payable, accounts receivable, and budget allocations</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <a href="?toggle_numbers=1" class="eye-toggle-btn" title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                        <i class='bx <?php echo $_SESSION['show_numbers'] ? 'bx-hide' : 'bx-show'; ?> text-xl'></i>
                    </a>
                    
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
                                    <div class="notification-item <?php echo !$notification['read'] ? 'unread' : ''; ?>" onclick="window.location.href='<?php echo htmlspecialchars($notification['link']); ?>'">
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
            
            <div class="p-6 flex-1">
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

                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex-1">
                            <div class="relative">
                                <input type="text" id="search-contacts" placeholder="Search contacts by company name, contact person, email, or ID..." 
                                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-green focus:border-transparent">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class='bx bx-search text-gray-400'></i>
                                </div>
                            </div>
                            
                        </div>
                        <div class="flex space-x-2">
                            <button id="clear-search" class="btn btn-secondary whitespace-nowrap">
                                <i class='bx bx-reset mr-2'></i>Clear
                            </button>
                            <div class="relative">
                                <select id="filter-balance" class="form-input pr-8">
                                    <option value="">All Balances</option>
                                    <option value="positive">Positive Balance</option>
                                    <option value="negative">Negative Balance</option>
                                    <option value="zero">Zero Balance</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="search-results-info" class="mt-3 text-sm text-gray-600 hidden">
                        <span id="results-count">0</span> contacts found
                    </div>
                </div>
              
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="contact-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total AP</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatCount(count($vendors), $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-building text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="contact-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total AR</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatCount(count($customers), $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-group text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="contact-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">AP Balance</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($total_ap_balance, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-credit-card text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="contact-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">AR Balance</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($total_ar_balance, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-money text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl card-shadow">
                    <div class="tab-container">
                        <div class="tab active" data-tab="vendors">Accounts Payable</div>
                        <div class="tab" data-tab="customers">Accounts Receivable</div>
                    </div>

                    <div class="p-6">
                        <div class="tab-content active" id="vendors-tab">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-dark-text">Accounts Payable Management</h3>
                                <button class="btn btn-primary" onclick="openVendorModal()">
                                    <i class='bx bx-plus mr-2'></i>Add AP Contact
                                </button>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Contact ID</th>
                                            <th>Company Name</th>
                                            <th>Contact Person</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Net Balance</th>
                                            <th>Total Payments</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($vendors) > 0): ?>
                                            <?php foreach ($vendors as $vendor): ?>
                                            <tr>
                                                <td class="font-mono font-medium"><?php echo htmlspecialchars($vendor['contact_id']); ?></td>
                                                <td class="font-medium"><?php echo htmlspecialchars($vendor['name']); ?></td>
                                                <td><?php echo htmlspecialchars($vendor['contact_person']); ?></td>
                                                <td><?php echo htmlspecialchars($vendor['email']); ?></td>
                                                <td><?php echo htmlspecialchars($vendor['phone']); ?></td>
                                                <td class="<?php echo (float)$vendor['net_balance'] < 0 ? 'balance-negative' : 'balance-positive'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber((float)$vendor['net_balance'], $_SESSION['show_numbers']); ?>
                                                </td>
                                                <td class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber((float)$vendor['total_payments'], $_SESSION['show_numbers']); ?>
                                                    <div class="text-xs text-gray-500"><?php echo $vendor['payment_count']; ?> payments</div>
                                                </td>
                                                <td>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button class="action-btn record" title="Record Invoice" onclick="recordInvoiceForContact(<?php echo $vendor['id']; ?>, 'Vendor')">
                                                            <i class='bx bx-receipt mr-1'></i>Record
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-gray-500">
                                                    No accounts payable contacts found. <button class="text-primary-green hover:underline" onclick="openVendorModal()">Add your first AP contact</button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-content" id="customers-tab">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-lg font-bold text-dark-text">Accounts Receivable Management</h3>
                                <button class="btn btn-primary" onclick="openCustomerModal()">
                                    <i class='bx bx-plus mr-2'></i>Add AR Contact
                                </button>
                            </div>
                            
                            <div class="mb-8">
                                
                                <div class="overflow-x-auto">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Contact ID</th>
                                                <th>Company Name</th>
                                                <th>Contact Person</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Allocated Budget</th>
                                                <th>Amount</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($customers) > 0): ?>
                                                <?php foreach ($customers as $customer): ?>
                                                <tr>
                                                    <td class="font-mono font-medium"><?php echo htmlspecialchars($customer['contact_id']); ?></td>
                                                    <td class="font-medium">
                                                        <?php echo htmlspecialchars($customer['name']); ?>
                                                        <?php if ($customer['contact_person'] === 'Admin'): ?>
    <span class="budget-badge"></span>
<?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                                    <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                                    <td class="<?php echo (float)$customer['net_balance'] < 0 ? 'balance-negative' : 'balance-positive'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                        <?php echo formatNumber((float)$customer['net_balance'], $_SESSION['show_numbers']); ?>
                                                    </td>
                                                    <td class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                        <?php echo formatNumber((float)$customer['total_payments'], $_SESSION['show_numbers']); ?>
                                                        <div class="text-xs text-gray-500"><?php echo $customer['payment_count']; ?> payments</div>
                                                    </td>
                                                    <td>
                                                        <div class="flex flex-wrap gap-2">
                                                            <button class="action-btn record" title="Record Invoice" onclick="recordInvoiceForContact(<?php echo $customer['id']; ?>, 'Customer')">
                                                                <i class='bx bx-receipt mr-1'></i>Record
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4 text-gray-500">
                                                        No accounts receivable contacts found. <button class="text-primary-green hover:underline" onclick="openCustomerModal()">Add your first AR contact</button>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button class="btn btn-primary w-full text-left" onclick="openVendorModal()">
                                <i class='bx bx-plus mr-2'></i>Add Accounts Payable Contact
                            </button>
                            <button class="btn btn-primary w-full text-left" onclick="openCustomerModal()">
                                <i class='bx bx-plus mr-2'></i>Add Accounts Receivable Contact
                            </button>
                            <button class="btn btn-secondary w-full text-left" onclick="window.location.href='payment_entry.php'">
                                <i class='bx bx-credit-card mr-2'></i>Record Payment
                            </button>
                            <button class="btn btn-secondary w-full text-left" onclick="window.location.href='invoices.php'">
                                <i class='bx bx-receipt mr-2'></i>Manage Invoices
                            </button>
                            <button class="btn btn-secondary w-full text-left" onclick="window.location.href='budget_proposal.php'">
                                <i class='bx bx-wallet mr-2'></i>Manage Budget Proposals
                            </button>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Recent Activity</h3>
                        <div class="space-y-3">
                            <?php
                            // Get recent activity
                            $recent_vendors = array_slice($vendors, 0, 1);
                            $recent_customers = array_slice($customers, 0, 1);
                            $recent_budgets = array_slice($recent_budget_approvals, 0, 2);
                            ?>
                            
                            <?php foreach ($recent_vendors as $vendor): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div>
                                    <div class="font-medium">New AP contact added</div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($vendor['name']); ?></div>
                                </div>
                                <div class="text-sm text-gray-500">Recently</div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($recent_customers as $customer): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div>
                                    <div class="font-medium">New AR contact added</div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($customer['name']); ?></div>
                                </div>
                                <div class="text-sm text-gray-500">Recently</div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($recent_budgets as $budget): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div>
                                    <div class="font-medium">Budget approved</div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($budget['title']); ?> (<?php echo htmlspecialchars($budget['department_name']); ?>)</div>
                                </div>
                                <div class="text-sm text-gray-500"><?php echo date('M j', strtotime($budget['approval_date'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <footer class="main-footer mt-auto">
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
        
        categoryToggles.forEach(function(toggle) {
            toggle.addEventListener('click', function() {
                const category = this.getAttribute('data-category');
                const submenu = document.getElementById(category + '-submenu');
                const arrow = document.querySelector('.category-arrow[data-category="' + category + '"]');
                
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotate-180');
            });
        });

        // Tab functionality
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all tabs and tab contents
                tabs.forEach(function(t) {
                    t.classList.remove('active');
                });
                document.querySelectorAll('.tab-content').forEach(function(content) {
                    content.classList.remove('active');
                });
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabContent = document.getElementById(tabId + '-tab');
                if (tabContent) {
                    tabContent.classList.add('active');
                }
                
                // Re-run search when switching tabs
                setTimeout(performSearch, 100);
            });
        });

        // Modal functionality
        const profileBtn = document.getElementById('profile-btn');
        const profileModal = document.getElementById('profile-modal');
        const vendorModal = document.getElementById('vendor-modal');
        const customerModal = document.getElementById('customer-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                if (profileModal) profileModal.style.display = 'none';
                if (vendorModal) vendorModal.style.display = 'none';
                if (customerModal) customerModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) profileModal.style.display = 'none';
            if (event.target === vendorModal) vendorModal.style.display = 'none';
            if (event.target === customerModal) customerModal.style.display = 'none';
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
            
            document.addEventListener('click', function() {
                notificationDropdown.classList.remove('active');
            });
            
            notificationDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
        
        // Mark all as read functionality
        const markAllReadBtn = document.getElementById('mark-all-read');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                fetch('?mark_notifications_read=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const unreadItems = document.querySelectorAll('.notification-item.unread');
                            unreadItems.forEach(item => item.classList.remove('unread'));
                            const badge = document.querySelector('.notification-badge');
                            if (badge) badge.remove();
                            markAllReadBtn.style.display = 'none';
                        }
                    });
            });
        }

        // Initialize search functionality
        initializeSearch();
    });

    // Accounts Payable functions
    function openVendorModal(vendorId) {
        if (vendorId === undefined) vendorId = null;
        const modal = document.getElementById('vendor-modal');
        const title = document.getElementById('vendor-modal-title');
        const action = document.getElementById('vendor-action');
        const vendorIdInput = document.getElementById('vendor-id');
        
        if (vendorId) {
            title.textContent = 'Edit Accounts Payable Contact';
            action.value = 'update_vendor';
            vendorIdInput.value = vendorId;
        } else {
            title.textContent = 'Add New Accounts Payable Contact';
            action.value = 'add_vendor';
            vendorIdInput.value = '';
            document.getElementById('vendor-form').reset();
        }
        modal.style.display = 'block';
    }

    // Accounts Receivable functions
    function openCustomerModal(customerId) {
        if (customerId === undefined) customerId = null;
        const modal = document.getElementById('customer-modal');
        const title = document.getElementById('customer-modal-title');
        const action = document.getElementById('customer-action');
        const customerIdInput = document.getElementById('customer-id');
        
        if (customerId) {
            title.textContent = 'Edit Accounts Receivable Contact';
            action.value = 'update_customer';
            customerIdInput.value = customerId;
        } else {
            title.textContent = 'Add New Accounts Receivable Contact';
            action.value = 'add_customer';
            customerIdInput.value = '';
            document.getElementById('customer-form').reset();
        }
        modal.style.display = 'block';
    }

    // Record invoice for specific contact - MODIFIED to go to invoice records
    function recordInvoiceForContact(contactId, contactType) {
        // Determine which page to show based on contact type
        if (contactType === 'Vendor') {
            // For vendors (AP), go to payable invoice records
            window.location.href = 'invoices.php?view=payables&contact_id=' + contactId;
        } else {
            // For customers (AR), go to receivable invoice records
            window.location.href = 'invoices.php?view=receivables&contact_id=' + contactId;
        }
    }
      
    // Search functionality
    function performSearch() {
        const searchInput = document.getElementById('search-contacts');
        const selectedBalance = document.getElementById('filter-balance')?.value || '';
        const searchResultsInfo = document.getElementById('search-results-info');
        const resultsCount = document.getElementById('results-count');
        
        if (!searchInput || !searchResultsInfo) return;
        
        const searchTerm = searchInput.value.toLowerCase().trim();
        const activeTab = document.querySelector('.tab.active')?.getAttribute('data-tab');
        if (!activeTab) return;
        
        const tableBody = document.querySelector(`#${activeTab}-tab .data-table tbody`);
        if (!tableBody) return;
        
        const rows = tableBody.querySelectorAll('tr');
        let visibleRows = 0;
        
        rows.forEach(row => {
            if (row.cells.length <= 1) return;
            
            const cells = row.cells;
            const contactId = cells[0].textContent.toLowerCase();
            const companyName = cells[1].textContent.toLowerCase();
            const contactPerson = cells[2].textContent.toLowerCase();
            const email = cells[3].textContent.toLowerCase();
            const balanceCell = cells[5];
            const balanceText = balanceCell.textContent;
            
            const matchesSearch = !searchTerm || 
                                 contactId.includes(searchTerm) || 
                                 companyName.includes(searchTerm) || 
                                 contactPerson.includes(searchTerm) || 
                                 email.includes(searchTerm);
            
            let matchesBalance = true;
            if (selectedBalance) {
                const balanceValue = parseFloat(balanceText.replace(/[^\d.-]/g, ''));
                if (selectedBalance === 'positive' && balanceValue <= 0) matchesBalance = false;
                if (selectedBalance === 'negative' && balanceValue >= 0) matchesBalance = false;
                if (selectedBalance === 'zero' && balanceValue !== 0) matchesBalance = false;
            }
            
            const isVisible = matchesSearch && matchesBalance;
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleRows++;
        });
        
        if (searchTerm || selectedBalance) {
            searchResultsInfo.classList.remove('hidden');
            resultsCount.textContent = visibleRows;
        } else {
            searchResultsInfo.classList.add('hidden');
        }
    }

    function initializeSearch() {
        const searchInput = document.getElementById('search-contacts');
        const clearSearchBtn = document.getElementById('clear-search');
        const filterBalance = document.getElementById('filter-balance');
        
        if (!searchInput) return;
        
        searchInput.addEventListener('input', performSearch);
        if (filterBalance) filterBalance.addEventListener('change', performSearch);
        
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                if (filterBalance) filterBalance.value = '';
                performSearch();
                searchInput.focus();
            });
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
        });
        
        performSearch();
    }
    </script>
</body>
</html>