<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| Employee identification
|--------------------------------------------------------------------------
*/
$employeeId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($employeeId <= 0) {
    http_response_code(401);
    exit('Employee session not found. Please log in again.');
}

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/
function taskEscape(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function taskStatusLabel(string $status): string
{
    return match ($status) {
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'completed'   => 'Awaiting Owner Review',
        'accepted'    => 'Accepted',
        'rework'      => 'Rework Required',
        'cancelled'   => 'Cancelled',
        default       => ucfirst(str_replace('_', ' ', $status)),
    };
}

function taskPriorityLabel(string $priority): string
{
    return match (strtolower($priority)) {
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
        default  => ucfirst($priority),
    };
}

function taskStatusClass(string $status): string
{
    return match ($status) {
        'pending'     => 'status-pending',
        'in_progress' => 'status-progress',
        'completed'   => 'status-completed',
        'accepted'    => 'status-accepted',
        'rework'      => 'status-rework',
        'cancelled'   => 'status-cancelled',
        default       => 'status-pending',
    };
}

/*
|--------------------------------------------------------------------------
| Employee information
|--------------------------------------------------------------------------
*/
$employeeName = 'Employee';

try {
    $employeeStmt = $pdo->prepare(
        'SELECT name FROM users WHERE id = :employee_id LIMIT 1'
    );

    $employeeStmt->execute([
        'employee_id' => $employeeId,
    ]);

    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if ($employee && !empty($employee['name'])) {
        $employeeName = $employee['name'];
    }
} catch (PDOException $e) {
    error_log('Employee name lookup failed: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Read flash message
|--------------------------------------------------------------------------
*/
$flashMessage = '';
$flashType = 'info';

$messages = [
    'started' => [
        'type' => 'success',
        'text' => 'Task started successfully.',
    ],
    'completed' => [
        'type' => 'success',
        'text' => 'Task submitted successfully. It is now awaiting owner review.',
    ],
    'restarted' => [
        'type' => 'success',
        'text' => 'Task restarted. Please complete the requested changes.',
    ],
    'invalid' => [
        'type' => 'error',
        'text' => 'Invalid request. Please try again.',
    ],
    'failed' => [
        'type' => 'error',
        'text' => 'Unable to update the task. Please try again.',
    ],
    'unauthorized' => [
        'type' => 'error',
        'text' => 'You are not authorized to perform this action.',
    ],
];

$messageKey = (string)($_GET['message'] ?? '');

if (isset($messages[$messageKey])) {
    $flashType = $messages[$messageKey]['type'];
    $flashMessage = $messages[$messageKey]['text'];
}

/*
|--------------------------------------------------------------------------
| Fetch employee task statistics
|--------------------------------------------------------------------------
*/
$stats = [
    'total'       => 0,
    'pending'     => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'accepted'    => 0,
    'rework'      => 0,
    'overdue'     => 0,
];

try {
    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
            SUM(CASE WHEN status = 'rework' THEN 1 ELSE 0 END) AS rework,
            SUM(
                CASE
                    WHEN due_date IS NOT NULL
                     AND due_date < CURDATE()
                     AND status IN ('pending', 'in_progress', 'rework')
                    THEN 1
                    ELSE 0
                END
            ) AS overdue
        FROM assigned_tasks
        WHERE assigned_to = :employee_id"
    );

    $statsStmt->execute([
        'employee_id' => $employeeId,
    ]);

    $result = $statsStmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        foreach ($stats as $key => $value) {
            $stats[$key] = (int)($result[$key] ?? 0);
        }
    }
} catch (PDOException $e) {
    error_log('Employee task statistics failed: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Fetch assigned tasks
|--------------------------------------------------------------------------
*/
$tasks = [];

try {
    $tasksStmt = $pdo->prepare(
        "SELECT
            id,
            title,
            description,
            priority,
            status,
            due_date,
            created_at,
            completion_note,
            completed_at,
            review_note,
            reviewed_at
        FROM assigned_tasks
        WHERE assigned_to = :employee_id
        ORDER BY
            CASE status
                WHEN 'rework' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'in_progress' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'accepted' THEN 5
                WHEN 'cancelled' THEN 6
                ELSE 7
            END,
            CASE priority
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 3
                ELSE 4
            END,
            due_date ASC,
            created_at DESC"
    );

    $tasksStmt->execute([
        'employee_id' => $employeeId,
    ]);

    $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Employee task list failed: ' . $e->getMessage());

    $flashType = 'error';
    $flashMessage = 'Could not load tasks. Check your database columns and try again.';
}

/*
|--------------------------------------------------------------------------
| Task filtering
|--------------------------------------------------------------------------
*/
$filter = (string)($_GET['status'] ?? 'all');

$allowedFilters = [
    'all',
    'pending',
    'in_progress',
    'completed',
    'accepted',
    'rework',
    'cancelled',
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($filter !== 'all') {
    $tasks = array_values(
        array_filter(
            $tasks,
            static fn(array $task): bool =>
                ($task['status'] ?? '') === $filter
        )
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Tasks | Mahalaxmi Medical Store</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --primary: #a82020;
            --primary-dark: #7f1717;
            --cream: #fffaf2;
            --cream-dark: #f5ead9;
            --text: #302727;
            --muted: #796d6d;
            --border: #eadfd3;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            background: var(--primary);
            color: white;
            padding: 18px 0;
            box-shadow: 0 4px 16px rgba(80, 0, 0, 0.12);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 19px;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: white;
            color: var(--primary);
            font-size: 21px;
        }

        .topbar a {
            color: white;
            text-decoration: none;
        }

        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 18px 55px;
        }

        .page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 7px;
            font-size: clamp(25px, 4vw, 34px);
            font-weight: 800;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
        }

        .date-label {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: white;
            color: var(--muted);
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 28px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 19px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 5px 18px rgba(70, 35, 10, 0.04);
        }

        .stat-icon {
            width: 47px;
            height: 47px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background: var(--cream-dark);
            color: var(--primary);
            font-size: 20px;
        }

        .stat-number {
            font-size: 25px;
            line-height: 1.2;
            font-weight: 800;
        }

        .stat-label {
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .section-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(70, 35, 10, 0.04);
        }

        .section-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .section-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .filter-links {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            padding: 15px 22px 0;
        }

        .filter-link {
            display: inline-block;
            text-decoration: none;
            color: var(--muted);
            border: 1px solid var(--border);
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 13px;
        }

        .filter-link:hover,
        .filter-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .task-list {
            padding: 18px;
        }

        .task-card {
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 16px;
            background: white;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .task-card:last-child {
            margin-bottom: 0;
        }

        .task-card:hover {
            box-shadow: 0 7px 22px rgba(70, 35, 10, 0.07);
        }

        .task-card.rework-card {
            border-left: 5px solid #b42318;
            background: #fffafa;
        }

        .task-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .task-title {
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 8px;
            overflow-wrap: anywhere;
        }

        .task-description {
            color: var(--muted);
            white-space: pre-line;
            overflow-wrap: anywhere;
            line-height: 1.6;
        }

        .badges {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            margin: 12px 0;
        }

        .badge-custom {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
        }

        .priority-high {
            color: #a61b1b;
            background: #ffebeb;
        }

        .priority-medium {
            color: #875400;
            background: #fff3cd;
        }

        .priority-low {
            color: #176b42;
            background: #e5f7ed;
        }

        .status-pending {
            color: #875400;
            background: #fff3cd;
        }

        .status-progress {
            color: #075985;
            background: #e0f2fe;
        }

        .status-completed {
            color: #4c3a91;
            background: #eee8ff;
        }

        .status-accepted {
            color: #176b42;
            background: #e5f7ed;
        }

        .status-rework {
            color: #a61b1b;
            background: #ffebeb;
        }

        .status-cancelled {
            color: #625b5b;
            background: #efeded;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 22px;
            margin-top: 14px;
            color: var(--muted);
            font-size: 13px;
        }

        .task-meta i {
            margin-right: 5px;
            color: var(--primary);
        }

        .overdue-text {
            color: #b42318 !important;
            font-weight: 700;
        }

        .owner-feedback {
            margin-top: 16px;
            padding: 15px;
            border: 1px solid #f3b8b8;
            border-radius: 12px;
            background: #fff1f1;
        }

        .owner-feedback h3 {
            margin: 0 0 8px;
            color: #9b1c1c;
            font-size: 15px;
            font-weight: 800;
        }

        .owner-feedback p {
            margin: 0;
            white-space: pre-line;
            overflow-wrap: anywhere;
            line-height: 1.6;
        }

        .completion-note {
            margin-top: 14px;
            padding: 13px;
            border-radius: 10px;
            background: #f8f5ff;
            border: 1px solid #e5ddfa;
        }

        .completion-note strong {
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .completion-note p {
            margin: 0;
            white-space: pre-line;
            overflow-wrap: anywhere;
            color: #5e5471;
        }

        .task-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
            align-items: flex-start;
        }

        .task-actions form {
            margin: 0;
        }

        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: 1px solid var(--primary);
            border-radius: 9px;
            padding: 10px 15px;
            font-weight: 700;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
        }

        .btn-outline-custom {
            background: white;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 9px;
            padding: 10px 15px;
            font-weight: 700;
        }

        .btn-outline-custom:hover {
            background: var(--cream);
            color: var(--primary-dark);
        }

        .completion-form {
            flex: 1 1 100%;
            max-width: 650px;
            margin-top: 5px !important;
        }

        .completion-form label {
            display: block;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .completion-form textarea {
            width: 100%;
            min-height: 90px;
            resize: vertical;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
        }

        .completion-form textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(168, 32, 32, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-state i {
            font-size: 42px;
            color: #cbb7a3;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 20px;
            font-weight: 800;
        }

        .empty-state p {
            color: var(--muted);
            margin: 0;
        }

        .alert {
            border-radius: 12px;
        }

        .footer {
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            padding: 28px 10px 0;
        }

        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .main-container {
                padding: 22px 12px 35px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .stat-card {
                padding: 13px;
                gap: 9px;
            }

            .stat-icon {
                width: 38px;
                height: 38px;
                font-size: 16px;
            }

            .stat-number {
                font-size: 21px;
            }

            .stat-label {
                font-size: 12px;
            }

            .task-list {
                padding: 12px;
            }

            .task-card {
                padding: 15px;
            }

            .section-header {
                padding: 17px;
            }

            .filter-links {
                padding: 13px 15px 0;
            }

            .task-actions form,
            .task-actions form button {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="container d-flex align-items-center justify-content-between gap-3 flex-wrap">

        <a href="dashboard.php" class="brand">
            <span class="brand-icon">
                <i class="fa-solid fa-prescription-bottle-medical"></i>
            </span>

            <span>
                Mahalaxmi Medical Store
                <small class="d-block fw-normal" style="font-size:12px;opacity:.85">
                    Employee Task Portal
                </small>
            </span>
        </a>

        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span>
                <i class="fa-solid fa-user me-1"></i>
                <?= taskEscape($employeeName) ?>
            </span>

            <a href="../logout.php" title="Logout">
                <i class="fa-solid fa-right-from-bracket me-1"></i>
                Logout
            </a>
        </div>

    </div>
</header>

<main class="main-container">

    <section class="page-heading">
        <div>
            <h1>My Tasks</h1>
            <p>View your assigned work, complete tasks, and review owner feedback.</p>
        </div>

        <div class="date-label">
            <i class="fa-regular fa-calendar me-2"></i>
            <?= date('d M Y') ?>
        </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert <?= $flashType === 'success' ? 'alert-success' : ($flashType === 'error' ? 'alert-danger' : 'alert-info') ?> alert-dismissible fade show" role="alert">
            <?= taskEscape($flashMessage) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>
        </div>
    <?php endif; ?>

    <section class="stats-grid">

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-list-check"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-regular fa-clock"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['pending'] ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-spinner"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['in_progress'] ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['overdue'] ?></div>
                <div class="stat-label">Overdue</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['completed'] ?></div>
                <div class="stat-label">Awaiting Review</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['accepted'] ?></div>
                <div class="stat-label">Accepted</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-rotate-left"></i>
            </div>
            <div>
                <div class="stat-number"><?= $stats['rework'] ?></div>
                <div class="stat-label">Rework Required</div>
            </div>
        </div>

    </section>

    <section class="section-card">

        <div class="section-header">
            <h2>
                <i class="fa-solid fa-clipboard-list me-2" style="color:var(--primary)"></i>
                Assigned Tasks
            </h2>

            <span class="text-secondary small">
                <?= count($tasks) ?> task(s) shown
            </span>
        </div>

        <nav class="filter-links" aria-label="Filter tasks">
            <?php
            $filterLabels = [
                'all'         => 'All Tasks',
                'pending'     => 'Pending',
                'in_progress' => 'In Progress',
                'completed'   => 'Awaiting Review',
                'accepted'    => 'Accepted',
                'rework'      => 'Rework Required',
                'cancelled'   => 'Cancelled',
            ];
            ?>

            <?php foreach ($filterLabels as $key => $label): ?>
                <a
                    class="filter-link <?= $filter === $key ? 'active' : '' ?>"
                    href="tasks.php?status=<?= urlencode($key) ?>"
                >
                    <?= taskEscape($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="task-list">

            <?php if (empty($tasks)): ?>

                <div class="empty-state">
                    <i class="fa-regular fa-folder-open"></i>

                    <h3>No tasks found</h3>

                    <p>
                        <?= $filter === 'all'
                            ? 'You do not have any assigned tasks yet.'
                            : 'There are no tasks in this category.' ?>
                    </p>
                </div>

            <?php else: ?>

                <?php foreach ($tasks as $task): ?>

                    <?php
                    $taskStatus = (string)($task['status'] ?? 'pending');
                    $priority = strtolower((string)($task['priority'] ?? 'medium'));

                    $dueDate = !empty($task['due_date'])
                        ? strtotime((string)$task['due_date'])
                        : false;

                    $isOverdue = $dueDate !== false
                        && $dueDate < strtotime(date('Y-m-d'))
                        && in_array(
                            $taskStatus,
                            ['pending', 'in_progress', 'rework'],
                            true
                        );
                    ?>

                    <article class="task-card <?= $taskStatus === 'rework' ? 'rework-card' : '' ?>">

                        <div class="task-top">
                            <div>
                                <h3 class="task-title">
                                    <?= taskEscape($task['title'] ?? 'Untitled Task') ?>
                                </h3>

                                <?php if (!empty($task['description'])): ?>
                                    <div class="task-description">
                                        <?= taskEscape($task['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <span class="badge-custom <?= taskStatusClass($taskStatus) ?>">
                                <?= taskEscape(taskStatusLabel($taskStatus)) ?>
                            </span>
                        </div>

                        <div class="badges">
                            <span class="badge-custom priority-<?= in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium' ?>">
                                <i class="fa-solid fa-flag"></i>
                                <?= taskEscape(taskPriorityLabel($priority)) ?> Priority
                            </span>

                            <?php if ($isOverdue): ?>
                                <span class="badge-custom priority-high">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    Overdue
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="task-meta">

                            <span>
                                <i class="fa-regular fa-calendar"></i>

                                Due:
                                <?php if ($dueDate !== false): ?>
                                    <span class="<?= $isOverdue ? 'overdue-text' : '' ?>">
                                        <?= date('d M Y', $dueDate) ?>
                                    </span>
                                <?php else: ?>
                                    Not specified
                                <?php endif; ?>
                            </span>

                            <?php if (!empty($task['created_at'])): ?>
                                <span>
                                    <i class="fa-regular fa-clock"></i>
                                    Assigned:
                                    <?= taskEscape(date('d M Y', strtotime((string)$task['created_at']))) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($task['completed_at'])): ?>
                                <span>
                                    <i class="fa-solid fa-check"></i>
                                    Submitted:
                                    <?= taskEscape(date('d M Y, h:i A', strtotime((string)$task['completed_at']))) ?>
                                </span>
                            <?php endif; ?>

                        </div>

                        <?php if ($taskStatus === 'rework'): ?>

                            <div class="owner-feedback">
                                <h3>
                                    <i class="fa-solid fa-rotate-left me-1"></i>
                                    Changes Requested by Owner
                                </h3>

                                <?php if (!empty($task['review_note'])): ?>
                                    <p><?= taskEscape($task['review_note']) ?></p>
                                <?php else: ?>
                                    <p>The owner has requested changes. Please contact the owner for details.</p>
                                <?php endif; ?>

                                <?php if (!empty($task['reviewed_at'])): ?>
                                    <div class="small text-secondary mt-2">
                                        Reviewed:
                                        <?= taskEscape(date('d M Y, h:i A', strtotime((string)$task['reviewed_at']))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php endif; ?>

                        <?php if (!empty($task['completion_note'])): ?>

                            <div class="completion-note">
                                <strong>
                                    <i class="fa-solid fa-note-sticky me-1"></i>
                                    <?= in_array($taskStatus, ['completed', 'accepted'], true)
                                        ? 'Your Completion Note'
                                        : 'Previous Completion Note' ?>
                                </strong>

                                <p><?= taskEscape($task['completion_note']) ?></p>
                            </div>

                        <?php endif; ?>

                        <?php if ($taskStatus === 'pending'): ?>

                            <div class="task-actions">
                                <form
                                    method="POST"
                                    action="../api/update-task-status.php"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= (int)$task['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="start"
                                    >

                                    <button type="submit" class="btn btn-primary-custom">
                                        <i class="fa-solid fa-play me-1"></i>
                                        Start Task
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($taskStatus === 'rework'): ?>

                            <div class="task-actions">
                                <form
                                    method="POST"
                                    action="../api/update-task-status.php"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= (int)$task['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="restart"
                                    >

                                    <button type="submit" class="btn btn-primary-custom">
                                        <i class="fa-solid fa-rotate-right me-1"></i>
                                        Restart Task
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($taskStatus === 'in_progress'): ?>

                            <div class="task-actions">

                                <form
                                    method="POST"
                                    action="../api/update-task-status.php"
                                    class="completion-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= taskEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= (int)$task['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="complete"
                                    >

                                    <label for="completion_note_<?= (int)$task['id'] ?>">
                                        Completion Note
                                    </label>

                                    <textarea
                                        id="completion_note_<?= (int)$task['id'] ?>"
                                        name="completion_note"
                                        maxlength="1000"
                                        placeholder="Describe what you completed..."
                                        required
                                    ></textarea>

                                    <button type="submit" class="btn btn-primary-custom mt-3">
                                        <i class="fa-solid fa-check me-1"></i>
                                        Submit for Owner Review
                                    </button>
                                </form>

                            </div>

                        <?php elseif ($taskStatus === 'completed'): ?>

                            <div class="alert alert-info mt-3 mb-0">
                                <i class="fa-solid fa-hourglass-half me-1"></i>
                                Your work has been submitted. It is waiting for the owner to review it.
                            </div>

                        <?php elseif ($taskStatus === 'accepted'): ?>

                            <div class="alert alert-success mt-3 mb-0">
                                <i class="fa-solid fa-circle-check me-1"></i>
                                The owner accepted this task. Thank you!
                            </div>

                        <?php elseif ($taskStatus === 'cancelled'): ?>

                            <div class="alert alert-secondary mt-3 mb-0">
                                <i class="fa-solid fa-ban me-1"></i>
                                This task has been cancelled by the owner.
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>
    </section>

    <footer class="footer">
        &copy; <?= date('Y') ?> Mahalaxmi Medical Store Management System.
        All rights reserved.
    </footer>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>