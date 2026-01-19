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
        header("Location: login.php");
        exit;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// Initialize data arrays
$budget_proposals = [];
$departments = [];
$fiscal_years = [];
$categories = [];
$notifications = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_proposal'])) {
            handleCreateProposal($db, $user_id);
        } elseif (isset($_POST['update_proposal'])) {
            handleUpdateProposal($db, $user_id);
        } elseif (isset($_POST['submit_for_approval'])) {
            handleSubmitForApproval($db, $user_id);
        } elseif (isset($_POST['add_budget_item'])) {
            handleAddBudgetItem($db, $user_id);
        } elseif (isset($_POST['delete_budget_item'])) {
            handleDeleteBudgetItem($db, $user_id);
        } elseif (isset($_POST['delete_proposal'])) {
            handleDeleteProposal($db, $user_id);
        } elseif (isset($_POST['revise_proposal'])) {
            handleReviseProposal($db, $user_id);
        }
    } catch (Exception $e) {
        error_log("Budget proposal error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Load data for dropdowns and listings
loadData($db, $user_id, $budget_proposals, $departments, $fiscal_years, $categories);

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}

// ========== HELPER FUNCTIONS ==========

function safe_output($value, $default = '') {
    if ($value === null) {
        return $default;
    }
    return htmlspecialchars((string)$value);
}

function safe_number_format($value, $decimals = 2, $default = '0.00') {
    if ($value === null || $value === '') {
        return $default;
    }
    
    $float_value = (float)$value;
    
    if (!is_numeric($float_value)) {
        return $default;
    }
    
    if (isset($_SESSION['hide_numbers']) && $_SESSION['hide_numbers']) {
        return str_repeat('*', 6);
    }
    
    return number_format($float_value, $decimals);
}

function format_amount($value, $decimals = 2) {
    if (!isset($_SESSION['hide_numbers']) || !$_SESSION['hide_numbers']) {
        return '₱' . number_format((float)$value, $decimals);
    } else {
        return '₱' . str_repeat('*', 6);
    }
}

function getNotifications(PDO $db, int $user_id): array {
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE (user_id = ? OR user_id IS NULL) 
            AND (is_read = 0 OR is_read IS NULL)
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
        return [];
    }
}

function handleCreateProposal(PDO $db, $user_id): void {
    $required = ['title', 'department', 'fiscal_year'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    $title = trim($_POST['title']);
    $department = (int)$_POST['department'];
    $fiscal_year = trim($_POST['fiscal_year']);
    $description = trim($_POST['description'] ?? '');
    
    if (empty($title)) {
        throw new Exception("Proposal title is required");
    }
    
    $stmt = $db->prepare("
        INSERT INTO budget_proposals 
        (title, department, fiscal_year, submitted_by, status, description) 
        VALUES (?, ?, ?, ?, 'Draft', ?)
    ");
    $stmt->execute([$title, $department, $fiscal_year, $user_id, $description]);
    $proposal_id = $db->lastInsertId();
    
    // Handle budget items if provided during creation
    if (isset($_POST['budget_items']) && is_array($_POST['budget_items'])) {
        foreach ($_POST['budget_items'] as $item) {
            if (!empty($item['description']) && $item['quantity'] > 0 && $item['unit_cost'] >= 0) {
                $category = (int)$item['category'];
                $item_description = trim($item['description']);
                $quantity = (int)$item['quantity'];
                $unit_cost = (float)$item['unit_cost'];
                $justification = trim($item['justification'] ?? '');
                $total_cost = $quantity * $unit_cost;

                $insert_stmt = $db->prepare("
                    INSERT INTO budget_items 
                    (proposal_id, category, description, quantity, unit_cost, total_cost, justification) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_stmt->execute([
                    $proposal_id, $category, $item_description, $quantity, 
                    $unit_cost, $total_cost, $justification
                ]);
            }
        }
    }
    
    $_SESSION['success_message'] = "Budget proposal created successfully!";
    
    // Redirect to main page instead of edit page
    header("Location: budget_proposal.php");
    exit;
}

function handleUpdateProposal(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description'] ?? '');
    
    $stmt = $db->prepare("
        UPDATE budget_proposals 
        SET title = ?, description = ?, updated_at = NOW()
        WHERE id = ? AND submitted_by = ?
    ");
    $stmt->execute([$title, $description, $proposal_id, $user_id]);
    
    $_SESSION['success_message'] = "Budget proposal updated successfully!";
    header("Location: budget_proposal.php");
    exit;
}

function handleReviseProposal(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    
    // Verify the proposal belongs to the user and is in Rejected status
    $verify_stmt = $db->prepare("SELECT id FROM budget_proposals WHERE id = ? AND submitted_by = ? AND status = 'Rejected'");
    $verify_stmt->execute([$proposal_id, $user_id]);
    $proposal = $verify_stmt->fetch();
    
    if (!$proposal) {
        throw new Exception("Proposal not found, not rejected, or you don't have permission to revise it");
    }
    
    // Update proposal status back to Draft
    $stmt = $db->prepare("
        UPDATE budget_proposals 
        SET status = 'Draft', updated_at = NOW()
        WHERE id = ? AND submitted_by = ?
    ");
    $stmt->execute([$proposal_id, $user_id]);
    
    $_SESSION['success_message'] = "Proposal revised successfully! You can now edit it.";
    header("Location: budget_proposal.php");
    exit;
}

function handleSubmitForApproval(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    
    // Check if proposal has budget items
    $check_items = $db->prepare("SELECT COUNT(*) as item_count FROM budget_items WHERE proposal_id = ?");
    $check_items->execute([$proposal_id]);
    $item_count = $check_items->fetch()['item_count'];
    
    if ($item_count === 0) {
        throw new Exception("Cannot submit proposal without budget items.");
    }
    
    // Verify the proposal belongs to the user and is in Draft status
    $verify_stmt = $db->prepare("SELECT id, department, title FROM budget_proposals WHERE id = ? AND submitted_by = ? AND status = 'Draft'");
    $verify_stmt->execute([$proposal_id, $user_id]);
    $proposal = $verify_stmt->fetch();
    
    if (!$proposal) {
        throw new Exception("Proposal not found or you don't have permission to submit it");
    }
    
    // Update proposal status
    $stmt = $db->prepare("
        UPDATE budget_proposals 
        SET status = 'Submitted', submitted_date = NOW(), updated_at = NOW()
        WHERE id = ? AND submitted_by = ?
    ");
    $stmt->execute([$proposal_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Failed to submit proposal.");
    }
    
    $_SESSION['success_message'] = "Budget proposal submitted for approval!";
    header("Location: budget_proposal.php");
    exit;
}

function handleAddBudgetItem(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    $category = (int)$_POST['category'];
    $item_description = trim($_POST['item_description'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $unit_cost = (float)($_POST['unit_cost'] ?? 0);
    $justification = trim($_POST['justification'] ?? '');
    
    if (empty($item_description)) {
        throw new Exception("Item description is required");
    }

    if ($quantity <= 0) {
        throw new Exception("Quantity must be greater than 0");
    }

    if ($unit_cost < 0) {
        throw new Exception("Unit cost cannot be negative");
    }

    $total_cost = $quantity * $unit_cost;

    $insert_stmt = $db->prepare("
        INSERT INTO budget_items 
        (proposal_id, category, description, quantity, unit_cost, total_cost, justification) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert_stmt->execute([
        $proposal_id, $category, $item_description, $quantity, 
        $unit_cost, $total_cost, $justification
    ]);
    
    $_SESSION['success_message'] = "Budget item added successfully!";
    header("Location: budget_proposal.php");
    exit;
}

function handleDeleteBudgetItem(PDO $db, $user_id): void {
    $item_id = (int)$_POST['item_id'];
    $proposal_id = (int)$_POST['proposal_id'];
    
    $delete_stmt = $db->prepare("DELETE FROM budget_items WHERE id = ? AND proposal_id = ?");
    $delete_stmt->execute([$item_id, $proposal_id]);
    
    $_SESSION['success_message'] = "Budget item deleted successfully!";
    header("Location: budget_proposal.php");
    exit;
}

function handleDeleteProposal(PDO $db, $user_id): void {
    if (empty($_POST['proposal_id'])) {
        throw new Exception("Proposal ID is required");
    }
    
    $proposal_id = (int)$_POST['proposal_id'];
    
    // Verify the proposal belongs to the user
    $verify_stmt = $db->prepare("SELECT id FROM budget_proposals WHERE id = ? AND submitted_by = ?");
    $verify_stmt->execute([$proposal_id, $user_id]);
    $proposal = $verify_stmt->fetch();
    
    if (!$proposal) {
        throw new Exception("Proposal not found or you don't have permission to delete it");
    }
    
    // Delete budget items first
    $delete_items = $db->prepare("DELETE FROM budget_items WHERE proposal_id = ?");
    $delete_items->execute([$proposal_id]);
    
    // Delete the proposal
    $delete_stmt = $db->prepare("DELETE FROM budget_proposals WHERE id = ? AND submitted_by = ?");
    $delete_stmt->execute([$proposal_id, $user_id]);
    
    $_SESSION['success_message'] = "Budget proposal deleted successfully!";
    header("Location: budget_proposal.php");
    exit;
}

function loadData(PDO $db, $user_id, &$budget_proposals, &$departments, &$fiscal_years, &$categories): void {
    try {
        // Get budget proposals
        $proposal_stmt = $db->prepare("
            SELECT bp.*, u.name as submitter_name, 
                   d.name as department_name,
                   COUNT(bi.id) as item_count,
                   COALESCE(SUM(bi.total_cost), 0) as calculated_total
            FROM budget_proposals bp
            LEFT JOIN users u ON bp.submitted_by = u.id
            LEFT JOIN departments d ON bp.department = d.id
            LEFT JOIN budget_items bi ON bp.id = bi.proposal_id
            WHERE bp.submitted_by = ?
            GROUP BY bp.id
            ORDER BY bp.created_at DESC
        ");
        $proposal_stmt->execute([$user_id]);
        $budget_proposals = $proposal_stmt->fetchAll();

        // Get departments
        $dept_stmt = $db->query("SELECT id, name FROM departments WHERE status = 'Active' ORDER BY name");
        $departments = $dept_stmt->fetchAll();

        // Get fiscal years
        $year_stmt = $db->query("SELECT DISTINCT fiscal_year FROM fiscal_years WHERE status = 'Active' ORDER BY fiscal_year DESC");
        $fiscal_years = $year_stmt->fetchAll();
        if (empty($fiscal_years)) {
            $fiscal_years = [['fiscal_year' => date('Y')]];
        }

        // Get budget categories
        $cat_stmt = $db->query("SELECT id, name, type FROM budget_categories WHERE status = 'Active' ORDER BY type, name");
        $categories = $cat_stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Data load error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading data: " . $e->getMessage();
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
    <title>Budget Proposal | Financial Dashboard</title>
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
        .status-draft {
            background-color: rgba(156, 163, 175, 0.1);
            color: #6B7280;
        }
        .status-submitted {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3B82F6;
        }
        .status-approved {
            background-color: rgba(34, 197, 94, 0.1);
            color: #16A34A;
        }
        .status-rejected {
            background-color: rgba(239, 68, 68, 0.1);
            color: #DC2626;
        }
        .status-under-review {
            background-color: rgba(245, 158, 11, 0.1);
            color: #D97706;
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .edit-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .edit-modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 20px;
            border-radius: 8px;
            width: 95%;
            max-width: 1200px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 10px;
        }
        
        .close-modal:hover {
            color: black;
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
        
        .action-btn.edit {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
        }
        
        .action-btn.edit:hover {
            background-color: #FDE68A;
            color: #B45309;
        }
        
        .action-btn.success {
            background-color: #D1FAE5;
            color: #059669;
            border-color: #059669;
        }
        
        .action-btn.success:hover {
            background-color: #A7F3D0;
            color: #047857;
        }
        
        .action-btn.view {
            background-color: #EFF6FF;
            color: #3B82F6;
            border-color: #3B82F6;
        }
        
        .action-btn.view:hover {
            background-color: #DBEAFE;
            color: #2563EB;
        }
        
        .action-btn.danger {
            background-color: #FEE2E2;
            color: #DC2626;
            border-color: #DC2626;
        }
        
        .action-btn.danger:hover {
            background-color: #FECACA;
            color: #B91C1C;
        }
        
        .action-btn.warning {
            background-color: #FEF3C7;
            color: #D97706;
            border-color: #D97706;
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

        .budget-item-row {
            transition: background-color 0.2s;
        }
        
        .budget-item-row:hover {
            background-color: #f9fafb;
        }
        
        .budget-items-container {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .stat-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 0.5rem;
            background: white;
            box-shadow: 0px 2px 6px rgba(0,0,0,0.08);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .budget-item-form {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f9fafb;
        }
        
        .remove-item-btn {
            background-color: #ef4444;
            color: white;
            border: none;
            border-radius: 0.375rem;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .remove-item-btn:hover {
            background-color: #dc2626;
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
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Notifications</h2>
            <div id="notification-list">
                <div class="text-center text-gray-500 py-4">Loading notifications...</div>
            </div>
        </div>
    </div>

    <!-- Modal for creating new budget proposal -->
    <div id="create-proposal-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 class="text-xl font-bold mb-4">Create New Budget Proposal</h2>
            <form method="POST" id="create-proposal-form">
                <div class="form-group">
                    <label class="form-label">Proposal Title*</label>
                    <input type="text" name="title" class="form-input" required 
                           placeholder="e.g., Q3 Marketing Campaign Budget">
                </div>
                <div class="form-group">
                    <label class="form-label">Department*</label>
                    <select name="department" class="form-select" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= safe_output($dept['id']) ?>"><?= safe_output($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Fiscal Year*</label>
                    <select name="fiscal_year" class="form-select" required>
                        <option value="">Select Fiscal Year</option>
                        <?php foreach ($fiscal_years as $year): ?>
                            <option value="<?= safe_output($year['fiscal_year']) ?>"><?= safe_output($year['fiscal_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" rows="3" 
                              placeholder="Enter detailed proposal description (optional)"></textarea>
                </div>
                
                <!-- Budget Items Section -->
                <div class="mt-6">
                    <h3 class="text-lg font-semibold mb-4">Budget Items</h3>
                    <div id="budget-items-container">
                        <!-- Budget items will be added here dynamically -->
                    </div>
                    <button type="button" id="add-budget-item-btn" class="btn btn-secondary mb-4">
                        <i class="fas fa-plus mr-2"></i>Add Budget Item
                    </button>
                </div>
                
                <div class="flex space-x-2 mt-6">
                    <button type="button" class="btn btn-secondary flex-1" onclick="closeModal('create-proposal-modal')">Cancel</button>
                    <button type="submit" name="create_proposal" class="btn btn-primary flex-1">Create Proposal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Proposal Modal -->
    <div id="edit-proposal-modal" class="edit-modal">
        <div class="edit-modal-content">
            <span class="close-modal">&times;</span>
            <div id="edit-modal-content">
                <!-- Content will be loaded dynamically via JavaScript -->
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
                                <a href="budget_proposal.php" class="submenu-item active transition-colors duration-200">Budget Proposal</a>
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
                        <h1 class="text-2xl font-bold text-white">Budget Proposal Management</h1>
                        <p class="text-sm text-white/90">Create, manage, and track budget proposals</p>
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

                <!-- Quick Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card">
                        <div class="stat-value text-primary-green"><?= count($budget_proposals) ?></div>
                        <div class="stat-label">Total Proposals</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-blue-600">
                            <?= count(array_filter($budget_proposals, fn($p) => $p['status'] === 'Draft')) ?>
                        </div>
                        <div class="stat-label">Draft Proposals</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-orange-600">
                            <?= count(array_filter($budget_proposals, fn($p) => $p['status'] === 'Submitted')) ?>
                        </div>
                        <div class="stat-label">Submitted</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value text-green-600">
                            <?= count(array_filter($budget_proposals, fn($p) => $p['status'] === 'Approved')) ?>
                        </div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold">Budget Proposals</h2>
                    <div class="flex space-x-2">
                        <button class="btn btn-secondary" onclick="printProposals()">
                            <i class="fa-solid fa-print mr-2"></i>Print
                        </button>
                        <button class="btn btn-secondary" onclick="exportToExcel()">
                            <i class="fa-solid fa-file-excel mr-2"></i>Export
                        </button>
                        <button class="btn btn-primary" onclick="openModal('create-proposal-modal')">
                            <i class="fa-solid fa-plus mr-2"></i>New Proposal
                        </button>
                    </div>
                </div>

                <!-- Proposals Table -->
                <div class="metric-card">
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Department</th>
                                    <th>Fiscal Year</th>
                                    <th>Status</th>
                                    <th>Total Amount</th>
                                    <th>Items</th>
                                    <th>Created Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($budget_proposals)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-gray-500">
                                            No budget proposals found. <button class="text-primary-green hover:underline" onclick="openModal('create-proposal-modal')">Create your first proposal</button>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($budget_proposals as $proposal): ?>
                                        <tr class="budget-item-row">
                                            <td>
                                                <div class="font-semibold"><?= safe_output($proposal['title'] ?? 'Untitled Proposal') ?></div>
                                                <?php if (!empty($proposal['description'])): ?>
                                                    <div class="text-sm text-gray-500"><?= safe_output(substr($proposal['description'], 0, 50)) . (strlen($proposal['description']) > 50 ? '...' : '') ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= safe_output($proposal['department_name'] ?? $proposal['department']) ?></td>
                                            <td><?= safe_output($proposal['fiscal_year']) ?></td>
                                            <td>
                                                <span class="status-badge status-<?= strtolower($proposal['status']) ?>">
                                                    <?= safe_output($proposal['status']) ?>
                                                </span>
                                            </td>
                                            <td class="font-semibold <?= $_SESSION['hide_numbers'] ? 'amount-masked' : '' ?>">
                                                <?php 
                                                $calculated_total = (float)($proposal['calculated_total'] ?? 0);
                                                echo $calculated_total > 0 ? format_amount($calculated_total) : '<span class="text-gray-400 text-sm">No items yet</span>';
                                                ?>
                                            </td>
                                            <td><?= $proposal['item_count'] ?></td>
                                            <td><?= date('M j, Y', strtotime($proposal['created_at'])) ?></td>
                                            <td class="space-x-1">
    <?php if ($proposal['status'] === 'Draft'): ?>
        <?php if ($proposal['item_count'] > 0): ?>
            <form method="POST" class="inline">
                <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                <button type="submit" name="submit_for_approval" class="action-btn success" 
                        onclick="return confirm('Submit this proposal for approval?')">
                    <i class="fa-solid fa-paper-plane mr-1"></i>Submit
                </button>
            </form>
        <?php endif; ?>
    <?php elseif ($proposal['status'] === 'Rejected'): ?>
        <button class="action-btn warning" onclick="openEditModal(<?= $proposal['id'] ?>)">
            <i class="fa-solid fa-edit mr-1"></i>Revise
        </button>
        <a href="approval_workflow.php?proposal_id=<?= $proposal['id'] ?>" class="action-btn view">
            <i class="fa-solid fa-eye mr-1"></i>View
        </a>
    <?php else: ?>
        <a href="approval_workflow.php?proposal_id=<?= $proposal['id'] ?>" class="action-btn view">
            <i class="fa-solid fa-eye mr-1"></i>View
        </a>
    <?php endif; ?>
</td>
                                        </tr>
                                    <?php endforeach; ?>
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

            // Budget items functionality for create proposal modal
            const addBudgetItemBtn = document.getElementById('add-budget-item-btn');
            const budgetItemsContainer = document.getElementById('budget-items-container');
            let budgetItemCount = 0;

            if (addBudgetItemBtn && budgetItemsContainer) {
                addBudgetItemBtn.addEventListener('click', function() {
                    addBudgetItem();
                });

                // Add first budget item by default
                addBudgetItem();
            }

            function addBudgetItem() {
                budgetItemCount++;
                const itemId = `budget_item_${budgetItemCount}`;
                
                const budgetItemHtml = `
                    <div class="budget-item-form" id="${itemId}">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="font-medium">Budget Item #${budgetItemCount}</h4>
                            <button type="button" class="remove-item-btn" onclick="removeBudgetItem('${itemId}')">
                                <i class="fas fa-times mr-1"></i>Remove
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-3">
                            <div class="form-group">
                                <label class="form-label">Category*</label>
                                <select name="budget_items[${budgetItemCount}][category]" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= safe_output($category['id']) ?>">
                                            <?= safe_output($category['name']) ?> (<?= safe_output($category['type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description*</label>
                                <input type="text" name="budget_items[${budgetItemCount}][description]" class="form-input" required placeholder="Item description">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Quantity*</label>
                                <input type="number" name="budget_items[${budgetItemCount}][quantity]" class="form-input" value="1" min="1" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unit Cost (₱)*</label>
                                <input type="number" name="budget_items[${budgetItemCount}][unit_cost]" class="form-input" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Justification</label>
                            <textarea name="budget_items[${budgetItemCount}][justification]" class="form-textarea" rows="2" placeholder="Why is this item needed?"></textarea>
                        </div>
                    </div>
                `;
                
                budgetItemsContainer.insertAdjacentHTML('beforeend', budgetItemHtml);
            }

            // Load edit modal content when opening
            window.openEditModal = function(proposalId) {
                console.log('Opening edit modal for proposal:', proposalId);
                
                // Show loading state
                document.getElementById('edit-modal-content').innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-spinner fa-spin text-2xl text-primary-green mb-2"></i>
                        <p>Loading proposal details...</p>
                    </div>
                `;
                
                // Open the modal
                document.getElementById('edit-proposal-modal').style.display = 'block';
                
                // Load the edit form via AJAX
                fetch(`budget_proposal_edit.php?proposal_id=${proposalId}`)
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('edit-modal-content').innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error loading edit form:', error);
                        document.getElementById('edit-modal-content').innerHTML = `
                            <div class="text-center py-8 text-red-600">
                                <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                                <p>Error loading proposal details. Please try again.</p>
                            </div>
                        `;
                    });
            };

            // Load notifications
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
                        notificationList.innerHTML = '<div class="text-center text-gray-500 py-4">No new notifications</div>';
                    } else {
                        notifications.forEach(notification => {
                            const notificationEl = document.createElement('div');
                            notificationEl.className = 'p-3 border-b border-gray-200';
                            notificationEl.innerHTML = `
                                <div class="font-medium">${notification.message || 'Notification'}</div>
                                <div class="text-sm text-gray-500 mt-1">${new Date(notification.created_at).toLocaleDateString()}</div>
                            `;
                            notificationList.appendChild(notificationEl);
                        });
                    }
                }
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

        // Global functions
        function removeBudgetItem(itemId) {
            const itemElement = document.getElementById(itemId);
            if (itemElement) {
                itemElement.remove();
            }
        }

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

        function deleteProposal(proposalId) {
            if (confirm('Are you sure you want to delete this proposal?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="proposal_id" value="${proposalId}">
                    <input type="hidden" name="delete_proposal" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function printProposals() {
            window.print();
        }
        
        function exportToExcel() {
            alert('Excel export functionality would be implemented here');
        }
    </script>
</body>
</html>