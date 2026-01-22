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

// Mark notification as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification_id]);
    exit;
}

// Mark all notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
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
        if (isset($_POST['entry_date']) && isset($_POST['entry_id']) && isset($_POST['description'])) {
            try {
                $db->beginTransaction();
                
                // Enhanced validation
                $entry_date = $_POST['entry_date'];
                $entry_id = trim($_POST['entry_id']);
                $description = trim($_POST['description']);
                
                // Validate entry date (not future date)
                if (strtotime($entry_date) > time()) {
                    throw new Exception("Entry date cannot be in the future.");
                }
                
                // Validate entry ID format (alphanumeric, dashes, underscores)
                if (!preg_match('/^[A-Za-z0-9\-_]+$/', $entry_id)) {
                    throw new Exception("Entry ID can only contain letters, numbers, hyphens, and underscores.");
                }
                
                // Check for duplicate entry ID
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE entry_id = ?");
                $check_stmt->execute([$entry_id]);
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception("Entry ID already exists. Please use a unique reference number.");
                }
                
                // Validate description length
                if (strlen($description) < 5) {
                    throw new Exception("Description must be at least 5 characters long.");
                }
                
                // Insert journal entry - always posted directly
                $stmt = $db->prepare("INSERT INTO journal_entries (entry_id, entry_date, description, status, created_by) 
                                     VALUES (?, ?, ?, 'Posted', ?)");
                $stmt->execute([$entry_id, $entry_date, $description, $user_id]);
                $journal_entry_id = $db->lastInsertId();
                
                // Insert journal entry lines
                $accounts = $_POST['accounts'] ?? [];
                $debits = $_POST['debits'] ?? [];
                $credits = $_POST['credits'] ?? [];
                
                $total_debit = 0;
                $total_credit = 0;
                $valid_lines = 0;
                
                for ($i = 0; $i < count($accounts); $i++) {
                    if (!empty($accounts[$i])) {
                        $account_id = $accounts[$i];
                        $debit = floatval($debits[$i] ?? 0);
                        $credit = floatval($credits[$i] ?? 0);
                        
                        // Validate at least one non-zero amount per line
                        if ($debit > 0 || $credit > 0) {
                            $valid_lines++;
                        }
                        
                        $total_debit += $debit;
                        $total_credit += $credit;
                        
                        $stmt = $db->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit) 
                                             VALUES (?, ?, ?, ?)");
                        $stmt->execute([$journal_entry_id, $account_id, $debit, $credit]);
                    }
                }
                
                // Validate exactly one entry line with non-zero amounts
                if ($valid_lines < 1) {
                    throw new Exception("Journal entry must have at least one valid line with non-zero amounts.");
                }
                
                // Check if balanced
                if (abs($total_debit - $total_credit) > 0.01) {
                    throw new Exception("Journal entry is not balanced. Debits: $total_debit, Credits: $total_credit");
                }
                
                // Create notification for new journal entry - FIXED VERSION
                try {
                    $notification_msg = "New journal entry created: " . $entry_id . " - " . $description;
                    // Check if notifications table has title column
                    $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'success')");
                    $notif_stmt->execute([$user_id, $notification_msg]);
                } catch (Exception $e) {
                    // If it fails, try without title
                    try {
                        $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        $notif_stmt->execute([$user_id, $notification_msg]);
                    } catch (Exception $e2) {
                        error_log("Notification creation failed: " . $e2->getMessage());
                    }
                }
                
                $db->commit();
                $success_message = "Journal entry created successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error creating journal entry: " . $e->getMessage();
            }
        }
    }
}

// Handle delete journal entry - MOVED OUTSIDE THE CREATE ENTRY BLOCK
if (isset($_POST['delete_entry'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid CSRF token.";
    } else {
        $entry_id = $_POST['entry_id'];
        try {
            $db->beginTransaction();
            
            // Get entry details for notification
            $entry_stmt = $db->prepare("SELECT entry_id, description FROM journal_entries WHERE id = ?");
            $entry_stmt->execute([$entry_id]);
            $entry = $entry_stmt->fetch();
            
            // Delete entry lines first
            $stmt = $db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ?");
            $stmt->execute([$entry_id]);
            
            // Delete journal entry
            $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ?");
            $stmt->execute([$entry_id]);
            
            // Create notification for journal entry deletion - FIXED VERSION
            if ($entry) {
                try {
                    $notification_msg = "Journal entry deleted: " . $entry['entry_id'] . " - " . $entry['description'];
                    // Check if notifications table has title column
                    $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'warning')");
                    $notif_stmt->execute([$user_id, $notification_msg]);
                } catch (Exception $e) {
                    // If it fails, try without title and type
                    try {
                        $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        $notif_stmt->execute([$user_id, $notification_msg]);
                    } catch (Exception $e2) {
                        error_log("Notification creation failed: " . $e2->getMessage());
                    }
                }
            }
            
            $db->commit();
            $success_message = "Journal entry deleted successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error deleting journal entry: " . $e->getMessage();
        }
    }
}

// Get journal entries data - only show Posted entries
function getJournalEntries(PDO $db): array {
    $sql = "SELECT je.* 
            FROM journal_entries je
            WHERE je.status = 'Posted'
            ORDER BY je.entry_date DESC, je.id DESC";
    return $db->query($sql)->fetchAll();
}

// Get chart of accounts for dropdown
function getChartOfAccounts(PDO $db): array {
    $sql = "SELECT id, account_code, account_name, account_type 
            FROM chart_of_accounts 
            WHERE status = 'Active'
            ORDER BY account_type, account_code";
    return $db->query($sql)->fetchAll();
}

$journal_entries = getJournalEntries($db);
$chart_accounts = getChartOfAccounts($db);

// Get journal entry lines for a specific entry
function getJournalEntryLines(PDO $db, $entry_id): array {
    $entry_id = (int)$entry_id; // Ensure it's an integer
    $sql = "SELECT jel.*, coa.account_code, coa.account_name, coa.account_type
            FROM journal_entry_lines jel
            LEFT JOIN chart_of_accounts coa ON jel.account_id = coa.id
            WHERE jel.journal_entry_id = ?
            ORDER BY jel.id";
    $stmt = $db->prepare($sql);
    $stmt->execute([$entry_id]);
    $result = $stmt->fetchAll();
    return $result ?: []; // Return empty array if no results
}

// Get financial summary for dashboard
function getFinancialSummary(PDO $db): array {
    $current_month = date('Y-m');
    $sql = "SELECT 
                coa.account_type,
                COALESCE(SUM(
                    CASE 
                        WHEN coa.account_type = 'Revenue' THEN jel.credit - jel.debit
                        WHEN coa.account_type = 'Expense' THEN jel.debit - jel.credit
                        ELSE 0 
                    END
                ), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id 
                AND je.status = 'Posted'
                AND DATE_FORMAT(je.entry_date, '%Y-%m') = ?
            WHERE coa.account_type IN ('Revenue', 'Expense')
            GROUP BY coa.account_type";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$current_month]);
    return $stmt->fetchAll();
}

// Call this function and add to your existing data
$financial_summary = getFinancialSummary($db);

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
    <title>Journal Entry - Financial Dashboard</title>
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
        /* Previous CSS styles remain the same */
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
            box-shadow: 0px 2px6px rgba(0,0,0,0.08);
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
            max-width: 800px;
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

        /* Journal entry specific styles */
        .entry-line {
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f9fafb;
        }
        
        .debit-amount {
            color: #10B981;
            font-weight: 600;
        }
        
        .credit-amount {
            color: #EF4444;
            font-weight: 600;
        }
        
        .balance-check {
            padding: 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
        }
        
        .balance-balanced {
            background-color: #D1FAE5;
            color: #065F46;
        }
        
        .balance-unbalanced {
            background-color: #FEE2E2;
            color: #DC2626;
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

        /* Notification styles - ADDED FROM REFERENCE */
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
    
    <!-- Modal for New Journal Entry -->
    <div id="new-entry-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Create New Journal Entry</h2>
            <form id="journal-entry-form" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="form-group">
                        <label class="form-label required-field">Entry Date</label>
                        <input type="date" name="entry_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required-field">Reference Number</label>
                        <input type="text" name="entry_id" class="form-input" placeholder="JE-2025-001" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required-field">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Enter journal entry description" required></textarea>
                </div>
                
                <div class="mb-6">
                    <h3 class="font-bold text-lg mb-4">Entry Line</h3>
                    <div id="entry-lines-container">
                        <!-- Single entry line -->
                        <div class="entry-line">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Account</label>
                                    <select name="accounts[]" class="form-input" required>
                                        <option value="">Select Account</option>
                                        <?php foreach ($chart_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>">
                                            <?php echo htmlspecialchars($account['account_code'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Debit</label>
                                    <input type="number" name="debits[]" class="form-input debit-input" placeholder="0.00" step="0.01" min="0" value="0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Credit</label>
                                    <input type="number" name="credits[]" class="form-input credit-input" placeholder="0.00" step="0.01" min="0" value="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end items-center mt-4">
                        <div id="balance-check" class="balance-check balance-unbalanced">
                            Debit: ₱0.00 | Credit: ₱0.00 | Difference: ₱0.00
                        </div>
                    </div>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" class="btn btn-secondary flex-1 close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary flex-1">Post Entry</button>
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
                            <div class="submenu active mt-1" id="ledger-submenu">
                                <a href="chart_of_accounts.php" class="submenu-item transition-colors duration-200">Chart of Accounts</a>
                                <a href="journal_entry.php" class="submenu-item active transition-colors duration-200">Journal Entry</a>
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
            <!-- Header - UPDATED WITH NOTIFICATION SYSTEM -->
            <div class="bg-primary-green text-white p-4 flex justify-between items-center">
                <div class="flex items-center">
                    <button id="hamburger-btn" class="mr-4">
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                        <div class="hamburger-line"></div>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Journal Entry</h1>
                        <p class="text-sm text-white/90">Record and manage journal entries</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <!-- Balance Toggle Button -->
                    <button id="visibility-toggle" class="relative p-2 transition duration-200 focus:outline-none" title="Toggle Amount Visibility">
                        <i class="fa-solid fa-eye-slash text-xl text-white"></i>
                    </button>
                    
                    <!-- Notification Bell - ADDED FROM REFERENCE -->
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

                <!-- Journal Entry Content -->
                <div class="bg-white rounded-xl p-6 card-shadow">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-dark-text">Journal Entries</h2>
                        <button class="btn btn-primary" onclick="document.getElementById('new-entry-modal').style.display='block'">
                            <i class='bx bx-plus mr-2'></i>New Entry
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Entry ID</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Debit Total</th>
                                    <th>Credit Total</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($journal_entries) > 0): ?>
                                    <?php foreach ($journal_entries as $entry): 
    $entry_lines = getJournalEntryLines($db, $entry['id']);
    $debit_total = array_sum(array_column($entry_lines, 'debit'));
    $credit_total = array_sum(array_column($entry_lines, 'credit'));
?>
                                    <tr>
                                        <td class="font-mono font-medium"><?php echo htmlspecialchars($entry['entry_id']); ?></td>
                                        <td><?php echo htmlspecialchars($entry['entry_date']); ?></td>
                                        <td class="max-w-xs"><?php echo htmlspecialchars($entry['description']); ?></td>
                                        <td class="debit-amount">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount font-semibold" 
                                                      data-value="₱<?php echo number_format((float)$debit_total, 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td class="credit-amount">
                                            <div class="amount-cell">
                                                <span class="amount-value hidden-amount font-semibold" 
                                                      data-value="₱<?php echo number_format((float)$credit_total, 2); ?>">
                                                    ********
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-approved">
                                                Posted
                                            </span>
                                        </td>
                                        <td>
    <div class="flex flex-wrap gap-2">
        <span class="text-gray-400 text-xs italic"></span>
    </div>
</td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-gray-500">
                                            No journal entries found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">Total Entries</p>
                                <p class="text-2xl font-bold text-dark-text"><?php echo count($journal_entries); ?></p>
                            </div>
                            <i class='bx bx-book text-3xl text-primary-green'></i>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-6 card-shadow">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">This Month</p>
                                <p class="text-2xl font-bold text-dark-text">
                                    <?php 
                                    $current_month = date('Y-m');
                                    $month_count = count(array_filter($journal_entries, fn($entry) => 
                                        substr($entry['entry_date'], 0, 7) === $current_month
                                    ));
                                    echo $month_count;
                                    ?>
                                </p>
                            </div>
                            <i class='bx bx-calendar text-3xl text-blue-600'></i>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-6 card-shadow">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-500">Total Amount</p>
            <div class="amount-cell">
                <span class="amount-value hidden-amount text-2xl font-bold text-dark-text" 
                      data-value="₱<?php 
                        $total_amount = 0;
                        foreach ($journal_entries as $entry) {
                            $entry_lines = getJournalEntryLines($db, $entry['id']);
                            $total_amount += array_sum(array_column($entry_lines, 'debit'));
                        }
                        echo number_format($total_amount, 2);
                      ?>">
                    ********
                </span>
            </div>
        </div>
        <i class='bx bx-money text-3xl text-green-600'></i>
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
        // Balance visibility toggle functionality
        let amountsVisible = false;
        
        // Global toggle function
        document.getElementById('visibility-toggle').addEventListener('click', function() {
            const toggleButtons = document.querySelectorAll('.visibility-toggle');
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

        // Notification functionality - ADDED FROM REFERENCE
        const notificationBtn = document.getElementById('notification-btn');
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationItems = document.querySelectorAll('.notification-item');
        const markAllReadBtn = document.getElementById('mark-all-read');
        
        // Toggle notification dropdown
        if (notificationBtn && notificationDropdown) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.style.display = 
                    notificationDropdown.style.display === 'block' ? 'none' : 'block';
            });
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            if (notificationDropdown) {
                notificationDropdown.style.display = 'none';
            }
        });
        
        // Mark notification as read when clicked
        notificationItems.forEach(item => {
            item.addEventListener('click', function() {
                const notificationId = this.getAttribute('data-id');
                if (!this.classList.contains('read')) {
                    // Mark as read via AJAX
                    const formData = new FormData();
                    formData.append('action', 'mark_notification_read');
                    formData.append('notification_id', notificationId);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => {
                        if (response.ok) {
                            this.classList.remove('unread');
                            this.classList.add('read');
                            this.querySelector('.bg-blue-500')?.remove();
                            
                            // Update notification count
                            updateNotificationCount();
                        }
                    });
                }
            });
        });
        
        // Mark all as read
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                
                const formData = new FormData();
                formData.append('action', 'mark_all_read');
                
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
                        markAllReadBtn.style.display = 'none';
                    }
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
            } else {
                if (!notificationBadge) {
                    // Create badge if it doesn't exist
                    const badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    notificationBtn.appendChild(badge);
                }
                document.querySelector('.notification-badge').textContent = unreadItems.length;
            }
        }

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
        const newEntryModal = document.getElementById('new-entry-modal');
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
                newEntryModal.style.display = 'none';
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                profileModal.style.display = 'none';
            }
            if (event.target === newEntryModal) {
                newEntryModal.style.display = 'none';
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

        // Update balance check
        function updateBalanceCheck() {
            let totalDebit = 0;
            let totalCredit = 0;
            
            // Get all debit and credit inputs
            const debitInputs = document.querySelectorAll('input[name="debits[]"]');
            const creditInputs = document.querySelectorAll('input[name="credits[]"]');
            
            // Calculate total debit
            debitInputs.forEach(input => {
                totalDebit += parseFloat(input.value) || 0;
            });
            
            // Calculate total credit
            creditInputs.forEach(input => {
                totalCredit += parseFloat(input.value) || 0;
            });
            
            const difference = Math.abs(totalDebit - totalCredit);
            const isBalanced = Math.abs(totalDebit - totalCredit) < 0.01; // Allow for floating point precision
            
            // Update balance check display
            const balanceCheck = document.getElementById('balance-check');
            if (balanceCheck) {
                balanceCheck.textContent = `Debit: ₱${totalDebit.toFixed(2)} | Credit: ₱${totalCredit.toFixed(2)} | Difference: ₱${difference.toFixed(2)}`;
                
                if (isBalanced) {
                    balanceCheck.className = 'balance-check balance-balanced';
                } else {
                    balanceCheck.className = 'balance-check balance-unbalanced';
                }
            }
        }

        // Initialize balance check for existing inputs
        const debitInputs = document.querySelectorAll('input[name="debits[]"]');
        const creditInputs = document.querySelectorAll('input[name="credits[]"]');
        
        debitInputs.forEach(input => {
            input.addEventListener('input', updateBalanceCheck);
        });
        
        creditInputs.forEach(input => {
            input.addEventListener('input', updateBalanceCheck);
        });
        
        // Initial balance check
        updateBalanceCheck();

        // Enhanced form validation
        function validateJournalEntry() {
            const entryId = document.querySelector('input[name="entry_id"]');
            const description = document.querySelector('textarea[name="description"]');
            const entryDate = document.querySelector('input[name="entry_date"]');
            
            // Clear previous errors
            clearValidationErrors();
            
            let isValid = true;
            
            // Entry ID validation
            if (entryId && !/^[A-Za-z0-9\-_]+$/.test(entryId.value)) {
                showValidationError(entryId, 'Only letters, numbers, hyphens, and underscores allowed');
                isValid = false;
            }
            
            // Description validation
            if (description && description.value.trim().length < 5) {
                showValidationError(description, 'Description must be at least 5 characters');
                isValid = false;
            }
            
            // Date validation (not future)
            if (entryDate && new Date(entryDate.value) > new Date()) {
                showValidationError(entryDate, 'Entry date cannot be in the future');
                isValid = false;
            }
            
            // Line validation - at least one line with non-zero amounts
            const accounts = document.querySelectorAll('select[name="accounts[]"]');
            let validLines = 0;
            
            accounts.forEach((account, index) => {
                const debit = parseFloat(document.querySelectorAll('input[name="debits[]"]')[index].value) || 0;
                const credit = parseFloat(document.querySelectorAll('input[name="credits[]"]')[index].value) || 0;
                
                if (account.value && (debit > 0 || credit > 0)) {
                    validLines++;
                }
            });
            
            if (validLines < 1) {
                alert('Journal entry must have at least one valid line with non-zero amounts.');
                isValid = false;
            }
            
            return isValid;
        }

        function showValidationError(element, message) {
            // Remove existing error
            const existingError = element.parentNode.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }
            
            // Add error styling
            element.classList.add('error');
            
            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error';
            errorDiv.textContent = message;
            element.parentNode.appendChild(errorDiv);
            
            // Focus on the problematic field
            element.focus();
        }

        function clearValidationErrors() {
            document.querySelectorAll('.form-input').forEach(input => {
                input.classList.remove('error');
            });
            document.querySelectorAll('.validation-error').forEach(error => {
                error.remove();
            });
        }

        // Real-time validation for key fields
        const entryIdInput = document.querySelector('input[name="entry_id"]');
        const descriptionInput = document.querySelector('textarea[name="description"]');
        
        if (entryIdInput) {
            entryIdInput.addEventListener('blur', function() {
                if (!/^[A-Za-z0-9\-_]+$/.test(this.value)) {
                    showValidationError(this, 'Only letters, numbers, hyphens, and underscores allowed');
                } else {
                    clearValidationError(this);
                }
            });
        }
        
        if (descriptionInput) {
            descriptionInput.addEventListener('blur', function() {
                if (this.value.trim().length < 5) {
                    showValidationError(this, 'Description must be at least 5 characters');
                } else {
                    clearValidationError(this);
                }
            });
        }

        function clearValidationError(element) {
            element.classList.remove('error');
            const existingError = element.parentNode.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }
        }

        // Journal entry form submission
        const journalEntryForm = document.getElementById('journal-entry-form');
        if (journalEntryForm) {
            journalEntryForm.addEventListener('submit', function(e) {
                if (!validateJournalEntry()) {
                    e.preventDefault();
                    return;
                }
                
                // Existing balance validation
                const totalDebit = Array.from(document.querySelectorAll('input[name="debits[]"]'))
                    .reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const totalCredit = Array.from(document.querySelectorAll('input[name="credits[]"]'))
                    .reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                
                if (Math.abs(totalDebit - totalCredit) > 0.01) {
                    e.preventDefault();
                    alert('Journal entry must be balanced! Debits must equal credits.');
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div>Processing...';
                submitBtn.disabled = true;
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

    // Individual amount toggle function (for individual eye buttons)
    function toggleAmountVisibility(button) {
        const amountSpan = button.parentElement.querySelector('.amount-value');
        const icon = button.querySelector('i');
        
        if (amountSpan.classList.contains('hidden-amount')) {
            // Show amount
            const actualAmount = amountSpan.getAttribute('data-value');
            amountSpan.textContent = actualAmount;
            amountSpan.classList.remove('hidden-amount');
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        } else {
            // Hide amount
            amountSpan.textContent = '********';
            amountSpan.classList.add('hidden-amount');
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    }
</script>
</body>
</html>