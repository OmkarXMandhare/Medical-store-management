<?php

require_once __DIR__ . '/config/database.php';

$message = '';

try {
    // Check whether an owner account already exists
    $check = $pdo->prepare(
        "SELECT id FROM users WHERE role = 'owner' LIMIT 1"
    );
    $check->execute();

    if ($check->fetch()) {
        exit(
            "An owner account already exists. " .
            "Delete this setup file."
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $username === '' || $password === '') {
            $message = 'All fields are required.';
        } elseif (strlen($password) < 12) {
            $message = 'Password must contain at least 12 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            $message = 'Username must be 3–50 characters and use letters, numbers, dots, underscores, or hyphens.';
        } else {

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (name, username, password, role)
                 VALUES (:name, :username, :password, 'owner')"
            );

            $stmt->execute([
                ':name' => $name,
                ':username' => $username,
                ':password' => $hash
            ]);

            echo '<h2>Owner account created successfully!</h2>';
            echo '<p>Username: ' .
                htmlspecialchars($username, ENT_QUOTES, 'UTF-8') .
                '</p>';
            echo '<p>Delete create-owner.php now.</p>';
            exit;
        }
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    $message = 'Unable to create the account. Check the database connection and server logs.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Owner Account</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="login-page">

    <main class="login-container">

        <section class="login-brand">
            <h1>Mahalaxmi</h1>
            <p>Medical & General Store</p>
            <br>
            <p>Create your secure owner account.</p>
        </section>

        <section class="login-card">
            <h2>Create Owner</h2>
            <p class="login-subtitle">
                This account will have owner-level access.
            </p>

            <?php if ($message !== ''): ?>
                <p role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <form method="POST" action="create-owner.php">

                <label for="name">Full Name</label>
                <div class="input-group">
                    <input
                        type="text"
                        id="name"
                        name="name"
                        maxlength="100"
                        required
                    >
                </div>

                <label for="username">Username</label>
                <div class="input-group">
                    <input
                        type="text"
                        id="username"
                        name="username"
                        minlength="3"
                        maxlength="50"
                        autocomplete="username"
                        required
                    >
                </div>

                <label for="password">Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="login-button">
                    Create Owner Account
                </button>

            </form>
        </section>

    </main>

</body>
</html>