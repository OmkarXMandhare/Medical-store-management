<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function ownerActivityEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$allowedStatuses = [
    'submitted',
    'reviewed',
    'resolved'
];

$allowedPriorities = [
    'low',
    'normal',
    'high',
    'urgent'
];

$statusLabels = [
    'submitted' => 'Submitted',
    'reviewed' => 'Reviewed',
    'resolved' => 'Resolved'
];

$typeLabels = [
    'daily_work' => 'Daily Work',
    'stock_issue' => 'Stock Issue',
    'medicine_issue' => 'Medicine Issue',
    'customer_issue' => 'Customer Issue',
    'equipment_issue' => 'Equipment Issue',
    'other' => 'Other'
];

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Update an employee report
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'Invalid security token. Refresh the page and try again.'
            );
        }

        $activityId = filter_input(
            INPUT_POST,
            'activity_id',
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['status'] ?? '';
        $ownerNote = trim($_POST['owner_note'] ?? '');

        if (!$activityId || $activityId < 1) {
            throw new RuntimeException('Invalid report selected.');
        }

        if (!in_array($newStatus, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid report status.');
        }

        if (mb_strlen($ownerNote) > 5000) {
            throw new RuntimeException(
                'Owner notes cannot exceed 5000 characters.'
            );
        }

        $ownerId = (int) (
            $_SESSION['user_id']
            ?? $_SESSION['id']
            ?? 0
        );

        if ($ownerId <= 0) {
            throw new RuntimeException(
                'Your login session is invalid. Please log in again.'
            );
        }

        $update = $pdo->prepare("
            UPDATE employee_activities
            SET
                status = :status,
                owner_note = :owner_note,
                reviewed_by = :reviewed_by,
                reviewed_at = NOW()
            WHERE id = :id
        ");

        $update->execute([
            'status' => $newStatus,
            'owner_note' => $ownerNote !== '' ? $ownerNote : null,
            'reviewed_by' => $ownerId,
            'id' => $activityId
        ]);

        header(
            'Location: activities.php?updated=1',
            true,
            303
        );

        exit;

    } catch (PDOException $e) {
        $error = 'Unable to update the report. Check the database configuration.';

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['updated'])) {
    $message = 'Employee report updated successfully.';
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filterStatus = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}

if (!in_array($filterPriority, $allowedPriorities, true)) {
    $filterPriority = '';
}

$search = mb_substr($search, 0, 100);

/*
|--------------------------------------------------------------------------
| Fetch reports and summary statistics
|--------------------------------------------------------------------------
*/

$activities = [];
$stats = [
    'total' => 0,
    'submitted' => 0,
    'reviewed' => 0,
    'resolved' => 0
];

try {
    $statsQuery = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(status = 'submitted'), 0) AS submitted_count,
            COALESCE(SUM(status = 'reviewed'), 0) AS reviewed_count,
            COALESCE(SUM(status = 'resolved'), 0) AS resolved_count
        FROM employee_activities
    ");

    $statsResult = $statsQuery->fetch(PDO::FETCH_ASSOC);

    if ($statsResult) {
        $stats['total'] = (int) $statsResult['total_count'];
        $stats['submitted'] = (int) $statsResult['submitted_count'];
        $stats['reviewed'] = (int) $statsResult['reviewed_count'];
        $stats['resolved'] = (int) $statsResult['resolved_count'];
    }

    $sql = "
        SELECT
            a.id,
            a.employee_id,
            a.activity_type,
            a.title,
            a.description,
            a.priority,
            a.status,
            a.owner_note,
            a.created_at,
            a.reviewed_at,
            u.name AS employee_name,
            u.username AS employee_username
        FROM employee_activities a
        LEFT JOIN users u
            ON u.id = a.employee_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($filterStatus !== '') {
        $sql .= " AND a.status = :status";
        $params['status'] = $filterStatus;
    }

    if ($filterPriority !== '') {
        $sql .= " AND a.priority = :priority";
        $params['priority'] = $filterPriority;
    }

    if ($search !== '') {
        $sql .= "
            AND (
                a.title LIKE :search_title
                OR a.description LIKE :search_description
                OR u.name LIKE :search_name
                OR u.username LIKE :search_username
            )
        ";

        $searchTerm = '%' . $search . '%';

        $params['search_title'] = $searchTerm;
        $params['search_description'] = $searchTerm;
        $params['search_name'] = $searchTerm;
        $params['search_username'] = $searchTerm;
    }

    $sql .= "
        ORDER BY
            CASE a.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                ELSE 4
            END,
            CASE a.status
                WHEN 'submitted' THEN 1
                WHEN 'reviewed' THEN 2
                ELSE 3
            END,
            a.created_at DESC
        LIMIT 200
    ";

    $query = $pdo->prepare($sql);
    $query->execute($params);

    $activities = $query->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'Unable to load employee reports. Check the database configuration.';
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

    <title>Employee Activities | Owner Dashboard</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/owner.css">

    <style>
        .activities-container {
            max-width: 1250px;
            margin: 25px auto;
            padding: 0 16px 40px;
        }

        .activities-hero {
            background: linear-gradient(135deg, #8f1724, #c83240);
            color: white;
            padding: 25px;
            border-radius: 14px;
            margin-bottom: 22px;
        }

        .activities-hero h2 {
            margin-top: 0;
        }

        .activity-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .activity-stat-card {
            padding: 20px;
            background: white;
            border: 1px solid #f0dadd;
            border-radius: 12px;
        }

        .activity-stat-card p {
            color: #666;
            margin: 0 0 10px;
        }

        .activity-stat-card h3 {
            color: #981c2b;
            font-size: 28px;
            margin: 0;
        }

        .activity-panel {
            background: white;
            border: 1px solid #f0dadd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 22px;
        }

        .activity-panel h3 {
            color: #8f1724;
            margin-top: 0;
        }

        .activity-filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .activity-filters label,
        .review-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .activity-filters input,
        .activity-filters select,
        .review-form select,
        .review-form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font: inherit;
        }

        .activity-button {
            display: inline-block;
            padding: 11px 15px;
            background: #971b2a;
            color: white;
            border: none;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            font: inherit;
        }

        .activity-button:hover {
            background: #71121e;
        }

        .activity-reset {
            background: #666;
        }

        .activity-table-wrapper {
            overflow-x: auto;
        }

        .activity-table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        .activity-table th,
        .activity-table td {
            padding: 14px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: top;
        }

        .activity-table th {
            background: #fff2f3;
            color: #751622;
        }

        .activity-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-submitted {
            background: #fff1d6;
            color: #855800;
        }

        .status-reviewed {
            background: #e0efff;
            color: #1557a0;
        }

        .status-resolved {
            background: #e1f6e8;
            color: #176b35;
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

        .review-form {
            min-width: 190px;
            display: grid;
            gap: 9px;
        }

        .review-form textarea {
            min-height: 75px;
            resize: vertical;
        }

        .activity-message {
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .activity-success {
            background: #e5f7eb;
            color: #176b35;
        }

        .activity-error {
            background: #fff0f0;
            color: #8f1724;
        }

        .activity-back {
            display: inline-block;
            margin-bottom: 15px;
            color: #971b2a;
            text-decoration: none;
            font-weight: 600;
        }

        .activity-empty {
            text-align: center;
            padding: 25px;
            color: #666;
        }

        @media (max-width: 850px) {
            .activity-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .activity-filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 520px) {
            .activity-stat-grid,
            .activity-filters {
                grid-template-columns: 1fr;
            }

            .activity-panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<header class="owner-header">
    <h1>Mahalaxmi Medical Store</h1>
    <p>Owner Activity Management</p>
</header>

<main class="activities-container">

    <a href="dashboard.php" class="activity-back">
        ← Back to Owner Dashboard
    </a>

    <section class="activities-hero">
        <h2>Employee Activity Reports</h2>
        <p>Review employee updates and manage reported store issues.</p>
    </section>

    <?php if ($message): ?>
        <div class="activity-message activity-success">
            <?= ownerActivityEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="activity-message activity-error">
            <?= ownerActivityEscape($error) ?>
        </div>
    <?php endif; ?>

    <section class="activity-stat-grid">

        <div class="activity-stat-card">
            <p>Total Reports</p>
            <h3><?= $stats['total'] ?></h3>
        </div>

        <div class="activity-stat-card">
            <p>Awaiting Review</p>
            <h3><?= $stats['submitted'] ?></h3>
        </div>

        <div class="activity-stat-card">
            <p>Reviewed</p>
            <h3><?= $stats['reviewed'] ?></h3>
        </div>

        <div class="activity-stat-card">
            <p>Resolved</p>
            <h3><?= $stats['resolved'] ?></h3>
        </div>

    </section>

    <section class="activity-panel">

        <h3>Search and Filter Reports</h3>

        <form method="GET" action="activities.php" class="activity-filters">

            <div>
                <label for="search">Search</label>

                <input
                    type="search"
                    id="search"
                    name="search"
                    value="<?= ownerActivityEscape($search) ?>"
                    placeholder="Employee, title, or description"
                >
            </div>

            <div>
                <label for="status">Status</label>

                <select id="status" name="status">
                    <option value="">All statuses</option>

                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option
                            value="<?= ownerActivityEscape($key) ?>"
                            <?= $filterStatus === $key ? 'selected' : '' ?>
                        >
                            <?= ownerActivityEscape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="priority">Priority</label>

                <select id="priority" name="priority">
                    <option value="">All priorities</option>

                    <?php foreach ($allowedPriorities as $priority): ?>
                        <option
                            value="<?= ownerActivityEscape($priority) ?>"
                            <?= $filterPriority === $priority ? 'selected' : '' ?>
                        >
                            <?= ownerActivityEscape(ucfirst($priority)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="activity-button">
                    Apply Filters
                </button>

                <a
                    href="activities.php"
                    class="activity-button activity-reset"
                >
                    Reset
                </a>
            </div>

        </form>

    </section>

    <section class="activity-panel">

        <h3>Submitted Reports</h3>

        <?php if (empty($activities)): ?>

            <p class="activity-empty">
                No reports match your filters.
            </p>

        <?php else: ?>

            <div class="activity-table-wrapper">

                <table class="activity-table">

                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Report</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Owner Review</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($activities as $activity): ?>

                        <?php
                        $status = $activity['status'];

                        if (!isset($statusLabels[$status])) {
                            $status = 'submitted';
                        }

                        $priority = $activity['priority'];

                        if (!in_array($priority, $allowedPriorities, true)) {
                            $priority = 'normal';
                        }

                        $type = $activity['activity_type'];

                        if (!isset($typeLabels[$type])) {
                            $type = 'other';
                        }
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= ownerActivityEscape(
                                        $activity['employee_name']
                                        ?: 'Unknown Employee'
                                    ) ?>
                                </strong>

                                <br>

                                <small>
                                    <?= ownerActivityEscape(
                                        $activity['employee_username'] ?? ''
                                    ) ?>
                                </small>

                                <br><br>

                                <small>
                                    <?= ownerActivityEscape(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime($activity['created_at'])
                                        )
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <strong>
                                    <?= ownerActivityEscape($activity['title']) ?>
                                </strong>

                                <p>
                                    <small>
                                        <?= ownerActivityEscape($typeLabels[$type]) ?>
                                    </small>
                                </p>

                                <p>
                                    <?= nl2br(
                                        ownerActivityEscape($activity['description'])
                                    ) ?>
                                </p>
                            </td>

                            <td>
                                <span class="priority-<?= ownerActivityEscape($priority) ?>">
                                    <?= ownerActivityEscape(ucfirst($priority)) ?>
                                </span>
                            </td>

                            <td>
                                <span class="activity-badge status-<?= ownerActivityEscape($status) ?>">
                                    <?= ownerActivityEscape($statusLabels[$status]) ?>
                                </span>

                                <?php if (!empty($activity['reviewed_at'])): ?>
                                    <br><br>

                                    <small>
                                        Reviewed:
                                        <?= ownerActivityEscape(
                                            date(
                                                'd M Y',
                                                strtotime($activity['reviewed_at'])
                                            )
                                        ) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="activities.php"
                                    class="review-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= ownerActivityEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="activity_id"
                                        value="<?= (int) $activity['id'] ?>"
                                    >

                                    <label
                                        for="status-<?= (int) $activity['id'] ?>"
                                    >
                                        Update status
                                    </label>

                                    <select
                                        id="status-<?= (int) $activity['id'] ?>"
                                        name="status"
                                        required
                                    >
                                        <?php foreach ($statusLabels as $key => $label): ?>
                                            <option
                                                value="<?= ownerActivityEscape($key) ?>"
                                                <?= $status === $key ? 'selected' : '' ?>
                                            >
                                                <?= ownerActivityEscape($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <label
                                        for="note-<?= (int) $activity['id'] ?>"
                                    >
                                        Owner's note
                                    </label>

                                    <textarea
                                        id="note-<?= (int) $activity['id'] ?>"
                                        name="owner_note"
                                        maxlength="5000"
                                        placeholder="Write feedback or resolution..."
                                    ><?= ownerActivityEscape($activity['owner_note']) ?></textarea>

                                    <button
                                        type="submit"
                                        class="activity-button"
                                    >
                                        Save Review
                                    </button>

                                </form>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <p>
                Showing up to 200 matching reports.
            </p>

        <?php endif; ?>

    </section>

</main>

</body>
</html>