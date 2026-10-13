<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function escapeOutput(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$ownerId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

if ($ownerId <= 0) {
    http_response_code(401);
    exit('Please log in again.');
}

$employees = [];
$errors = [];

$title = '';
$description = '';
$priority = 'normal';
$dueDate = '';
$assignedTo = 0;

/*
|--------------------------------------------------------------------------
| Load active employees
|--------------------------------------------------------------------------
*/

try {
    $employeeStmt = $pdo->query(
        "SELECT id, name, username
         FROM users
         WHERE role = 'employee'
           AND is_active = 1
         ORDER BY name ASC"
    );

    $employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Loading employees failed: ' . $e->getMessage());
    $errors[] = 'Unable to load employees. Please check the database.';
}

/*
|--------------------------------------------------------------------------
| Process task assignment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'normal'));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $assignedTo = filter_var(
        $_POST['assigned_to'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($assignedTo === false || $assignedTo === null || $assignedTo <= 0) {
        $assignedTo = 0;
        $errors[] = 'Please select an employee.';
    }

    if ($title === '') {
        $errors[] = 'Task title is required.';
    } elseif (mb_strlen($title) > 200) {
        $errors[] = 'Task title cannot exceed 200 characters.';
    }

    if (mb_strlen($description) > 10000) {
        $errors[] = 'Description is too long.';
    }

    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        $errors[] = 'Please select a valid priority.';
    }

    $databaseDueDate = null;

    if ($dueDate !== '') {
        $dateObject = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $dueDate
        );

        $dateErrors = DateTimeImmutable::getLastErrors();

        if (
            $dateObject === false ||
            ($dateErrors !== false &&
                ($dateErrors['warning_count'] > 0 ||
                 $dateErrors['error_count'] > 0)) ||
            $dateObject->format('Y-m-d\TH:i') !== $dueDate
        ) {
            $errors[] = 'Please enter a valid due date and time.';
        } else {
            $databaseDueDate = $dateObject->format('Y-m-d H:i:s');
        }
    }

    /*
    |----------------------------------------------------------------------
    | Confirm employee is active
    |----------------------------------------------------------------------
    */

    if ($assignedTo > 0) {
        try {
            $checkEmployee = $pdo->prepare(
                "SELECT id
                 FROM users
                 WHERE id = :employee_id
                   AND role = 'employee'
                   AND is_active = 1
                 LIMIT 1"
            );

            $checkEmployee->execute([
                'employee_id' => $assignedTo,
            ]);

            if (!$checkEmployee->fetchColumn()) {
                $errors[] = 'The selected employee is inactive or does not exist.';
            }
        } catch (PDOException $e) {
            error_log('Employee validation failed: ' . $e->getMessage());
            $errors[] = 'Unable to validate the selected employee.';
        }
    }

    /*
    |----------------------------------------------------------------------
    | Insert task
    |----------------------------------------------------------------------
    */

    if (!$errors) {
        try {
            $insertStmt = $pdo->prepare(
                "INSERT INTO assigned_tasks (
                    title,
                    description,
                    assigned_to,
                    assigned_by,
                    priority,
                    status,
                    due_date
                ) VALUES (
                    :title,
                    :description,
                    :assigned_to,
                    :assigned_by,
                    :priority,
                    'pending',
                    :due_date
                )"
            );

            $insertStmt->execute([
                'title' => $title,
                'description' => $description !== ''
                    ? $description
                    : null,
                'assigned_to' => $assignedTo,
                'assigned_by' => $ownerId,
                'priority' => $priority,
                'due_date' => $databaseDueDate,
            ]);

            header('Location: tasks.php?message=assigned');
            exit;
        } catch (PDOException $e) {
            error_log('Task assignment failed: ' . $e->getMessage());
            $errors[] = 'The task could not be saved. Please check the database configuration and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Assign Task | Mahalaxmi Medical Store</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --red: #a82020;
            --red-dark: #7f1717;
            --cream: #fffaf2;
            --border: #eadfd3;
            --text: #302727;
            --muted: #796d6d;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            background: var(--red);
            color: white;
            padding: 20px;
        }

        .topbar a {
            color: white;
            text-decoration: none;
        }

        .container-main {
            max-width: 850px;
            margin: 35px auto;
            padding: 0 16px 40px;
        }

        .form-card {
            padding: 28px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(70, 35, 10, .06);
        }

        .form-card h2 {
            font-weight: 800;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--muted);
            margin-bottom: 25px;
        }

        .form-label {
            font-weight: 700;
        }

        .form-control,
        .form-select {
            border-color: #ddc9bd;
            padding: 11px 12px;
            border-radius: 9px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(168, 32, 32, .1);
        }

        .btn-red {
            color: white;
            background: var(--red);
            border: 1px solid var(--red);
            padding: 11px 18px;
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-red:hover {
            background: var(--red-dark);
            color: white;
        }

        .btn-light-custom {
            background: white;
            border: 1px solid var(--border);
            padding: 11px 18px;
            border-radius: 9px;
            color: var(--text);
            text-decoration: none;
        }

        @media (max-width: 576px) {
            .form-card {
                padding: 20px 16px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <strong>Mahalaxmi Medical Store</strong>
            <div class="small">Owner — Task Management</div>
        </div>

        <a href="dashboard.php">← Owner Dashboard</a>
    </div>
</header>

<main class="container-main">

    <div class="mb-3">
        <a href="tasks.php" class="text-decoration-none">
            ← Back to Task Monitoring
        </a>
    </div>

    <section class="form-card">

        <h2>Assign a New Task</h2>

        <p class="subtitle">
            Assign work to an active employee and set a deadline.
        </p>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Please correct the following:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escapeOutput($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$employees): ?>
            <div class="alert alert-warning">
                No active employees are available. Add or activate an employee
                before assigning a task.
            </div>
        <?php endif; ?>

        <form method="POST" action="assign-task.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeOutput($csrfToken) ?>"
            >

            <div class="mb-3">
                <label for="title" class="form-label">
                    Task Title <span class="text-danger">*</span>
                </label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    class="form-control"
                    maxlength="200"
                    required
                    value="<?= escapeOutput($title) ?>"
                    placeholder="Example: Check medicine stock"
                >
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">
                    Task Description
                </label>

                <textarea
                    id="description"
                    name="description"
                    class="form-control"
                    rows="4"
                    maxlength="10000"
                    placeholder="Describe the work and any instructions..."
                ><?= escapeOutput($description) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="assigned_to" class="form-label">
                    Assign to Employee <span class="text-danger">*</span>
                </label>

                <select
                    id="assigned_to"
                    name="assigned_to"
                    class="form-select"
                    required
                >
                    <option value="">Select an employee</option>

                    <?php foreach ($employees as $employee): ?>
                        <option
                            value="<?= (int)$employee['id'] ?>"
                            <?= $assignedTo === (int)$employee['id'] ? 'selected' : '' ?>
                        >
                            <?= escapeOutput($employee['name']) ?>
                            (<?= escapeOutput($employee['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-3">

                <div class="col-md-6">
                    <label for="priority" class="form-label">
                        Priority
                    </label>

                    <select
                        id="priority"
                        name="priority"
                        class="form-select"
                    >
                        <?php foreach ([
                            'low' => 'Low',
                            'normal' => 'Normal',
                            'high' => 'High',
                            'urgent' => 'Urgent',
                        ] as $value => $label): ?>
                            <option
                                value="<?= escapeOutput($value) ?>"
                                <?= $priority === $value ? 'selected' : '' ?>
                            >
                                <?= escapeOutput($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="due_date" class="form-label">
                        Due Date and Time
                    </label>

                    <input
                        type="datetime-local"
                        id="due_date"
                        name="due_date"
                        class="form-control"
                        value="<?= escapeOutput($dueDate) ?>"
                    >

                    <small class="text-secondary">
                        Optional
                    </small>
                </div>

            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">

                <button
                    type="submit"
                    class="btn btn-red"
                    <?= !$employees ? 'disabled' : '' ?>
                >
                    Assign Task
                </button>

                <a href="tasks.php" class="btn-light-custom">
                    Cancel
                </a>

            </div>

        </form>

    </section>

</main>

</body>
</html>