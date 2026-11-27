<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
start_app_session();
$user = session_user();
if ($user === null) json_response(['success' => false, 'message' => 'Authentication is required.'], 401);

try {
    $pdo = pdo_connection();
    if ($method === 'GET') {
        $statement = $pdo->prepare(
            'SELECT id, notification_type, title, message, related_module, related_record_id, is_read, created_at
             FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $statement->execute(['user_id' => $user['id']]);
        $rows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'type' => (string) $row['notification_type'],
            'title' => (string) $row['title'], 'message' => (string) $row['message'],
            'module' => (string) $row['related_module'],
            'related_record_id' => $row['related_record_id'] !== null ? (string) $row['related_record_id'] : null,
            'is_read' => (bool) $row['is_read'], 'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
        json_response(['success' => true, 'notifications' => $rows]);
    }

    $data = read_json_request();
    if (($data['action'] ?? '') === 'mark-read') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) json_response(['success' => false, 'message' => 'Select a valid notification.'], 422);
        $update = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
        $update->execute(['id' => $id, 'user_id' => $user['id']]);
        if ($update->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :user_id');
            $check->execute(['id' => $id, 'user_id' => $user['id']]);
            if ($check->fetchColumn() === false) json_response(['success' => false, 'message' => 'Notification not found.'], 404);
        }
        json_response(['success' => true]);
    }
    if (($data['action'] ?? '') === 'mark-all-read') {
        $update = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
        $update->execute(['user_id' => $user['id']]);
        json_response(['success' => true]);
    }
    json_response(['success' => false, 'message' => 'Unknown notification action.'], 400);
} catch (PDOException $error) {
    error_log('TanimTrack notification request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load or update notifications.'], 503);
}
