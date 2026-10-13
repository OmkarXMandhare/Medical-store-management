<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('employee');

require_once __DIR__ . '/../config/database.php';

// Prevent HTML injection in the employee's name
$name = htmlspecialchars(
    $_SESSION['name'] ?? 'Employee',
    ENT_QUOTES,
    'UTF-8'
);

// Support the session ID convention used by the existing login system
$employeeId = (int) (
    $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? 0
);

$taskStats = [
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'rework' => 0
];

$recentTasks = [];
$taskError = false;

try {
    if ($employeeId > 0) {
        // Count the employee's assigned tasks by status
        $statsQuery = $pdo->prepare("
            SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END)
                    AS pending_count,

                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END)
                    AS progress_count,

                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)
                    AS completed_count,

                SUM(CASE WHEN status = 'rework' THEN 1 ELSE 0 END)
                    AS rework_count

            FROM assigned_tasks
            WHERE assigned_to = :employee_id
        ");

        $statsQuery->execute([
            'employee_id' => $employeeId
        ]);

        $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

        if ($stats) {
            $taskStats['pending'] = (int) ($stats['pending_count'] ?? 0);
            $taskStats['in_progress'] = (int) ($stats['progress_count'] ?? 0);
            $taskStats['completed'] = (int) ($stats['completed_count'] ?? 0);
            $taskStats['rework'] = (int) ($stats['rework_count'] ?? 0);
        }

        // Get the employee's five most recently assigned or updated tasks
        $recentQuery = $pdo->prepare("
            SELECT
                id,
                title,
                priority,
                status,
                due_date,
                created_at
            FROM assigned_tasks
            WHERE assigned_to = :employee_id
              AND status != 'cancelled'
            ORDER BY
                CASE
                    WHEN status = 'rework' THEN 0
                    WHEN status = 'pending' THEN 1
                    WHEN status = 'in_progress' THEN 2
                    ELSE 3
                END,
                created_at DESC
            LIMIT 5
        ");

        $recentQuery->execute([
            'employee_id' => $employeeId
        ]);

        $recentTasks = $recentQuery->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Keep database details out of the employee-facing page
    $taskError = true;
}

function employeeEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function employeeStatusClass(string $status): string
{
    $allowedStatuses = [
        'pending',
        'in_progress',
        'completed',
        'accepted',
        'rework',
        'cancelled'
    ];

    return in_array($status, $allowedStatuses, true)
        ? $status
        : 'pending';
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

    <title>Employee Dashboard | Mahalaxmi Medical Store</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/employee.css">

    <style>
        .employee-dashboard {
            max-width: 1150px;
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .employee-welcome {
            background: linear-gradient(135deg, #8f1724, #c83240);
            color: #fff;
            padding: 28px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 5px 18px rgba(120, 20, 35, 0.12);
        }

        .employee-welcome h2 {
            margin: 0 0 8px;
            font-size: 27px;
        }

        .employee-welcome p {
            margin: 0;
            line-height: 1.6;
        }

        .employee-section-title {
            color: #751622;
            margin: 26px 0 15px;
            font-size: 21px;
        }

        .employee-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
        }

        .employee-stat {
            background: #fff;
            border: 1px solid #f0dadd;
            border-radius: 13px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .employee-stat p {
            color: #666;
            margin: 0 0 12px;
            font-size: 14px;
        }

        .employee-stat h3 {
            color: #9b1c2b;
            margin: 0;
            font-size: 30px;
        }

        .employee-shortcuts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
        }

        .employee-shortcut {
            display: block;
            box-sizing: border-box;
            background: #fff;
            border: 1px solid #f0dadd;
            border-radius: 13px;
            padding: 20px;
            color: #333;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .employee-shortcut:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(120, 20, 35, 0.1);
        }

        .employee-shortcut h3 {
            color: #981c2b;
            margin: 0 0 10px;
            font-size: 17px;
        }

        .employee-shortcut p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
        }

        .employee-task-table-wrapper {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #f0dadd;
            border-radius: 13px;
        }

        .employee-task-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .employee-task-table th,
        .employee-task-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #f1e5e6;
            font-size: 14px;
        }

        .employee-task-table th {
            background: #fff3f4;
            color: #751622;
        }

        .employee-task-table tr:last-child td {
            border-bottom: none;
        }

        .employee-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-pending {
            background: #fff1d6;
            color: #855800;
        }

        .status-in_progress {
            background: #e0efff;
            color: #1557a0;
        }

        .status-completed {
            background: #e1f6e8;
            color: #176b35;
        }

        .status-accepted {
            background: #d7f4e1;
            color: #176b35;
        }

        .status-rework {
            background: #ffe2e2;
            color: #a51f2d;
        }

        .status-cancelled {
            background: #eeeeee;
            color: #555;
        }

        .priority-urgent,
        .priority-high {
            color: #b42332;
            font-weight: 700;
        }

        .priority-normal {
            color: #795600;
        }

        .priority-low {
            color: #367347;
        }

        .employee-empty {
            padding: 24px;
            text-align: center;
            color: #666;
        }

        .employee-view-tasks {
            display: inline-block;
            margin: 16px;
            color: #981c2b;
            font-weight: 600;
            text-decoration: none;
        }

        .employee-footer {
            text-align: center;
            color: #777;
            font-size: 13px;
            padding: 20px 12px;
        }

        .employee-logout {
            display: inline-block;
            margin-top: 22px;
            padding: 11px 18px;
            background: #8f1724;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
        }

        .employee-logout:hover {
            background: #6f101b;
        }

        .employee-error {
            background: #fff0f0;
            border: 1px solid #efb5b5;
            color: #8f1724;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 800px) {
            .employee-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .employee-shortcuts {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 520px) {
            .employee-dashboard {
                padding: 16px 12px 30px;
            }

            .employee-welcome {
                padding: 22px 18px;
            }

            .employee-welcome h2 {
                font-size: 23px;
            }

            .employee-stats,
            .employee-shortcuts {
                grid-template-columns: 1fr;
            }

            .employee-stat {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<header class="employee-header">
    <h1>Mahalaxmi Medical Store</h1>
    <p>Employee Dashboard</p>
</header>

<main class="employee-dashboard">

    <section class="employee-welcome">
        <h2>Hello, <?= $name ?>! 👋</h2>

        <p>
            Welcome to your employee dashboard.
            View your assigned tasks, track your progress,
            and report your daily store activities.
        </p>
    </section>

    <?php if ($taskError): ?>
        <div class="employee-error">
            Unable to load your task information.
            Please refresh the page or contact the owner if the problem continues.
        </div>
    <?php endif; ?>

    <h2 class="employee-section-title">My Task Summary</h2>

    <section class="employee-stats">

        <div class="employee-stat">
            <p>Pending Tasks</p>
            <h3><?= $taskStats['pending'] ?></h3>
        </div>

        <div class="employee-stat">
            <p>In Progress</p>
            <h3><?= $taskStats['in_progress'] ?></h3>
        </div>

        <div class="employee-stat">
            <p>Awaiting Owner Review</p>
            <h3><?= $taskStats['completed'] ?></h3>
        </div>

        <div class="employee-stat">
            <p>Returned for Rework</p>
            <h3><?= $taskStats['rework'] ?></h3>
        </div>

    </section>

    <h2 class="employee-section-title">Quick Actions</h2>

    <section class="employee-shortcuts">

        <a href="tasks.php" class="employee-shortcut">
            <h3>📋 My Tasks</h3>
            <p>
                View assigned duties, priorities, deadlines,
                and task status.
            </p>
        </a>

        <a href="activity.php" class="employee-shortcut">
            <h3>📝 Store Activities</h3>
            <p>
                Report completed work, stock updates,
                and store problems.
            </p>
        </a>

        <a href="checklist.php" class="employee-shortcut">
            <h3>✅ Daily Checklist</h3>
            <p>
                Open your daily checklist for store opening,
                cleanliness, safety, and closing.
            </p>
        </a>

    </section>

    <h2 class="employee-section-title">My Recent Tasks</h2>

    <section class="employee-task-table-wrapper">

        <?php if (empty($recentTasks)): ?>

            <div class="employee-empty">
                <?php if ($employeeId <= 0): ?>
                    Your login session does not contain an employee ID.
                    Please log out and log in again.
                <?php elseif ($taskError): ?>
                    Your tasks could not be loaded.
                <?php else: ?>
                    You don't have any assigned tasks yet.
                <?php endif; ?>
            </div>

        <?php else: ?>

            <table class="employee-task-table">

                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Due Date</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($recentTasks as $task): ?>

                    <?php
                    $status = employeeStatusClass(
                        (string) $task['status']
                    );

                    $priority = strtolower(
                        (string) ($task['priority'] ?? 'normal')
                    );

                    if (!in_array(
                        $priority,
                        ['low', 'normal', 'high', 'urgent'],
                        true
                    )) {
                        $priority = 'normal';
                    }
                    ?>

                    <tr>
                        <td>
                            <?= employeeEscape($task['title']) ?>
                        </td>

                        <td>
                            <span class="priority-<?= employeeEscape($priority) ?>">
                                <?= employeeEscape(ucfirst($priority)) ?>
                            </span>
                        </td>

                        <td>
                            <span class="employee-badge status-<?= employeeEscape($status) ?>">
                                <?= employeeEscape(
                                    ucwords(str_replace('_', ' ', $status))
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($task['due_date'])): ?>
                                <?= employeeEscape(
                                    date('d M Y', strtotime($task['due_date']))
                                ) ?>
                            <?php else: ?>
                                No deadline
                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

            <a href="tasks.php" class="employee-view-tasks">
                View All My Tasks →
            </a>

        <?php endif; ?>

    </section>

    <a class="employee-logout" href="../logout.php">
        Log Out
    </a>

</main>

<footer class="employee-footer">
    &copy; <?= date('Y') ?> Mahalaxmi Medical Store Management System
</footer>

</body>
</html>