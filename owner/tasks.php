<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| HTML escaping
|--------------------------------------------------------------------------
*/

function taskEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
|
| The task action handler must validate this token before making changes.
|
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    'pending',
    'in_progress',
    'completed',
    'accepted',
    'rework',
    'cancelled'
];

$allowedPriorities = [
    'low',
    'normal',
    'high',
    'urgent'
];

$status = trim((string)($_GET['status'] ?? ''));
$priority = trim((string)($_GET['priority'] ?? ''));

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($priority, $allowedPriorities, true)) {
    $priority = '';
}

/*
|--------------------------------------------------------------------------
| Fetch tasks
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 't.status = :status';
    $params['status'] = $status;
}

if ($priority !== '') {
    $where[] = 't.priority = :priority';
    $params['priority'] = $priority;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$sql = "
    SELECT
        t.id,
        t.title,
        t.description,
        t.priority,
        t.status,
        t.due_date,
        t.completion_note,
        t.completed_at,
        t.created_at,
        t.reviewed_by,
        t.reviewed_at,
        t.review_note,

        employee.name AS employee_name,
        employee.username AS employee_username,

        owner.name AS owner_name

    FROM assigned_tasks t

    LEFT JOIN users employee
        ON employee.id = t.assigned_to

    LEFT JOIN users owner
        ON owner.id = t.assigned_by

    $whereSql

    ORDER BY
        CASE
            WHEN t.status = 'completed' THEN 1
            WHEN t.status = 'rework' THEN 2
            WHEN t.status = 'in_progress' THEN 3
            WHEN t.status = 'pending' THEN 4
            WHEN t.status = 'accepted' THEN 5
            WHEN t.status = 'cancelled' THEN 6
            ELSE 7
        END,
        t.due_date ASC,
        t.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Task statistics
|--------------------------------------------------------------------------
*/

$statsSql = "
    SELECT
        COUNT(*) AS total_tasks,

        SUM(
            CASE
                WHEN status = 'pending'
                THEN 1 ELSE 0
            END
        ) AS pending_tasks,

        SUM(
            CASE
                WHEN status = 'in_progress'
                THEN 1 ELSE 0
            END
        ) AS in_progress_tasks,

        SUM(
            CASE
                WHEN status = 'completed'
                THEN 1 ELSE 0
            END
        ) AS review_tasks,

        SUM(
            CASE
                WHEN status = 'accepted'
                THEN 1 ELSE 0
            END
        ) AS accepted_tasks,

        SUM(
            CASE
                WHEN status NOT IN (
                    'completed',
                    'accepted',
                    'cancelled'
                )
                AND due_date IS NOT NULL
                AND due_date < NOW()
                THEN 1 ELSE 0
            END
        ) AS overdue_tasks

    FROM assigned_tasks
";

$stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

$totalTasks = (int)($stats['total_tasks'] ?? 0);
$pendingTasks = (int)($stats['pending_tasks'] ?? 0);
$inProgressTasks = (int)($stats['in_progress_tasks'] ?? 0);
$reviewTasks = (int)($stats['review_tasks'] ?? 0);
$acceptedTasks = (int)($stats['accepted_tasks'] ?? 0);
$overdueTasks = (int)($stats['overdue_tasks'] ?? 0);

$completionRate = $totalTasks > 0
    ? (int)round(($acceptedTasks / $totalTasks) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| Display helpers
|--------------------------------------------------------------------------
*/

function formatTaskStatus(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Awaiting Owner Review',
        'accepted' => 'Accepted',
        'rework' => 'Rework Required',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status))
    };
}

function formatTaskPriority(string $priority): string
{
    return ucfirst($priority);
}

function isTaskOverdue(array $task): bool
{
    if (
        empty($task['due_date']) ||
        in_array(
            $task['status'],
            ['completed', 'accepted', 'cancelled'],
            true
        )
    ) {
        return false;
    }

    $dueTimestamp = strtotime((string)$task['due_date']);

    return $dueTimestamp !== false
        && $dueTimestamp < time();
}

function taskStatusClass(string $status): string
{
    $allowed = [
        'pending',
        'in_progress',
        'completed',
        'accepted',
        'rework',
        'cancelled'
    ];

    return in_array($status, $allowed, true)
        ? $status
        : 'pending';
}

function taskPriorityClass(string $priority): string
{
    $allowed = [
        'low',
        'normal',
        'high',
        'urgent'
    ];

    return in_array($priority, $allowed, true)
        ? $priority
        : 'normal';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Task Monitoring | Mahalaxmi Medical Store
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        .task-monitor {
            max-width: 1450px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        .task-subtitle {
            color: #777;
            margin-bottom: 24px;
        }

        .task-stats {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin: 24px 0;
        }

        .task-stat {
            background: #fff;
            border: 1px solid #eadbd0;
            border-radius: 12px;
            padding: 18px;
        }

        .task-stat span {
            display: block;
            color: #666;
            font-size: 13px;
        }

        .task-stat strong {
            display: block;
            margin-top: 8px;
            font-size: 27px;
            color: #b51f2e;
        }

        .task-panel {
            background: #fff;
            border: 1px solid #eadbd0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .task-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
        }

        .task-filters label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .task-filters select {
            min-width: 170px;
            padding: 10px;
            border: 1px solid #ddc9bd;
            border-radius: 7px;
            background: #fff;
        }

        .task-button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            background: #b51f2e;
            color: #fff;
            padding: 10px 13px;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        .task-button:hover {
            opacity: .9;
        }

        .task-button.secondary {
            background: #666;
        }

        .task-button.success {
            background: #16834a;
        }

        .task-button.warning {
            background: #a96500;
        }

        .task-button.danger {
            background: #8d1724;
        }

        .task-table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #eadbd0;
            border-radius: 12px;
        }

        .task-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1250px;
        }

        .task-table th,
        .task-table td {
            padding: 14px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid #eee3dc;
        }

        .task-table th {
            background: #f9eee6;
            font-size: 13px;
        }

        .task-table td {
            font-size: 13px;
        }

        .task-title {
            font-weight: 700;
            margin-bottom: 6px;
        }

        .task-description {
            color: #666;
            max-width: 230px;
            overflow-wrap: anywhere;
        }

        .task-badge {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-pending {
            background: #fff0c2;
            color: #785700;
        }

        .status-in_progress {
            background: #dcecff;
            color: #174c8c;
        }

        .status-completed {
            background: #e8ddff;
            color: #59328c;
        }

        .status-accepted {
            background: #d9f5e3;
            color: #146c3a;
        }

        .status-rework {
            background: #ffe7c2;
            color: #8a4a00;
        }

        .status-cancelled {
            background: #eee;
            color: #555;
        }

        .priority-low,
        .priority-normal {
            background: #e8f3e8;
            color: #28652e;
        }

        .priority-high {
            background: #ffe7c2;
            color: #8a4a00;
        }

        .priority-urgent {
            background: #ffe0e0;
            color: #a00000;
        }

        .completion-note {
            max-width: 260px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .review-note {
            margin-top: 8px;
            padding: 10px;
            background: #fff8f2;
            border-left: 3px solid #b51f2e;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .task-actions {
            min-width: 210px;
        }

        .task-actions form {
            margin: 0 0 10px;
        }

        .task-actions textarea {
            width: 100%;
            min-height: 75px;
            padding: 9px;
            margin-bottom: 7px;
            box-sizing: border-box;
            border: 1px solid #ddc9bd;
            border-radius: 7px;
            font: inherit;
            resize: vertical;
        }

        .task-actions .task-button {
            margin: 3px 2px 3px 0;
        }

        .overdue-label {
            display: inline-block;
            margin-top: 6px;
            color: #b00020;
            font-weight: 700;
            font-size: 12px;
        }

        .task-progress {
            height: 9px;
            background: #eee;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 12px;
        }

        .task-progress-fill {
            height: 100%;
            background: #b51f2e;
        }

        .empty-tasks {
            padding: 35px;
            text-align: center;
            color: #777;
        }

        .task-flash {
            padding: 13px 16px;
            margin: 15px 0;
            border-radius: 8px;
            background: #fff0c2;
            color: #785700;
        }

        @media (max-width: 1200px) {
            .task-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .task-monitor {
                padding: 0 12px 30px;
            }

            .task-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .task-filters label,
            .task-filters select {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>

</head>

<body>

<header class="owner-header">
    <div>
        <h1>Mahalaxmi Medical Store</h1>
        <p>Owner — Task Monitoring</p>
    </div>
</header>

<main class="task-monitor">

    <nav style="margin-bottom:22px;">
        <a href="dashboard.php">← Owner Dashboard</a>
        &nbsp; | &nbsp;
        <a href="tasks.php">Task Monitoring</a>
        &nbsp; | &nbsp;
        <a href="../logout.php">Log out</a>
    </nav>

    <h2>Employee Task Monitoring</h2>

    <p class="task-subtitle">
        Review completed work, manage assigned tasks, and monitor employee progress.
    </p>

    <?php
    $messageType = (string)($_GET['message'] ?? '');

    $successMessages = [
        'accepted'  => 'Work accepted successfully.',
        'rework'    => 'Rework requested. Feedback has been saved.',
        'cancelled' => 'Task cancelled successfully.'
    ];

    $errorMessages = [
        'invalid' => 'Invalid request or task status. Please refresh and try again.',
        'failed'  => 'Unable to update the task. Please try again.'
    ];

    $successMessage = $successMessages[$messageType] ?? '';
    $errorMessage = $errorMessages[$messageType] ?? '';
    ?>

    <?php if ($successMessage !== ''): ?>

        <div class="task-flash" role="status">
            <?= taskEscape($successMessage) ?>
        </div>

    <?php elseif ($errorMessage !== ''): ?>

        <div
            class="task-flash"
            role="alert"
            style="background:#ffe0e0;color:#8d1724;"
        >
            <?= taskEscape($errorMessage) ?>
        </div>

    <?php endif; ?>

    <section class="task-stats">

        <div class="task-stat">
            <span>Total Tasks</span>
            <strong><?= $totalTasks ?></strong>
        </div>

        <div class="task-stat">
            <span>Pending</span>
            <strong><?= $pendingTasks ?></strong>
        </div>

        <div class="task-stat">
            <span>In Progress</span>
            <strong><?= $inProgressTasks ?></strong>
        </div>

        <div class="task-stat">
            <span>Awaiting Review</span>
            <strong><?= $reviewTasks ?></strong>
        </div>

        <div class="task-stat">
            <span>Accepted</span>
            <strong><?= $acceptedTasks ?></strong>
        </div>

        <div class="task-stat">
            <span>Overdue</span>
            <strong><?= $overdueTasks ?></strong>
        </div>

    </section>

    <section class="task-panel">

        <h3>Overall Accepted Task Rate</h3>

        <p>
            <?= $acceptedTasks ?> of <?= $totalTasks ?> tasks accepted
            (<?= $completionRate ?>%)
        </p>

        <div class="task-progress">
            <div
                class="task-progress-fill"
                style="width: <?= $completionRate ?>%;"
            ></div>
        </div>

    </section>

    <section class="task-panel">

        <h3>Filter Tasks</h3>

        <form method="GET" class="task-filters">

            <label>
                Status

                <select name="status">

                    <option value="">All statuses</option>

                    <?php foreach ($allowedStatuses as $option): ?>

                        <option
                            value="<?= taskEscape($option) ?>"
                            <?= $status === $option ? 'selected' : '' ?>
                        >
                            <?= taskEscape(formatTaskStatus($option)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </label>

            <label>
                Priority

                <select name="priority">

                    <option value="">All priorities</option>

                    <?php foreach ($allowedPriorities as $option): ?>

                        <option
                            value="<?= taskEscape($option) ?>"
                            <?= $priority === $option ? 'selected' : '' ?>
                        >
                            <?= taskEscape(formatTaskPriority($option)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </label>

            <button class="task-button" type="submit">
                Apply Filters
            </button>

            <a href="tasks.php" class="task-button secondary">
                Clear Filters
            </a>

            <a href="assign-task.php" class="task-button">
                + Assign New Task

            </a>

        </form>

    </section>

    <h3>Assigned Tasks</h3>

    <div class="task-table-wrap">

        <table class="task-table">

            <thead>
                <tr>
                    <th>Task</th>
                    <th>Employee</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Deadline</th>
                    <th>Employee Completion Note</th>
                    <th>Owner Review</th>
                    <th>Owner Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (!$tasks): ?>

                <tr>
                    <td colspan="8" class="empty-tasks">
                        No tasks match the selected filters.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($tasks as $task): ?>

                    <?php
                    $taskId = (int)$task['id'];

                    $taskStatus = (string)$task['status'];
                    $taskPriority = (string)$task['priority'];

                    $overdue = isTaskOverdue($task);

                    $statusClass = taskStatusClass($taskStatus);
                    $priorityClass = taskPriorityClass($taskPriority);
                    ?>

                    <tr>

                        <td>
                            <div class="task-title">
                                #<?= $taskId ?> —
                                <?= taskEscape($task['title']) ?>
                            </div>

                            <div class="task-description">
                                <?= nl2br(taskEscape($task['description'])) ?>
                            </div>
                        </td>

                        <td>
                            <strong>
                                <?= taskEscape(
                                    $task['employee_name'] ?? 'Unknown employee'
                                ) ?>
                            </strong>

                            <?php if (!empty($task['employee_username'])): ?>
                                <br>
                                <small>
                                    <?= taskEscape($task['employee_username']) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="task-badge priority-<?= taskEscape($priorityClass) ?>">
                                <?= taskEscape(formatTaskPriority($taskPriority)) ?>
                            </span>
                        </td>

                        <td>
                            <span class="task-badge status-<?= taskEscape($statusClass) ?>">
                                <?= taskEscape(formatTaskStatus($taskStatus)) ?>
                            </span>

                            <?php if ($overdue): ?>
                                <br>
                                <span class="overdue-label">OVERDUE</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($task['due_date'])): ?>

                                <?php
                                $dueTimestamp = strtotime((string)$task['due_date']);
                                ?>

                                <?= $dueTimestamp !== false
                                    ? taskEscape(date('d M Y, h:i A', $dueTimestamp))
                                    : 'Invalid date'
                                ?>

                            <?php else: ?>

                                No deadline

                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="completion-note">
                                <?= taskEscape(
                                    $task['completion_note'] ?: 'No completion note submitted.'
                                ) ?>
                            </div>

                            <?php if (!empty($task['completed_at'])): ?>
                                <br>
                                <small>
                                    Submitted:
                                    <?= taskEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string)$task['completed_at'])
                                        )
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </td>

                        <td>

                            <?php if (!empty($task['review_note'])): ?>

                                <div class="review-note">
                                    <?= taskEscape($task['review_note']) ?>
                                </div>

                            <?php else: ?>

                                <span>No owner review yet.</span>

                            <?php endif; ?>

                            <?php if (!empty($task['reviewed_at'])): ?>

                                <br>
                                <small>
                                    Reviewed:
                                    <?= taskEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string)$task['reviewed_at'])
                                        )
                                    ) ?>
                                </small>

                            <?php endif; ?>

                        </td>

                        <td class="task-actions">

                            <?php if ($taskStatus === 'completed'): ?>

                                <form
                                    method="POST"
                                    action="task-action.php"
                                    onsubmit="return confirm('Accept this completed task?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= $taskId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="accept"
                                    >

                                    <button
                                        type="submit"
                                        class="task-button success"
                                    >
                                        Accept Work
                                    </button>

                                </form>

                                <form
                                    method="POST"
                                    action="task-action.php"
                                    onsubmit="return confirm('Request rework for this task?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= $taskId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="rework"
                                    >

                                    <textarea
                                        name="review_note"
                                        maxlength="500"
                                        required
                                        placeholder="Explain what needs correction..."
                                    ></textarea>

                                    <button
                                        type="submit"
                                        class="task-button warning"
                                    >
                                        Request Rework
                                    </button>

                                </form>

                            <?php endif; ?>

                            <?php if (
                                in_array(
                                    $taskStatus,
                                    ['pending', 'in_progress', 'completed', 'rework'],
                                    true
                                )
                            ): ?>

                                <form
                                    method="POST"
                                    action="task-action.php"
                                    onsubmit="return confirm('Cancel this task? The employee will no longer be able to work on it.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= $taskId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="cancel"
                                    >

                                    <button
                                        type="submit"
                                        class="task-button danger"
                                    >
                                        Cancel Task
                                    </button>

                                </form>

                            <?php endif; ?>

                            <?php if ($taskStatus === 'accepted'): ?>

                                <strong style="color:#16834a;">
                                    Work accepted
                                </strong>

                            <?php elseif ($taskStatus === 'cancelled'): ?>

                                <strong style="color:#666;">
                                    Task cancelled
                                </strong>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</main>

</body>
</html>

