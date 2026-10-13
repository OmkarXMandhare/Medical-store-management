<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| Only POST requests are allowed
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

/*
|--------------------------------------------------------------------------
| Get employee ID
|--------------------------------------------------------------------------
*/
$employeeId = (int) (
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);

if ($employeeId <= 0) {
    http_response_code(401);
    exit('Please log in again.');
}

/*
|--------------------------------------------------------------------------
| Validate CSRF token
|--------------------------------------------------------------------------
*/
$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken) ||
    !is_string($postedToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}

/*
|--------------------------------------------------------------------------
| Read submitted values
|--------------------------------------------------------------------------
*/
$taskId = filter_input(
    INPUT_POST,
    'task_id',
    FILTER_VALIDATE_INT
);

$action = $_POST['action'] ?? '';

$completionNote = trim(
    (string) ($_POST['completion_note'] ?? '')
);

if (!$taskId || $taskId <= 0) {
    header('Location: ../employee/tasks.php?message=invalid');
    exit;
}

$allowedActions = [
    'start',
    'restart',
    'complete',
];

if (
    !is_string($action) ||
    !in_array($action, $allowedActions, true)
) {
    header('Location: ../employee/tasks.php?message=invalid');
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate completion note
|--------------------------------------------------------------------------
*/
if (strlen($completionNote) > 1000) {
    header('Location: ../employee/tasks.php?message=failed');
    exit;
}

if ($action === 'complete' && $completionNote === '') {
    header('Location: ../employee/tasks.php?message=failed');
    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Start a transaction
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Lock task and verify ownership
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM assigned_tasks
        WHERE id = :task_id
          AND assigned_to = :employee_id
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        'task_id' => $taskId,
        'employee_id' => $employeeId,
    ]);

    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        $pdo->rollBack();

        http_response_code(404);
        header('Location: ../employee/tasks.php?message=invalid');
        exit;
    }

    $currentStatus = (string) $task['status'];

    /*
    |--------------------------------------------------------------------------
    | Action 1: Start a pending task
    |--------------------------------------------------------------------------
    */
    if ($action === 'start') {

        if ($currentStatus !== 'pending') {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=invalid');
            exit;
        }

        $update = $pdo->prepare("
            UPDATE assigned_tasks
            SET status = 'in_progress',
                updated_at = NOW()
            WHERE id = :task_id
              AND assigned_to = :employee_id
              AND status = 'pending'
        ");

        $update->execute([
            'task_id' => $taskId,
            'employee_id' => $employeeId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=failed');
            exit;
        }

        $pdo->commit();

        header('Location: ../employee/tasks.php?message=started');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Action 2: Restart a task returned for rework
    |--------------------------------------------------------------------------
    */
    if ($action === 'restart') {

        if ($currentStatus !== 'rework') {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=invalid');
            exit;
        }

        $update = $pdo->prepare("
            UPDATE assigned_tasks
            SET status = 'in_progress',
                updated_at = NOW()
            WHERE id = :task_id
              AND assigned_to = :employee_id
              AND status = 'rework'
        ");

        $update->execute([
            'task_id' => $taskId,
            'employee_id' => $employeeId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=failed');
            exit;
        }

        $pdo->commit();

        header('Location: ../employee/tasks.php?message=restarted');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Action 3: Submit a task for owner review
    |--------------------------------------------------------------------------
    */
    if ($action === 'complete') {

        if ($currentStatus !== 'in_progress') {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=invalid');
            exit;
        }

        $update = $pdo->prepare("
            UPDATE assigned_tasks
            SET status = 'completed',
                completion_note = :completion_note,
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = :task_id
              AND assigned_to = :employee_id
              AND status = 'in_progress'
        ");

        $update->execute([
            'completion_note' => $completionNote,
            'task_id' => $taskId,
            'employee_id' => $employeeId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();

            header('Location: ../employee/tasks.php?message=failed');
            exit;
        }

        $pdo->commit();

        header('Location: ../employee/tasks.php?message=completed');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected action
    |--------------------------------------------------------------------------
    */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: ../employee/tasks.php?message=invalid');
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Task status update failed: ' . $e->getMessage()
    );

    header('Location: ../employee/tasks.php?message=failed');
    exit;
}