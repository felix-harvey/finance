<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

// Handle export request
if (isset($_POST['export_aging_report'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    // Get filter parameters
    $type = $_POST['type'] ?? null;
    $contact_id = $_POST['contact_id'] ?? null;
    $as_of_date = $_POST['as_of_date'] ?? date('Y-m-d');

    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get aging data using the existing function
        $aging_data = getAgingReport($db, $type, $contact_id, $as_of_date);
        $aging_summary = getAgingSummary($db, $type, $as_of_date);
        
        // Export as Excel
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="aging_report_' . date('Y-m-d') . '.xls"');
        
        // Excel output
        echo "<html>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<style>";
        echo "table { border-collapse: collapse; width: 100%; }";
        echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
        echo "th { background-color: #f2f2f2; font-weight: bold; }";
        echo ".summary { margin-bottom: 20px; }";
        echo ".bucket { font-weight: bold; }";
        echo ".total-row { background-color: #e6f3ff; font-weight: bold; }";
        echo "</style>";
        echo "</head>";
        echo "<body>";
        
        echo "<h2>Aging Report</h2>";
        echo "<p><strong>As Of:</strong> " . htmlspecialchars($as_of_date) . "</p>";
        echo "<p><strong>Report Type:</strong> " . ($type ? htmlspecialchars($type) : 'All Types') . "</p>";
        if ($contact_id) {
            $contact_name = '';
            foreach ($all_contacts as $contact) {
                if ($contact['contact_id'] == $contact_id) {
                    $contact_name = $contact['name'];
                    break;
                }
            }
            echo "<p><strong>Contact:</strong> " . htmlspecialchars($contact_name) . "</p>";
        }
        
        // Aging Summary
        echo "<div class='summary'>";
        echo "<h3>Aging Summary</h3>";
        echo "<table>";
        echo "<tr><th>Aging Bucket</th><th>Invoice Count</th><th>Total Amount</th></tr>";
        
        $total_outstanding_export = 0;
        $total_invoices_export = 0;
        foreach ($aging_summary as $bucket) {
            echo "<tr>";
            echo "<td class='bucket'>" . htmlspecialchars($bucket['aging_bucket']) . "</td>";
            echo "<td>" . $bucket['invoice_count'] . "</td>";
            echo "<td>₱" . number_format((float)$bucket['total_amount'], 2) . "</td>";
            echo "</tr>";
            $total_outstanding_export += (float)$bucket['total_amount'];
            $total_invoices_export += $bucket['invoice_count'];
        }
        
        echo "<tr class='total-row'>";
        echo "<td>Total Outstanding</td>";
        echo "<td>" . $total_invoices_export . "</td>";
        echo "<td>₱" . number_format($total_outstanding_export, 2) . "</td>";
        echo "</tr>";
        echo "</table>";
        echo "</div>";
        
        // Detailed Report
        echo "<h3>Detailed Aging Report</h3>";
        echo "<table>";
        echo "<tr>
                <th>Invoice #</th>
                <th>Contact</th>
                <th>Type</th>
                <th>Issue Date</th>
                <th>Due Date</th>
                <th>Amount</th>
                <th>Outstanding</th>
                <th>Days Overdue</th>
                <th>Aging Bucket</th>
              </tr>";
        
        foreach ($aging_data as $invoice) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($invoice['invoice_number']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['contact_name']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['type']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['issue_date']) . "</td>";
            echo "<td>" . htmlspecialchars($invoice['due_date']) . "</td>";
            echo "<td>₱" . number_format((float)$invoice['amount'], 2) . "</td>";
            echo "<td>₱" . number_format((float)$invoice['outstanding_balance'], 2) . "</td>";
            echo "<td>" . ($invoice['days_overdue'] > 0 ? $invoice['days_overdue'] : '0') . "</td>";
            echo "<td>" . htmlspecialchars($invoice['aging_bucket']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        echo "<p style='margin-top: 20px; font-style: italic;'>Generated on: " . date('Y-m-d H:i:s') . "</p>";
        echo "</body></html>";
        
        exit;
        
    } catch (Exception $e) {
        die('Export error: ' . $e->getMessage());
    }
}

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

// Load current user with proper error handling
try {
    $u = $db->prepare("SELECT id, name, username, role FROM users WHERE id = ?");
    $u->execute([$user_id]);
    $user = $u->fetch();
    
    if (!$user) {
        header("Location: index.php");
        exit;
    }
    
    // Ensure user data has proper values
    $user['name'] = $user['name'] ?? 'Unknown User';
    $user['username'] = $user['username'] ?? 'unknown';
    $user['role'] = $user['role'] ?? 'user';
    
} catch (Throwable $e) {
    error_log("User loading error: " . $e->getMessage());
    header("Location: index.php");
    exit;
}

// Function to add a notification for overdue invoices
function addOverdueNotification($contact_name, $amount, $days_overdue) {
    $notification = [
        'id' => uniqid(),
        'type' => 'aging',
        'message' => "Overdue invoice: {$contact_name} - " . formatNumber($amount, true) . " ({$days_overdue} days overdue)",
        'timestamp' => time(),
        'link' => 'aging_reports.php',
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

// Get aging report data - UPDATED to match invoices.php field names
function getAgingReport(PDO $db, ?string $type = null, ?string $contact_id = null, ?string $as_of_date = null): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    $sql = "SELECT 
                i.*, 
                bc.name as contact_name,
                bc.contact_person,
                bc.email,
                bc.phone,
                (i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as outstanding_balance,
                DATEDIFF(?, i.due_date) as days_overdue,
                CASE 
                    WHEN DATEDIFF(?, i.due_date) <= 0 THEN 'Current'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 1 AND 30 THEN '1-30 Days'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 31 AND 60 THEN '31-60 Days'
                    WHEN DATEDIFF(?, i.due_date) BETWEEN 61 AND 90 THEN '61-90 Days'
                    ELSE 'Over 90 Days'
                END as aging_bucket
            FROM invoices i
            LEFT JOIN business_contacts bc ON i.contact_id = bc.contact_id
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [$as_of_date, $as_of_date, $as_of_date, $as_of_date, $as_of_date];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    if ($contact_id) {
        $sql .= " AND i.contact_id = ?";
        $params[] = $contact_id;
    }
    
    $sql .= " ORDER BY i.due_date ASC, outstanding_balance DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    // Ensure all string fields have values
    foreach ($results as &$result) {
        $result['contact_name'] = $result['contact_name'] ?? 'Unknown Contact';
        $result['contact_person'] = $result['contact_person'] ?? 'N/A';
        $result['invoice_number'] = $result['invoice_number'] ?? 'N/A';
        $result['issue_date'] = $result['issue_date'] ?? 'N/A';
        $result['due_date'] = $result['due_date'] ?? 'N/A';
    }
    
    return $results;
}

// Get aging summary by bucket - UPDATED to match invoices.php field names
function getAgingSummary(PDO $db, ?string $type = null, ?string $as_of_date = null): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    // Simple query that calculates everything in PHP
    $sql = "SELECT 
                i.id,
                i.amount,
                i.due_date,
                i.type,
                (i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as outstanding_balance
            FROM invoices i
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY i.due_date ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
    
    // Calculate aging buckets in PHP
    $aging_buckets = [
        'Current' => ['invoice_count' => 0, 'total_amount' => 0],
        '1-30 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        '31-60 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        '61-90 Days' => ['invoice_count' => 0, 'total_amount' => 0],
        'Over 90 Days' => ['invoice_count' => 0, 'total_amount' => 0]
    ];
    
    foreach ($invoices as $invoice) {
        $days_overdue = (strtotime($as_of_date) - strtotime($invoice['due_date'])) / (60 * 60 * 24);
        
        if ($days_overdue <= 0) {
            $bucket = 'Current';
        } elseif ($days_overdue <= 30) {
            $bucket = '1-30 Days';
        } elseif ($days_overdue <= 60) {
            $bucket = '31-60 Days';
        } elseif ($days_overdue <= 90) {
            $bucket = '61-90 Days';
        } else {
            $bucket = 'Over 90 Days';
        }
        
        $aging_buckets[$bucket]['invoice_count']++;
        $aging_buckets[$bucket]['total_amount'] += $invoice['outstanding_balance'];
    }
    
    // Convert to the format expected by the frontend
    $result = [];
    foreach ($aging_buckets as $bucket => $data) {
        if ($data['total_amount'] > 0) {
            $result[] = [
                'aging_bucket' => $bucket,
                'invoice_count' => $data['invoice_count'],
                'total_amount' => $data['total_amount']
            ];
        }
    }
    
    // Sort by bucket order
    usort($result, function($a, $b) {
        $order = ['Current' => 1, '1-30 Days' => 2, '31-60 Days' => 3, '61-90 Days' => 4, 'Over 90 Days' => 5];
        return $order[$a['aging_bucket']] - $order[$b['aging_bucket']];
    });
    
    return $result;
}

// Get business contacts for dropdown - UPDATED to match invoices.php field names
function getBusinessContacts(PDO $db, ?string $type = null): array {
    $sql = "SELECT contact_id, name, contact_person, type 
            FROM business_contacts 
            WHERE status = 'Active'";
    
    if ($type) {
        $sql .= " AND type = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$type]);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }
    
    $contacts = $stmt->fetchAll();
    
    // Ensure all contact fields have values
    foreach ($contacts as &$contact) {
        $contact['name'] = $contact['name'] ?? 'Unknown Contact';
        $contact['contact_person'] = $contact['contact_person'] ?? 'N/A';
    }
    
    return $contacts;
}

// Get top overdue contacts - UPDATED to match invoices.php field names
function getTopOverdueContacts(PDO $db, ?string $type = null, ?string $as_of_date = null, int $limit = 10): array {
    $as_of_date = $as_of_date ?: date('Y-m-d');
    
    // Get all overdue invoices grouped by contact
    $sql = "SELECT 
                bc.contact_id as id,
                bc.name as contact_name,
                bc.contact_person,
                COUNT(i.id) as overdue_invoices,
                SUM(i.amount - COALESCE((
                    SELECT SUM(p.amount) 
                    FROM payments p 
                    WHERE p.invoice_id = i.id 
                    AND p.status = 'Completed'
                ), 0)) as total_overdue,
                MAX(i.due_date) as oldest_due_date
            FROM business_contacts bc
            INNER JOIN invoices i ON bc.contact_id = i.contact_id
            WHERE i.status NOT IN ('Paid', 'Cancelled')
            AND DATEDIFF(?, i.due_date) > 0
            AND (i.amount - COALESCE((
                SELECT SUM(p.amount) 
                FROM payments p 
                WHERE p.invoice_id = i.id 
                AND p.status = 'Completed'
            ), 0)) > 0";
    
    $params = [$as_of_date];
    
    if ($type) {
        $sql .= " AND i.type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY bc.contact_id, bc.name, bc.contact_person
              HAVING total_overdue > 0
              ORDER BY total_overdue DESC
              LIMIT ?";
    
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    
    // Bind parameters with explicit types
    foreach ($params as $key => $value) {
        if ($key === count($params) - 1) { // Last parameter is the LIMIT
            $stmt->bindValue($key + 1, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key + 1, $value, PDO::PARAM_STR);
        }
    }
    
    $stmt->execute();
    $contacts = $stmt->fetchAll();
    
    // Ensure all contact fields have values
    foreach ($contacts as &$contact) {
        $contact['contact_name'] = $contact['contact_name'] ?? 'Unknown Contact';
        $contact['contact_person'] = $contact['contact_person'] ?? 'N/A';
        $contact['oldest_due_date'] = $contact['oldest_due_date'] ?? 'N/A';
    }
    
    return $contacts;
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter values from request
$report_type = $_GET['type'] ?? null;
$contact_id = $_GET['contact_id'] ?? null;
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

$aging_data = getAgingReport($db, $report_type, $contact_id, $as_of_date);
$aging_summary = getAgingSummary($db, $report_type, $as_of_date);
$top_overdue = getTopOverdueContacts($db, $report_type, $as_of_date, 10);
$vendors = getBusinessContacts($db, 'Vendor');
$customers = getBusinessContacts($db, 'Customer');
$all_contacts = getBusinessContacts($db);

// Get notifications
$notification_count = getUnreadNotificationCount();
$notifications = getNotifications();

// Calculate totals
$total_outstanding = array_sum(array_column($aging_data, 'outstanding_balance'));
$total_invoices = count($aging_data);

// Helper function to get amount by aging bucket
function getAmountByBucket(array $aging_data, string $bucket): float {
    return array_sum(array_map(function($item) use ($bucket) {
        return $item['aging_bucket'] === $bucket ? (float)$item['outstanding_balance'] : 0;
    }, $aging_data));
}

$current_amount = getAmountByBucket($aging_data, 'Current');
$bucket_1_30 = getAmountByBucket($aging_data, '1-30 Days');
$bucket_31_60 = getAmountByBucket($aging_data, '31-60 Days');
$bucket_61_90 = getAmountByBucket($aging_data, '61-90 Days');
$bucket_over_90 = getAmountByBucket($aging_data, 'Over 90 Days');

// Safe output function
function safe_html($value, $default = '') {
    if ($value === null) {
        $value = $default;
    }
    return htmlspecialchars((string)$value);
}

// Function to format numbers with asterisks if hidden - FIXED
function formatDisplayNumber($number, $show_numbers = false) {
    // Ensure the input is treated as float
    $number_float = (float)$number;
    
    if ($show_numbers) {
        return '₱' . number_format($number_float, 0); // Remove decimal places for aging reports
    } else {
        $numberStr = number_format($number_float, 0);
        return '₱' . str_repeat('*', strlen($numberStr));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aging Reports - Financial Dashboard</title>
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
            border: 2px solid #f3f3f3;
            border-top: 2px solid #2f855A;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
            vertical-align: middle;
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
            max-width: 500px;
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
        
        .action-btn.contact {
            background-color: #F0F9FF;
            color: #0369A1;
            border-color: #0369A1;
        }
        
        .action-btn.contact:hover {
            background-color: #0369A1;
            color: white;
        }

        /* Aging Reports specific styles */
        .aging-card {
            border-radius: 0.75rem;
            padding: 1.5rem;
            color: white;
        }
        
        .aging-bucket-current { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .aging-bucket-1-30 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .aging-bucket-31-60 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .aging-bucket-61-90 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .aging-bucket-over-90 { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        
        .progress-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-current { background-color: #10B981; }
        .progress-1-30 { background-color: #F59E0B; }
        .progress-31-60 { background-color: #F97316; }
        .progress-61-90 { background-color: #EF4444; }
        .progress-over-90 { background-color: #DC2626; }
        
        .aging-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-current { background-color: #D1FAE5; color: #065F46; }
        .badge-1-30 { background-color: #FEF3C7; color: #92400E; }
        .badge-31-60 { background-color: #FEE2E2; color: #991B1B; }
        .badge-61-90 { background-color: #FECACA; color: #7F1D1D; }
        .badge-over-90 { background-color: #FCA5A5; color: #450A0A; }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .amount-masked {
            font-family: monospace;
            letter-spacing: 2px;
        }
        
        .toggle-visibility-btn {
            background-color: #2f855A;
            color: white;
            border: none;
            padding: 0.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }
        
        .toggle-visibility-btn:hover {
            background-color: #28644c;
        }

        /* Loading state styles */
        .action-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
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
                    <h3 class="text-lg font-bold" id="profile-name"><?php echo safe_html($user['name']); ?></h3>
                    <p class="text-gray-500"><?php echo ucfirst(safe_html($user['role'])); ?></p>
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
                                <a href="payment_entry.php" class="submenu-item transition-colors duration-200">Payment Entry</a>
                                <a href="aging_reports.php" class="submenu-item active transition-colors duration-200">Aging Reports</a>
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
                        <h1 class="text-2xl font-bold text-white">Aging Reports</h1>
                        <p class="text-sm text-white/90">Analyze outstanding invoices by aging period</p>
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
                        <span class="text-white font-medium"><?php echo safe_html($user['name']); ?></span>
                        <i class="fa-solid fa-chevron-down text-sm text-white"></i>
                    </div>
                </div>
            </div>
            
            <div class="p-6 flex-1">
                <!-- Report Controls -->
                <div class="bg-white rounded-xl p-6 card-shadow mb-6">
                    <h3 class="text-lg font-bold text-dark-text mb-4">Report Parameters</h3>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="form-group">
                            <label class="form-label">Report Type</label>
                            <select name="type" class="form-input">
                                <option value="">All Types</option>
                                <option value="Receivable" <?php echo $report_type === 'Receivable' ? 'selected' : ''; ?>>Accounts Receivable</option>
                                <option value="Payable" <?php echo $report_type === 'Payable' ? 'selected' : ''; ?>>Accounts Payable</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact</label>
                            <select name="contact_id" class="form-input">
                                <option value="">All Contacts</option>
                                <?php foreach ($all_contacts as $contact): ?>
                                <option value="<?php echo safe_html($contact['contact_id']); ?>" <?php echo $contact_id == $contact['contact_id'] ? 'selected' : ''; ?>>
                                    <?php echo safe_html($contact['name'] . ' (' . $contact['type'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">As Of Date</label>
                            <input type="date" name="as_of_date" class="form-input" value="<?php echo safe_html($as_of_date); ?>">
                        </div>
                        <div class="form-group flex items-end space-x-2">
                            <button type="submit" class="btn btn-primary flex-1">
                                <i class='bx bx-refresh mr-2'></i>Generate Report
                            </button>
                            <!-- Simple Export Button - Direct Excel Export -->
                            <button type="button" class="btn btn-secondary" onclick="exportAgingReport()">
                                <i class='bx bx-download mr-2'></i>Export to Excel
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Aging Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                    <div class="aging-card aging-bucket-current">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Current</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatDisplayNumber($current_amount, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Not Due</p>
                            </div>
                            <i class='bx bx-time text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-1-30">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">1-30 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_1_30, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Slightly Overdue</p>
                            </div>
                            <i class='bx bx-alarm-exclamation text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-31-60">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">31-60 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_31_60, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Overdue</p>
                            </div>
                            <i class='bx bx-error-circle text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-61-90">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">61-90 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_61_90, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Significantly Overdue</p>
                            </div>
                            <i class='bx bx-error-alt text-3xl opacity-80'></i>
                        </div>
                    </div>
                    
                    <div class="aging-card aging-bucket-over-90">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm opacity-90">Over 90 Days</p>
                                <p class="text-2xl font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                    <?php echo formatDisplayNumber($bucket_over_90, $_SESSION['show_numbers']); ?>
                                </p>
                                <p class="text-xs opacity-80 mt-1">Critical</p>
                            </div>
                            <i class='bx bx-dizzy text-3xl opacity-80'></i>
                        </div>
                    </div>
                </div>

                <!-- Charts and Visualizations -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Aging Distribution Chart -->
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Aging Distribution</h3>
                        <div class="chart-container">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Aging Progress Bars -->
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Aging Breakdown</h3>
                        <div class="space-y-4">
                            <?php if (count($aging_summary) > 0): ?>
                                <?php foreach ($aging_summary as $bucket): ?>
                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="font-medium"><?php echo safe_html($bucket['aging_bucket']); ?></span>
                                        <span class="font-bold <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                            <?php echo formatDisplayNumber($bucket['total_amount'], $_SESSION['show_numbers']); ?>
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill 
                                            <?php
                                            echo match($bucket['aging_bucket']) {
                                                'Current' => 'progress-current',
                                                '1-30 Days' => 'progress-1-30',
                                                '31-60 Days' => 'progress-31-60',
                                                '61-90 Days' => 'progress-61-90',
                                                default => 'progress-over-90'
                                            };
                                            ?>" 
                                            style="width: <?php echo $total_outstanding > 0 ? ($bucket['total_amount'] / $total_outstanding * 100) : 0; ?>%">
                                        </div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-500 mt-1">
                                        <span><?php echo $bucket['invoice_count']; ?> invoices</span>
                                        <span><?php echo $total_outstanding > 0 ? number_format(($bucket['total_amount'] / $total_outstanding * 100), 1) : 0; ?>%</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500">
                                    No aging data available for the selected criteria.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Overdue Contacts -->
                <div class="bg-white rounded-xl card-shadow mb-6">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-dark-text mb-4">Top Overdue Contacts</h3>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Contact Name</th>
                                        <th>Overdue Invoices</th>
                                        <th>Total Overdue</th>
                                        <th>Oldest Due Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($top_overdue) > 0): ?>
                                        <?php foreach ($top_overdue as $contact): ?>
                                        <tr>
                                            <td class="font-medium"><?php echo safe_html($contact['contact_name']); ?></td>
                                            <td><?php echo $contact['overdue_invoices']; ?></td>
                                            <td class="font-bold amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatDisplayNumber($contact['total_overdue'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td><?php echo safe_html($contact['oldest_due_date']); ?></td>
                                            <td>
                                                <button class="action-btn contact" onclick="viewContactInvoices('<?php echo safe_html($contact['id']); ?>')">
                                                    <i class='bx bx-show mr-1'></i>View Invoices
                                                </button>
                                                <button class="action-btn view" onclick="sendReminder('<?php echo safe_html($contact['id']); ?>', '<?php echo safe_html($contact['contact_name']); ?>')">
                                                    <i class='bx bx-envelope mr-1'></i>Remind
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-gray-500">
                                                No overdue contacts found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Detailed Aging Report -->
                <div class="bg-white rounded-xl card-shadow">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-bold text-dark-text">Detailed Aging Report</h3>
                            <div class="text-sm text-gray-500">
                                As of: <?php echo safe_html($as_of_date); ?> | 
                                Total: <span class="<?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>"><?php echo formatDisplayNumber($total_outstanding, $_SESSION['show_numbers']); ?></span> | 
                                Invoices: <?php echo $total_invoices; ?>
                            </div>
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
                                        <th>Outstanding</th>
                                        <th>Days Overdue</th>
                                        <th>Aging Bucket</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($aging_data) > 0): ?>
                                        <?php foreach ($aging_data as $invoice): 
                                            $badge_class = match($invoice['aging_bucket']) {
                                                'Current' => 'badge-current',
                                                '1-30 Days' => 'badge-1-30',
                                                '31-60 Days' => 'badge-31-60',
                                                '61-90 Days' => 'badge-61-90',
                                                default => 'badge-over-90'
                                            };
                                        ?>
                                        <tr>
                                            <td class="font-mono font-medium"><?php echo safe_html($invoice['invoice_number']); ?></td>
                                            <td>
                                                <div class="font-medium"><?php echo safe_html($invoice['contact_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo safe_html($invoice['contact_person']); ?></div>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                    <?php echo $invoice['type'] === 'Receivable' ? 'AR' : 'AP'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo safe_html($invoice['issue_date']); ?></td>
                                            <td class="<?php echo $invoice['days_overdue'] > 0 ? 'text-red-600 font-medium' : ''; ?>">
                                                <?php echo safe_html($invoice['due_date']); ?>
                                            </td>
                                            <td class="font-medium <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatDisplayNumber($invoice['amount'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="font-bold amount-negative <?php echo !$_SESSION['show_numbers'] ? 'hidden-numbers' : ''; ?>">
                                                <?php echo formatDisplayNumber($invoice['outstanding_balance'], $_SESSION['show_numbers']); ?>
                                            </td>
                                            <td class="<?php echo $invoice['days_overdue'] > 0 ? 'text-red-600 font-medium' : 'text-green-600'; ?>">
                                                <?php echo $invoice['days_overdue'] > 0 ? $invoice['days_overdue'] : '0'; ?>
                                            </td>
                                            <td>
                                                <span class="aging-badge <?php echo $badge_class; ?>">
                                                    <?php echo safe_html($invoice['aging_bucket']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap gap-2">
                                                    <button class="action-btn view" title="View Invoice" onclick="viewInvoice(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-show mr-1'></i>View
                                                    </button>
                                                    <button class="action-btn contact" title="Record Payment" onclick="recordPayment(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-credit-card mr-1'></i>Pay
                                                    </button>
                                                    <button class="action-btn view" title="Send Reminder" onclick="sendInvoiceReminder(<?php echo $invoice['id']; ?>)">
                                                        <i class='bx bx-envelope mr-1'></i>Remind
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-gray-500">
                                                No outstanding invoices found for the selected criteria.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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

        // Modal functionality - FIXED
        const profileBtn = document.getElementById('profile-btn');
        const profileModal = document.getElementById('profile-modal');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        // Ensure modal is hidden on page load
        if (profileModal) {
            profileModal.style.display = 'none';
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
                if (profileModal) {
                    profileModal.style.display = 'none';
                }
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (profileModal && event.target === profileModal) {
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

        // Initialize charts
        initializeAgingChart();
    });

    // Initialize Aging Chart
    function initializeAgingChart() {
        const ctx = document.getElementById('agingChart').getContext('2d');
        
        // Chart data from PHP
        const agingData = {
            labels: ['Current', '1-30 Days', '31-60 Days', '61-90 Days', 'Over 90 Days'],
            datasets: [{
                data: [
                    <?php echo $current_amount; ?>,
                    <?php echo $bucket_1_30; ?>,
                    <?php echo $bucket_31_60; ?>,
                    <?php echo $bucket_61_90; ?>,
                    <?php echo $bucket_over_90; ?>
                ],
                backgroundColor: [
                    '#10B981',
                    '#F59E0B',
                    '#F97316',
                    '#EF4444',
                    '#DC2626'
                ],
                borderWidth: 1
            }]
        };

        new Chart(ctx, {
            type: 'doughnut',
            data: agingData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ₱${parseInt(value).toLocaleString('en-US')} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Generic function to handle button loading states - FIXED VERSION
    function setButtonLoading(button, isLoading) {
        // Find the actual button element if the event target was a child element
        let btnElement = button;
        if (!button.classList.contains('action-btn') && !button.classList.contains('btn')) {
            btnElement = button.closest('.action-btn') || button.closest('.btn');
        }
        
        if (!btnElement) return;
        
        if (isLoading) {
            // Store original content
            btnElement.dataset.originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<div class="spinner"></div>Loading...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';
            btnElement.style.cursor = 'not-allowed';
        } else {
            // Restore original content
            if (btnElement.dataset.originalContent) {
                btnElement.innerHTML = btnElement.dataset.originalContent;
            }
            btnElement.disabled = false;
            btnElement.style.opacity = '1';
            btnElement.style.cursor = 'pointer';
        }
    }

    // Updated reminder functions with proper button handling
    async function sendReminder(contactId, contactName) {
        const btn = event.target.closest('.action-btn') || event.target;
        
        if (confirm(`Send payment reminder to ${contactName}?`)) {
            setButtonLoading(btn, true);
            
            try {
                // Simulate API call delay
                await new Promise(resolve => setTimeout(resolve, 1500));
                
                // Simulated success
                alert(`Reminder sent successfully to ${contactName}!`);
                
            } catch (error) {
                console.error('Error sending reminder:', error);
                alert('Error sending reminder: ' + error.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }
    }

    async function sendInvoiceReminder(invoiceId) {
        const btn = event.target.closest('.action-btn') || event.target;
        
        if (confirm('Send reminder for this specific invoice?')) {
            setButtonLoading(btn, true);
            
            try {
                // Simulate API call delay
                await new Promise(resolve => setTimeout(resolve, 1500));
                
                // Simulated success
                alert('Invoice reminder sent successfully!');
                
            } catch (error) {
                console.error('Error sending invoice reminder:', error);
                alert('Error sending reminder: ' + error.message);
            } finally {
                setButtonLoading(btn, false);
            }
        }
    }

    // Aging Report Functions - UPDATED
    function exportAgingReport() {
        const btn = event.target;
        setButtonLoading(btn, true);
        
        // Create a form to submit the export request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        // Add export flag
        const exportInput = document.createElement('input');
        exportInput.type = 'hidden';
        exportInput.name = 'export_aging_report';
        exportInput.value = '1';
        form.appendChild(exportInput);
        
        // Add CSRF token
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfInput);
        
        // Add current filters
        const typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'type';
        typeInput.value = '<?php echo $report_type ?? ''; ?>';
        form.appendChild(typeInput);
        
        const contactInput = document.createElement('input');
        contactInput.type = 'hidden';
        contactInput.name = 'contact_id';
        contactInput.value = '<?php echo $contact_id ?? ''; ?>';
        form.appendChild(contactInput);
        
        const dateInput = document.createElement('input');
        dateInput.type = 'hidden';
        dateInput.name = 'as_of_date';
        dateInput.value = '<?php echo $as_of_date; ?>';
        form.appendChild(dateInput);
        
        // Add to document and submit
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        // Reset button state after a short delay (in case submission fails)
        setTimeout(() => {
            setButtonLoading(btn, false);
        }, 3000);
    }

    function viewContactInvoices(contactId) {
        // Redirect to invoices page filtered by this contact
        window.location.href = `invoices.php?contact_id=${contactId}`;
    }

    function viewInvoice(invoiceId) {
        // Redirect to invoice details or scroll to invoice in invoices page
        window.location.href = `invoices.php#invoice-${invoiceId}`;
    }

    function recordPayment(invoiceId) {
        // Redirect to payment entry page with invoice pre-selected
        window.location.href = `payment_entry.php?invoice_id=${invoiceId}`;
    }

    // Print report function
    function printAgingReport() {
        window.print();
    }

    // Contact management functions
    function viewContactDetails(contactId) {
        window.location.href = `contact_details.php?id=${contactId}`;
    }

    function createNewInvoiceForContact(contactId) {
        window.location.href = `invoices.php?contact_id=${contactId}&create_new=1`;
    }
    </script>
</body>
</html>