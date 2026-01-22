<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/database.php';

if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$proposal_id = $_GET['id'] ?? null;

if (!$proposal_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Proposal ID required']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get proposal details
    $proposal_stmt = $db->prepare("
        SELECT bp.*, d.name as department_name,
               COUNT(bi.id) as item_count,
               COALESCE(SUM(bi.total_cost), 0) as calculated_total
        FROM budget_proposals bp
        LEFT JOIN departments d ON bp.department = d.id
        LEFT JOIN budget_items bi ON bp.id = bi.proposal_id
        WHERE bp.id = ? AND bp.submitted_by = ?
        GROUP BY bp.id
    ");
    $proposal_stmt->execute([$proposal_id, $user_id]);
    $proposal = $proposal_stmt->fetch();
    
    if (!$proposal) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Proposal not found']);
        exit;
    }
    
    // Get budget items
    $items_stmt = $db->prepare("
        SELECT bi.*, bc.name as category_name 
        FROM budget_items bi
        LEFT JOIN budget_categories bc ON bi.category = bc.id
        WHERE bi.proposal_id = ?
        ORDER BY bi.created_at DESC
    ");
    $items_stmt->execute([$proposal_id]);
    $items = $items_stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'proposal' => $proposal,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}