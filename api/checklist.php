<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function checklistRespond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode($data);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    checklistRespond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    checklistRespond(400, [
        'success' => false,
        'message' => 'Invalid request.'
    ]);
}

$token = $input['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    checklistRespond(403, [
        'success' => false,
        'message' => 'Security token invalid. Refresh the page.'
    ]);
}

$itemId = filter_var(
    $input['item_id'] ?? null,
    FILTER_VALIDATE_INT
);

$completed = filter_var(
    $input['completed'] ?? null,
    FILTER_VALIDATE_INT
);

$notes = trim($input['notes'] ?? '');

if (
    !$itemId ||
    !in_array($completed, [0, 1], true) ||
    strlen($notes) > 500
) {
    checklistRespond(422, [
        'success' => false,
        'message' => 'Invalid checklist information.'
    ]);
}

try {
    $stmt = $pdo->prepare(
        "SELECT id
         FROM checklist_items
         WHERE id = :id AND is_active = 1"
    );

    $stmt->execute(['id' => $itemId]);

    if (!$stmt->fetch()) {
        checklistRespond(404, [
            'success' => false,
            'message' => 'Checklist item not found.'
        ]);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO checklist_logs (
            item_id,
            user_id,
            checklist_date,
            completed,
            notes,
            completed_at
        )
        VALUES (
            :item_id,
            :user_id,
            CURDATE(),
            :completed,
            :notes,
            CASE
                WHEN :completed_at = 1 THEN NOW()
                ELSE NULL
            END
        )
        ON DUPLICATE KEY UPDATE
            completed = VALUES(completed),
            notes = VALUES(notes),
            completed_at = CASE
                WHEN VALUES(completed) = 1
                    THEN COALESCE(completed_at, NOW())
                ELSE NULL
            END"
    );

    $stmt->execute([
        'item_id' => $itemId,
        'user_id' => $_SESSION['user_id'],
        'completed' => $completed,
        'notes' => $notes,
        'completed_at' => $completed
    ]);

    checklistRespond(200, [
        'success' => true,
        'message' => $completed
            ? 'Checklist item completed.'
            : 'Checklist item marked incomplete.'
    ]);

} catch (PDOException $e) {

    error_log('Checklist API error: ' . $e->getMessage());

    checklistRespond(500, [
        'success' => false,
        'message' => 'Unable to save checklist update.'
    ]);
}