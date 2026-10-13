<?php

session_start();

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'owner') {
        header('Location: owner/dashboard.php');
        exit;
    }

    if ($_SESSION['role'] === 'employee') {
        header('Location: employee/dashboard.php');
        exit;
    }
}

require_once __DIR__ . '/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, username, password, role, is_active
                 FROM users
                 WHERE username = :username
                 LIMIT 1"
            );

            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if (
                $user &&
                (int) $user['is_active'] === 1 &&
                password_verify($password, $user['password'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'owner') {
                    header('Location: owner/dashboard.php');
                    exit;
                }

                if ($user['role'] === 'employee') {
                    header('Location: employee/dashboard.php');
                    exit;
                }

                session_unset();
                session_destroy();
                $error = 'Invalid account permissions.';
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = 'Unable to sign in right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Mahalaxmi Medical Store</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="login-page">

    <main class="login-container">

        <section class="login-brand">
            <div class="brand-icon">
                <i class="fa-solid fa-house-medical"></i>
            </div>

            <h1>Mahalaxmi</h1>
            <p>Medical &amp; General Store</p>

            <div class="brand-divider"></div>

            <p class="brand-description">
                Your store. Your team. One place to stay connected.
            </p>
        </section>

        <section class="login-card">
            <h2>Welcome Back</h2>
            <p class="login-subtitle">
                Sign in to access your dashboard.
            </p>

            <?php if ($error !== ''): ?>
                <div role="alert"
                     style="background:#FEE2E2;color:#991B1B;padding:12px;border-radius:8px;margin-bottom:16px;">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">

                <label for="username">Username</label>

                <div class="input-group">
                    <i class="fa-regular fa-user"></i>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        autocomplete="username"
                        maxlength="50"
                        required
                    >
                </div>

                <label for="password">Password</label>

                <div class="input-group">
                    <i class="fa-solid fa-lock"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="login-button">
                    Sign In <i class="fa-solid fa-arrow-right"></i>
                </button>

            </form>

            <p class="login-footer">
                Authorized employees and owner only.
            </p>
        </section>

    </main>

</body>
</html>