
<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tasks.php?message=invalid');
    exit;
}

// Validate CSRF token.
$sessionToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken) ||
    !is_string($submittedToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    header('Location: tasks.php?message=invalid');
    exit;
}

$taskId = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

$allowedActions = ['accept', 'rework', 'cancel'];

if (!$taskId || !in_array($action, $allowedActions, true)) {
    header('Location: tasks.php?message=invalid');
    exit;
}

$reviewNote = trim((string) ($_POST['review_note'] ?? ''));

if (strlen($reviewNote) > 500) {
    header('Location: tasks.php?message=failed');
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock the task while the owner reviews it.
    $stmt = $pdo->prepare("
        SELECT id, status
        FROM assigned_tasks
        WHERE id = :task_id
        FOR UPDATE
    ");

    $stmt->execute(['task_id' => $taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        $pdo->rollBack();
        header('Location: tasks.php?message=failed');
        exit;
    }

    $currentStatus = $task['status'];

    if ($action === 'accept') {

        if ($currentStatus !== 'completed') {
            $pdo->rollBack();
            header('Location: tasks.php?message=invalid');
            exit;
        }

        $newStatus = 'accepted';

    } elseif ($action === 'rework') {

        if ($currentStatus !== 'completed' || $reviewNote === '') {
            $pdo->rollBack();
            header('Location: tasks.php?message=invalid');
            exit;
        }

        $newStatus = 'rework';

    } else {

        if (!in_array(
            $currentStatus,
            ['pending', 'in_progress', 'completed', 'rework'],
            true
        )) {
            $pdo->rollBack();
            header('Location: tasks.php?message=invalid');
            exit;
        }

        $newStatus = 'cancelled';
    }

    $update = $pdo->prepare("
        UPDATE assigned_tasks
        SET
            status = :status,
            reviewed_by = :reviewed_by,
            reviewed_at = NOW(),
            review_note = :review_note
        WHERE id = :task_id
    ");

    $update->execute([
        'status' => $newStatus,
        'reviewed_by' => (int) (
            $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0
        ),
        'review_note' => $reviewNote !== '' ? $reviewNote : null,
        'task_id' => $taskId
    ]);

    $pdo->commit();

    $messages = [
        'accepted' => 'accepted',
        'rework' => 'rework',
        'cancelled' => 'cancelled'
    ];

    header('Location: tasks.php?message=' . $messages[$newStatus]);
    exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Owner task action failed: ' . $e->getMessage());

    header('Location: tasks.php?message=failed');
    exit;
}