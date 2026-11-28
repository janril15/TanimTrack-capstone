<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

start_app_session();
$sessionUser = session_user();
if ($sessionUser === null) {
    json_response(['success' => false, 'message' => 'Authentication is required.'], 401);
}

$pdo = pdo_connection();
$allowedTypes = ['Plant Growth', 'Garden Log', 'Climate Risk', 'Harvest', 'Watering', 'Fertilizer'];

function farm_activity_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function farm_activity_legacy_key(string $legacyId): string
{
    return 'legacy:' . hash('sha256', $legacyId);
}

function normalize_farm_activity(array $data, array $allowedTypes, bool $requirePlantLink = false): array
{
    $type = trim(is_string($data['activity_type'] ?? $data['type'] ?? null) ? ($data['activity_type'] ?? $data['type']) : '');
    $date = trim(is_string($data['activity_date'] ?? $data['date'] ?? null) ? ($data['activity_date'] ?? $data['date']) : '');
    $title = trim(is_string($data['title'] ?? null) ? $data['title'] : '');
    $details = trim(is_string($data['details'] ?? null) ? $data['details'] : '');
    $related = trim(is_string($data['related_plant'] ?? null) ? $data['related_plant'] : '');
    $plantId = trim(is_string($data['plant_id'] ?? null) ? $data['plant_id'] : '');
    $growthStage = trim(is_string($data['growth_stage'] ?? null) ? $data['growth_stage'] : '');
    $climateRisk = trim(is_string($data['climate_risk'] ?? null) ? $data['climate_risk'] : '');
    $actionsTaken = trim(is_string($data['actions_taken'] ?? null) ? $data['actions_taken'] : '');
    $quantityInput = trim(is_string($data['harvest_quantity'] ?? null) || is_numeric($data['harvest_quantity'] ?? null)
        ? (string) $data['harvest_quantity']
        : '');
    $harvestUnit = trim(is_string($data['harvest_unit'] ?? null) ? $data['harvest_unit'] : '');

    $errors = [];
    if (!in_array($type, $allowedTypes, true)) {
        $errors['activity_type'] = 'Choose a valid activity type.';
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
        $errors['activity_date'] = 'Enter a valid activity date.';
    }
    if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
        $errors['title'] = 'Enter an activity title (up to 160 characters).';
    }
    if (mb_strlen($details, 'UTF-8') > 5000) {
        $errors['details'] = 'Activity details must be 5000 characters or fewer.';
    }
    if (mb_strlen($related, 'UTF-8') > 200) {
        $errors['related_plant'] = 'Related plant or garden must be 200 characters or fewer.';
    }
    if ($plantId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $plantId) !== 1) {
        $errors['plant_id'] = 'Select a valid registered plant.';
    }
    if (mb_strlen($growthStage, 'UTF-8') > 100) {
        $errors['growth_stage'] = 'Growth stage must be 100 characters or fewer.';
    }
    if (mb_strlen($climateRisk, 'UTF-8') > 500) {
        $errors['climate_risk'] = 'Weather or climate risk must be 500 characters or fewer.';
    }
    if (mb_strlen($actionsTaken, 'UTF-8') > 5000) {
        $errors['actions_taken'] = 'Actions taken must be 5000 characters or fewer.';
    }
    if (mb_strlen($harvestUnit, 'UTF-8') > 40 || preg_match('/[\x00-\x1F\x7F]/', $harvestUnit) === 1) {
        $errors['harvest_unit'] = 'Enter a valid harvest unit (up to 40 characters).';
    }

    if (in_array($type, ['Plant Growth', 'Garden Log', 'Harvest'], true)
        && $related === ''
        && !($type === 'Plant Growth' && $plantId !== '')) {
        $errors['related_plant'] = 'Enter the related plant or garden.';
    }
    if ($requirePlantLink && $type === 'Plant Growth' && $plantId === '') {
        $errors['plant_id'] = 'Select a registered plant or register a new plant first.';
    }
    if ($type === 'Plant Growth' && $growthStage === '') {
        $errors['growth_stage'] = 'Enter the growth stage.';
    }
    if (in_array($type, ['Plant Growth', 'Garden Log'], true) && $details === '') {
        $errors['details'] = 'Enter the activity details.';
    }
    if ($type === 'Climate Risk') {
        if ($climateRisk === '') {
            $errors['climate_risk'] = 'Describe the weather or climate risk.';
        }
        if ($actionsTaken === '') {
            $errors['actions_taken'] = 'Describe the actions taken.';
        }
    }
    if ($type === 'Harvest') {
        if (preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $quantityInput) !== 1) {
            $errors['harvest_quantity'] = 'Enter a non-negative harvest quantity with up to three decimal places.';
        }
        if ($harvestUnit === '') {
            $errors['harvest_unit'] = 'Enter the harvest unit.';
        }
    }

    if ($errors !== []) {
        json_response(['success' => false, 'message' => 'Please correct the activity details.', 'errors' => $errors], 422);
    }

    return [
        'activity_type' => $type,
        'activity_date' => $date,
        'title' => $title,
        'details' => $details,
        'related_plant' => $related !== '' ? $related : null,
        'plant_id' => $plantId !== '' ? $plantId : null,
        'growth_stage' => $type === 'Plant Growth' ? $growthStage : null,
        'climate_risk' => $type === 'Climate Risk' ? $climateRisk : null,
        'actions_taken' => $type === 'Climate Risk' ? $actionsTaken : null,
        'harvest_quantity' => $type === 'Harvest' ? number_format((float) $quantityInput, 3, '.', '') : null,
        'harvest_unit' => $type === 'Harvest' ? $harvestUnit : null,
    ];
}

function farm_activity_response_row(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'user_id' => (int) $row['user_id'],
        'author_name' => (string) $row['author_name'],
        'activity_type' => (string) $row['activity_type'],
        'activity_date' => (string) $row['activity_date'],
        'title' => (string) $row['title'],
        'details' => (string) $row['details'],
        'plant_id' => $row['plant_id'] !== null ? (string) $row['plant_id'] : null,
        'related_plant' => $row['related_plant'] !== null ? (string) $row['related_plant'] : '',
        'growth_stage' => $row['growth_stage'] !== null ? (string) $row['growth_stage'] : '',
        'climate_risk' => $row['climate_risk'] !== null ? (string) $row['climate_risk'] : '',
        'actions_taken' => $row['actions_taken'] !== null ? (string) $row['actions_taken'] : '',
        'harvest_quantity' => $row['harvest_quantity'] !== null ? (string) $row['harvest_quantity'] : '',
        'harvest_unit' => $row['harvest_unit'] !== null ? (string) $row['harvest_unit'] : '',
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

if ($requestMethod === 'GET') {
    try {
        $statement = $pdo->prepare(
            "SELECT a.id, a.user_id, u.full_name AS author_name, a.activity_type, a.activity_date,
                    a.plant_id,
                    a.title, a.details, a.related_plant, a.growth_stage, a.climate_risk,
                    a.actions_taken, a.harvest_quantity, a.harvest_unit, a.created_at, a.updated_at
             FROM farm_activities AS a
             INNER JOIN users AS u ON u.id = a.user_id
             WHERE a.user_id = :user_id
               AND a.activity_type IN ('Plant Growth', 'Garden Log', 'Climate Risk', 'Harvest', 'Watering', 'Fertilizer')
               AND a.id NOT IN (
                   'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a01',
                   'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a02',
                   'd6b2fa22-299c-4ae9-8ee5-b2b4e9f91a03'
               )
               AND (a.legacy_key IS NULL OR a.legacy_key NOT IN (
                   'legacy:b89397fde04e4452692e4d2d8ac343474441008d70345d6e8f9ac6c57601a247',
                   'legacy:375d7e9fe262fbd701f9b70431cbe79d3827b76f5308c04944b7bc79e80956e0',
                   'legacy:140c8c724205719b1ba3267020f87c489d935dcd06bd0d310f86ec70f763fbf9'
               ))
             UNION ALL
             SELECT h.id, h.user_id, u.full_name AS author_name, 'Plant Growth' AS activity_type,
                    DATE(h.created_at) AS activity_date, h.plant_id, 'Plant Growth Scan' AS title,
                    CONCAT('Health status: ', h.health_status,
                           IF(h.detected_issue IS NOT NULL AND h.detected_issue <> '',
                              CONCAT('. Finding: ', h.detected_issue), '')) AS details,
                    p.name AS related_plant, NULL AS growth_stage, NULL AS climate_risk,
                    NULL AS actions_taken, NULL AS harvest_quantity, NULL AS harvest_unit,
                    h.created_at, h.updated_at
             FROM plant_analysis_history AS h
             INNER JOIN plants AS p ON p.user_id = h.user_id AND p.id = h.plant_id
             INNER JOIN users AS u ON u.id = h.user_id
             WHERE h.user_id = :scan_user_id AND h.plant_id IS NOT NULL
             ORDER BY activity_date DESC, created_at DESC, id DESC"
        );
        $statement->execute([
            'user_id' => $sessionUser['id'],
            'scan_user_id' => $sessionUser['id'],
        ]);
        $activities = array_map('farm_activity_response_row', $statement->fetchAll());
    } catch (PDOException $error) {
        error_log('TanimTrack farm activities lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to load farm activities. Check that the farm activity and plant-link migrations have been applied.'], 503);
    }

    json_response(['success' => true, 'activities' => $activities]);
}

$data = read_json_request();
if (($data['action'] ?? '') === 'import-legacy') {
    $legacyActivities = $data['activities'] ?? null;
    if (!is_array($legacyActivities) || count($legacyActivities) > 100) {
        json_response(['success' => false, 'message' => 'Legacy farm activity data is invalid.'], 422);
    }

    $normalizedActivities = [];
    foreach ($legacyActivities as $activity) {
        if (!is_array($activity) || !is_string($activity['id'] ?? null) || $activity['id'] === '') {
            json_response(['success' => false, 'message' => 'Legacy farm activity data is invalid.'], 422);
        }
        $normalizedActivities[] = [
            'legacy_key' => farm_activity_legacy_key($activity['id']),
            ...normalize_farm_activity($activity, $allowedTypes),
        ];
    }

    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO farm_activities
                (id, user_id, activity_type, activity_date, title, details, related_plant,
                 growth_stage, climate_risk, actions_taken, harvest_quantity, harvest_unit, legacy_key)
             VALUES
                (:id, :user_id, :activity_type, :activity_date, :title, :details, :related_plant,
                 :growth_stage, :climate_risk, :actions_taken, :harvest_quantity, :harvest_unit, :legacy_key)'
        );
        foreach ($normalizedActivities as $activity) {
            $insert->execute([
                'id' => farm_activity_uuid(),
                'user_id' => $sessionUser['id'],
                'activity_type' => $activity['activity_type'],
                'activity_date' => $activity['activity_date'],
                'title' => $activity['title'],
                'details' => $activity['details'],
                'related_plant' => $activity['related_plant'],
                'growth_stage' => $activity['growth_stage'],
                'climate_risk' => $activity['climate_risk'],
                'actions_taken' => $activity['actions_taken'],
                'harvest_quantity' => $activity['harvest_quantity'],
                'harvest_unit' => $activity['harvest_unit'],
                'legacy_key' => $activity['legacy_key'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TanimTrack legacy farm activity import failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to preserve older farm activities. Please try again.'], 503);
    }

    json_response(['success' => true, 'message' => 'Older farm activities were preserved.']);
}

$activity = normalize_farm_activity($data, $allowedTypes, true);
$requestId = trim(is_string($data['request_id'] ?? null) ? $data['request_id'] : '');
if ($requestId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $requestId) !== 1) {
    json_response(['success' => false, 'message' => 'The activity request identifier is invalid.'], 422);
}
$id = $requestId !== '' ? $requestId : farm_activity_uuid();
$lookupSql = 'SELECT a.id, a.user_id, u.full_name AS author_name, a.activity_type, a.activity_date,
                     a.plant_id, a.title, a.details, a.related_plant, a.growth_stage, a.climate_risk,
                     a.actions_taken, a.harvest_quantity, a.harvest_unit, a.created_at, a.updated_at
              FROM farm_activities AS a
              INNER JOIN users AS u ON u.id = a.user_id
              WHERE a.id = :id AND a.user_id = :user_id LIMIT 1';
try {
    if ($activity['plant_id'] !== null) {
        $plantLookup = $pdo->prepare('SELECT name FROM plants WHERE id = :id AND user_id = :user_id LIMIT 1');
        $plantLookup->execute(['id' => $activity['plant_id'], 'user_id' => $sessionUser['id']]);
        $plant = $plantLookup->fetch();
        if ($plant === false) {
            json_response(['success' => false, 'message' => 'Select a registered plant that belongs to your account.'], 422);
        }
        $activity['related_plant'] = (string) $plant['name'];
    }

    $lookup = $pdo->prepare($lookupSql);
    $lookup->execute(['id' => $id, 'user_id' => $sessionUser['id']]);
    $existingRow = $lookup->fetch();
    if ($existingRow !== false) {
        json_response(['success' => true, 'activity' => farm_activity_response_row($existingRow)], 200);
    }

    $statement = $pdo->prepare(
        'INSERT INTO farm_activities
            (id, user_id, plant_id, activity_type, activity_date, title, details, related_plant,
             growth_stage, climate_risk, actions_taken, harvest_quantity, harvest_unit)
         VALUES
            (:id, :user_id, :plant_id, :activity_type, :activity_date, :title, :details, :related_plant,
             :growth_stage, :climate_risk, :actions_taken, :harvest_quantity, :harvest_unit)'
    );
    $statement->execute([
        'id' => $id,
        'user_id' => $sessionUser['id'],
        ...$activity,
    ]);

    $lookup = $pdo->prepare($lookupSql);
    $lookup->execute(['id' => $id, 'user_id' => $sessionUser['id']]);
    $row = $lookup->fetch();
    if ($row === false) {
        throw new RuntimeException('The saved farm activity could not be read back.');
    }
} catch (PDOException $error) {
    error_log('TanimTrack farm activity save failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to save this farm activity. Please try again.'], 503);
} catch (RuntimeException $error) {
    error_log('TanimTrack farm activity lookup after save failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'The activity was saved, but its details could not be loaded. Refresh Farm Logs to check it.'], 503);
}

json_response(['success' => true, 'activity' => farm_activity_response_row($row)], 201);
