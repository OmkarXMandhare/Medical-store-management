<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function taskApiResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// Accept POST requests only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    taskApiResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

// Confirm the employee is logged in.
$employeeId = (int) (
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);

if ($employeeId <= 0) {
    taskApiResponse(401, [
        'success' => false,
        'message' => 'Your session has expired. Please log in again.'
    ]);
}

// Validate CSRF token.
$sessionToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (
    !is_string($sessionToken) ||
    !is_string($submittedToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    taskApiResponse(403, [
        'success' => false,
        'message' => 'Security token invalid. Refresh the page and try again.'
    ]);
}

// Read JSON submitted by the browser.
$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    taskApiResponse(400, [
        'success' => false,
        'message' => 'Invalid request data.'
    ]);
}

$taskId = filter_var(
    $input['task_id'] ?? null,
    FILTER_VALIDATE_INT
);

$action = $input['action'] ?? '';

if (!$taskId || $taskId < 1) {
    taskApiResponse(422, [
        'success' => false,
        'message' => 'Invalid task ID.'
    ]);
}

if (!in_array($action, ['start', 'complete'], true)) {
    taskApiResponse(422, [
        'success' => false,
        'message' => 'Invalid task action.'
    ]);
}

$completionNote = trim(
    (string) ($input['completion_note'] ?? '')
);

if (mb_strlen($completionNote) > 500) {
    taskApiResponse(422, [
        'success' => false,
        'message' => 'Completion notes must be 500 characters or fewer.'
    ]);
}

try {
    // Load the task only if it belongs to this employee.
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM assigned_tasks
        WHERE id = :task_id
          AND assigned_to = :employee_id
        LIMIT 1
    ");

    $stmt->execute([
        'task_id' => $taskId,
        'employee_id' => $employeeId
    ]);

    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        taskApiResponse(404, [
            'success' => false,
            'message' => 'Task not found or not assigned to your account.'
        ]);
    }

    $currentStatus = $task['status'];

    if ($action === 'start') {
        // Only pending tasks can be started.
        if ($currentStatus !== 'pending') {
            taskApiResponse(409, [
                'success' => false,
                'message' => 'Only pending tasks can be started.'
            ]);
        }

        $update = $pdo->prepare("
            UPDATE assigned_tasks
            SET status = 'in_progress'
            WHERE id = :task_id
              AND assigned_to = :employee_id
              AND status = 'pending'
        ");

        $update->execute([
            'task_id' => $taskId,
            'employee_id' => $employeeId
        ]);

        if ($update->rowCount() !== 1) {
            taskApiResponse(409, [
                'success' => false,
                'message' => 'The task status changed. Refresh the page.'
            ]);
        }

        taskApiResponse(200, [
            'success' => true,
            'message' => 'Task started successfully.'
        ]);
    }

    // Complete action.
    if ($currentStatus !== 'in_progress') {
        taskApiResponse(409, [
            'success' => false,
            'message' => 'Start the task before marking it completed.'
        ]);
    }

    $update = $pdo->prepare("
        UPDATE assigned_tasks
        SET
            status = 'completed',
            completion_note = :completion_note,
            completed_at = NOW()
        WHERE id = :task_id
          AND assigned_to = :employee_id
          AND status = 'in_progress'
    ");

    $update->execute([
        'completion_note' => $completionNote !== ''
            ? $completionNote
            : null,
        'task_id' => $taskId,
        'employee_id' => $employeeId
    ]);

    if ($update->rowCount() !== 1) {
        taskApiResponse(409, [
            'success' => false,
            'message' => 'The task status changed. Refresh the page.'
        ]);
    }

    taskApiResponse(200, [
        'success' => true,
        'message' => 'Task completed successfully.'
    ]);

} catch (PDOException $e) {
    error_log('Task status API error: ' . $e->getMessage());

    taskApiResponse(500, [
        'success' => false,
        'message' => 'A database error occurred. Check the PHP error log.'
    ]);
}