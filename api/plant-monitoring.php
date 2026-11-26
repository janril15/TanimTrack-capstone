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
$imageScanId = $_GET['image'] ?? null;
if ($requestMethod === 'GET' && $imageScanId !== null) {
    if (!is_string($imageScanId) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $imageScanId) !== 1) {
        json_response(['success' => false, 'message' => 'The scan image was not found.'], 404);
    }

    try {
        $statement = $pdo->prepare(
            'SELECT image_path, image_mime_type
             FROM plant_analysis_history WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $imageScanId, 'user_id' => $sessionUser['id']]);
        $scan = $statement->fetch();
    } catch (PDOException $error) {
        error_log('TanimTrack plant scan image lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to load this scan image. Please try again.'], 503);
    }
    if ($scan === false || preg_match('#^uploads/plant-scans/[a-f0-9]{32}\.(?:jpg|png|webp)$#D', (string) $scan['image_path']) !== 1) {
        http_response_code(404);
        header('Cache-Control: private, no-store');
        exit;
    }

    $imagePath = __DIR__ . '/../' . $scan['image_path'];
    if (!is_file($imagePath)) {
        http_response_code(404);
        header('Cache-Control: private, no-store');
        exit;
    }

    header('Content-Type: ' . $scan['image_mime_type']);
    header('Content-Length: ' . (string) filesize($imagePath));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($imagePath);
    exit;
}

function plant_monitoring_scan_response(array $row): array
{
    $analysis = null;
    if ($row['analysis_result'] !== null) {
        $analysis = json_decode((string) $row['analysis_result'], true);
    }
    if ($row['analysis_result'] !== null && !is_array($analysis)) {
        throw new RuntimeException('A saved plant scan has invalid analysis data.');
    }

    return [
        'id' => (string) $row['id'],
        'plant_id' => (string) $row['plant_id'],
        'scanned_at' => (string) $row['created_at'],
        'image_url' => !empty($row['image_path'])
            ? '/api/plant-monitoring.php?image=' . rawurlencode((string) $row['id'])
            : null,
        'plant_name' => $row['plant_name'] !== null ? (string) $row['plant_name'] : null,
        'health_status' => (string) $row['health_status'],
        'detected_issue' => $row['detected_issue'] !== null ? (string) $row['detected_issue'] : null,
        'severity' => (string) $row['severity'],
        'confidence' => (string) $row['confidence'],
        'analysis_result' => $analysis,
        'leaf_count' => $row['leaf_count'] !== null ? (int) $row['leaf_count'] : null,
        'plant_size_cm' => $row['plant_size_cm'] !== null ? (float) $row['plant_size_cm'] : null,
        'plant_height_cm' => $row['plant_height_cm'] !== null ? (float) $row['plant_height_cm'] : null,
    ];
}

function plant_monitoring_scans(PDO $pdo, string $plantId, int $userId, ?string $scanId = null): array
{
    $query = 'SELECT id, plant_id, created_at, image_path, image_mime_type, plant_name,
                     health_status, detected_issue, severity, confidence, analysis_result,
                     leaf_count, plant_size_cm, plant_height_cm
              FROM plant_analysis_history
              WHERE user_id = :user_id AND plant_id = :plant_id';
    $parameters = ['user_id' => $userId, 'plant_id' => $plantId];
    if ($scanId !== null) {
        $query .= ' AND id = :scan_id';
        $parameters['scan_id'] = $scanId;
    }
    $query .= ' ORDER BY created_at DESC, id DESC';
    $statement = $pdo->prepare($query);
    $statement->execute($parameters);
    return array_map('plant_monitoring_scan_response', $statement->fetchAll());
}

if ($requestMethod === 'GET') {
    $plantId = $_GET['plant_id'] ?? '';
    if (!is_string($plantId) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $plantId) !== 1) {
        json_response(['success' => false, 'message' => 'Choose a valid registered plant.'], 422);
    }
    try {
        $plantQuery = $pdo->prepare(
            'SELECT id, name, species, location, planted_on, created_at
             FROM plants WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $plantQuery->execute(['id' => $plantId, 'user_id' => $sessionUser['id']]);
        $row = $plantQuery->fetch();
    } catch (PDOException $error) {
        error_log('TanimTrack plant details lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to load this plant. Please try again.'], 503);
    }
    if ($row === false) {
        json_response(['success' => false, 'message' => 'This plant could not be found in your account.'], 404);
    }

    try {
        $scans = plant_monitoring_scans($pdo, $plantId, $sessionUser['id']);
    } catch (Throwable $error) {
        error_log('TanimTrack plant scan history lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to load this plant’s scan history. Please try again.'], 503);
    }

    json_response([
        'success' => true,
        'plant' => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'species' => $row['species'] !== null ? (string) $row['species'] : '',
            'location' => $row['location'] !== null ? (string) $row['location'] : '',
            'planted_on' => $row['planted_on'] !== null ? (string) $row['planted_on'] : null,
            'created_at' => (string) $row['created_at'],
        ],
        'scans' => $scans,
    ]);
}

$data = read_json_request();
$plantId = is_string($data['plant_id'] ?? null) ? $data['plant_id'] : '';
$scanId = is_string($data['scan_id'] ?? null) ? $data['scan_id'] : '';
if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $plantId) !== 1
    || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $scanId) !== 1) {
    json_response(['success' => false, 'message' => 'Choose a valid plant scan.'], 422);
}

$leafCount = $data['leaf_count'] ?? null;
if ($leafCount !== null && (!is_int($leafCount) || $leafCount < 0 || $leafCount > 1000000)) {
    json_response(['success' => false, 'message' => 'Leaf count must be a whole number from 0 to 1,000,000.'], 422);
}

$measurements = [];
foreach (['plant_size_cm', 'plant_height_cm'] as $field) {
    $value = $data[$field] ?? null;
    if ($value === null || $value === '') {
        $measurements[$field] = null;
        continue;
    }
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 999999.99) {
        json_response(['success' => false, 'message' => 'Plant size and height must be valid measurements from 0 to 999,999.99 cm.'], 422);
    }
    $measurements[$field] = number_format((float) $value, 2, '.', '');
}

try {
    $exists = $pdo->prepare(
        'SELECT id FROM plant_analysis_history
         WHERE id = :scan_id AND plant_id = :plant_id AND user_id = :user_id LIMIT 1'
    );
    $exists->execute(['scan_id' => $scanId, 'plant_id' => $plantId, 'user_id' => $sessionUser['id']]);
    if ($exists->fetchColumn() === false) {
        json_response(['success' => false, 'message' => 'This scan does not belong to the selected plant.'], 404);
    }

    $update = $pdo->prepare(
        'UPDATE plant_analysis_history
         SET leaf_count = :leaf_count, plant_size_cm = :plant_size_cm, plant_height_cm = :plant_height_cm
         WHERE id = :scan_id AND plant_id = :plant_id AND user_id = :user_id'
    );
    $update->execute([
        'leaf_count' => $leafCount,
        'plant_size_cm' => $measurements['plant_size_cm'],
        'plant_height_cm' => $measurements['plant_height_cm'],
        'scan_id' => $scanId,
        'plant_id' => $plantId,
        'user_id' => $sessionUser['id'],
    ]);
    $scans = plant_monitoring_scans($pdo, $plantId, $sessionUser['id'], $scanId);
} catch (Throwable $error) {
    error_log('TanimTrack plant scan measurement update failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to save these measurements. Please try again.'], 503);
}

json_response(['success' => true, 'scan' => $scans[0]]);
