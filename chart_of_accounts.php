<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    header("Location: login.php");
    exit;
}

// Get notifications for the user - IMPROVED VERSION
function getNotifications(PDO $db, int $user_id): array {
    try {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? OR user_id IS NULL 
                ORDER BY created_at DESC 
                LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

// Mark notification as read - WITH CSRF VALIDATION
if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    exit;
}

// Mark all notifications as read - WITH CSRF VALIDATION
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$user_id]);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid CSRF token.";
    } else {
        if (isset($_POST['action'])) {
            try {
                switch ($_POST['action']) {
                    case 'add_account':
    $account_code = trim($_POST['account_code'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $balance = floatval($_POST['balance'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    
    // Validation
    if (empty($account_code) || empty($account_name) || empty($account_type)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $account_code)) {
        $error_message = "Account code can only contain uppercase letters, numbers, hyphens, and underscores.";
    } else {
        // Check for duplicate account code
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE account_code = ?");
        $check_stmt->execute([$account_code]);
        if ($check_stmt->fetchColumn() > 0) {
            $error_message = "Account code already exists. Please use a unique code.";
        } else {
            $stmt = $db->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, balance, status) 
                                 VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$account_code, $account_name, $account_type, $balance, $status]);
            
            $success_message = "Account added successfully!";
            
            // Create notification for new account - with separate error handling
            try {
                $notification_msg = "New account created: " . $account_name . " (" . $account_code . ")";
                $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Account Created', ?, 'success')");
                $notif_stmt->execute([$user_id, $notification_msg]);
            } catch (Exception $e) {
                // Log notification error but don't show to user
                error_log("Notification creation failed: " . $e->getMessage());
                // Continue without showing error - the main operation was successful
            }
        }
    }
    break;
                        
                    case 'edit_account':
    $account_id = (int)($_POST['account_id'] ?? 0);
    $account_code = trim($_POST['account_code'] ?? '');
    $account_name = trim($_POST['account_name'] ?? '');
    $account_type = $_POST['account_type'] ?? '';
    $balance = floatval($_POST['balance'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    
    // Validation
    if ($account_id <= 0) {
        $error_message = "Invalid account ID.";
    } elseif (empty($account_code) || empty($account_name) || empty($account_type)) {
        $error_message = "Please fill in all required fields.";
    } elseif (!preg_match('/^[A-Z0-9\-_]+$/', $account_code)) {
        $error_message = "Account code can only contain uppercase letters, numbers, hyphens, and underscores.";
    } else {
        // Check for duplicate account code (excluding current account)
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE account_code = ? AND id != ?");
        $check_stmt->execute([$account_code, $account_id]);
        if ($check_stmt->fetchColumn() > 0) {
            $error_message = "Account code already exists. Please use a unique code.";
        } else {
            $stmt = $db->prepare("UPDATE chart_of_accounts 
                                 SET account_code = ?, account_name = ?, account_type = ?, balance = ?, status = ? 
                                 WHERE id = ?");
            $stmt->execute([$account_code, $account_name, $account_type, $balance, $status, $account_id]);
            
            $success_message = "Account updated successfully!";
            
            // Create notification for account update - with separate error handling
            try {
                $notification_msg = "Account updated: " . $account_name . " (" . $account_code . ")";
                $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Updated', ?, 'info')");
                $notif_stmt->execute([$user_id, $notification_msg]);
            } catch (Exception $e) {
                error_log("Notification creation failed: " . $e->getMessage());
            }
        }
    }
    break;
                        
                    case 'delete_account':
    $account_id = (int)($_POST['account_id'] ?? 0);
    if ($account_id > 0) {
        // Get account details for notification
        $account_stmt = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = ?");
        $account_stmt->execute([$account_id]);
        $account = $account_stmt->fetch();
        
        // Check if account has transactions
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM journal_entry_lines WHERE account_id = ?");
        $check_stmt->execute([$account_id]);
        $transaction_count = $check_stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            $error_message = "Cannot delete account that has transactions. Deactivate it instead.";
        } else {
            $stmt = $db->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            
            $success_message = "Account deleted successfully!";
            
            // Create notification for account deletion - with separate error handling
            try {
                if ($account) {
                    $notification_msg = "Account deleted: " . $account['account_name'] . " (" . $account['account_code'] . ")";
                    $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Deleted', ?, 'warning')");
                    $notif_stmt->execute([$user_id, $notification_msg]);
                }
            } catch (Exception $e) {
                error_log("Notification creation failed: " . $e->getMessage());
            }
        }
    }
    break;
                        
                    case 'toggle_status':
                        $account_id = (int)($_POST['account_id'] ?? 0);
                        if ($account_id > 0) {
                            // Get current status and account details
                            $stmt = $db->prepare("SELECT status, account_code, account_name FROM chart_of_accounts WHERE id = ?");
                            $stmt->execute([$account_id]);
                            $account_data = $stmt->fetch();
                            $current_status = $account_data['status'];
                            
                            $new_status = $current_status === 'Active' ? 'Inactive' : 'Active';
                            
                            $stmt = $db->prepare("UPDATE chart_of_accounts SET status = ? WHERE id = ?");
                            $stmt->execute([$new_status, $account_id]);
                            
                            // Create notification for status change
                            $notification_msg = "Account " . $account_data['account_name'] . " (" . $account_data['account_code'] . ") status changed to " . $new_status;
                            $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Account Status Changed', ?, 'info')");
                            $notif_stmt->execute([$user_id, $notification_msg]);
                            
                            $success_message = "Account status updated successfully!";
                        }
                        break;
                        
                    case 'refresh_balances':
                        $db->beginTransaction();
                        
                        // Reset all balances to zero
                        $reset_stmt = $db->prepare("UPDATE chart_of_accounts SET balance = 0");
                        $reset_stmt->execute();
                        
                        // Calculate balances from journal entries
                        $balance_stmt = $db->prepare("
                            UPDATE chart_of_accounts coa
                            SET balance = (
                                SELECT COALESCE(SUM(debit - credit), 0)
                                FROM journal_entry_lines jel
                                JOIN journal_entries je ON jel.journal_entry_id = je.id
                                WHERE jel.account_id = coa.id AND je.status = 'Posted'
                            )
                        ");
                        $balance_stmt->execute();
                        
                        $db->commit();
                        
                        // Create notification for balance refresh
                        $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Balances Refreshed', 'All account balances have been updated from transactions', 'success')");
                        $notif_stmt->execute([$user_id]);
                        
                        $success_message = "Account balances refreshed successfully!";
                        break;
                }
            } catch (PDOException $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Database error: " . $e->getMessage());
                $error_message = "A database error occurred. Please try again.";
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("General error: " . $e->getMessage());
                $error_message = "An error occurred. Please try again.";
            }
        }
    }
}

// Handle export requests
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=chart_of_accounts_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Chart of Accounts - Generated on ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    fputcsv($output, ['Account Code', 'Account Name', 'Account Type', 'Balance', 'Status']);
    
    // Data rows
    $accounts = getChartOfAccounts($db);
    foreach ($accounts as $account) {
        fputcsv($output, [
            $account['account_code'],
            $account['account_name'],
            $account['account_type'],
            number_format((float)$account['balance'], 2),
            $account['status']
        ]);
    }
    
    fclose($output);
    exit;
}

// Get chart of accounts data with transaction counts
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT coa.*, 
                   COUNT(jel.id) as transaction_count
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            GROUP BY coa.id
            ORDER BY coa.account_type, coa.account_code";
    return $db->query($sql)->fetchAll();
}

$chart_of_accounts = getChartOfAccounts($db);

// Get account type totals
function getAccountTypeTotals(PDO $db): array {
    $sql = "SELECT account_type, 
                   COUNT(*) as count,
                   COALESCE(SUM(balance), 0) as total_balance
            FROM chart_of_accounts 
            WHERE status = 'Active'
            GROUP BY account_type 
            ORDER BY account_type";
    return $db->query($sql)->fetchAll();
}

$account_totals = getAccountTypeTotals($db);

// Get account details for editing
$edit_account = null;
if (isset($_GET['edit_account']) && is_numeric($_GET['edit_account'])) {
    $account_id = (int)$_GET['edit_account'];
    $stmt = $db->prepare("SELECT * FROM chart_of_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $edit_account = $stmt->fetch();
}

// Get notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, function($notification) {
    return !$notification['is_read'];
});
$unread_count = count($unread_notifications);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Include jsPDF and html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
        /* All previous CSS styles remain the same */
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
        
        .action-btn.delete {
            background-color: #FEF2F2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.delete:hover {
            background-color: #DC2626;
            color: white;
        }
        
        .action-btn.delete:disabled {
            background-color: #F3F4F6;
            color: #9CA3AF;
            border-color: #D1D5DB;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .action-btn.deactivate {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }
        
        .action-btn.deactivate:hover {
            background-color: #D97706;
            color: white;
        }
        
        .action-btn.activate {
            background-color: #D1FAE5;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.activate:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.edit {
            background-color: #E0F2FE;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.edit:hover {
            background-color: #0369A1;
            color: white;
        }

        /* Account type specific colors */
        .account-type-asset {
            border-left: 4px solid #3B82F6;
        }
        
        .account-type-liability {
            border-left: 4px solid #EF4444;
        }
        
        .account-type-equity {
            border-left: 4px solid #8B5CF6;
        }
        
        .account-type-revenue {
            border-left: 4px solid #10B981;
        }
        
        .account-type-expense {
            border-left: 4px solid #F59E0B;
        }

        /* Alert styles */
        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: #D1FAE5;
            color: #047857;
            border: 1px solid #A7F3D0;
        }
        
        .alert-error {
            background-color: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }

        /* Print styles - FIXED VERSION */
@media print {
    body * {
        visibility: hidden;
    }
    
    #main-content, 
    #main-content * {
        visibility: visible;
    }
    
    #main-content {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    .no-print,
    #sidebar,
    #hamburger-btn,
    .bg-primary-green, 
    .main-footer,
    .grid.grid-cols-1.md\\:grid-cols-2.gap-6.mt-8, /* Quick Actions section */
    button,
    .action-btn,
    .flex.justify-between.items-center.mb-6 .btn, /* Add Account button */
    .notification-container,
    #profile-btn {
        display: none !important;
    }
    
    /* Ensure tables print properly */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    th, td {
        border: 1px solid #000 !important;
        padding: 8px !important;
    }
    
    th {
        background-color: #f0f0f0 !important;
        color: #000 !important;
    }
    
    /* Show all amounts when printing */
    .amount-value {
        filter: none !important;
        visibility: visible !important;
    }
    
    .hidden-amount {
        display: none !important;
    }
    
    /* Ensure proper text colors for printing */
    * {
        color: #000 !important;
        background-color: #fff !important;
    }
}

        /* Validation styles */
        .validation-error {
            color: #EF4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        .form-input.error {
            border-color: #EF4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }

        .required-field::after {
            content: " *";
            color: #EF4444;
        }
        
        /* Tooltip styles */
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: #374151;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
            font-weight: normal;
        }
        
        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
        
        .transaction-badge {
            background-color: #E5E7EB;
            color: #6B7280;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.375rem;
            margin-left: 0.5rem;
        }

        /* Notification styles */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #EF4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            z-index: 100;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f9fafb;
        }
        
        .notification-item.unread {
            background-color: #f0f9ff;
            border-left: 3px solid #3B82F6;
        }
        
        .notification-item.read {
            opacity: 0.7;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        /* Balance hide/show styles */
        .amount-cell {
            display: flex;
            align-items: center;
        }

        .amount-value {
            transition: all 0.3s ease;
        }

        .hidden-amount {
            letter-spacing: 2px;
            font-family: monospace;
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

        .balance-hidden {
            filter: blur(4px);
            user-select: none;
        }
        
        .balance-toggle {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }
        
        .balance-toggle:hover {
            background-color: #f3f4f6;
        }
        
        .bg-white.rounded-xl.p-6.card-shadow {
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .bg-white.rounded-xl.p-6.card-shadow:hover {
        transform: translateY(-5px);
        box-shadow: 0px 8px 25px rgba(0,0,0,0.15);
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
    
    <!-- Modal for Add Account -->
    <div id="add-account-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Add New Account</h2>
            <form id="account-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_account">
                <div class="form-group">
                    <label class="form-label required-field">Account Code</label>
                    <input type="text" name="account_code" class="form-input" placeholder="e.g., 1001" required 
                           pattern="[A-Z0-9\-_]+" title="Uppercase letters, numbers, hyphens, and underscores only">
                    <div class="validation-error" id="account-code-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label required-field">Account Name</label>
                    <input type="text" name="account_name" class="form-input" placeholder="e.g., Cash on Hand" required>
                </div>
                <div class="form-group">
                    <label class="form-label required-field">Account Type</label>
                    <select name="account_type" class="form-input" required>
                        <option value="">Select Account Type</option>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                        <option value="Revenue">Revenue</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Opening Balance</label>
                    <input type="number" name="balance" class="form-input" placeholder="0.00" step="0.01" value="0.00" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-input" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Add Account</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal for Edit Account -->
    <div id="edit-account-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Edit Account</h2>
            <form id="edit-account-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="account_id" id="edit_account_id" value="">
                <div class="form-group">
                    <label class="form-label required-field">Account Code</label>
                    <input type="text" name="account_code" id="edit_account_code" class="form-input" placeholder="e.g., 1001" required 
                           pattern="[A-Z0-9\-_]+" title="Uppercase letters, numbers, hyphens, and underscores only">
                    <div class="validation-error" id="edit-account-code-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label required-field">Account Name</label>
                    <input type="text" name="account_name" id="edit_account_name" class="form-input" placeholder="e.g., Cash on Hand" required>
                </div>
                <div class="form-group">
                    <label class="form-label required-field">Account Type</label>
                    <select name="account_type" id="edit_account_type" class="form-input" required>
                        <option value="">Select Account Type</option>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                        <option value="Revenue">Revenue</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Balance</label>
                    <input type="number" name="balance" id="edit_balance" class="form-input" placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_status" class="form-input" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Update Account</button>
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
                <a href="disbursement_request.php" class="submenu-item transition-colors duration-200">Disbursement History</a>
                <a href="pending_disbursements.php" class="submenu-item transition-colors duration-200">Pending Disbursements</a>
                
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
                <a href="chart_of_accounts.php" class="submenu-item active transition-colors duration-200">Chart of Accounts</a>
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
                        <h1 class="text-2xl font-bold text-white">Chart of Accounts</h1>
                        <p class="text-sm text-white/90">Manage your accounting chart of accounts</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <!-- Balance Toggle Button - UPDATED WITH REFERENCE CODE -->
                    <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                        <i class="fa-solid fa-eye-slash text-xl text-white"></i>
                    </button>
                    
                    <!-- Notification Bell -->
                    <div class="relative" id="notification-container">
                        <button id="notification-btn" class="relative p-2 transition duration-200 focus:outline-none">
                            <i class="fa-solid fa-bell text-xl text-white"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Notification Dropdown -->
                        <div id="notification-dropdown" class="notification-dropdown">
                            <div class="p-4 border-b border-gray-200">
                                <div class="flex justify-between items-center">
                                    <h3 class="font-bold text-gray-800">Notifications</h3>
                                    <?php if ($unread_count > 0): ?>
                                        <button id="mark-all-read" class="text-sm text-blue-600 hover:text-blue-800">Mark all as read</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="notification-list">
                                <?php if (empty($notifications)): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        No notifications
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <div class="notification-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>" data-id="<?php echo $notification['id']; ?>">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <h4 class="font-medium text-gray-800"><?php echo htmlspecialchars($notification['title']); ?></h4>
                                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                    <div class="notification-time">
                                                        <?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?>
                                                    </div>
                                                </div>
                                                <?php if (!$notification['is_read']): ?>
                                                    <div class="w-2 h-2 bg-blue-500 rounded-full ml-2 mt-2"></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Button -->
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success mb-6">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error mb-6">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Account Type Summary - UPDATED WITH BALANCE TOGGLE -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                    <?php foreach ($account_totals as $type): ?>
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="text-center">
                            <div class="amount-cell justify-center mb-2">
                                <span class="amount-value hidden-amount text-2xl font-bold text-dark-text" 
                                      data-value="₱<?php echo number_format((float)$type['total_balance'], 2); ?>">
                                    ********
                                </span>
                            </div>
                            <div class="text-sm text-gray-500 font-medium"><?php echo htmlspecialchars($type['account_type']); ?></div>
                            <div class="text-xs text-gray-400 mt-1"><?php echo $type['count']; ?> accounts</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            <!-- Search Section - ADDED -->
<div class="bg-white rounded-xl p-6 card-shadow mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex-1">
            <div class="relative">
                <input type="text" id="search-accounts" placeholder="Search accounts by code, name, or type..." 
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
                <select id="filter-type" class="form-input pr-8">
                    <option value="">All Types</option>
                    <option value="Asset">Asset</option>
                    <option value="Liability">Liability</option>
                    <option value="Equity">Equity</option>
                    <option value="Revenue">Revenue</option>
                    <option value="Expense">Expense</option>
                </select>
            </div>
            <div class="relative">
                <select id="filter-status" class="form-input pr-8">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div id="search-results-info" class="mt-3 text-sm text-gray-600 hidden">
        <span id="results-count">0</span> accounts found
    </div>
</div>

                <!-- Chart of Accounts Content - UPDATED WITH BALANCE TOGGLE -->
                <div class="bg-white rounded-xl p-6 card-shadow">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-dark-text">Chart of Accounts</h2>
                        <div class="flex space-x-3">
                            <button class="btn btn-primary" onclick="document.getElementById('add-account-modal').style.display='block'">
                                <i class='bx bx-plus mr-2'></i>Add Account
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th>Account Type</th>
                                    <th>
                                        <div class="flex items-center space-x-2">
                                            <span>Balance</span>
                                        </div>
                                    </th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($chart_of_accounts) > 0): ?>
                                    <?php 
                                    $current_type = '';
                                    foreach ($chart_of_accounts as $account): 
                                        if ($current_type !== $account['account_type']) {
                                            $current_type = $account['account_type'];
                                    ?>
                                    <tr class="bg-gray-50">
                                        <td colspan="6" class="font-semibold text-gray-700 py-3">
                                            <?php echo htmlspecialchars($account['account_type']); ?> ACCOUNTS
                                        </td>
                                    </tr>
                                    <?php } ?>
                                    <tr class="account-type-<?php echo strtolower($account['account_type']); ?>">
                                        <td class="font-mono font-medium">
                                            <?php echo htmlspecialchars($account['account_code']); ?>
                                            <?php if ($account['transaction_count'] > 0): ?>
                                                <span class="transaction-badge" title="This account has transactions">
                                                    <?php echo $account['transaction_count']; ?> trans
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                        <td>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?php echo $account['account_type'] === 'Asset' ? 'bg-blue-100 text-blue-800' : 
                                                       ($account['account_type'] === 'Liability' ? 'bg-red-100 text-red-800' : 
                                                       ($account['account_type'] === 'Equity' ? 'bg-purple-100 text-purple-800' : 
                                                       ($account['account_type'] === 'Revenue' ? 'bg-green-100 text-green-800' : 
                                                       'bg-yellow-100 text-yellow-800'))); ?>">
                                                <?php echo htmlspecialchars($account['account_type']); ?>
                                            </span>
                                        </td>
                                        <td class="<?php echo (float)$account['balance'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount font-semibold" 
                                                      data-value="₱<?php echo number_format((float)$account['balance'], 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $account['status'] === 'Active' ? 'status-approved' : 'status-rejected'; ?>">
                                                <?php echo htmlspecialchars($account['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap gap-2">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                                                    <?php if ($account['status'] === 'Active'): ?>
                                                    <button type="submit" class="action-btn deactivate" title="Deactivate Account">
                                                        <i class='bx bx-power-off mr-1'></i>Deactivate
                                                    </button>
                                                    <?php else: ?>
                                                    <button type="submit" class="action-btn activate" title="Activate Account">
                                                        <i class='bx bx-check mr-1'></i>Activate
                                                    </button>
                                                    <?php endif; ?>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-gray-500">
                                            No accounts found in the chart of accounts.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
    <div class="bg-white rounded-xl p-6 card-shadow text-center cursor-pointer hover:bg-gray-50 transition-colors duration-200" id="export-chart-btn">
        <i class='bx bx-export text-3xl text-primary-green mb-3'></i>
        <h3 class="font-medium mb-2">Export Chart</h3>
        <p class="text-sm text-gray-500">Export accounts to Excel or PDF</p>
    </div>
    <div class="bg-white rounded-xl p-6 card-shadow text-center cursor-pointer hover:bg-gray-50 transition-colors duration-200" id="print-report-btn">
        <i class='bx bx-printer text-3xl text-primary-green mb-3'></i>
        <h3 class="font-medium mb-2">Print Report</h3>
        <p class="text-sm text-gray-500">Print chart of accounts</p>
    </div>
</div>

</div> <footer class="main-footer">
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
            // Balance visibility toggle functionality - CORRECTED VERSION
            let amountsVisible = false;

            // Global toggle function
            document.getElementById('visibility-toggle').addEventListener('click', function() {
                const globalIcon = this.querySelector('i');
                
                amountsVisible = !amountsVisible;
                
                const amountSpans = document.querySelectorAll('.amount-value');
                amountSpans.forEach(span => {
                    if (amountsVisible) {
                        // Show amount
                        const actualAmount = span.getAttribute('data-value');
                        span.textContent = actualAmount;
                        span.classList.remove('hidden-amount');
                    } else {
                        // Hide amount
                        span.textContent = '********';
                        span.classList.add('hidden-amount');
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

            // Initialize all amounts as hidden
            const amountSpans = document.querySelectorAll('.amount-value');
            amountSpans.forEach(span => {
                span.textContent = '********';
                span.classList.add('hidden-amount');
            });

            // Enhanced form validation
            function validateAccountForm(form) {
                let isValid = true;
                
                // Clear previous errors
                const errorElements = form.querySelectorAll('.validation-error');
                errorElements.forEach(el => el.textContent = '');
                
                const inputs = form.querySelectorAll('input, select');
                inputs.forEach(input => {
                    input.classList.remove('error');
                });
                
                // Validate account code
                const accountCode = form.querySelector('input[name="account_code"]');
                if (accountCode) {
                    const codeValue = accountCode.value.trim();
                    if (!codeValue) {
                        showError(accountCode, 'Account code is required');
                        isValid = false;
                    } else if (!/^[A-Z0-9\-_]+$/.test(codeValue)) {
                        showError(accountCode, 'Only uppercase letters, numbers, hyphens, and underscores allowed');
                        isValid = false;
                    }
                }
                
                // Validate account name
                const accountName = form.querySelector('input[name="account_name"]');
                if (accountName && !accountName.value.trim()) {
                    showError(accountName, 'Account name is required');
                    isValid = false;
                }
                
                // Validate account type
                const accountType = form.querySelector('select[name="account_type"]');
                if (accountType && !accountType.value) {
                    showError(accountType, 'Account type is required');
                    isValid = false;
                }
                
                return isValid;
            }

            function showError(input, message) {
                input.classList.add('error');
                let errorDiv = input.parentNode.querySelector('.validation-error');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'validation-error';
                    input.parentNode.appendChild(errorDiv);
                }
                errorDiv.textContent = message;
            }

            // Add form validation to account forms
            const accountForm = document.getElementById('account-form');
            const editAccountForm = document.getElementById('edit-account-form');
            
            if (accountForm) {
                accountForm.addEventListener('submit', function(e) {
                    if (!validateAccountForm(this)) {
                        e.preventDefault();
                    }
                });
            }
            
            if (editAccountForm) {
                editAccountForm.addEventListener('submit', function(e) {
                    if (!validateAccountForm(this)) {
                        e.preventDefault();
                    }
                });
            }

            // Notification functionality - CORRECTED VERSION
            const notificationBtn = document.getElementById('notification-btn');
            const notificationDropdown = document.getElementById('notification-dropdown');
            const notificationItems = document.querySelectorAll('.notification-item');
            const markAllReadBtn = document.getElementById('mark-all-read');

            // Toggle notification dropdown
            if (notificationBtn && notificationDropdown) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isVisible = notificationDropdown.style.display === 'block';
                    notificationDropdown.style.display = isVisible ? 'none' : 'block';
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (notificationDropdown && !notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                    notificationDropdown.style.display = 'none';
                }
            });

            // Prevent dropdown from closing when clicking inside it
            if (notificationDropdown) {
                notificationDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            // Mark notification as read when clicked
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    if (!this.classList.contains('read')) {
                        // Add loading state
                        const originalContent = this.innerHTML;
                        this.innerHTML = '<div class="spinner"></div>Loading...';
                        
                        // Mark as read via AJAX
                        const formData = new FormData();
                        formData.append('action', 'mark_notification_read');
                        formData.append('notification_id', notificationId);
                        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                        
                        fetch('', {
                            method: 'POST',
                            body: formData
                        }).then(response => {
                            if (response.ok) {
                                this.classList.remove('unread');
                                this.classList.add('read');
                                this.querySelector('.bg-blue-500')?.remove();
                                
                                // Restore content
                                this.innerHTML = originalContent;
                                
                                // Update notification count
                                updateNotificationCount();
                            }
                        }).catch(error => {
                            console.error('Error marking notification as read:', error);
                            this.innerHTML = originalContent;
                        });
                    }
                });
            });

            // Mark all as read
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    // Add loading state
                    const originalText = this.textContent;
                    this.innerHTML = '<div class="spinner"></div>Processing...';
                    this.disabled = true;
                    
                    const formData = new FormData();
                    formData.append('action', 'mark_all_read');
                    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => {
                        if (response.ok) {
                            // Update all notifications to read
                            notificationItems.forEach(item => {
                                item.classList.remove('unread');
                                item.classList.add('read');
                                item.querySelector('.bg-blue-500')?.remove();
                            });
                            
                            // Update notification count
                            updateNotificationCount();
                            
                            // Hide mark all button
                            this.style.display = 'none';
                            
                            // Show success message briefly
                            const originalDisplay = this.style.display;
                            this.textContent = 'Marked all as read!';
                            this.disabled = false;
                            
                            setTimeout(() => {
                                this.style.display = 'none';
                            }, 2000);
                        }
                    }).catch(error => {
                        console.error('Error marking all as read:', error);
                        this.textContent = originalText;
                        this.disabled = false;
                    });
                });
            }

            function updateNotificationCount() {
                const unreadItems = document.querySelectorAll('.notification-item.unread');
                const notificationBadge = document.querySelector('.notification-badge');
                
                if (unreadItems.length === 0) {
                    if (notificationBadge) {
                        notificationBadge.remove();
                    }
                    // Hide mark all button if it exists
                    if (markAllReadBtn) {
                        markAllReadBtn.style.display = 'none';
                    }
                } else {
                    if (!notificationBadge) {
                        // Create badge if it doesn't exist
                        const badge = document.createElement('span');
                        badge.className = 'notification-badge';
                        notificationBtn.appendChild(badge);
                    }
                    document.querySelector('.notification-badge').textContent = unreadItems.length;
                    
                    // Show mark all button
                    if (markAllReadBtn) {
                        markAllReadBtn.style.display = 'block';
                    }
                }
            }

            // Close notification dropdown when pressing Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && notificationDropdown) {
                    notificationDropdown.style.display = 'none';
                }
            });

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
            const addAccountModal = document.getElementById('add-account-modal');
            const editAccountModal = document.getElementById('edit-account-modal');
            const closeButtons = document.querySelectorAll('.close-modal');
            
            // Profile button click
            if (profileBtn && profileModal) {
                profileBtn.addEventListener('click', function() {
                    profileModal.style.display = 'block';
                });
            }
            
            // Close buttons functionality
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    profileModal.style.display = 'none';
                    addAccountModal.style.display = 'none';
                    editAccountModal.style.display = 'none';
                });
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === profileModal) {
                    profileModal.style.display = 'none';
                }
                if (event.target === addAccountModal) {
                    addAccountModal.style.display = 'none';
                }
                if (event.target === editAccountModal) {
                    editAccountModal.style.display = 'none';
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

            // Export Chart functionality
            const exportChartBtn = document.getElementById('export-chart-btn');
            if (exportChartBtn) {
                exportChartBtn.addEventListener('click', function() {
                    // Show export options
                    if (confirm('Export chart of accounts as CSV?')) {
                        window.location.href = '?export=csv';
                    }
                });
            }

            // Print Report functionality - ENHANCED VERSION
const printReportBtn = document.getElementById('print-report-btn');
if (printReportBtn) {
    printReportBtn.addEventListener('click', function() {
        // Store original states
        const originalTitle = document.title;
        
        // Update page title for print
        document.title = 'Chart of Accounts Report - ' + new Date().toLocaleDateString();
        
        // Show all balances before printing
        const amountSpans = document.querySelectorAll('.amount-value');
        amountSpans.forEach(span => {
            const actualAmount = span.getAttribute('data-value');
            span.textContent = actualAmount;
            span.classList.remove('hidden-amount');
        });
        
        // Add print-specific styling
        const style = document.createElement('style');
        style.innerHTML = `
            @media print {
                body { margin: 0; padding: 20px; }
                .no-print, #sidebar, #hamburger-btn, .bg-primary-green, 
                .main-footer, button, .action-btn, .notification-container, 
                #profile-btn, .grid.grid-cols-1.md\\\\:grid-cols-2.gap-6.mt-8 {
                    display: none !important;
                }
                #main-content {
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                table { 
                    width: 100% !important; 
                    border-collapse: collapse !important;
                    font-size: 12px !important;
                }
                th, td { 
                    border: 1px solid #000 !important; 
                    padding: 6px !important;
                }
                th { 
                    background-color: #f5f5f5 !important; 
                    font-weight: bold !important;
                }
                .card-shadow { box-shadow: none !important; }
            }
        `;
        document.head.appendChild(style);
        
        // Trigger print
        window.print();
        
        // Restore original states after print
        setTimeout(() => {
            document.title = originalTitle;
            style.remove();
            
            // Restore balance visibility state
            const isVisible = document.querySelector('#visibility-toggle i').classList.contains('fa-eye');
            if (!isVisible) {
                amountSpans.forEach(span => {
                    span.textContent = '********';
                    span.classList.add('hidden-amount');
                });
            }
        }, 100);
    });
}

            // PDF Export Function
            function exportToPDF() {
                const { jsPDF } = window.jspdf;
                
                html2canvas(document.querySelector('.bg-white.rounded-xl.p-6.card-shadow')).then(canvas => {
                    const imgData = canvas.toDataURL('image/png');
                    const pdf = new jsPDF('p', 'mm', 'a4');
                    const imgWidth = 210;
                    const pageHeight = 295;
                    const imgHeight = canvas.height * imgWidth / canvas.width;
                    let heightLeft = imgHeight;
                    let position = 0;

                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;

                    while (heightLeft >= 0) {
                        position = heightLeft - imgHeight;
                        pdf.addPage();
                        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                        heightLeft -= pageHeight;
                    }

                    pdf.save('chart_of_accounts_<?php echo date('Y-m-d'); ?>.pdf');
                });
            }

            // Highlight current page in sidebar
            const currentPage = window.location.pathname.split('/').pop();
            const menuItems = document.querySelectorAll('.submenu-item');
            
            menuItems.forEach(item => {
                if (item.getAttribute('href') === currentPage) {
                    item.classList.add('active');
                    // Also open the parent submenu
                    const category = item.closest('.submenu').id.replace('-submenu', '');
                    const submenu = document.getElementById(`${category}-submenu`);
                    const arrow = document.querySelector(`.category-arrow[data-category="${category}"]`);
                    
                    if (submenu && arrow) {
                        submenu.classList.add('active');
                        arrow.classList.add('rotate-180');
                    }
                }
            });
        });

        // Function to open edit modal with account data
        function openEditModal(id, code, name, type, balance, status) {
            document.getElementById('edit_account_id').value = id;
            document.getElementById('edit_account_code').value = code;
            document.getElementById('edit_account_name').value = name;
            document.getElementById('edit_account_type').value = type;
            document.getElementById('edit_balance').value = balance;
            document.getElementById('edit_status').value = status;
            
            document.getElementById('edit-account-modal').style.display = 'block';
        }
        
        // Search functionality - ADDED
function initializeSearch() {
    const searchInput = document.getElementById('search-accounts');
    const clearSearchBtn = document.getElementById('clear-search');
    const filterType = document.getElementById('filter-type');
    const filterStatus = document.getElementById('filter-status');
    const searchResultsInfo = document.getElementById('search-results-info');
    const resultsCount = document.getElementById('results-count');
    
    if (!searchInput) return;
    
    function performSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const selectedType = filterType.value;
        const selectedStatus = filterStatus.value;
        
        const tableBody = document.querySelector('.data-table tbody');
        const rows = tableBody.querySelectorAll('tr');
        let visibleRows = 0;
        let visibleAccounts = 0;
        
        rows.forEach(row => {
            // Skip account type header rows
            if (row.classList.contains('bg-gray-50')) {
                let shouldShowHeader = false;
                const nextRows = [];
                let nextRow = row.nextElementSibling;
                
                // Check if any account in this category matches the filters
                while (nextRow && !nextRow.classList.contains('bg-gray-50')) {
                    if (filterRow(nextRow, searchTerm, selectedType, selectedStatus)) {
                        shouldShowHeader = true;
                    }
                    nextRows.push(nextRow);
                    nextRow = nextRow.nextElementSibling;
                }
                
                // Show/hide header based on whether any accounts in category are visible
                row.style.display = shouldShowHeader ? '' : 'none';
                
                // Show/hide account rows in this category
                nextRows.forEach(accRow => {
                    const isVisible = filterRow(accRow, searchTerm, selectedType, selectedStatus);
                    accRow.style.display = isVisible ? '' : 'none';
                    if (isVisible) {
                        visibleRows++;
                        visibleAccounts++;
                    }
                });
                
                return;
            }
            
            // Handle regular account rows (though they should be handled above)
            if (!row.classList.contains('bg-gray-50')) {
                const isVisible = filterRow(row, searchTerm, selectedType, selectedStatus);
                row.style.display = isVisible ? '' : 'none';
                if (isVisible) {
                    visibleRows++;
                    visibleAccounts++;
                }
            }
        });
        
        // Update results info
        if (searchTerm || selectedType || selectedStatus) {
            searchResultsInfo.classList.remove('hidden');
            resultsCount.textContent = visibleAccounts;
        } else {
            searchResultsInfo.classList.add('hidden');
        }
    }
    
    function filterRow(row, searchTerm, selectedType, selectedStatus) {
        const cells = row.querySelectorAll('td');
        if (cells.length < 5) return false;
        
        const accountCode = cells[0].textContent.toLowerCase();
        const accountName = cells[1].textContent.toLowerCase();
        const accountType = cells[2].textContent.toLowerCase();
        const status = cells[4].textContent.toLowerCase();
        
        // Text search
        const matchesSearch = !searchTerm || 
                             accountCode.includes(searchTerm) || 
                             accountName.includes(searchTerm) || 
                             accountType.includes(searchTerm);
        
        // Type filter
        const matchesType = !selectedType || 
                           cells[2].textContent === selectedType;
        
        // Status filter
        const matchesStatus = !selectedStatus || 
                             cells[4].textContent === selectedStatus;
        
        return matchesSearch && matchesType && matchesStatus;
    }
    
    // Event listeners
    searchInput.addEventListener('input', performSearch);
    filterType.addEventListener('change', performSearch);
    filterStatus.addEventListener('change', performSearch);
    
    clearSearchBtn.addEventListener('click', function() {
        searchInput.value = '';
        filterType.value = '';
        filterStatus.value = '';
        performSearch();
        searchInput.focus();
    });
    
    // Add keyboard shortcut
    searchInput.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            this.focus();
        }
    });
    
    // Add global keyboard shortcut
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
    });
}

// Initialize search when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // ... existing DOMContentLoaded code ...
    
    // Initialize search functionality
    initializeSearch();
});
        
    </script>
</body>
</html>