<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function dashboardEscape(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dashboardStatusLabel(string $status): string
{
    return match ($status) {
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'completed'   => 'Awaiting Review',
        'accepted'    => 'Accepted',
        'rework'      => 'Rework Required',
        'cancelled'   => 'Cancelled',
        default       => ucfirst(str_replace('_', ' ', $status)),
    };
}

function dashboardStatusClass(string $status): string
{
    return match ($status) {
        'pending'     => 'pending',
        'in_progress' => 'progress',
        'completed'   => 'review',
        'accepted'    => 'accepted',
        'rework'      => 'rework',
        'cancelled'   => 'cancelled',
        default       => 'pending',
    };
}

/*
|--------------------------------------------------------------------------
| Default values
|--------------------------------------------------------------------------
*/

$dashboardError = '';

$stats = [
    'total'       => 0,
    'pending'     => 0,
    'in_progress' => 0,
    'completed'   => 0,
    'accepted'    => 0,
    'rework'      => 0,
    'cancelled'   => 0,
    'overdue'     => 0,
];

$activeEmployees = 0;
$recentTasks = [];
$tasksAwaitingReview = [];
$overdueTasks = [];

/*
|--------------------------------------------------------------------------
| Load dashboard information
|--------------------------------------------------------------------------
*/

try {
    /*
    | Task statistics
    */

    $statsSql = "
        SELECT
            COUNT(*) AS total,

            COALESCE(SUM(
                CASE WHEN status = 'pending'
                THEN 1 ELSE 0 END
            ), 0) AS pending,

            COALESCE(SUM(
                CASE WHEN status = 'in_progress'
                THEN 1 ELSE 0 END
            ), 0) AS in_progress,

            COALESCE(SUM(
                CASE WHEN status = 'completed'
                THEN 1 ELSE 0 END
            ), 0) AS completed,

            COALESCE(SUM(
                CASE WHEN status = 'accepted'
                THEN 1 ELSE 0 END
            ), 0) AS accepted,

            COALESCE(SUM(
                CASE WHEN status = 'rework'
                THEN 1 ELSE 0 END
            ), 0) AS rework,

            COALESCE(SUM(
                CASE WHEN status = 'cancelled'
                THEN 1 ELSE 0 END
            ), 0) AS cancelled,

            COALESCE(SUM(
                CASE
                    WHEN due_date IS NOT NULL
                     AND due_date < NOW()
                     AND status IN (
                        'pending',
                        'in_progress',
                        'rework'
                     )
                    THEN 1
                    ELSE 0
                END
            ), 0) AS overdue

        FROM assigned_tasks
    ";

    $statsResult = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

    if ($statsResult) {
        foreach ($stats as $key => $value) {
            $stats[$key] = (int)($statsResult[$key] ?? 0);
        }
    }

    /*
    | Active employee accounts
    */

    $employeeSql = "
        SELECT COUNT(*)
        FROM users
        WHERE role = 'employee'
          AND is_active = 1
    ";

    $activeEmployees = (int)$pdo->query($employeeSql)->fetchColumn();

    /*
    | Recent tasks
    */

    $recentSql = "
        SELECT
            t.id,
            t.title,
            t.priority,
            t.status,
            t.due_date,
            t.created_at,
            u.name AS employee_name

        FROM assigned_tasks t

        LEFT JOIN users u
            ON u.id = t.assigned_to

        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 8
    ";

    $recentTasks = $pdo
        ->query($recentSql)
        ->fetchAll(PDO::FETCH_ASSOC);

    /*
    | Tasks awaiting owner review
    */

    $reviewSql = "
        SELECT
            t.id,
            t.title,
            t.due_date,
            t.completed_at,
            u.name AS employee_name

        FROM assigned_tasks t

        LEFT JOIN users u
            ON u.id = t.assigned_to

        WHERE t.status = 'completed'

        ORDER BY
            t.completed_at ASC,
            t.created_at ASC

        LIMIT 6
    ";

    $tasksAwaitingReview = $pdo
        ->query($reviewSql)
        ->fetchAll(PDO::FETCH_ASSOC);

    /*
    | Overdue tasks
    */

    $overdueSql = "
        SELECT
            t.id,
            t.title,
            t.due_date,
            t.status,
            u.name AS employee_name

        FROM assigned_tasks t

        LEFT JOIN users u
            ON u.id = t.assigned_to

        WHERE t.due_date IS NOT NULL
          AND t.due_date < NOW()
          AND t.status IN (
              'pending',
              'in_progress',
              'rework'
          )

        ORDER BY t.due_date ASC

        LIMIT 6
    ";

    $overdueTasks = $pdo
        ->query($overdueSql)
        ->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Owner dashboard error: ' . $e->getMessage());

    $dashboardError =
        'Some dashboard information could not be loaded. '
        . 'Please check the database configuration and table structure.';
}

/*
|--------------------------------------------------------------------------
| Completion rate
|--------------------------------------------------------------------------
*/

$completionRate = $stats['total'] > 0
    ? (int)round(
        ($stats['accepted'] / $stats['total']) * 100
    )
    : 0;

/*
|--------------------------------------------------------------------------
| Owner name
|--------------------------------------------------------------------------
*/

$ownerName = (string)(
    $_SESSION['name']
    ?? $_SESSION['username']
    ?? 'Owner'
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Owner Dashboard | Mahalaxmi Medical Store</title>

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
            --red: #a82020;
            --red-dark: #7f1717;
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

        a {
            text-decoration: none;
        }

        .topbar {
            background: var(--red);
            color: white;
            padding: 18px 0;
            box-shadow: 0 4px 16px rgba(80, 0, 0, .12);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-size: 19px;
            font-weight: 800;
        }

        .brand:hover {
            color: white;
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: white;
            color: var(--red);
            font-size: 21px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .topbar-actions a {
            color: white;
        }

        .dashboard {
            max-width: 1450px;
            margin: auto;
            padding: 30px 20px 50px;
        }

        .welcome {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 25px;
        }

        .welcome h1 {
            margin: 0 0 8px;
            font-size: clamp(26px, 4vw, 36px);
            font-weight: 800;
        }

        .welcome p {
            color: var(--muted);
            margin: 0;
        }

        .today {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            color: var(--muted);
        }

        .section-title {
            margin: 30px 0 15px;
            font-size: 21px;
            font-weight: 800;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 20px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 15px;
            box-shadow: 0 5px 18px rgba(70, 35, 10, .04);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream-dark);
            color: var(--red);
            border-radius: 13px;
            font-size: 20px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2;
        }

        .stat-label {
            margin-top: 5px;
            color: var(--muted);
            font-size: 13px;
        }

        .stat-card.alert-stat .stat-icon {
            color: #b42318;
            background: #ffebeb;
        }

        .panel {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 5px 18px rgba(70, 35, 10, .04);
            margin-bottom: 22px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 19px 22px;
            border-bottom: 1px solid var(--border);
        }

        .panel-header h2 {
            margin: 0;
            font-size: 19px;
            font-weight: 800;
        }

        .panel-body {
            padding: 20px;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .quick-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px;
            color: var(--text);
            background: #fffdf9;
            border: 1px solid var(--border);
            border-radius: 13px;
            transition: .2s;
        }

        .quick-link:hover {
            border-color: var(--red);
            transform: translateY(-2px);
            color: var(--red);
        }

        .quick-link i {
            color: var(--red);
            font-size: 22px;
        }

        .quick-link strong {
            display: block;
            margin-bottom: 5px;
        }

        .quick-link small {
            color: var(--muted);
        }

        .progress-track {
            height: 10px;
            background: #eee5dc;
            border-radius: 20px;
            overflow: hidden;
            margin-top: 12px;
        }

        .progress-fill {
            height: 100%;
            background: var(--red);
            border-radius: 20px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .dashboard-table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        .dashboard-table th,
        .dashboard-table td {
            padding: 14px 18px;
            text-align: left;
            border-bottom: 1px solid #f0e7df;
            vertical-align: middle;
        }

        .dashboard-table th {
            background: #fcf3e9;
            color: #554343;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .dashboard-table td {
            font-size: 13px;
        }

        .dashboard-table tbody tr:last-child td {
            border-bottom: none;
        }

        .task-name {
            font-weight: 700;
            color: var(--text);
            overflow-wrap: anywhere;
        }

        .task-id {
            color: var(--muted);
            font-size: 12px;
            margin-top: 4px;
        }

        .badge-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .pending {
            background: #fff3cd;
            color: #875400;
        }

        .progress {
            background: #e0f2fe;
            color: #075985;
        }

        .review {
            background: #eee8ff;
            color: #59328c;
        }

        .accepted {
            background: #e5f7ed;
            color: #176b42;
        }

        .rework {
            background: #ffebeb;
            color: #a61b1b;
        }

        .cancelled {
            background: #efeded;
            color: #625b5b;
        }

        .priority-label {
            font-weight: 700;
        }

        .priority-urgent,
        .priority-high {
            color: #b42318;
        }

        .priority-normal {
            color: #875400;
        }

        .priority-low {
            color: #176b42;
        }

        .deadline-overdue {
            color: #b42318;
            font-weight: 800;
        }

        .btn-red {
            display: inline-block;
            padding: 10px 15px;
            background: var(--red);
            border: 1px solid var(--red);
            border-radius: 9px;
            color: white;
            font-weight: 700;
            font-size: 13px;
        }

        .btn-red:hover {
            background: var(--red-dark);
            border-color: var(--red-dark);
            color: white;
        }

        .btn-light-custom {
            display: inline-block;
            padding: 9px 13px;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--red);
            background: white;
            font-weight: 700;
            font-size: 12px;
        }

        .btn-light-custom:hover {
            background: var(--cream);
            color: var(--red-dark);
        }

        .alert-row {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 15px 0;
            border-bottom: 1px solid #f0e7df;
        }

        .alert-row:last-child {
            border-bottom: none;
        }

        .alert-icon {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffebeb;
            color: #b42318;
            border-radius: 10px;
        }

        .alert-icon.info {
            color: #875400;
            background: #fff3cd;
        }

        .alert-content {
            flex: 1;
            min-width: 0;
        }

        .alert-content strong {
            display: block;
            margin-bottom: 5px;
        }

        .alert-content p {
            color: var(--muted);
            font-size: 13px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 28px 15px;
        }

        .empty-state i {
            font-size: 30px;
            color: #cbb7a3;
            margin-bottom: 10px;
        }

        .empty-state p {
            margin: 0;
        }

        .footer {
            padding: 25px 10px 0;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .system-note {
            padding: 14px 16px;
            margin-bottom: 22px;
            border: 1px solid #f0d39a;
            border-radius: 10px;
            background: #fff8e8;
            color: #765416;
        }

        @media (max-width: 1000px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .quick-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .dashboard {
                padding: 22px 12px 35px;
            }

            .stats-grid {
                gap: 9px;
            }

            .stat-card {
                padding: 13px;
                gap: 9px;
            }

            .stat-icon {
                width: 37px;
                height: 37px;
                font-size: 16px;
            }

            .stat-number {
                font-size: 22px;
            }

            .stat-label {
                font-size: 11px;
            }

            .quick-grid {
                grid-template-columns: 1fr;
            }

            .panel-body {
                padding: 14px;
            }

            .panel-header {
                padding: 16px;
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
                    Owner Dashboard
                </small>
            </span>

        </a>

        <div class="topbar-actions">

            <span>
                <i class="fa-solid fa-user me-1"></i>
                <?= dashboardEscape($ownerName) ?>
            </span>

            <a href="../logout.php">
                <i class="fa-solid fa-right-from-bracket me-1"></i>
                Logout
            </a>

        </div>

    </div>

</header>

<main class="dashboard">

    <section class="welcome">

        <div>
            <h1>Welcome, <?= dashboardEscape($ownerName) ?>!</h1>

            <p>
                Monitor employee tasks, review submitted work,
                and keep track of important deadlines.
            </p>
        </div>

        <div class="today">
            <i class="fa-regular fa-calendar me-2"></i>
            <?= date('l, d F Y') ?>
        </div>

    </section>

    <?php if ($dashboardError !== ''): ?>

        <div class="alert alert-danger" role="alert">
            <?= dashboardEscape($dashboardError) ?>
        </div>

    <?php endif; ?>

    <div class="system-note">
        <i class="fa-solid fa-circle-info me-2"></i>
        <strong>Dashboard status:</strong>
        Employee task monitoring is available. Sales and medicine inventory
        summaries will be added after their database tables are integrated.
    </div>

    <h2 class="section-title">Task Overview</h2>

    <section class="stats-grid">

        <article class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-list-check"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon">
                <i class="fa-regular fa-clock"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['pending'] ?></div>
                <div class="stat-label">Pending</div>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-spinner"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['in_progress'] ?></div>
                <div class="stat-label">In Progress</div>
            </div>

        </article>

        <article class="stat-card alert-stat">

            <div class="stat-icon">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['completed'] ?></div>
                <div class="stat-label">Awaiting Review</div>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['accepted'] ?></div>
                <div class="stat-label">Accepted Tasks</div>
            </div>

        </article>

        <article class="stat-card alert-stat">

            <div class="stat-icon">
                <i class="fa-solid fa-rotate-left"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['rework'] ?></div>
                <div class="stat-label">Rework Required</div>
            </div>

        </article>

        <article class="stat-card alert-stat">

            <div class="stat-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <div>
                <div class="stat-number"><?= $stats['overdue'] ?></div>
                <div class="stat-label">Overdue Tasks</div>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon">
                <i class="fa-solid fa-users"></i>
            </div>

            <div>
                <div class="stat-number"><?= $activeEmployees ?></div>
                <div class="stat-label">Active Employees</div>
            </div>

        </article>

    </section>

    <h2 class="section-title">Quick Actions</h2>

    <section class="panel">

        <div class="panel-body">

            <div class="quick-grid">

                <a href="tasks.php" class="quick-link">

                    <i class="fa-solid fa-clipboard-list"></i>

                    <span>
                        <strong>Manage Tasks</strong>
                        <small>View and monitor assignments</small>
                    </span>

                </a>

                <a href="tasks.php?status=completed" class="quick-link">

                    <i class="fa-solid fa-clipboard-check"></i>

                    <span>
                        <strong>Review Completed Work</strong>
                        <small>
                            <?= $stats['completed'] ?> task(s) awaiting review
                        </small>
                    </span>

                </a>

                <a href="tasks.php?status=rework" class="quick-link">

                    <i class="fa-solid fa-rotate-left"></i>

                    <span>
                        <strong>Rework Tasks</strong>
                        <small>
                            <?= $stats['rework'] ?> task(s) need corrections
                        </small>
                    </span>

                </a>

                <a href="employees.php" class="quick-link">

                    <i class="fa-solid fa-users"></i>

                    <span>
                        <strong>Manage Employees</strong>
                        <small>View and monitor employee</small>
                    </span>

                </a>
                <a href="inventory-alerts.php">
                    Manage Inventory & Expiry Alerts
                </a>
                <a href="inventory.php" class="quick-action">
                    <strong>Inventory Management</strong>
                    <span>View medicines, stock quantities and expiry information</span>
                </a>
            </div>

        </div>

    </section>

    <h2 class="section-title">Important Alerts</h2>

    <section class="panel">

        <div class="panel-header">

            <h2>
                <i class="fa-solid fa-bell me-2" style="color:var(--red)"></i>
                Action Required
            </h2>

            <a href="tasks.php" class="btn-light-custom">
                View All Tasks
            </a>

        </div>

        <div class="panel-body">

            <?php if (
                $stats['completed'] === 0
                && $stats['overdue'] === 0
                && $stats['rework'] === 0
            ): ?>

                <div class="empty-state">

                    <i class="fa-solid fa-circle-check"></i>

                    <p>
                        No urgent task alerts at the moment.
                    </p>

                </div>

            <?php else: ?>

                <?php if ($stats['completed'] > 0): ?>

                    <div class="alert-row">

                        <div class="alert-icon info">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>

                        <div class="alert-content">

                            <strong>
                                <?= $stats['completed'] ?>
                                task(s) awaiting owner review
                            </strong>

                            <p>
                                Employees have submitted their work.
                                Accept the work or request corrections.
                            </p>

                            <a
                                href="tasks.php?status=completed"
                                class="btn-light-custom mt-2"
                            >
                                Review Tasks
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

                <?php if ($stats['overdue'] > 0): ?>

                    <div class="alert-row">

                        <div class="alert-icon">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>

                        <div class="alert-content">

                            <strong>
                                <?= $stats['overdue'] ?>
                                overdue task(s)
                            </strong>

                            <p>
                                These tasks have passed their deadlines
                                and are not yet finished.
                            </p>

                            <a
                                href="tasks.php"
                                class="btn-light-custom mt-2"
                            >
                                Check Deadlines
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

                <?php if ($stats['rework'] > 0): ?>

                    <div class="alert-row">

                        <div class="alert-icon info">
                            <i class="fa-solid fa-rotate-left"></i>
                        </div>

                        <div class="alert-content">

                            <strong>
                                <?= $stats['rework'] ?>
                                task(s) require rework
                            </strong>

                            <p>
                                Employees need to address the feedback
                                before resubmitting their work.
                            </p>

                            <a
                                href="tasks.php?status=rework"
                                class="btn-light-custom mt-2"
                            >
                                View Rework Tasks
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </section>

    <h2 class="section-title">Tasks Awaiting Review</h2>

    <section class="panel">

        <div class="panel-header">

            <h2>Recent Employee Submissions</h2>

            <a
                href="tasks.php?status=completed"
                class="btn-light-custom"
            >
                View All
            </a>

        </div>

        <?php if (empty($tasksAwaitingReview)): ?>

            <div class="empty-state">

                <i class="fa-regular fa-folder-open"></i>

                <p>
                    No completed tasks are waiting for review.
                </p>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="dashboard-table">

                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Employee</th>
                            <th>Submitted</th>
                            <th>Deadline</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($tasksAwaitingReview as $task): ?>

                        <tr>

                            <td>
                                <div class="task-name">
                                    <?= dashboardEscape($task['title']) ?>
                                </div>

                                <div class="task-id">
                                    Task #<?= (int)$task['id'] ?>
                                </div>
                            </td>

                            <td>
                                <?= dashboardEscape(
                                    $task['employee_name'] ?? 'Unknown employee'
                                ) ?>
                            </td>

                            <td>
                                <?php if (!empty($task['completed_at'])): ?>

                                    <?= dashboardEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string)$task['completed_at'])
                                        )
                                    ) ?>

                                <?php else: ?>

                                    Not recorded

                                <?php endif; ?>
                            </td>

                            <td>

                                <?php if (!empty($task['due_date'])): ?>

                                    <?= dashboardEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string)$task['due_date'])
                                        )
                                    ) ?>

                                <?php else: ?>

                                    No deadline

                                <?php endif; ?>

                            </td>

                            <td>
                                <a
                                    href="tasks.php?status=completed"
                                    class="btn-red"
                                >
                                    Review
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

    <h2 class="section-title">Overdue Tasks</h2>

    <section class="panel">

        <div class="panel-header">

            <h2>
                <i class="fa-solid fa-calendar-xmark me-2" style="color:var(--red)"></i>
                Missed Deadlines
            </h2>

            <span class="text-secondary small">
                <?= $stats['overdue'] ?> overdue
            </span>

        </div>

        <?php if (empty($overdueTasks)): ?>

            <div class="empty-state">

                <i class="fa-solid fa-calendar-check"></i>

                <p>
                    No overdue tasks. All current deadlines are on track.
                </p>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="dashboard-table">

                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Employee</th>
                            <th>Status</th>
                            <th>Deadline</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($overdueTasks as $task): ?>

                        <tr>

                            <td>
                                <div class="task-name">
                                    <?= dashboardEscape($task['title']) ?>
                                </div>

                                <div class="task-id">
                                    Task #<?= (int)$task['id'] ?>
                                </div>
                            </td>

                            <td>
                                <?= dashboardEscape(
                                    $task['employee_name'] ?? 'Unknown employee'
                                ) ?>
                            </td>

                            <td>
                                <span class="badge-status <?= dashboardStatusClass((string)$task['status']) ?>">
                                    <?= dashboardEscape(
                                        dashboardStatusLabel((string)$task['status'])
                                    ) ?>
                                </span>
                            </td>

                            <td class="deadline-overdue">

                                <?= dashboardEscape(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime((string)$task['due_date'])
                                    )
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

    <h2 class="section-title">Recent Tasks</h2>

    <section class="panel">

        <div class="panel-header">

            <h2>Latest Assignments</h2>

            <a href="tasks.php" class="btn-light-custom">
                Manage All Tasks
            </a>

        </div>

        <?php if (empty($recentTasks)): ?>

            <div class="empty-state">

                <i class="fa-regular fa-clipboard"></i>

                <p>
                    No tasks have been assigned yet.
                </p>

                <a href="tasks.php" class="btn-red mt-3">
                    Open Task Management
                </a>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="dashboard-table">

                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Employee</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Deadline</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($recentTasks as $task): ?>

                        <?php
                        $taskStatus = (string)$task['status'];
                        $taskPriority = (string)$task['priority'];

                        $isOverdue = !empty($task['due_date'])
                            && strtotime((string)$task['due_date']) < time()
                            && in_array(
                                $taskStatus,
                                ['pending', 'in_progress', 'rework'],
                                true
                            );
                        ?>

                        <tr>

                            <td>

                                <div class="task-name">
                                    <?= dashboardEscape($task['title']) ?>
                                </div>

                                <div class="task-id">
                                    Task #<?= (int)$task['id'] ?>
                                </div>

                            </td>

                            <td>
                                <?= dashboardEscape(
                                    $task['employee_name'] ?? 'Unknown employee'
                                ) ?>
                            </td>

                            <td>
                                <span class="priority-label priority-<?= dashboardEscape($taskPriority) ?>">
                                    <?= dashboardEscape(ucfirst($taskPriority)) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge-status <?= dashboardStatusClass($taskStatus) ?>">
                                    <?= dashboardEscape(
                                        dashboardStatusLabel($taskStatus)
                                    ) ?>
                                </span>

                                <?php if ($isOverdue): ?>

                                    <div class="deadline-overdue mt-1">
                                        Overdue
                                    </div>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if (!empty($task['due_date'])): ?>

                                    <?= dashboardEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string)$task['due_date'])
                                        )
                                    ) ?>

                                <?php else: ?>

                                    No deadline

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

    <section class="panel">

        <div class="panel-body">

            <h2 style="font-size:19px;font-weight:800;">
                Overall Task Acceptance Rate
            </h2>

            <p class="text-secondary mb-0">
                <?= $stats['accepted'] ?> of <?= $stats['total'] ?>
                tasks have been accepted by the owner.
            </p>

            <div class="progress-track">

                <div
                    class="progress-fill"
                    style="width: <?= $completionRate ?>%;"
                ></div>

            </div>

            <div class="text-secondary small mt-2">
                <?= $completionRate ?>% accepted
            </div>

        </div>

    </section>

    <footer class="footer">
        &copy; <?= date('Y') ?>
        Mahalaxmi Medical Store Management System.
        All rights reserved.
    </footer>

</main>

</body>
</html>