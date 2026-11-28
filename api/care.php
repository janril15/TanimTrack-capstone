<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const CARE_TIMEZONE = 'Asia/Manila';

function care_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(CARE_TIMEZONE));
}

function care_valid_uuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
}

function care_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(CARE_TIMEZONE));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function care_json(mixed $value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function care_owner(PDO $pdo, int $userId, string $plantId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, name, species, location, planted_on
         FROM plants WHERE id = :plant_id AND user_id = :user_id LIMIT 1'
    );
    $statement->execute(['plant_id' => $plantId, 'user_id' => $userId]);
    $plant = $statement->fetch();
    return $plant === false ? null : $plant;
}

function care_weather(PDO $pdo, ?string $location): ?array
{
    $configured = trim(getenv('TANODBUKID_CLIMATE_LOCATION') ?: 'Sta. Maria, Bulacan, Philippines');
    $normalize = static fn (string $value): string => preg_replace('/,\s*philippines$/i', '', mb_strtolower(trim($value), 'UTF-8')) ?? mb_strtolower(trim($value), 'UTF-8');
    if ($location === null || $location === '' || $normalize($location) !== $normalize($configured)) return null;

    $statement = $pdo->prepare(
        'SELECT location_name, temperature, relative_humidity, precipitation, rain_probability, observed_at, fetched_at
         FROM weather_current WHERE location_name = :location LIMIT 1'
    );
    $statement->execute(['location' => $configured]);
    $row = $statement->fetch();
    if ($row === false) return null;
    $fetchedAt = new DateTimeImmutable((string) $row['fetched_at'], new DateTimeZone(CARE_TIMEZONE));
    if (care_now()->getTimestamp() - $fetchedAt->getTimestamp() > 21600) return null;

    $forecast = $pdo->prepare(
        'SELECT SUM(precipitation) precipitation_24h, MAX(precipitation_probability) probability_24h
         FROM weather_forecasts
         WHERE location_name = :location AND forecast_kind = :kind
           AND forecast_at >= :now AND forecast_at < :until'
    );
    $now = care_now();
    $forecast->execute([
        'location' => $configured, 'kind' => 'hourly',
        'now' => $now->format('Y-m-d H:i:s'), 'until' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
    ]);
    $rain = $forecast->fetch() ?: [];
    return [
        'location_name' => (string) $row['location_name'],
        'fetched_at' => (string) $row['fetched_at'],
        'observed_at' => (string) $row['observed_at'],
        'temperature_c' => $row['temperature'] !== null ? (float) $row['temperature'] : null,
        'relative_humidity_percent' => $row['relative_humidity'] !== null ? (int) $row['relative_humidity'] : null,
        'precipitation_mm' => $row['precipitation'] !== null ? (float) $row['precipitation'] : null,
        'rain_probability_percent' => $row['rain_probability'] !== null ? (int) $row['rain_probability'] : null,
        'forecast_precipitation_24h_mm' => isset($rain['precipitation_24h']) ? (float) $rain['precipitation_24h'] : null,
        'forecast_rain_probability_percent' => isset($rain['probability_24h']) ? (int) $rain['probability_24h'] : null,
    ];
}

function care_context(PDO $pdo, int $userId, array $plant): array
{
    $scanQuery = $pdo->prepare(
        'SELECT id, created_at, health_status, detected_issue, visible_symptoms, possible_causes,
                leaf_count, plant_size_cm, plant_height_cm,
                recommendations, analysis_result
         FROM plant_analysis_history
         WHERE user_id = :user_id AND plant_id = :plant_id
         ORDER BY created_at DESC, id DESC LIMIT 5'
    );
    $scanQuery->execute(['user_id' => $userId, 'plant_id' => $plant['id']]);
    $scans = [];
    foreach ($scanQuery->fetchAll() as $row) {
        $analysis = care_json($row['analysis_result']);
        $scans[] = [
            'id' => (string) $row['id'],
            'scanned_at' => (string) $row['created_at'],
            'health_status' => (string) $row['health_status'],
            'growth_stage' => is_string($analysis['growth_stage'] ?? null) ? $analysis['growth_stage'] : null,
            'detected_issue' => $row['detected_issue'] !== null ? (string) $row['detected_issue'] : null,
            'leaf_count' => $row['leaf_count'] !== null ? (int) $row['leaf_count'] : null,
            'plant_size_cm' => $row['plant_size_cm'] !== null ? (float) $row['plant_size_cm'] : null,
            'plant_height_cm' => $row['plant_height_cm'] !== null ? (float) $row['plant_height_cm'] : null,
            'visible_symptoms' => array_values(array_filter(care_json($row['visible_symptoms']), 'is_string')),
            'possible_causes' => array_values(array_filter(care_json($row['possible_causes']), 'is_string')),
            'recommendations' => array_values(array_filter(care_json($row['recommendations']), 'is_string')),
            'analysis' => is_string($analysis['analysis'] ?? null) ? $analysis['analysis'] : '',
        ];
    }

    $stageQuery = $pdo->prepare(
        'SELECT growth_stage FROM farm_activities
         WHERE user_id = :user_id AND plant_id = :plant_id AND activity_type = :type
           AND growth_stage IS NOT NULL AND growth_stage <> \'\'
         ORDER BY activity_date DESC, created_at DESC LIMIT 1'
    );
    $stageQuery->execute(['user_id' => $userId, 'plant_id' => $plant['id'], 'type' => 'Plant Growth']);
    $loggedStage = $stageQuery->fetchColumn();
    $growthStage = $scans[0]['growth_stage'] ?? (is_string($loggedStage) ? $loggedStage : null);

    $wateringQuery = $pdo->prepare(
        'SELECT scheduled_on, scheduled_time, status, amount_ml, completed_at
         FROM watering_tasks
        WHERE user_id = :user_id AND plant_id = :plant_id AND status = :completed
        ORDER BY completed_at DESC, id DESC LIMIT 20'
    );
    $wateringQuery->execute(['user_id' => $userId, 'plant_id' => $plant['id'], 'completed' => 'completed']);
    $wateringHistory = array_map(static fn (array $row): array => [
        'scheduled_on' => (string) $row['scheduled_on'],
        'status' => (string) $row['status'],
        'amount_ml' => $row['amount_ml'] !== null ? (int) $row['amount_ml'] : null,
        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
    ], $wateringQuery->fetchAll());

    $fertilizerQuery = $pdo->prepare(
        'SELECT scheduled_on, fertilizer_category, product_name, applied_amount, guidance, completed_at
         FROM fertilizer_tasks
         WHERE user_id = :user_id AND plant_id = :plant_id AND status = :completed
         ORDER BY completed_at DESC, id DESC LIMIT 20'
    );
    $fertilizerQuery->execute(['user_id' => $userId, 'plant_id' => $plant['id'], 'completed' => 'completed']);
    $fertilizerHistory = array_map(static fn (array $row): array => [
        'applied_on' => $row['completed_at'] !== null ? substr((string) $row['completed_at'], 0, 10) : (string) $row['scheduled_on'],
        'category' => (string) $row['fertilizer_category'],
        'product_name' => $row['product_name'] !== null ? (string) $row['product_name'] : null,
        'amount' => $row['applied_amount'] !== null ? (string) $row['applied_amount'] : null,
        'guidance' => (string) $row['guidance'],
    ], $fertilizerQuery->fetchAll());

    $soilQuery = $pdo->prepare(
        'SELECT soil_type, soil_moisture_percent, soil_moisture_recorded_at
         FROM watering_plans WHERE user_id = :user_id AND plant_id = :plant_id LIMIT 1'
    );
    $soilQuery->execute(['user_id' => $userId, 'plant_id' => $plant['id']]);
    $soilRow = $soilQuery->fetch() ?: [];
    $soilMoisture = null;
    if ($soilRow && $soilRow['soil_moisture_percent'] !== null && $soilRow['soil_moisture_recorded_at'] !== null) {
        $recorded = new DateTimeImmutable((string) $soilRow['soil_moisture_recorded_at'], new DateTimeZone(CARE_TIMEZONE));
        $age = care_now()->getTimestamp() - $recorded->getTimestamp();
        if ($age >= 0 && $age <= 86400) $soilMoisture = (float) $soilRow['soil_moisture_percent'];
    }
    $soil = [
        'type' => $soilRow['soil_type'] ?? null,
        'manual_moisture_percent' => $soilMoisture,
        'moisture_recorded_at' => $soilMoisture !== null ? (string) $soilRow['soil_moisture_recorded_at'] : null,
    ];
    $plantingDate = $plant['planted_on'] !== null ? (string) $plant['planted_on'] : null;
    $ageWeeks = null;
    if ($plantingDate !== null) {
        $planted = new DateTimeImmutable($plantingDate, new DateTimeZone(CARE_TIMEZONE));
        if ($planted <= care_now()) $ageWeeks = intdiv($planted->diff(care_now())->days, 7);
    }
    $context = [
        'plant' => [
            'id' => (string) $plant['id'], 'name' => (string) $plant['name'],
            'species' => $plant['species'] !== null ? (string) $plant['species'] : null,
            'planted_on' => $plantingDate, 'age_weeks' => $ageWeeks, 'growth_stage' => $growthStage,
        ],
        'scan_history' => $scans,
        'watering_history' => $wateringHistory,
        'fertilizer_history' => $fertilizerHistory,
        'soil' => $soil,
        'weather' => care_weather($pdo, $plant['location'] !== null ? (string) $plant['location'] : null),
        'timezone' => CARE_TIMEZONE,
    ];
    return ['context' => $context, 'scans' => $scans, 'growth_stage' => $growthStage];
}

function care_context_hash(array $context): string
{
    return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function care_ai(array $context): array
{
    $url = trim(getenv('TANODBUKID_AI_FERTILIZER_URL') ?: 'http://ai:8080/fertilizer-recommendation');
    $request = curl_init($url);
    if ($request === false) throw new RuntimeException('Unable to contact the AI fertilizer service.');
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120,
    ]);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);
    if (!is_string($body)) {
        error_log('Gemini fertilizer call failed: ' . $error);
        throw new RuntimeException('The AI fertilizer service could not be reached.');
    }
    $result = json_decode($body, true);
    $categories = ['organic', 'compost', 'balanced_npk', 'nitrogen_rich', 'crop_specific', 'none'];
    if ($status < 200 || $status >= 300 || !is_array($result)) {
        throw new RuntimeException(is_array($result) && is_string($result['message'] ?? null) ? $result['message'] : 'The AI fertilizer service returned an invalid response.');
    }
    if (($result['success'] ?? null) !== true
        || !in_array($result['decision'] ?? null, ['apply', 'not_needed', 'review'], true)
        || !in_array($result['fertilizer_category'] ?? null, $categories, true)
        || !in_array($result['nutrient_stress'] ?? null, ['not_indicated', 'possible'], true)
        || !in_array($result['confidence'] ?? null, ['low', 'moderate', 'high'], true)
        || !is_string($result['guidance'] ?? null) || trim($result['guidance']) === ''
        || !is_string($result['reason'] ?? null) || trim($result['reason']) === ''
        || !is_array($result['warnings'] ?? null) || count($result['warnings']) > 5
        || (($result['decision'] === 'apply') !== is_string($result['recommended_on'] ?? null))
        || ($result['decision'] === 'apply' && $result['fertilizer_category'] === 'none')
        || ($result['decision'] !== 'apply' && $result['fertilizer_category'] !== 'none')
    ) {
        throw new RuntimeException('The AI service returned an invalid fertilizer recommendation.');
    }
    if ($result['decision'] === 'apply' && (!care_valid_date($result['recommended_on']) || $result['recommended_on'] < care_now()->format('Y-m-d'))) {
        throw new RuntimeException('The AI service returned an invalid fertilizer application date.');
    }
    foreach ($result['warnings'] as $warning) {
        if (!is_string($warning) || trim($warning) === '') throw new RuntimeException('The AI service returned an invalid fertilizer warning.');
    }
    return $result;
}

function care_recommendation(PDO $pdo, int $userId, string $plantId, string $contextHash): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, source_scan_id, decision, fertilizer_category, nutrient_stress,
                confidence, recommended_on, guidance, reason, warnings, created_at
         FROM fertilizer_recommendations
         WHERE user_id = :user_id AND plant_id = :plant_id AND context_hash = :context_hash LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'plant_id' => $plantId, 'context_hash' => $contextHash]);
    $row = $statement->fetch();
    if ($row === false) return null;
    return [
        'id' => (string) $row['id'], 'source_scan_id' => (string) $row['source_scan_id'],
        'decision' => (string) $row['decision'], 'fertilizer_category' => (string) $row['fertilizer_category'],
        'nutrient_stress' => (string) $row['nutrient_stress'], 'confidence' => (string) $row['confidence'],
        'recommended_on' => $row['recommended_on'] !== null ? (string) $row['recommended_on'] : null,
        'guidance' => (string) $row['guidance'], 'reason' => (string) $row['reason'],
        'warnings' => array_values(array_filter(care_json($row['warnings']), 'is_string')),
        'created_at' => (string) $row['created_at'],
    ];
}

function care_assess(PDO $pdo, int $userId, string $plantId): array
{
    $plant = care_owner($pdo, $userId, $plantId);
    if ($plant === null) json_response(['success' => false, 'message' => 'This plant is not available in your account.'], 404);
    $lockName = 'care:' . substr(hash('sha256', $userId . ':' . $plantId), 0, 60);
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout)');
    $lock->execute(['lock_name' => $lockName, 'timeout' => 5]);
    if ((int) $lock->fetchColumn() !== 1) json_response(['success' => false, 'message' => 'A care assessment is already running for this plant. Try again shortly.'], 409);

    try {
        $data = care_context($pdo, $userId, $plant);
        $context = $data['context'];
        $latestScan = $data['scans'][0] ?? null;
        if ($latestScan === null) json_response(['success' => false, 'code' => 'scan_required', 'message' => 'Save a scan with the existing Plant/Crop Scan before assessing fertilizer care.'], 409);
        $contextHash = care_context_hash($context);
        $cached = care_recommendation($pdo, $userId, $plantId, $contextHash);
        if ($cached !== null) return ['success' => true, 'cached' => true, 'plant_id' => $plantId, 'recommendation' => $cached];

        $result = care_ai($context);
        $recommendationId = app_uuid_v4();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO fertilizer_recommendations
                 (id, user_id, plant_id, source_scan_id, context_hash, decision, fertilizer_category,
                  nutrient_stress, confidence, recommended_on, guidance, reason, warnings)
                 VALUES (:id, :user_id, :plant_id, :scan_id, :context_hash, :decision, :category,
                  :stress, :confidence, :recommended_on, :guidance, :reason, :warnings)'
            );
            $insert->execute([
                'id' => $recommendationId, 'user_id' => $userId, 'plant_id' => $plantId,
                'scan_id' => $latestScan['id'], 'context_hash' => $contextHash,
                'decision' => $result['decision'], 'category' => $result['fertilizer_category'],
                'stress' => $result['nutrient_stress'], 'confidence' => $result['confidence'],
                'recommended_on' => $result['recommended_on'],
                'guidance' => mb_substr(trim($result['guidance']), 0, 1200),
                'reason' => mb_substr(trim($result['reason']), 0, 1200),
                'warnings' => json_encode($result['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $notice = $pdo->prepare(
                'INSERT IGNORE INTO notifications
                 (user_id, notification_type, title, message, related_module, related_record_id, related_plant_id, event_key)
                 VALUES (:user_id, :type, :title, :message, :module, :record_id, :plant_id, :event_key)'
            );
            $notice->execute([
                'user_id' => $userId,
                'type' => $result['nutrient_stress'] === 'possible' ? 'possible_nutrient_stress' : 'care_recommendation',
                'title' => $result['nutrient_stress'] === 'possible'
                    ? 'Possible nutrient stress — verify before treating'
                    : 'Plant care recommendation updated',
                'message' => mb_substr($result['reason'], 0, 1000),
                'module' => 'plant-care', 'record_id' => $recommendationId,
                'plant_id' => $plantId, 'event_key' => 'care-recommendation:' . $contextHash,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                $cached = care_recommendation($pdo, $userId, $plantId, $contextHash);
                if ($cached !== null) return ['success' => true, 'cached' => true, 'plant_id' => $plantId, 'recommendation' => $cached];
            }
            throw $error;
        }
        return [
            'success' => true, 'cached' => false, 'plant_id' => $plantId,
            'recommendation' => care_recommendation($pdo, $userId, $plantId, $contextHash),
        ];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function care_task_rows(PDO $pdo, int $userId, string $start, string $end, ?string $plantId = null): array
{
    $sql =     'SELECT t.*, p.name plant_name, h.id latest_scan_id
            FROM fertilizer_tasks t
            JOIN plants p ON p.user_id = t.user_id AND p.id = t.plant_id
            LEFT JOIN (
                SELECT user_id, plant_id, id,
                       ROW_NUMBER() OVER (PARTITION BY user_id, plant_id ORDER BY created_at DESC, id DESC) scan_rank
                FROM plant_analysis_history WHERE user_id = :scan_user_id AND plant_id IS NOT NULL
            ) h ON h.user_id = t.user_id AND h.plant_id = t.plant_id AND h.scan_rank = 1
            WHERE t.user_id = :user_id AND t.scheduled_on BETWEEN :start_on AND :end_on';
    $parameters = ['scan_user_id' => $userId, 'user_id' => $userId, 'start_on' => $start, 'end_on' => $end];
    if ($plantId !== null) {
        $sql .= ' AND t.plant_id = :plant_id';
        $parameters['plant_id'] = $plantId;
    }
    $sql .= ' ORDER BY t.scheduled_on, t.scheduled_time, t.id';
    $query = $pdo->prepare($sql);
    $query->execute($parameters);
    $now = care_now();
    return array_map(static function (array $row) use ($now): array {
        $time = substr((string) $row['scheduled_time'], 0, 5);
        $overdue = $row['status'] === 'pending'
            && ((string) $row['scheduled_on'] < $now->format('Y-m-d')
                || ((string) $row['scheduled_on'] === $now->format('Y-m-d') && $time < $now->format('H:i')));
        return [
            'id' => (string) $row['id'], 'plant_id' => (string) $row['plant_id'],
            'plant_name' => (string) $row['plant_name'],
            'plant_image_url' => $row['latest_scan_id'] !== null
                ? '/api/plant-monitoring.php?image=' . rawurlencode((string) $row['latest_scan_id']) : null,
            'scheduled_on' => (string) $row['scheduled_on'], 'scheduled_time' => $time,
            'fertilizer_category' => (string) $row['fertilizer_category'],
            'guidance' => (string) $row['guidance'], 'status' => (string) $row['status'],
            'display_status' => $overdue ? 'overdue' : (string) $row['status'],
            'product_name' => $row['product_name'] !== null ? (string) $row['product_name'] : null,
            'applied_amount' => $row['applied_amount'] !== null ? (string) $row['applied_amount'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'reminder_sent_at' => $row['reminder_sent_at'] !== null ? (string) $row['reminder_sent_at'] : null,
        ];
    }, $query->fetchAll());
}

function care_log_activity(PDO $pdo, int $userId, string $plantId, string $taskId, string $type, string $date, string $title, string $details): void
{
    $plant = care_owner($pdo, $userId, $plantId);
    if ($plant === null) throw new RuntimeException('The task plant is no longer available.');
    $activity = $pdo->prepare(
        'INSERT INTO farm_activities
         (id, user_id, plant_id, care_task_id, activity_type, activity_date, title, details, related_plant)
         VALUES (:id, :user_id, :plant_id, :task_id, :type, :activity_date, :title, :details, :related_plant)'
    );
    $activity->execute([
        'id' => app_uuid_v4(), 'user_id' => $userId, 'plant_id' => $plantId,
        'task_id' => $taskId, 'type' => $type, 'activity_date' => $date,
        'title' => $title, 'details' => $details, 'related_plant' => (string) $plant['name'],
    ]);
}

function care_action(PDO $pdo, int $userId, array $data, DateTimeImmutable $now): array
{
    $action = $data['action'] ?? '';
    $plantId = is_string($data['plant_id'] ?? null) ? trim($data['plant_id']) : '';
    if ($action === 'assess') {
        if (!care_valid_uuid($plantId)) json_response(['success' => false, 'message' => 'Select a registered plant.'], 422);
        return care_assess($pdo, $userId, $plantId);
    }
    if ($action === 'schedule-recommendation') {
        $recommendationId = is_string($data['recommendation_id'] ?? null) ? trim($data['recommendation_id']) : '';
        if (!care_valid_uuid($plantId) || !care_valid_uuid($recommendationId)) json_response(['success' => false, 'message' => 'Select a valid plant recommendation.'], 422);
        $query = $pdo->prepare(
            'SELECT id, context_hash, fertilizer_category, recommended_on, guidance
             FROM fertilizer_recommendations
             WHERE id = :id AND user_id = :user_id AND plant_id = :plant_id AND decision = :decision LIMIT 1'
        );
        $query->execute(['id' => $recommendationId, 'user_id' => $userId, 'plant_id' => $plantId, 'decision' => 'apply']);
        $recommendation = $query->fetch();
        if ($recommendation === false) json_response(['success' => false, 'message' => 'This recommendation is no longer available to schedule.'], 404);
        $plant = care_owner($pdo, $userId, $plantId);
        $currentContext = care_context($pdo, $userId, $plant);
        if (!hash_equals((string) $recommendation['context_hash'], care_context_hash($currentContext['context']))) {
            json_response(['success' => false, 'message' => 'Plant records changed after this recommendation. Reassess before scheduling.'], 409);
        }
        if ((string) $recommendation['recommended_on'] < $now->format('Y-m-d')) {
            json_response(['success' => false, 'message' => 'This fertilizer recommendation date has passed. Reassess before scheduling.'], 409);
        }
        $time = is_string($data['scheduled_time'] ?? null) ? trim($data['scheduled_time']) : '08:00';
        if (!care_valid_date((string) $recommendation['recommended_on']) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            json_response(['success' => false, 'message' => 'The recommended schedule date or time is invalid.'], 422);
        }
        $existing = $pdo->prepare('SELECT id FROM fertilizer_tasks WHERE recommendation_id = :recommendation_id AND user_id = :user_id AND plant_id = :plant_id LIMIT 1');
        $existing->execute(['recommendation_id' => $recommendationId, 'user_id' => $userId, 'plant_id' => $plantId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) return ['success' => true, 'message' => 'This recommendation is already on the care schedule.', 'task_id' => (string) $existingId];
        $insert = $pdo->prepare(
            'INSERT INTO fertilizer_tasks
             (id, user_id, plant_id, recommendation_id, scheduled_on, scheduled_time, fertilizer_category, guidance, status)
             VALUES (:id, :user_id, :plant_id, :recommendation_id, :scheduled_on, :scheduled_time, :category, :guidance, :status)'
        );
        $taskId = app_uuid_v4();
        try {
            $insert->execute([
                'id' => $taskId, 'user_id' => $userId, 'plant_id' => $plantId,
                'recommendation_id' => $recommendationId, 'scheduled_on' => $recommendation['recommended_on'],
                'scheduled_time' => $time . ':00', 'category' => $recommendation['fertilizer_category'],
                'guidance' => $recommendation['guidance'], 'status' => 'pending',
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $existing->execute(['recommendation_id' => $recommendationId, 'user_id' => $userId, 'plant_id' => $plantId]);
            $existingId = $existing->fetchColumn();
            if ($existingId === false) throw $error;
            return ['success' => true, 'message' => 'This recommendation is already on the care schedule.', 'task_id' => (string) $existingId];
        }
        return ['success' => true, 'message' => 'Fertilizer recommendation added to the care schedule.', 'task_id' => $taskId];
    }
    if ($action === 'record-application') {
        if (!care_valid_uuid($plantId) || care_owner($pdo, $userId, $plantId) === null) json_response(['success' => false, 'message' => 'Select one of your registered plants.'], 404);
        $product = trim(is_string($data['product_name'] ?? null) ? $data['product_name'] : '');
        $amount = trim(is_string($data['applied_amount'] ?? null) || is_numeric($data['applied_amount'] ?? null) ? (string) $data['applied_amount'] : '');
        $category = is_string($data['fertilizer_category'] ?? null) ? $data['fertilizer_category'] : 'other';
        $date = is_string($data['applied_on'] ?? null) ? trim($data['applied_on']) : $now->format('Y-m-d');
        $categories = ['organic', 'compost', 'balanced_npk', 'nitrogen_rich', 'crop_specific', 'other'];
        if ($product === '' || mb_strlen($product, 'UTF-8') > 160 || mb_strlen($amount, 'UTF-8') > 100
            || !in_array($category, $categories, true) || !care_valid_date($date) || $date > $now->format('Y-m-d')) {
            json_response(['success' => false, 'message' => 'Enter a product, valid category, application date, and an optional amount.'], 422);
        }
        $taskId = app_uuid_v4();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO fertilizer_tasks
                 (id, user_id, plant_id, scheduled_on, scheduled_time, fertilizer_category, guidance,
                  status, product_name, applied_amount, completed_at)
                 VALUES (:id, :user_id, :plant_id, :date, :time, :category, :guidance, :status, :product, :amount, :completed_at)'
            );
            $insert->execute([
                'id' => $taskId, 'user_id' => $userId, 'plant_id' => $plantId, 'date' => $date,
                'time' => '08:00:00', 'category' => $category, 'guidance' => 'Recorded by the grower; follow the product label.',
                'status' => 'completed', 'product' => $product, 'amount' => $amount !== '' ? $amount : null,
                'completed_at' => $now->format('Y-m-d H:i:s'),
            ]);
            care_log_activity($pdo, $userId, $plantId, $taskId, 'Fertilizer', $date, 'Fertilizer application recorded',
                $product . ($amount !== '' ? ' · ' . $amount : '') . ' · ' . str_replace('_', ' ', $category));
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['success' => true, 'message' => 'Fertilizer application recorded in Care History and Farm Logs.', 'task_id' => $taskId];
    }
    if ($action === 'complete') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        if (!care_valid_uuid($taskId)) json_response(['success' => false, 'message' => 'Select a valid fertilizer task.'], 422);
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare(
                'SELECT plant_id, scheduled_on, fertilizer_category, guidance, product_name, applied_amount
                 FROM fertilizer_tasks
                 WHERE id = :id AND user_id = :user_id AND status = :pending FOR UPDATE'
            );
            $query->execute(['id' => $taskId, 'user_id' => $userId, 'pending' => 'pending']);
            $task = $query->fetch();
            if ($task === false) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'Task not found or already updated. Refresh the care schedule.'], 409);
            }
            $product = trim(is_string($data['product_name'] ?? null) ? $data['product_name'] : (string) ($task['product_name'] ?? ''));
            $amount = trim(is_string($data['applied_amount'] ?? null) || is_numeric($data['applied_amount'] ?? null)
                ? (string) $data['applied_amount'] : (string) ($task['applied_amount'] ?? ''));
            if (mb_strlen($product, 'UTF-8') > 160 || mb_strlen($amount, 'UTF-8') > 100) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'Product and amount details are too long.'], 422);
            }
            $update = $pdo->prepare(
                'UPDATE fertilizer_tasks SET status = :completed, completed_at = :at,
                   product_name = :product, applied_amount = :amount
                 WHERE id = :id AND user_id = :user_id AND status = :pending'
            );
            $update->execute([
                'completed' => 'completed', 'at' => $now->format('Y-m-d H:i:s'),
                'product' => $product !== '' ? $product : null, 'amount' => $amount !== '' ? $amount : null,
                'id' => $taskId, 'user_id' => $userId, 'pending' => 'pending',
            ]);
            care_log_activity($pdo, $userId, (string) $task['plant_id'], $taskId, 'Fertilizer', (string) $task['scheduled_on'],
                'Fertilizer application recorded',
                ($product !== '' ? $product : str_replace('_', ' ', (string) $task['fertilizer_category']))
                    . ($amount !== '' ? ' · ' . $amount : '') . ' · ' . (string) $task['guidance']);
            $notice = $pdo->prepare('UPDATE notifications SET is_completed = 1, is_read = 1 WHERE user_id = :user_id AND related_record_id = :task_id');
            $notice->execute(['user_id' => $userId, 'task_id' => $taskId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['success' => true, 'message' => 'Fertilizer task completed and saved to Care History and Farm Logs.'];
    }
    if ($action === 'dismiss') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        if (!care_valid_uuid($taskId)) json_response(['success' => false, 'message' => 'Select a valid fertilizer task.'], 422);
        $update = $pdo->prepare('UPDATE fertilizer_tasks SET status = :cancelled WHERE id = :id AND user_id = :user_id AND status = :pending');
        $update->execute(['cancelled' => 'cancelled', 'id' => $taskId, 'user_id' => $userId, 'pending' => 'pending']);
        if ($update->rowCount() !== 1) json_response(['success' => false, 'message' => 'Task not found or already updated.'], 409);
        $notice = $pdo->prepare(
            'UPDATE notifications SET dismissed_at = :dismissed_at
             WHERE user_id = :user_id AND related_module = :module AND related_record_id = :task_id'
        );
        $notice->execute([
            'dismissed_at' => $now->format('Y-m-d H:i:s'), 'user_id' => $userId,
            'module' => 'plant-care', 'task_id' => $taskId,
        ]);
        return ['success' => true, 'message' => 'Fertilizer reminder dismissed.'];
    }
    if ($action === 'claim-reminder') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        if (!care_valid_uuid($taskId)) json_response(['success' => false, 'message' => 'Select a valid fertilizer reminder.'], 422);
        $claim = $pdo->prepare(
            'UPDATE fertilizer_tasks SET reminder_sent_at = :sent
             WHERE id = :id AND user_id = :user_id AND status = :pending AND reminder_sent_at IS NULL
               AND (scheduled_on < :before_today OR (scheduled_on = :on_today AND scheduled_time <= :now_time))'
        );
        $claim->execute([
            'sent' => $now->format('Y-m-d H:i:s'), 'id' => $taskId, 'user_id' => $userId,
            'pending' => 'pending', 'before_today' => $now->format('Y-m-d'),
            'on_today' => $now->format('Y-m-d'), 'now_time' => $now->format('H:i:s'),
        ]);
        return ['success' => true, 'claimed' => $claim->rowCount() === 1];
    }
    json_response(['success' => false, 'message' => 'Unknown plant-care action.'], 400);
}

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
    $now = care_now();
    if ($method === 'POST') {
        $data = read_json_request();
        try {
            $response = care_action($pdo, (int) $user['id'], $data, $now);
        } catch (RuntimeException $error) {
            error_log('TanimTrack fertilizer AI failure: ' . $error->getMessage());
            json_response(['success' => false, 'message' => $error->getMessage()], 503);
        }
        json_response($response, ($data['action'] ?? '') === 'record-application' ? 201 : 200);
    }

    $month = is_string($_GET['month'] ?? null) ? trim($_GET['month']) : $now->format('Y-m');
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) json_response(['success' => false, 'message' => 'Choose a valid month.'], 422);
    $start = $month . '-01';
    $end = (new DateTimeImmutable($start, new DateTimeZone(CARE_TIMEZONE)))->modify('last day of this month')->format('Y-m-d');
    $userId = (int) $user['id'];
    $today = $now->format('Y-m-d');
    $tasks = care_task_rows($pdo, $userId, $start, $end);
    $due = care_task_rows($pdo, $userId, $today, $today);
    $overdue = care_task_rows($pdo, $userId, '2000-01-01', $today);
    $overdue = array_values(array_filter($overdue, static fn (array $task): bool =>
        $task['status'] === 'pending' && $task['display_status'] === 'overdue'
    ));
    $requestedPlantId = is_string($_GET['plant_id'] ?? null) ? trim($_GET['plant_id']) : '';
    if ($requestedPlantId !== '' && (!care_valid_uuid($requestedPlantId) || care_owner($pdo, $userId, $requestedPlantId) === null)) {
        json_response(['success' => false, 'message' => 'This plant is not available in your account.'], 404);
    }
    $plantsSql = 'SELECT id, name, species, location, planted_on FROM plants WHERE user_id = :user_id';
    $plantParameters = ['user_id' => $userId];
    if ($requestedPlantId !== '') {
        $plantsSql .= ' AND id = :plant_id';
        $plantParameters['plant_id'] = $requestedPlantId;
    }
    $plantsQuery = $pdo->prepare($plantsSql . ' ORDER BY created_at DESC');
    $plantsQuery->execute($plantParameters);
    $plants = [];
    foreach ($plantsQuery->fetchAll() as $plant) {
        $plantId = (string) $plant['id'];
        $data = care_context($pdo, $userId, $plant);
        $contextHash = care_context_hash($data['context']);
        $recommendation = care_recommendation($pdo, $userId, $plantId, $contextHash);
        $plants[] = [
            'id' => $plantId, 'name' => (string) $plant['name'], 'species' => $plant['species'],
            'planted_on' => $plant['planted_on'] !== null ? (string) $plant['planted_on'] : null,
            'age_weeks' => $data['context']['plant']['age_weeks'],
            'growth_stage' => $data['growth_stage'], 'latest_scan' => $data['scans'][0] ?? null,
            'recommendation' => $recommendation,
            'needs_assessment' => $data['scans'] !== [] && $recommendation === null,
            'soil' => $data['context']['soil'],
        ];
    }
    $countQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM fertilizer_tasks
         WHERE user_id = :user_id AND status = :status AND scheduled_on = :today'
    );
    $countQuery->execute(['user_id' => $userId, 'status' => 'pending', 'today' => $today]);
    $response = [
        'success' => true, 'today' => $today, 'month' => $month, 'timezone' => CARE_TIMEZONE,
        'today_count' => (int) $countQuery->fetchColumn(), 'plants' => $plants,
        'tasks' => $tasks, 'tasks_due_today' => $due, 'overdue_tasks' => $overdue,
    ];
    if ($requestedPlantId !== '') {
        $plantId = $requestedPlantId;
        $response['tasks'] = care_task_rows($pdo, $userId, '2000-01-01', '2099-12-31', $plantId);
        $waterQuery = $pdo->prepare(
            'SELECT scheduled_on, scheduled_time, amount_ml, status, completed_at
             FROM watering_tasks WHERE user_id = :user_id AND plant_id = :plant_id AND status <> :cancelled
             ORDER BY COALESCE(completed_at, CONCAT(scheduled_on, \' \', scheduled_time)) DESC LIMIT 30'
        );
        $waterQuery->execute(['user_id' => $userId, 'plant_id' => $plantId, 'cancelled' => 'cancelled']);
        $response['watering_history'] = array_map(static fn (array $row): array => [
            'scheduled_on' => (string) $row['scheduled_on'], 'scheduled_time' => substr((string) $row['scheduled_time'], 0, 5),
            'amount_ml' => $row['amount_ml'] !== null ? (int) $row['amount_ml'] : null,
            'status' => (string) $row['status'],
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        ], $waterQuery->fetchAll());
        $response['care_history'] = care_task_rows($pdo, $userId, '2000-01-01', '2099-12-31', $plantId);
    }
    json_response($response);
} catch (PDOException $error) {
    error_log('TanimTrack plant care database request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load or save plant-care data. Apply the smart care migration and try again.'], 503);
} catch (Throwable $error) {
    error_log('TanimTrack plant care request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to process plant-care data. Please try again.'], 503);
}
