```php
<?php
require_once '../includes/auth.php';
requireRole('owner');

require_once '../config/database.php';

date_default_timezone_set('Asia/Kolkata');

$selectedDate = $_GET['date'] ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Count active checklist items.
$itemStmt = $pdo->query("
    SELECT COUNT(*)
    FROM checklist_items
    WHERE is_active = 1
");
$totalTasks = (int) $itemStmt->fetchColumn();

// Get employees and their checklist progress for the selected date.
$sql = "
    SELECT
        u.id,
        u.name,
        u.username,
        COUNT(ci.id) AS total_tasks,
        COALESCE(SUM(
            CASE WHEN cl.completed = 1 THEN 1 ELSE 0 END
        ), 0) AS completed_tasks,
        MAX(cl.created_at) AS last_updated
    FROM users u
    LEFT JOIN checklist_items ci
        ON ci.is_active = 1
    LEFT JOIN checklist_logs cl
        ON cl.item_id = ci.id
        AND cl.user_id = u.id
        AND cl.checklist_date = :checklist_date
    WHERE u.role = 'employee'
        AND u.is_active = 1
    GROUP BY u.id, u.name, u.username
    ORDER BY u.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['checklist_date' => $selectedDate]);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary statistics.
$totalEmployees = count($employees);
$finishedEmployees = 0;
$completedTasksOverall = 0;
$totalTasksOverall = 0;

foreach ($employees as $employee) {
    $completed = (int) $employee['completed_tasks'];
    $total = (int) $employee['total_tasks'];

    $completedTasksOverall += $completed;
    $totalTasksOverall += $total;

    if ($total > 0 && $completed >= $total) {
        $finishedEmployees++;
    }
}

$overallPercent = $totalTasksOverall > 0
    ? (int) round(($completedTasksOverall / $totalTasksOverall) * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Checklist Monitoring | Mahalaxmi Medical Store</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            margin: 0;
            background: #fff8f0;
            color: #282323;
            font-family: Arial, sans-serif;
        }

        .page-header {
            background: #b51f2e;
            color: white;
            padding: 20px 24px;
        }

        .page-header h1 {
            margin: 0 0 5px;
            font-size: 24px;
        }

        .page-header p {
            margin: 0;
        }

        .page-container {
            width: min(1100px, 94%);
            margin: 28px auto;
        }

        .top-links {
            margin-bottom: 20px;
        }

        .top-links a {
            color: #b51f2e;
            margin-right: 16px;
        }

        .date-form {
            background: white;
            border: 1px solid #ead8cc;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .date-form label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .date-form input,
        .date-form button {
            padding: 10px 12px;
            border-radius: 7px;
            font-size: 15px;
        }

        .date-form input {
            border: 1px solid #d8c7bb;
        }

        .date-form button {
            border: 0;
            background: #b51f2e;
            color: white;
            cursor: pointer;
            margin-left: 6px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card,
        .employee-card {
            background: white;
            border: 1px solid #ead8cc;
            border-radius: 12px;
            padding: 18px;
        }

        .stat-card .number {
            display: block;
            font-size: 28px;
            font-weight: bold;
            color: #b51f2e;
            margin-top: 8px;
        }

        .employee-card {
            margin-bottom: 14px;
        }

        .employee-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .employee-heading h3 {
            margin: 0 0 5px;
        }

        .muted {
            color: #716760;
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
            background: #fff0d5;
            color: #805300;
        }

        .status.done {
            background: #e1f5e7;
            color: #176534;
        }

        .progress-track {
            height: 10px;
            background: #eee4dc;
            border-radius: 20px;
            overflow: hidden;
            margin: 14px 0 8px;
        }

        .progress-fill {
            height: 100%;
            background: #b51f2e;
            border-radius: 20px;
        }

        .employee-details {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 14px;
        }

        .empty-state {
            padding: 24px;
            background: white;
            border: 1px solid #ead8cc;
            border-radius: 12px;
        }

        @media (max-width: 650px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .employee-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .date-form input {
                width: 100%;
                box-sizing: border-box;
                margin-bottom: 10px;
            }

            .date-form button {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<header class="page-header">
    <h1>Mahalaxmi Medical Store</h1>
    <p>Owner — Daily Checklist Monitoring</p>
</header>

<main class="page-container">

    <nav class="top-links">
        <a href="dashboard.php">← Owner Dashboard</a>
        <a href="../logout.php">Log out</a>
    </nav>

    <h2>Employee Checklist Overview</h2>
    <p class="muted">
        Review employee progress for the selected date.
    </p>

    <form method="GET" class="date-form">
        <label for="date">Select checklist date</label>
        <input
            type="date"
            id="date"
            name="date"
            value="<?= h($selectedDate) ?>"
            max="<?= h(date('Y-m-d')) ?>"
            required
        >
        <button type="submit">View Progress</button>
    </form>

    <section class="stats">
        <div class="stat-card">
            Active Employees
            <span class="number"><?= $totalEmployees ?></span>
        </div>

        <div class="stat-card">
            Fully Completed
            <span class="number">
                <?= $finishedEmployees ?> / <?= $totalEmployees ?>
            </span>
        </div>

        <div class="stat-card">
            Overall Progress
            <span class="number"><?= $overallPercent ?>%</span>
            <div class="muted">
                <?= $completedTasksOverall ?> of <?= $totalTasksOverall ?> tasks
            </div>
        </div>
    </section>

    <h2>Employee Details</h2>

    <?php if (empty($employees)): ?>
        <div class="empty-state">
            No active employees were found. Add employees from Employee Management.
        </div>
    <?php else: ?>

        <?php foreach ($employees as $employee): ?>
            <?php
                $total = (int) $employee['total_tasks'];
                $completed = (int) $employee['completed_tasks'];

                $percent = $total > 0
                    ? (int) round(($completed / $total) * 100)
                    : 0;

                $isDone = $total > 0 && $completed >= $total;
                $pending = max(0, $total - $completed);
            ?>

            <article class="employee-card">
                <div class="employee-heading">
                    <div>
                        <h3><?= h($employee['name']) ?></h3>
                        <div class="muted">
                            Username: <?= h($employee['username']) ?>
                        </div>
                    </div>

                    <span class="status <?= $isDone ? 'done' : '' ?>">
                        <?php if ($total === 0): ?>
                            No tasks configured
                        <?php elseif ($isDone): ?>
                            Completed
                        <?php else: ?>
                            In progress
                        <?php endif; ?>
                    </span>
                </div>

                <div class="progress-track">
                    <div
                        class="progress-fill"
                        style="width: <?= $percent ?>%;"
                    ></div>
                </div>

                <div class="employee-details">
                    <span>
                        <strong><?= $completed ?>/<?= $total ?></strong>
                        tasks completed (<?= $percent ?>%)
                    </span>

                    <span>
                        <strong><?= $pending ?></strong> pending
                    </span>

                    <span>
                        Last updated:
                        <?= $employee['last_updated']
                            ? h(date('d M Y, h:i A', strtotime($employee['last_updated'])))
                            : 'No activity yet' ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
```
