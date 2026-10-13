<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('owner');

require_once __DIR__ . '/../config/database.php';

$message = '';
$messageType = 'success';

// Create employee account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'create_employee') {

        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $username === '' || $password === '') {
            $message = 'All fields are required.';
            $messageType = 'error';

        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            $message = 'Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.';
            $messageType = 'error';

        } elseif (strlen($password) < 12) {
            $message = 'Password must contain at least 12 characters.';
            $messageType = 'error';

        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, password, role)
                     VALUES (:name, :username, :password, 'employee')"
                );

                $stmt->execute([
                    ':name' => $name,
                    ':username' => $username,
                    ':password' => $hash
                ]);

                header('Location: employees.php?created=1');
                exit;

            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'That username is already in use.';
                } else {
                    error_log('Employee creation failed: ' . $e->getMessage());
                    $message = 'Unable to create the employee account.';
                }

                $messageType = 'error';
            }
        }
    }

    // Activate or deactivate an employee
    if ($action === 'toggle_status') {

        $employeeId = filter_input(
            INPUT_POST,
            'employee_id',
            FILTER_VALIDATE_INT
        );

        if (!$employeeId || $employeeId < 1) {
            $message = 'Invalid employee.';
            $messageType = 'error';

        } else {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                 WHERE id = :id AND role = 'employee'"
            );

            $stmt->execute([':id' => $employeeId]);

            header('Location: employees.php?updated=1');
            exit;
        }
    }
}

if (isset($_GET['created'])) {
    $message = 'Employee account created successfully.';
}

if (isset($_GET['updated'])) {
    $message = 'Employee status updated successfully.';
}

// Retrieve employees
$stmt = $pdo->query(
    "SELECT id, name, username, is_active, created_at
     FROM users
     WHERE role = 'employee'
     ORDER BY id DESC"
);

$employees = $stmt->fetchAll();

function escapeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Employee Management | Mahalaxmi Medical Store</title>
    

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/owner.css">
    
</head>
<body>

<header class="page-header">
    <div>
        <h1>Mahalaxmi Medical Store</h1>
        <p>Employee Management</p>
        
    </div>

    <a href="dashboard.php" class="back-link">Dashboard</a>
</header>

<main class="employee-management">

    <section class="management-card">
        <h2>Create Employee Account</h2>
        <p>Add an employee who can submit store activity updates.</p>

        <?php if ($message !== ''): ?>
            <div class="notice <?= $messageType === 'error' ? 'notice-error' : '' ?>"
                 role="status">
                <?= escapeHtml($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="employees.php" class="employee-form">

            <input type="hidden" name="action" value="create_employee">

            <label for="name">Employee Full Name</label>
            <input
                type="text"
                id="name"
                name="name"
                maxlength="100"
                required
            >

            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                minlength="3"
                maxlength="50"
                pattern="[a-zA-Z0-9_.-]{3,50}"
                autocomplete="off"
                required
            >

            <label for="password">Temporary Password</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="12"
                autocomplete="new-password"
                required
            >

            <button type="submit" class="primary-button">
                Create Employee
            </button>

        </form>
    </section>

    <section class="management-card">
        <h2>Employee Accounts</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$employees): ?>
                    <tr>
                        <td colspan="4">No employee accounts created yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><?= escapeHtml($employee['name']) ?></td>
                            <td><?= escapeHtml($employee['username']) ?></td>

                            <td>
                                <?php if ((int) $employee['is_active'] === 1): ?>
                                    <span class="status-active">Active</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <form method="POST" action="employees.php">
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="toggle_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="employee_id"
                                        value="<?= (int) $employee['id'] ?>"
                                    >

                                    <button type="submit" class="secondary-button">
                                        <?= (int) $employee['is_active'] === 1
                                            ? 'Deactivate'
                                            : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <a href="../logout.php" class="logout-link">Log out</a>

</main>

</body>
</html>