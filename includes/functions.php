/**
 * Record an activity in the activities table.
 *
 * @param PDO    $pdo         Database connection.
 * @param int    $userId      ID of the user performing the action.
 * @param string $activityType Short action name.
 * @param string $description Human-readable activity details.
 * @param string $priority    low, normal, high, or urgent.
 * @param string $status      pending, acknowledged, or resolved.
 */
function logActivity(
    PDO $pdo,
    int $userId,
    string $activityType,
    string $description,
    string $priority = 'normal',
    string $status = 'pending'
): bool {
    $allowedPriorities = ['low', 'normal', 'high', 'urgent'];
    $allowedStatuses = ['pending', 'acknowledged', 'resolved'];

    if (
        $userId < 1 ||
        trim($activityType) === '' ||
        !in_array($priority, $allowedPriorities, true) ||
        !in_array($status, $allowedStatuses, true)
    ) {
        return false;
    }

    try {
        $sql = "
            INSERT INTO activities (
                user_id,
                activity_type,
                description,
                priority,
                status
            )
            VALUES (
                :user_id,
                :activity_type,
                :description,
                :priority,
                :status
            )
        ";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([
            'user_id' => $userId,
            'activity_type' => mb_substr(
                trim($activityType),
                0,
                100
            ),
            'description' => $description,
            'priority' => $priority,
            'status' => $status,
        ]);

    } catch (PDOException $e) {
        error_log('Activity logging error: ' . $e->getMessage());

        return false;
    }
}