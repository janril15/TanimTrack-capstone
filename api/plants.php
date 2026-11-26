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

if ($requestMethod === 'GET') {
    try {
        $statement = $pdo->prepare(
            'SELECT p.id, p.name, p.species, p.location, p.planted_on, p.created_at,
                    latest_scan.id AS latest_scan_id, latest_scan.created_at AS latest_scanned_at,
                    latest_scan.health_status AS latest_health_status, latest_scan.image_path AS latest_image_path,
                    latest_stage.growth_stage AS growth_stage,
                    (SELECT h.leaf_count
                     FROM plant_analysis_history AS h
                     WHERE h.user_id = p.user_id AND h.plant_id = p.id AND h.leaf_count IS NOT NULL
                     ORDER BY h.created_at DESC, h.id DESC LIMIT 1) AS latest_leaf_count,
                    (SELECT h.plant_size_cm
                     FROM plant_analysis_history AS h
                     WHERE h.user_id = p.user_id AND h.plant_id = p.id AND h.plant_size_cm IS NOT NULL
                     ORDER BY h.created_at DESC, h.id DESC LIMIT 1) AS latest_plant_size_cm,
                    (SELECT h.plant_height_cm
                     FROM plant_analysis_history AS h
                     WHERE h.user_id = p.user_id AND h.plant_id = p.id AND h.plant_height_cm IS NOT NULL
                     ORDER BY h.created_at DESC, h.id DESC LIMIT 1) AS latest_plant_height_cm
             FROM plants AS p
             LEFT JOIN (
                 SELECT h.user_id, h.plant_id, h.id, h.created_at, h.health_status, h.image_path,
                        ROW_NUMBER() OVER (
                            PARTITION BY h.user_id, h.plant_id
                            ORDER BY h.created_at DESC, h.id DESC
                        ) AS scan_rank
                 FROM plant_analysis_history AS h
                 WHERE h.user_id = :scan_user_id AND h.plant_id IS NOT NULL
             ) AS latest_scan
                 ON latest_scan.user_id = p.user_id
                AND latest_scan.plant_id = p.id
                AND latest_scan.scan_rank = 1
             LEFT JOIN (
                 SELECT a.user_id, a.plant_id, a.growth_stage,
                        ROW_NUMBER() OVER (
                            PARTITION BY a.user_id, a.plant_id
                            ORDER BY a.activity_date DESC, a.created_at DESC, a.id DESC
                        ) AS stage_rank
                 FROM farm_activities AS a
                 WHERE a.user_id = :stage_user_id
                   AND a.activity_type = \'Plant Growth\'
                   AND a.plant_id IS NOT NULL
                   AND a.growth_stage IS NOT NULL
                   AND a.growth_stage <> \'\'
             ) AS latest_stage
                 ON latest_stage.user_id = p.user_id
                AND latest_stage.plant_id = p.id
                AND latest_stage.stage_rank = 1
             WHERE p.user_id = :user_id
             ORDER BY p.created_at DESC, p.id DESC'
        );
        $statement->execute([
            'scan_user_id' => $sessionUser['id'],
            'stage_user_id' => $sessionUser['id'],
            'user_id' => $sessionUser['id'],
        ]);
        $plants = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'species' => $row['species'] !== null ? (string) $row['species'] : '',
            'location' => $row['location'] !== null ? (string) $row['location'] : '',
            'planted_on' => $row['planted_on'] !== null ? (string) $row['planted_on'] : null,
            'created_at' => (string) $row['created_at'],
            'growth_stage' => $row['growth_stage'] !== null ? (string) $row['growth_stage'] : '',
            'latest_scan' => $row['latest_scan_id'] !== null ? [
                'id' => (string) $row['latest_scan_id'],
                'scanned_at' => (string) $row['latest_scanned_at'],
                'image_url' => $row['latest_image_path'] !== null
                    ? '/api/plant-monitoring.php?image=' . rawurlencode((string) $row['latest_scan_id'])
                    : null,
                'health_status' => (string) $row['latest_health_status'],
                'leaf_count' => $row['latest_leaf_count'] !== null ? (int) $row['latest_leaf_count'] : null,
                'plant_size_cm' => $row['latest_plant_size_cm'] !== null ? (float) $row['latest_plant_size_cm'] : null,
                'plant_height_cm' => $row['latest_plant_height_cm'] !== null ? (float) $row['latest_plant_height_cm'] : null,
            ] : null,
        ], $statement->fetchAll());
    } catch (PDOException $error) {
        error_log('TanimTrack plant lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to load registered plants. Please try again.'], 503);
    }

    json_response(['success' => true, 'plants' => $plants]);
}

$data = read_json_request();
$name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
$species = trim(is_string($data['species'] ?? null) ? $data['species'] : '');
$location = trim(is_string($data['location'] ?? null) ? $data['location'] : '');
$plantedOn = trim(is_string($data['planted_on'] ?? null) ? $data['planted_on'] : '');

$errors = [];
if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
    $errors['name'] = 'Enter a plant name (up to 120 characters).';
}
if (mb_strlen($species, 'UTF-8') > 120) {
    $errors['species'] = 'Species must be 120 characters or fewer.';
}
if (mb_strlen($location, 'UTF-8') > 200) {
    $errors['location'] = 'Location must be 200 characters or fewer.';
}
if ($plantedOn !== '') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $plantedOn);
    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $plantedOn) {
        $errors['planted_on'] = 'Enter a valid planting date.';
    }
}
if ($errors !== []) {
    json_response(['success' => false, 'message' => 'Please correct the plant details.', 'errors' => $errors], 422);
}

try {
    $plant = [
        'id' => app_uuid_v4(),
        'name' => $name,
        'species' => $species !== '' ? $species : null,
        'location' => $location !== '' ? $location : null,
        'planted_on' => $plantedOn !== '' ? $plantedOn : null,
    ];
    $statement = $pdo->prepare(
        'INSERT INTO plants (id, user_id, name, species, location, planted_on)
         VALUES (:id, :user_id, :name, :species, :location, :planted_on)'
    );
    $statement->execute([
        'id' => $plant['id'],
        'user_id' => $sessionUser['id'],
        'name' => $plant['name'],
        'species' => $plant['species'],
        'location' => $plant['location'],
        'planted_on' => $plant['planted_on'],
    ]);
    $plant['created_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
} catch (Throwable $error) {
    error_log('TanimTrack plant registration failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to register this plant. Please try again.'], 503);
}

json_response(['success' => true, 'plant' => $plant], 201);
