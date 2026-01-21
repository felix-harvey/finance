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
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Logout functionality
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// Load current user
$u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
$u->execute([$user_id]);
$user = $u->fetch();
if (!$user) {
    header("Location: login.php");
    exit;
}

// Function to add a notification when payment is received
function addPaymentNotification($payment_id, $contact_name, $amount) {
    $notification = [
        'id' => uniqid(),
        'type' => 'payment',
        'message' => "Payment received from {$contact_name}: {$payment_id} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'payment_entry.php',
        'read' => false
    ];
    
    // Add to beginning of array (newest first)
    array_unshift($_SESSION['ap_ar_notifications'], $notification);
    
    // Keep only last 10 notifications
    $_SESSION['ap_ar_notifications'] = array_slice($_SESSION['ap_ar_notifications'], 0, 10);
}

// Function to add a notification when payment is made
function addPaymentMadeNotification($payment_id, $vendor_name, $amount) {
    $notification = [
        'id' => uniqid(),
        'type' => 'payment',
        'message' => "Payment made to {$vendor_name}: {$payment_id} - " . formatNumber($amount, true),
        'timestamp' => time(),
        'link' => 'payment_entry.php',
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

// Get payments data with filters
function getPayments(PDO $db, ?string $type = null, ?string $status = null, ?string $start_date = null, ?string $end_date = null): array {
    $sql = "SELECT p.*, bc.name as contact_name, bc.contact_person, i.invoice_number, i.amount as invoice_amount,
                   i.type as invoice_type
            FROM payments p
            LEFT JOIN business_contacts bc ON p.contact_id = bc.id
            LEFT JOIN invoices i ON p.invoice_id = i.id
            WHERE 1=1";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND p.type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }
    
    if ($start_date) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $end_date;
    }
    
    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching payments: " . $e->getMessage());
        return [];
    }
}

// Get outstanding invoices for payment
function getOutstandingInvoices(PDO $db, ?string $type = null): array {
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.status != 'Paid' AND i.status != 'Cancelled'";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY i.id, i.invoice_number, i.contact_id, i.type, i.issue_date, i.due_date, 
                     i.amount, i.status, i.description, i.created_at, bc.name, bc.contact_person
              HAVING outstanding_balance > 0
              ORDER BY i.due_date ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching outstanding invoices: " . $e->getMessage());
        return [];
    }
}

// Get business contacts for dropdown
function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    $params = [];
    if ($type) {
        $sql .= " AND type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY name ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching business contacts: " . $e->getMessage());
        return [];
    }
}

// Generate unique payment ID
function generatePaymentId(PDO $db, string $type): string {
    $prefix = $type === 'Receive' ? 'PMT' : 'V-PMT';
    $year = date('Y');
    
    try {
        // Get the latest payment ID for this type and year
        $sql = "SELECT payment_id FROM payments WHERE payment_id LIKE ? ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$prefix . '-' . $year . '-%']);
        $lastPayment = $stmt->fetch();
        
        if ($lastPayment) {
            $lastNumber = intval(substr($lastPayment['payment_id'], -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }
        
        return $prefix . '-' . $year . '-' . $newNumber;
    } catch (PDOException $e) {
        error_log("Error generating payment ID: " . $e->getMessage());
        // Fallback method
        $timestamp = time();
        return $prefix . '-' . $year . '-' . substr($timestamp, -6);
    }
}

// Generate automatic OR Number
function generateOrNumber(PDO $db): string {
    $year = date('Y');
    
    try {
        // Get the latest OR number for this year
        $sql = "SELECT reference_number FROM payments 
                WHERE reference_number LIKE ? 
                ORDER BY CAST(SUBSTRING(reference_number, 5) AS UNSIGNED) DESC 
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute(['OR-' . $year . '-%']);
        $lastOr = $stmt->fetch();
        
        if ($lastOr) {
            $lastNumber = intval(substr($lastOr['reference_number'], 8)); // OR-YYYY-XXXXX
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        // Format as OR-YYYY-XXXXX (5 digits)
        return 'OR-' . $year . '-' . str_pad((string)$newNumber, 5, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error generating OR number: " . $e->getMessage());
        // Fallback method
        $timestamp = time();
        return 'OR-' . $year . '-' . substr($timestamp, -5);
    }
}

// Get invoice details for payment
function getInvoiceDetails(PDO $db, int $invoice_id): array {
    try {
        $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person, bc.id as contact_id,
                       (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
                FROM invoices i
                LEFT JOIN business_contacts bc ON i.contact_id = bc.id
                LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                WHERE i.id = ?
                GROUP BY i.id";
        $stmt = $db->prepare($sql);
        $stmt->execute([$invoice_id]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching invoice details: " . $e->getMessage());
        return [];
    }
}

// Get outstanding invoices for a specific contact
function getOutstandingInvoicesByContact(PDO $db, int $contact_id, ?string $type = null): array {
    $sql = "SELECT i.*, bc.name as contact_name, bc.contact_person,
                   (i.amount - COALESCE(SUM(p.amount), 0)) as outstanding_balance
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.id
            LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
            WHERE i.contact_id = ? AND i.status != 'Paid' AND i.status != 'Cancelled'";
    
    $params = [$contact_id];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY i.id, i.invoice_number, i.contact_id, i.type, i.issue_date, i.due_date, 
                     i.amount, i.status, i.description, i.created_at, bc.name, bc.contact_person
              HAVING outstanding_balance > 0
              ORDER BY i.due_date ASC";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching outstanding invoices by contact: " . $e->getMessage());
        return [];
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
        header("Location: payment_entry.php");
        exit;
    }
    
    if ($action === 'add_payment') {
        $payment_id = $_POST['payment_id'] ?? '';
        $contact_id = $_POST['contact_id'] ?? '';
        $invoice_id = $_POST['invoice_id'] ?? '';
        $payment_date = $_POST['payment_date'] ?? '';
        $amount = $_POST['amount'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        $type = $_POST['type'] ?? '';
        $reference_number = $_POST['reference_number'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Validate required fields
        if (empty($payment_id) || empty($contact_id) || empty($payment_date) || empty($amount) || empty($payment_method) || empty($type)) {
            $_SESSION['error'] = "Please fill in all required fields";
            header("Location: payment_entry.php");
            exit;
        }
        
        // Validate amount
        if (!is_numeric($amount) || $amount <= 0) {
            $_SESSION['error'] = "Please enter a valid payment amount";
            header("Location: payment_entry.php");
            exit;
        }
        
        // Generate OR Number if not provided
        if (empty($reference_number)) {
            $reference_number = generateOrNumber($db);
        }
        
        try {
            // Check if payment ID already exists
            $checkSql = "SELECT id FROM payments WHERE payment_id = ?";
            $checkStmt = $db->prepare($checkSql);
            $checkStmt->execute([$payment_id]);
            
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = "Payment ID already exists. Please try again.";
                header("Location: payment_entry.php");
                exit;
            }
            
            // Get contact details for notification
            $contactStmt = $db->prepare("SELECT name, type FROM business_contacts WHERE id = ?");
            $contactStmt->execute([$contact_id]);
            $contact = $contactStmt->fetch();
            
            // Determine if this is a collection payment (Receive type)
            $isCollectionPayment = ($type === 'Receive');
            
            // Check if notes column exists
            $checkColumns = $db->query("SHOW COLUMNS FROM payments LIKE 'notes'")->fetch();
            if ($checkColumns) {
                // Insert without created_by column
                $sql = "INSERT INTO payments (payment_id, contact_id, invoice_id, payment_date, amount, 
                        payment_method, type, reference_number, notes, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed')";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $payment_id, 
                    $contact_id, 
                    !empty($invoice_id) ? $invoice_id : null, 
                    $payment_date, 
                    $amount, 
                    $payment_method, 
                    $type, 
                    trim($reference_number), // Trim the OR Number
                    $notes
                ]);
            } else {
                // Insert without notes and created_by columns
                $sql = "INSERT INTO payments (payment_id, contact_id, invoice_id, payment_date, amount, 
                        payment_method, type, reference_number, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Completed')";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $payment_id, 
                    $contact_id, 
                    !empty($invoice_id) ? $invoice_id : null, 
                    $payment_date, 
                    $amount, 
                    $payment_method, 
                    $type, 
                    trim($reference_number) // Trim the OR Number
                ]);
            }
            
            $new_payment_id = $db->lastInsertId();
            
            // Create notification for payments
            if ($type === 'Receive') {
                addPaymentNotification($payment_id, $contact['name'] ?? 'customer', $amount);
            } else {
                addPaymentMadeNotification($payment_id, $contact['name'] ?? 'vendor', $amount);
            }
            
            // Update invoice status if fully paid and invoice_id is provided
            if (!empty($invoice_id)) {
                $invoice_sql = "SELECT i.amount, COALESCE(SUM(p.amount), 0) as total_paid
                               FROM invoices i
                               LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                               WHERE i.id = ?
                               GROUP BY i.id";
                $invoice_stmt = $db->prepare($invoice_sql);
                $invoice_stmt->execute([$invoice_id]);
                $invoice_data = $invoice_stmt->fetch();
                
                if ($invoice_data && ($invoice_data['total_paid'] + $amount) >= $invoice_data['amount']) {
                    $update_sql = "UPDATE invoices SET status = 'Paid' WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->execute([$invoice_id]);
                } else {
                    // Update to partially paid if there's some payment but not full
                    $update_sql = "UPDATE invoices SET status = 'Pending' WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->execute([$invoice_id]);
                }
            }
            
            $_SESSION['success'] = "Payment recorded successfully! OR Number: " . $reference_number . 
                ($isCollectionPayment ? " The payment has been added to collections." : "");
            
            header("Location: payment_entry.php");
            exit;
        } catch (PDOException $e) {
            error_log("Payment recording error: " . $e->getMessage());
            $_SESSION['error'] = "Error recording payment: " . $e->getMessage();
            header("Location: payment_entry.php");
            exit;
        }
    }
    
    // Handle delete payment
    if ($action === 'delete_payment') {
        $payment_id = $_POST['payment_id'] ?? '';
        
        if (!empty($payment_id)) {
            try {
                // Get payment details before deletion for invoice status update
                $payment_sql = "SELECT invoice_id, amount FROM payments WHERE payment_id = ?";
                $payment_stmt = $db->prepare($payment_sql);
                $payment_stmt->execute([$payment_id]);
                $payment_data = $payment_stmt->fetch();
                
                // Delete the payment
                $delete_sql = "DELETE FROM payments WHERE payment_id = ?";
                $delete_stmt = $db->prepare($delete_sql);
                $delete_stmt->execute([$payment_id]);
                
                // Update invoice status if it was linked
                if ($payment_data && $payment_data['invoice_id']) {
                    $invoice_sql = "SELECT i.amount, COALESCE(SUM(p.amount), 0) as total_paid
                                   FROM invoices i
                                   LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'Completed'
                                   WHERE i.id = ?
                                   GROUP BY i.id";
                    $invoice_stmt = $db->prepare($invoice_sql);
                    $invoice_stmt->execute([$payment_data['invoice_id']]);
                    $invoice_data = $invoice_stmt->fetch();
                    
                    if ($invoice_data && $invoice_data['total_paid'] < $invoice_data['amount']) {
                        $update_sql = "UPDATE invoices SET status = 'Pending' WHERE id = ?";
                        $update_stmt = $db->prepare($update_sql);
                        $update_stmt->execute([$payment_data['invoice_id']]);
                    }
                }
                
                $_SESSION['success'] = "Payment deleted successfully!";
                header("Location: payment_entry.php");
                exit;
            } catch (PDOException $e) {
                error_log("Payment deletion error: " . $e->getMessage());
                $_SESSION['error'] = "Error deleting payment: " . $e->getMessage();
                header("Location: payment_entry.php");
                exit;
            }
        }
    }
}

// Handle AJAX request for invoice details
if (isset($_GET['get_invoice_details']) && isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $invoice_details = getInvoiceDetails($db, $invoice_id);
    header('Content-Type: application/json');
    echo json_encode($invoice_details);
    exit;
}

// Handle AJAX request for contact invoices
if (isset($_GET['get_contact_invoices']) && isset($_GET['contact_id'])) {
    $contact_id = (int)$_GET['contact_id'];
    $type = $_GET['type'] ?? null;
    $invoices = getOutstandingInvoicesByContact($db, $contact_id, $type);
    header('Content-Type: application/json');
    echo json_encode($invoices);
    exit;
}

// Handle AJAX request for generating OR number
if (isset($_GET['generate_or_number'])) {
    $or_number = generateOrNumber($db);
    header('Content-Type: application/json');
    echo json_encode(['or_number' => $or_number]);
    exit;
}

// Handle invoice-based payment redirect
if (isset($_GET['invoice_id'])) {
    $invoice_id = (int)$_GET['invoice_id'];
    $invoice_details = getInvoiceDetails($db, $invoice_id);
    
    if ($invoice_details) {
        // Pre-fill the payment form with invoice data
        echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    openPaymentModal();
                    // Pre-fill form with invoice data
                    document.getElementById('payment-type').value = '" . ($invoice_details['type'] === 'Receivable' ? 'Receive' : 'Make') . "';
                    updatePaymentNumber();
                    document.getElementById('contact-id').value = '" . $invoice_details['contact_id'] . "';
                    document.getElementById('selected-invoice-id').value = '" . $invoice_id . "';
                    document.getElementById('payment-amount').value = '" . $invoice_details['outstanding_balance'] . "';
                    
                    // Hide invoice selection since we're auto-selecting
                    document.getElementById('invoice-selection').style.display = 'none';
                }, 500);
            });
        </script>";
    }
}

// Get filter values from request
$payment_type = $_GET['type'] ?? null;
$payment_status = $_GET['status'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$payments = getPayments($db, $payment_type, $payment_status, $start_date, $end_date);
$outstanding_invoices = getOutstandingInvoices($db);
$vendors = getBusinessContacts($db, 'Vendor');
$customers = getBusinessContacts($db, 'Customer');
$all_contacts = getBusinessContacts($db);

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
    header("Location: payment_entry.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Entry - Financial Dashboard</title>
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
        /* All your existing CSS styles remain the same */
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

        /* All other existing styles remain exactly the same */
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
        .status-processing {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3B82F6;
        }
        .status-cancelled {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
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
        
        .payment-card {
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
        
        .invoice-selection {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .invoice-option {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .invoice-option:hover {
            background-color: #f1f5f9;
        }
        
        .invoice-option.selected {
            background-color: #dbeafe;
            border-color: #3b82f6;
        }
        
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .method-cash { background-color: #dcfce7; color: #166534; }
        .method-check { background-color: #fef3c7; color: #92400e; }
        .method-transfer { background-color: #dbeafe; color: #1e40af; }
        .method-card { background-color: #f3e8ff; color: #7c3aed; }
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
    
    <!-- Modal for Add/Edit Payment -->
    <div id="payment-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4" id="payment-modal-title">Record Payment</h2>
            
            <!-- Error Message Display -->
            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form id="payment-form" method="POST">
                <input type="hidden" name="action" id="payment-action" value="add_payment">
                <input type="hidden" name="payment_id" id="payment-id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Payment ID *</label>
                        <input type="text" name="payment_id" id="payment-number" class="form-input" required readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Type *</label>
                        <select name="type" id="payment-type" class="form-input" required onchange="updatePaymentNumber()">
                            <option value="">Select Type</option>
                            <option value="Receive">Receive Payment (AR)</option>
                            <option value="Make">Make Payment (AP)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Contact *</label>
                    <select name="contact_id" id="contact-id" class="form-input" required onchange="loadContactInvoices()">
                        <option value="">Select Contact</option>
                        <!-- Options will be populated dynamically -->
                    </select>
                </div>
                
                <!-- Invoice Selection -->
                <div id="invoice-selection" class="invoice-selection" style="display: none;">
                    <h4 class="font-medium mb-3">Select Invoice to Pay</h4>
                    <div id="invoice-options">
                        <!-- Invoice options will be populated here -->
                    </div>
                    <div class="mt-3">
                        <label class="flex items-center">
                            <input type="checkbox" id="no-invoice" class="mr-2">
                            <span class="text-sm">Record payment without invoice</span>
                        </label>
                    </div>
                </div>
                
                <input type="hidden" name="invoice_id" id="selected-invoice-id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Payment Date *</label>
                        <input type="date" name="payment_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount *</label>
                        <input type="number" name="amount" id="payment-amount" class="form-input" step="0.01" min="0.01" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="form-label">Payment Method *</label>
                        <select name="payment_method" class="form-input" required>
                            <option value="">Select Method</option>
                            <option value="Cash">Cash</option>
                            <option value="Check">Check</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">OR Number *</label>
                        <div class="flex space-x-2">
                            <input type="text" name="reference_number" id="or-number" class="form-input flex-1" required readonly>
                            <button type="button" onclick="generateOrNumber()" class="btn btn-secondary" title="Generate New OR Number">
                                <i class='bx bx-refresh'></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">OR number is automatically generated</p>
                    </div>
                </div>
                
                <div class="form-group mb-6">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input" rows="3" placeholder="Additional payment notes"></textarea>
                </div>
                
                <div id="status-field" class="form-group mb-4" style="display: none;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input">
                        <option value="Completed">Completed</option>
                        <option value="Processing">Processing</option>
                        <option value="Scheduled">Scheduled</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="flex space-x-4">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Record Payment</button>
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
                                <a href="invoices.php" class="submenu-item transition-colors duration-200">Invoices</a>
                                <a href="payment_entry.php" class="submenu-item active transition-colors duration-200">Payment Entry</a>
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
                        <h1 class="text-2xl font-bold text-white">Payment Entry</h1>
                        <p class="text-sm text-white/90">Record and manage customer/vendor payments</p>
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
            
            <div class="p-6 flex-1">
                <!-- Success Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Error Messages -->
                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="payment-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Payments</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber(count($payments), $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-credit-card text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="payment-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Amount</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $total_amount = 0;
                                        foreach ($payments as $payment) {
                                            $total_amount += (float)$payment['amount'];
                                        }
                                        echo formatNumber($total_amount, $_SESSION['show_numbers']);
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-money text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="payment-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Outstanding Invoices</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber(count($outstanding_invoices), $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-time text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="payment-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">This Month</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $this_month = 0;
                                        foreach ($payments as $payment) {
                                            if (date('Y-m', strtotime($payment['payment_date'])) === date('Y-m')) {
                                                $this_month += (float)$payment['amount'];
                                            }
                                        }
                                        echo formatNumber($this_month, $_SESSION['show_numbers']);
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-calendar text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Filter Payments</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-input">
                                <option value="">All Types</option>
                                <option value="Receive" <?php echo $payment_type === 'Receive' ? 'selected' : ''; ?>>Receive Payment</option>
                                <option value="Make" <?php echo $payment_type === 'Make' ? 'selected' : ''; ?>>Make Payment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-input">
                                <option value="">All Status</option>
                                <option value="Completed" <?php echo $payment_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Processing" <?php echo $payment_status === 'Processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="Scheduled" <?php echo $payment_status === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="Cancelled" <?php echo $payment_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
                            <a href="payment_entry.php" class="btn btn-secondary">
                                <i class='bx bx-reset mr-2'></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Payments Table -->
                <div class="bg-white rounded-xl card-shadow">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-dark-text">Payment Management</h3>
                            <button class="btn btn-primary" onclick="openPaymentModal()">
                                <i class='bx bx-plus mr-2'></i>Record Payment
                            </button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Payment ID</th>
                                        <th>Contact</th>
                                        <th>Invoice</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>OR No</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($payments) > 0): ?>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td class="font-mono font-medium"><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                                            <td>
                                                <div class="font-medium"><?php echo htmlspecialchars($payment['contact_name'] ?? 'N/A'); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['contact_person'] ?? 'N/A'); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($payment['invoice_number']): ?>
                                                    <span class="font-mono text-sm"><?php echo htmlspecialchars($payment['invoice_number']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-sm">No invoice</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                    <?php echo $payment['type'] === 'Receive' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo $payment['type'] === 'Receive' ? 'Receive' : 'Make'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                            <td class="font-medium <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)$payment['amount'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $method_class = match($payment['payment_method']) {
                                                    'Cash' => 'method-cash',
                                                    'Check' => 'method-check',
                                                    'Bank Transfer' => 'method-transfer',
                                                    'Credit Card' => 'method-card',
                                                    default => 'method-cash'
                                                };
                                                ?>
                                                <span class="payment-method-badge <?php echo $method_class; ?>">
                                                    <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td class="font-mono text-sm"><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                                            <td>
                                                <?php
                                                $status_class = match($payment['status']) {
                                                    'Completed' => 'status-completed',
                                                    'Processing' => 'status-processing',
                                                    'Scheduled' => 'status-pending',
                                                    'Cancelled' => 'status-cancelled',
                                                    default => 'status-pending'
                                                };
                                                ?>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars($payment['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap gap-2">
                                                    <button class="action-btn view" title="View Related Invoice" onclick="viewInvoice('<?php echo $payment['invoice_id']; ?>')">
                                                        <i class='bx bx-show mr-1'></i>View
                                                    </button>
                                                    
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-gray-500">
                                                No payments found. <button class="text-primary-green hover:underline" onclick="openPaymentModal()">Record your first payment</button>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Payment Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span>Total Payments Amount:</span>
                                <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $total_amount = 0;
                                        foreach ($payments as $payment) {
                                            $total_amount += (float)$payment['amount'];
                                        }
                                        echo formatNumber($total_amount, $_SESSION['show_numbers']);
                                    ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Receivable Payments:</span>
                                <span class="font-bold amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $receive_total = 0;
                                        foreach ($payments as $payment) {
                                            if ($payment['type'] === 'Receive') {
                                                $receive_total += (float)$payment['amount'];
                                            }
                                        }
                                        echo formatNumber($receive_total, $_SESSION['show_numbers']);
                                    ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Payable Payments:</span>
                                <span class="font-bold amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php 
                                        $make_total = 0;
                                        foreach ($payments as $payment) {
                                            if ($payment['type'] === 'Make') {
                                                $make_total += (float)$payment['amount'];
                                            }
                                        }
                                        echo formatNumber($make_total, $_SESSION['show_numbers']);
                                    ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span>Outstanding Invoices:</span>
                                <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber(count($outstanding_invoices), $_SESSION['show_numbers']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <button class="btn btn-primary w-full text-left" onclick="openPaymentModal()">
                                <i class='bx bx-plus mr-2'></i>Record New Payment
                            </button>
                            <button class="btn btn-secondary w-full text-left">
                                <i class='bx bx-import mr-2'></i>Import Payments
                            </button>
                            <button class="btn btn-secondary w-full text-left">
                                <i class='bx bx-export mr-2'></i>Export Payments
                            </button>
                            <button class="btn btn-secondary w-full text-left" onclick="window.location.href='invoices.php'">
                                <i class='bx bx-receipt mr-2'></i>View Invoices
                            </button>
                        </div>
                    </div>
                </div>
            </div> <!-- Close the main content div -->
            
            <!-- Footer -->
            <footer class="main-footer">
                <div class="text-center">
                    <p class="text-sm">© 2025 Financial Dashboard. All rights reserved.</p>
                    <p class="text-xs mt-1 opacity-80">Powered by Microfinancial Management System</p>
                </div>
            </footer>
        </div> <!-- Close the main-content flex container -->
    </div> <!-- Close the page-container -->

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
        const profileModal = document.getElementById('profile-modal');
        const paymentModal = document.getElementById('payment-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                profileModal.style.display = 'none';
                paymentModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
            }
            if (event.target === paymentModal) {
                paymentModal.style.display = 'none';
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
        const paymentForm = document.getElementById('payment-form');
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div>Saving...';
                submitBtn.disabled = true;
                
                // Allow form to submit normally
            });
        }

        // Set payment date to today by default
        const paymentDateInput = document.querySelector('input[name="payment_date"]');
        if (paymentDateInput && !paymentDateInput.value) {
            paymentDateInput.value = new Date().toISOString().split('T')[0];
        }

        // Initialize contact dropdowns and generate OR number
        updateContactOptions();
        generateOrNumber();
    });

    // Payment functions
    function openPaymentModal(paymentId = null) {
        const modal = document.getElementById('payment-modal');
        const title = document.getElementById('payment-modal-title');
        const action = document.getElementById('payment-action');
        const paymentIdInput = document.getElementById('payment-id');
        const statusField = document.getElementById('status-field');
        
        if (paymentId) {
            title.textContent = 'Edit Payment';
            action.value = 'update_payment';
            paymentIdInput.value = paymentId;
            statusField.style.display = 'block';
            // In a real implementation, you would load the payment data here
        } else {
            title.textContent = 'Record Payment';
            action.value = 'add_payment';
            paymentIdInput.value = '';
            statusField.style.display = 'none';
            document.getElementById('payment-form').reset();
            updatePaymentNumber();
            generateOrNumber();
        }
        
        modal.style.display = 'block';
    }

    function updatePaymentNumber() {
        const type = document.getElementById('payment-type').value;
        const numberField = document.getElementById('payment-number');
        
        if (type) {
            // Generate a temporary payment number - in production this would come from the server
            const prefix = type === 'Receive' ? 'PMT' : 'V-PMT';
            const year = new Date().getFullYear();
            const timestamp = Date.now().toString().slice(-6);
            numberField.value = `${prefix}-${year}-${timestamp}`;
        } else {
            numberField.value = '';
        }
        
        // Update contact options based on type
        updateContactOptions(type);
    }

    // Generate OR Number function
    function generateOrNumber() {
        const orNumberField = document.getElementById('or-number');
        orNumberField.value = 'Generating...';
        
        fetch('?generate_or_number=1')
            .then(response => response.json())
            .then(data => {
                if (data.or_number) {
                    orNumberField.value = data.or_number;
                } else {
                    // Fallback if AJAX fails
                    const year = new Date().getFullYear();
                    const timestamp = Date.now().toString().slice(-5);
                    orNumberField.value = `OR-${year}-${timestamp}`;
                }
            })
            .catch(error => {
                console.error('Error generating OR number:', error);
                // Fallback if AJAX fails
                const year = new Date().getFullYear();
                const timestamp = Date.now().toString().slice(-5);
                orNumberField.value = `OR-${year}-${timestamp}`;
            });
    }

    function updateContactOptions(type = '') {
        const contactSelect = document.getElementById('contact-id');
        contactSelect.innerHTML = '<option value="">Select Contact</option>';
        
        // Use server-side data instead of hardcoded values
        <?php if (count($customers) > 0): ?>
        if (type === 'Receive') {
            <?php foreach ($customers as $customer): ?>
            contactSelect.innerHTML += '<option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>';
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($vendors) > 0): ?>
        if (type === 'Make') {
            <?php foreach ($vendors as $vendor): ?>
            contactSelect.innerHTML += '<option value="<?php echo $vendor['id']; ?>"><?php echo htmlspecialchars($vendor['name']); ?></option>';
            <?php endforeach; ?>
        }
        <?php endif; ?>
        
        <?php if (count($all_contacts) > 0): ?>
        if (!type) {
            <?php foreach ($all_contacts as $contact): ?>
            contactSelect.innerHTML += '<option value="<?php echo $contact['id']; ?>"><?php echo htmlspecialchars($contact['name'] . ' - ' . $contact['type']); ?></option>';
            <?php endforeach; ?>
        }
        <?php endif; ?>
    }

    function loadContactInvoices() {
        const contactId = document.getElementById('contact-id').value;
        const paymentType = document.getElementById('payment-type').value;
        const invoiceSelection = document.getElementById('invoice-selection');
        const invoiceOptions = document.getElementById('invoice-options');
        const noInvoiceCheckbox = document.getElementById('no-invoice');
        
        if (contactId) {
            invoiceSelection.style.display = 'block';
            invoiceOptions.innerHTML = '<div class="text-center py-4"><div class="spinner"></div>Loading invoices...</div>';
            
            // Make AJAX call to fetch real invoices for the contact
            fetch(`?get_contact_invoices=1&contact_id=${contactId}&type=${paymentType === 'Receive' ? 'Receivable' : 'Payable'}`)
                .then(response => response.json())
                .then(invoices => {
                    renderInvoiceOptions(invoices);
                })
                .catch(error => {
                    console.error('Error loading invoices:', error);
                    invoiceOptions.innerHTML = '<div class="text-center py-4 text-gray-500">Error loading invoices</div>';
                });
        } else {
            invoiceSelection.style.display = 'none';
        }
        
        noInvoiceCheckbox.addEventListener('change', function() {
            if (this.checked) {
                document.getElementById('selected-invoice-id').value = '';
                invoiceOptions.style.opacity = '0.5';
                document.querySelectorAll('.invoice-option').forEach(option => {
                    option.style.pointerEvents = 'none';
                });
            } else {
                invoiceOptions.style.opacity = '1';
                document.querySelectorAll('.invoice-option').forEach(option => {
                    option.style.pointerEvents = 'auto';
                });
            }
        });
    }

    function loadAndSelectInvoice(invoiceId) {
        // This function is kept for backward compatibility but simplified
        const contactId = document.getElementById('contact-id').value;
        const paymentType = document.getElementById('payment-type').value;
        
        fetch(`?get_contact_invoices=1&contact_id=${contactId}&type=${paymentType === 'Receive' ? 'Receivable' : 'Payable'}`)
            .then(response => response.json())
            .then(invoices => {
                renderInvoiceOptions(invoices);
                
                // Find and select the specific invoice
                const invoiceOption = document.querySelector(`.invoice-option[data-invoice-id="${invoiceId}"]`);
                if (invoiceOption) {
                    const invoice = invoices.find(inv => inv.id === invoiceId);
                    if (invoice) {
                        selectInvoice(invoiceOption, invoice.id, invoice.outstanding_balance);
                    }
                }
            })
            .catch(error => {
                console.error('Error loading invoices:', error);
            });
    }

    function renderInvoiceOptions(invoices) {
        const invoiceOptions = document.getElementById('invoice-options');
        
        if (invoices.length === 0) {
            invoiceOptions.innerHTML = '<div class="text-center py-4 text-gray-500">No outstanding invoices found for this contact.</div>';
            return;
        }
        
        let html = '';
        invoices.forEach(invoice => {
            html += `
                <div class="invoice-option" data-invoice-id="${invoice.id}" onclick="selectInvoice(this, ${invoice.id}, ${invoice.outstanding_balance})">
                    <div class="flex-1">
                        <div class="font-medium">${invoice.invoice_number}</div>
                        <div class="text-sm text-gray-600">
                            Amount: ${formatNumber(invoice.amount, <?php echo $_SESSION['show_numbers'] ? 'true' : 'false'; ?>)} | 
                            Outstanding: ${formatNumber(invoice.outstanding_balance, <?php echo $_SESSION['show_numbers'] ? 'true' : 'false'; ?>)} | 
                            Due: ${invoice.due_date}
                        </div>
                    </div>
                    <div class="flex items-center">
                        <input type="radio" name="invoice_radio" class="mr-2" id="invoice_${invoice.id}">
                    </div>
                </div>
            `;
        });
        
        invoiceOptions.innerHTML = html;
    }

    function selectInvoice(element, invoiceId, outstandingBalance) {
        document.getElementById('selected-invoice-id').value = invoiceId;
        document.getElementById('payment-amount').value = outstandingBalance;
        document.getElementById('payment-amount').max = outstandingBalance;
        
        // Update UI to show selected invoice
        document.querySelectorAll('.invoice-option').forEach(option => {
            option.classList.remove('selected');
        });
        element.classList.add('selected');
        element.querySelector('input[type="radio"]').checked = true;
    }

    function editPayment(paymentId) {
        openPaymentModal(paymentId);
        // In a real implementation, you would load the payment data via AJAX here
        alert('Edit functionality for payment: ' + paymentId + '\nThis would load payment data via AJAX.');
    }

    function viewPayment(paymentId) {
        alert('Viewing payment details for ID: ' + paymentId + '\nThis would open a detailed view with receipt information.');
    }

    function deletePayment(paymentId, paymentNumber) {
        if (confirm(`Are you sure you want to delete payment "${paymentNumber}"? This action cannot be undone.`)) {
            // Create a form and submit it to delete the payment
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_payment';
            form.appendChild(actionInput);
            
            const paymentIdInput = document.createElement('input');
            paymentIdInput.type = 'hidden';
            paymentIdInput.name = 'payment_id';
            paymentIdInput.value = paymentId;
            form.appendChild(paymentIdInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Format number function for JavaScript
    function formatNumber(number, showNumbers) {
        if (showNumbers) {
            return '₱' + parseFloat(number).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        } else {
            return '₱' + '*'.repeat(Math.max(6, Math.min(12, number.toString().length)));
        }
    }

	function viewInvoice(invoiceId) {
        if (invoiceId && invoiceId !== 'null' && invoiceId !== '') {
            // Redirect to invoices.php with the specific invoice ID
            window.location.href = `invoices.php?view_invoice=${invoiceId}`;
        } else {
            // If no invoice is linked, redirect to general invoices page
            window.location.href = 'invoices.php';
        }
    }

    function editPayment(paymentId) {
        // This function is now removed since we removed the edit button
        // Keeping it for backward compatibility but it won't be called
        console.log('Edit functionality removed');
    }

    function deletePayment(paymentId, paymentNumber) {
        if (confirm(`Are you sure you want to delete payment "${paymentNumber}"? This action cannot be undone.`)) {
            // Create a form and submit it to delete the payment
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_payment';
            form.appendChild(actionInput);
            
            const paymentIdInput = document.createElement('input');
            paymentIdInput.type = 'hidden';
            paymentIdInput.name = 'payment_id';
            paymentIdInput.value = paymentId;
            form.appendChild(paymentIdInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
            form.appendChild(csrfInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    </script>
</body>
</html>
