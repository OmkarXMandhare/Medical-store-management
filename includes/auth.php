<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        header('Location: /mahalaxmi-medical/login.php');
        exit;
    }
}

function requireRole(string $role): void
{
    requireLogin();

    if ($_SESSION['role'] !== $role) {
        http_response_code(403);
        exit('403 Forbidden: You do not have permission to access this page.');
    }
}