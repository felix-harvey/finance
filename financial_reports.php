<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add session variable to track number visibility
if (!isset($_SESSION['show_numbers'])) {
    $_SESSION['show_numbers'] = false; // Default to hidden
}

// Toggle number visibility
if (isset($_GET['toggle_numbers'])) {
    $_SESSION['show_numbers'] = !$_SESSION['show_numbers'];
    
    // Remove the toggle_numbers parameter and redirect
    $url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = [];
    parse_str($_SERVER['QUERY_STRING'] ?? '', $params);
    unset($params['toggle_numbers']);
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    header("Location: " . $url);
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

// Get notifications for the user
function getNotifications(PDO $db, int $user_id): array {
    $sql = "SELECT * FROM notifications 
            WHERE user_id = ? OR user_id IS NULL 
            ORDER BY created_at DESC 
            LIMIT 10";
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
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

// Get financial reports data - FIXED VERSION
function getIncomeStatement(PDO $db, string $start_date, string $end_date): array {
    $sql = "SELECT 
                coa.account_type,
                coa.account_name,
                coa.account_code,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Revenue', 'Operating Revenue', 'Other Revenue') THEN jel.credit - jel.debit
                        WHEN coa.account_type IN ('Expense', 'Operating Expense', 'Cost of Goods Sold', 'Other Expense') THEN jel.debit - jel.credit
                        ELSE 0 
                    END
                ), 0) as amount,
                COUNT(DISTINCT je.id) as transaction_count
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id 
                AND je.status = 'Posted'
                AND je.entry_date BETWEEN ? AND ?
            WHERE coa.status = 'Active'
            GROUP BY coa.id, coa.account_type, coa.account_name, coa.account_code
            HAVING amount != 0
            ORDER BY 
                CASE coa.account_type 
                    WHEN 'Revenue' THEN 1
                    WHEN 'Operating Revenue' THEN 1
                    WHEN 'Other Revenue' THEN 1
                    WHEN 'Expense' THEN 2
                    WHEN 'Operating Expense' THEN 2
                    WHEN 'Cost of Goods Sold' THEN 2
                    WHEN 'Other Expense' THEN 2
                    ELSE 3
                END,
                coa.account_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll();
}

function getBalanceSheet(PDO $db, string $as_of_date): array {
    $sql = "SELECT 
                coa.account_type,
                coa.account_name,
                coa.account_code,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Asset', 'Current Asset', 'Fixed Asset', 'Other Asset') THEN jel.debit - jel.credit
                        WHEN coa.account_type IN ('Liability', 'Current Liability', 'Long-term Liability') THEN jel.credit - jel.debit
                        WHEN coa.account_type IN ('Equity', 'Retained Earnings') THEN jel.credit - jel.debit
                        ELSE 0 
                    END
                ), 0) as balance
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id 
                AND je.status = 'Posted'
                AND je.entry_date <= ?
            WHERE coa.status = 'Active'
            GROUP BY coa.id, coa.account_type, coa.account_name, coa.account_code
            HAVING balance != 0
            ORDER BY 
                CASE coa.account_type 
                    WHEN 'Asset' THEN 1
                    WHEN 'Current Asset' THEN 1
                    WHEN 'Fixed Asset' THEN 1
                    WHEN 'Other Asset' THEN 1
                    WHEN 'Liability' THEN 2
                    WHEN 'Current Liability' THEN 2
                    WHEN 'Long-term Liability' THEN 2
                    WHEN 'Equity' THEN 3
                    WHEN 'Retained Earnings' THEN 3
                    ELSE 4
                END, 
                coa.account_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$as_of_date]);
    return $stmt->fetchAll();
}

function getCashFlowStatement(PDO $db, string $start_date, string $end_date): array {
    // Operating Activities
    $sql = "SELECT 
                'Operating' as category,
                'Net Income' as description,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Revenue', 'Operating Revenue', 'Other Revenue') THEN jel.credit - jel.debit
                        WHEN coa.account_type IN ('Expense', 'Operating Expense', 'Cost of Goods Sold', 'Other Expense') THEN jel.debit - jel.credit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id 
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'
                AND je.entry_date BETWEEN ? AND ?
                AND coa.account_type IN ('Revenue', 'Operating Revenue', 'Other Revenue', 'Expense', 'Operating Expense', 'Cost of Goods Sold', 'Other Expense')
            
            UNION ALL
            
            -- Investing Activities
            SELECT 
                'Investing' as category,
                'Purchase of Fixed Assets' as description,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Fixed Asset', 'Other Asset') THEN jel.debit - jel.credit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id 
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'
                AND je.entry_date BETWEEN ? AND ?
                AND coa.account_type IN ('Fixed Asset', 'Other Asset')
            
            UNION ALL
            
            -- Financing Activities
            SELECT 
                'Financing' as category,
                'Loans Received' as description,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Liability', 'Current Liability', 'Long-term Liability') THEN jel.credit - jel.debit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id 
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'
                AND je.entry_date BETWEEN ? AND ?
                AND coa.account_type IN ('Liability', 'Current Liability', 'Long-term Liability')
            
            UNION ALL
            
            SELECT 
                'Financing' as category,
                'Equity Investments' as description,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Equity', 'Retained Earnings') THEN jel.credit - jel.debit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM journal_entry_lines jel
            JOIN journal_entries je ON jel.journal_entry_id = je.id 
            JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE je.status = 'Posted'
                AND je.entry_date BETWEEN ? AND ?
                AND coa.account_type IN ('Equity', 'Retained Earnings')";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $start_date, $end_date,
        $start_date, $end_date,
        $start_date, $end_date,
        $start_date, $end_date
    ]);
    return $stmt->fetchAll();
}

function getTrialBalance(PDO $db, string $as_of_date): array {
    $sql = "SELECT 
                coa.account_code,
                coa.account_name,
                coa.account_type,
                COALESCE(SUM(jel.debit), 0) as total_debit,
                COALESCE(SUM(jel.credit), 0) as total_credit,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type IN ('Asset', 'Current Asset', 'Fixed Asset', 'Other Asset', 'Expense', 'Operating Expense', 'Cost of Goods Sold', 'Other Expense') 
                            THEN jel.debit - jel.credit
                        WHEN coa.account_type IN ('Liability', 'Current Liability', 'Long-term Liability', 'Equity', 'Retained Earnings', 'Revenue', 'Operating Revenue', 'Other Revenue') 
                            THEN jel.credit - jel.debit
                        ELSE 0 
                    END
                ), 0) as balance
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id 
                AND je.status = 'Posted'
                AND je.entry_date <= ?
            WHERE coa.status = 'Active'
            GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
            HAVING total_debit != 0 OR total_credit != 0
            ORDER BY 
                CASE coa.account_type 
                    WHEN 'Asset' THEN 1
                    WHEN 'Current Asset' THEN 1
                    WHEN 'Fixed Asset' THEN 1
                    WHEN 'Other Asset' THEN 1
                    WHEN 'Liability' THEN 2
                    WHEN 'Current Liability' THEN 2
                    WHEN 'Long-term Liability' THEN 2
                    WHEN 'Equity' THEN 3
                    WHEN 'Retained Earnings' THEN 3
                    WHEN 'Revenue' THEN 4
                    WHEN 'Operating Revenue' THEN 4
                    WHEN 'Other Revenue' THEN 4
                    WHEN 'Expense' THEN 5
                    WHEN 'Operating Expense' THEN 5
                    WHEN 'Cost of Goods Sold' THEN 5
                    WHEN 'Other Expense' THEN 5
                    ELSE 6
                END,
                coa.account_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$as_of_date]);
    return $stmt->fetchAll();
}

// Get chart of accounts summary for validation
function getChartOfAccountsSummary(PDO $db): array {
    $sql = "SELECT 
                account_type,
                COUNT(*) as account_count,
                COALESCE(SUM(balance), 0) as total_balance
            FROM chart_of_accounts 
            WHERE status = 'Active'
            GROUP BY account_type
            ORDER BY account_type";
    return $db->query($sql)->fetchAll();
}

// Get filter values from request
$report_type = $_GET['report_type'] ?? 'income_statement';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-t');

// Validate dates
if (!strtotime($start_date)) $start_date = date('Y-m-01');
if (!strtotime($end_date)) $end_date = date('Y-m-t');
if (!strtotime($as_of_date)) $as_of_date = date('Y-m-t');

// Ensure end date is not before start date
if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

// Fetch report data based on type
$report_data = [];
$report_title = '';

try {
    switch ($report_type) {
        case 'income_statement':
            $report_data = getIncomeStatement($db, $start_date, $end_date);
            $report_title = 'Income Statement';
            break;
        case 'balance_sheet':
            $report_data = getBalanceSheet($db, $as_of_date);
            $report_title = 'Balance Sheet';
            break;
        case 'cash_flow':
            $report_data = getCashFlowStatement($db, $start_date, $end_date);
            $report_title = 'Cash Flow Statement';
            break;
        case 'trial_balance':
            $report_data = getTrialBalance($db, $as_of_date);
            $report_title = 'Trial Balance';
            break;
    }
} catch (PDOException $e) {
    error_log("Report generation error: " . $e->getMessage());
    $error_message = "Error generating report. Please check your database configuration.";
}

// Get chart accounts summary for calculations
$chart_summary = getChartOfAccountsSummary($db);

// Calculate summary values
$revenue_total = 0;
$expenses_total = 0;
$assets_total = 0;
$liabilities_total = 0;
$equity_total = 0;

foreach ($report_data as $item) {
    if (isset($item['account_type'])) {
        $amount = (float)($item['amount'] ?? $item['balance'] ?? 0);
        
        switch ($item['account_type']) {
            case 'Revenue':
            case 'Operating Revenue':
            case 'Other Revenue':
                $revenue_total += $amount;
                break;
            case 'Expense':
            case 'Operating Expense':
            case 'Cost of Goods Sold':
            case 'Other Expense':
                $expenses_total += $amount;
                break;
            case 'Asset':
            case 'Current Asset':
            case 'Fixed Asset':
            case 'Other Asset':
                $assets_total += $amount;
                break;
            case 'Liability':
            case 'Current Liability':
            case 'Long-term Liability':
                $liabilities_total += $amount;
                break;
            case 'Equity':
            case 'Retained Earnings':
                $equity_total += $amount;
                break;
        }
    }
}

// For cash flow report, calculate net cash flow
$net_cash_flow = 0;
if ($report_type === 'cash_flow') {
    foreach ($report_data as $item) {
        $net_cash_flow += (float)($item['amount'] ?? 0);
    }
}

// Calculate trial balance totals
$trial_debit_total = 0;
$trial_credit_total = 0;
if ($report_type === 'trial_balance') {
    foreach ($report_data as $item) {
        $trial_debit_total += (float)($item['total_debit'] ?? 0);
        $trial_credit_total += (float)($item['total_credit'] ?? 0);
    }
}

// Get notifications
$notifications = getNotifications($db, $user_id);
$unread_notifications = array_filter($notifications, function($notification) {
    return !$notification['is_read'];
});
$unread_count = count($unread_notifications);

// Function to format numbers with asterisks if hidden - FIXED VERSION
function formatNumber($number, $show_numbers = false) {
    $number = (float)$number;
    if ($show_numbers) {
        return '₱' . number_format($number, 2);
    } else {
        // Create asterisks based on number length
        $formatted = number_format(abs($number), 2);
        $length = strlen($formatted);
        $asterisks = str_repeat('*', max(6, min(12, $length + 3))); // +3 for currency symbol and formatting
        return '₱' . $asterisks;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports - Financial Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
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
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #1D4ED8;
        }
        
        .action-btn.view:hover {
            background-color: #1D4ED8;
            color: white;
        }
        
        .action-btn.export {
            background-color: #F0FDF4;
            color: #047857;
            border-color: #047857;
        }
        
        .action-btn.export:hover {
            background-color: #047857;
            color: white;
        }
        
        .action-btn.print {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.print:hover {
            background-color: #0369A1;
            color: white;
        }

        /* Financial Reports specific styles */
        .report-header {
            background: linear-gradient(135deg, #28644c 0%, #2f855A 100%);
            color: white;
            padding: 2rem;
            border-radius: 0.75rem 0.75rem 0 0;
        }
        
        .report-section {
            background-color: #f8fafc;
            border-left: 4px solid #2f855A;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .report-total {
            background-color: #f1f5f9;
            font-weight: 700;
            border-top: 2px solid #cbd5e1;
        }
        
        .amount-positive {
            color: #10B981;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #EF4444;
            font-weight: 600;
        }
        
        .financial-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0.75rem;
        }
        
        .chart-container {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .account-type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .account-type-asset { background-color: #DBEAFE; color: #1E40AF; }
        .account-type-liability { background-color: #FEE2E2; color: #DC2626; }
        .account-type-equity { background-color: #F3E8FF; color: #7C3AED; }
        .account-type-revenue { background-color: #D1FAE5; color: #065F46; }
        .account-type-expense { background-color: #FEF3C7; color: #92400E; }
        
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

        /* Notification styles */
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

                        <!-- General Ledger Section - KEPT OPEN BY DEFAULT -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ledger">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">General Ledger</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200 rotate-180' data-category="ledger"></i>
                            </div>
                            <div class="submenu active mt-1" id="ledger-submenu">
                                <a href="chart_of_accounts.php" class="submenu-item transition-colors duration-200">Chart of Accounts</a>
                                <a href="journal_entry.php" class="submenu-item transition-colors duration-200">Journal Entry</a>
                                <a href="ledger_table.php" class="submenu-item transition-colors duration-200">Ledger Table</a>
                                <a href="financial_reports.php" class="submenu-item active transition-colors duration-200">Financial Reports</a>
                            </div>
                        </div>
                        
                        <!-- AP/AR Section -->
                        <div class="py-1 mx-2">
                            <div class="flex items-center justify-between sidebar-category py-3 px-3 rounded cursor-pointer hover:bg-hover-state transition-colors duration-200" data-category="ap-ar">
                                <h3 class="text-xs font-semibold text-white uppercase tracking-wider">AP/AR</h3>
                                <i class='bx bx-chevron-down text-white text-sm category-arrow transition-transform duration-200' data-category="ap-ar"></i>
                            </div>
                            <div class="submenu mt-1" id="ap-ar-submenu">
                                <a href="vendors_customers.php" class="submenu-item transition-colors duration-200">Vendors/Customers</a>
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
                        <h1 class="text-2xl font-bold text-white">Financial Reports</h1>
                        <p class="text-sm text-white/90">Generate and analyze financial statements</p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <!-- Number Visibility Toggle Button -->
                    <a href="?toggle_numbers=1" class="eye-toggle-btn" title="<?php echo $_SESSION['show_numbers'] ? 'Hide Numbers' : 'Show Numbers'; ?>">
                        <i class='bx <?php echo $_SESSION['show_numbers'] ? 'bx-hide' : 'bx-show'; ?> text-xl'></i>
                    </a>
                    
                    <!-- Notification Bell -->
                    <div class="relative" id="notification-container">
                        <button id="notification-btn" class="notification-btn" title="Notifications">
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
                    
                    <div id="profile-btn" class="flex items-center space-x-2 cursor-pointer px-3 py-2 transition duration-200">
                        <i class="fa-solid fa-user text-[18px] bg-white text-primary-green px-2.5 py-2 rounded-full"></i>
                        <span class="text-white font-medium"><?php echo htmlspecialchars($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Report Selection and Filters -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Report Configuration</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-input" onchange="this.form.submit()">
                                <option value="income_statement" <?php echo $report_type === 'income_statement' ? 'selected' : ''; ?>>Income Statement</option>
                                <option value="balance_sheet" <?php echo $report_type === 'balance_sheet' ? 'selected' : ''; ?>>Balance Sheet</option>
                                <option value="cash_flow" <?php echo $report_type === 'cash_flow' ? 'selected' : ''; ?>>Cash Flow Statement</option>
                                <option value="trial_balance" <?php echo $report_type === 'trial_balance' ? 'selected' : ''; ?>>Trial Balance</option>
                            </select>
                        </div>
                        
                        <?php if (in_array($report_type, ['income_statement', 'cash_flow'])): ?>
                        <div class="form-group">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <?php else: ?>
                        <div class="form-group">
                            <label class="form-label">As Of Date</label>
                            <input type="date" name="as_of_date" class="form-input" value="<?php echo htmlspecialchars($as_of_date); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <div class="h-10"></div> <!-- Spacer for alignment -->
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group flex items-end space-x-2">
                            <button type="submit" class="btn btn-primary flex-1">
                                <i class='bx bx-refresh mr-2'></i>Generate
                            </button>
                            <button type="button" id="export-pdf" class="btn btn-secondary">
                                <i class='bx bx-download mr-2'></i>PDF
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Financial Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="financial-summary-card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Revenue</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($revenue_total, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-trending-up text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="financial-summary-card p-6" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Expenses</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($expenses_total, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-trending-down text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="financial-summary-card p-6" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Net Income</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($revenue_total - $expenses_total, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-line-chart text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="financial-summary-card p-6" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Total Assets</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatNumber($assets_total, $_SESSION['show_numbers']); ?>
                                </p>
                            </div>
                            <i class='bx bx-package text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <!-- Report Content -->
                <div class="bg-white rounded-xl card-shadow overflow-hidden">
                    <!-- Report Header -->
                    <div class="report-header">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-2xl font-bold"><?php echo $report_title; ?></h2>
                                <p class="opacity-90">
                                    <?php if (in_array($report_type, ['income_statement', 'cash_flow'])): ?>
                                        Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                                    <?php else: ?>
                                        As of: <?php echo date('F j, Y', strtotime($as_of_date)); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm opacity-90">Generated on</p>
                                <p class="font-medium"><?php echo date('F j, Y'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Report Body -->
                    <div class="p-6">
                        <?php if (empty($report_data)): ?>
                            <div class="text-center py-8">
                                <i class='bx bx-line-chart text-6xl text-gray-300 mb-4'></i>
                                <h3 class="text-xl font-bold text-gray-500 mb-2">No Data Available</h3>
                                <p class="text-gray-400">No transactions found for the selected period.</p>
                            </div>
                        <?php elseif ($report_type === 'income_statement'): ?>
                            <!-- Income Statement -->
                            <div class="overflow-x-auto">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Account</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Revenue Section -->
                                        <tr class="report-section">
                                            <td colspan="3" class="font-bold text-lg">REVENUE</td>
                                        </tr>
                                        <?php 
                                        $total_revenue = 0;
                                        foreach ($report_data as $item): 
                                            if (in_array($item['account_type'], ['Revenue', 'Operating Revenue', 'Other Revenue']) && ($item['amount'] ?? 0) != 0):
                                                $total_revenue += (float)($item['amount'] ?? 0);
                                        ?>
                                        <tr>
                                            <td class="pl-8"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                            <td><span class="account-type-badge account-type-revenue"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></span></td>
                                            <td class="amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)($item['amount'] ?? 0), $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                        <tr class="report-total">
                                            <td class="pl-8 font-bold" colspan="2">Total Revenue</td>
                                            <td class="amount-positive font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_revenue, $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>

                                        <!-- Expenses Section -->
                                        <tr class="report-section">
                                            <td colspan="3" class="font-bold text-lg">EXPENSES</td>
                                        </tr>
                                        <?php 
                                        $total_expenses = 0;
                                        foreach ($report_data as $item): 
                                            if (in_array($item['account_type'], ['Expense', 'Operating Expense', 'Cost of Goods Sold', 'Other Expense']) && ($item['amount'] ?? 0) != 0):
                                                $total_expenses += (float)($item['amount'] ?? 0);
                                        ?>
                                        <tr>
                                            <td class="pl-8"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                            <td><span class="account-type-badge account-type-expense"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></span></td>
                                            <td class="amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)($item['amount'] ?? 0), $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                        <tr class="report-total">
                                            <td class="pl-8 font-bold" colspan="2">Total Expenses</td>
                                            <td class="amount-negative font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_expenses, $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>

                                        <!-- Net Income -->
                                        <tr class="report-section">
                                            <td class="font-bold text-lg" colspan="2">NET INCOME</td>
                                            <td class="font-bold text-lg <?php echo ($total_revenue - $total_expenses) >= 0 ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_revenue - $total_expenses, $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        <?php elseif ($report_type === 'balance_sheet'): ?>
                            <!-- Balance Sheet -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <!-- Assets -->
                                <div>
                                    <h3 class="text-xl font-bold mb-4">ASSETS</h3>
                                    <table class="data-table w-full">
                                        <tbody>
                                            <?php 
                                            $total_assets = 0;
                                            foreach ($report_data as $item): 
                                                if (in_array($item['account_type'], ['Asset', 'Current Asset', 'Fixed Asset', 'Other Asset']) && ($item['balance'] ?? 0) != 0):
                                                    $total_assets += (float)($item['balance'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                                <td><span class="account-type-badge account-type-asset"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></span></td>
                                                <td class="amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber((float)($item['balance'] ?? 0), $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                            <tr class="report-total">
                                                <td class="font-bold" colspan="2">Total Assets</td>
                                                <td class="amount-positive font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber($total_assets, $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Liabilities & Equity -->
                                <div>
                                    <h3 class="text-xl font-bold mb-4">LIABILITIES & EQUITY</h3>
                                    <table class="data-table w-full">
                                        <tbody>
                                            <!-- Liabilities -->
                                            <tr>
                                                <td colspan="3" class="font-bold">Liabilities</td>
                                            </tr>
                                            <?php 
                                            $total_liabilities = 0;
                                            foreach ($report_data as $item): 
                                                if (in_array($item['account_type'], ['Liability', 'Current Liability', 'Long-term Liability']) && ($item['balance'] ?? 0) != 0):
                                                    $total_liabilities += (float)($item['balance'] ?? 0);
                                            ?>
                                            <tr>
                                                <td class="pl-4"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                                <td><span class="account-type-badge account-type-liability"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></span></td>
                                                <td class="amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber((float)($item['balance'] ?? 0), $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                            <tr class="report-total">
                                                <td class="font-bold" colspan="2">Total Liabilities</td>
                                                <td class="amount-negative font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber($total_liabilities, $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>

                                            <!-- Equity -->
                                            <tr>
                                                <td colspan="3" class="font-bold pt-4">Equity</td>
                                            </tr>
                                            <?php 
                                            $total_equity = 0;
                                            foreach ($report_data as $item): 
                                                if (in_array($item['account_type'], ['Equity', 'Retained Earnings']) && ($item['balance'] ?? 0) != 0):
                                                    $total_equity += (float)($item['balance'] ?? 0);
                                            ?>
                                            <tr>
                                                <td class="pl-4"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                                <td><span class="account-type-badge account-type-equity"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></span></td>
                                                <td class="amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber((float)($item['balance'] ?? 0), $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                            <tr class="report-total">
                                                <td class="font-bold" colspan="2">Total Equity</td>
                                                <td class="amount-positive font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber($total_equity, $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>

                                            <!-- Total Liabilities & Equity -->
                                            <tr class="report-section">
                                                <td class="font-bold" colspan="2">Total Liabilities & Equity</td>
                                                <td class="font-bold <?php echo ($total_liabilities + $total_equity) == $total_assets ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                    <?php echo formatNumber($total_liabilities + $total_equity, $_SESSION['show_numbers']); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        <?php elseif ($report_type === 'cash_flow'): ?>
                            <!-- Cash Flow Statement -->
                            <div class="overflow-x-auto">
                                <table class="data-table w-full">
                                    <tbody>
                                        <?php 
                                        $net_cash_flow = 0;
                                        $current_category = '';
                                        foreach ($report_data as $item): 
                                            if (($item['category'] ?? '') !== $current_category):
                                                $current_category = $item['category'] ?? '';
                                        ?>
                                        <tr class="report-section">
                                            <td colspan="2" class="font-bold text-lg">CASH FLOWS FROM <?php echo strtoupper($current_category); ?> ACTIVITIES</td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <td class="pl-8"><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                            <td class="<?php echo ($item['amount'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)($item['amount'] ?? 0), $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                        <?php 
                                            $net_cash_flow += (float)($item['amount'] ?? 0);
                                        endforeach; 
                                        ?>
                                        <tr class="report-section">
                                            <td class="font-bold text-lg">NET INCREASE IN CASH</td>
                                            <td class="font-bold text-lg <?php echo $net_cash_flow >= 0 ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($net_cash_flow, $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        <?php elseif ($report_type === 'trial_balance'): ?>
                            <!-- Trial Balance -->
                            <div class="overflow-x-auto">
                                <table class="data-table w-full">
                                    <thead>
                                        <tr>
                                            <th>Account</th>
                                            <th>Type</th>
                                            <th>Debit</th>
                                            <th>Credit</th>
                                            <th>Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_debit = 0;
                                        $total_credit = 0;
                                        $current_type = '';
                                        foreach ($report_data as $item): 
                                            if (($item['account_type'] ?? '') !== $current_type):
                                                $current_type = $item['account_type'] ?? '';
                                        ?>
                                        <tr class="report-section">
                                            <td colspan="5" class="font-bold"><?php echo strtoupper($current_type); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr>
                                            <td class="pl-8"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                                            <td>
                                                <span class="account-type-badge 
                                                    <?php 
                                                    if (in_array($item['account_type'], ['Asset', 'Current Asset', 'Fixed Asset', 'Other Asset'])) echo 'account-type-asset';
                                                    elseif (in_array($item['account_type'], ['Liability', 'Current Liability', 'Long-term Liability'])) echo 'account-type-liability';
                                                    elseif (in_array($item['account_type'], ['Equity', 'Retained Earnings'])) echo 'account-type-equity';
                                                    elseif (in_array($item['account_type'], ['Revenue', 'Operating Revenue', 'Other Revenue'])) echo 'account-type-revenue';
                                                    else echo 'account-type-expense';
                                                    ?>">
                                                    <?php echo htmlspecialchars($item['account_type'] ?? ''); ?>
                                                </span>
                                            </td>
                                            <td class="amount-positive <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo ($item['total_debit'] ?? 0) > 0 ? formatNumber((float)($item['total_debit'] ?? 0), $_SESSION['show_numbers']) : ''; ?>
                                            </td>
                                            <td class="amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo ($item['total_credit'] ?? 0) > 0 ? formatNumber((float)($item['total_credit'] ?? 0), $_SESSION['show_numbers']) : ''; ?>
                                            </td>
                                            <td class="<?php echo ($item['balance'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber((float)($item['balance'] ?? 0), $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                        <?php 
                                            $total_debit += (float)($item['total_debit'] ?? 0);
                                            $total_credit += (float)($item['total_credit'] ?? 0);
                                        endforeach; 
                                        ?>
                                        <tr class="report-total">
                                            <td class="font-bold" colspan="2">TOTAL</td>
                                            <td class="amount-positive font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_debit, $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="amount-negative font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_credit, $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="font-bold <?php echo ($total_debit - $total_credit) == 0 ? 'amount-positive' : 'amount-negative'; ?> <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatNumber($total_debit - $total_credit, $_SESSION['show_numbers']); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                    <div class="chart-container">
                        <h3 class="text-lg font-bold mb-4">Financial Overview</h3>
                        <canvas id="financialChart" height="300"></canvas>
                    </div>
                    <div class="chart-container">
                        <h3 class="text-lg font-bold mb-4">Income vs Expenses</h3>
                        <canvas id="incomeExpenseChart" height="300"></canvas>
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
        const profileModal = document.getElementById('profile-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        if (profileBtn && profileModal) {
            profileBtn.addEventListener('click', function() {
                profileModal.style.display = 'block';
            });
        }
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                profileModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
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

        // Export PDF functionality
        const exportPdfBtn = document.getElementById('export-pdf');
        if (exportPdfBtn) {
            exportPdfBtn.addEventListener('click', function() {
                const originalText = this.innerHTML;
                this.innerHTML = '<div class="spinner"></div>Generating PDF...';
                this.disabled = true;
                
                setTimeout(() => {
                    alert('PDF export functionality would be implemented here. This would generate a downloadable PDF of the current report.');
                    this.innerHTML = originalText;
                    this.disabled = false;
                }, 1500);
            });
        }

        // Notification functionality
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

        // Initialize Charts
        initializeFinancialCharts();
    });

    function initializeFinancialCharts() {
        // Financial Overview Chart
        const financialCtx = document.getElementById('financialChart');
        if (financialCtx) {
            const revenue = <?php echo $revenue_total ?? 0; ?>;
            const expenses = <?php echo $expenses_total ?? 0; ?>;
            const netIncome = <?php echo ($revenue_total ?? 0) - ($expenses_total ?? 0); ?>;
            
            // Only create chart if we have data
            if (revenue > 0 || expenses > 0) {
                const financialChart = new Chart(financialCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Revenue', 'Expenses', 'Net Income'],
                        datasets: [{
                            data: [revenue, expenses, netIncome],
                            backgroundColor: ['#10B981', '#EF4444', '#3B82F6'],
                            borderWidth: 0
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
            } else {
                financialCtx.closest('.chart-container').innerHTML = '<div class="text-center py-4 text-gray-500">No data available for chart</div>';
            }
        }

        // Income vs Expenses Chart
        const incomeExpenseCtx = document.getElementById('incomeExpenseChart');
        if (incomeExpenseCtx) {
            const revenue = <?php echo $revenue_total ?? 0; ?>;
            const expenses = <?php echo $expenses_total ?? 0; ?>;
            
            if (revenue > 0 || expenses > 0) {
                const incomeExpenseChart = new Chart(incomeExpenseCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Revenue', 'Expenses'],
                        datasets: [{
                            label: 'Amount',
                            data: [revenue, expenses],
                            backgroundColor: ['#10B981', '#EF4444'],
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            } else {
                incomeExpenseCtx.closest('.chart-container').innerHTML = '<div class="text-center py-4 text-gray-500">No data available for chart</div>';
            }
        }
    }
    </script>
</body>
</html>