<?php
// Common budget functions used across all pages
function getBudgetStatusBadge($status) {
    $statusClasses = [
        'Draft' => 'status-draft',
        'Submitted' => 'status-submitted', 
        'Under Review' => 'status-under-review',
        'Approved' => 'status-approved',
        'Rejected' => 'status-rejected'
    ];
    
    $class = $statusClasses[$status] ?? 'status-draft';
    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function calculateProposalTotal($db, $proposal_id) {
    $stmt = $db->prepare("SELECT SUM(total_cost) as total FROM budget_items WHERE proposal_id = ?");
    $stmt->execute([$proposal_id]);
    $result = $stmt->fetch();
    return $result['total'] ?? 0;
}

function updateProposalTotal($db, $proposal_id) {
    $total = calculateProposalTotal($db, $proposal_id);
    $stmt = $db->prepare("UPDATE budget_proposals SET total_amount = ? WHERE id = ?");
    return $stmt->execute([$total, $proposal_id]);
}

function getDepartmentName($db, $department_id) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
    $stmt->execute([$department_id]);
    $result = $stmt->fetch();
    return $result['name'] ?? 'Unknown Department';
}
?>