<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');

function process_care_reminders(PDO $pdo): void
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $watering = $pdo->prepare(
        'SELECT t.user_id, t.id, t.plant_id, t.scheduled_on, t.scheduled_time, t.reminder_time, p.name
         FROM watering_tasks t
         JOIN plants p ON p.user_id = t.user_id AND p.id = t.plant_id
         WHERE t.status = :status
           AND (t.scheduled_on < :before_today OR
             (t.scheduled_on = :on_today AND COALESCE(t.reminder_time, t.scheduled_time) <= :now_time))'
    );
    $watering->execute([
        'status' => 'pending', 'before_today' => $now->format('Y-m-d'),
        'on_today' => $now->format('Y-m-d'), 'now_time' => $now->format('H:i:s'),
    ]);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO notifications
         (user_id, notification_type, title, message, related_module, related_record_id, related_plant_id, scheduled_at, event_key)
         VALUES (:user_id, :type, :title, :message, :module, :record_id, :plant_id, :scheduled_at, :event_key)'
    );
    foreach ($watering->fetchAll() as $task) {
        $isOverdue = (string) $task['scheduled_on'] < $now->format('Y-m-d');
        $dueAt = (string) $task['scheduled_on'] . ' ' . (string) ($task['reminder_time'] ?? $task['scheduled_time']);
        $insert->execute([
            'user_id' => $task['user_id'], 'type' => $isOverdue ? 'watering_overdue' : 'watering_due',
            'title' => ($isOverdue ? 'Overdue watering: ' : 'Watering due today: ') . (string) $task['name'],
            'message' => 'Check soil moisture before watering. Scheduled ' . $dueAt . '.',
            'module' => 'watering', 'record_id' => $task['id'], 'plant_id' => $task['plant_id'],
            'scheduled_at' => $dueAt, 'event_key' => 'care-task:watering:' . $task['id'],
        ]);
    }

    $fertilizer = $pdo->prepare(
        'SELECT t.user_id, t.id, t.plant_id, t.scheduled_on, t.scheduled_time, t.fertilizer_category, t.guidance, p.name
         FROM fertilizer_tasks t
         JOIN plants p ON p.user_id = t.user_id AND p.id = t.plant_id
         WHERE t.status = :status
           AND (t.scheduled_on < :before_today OR
             (t.scheduled_on = :on_today AND t.scheduled_time <= :now_time))'
    );
    $fertilizer->execute([
        'status' => 'pending', 'before_today' => $now->format('Y-m-d'),
        'on_today' => $now->format('Y-m-d'), 'now_time' => $now->format('H:i:s'),
    ]);
    foreach ($fertilizer->fetchAll() as $task) {
        $isOverdue = (string) $task['scheduled_on'] < $now->format('Y-m-d');
        $dueAt = (string) $task['scheduled_on'] . ' ' . (string) $task['scheduled_time'];
        $insert->execute([
            'user_id' => $task['user_id'], 'type' => $isOverdue ? 'fertilizer_overdue' : 'fertilizer_due',
            'title' => ($isOverdue ? 'Overdue fertilizer task: ' : 'Fertilizer due today: ') . (string) $task['name'],
            'message' => str_replace('_', ' ', (string) $task['fertilizer_category']) . '. ' . (string) $task['guidance'],
            'module' => 'plant-care', 'record_id' => $task['id'], 'plant_id' => $task['plant_id'],
            'scheduled_at' => $dueAt, 'event_key' => 'care-task:fertilizer:' . $task['id'],
        ]);
    }
}

while (true) {
    try {
        process_care_reminders(pdo_connection());
    } catch (Throwable $error) {
        error_log('TanimTrack care reminder worker failed: ' . $error->getMessage());
    }
    sleep(60);
}
