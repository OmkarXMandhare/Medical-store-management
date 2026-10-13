<?php

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requestToken(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$method = $_SERVER['REQUEST_METHOD'];
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];

if ($method === 'GET') {
    try {
        if ($role === 'owner') {
            $stmt = $pdo->query(
                "SELECT a.id, a.activity_type, a.description,
                        a.priority, a.status, a.created_at,
                        u.name AS employee_name
                 FROM activities a
                 JOIN users u ON u.id = a.user_id
                 ORDER BY a.created_at DESC
                 LIMIT 100"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT a.id, a.activity_type, a.description,
                        a.priority, a.status, a.created_at,
                        u.name AS employee_name
                 FROM activities a
                 JOIN users u ON u.id = a.user_id
                 WHERE a.user_id = :user_id
                 ORDER BY a.created_at DESC
                 LIMIT 100"
            );

            $stmt->execute(['user_id' => $userId]);
        }

        respond(200, [
            'success' => true,
            'activities' => $stmt->fetchAll()
        ]);

    } catch (PDOException $e) {
        error_log('Activity retrieval failed: ' . $e->getMessage());
        respond(500, ['success' => false, 'message' => 'Unable to load activities.']);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = $_POST;
}

$token = $input['csrf_token'] ?? '';

if (
    !is_string($token) ||
    !hash_equals(requestToken(), $token)
) {
    respond(403, ['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
}

$action = $input['action'] ?? 'create';

try {
    if ($action === 'create') {
        if ($role !== 'employee') {
            respond(403, ['success' => false, 'message' => 'Only employees can submit activities.']);
        }

        $allowedTypes = [
            'Store opened',
            'Stock received',
            'Medicine out of stock',
            'Purchase bills completed',
            'Cleaning completed',
            'Refrigerator checked',
            'Store closing',
            'Other'
        ];

        $type = trim($input['activity_type'] ?? '');
        $description = trim($input['description'] ?? '');
        $priority = $input['priority'] ?? 'normal';

        if (!in_array($type, $allowedTypes, true)) {
            respond(422, ['success' => false, 'message' => 'Choose a valid activity type.']);
        }

        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            respond(422, ['success' => false, 'message' => 'Choose a valid priority.']);
        }

        if (strlen($description) > 3000) {
            respond(422, ['success' => false, 'message' => 'Description must be 3000 characters or fewer.']);
        }

        if ($type === 'Other' && $description === '') {
            respond(422, ['success' => false, 'message' => 'Please describe the activity.']);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO activities
                (user_id, activity_type, description, priority)
             VALUES
                (:user_id, :activity_type, :description, :priority)"
        );

        $stmt->execute([
            'user_id' => $userId,
            'activity_type' => $type,
            'description' => $description,
            'priority' => $priority
        ]);

        respond(201, [
            'success' => true,
            'message' => 'Activity submitted successfully.'
        ]);
    }

    if ($action === 'update_status') {
        if ($role !== 'owner') {
            respond(403, ['success' => false, 'message' => 'Owner access required.']);
        }

        $activityId = filter_var(
            $input['activity_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $status = $input['status'] ?? '';

        if (
            !$activityId ||
            !in_array($status, ['acknowledged', 'resolved'], true)
        ) {
            respond(422, ['success' => false, 'message' => 'Invalid activity or status.']);
        }

        $stmt = $pdo->prepare(
            "UPDATE activities
             SET status = :status
             WHERE id = :id"
        );

        $stmt->execute([
            'status' => $status,
            'id' => $activityId
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare("SELECT id FROM activities WHERE id = :id");
            $check->execute(['id' => $activityId]);

            if (!$check->fetch()) {
                respond(404, ['success' => false, 'message' => 'Activity not found.']);
            }
        }

        respond(200, [
            'success' => true,
            'message' => 'Activity status updated.'
        ]);
    }

    respond(422, ['success' => false, 'message' => 'Unknown action.']);

} catch (PDOException $e) {
    error_log('Activity API error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'A database error occurred.']);
}