<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const WATERING_TZ = 'Asia/Manila';

function watering_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(WATERING_TZ));
}

function watering_uuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1;
}

function watering_read_json(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return [];
    }
    $result = json_decode($value, true);
    return is_array($result) ? $result : [];
}

function watering_number(mixed $value): ?float
{
    return $value !== null && is_numeric($value) ? (float) $value : null;
}

function watering_time_valid(string $time): bool
{
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) === 1;
}

function watering_date_valid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(WATERING_TZ));
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function watering_owned_plant(PDO $pdo, int $userId, string $plantId): ?array
{
    $statement = $pdo->prepare(
        'SELECT p.id, p.name, p.species, p.location,
                (SELECT a.growth_stage FROM farm_activities a
                 WHERE a.user_id = p.user_id AND a.plant_id = p.id
                   AND a.activity_type = :activity_type AND a.growth_stage IS NOT NULL AND a.growth_stage <> \'\'
                 ORDER BY a.activity_date DESC, a.created_at DESC LIMIT 1) AS logged_growth_stage
         FROM plants p WHERE p.user_id = :user_id AND p.id = :plant_id LIMIT 1'
    );
    $statement->execute(['activity_type' => 'Plant Growth', 'user_id' => $userId, 'plant_id' => $plantId]);
    $plant = $statement->fetch();
    return $plant === false ? null : $plant;
}

function watering_scans(PDO $pdo, int $userId, string $plantId, int $limit = 5): array
{
    $limit = max(1, min(5, $limit));
    $statement = $pdo->prepare(
        'SELECT id, created_at, health_status, detected_issue, visible_symptoms, possible_causes,
                leaf_count, plant_size_cm, plant_height_cm, image_path, analysis_result
         FROM plant_analysis_history
         WHERE user_id = :user_id AND plant_id = :plant_id
         ORDER BY created_at DESC, id DESC LIMIT ' . $limit
    );
    $statement->execute(['user_id' => $userId, 'plant_id' => $plantId]);
    return array_map(static function (array $row): array {
        $analysis = watering_read_json($row['analysis_result']);
        $symptoms = watering_read_json($row['visible_symptoms']);
        $causes = watering_read_json($row['possible_causes']);
        return [
            'id' => (string) $row['id'],
            'scanned_at' => (string) $row['created_at'],
            'health_status' => (string) $row['health_status'],
            'growth_stage' => is_string($analysis['growth_stage'] ?? null) ? $analysis['growth_stage'] : null,
            'leaf_condition' => is_string($analysis['leaf_condition'] ?? null) ? $analysis['leaf_condition'] : null,
            'detected_issue' => $row['detected_issue'] !== null ? (string) $row['detected_issue'] : null,
            'visible_symptoms' => array_values(array_filter($symptoms ?: ($analysis['visible_symptoms'] ?? []), 'is_string')),
            'possible_causes' => array_values(array_filter($causes ?: ($analysis['possible_causes'] ?? []), 'is_string')),
            'analysis' => is_string($analysis['analysis'] ?? null) ? $analysis['analysis'] : '',
            'leaf_count' => $row['leaf_count'] !== null ? (int) $row['leaf_count'] : null,
            'plant_size_cm' => watering_number($row['plant_size_cm']),
            'plant_height_cm' => watering_number($row['plant_height_cm']),
            'image_url' => $row['image_path'] !== null
                ? '/api/plant-monitoring.php?image=' . rawurlencode((string) $row['id'])
                : null,
        ];
    }, $statement->fetchAll());
}

function watering_weather(PDO $pdo, ?string $plantLocation): array
{
    $configured = trim(getenv('TANODBUKID_CLIMATE_LOCATION') ?: 'Sta. Maria, Bulacan, Philippines');
    $normalize = static fn (string $value): string => preg_replace('/,\s*philippines$/i', '', mb_strtolower(trim($value), 'UTF-8')) ?? mb_strtolower(trim($value), 'UTF-8');
    if ($plantLocation === null || $plantLocation === '' || $normalize($plantLocation) !== $normalize($configured)) {
        return ['available' => false, 'reason' => 'No weather snapshot is matched to this plant location.'];
    }
    try {
        $statement = $pdo->prepare(
            'SELECT location_name, temperature, relative_humidity, precipitation, rain_probability, observed_at, fetched_at
             FROM weather_current WHERE location_name = :location LIMIT 1'
        );
        $statement->execute(['location' => $configured]);
        $current = $statement->fetch();
        if ($current === false) {
            return ['available' => false, 'reason' => 'No saved weather snapshot is available.'];
        }
        $fetchedAt = new DateTimeImmutable((string) $current['fetched_at'], new DateTimeZone(WATERING_TZ));
        $age = max(0, watering_now()->getTimestamp() - $fetchedAt->getTimestamp());
        if ($age > 21600) {
            return ['available' => false, 'reason' => 'The saved weather snapshot is older than six hours.'];
        }
        $forecast = $pdo->prepare(
            'SELECT SUM(precipitation) precipitation_24h, MAX(precipitation_probability) probability_24h
             FROM weather_forecasts
             WHERE location_name = :location AND forecast_kind = :kind
               AND forecast_at >= :now AND forecast_at < :until'
        );
        $now = watering_now();
        $forecast->execute([
            'location' => $configured,
            'kind' => 'hourly',
            'now' => $now->format('Y-m-d H:i:s'),
            'until' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
        ]);
        $next = $forecast->fetch() ?: [];
        return [
            'available' => true,
            'fetched_at' => (string) $current['fetched_at'],
            'data' => [
                'location_name' => (string) $current['location_name'],
                'observed_at' => (string) $current['observed_at'],
                'temperature_c' => watering_number($current['temperature']),
                'relative_humidity_percent' => $current['relative_humidity'] !== null ? (int) $current['relative_humidity'] : null,
                'precipitation_mm' => watering_number($current['precipitation']),
                'rain_probability_percent' => $current['rain_probability'] !== null ? (int) $current['rain_probability'] : null,
                'forecast_precipitation_24h_mm' => watering_number($next['precipitation_24h'] ?? null),
                'forecast_rain_probability_percent' => $next['probability_24h'] !== null ? (int) $next['probability_24h'] : null,
            ],
        ];
    } catch (PDOException $error) {
        error_log('TanimTrack watering weather lookup failed: ' . $error->getMessage());
        return ['available' => false, 'reason' => 'Weather is unavailable; no environmental reading was assumed.'];
    }
}

function watering_plan(PDO $pdo, int $userId, string $plantId): ?array
{
    $statement = $pdo->prepare(
        'SELECT wp.*, wr.source_scan_id, wr.source_weather_at, wr.decision, wr.evidence_status,
                wr.confidence, wr.frequency_days, wr.amount_min_ml, wr.amount_max_ml,
                wr.recommended_time, wr.first_scheduled_on, wr.reason,
                wr.soil_check_instruction, wr.warnings, wr.created_at recommendation_created_at
         FROM watering_plans wp
         LEFT JOIN watering_recommendations wr ON wr.id = wp.current_recommendation_id
          AND wr.user_id = wp.user_id AND wr.plant_id = wp.plant_id
         WHERE wp.user_id = :user_id AND wp.plant_id = :plant_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'plant_id' => $plantId]);
    $plan = $statement->fetch();
    return $plan === false ? null : $plan;
}

function watering_format_task(array $row, DateTimeImmutable $now): array
{
    $time = substr((string) $row['scheduled_time'], 0, 5);
    $overdue = $row['status'] === 'pending'
        && ((string) $row['scheduled_on'] < $now->format('Y-m-d')
            || ((string) $row['scheduled_on'] === $now->format('Y-m-d') && $time < $now->format('H:i')));
    return [
        'id' => (string) $row['id'],
        'plant_id' => (string) $row['plant_id'],
        'plant_name' => (string) ($row['plant_name'] ?? 'Registered plant'),
        'plant_image_url' => !empty($row['latest_scan_id'])
            ? '/api/plant-monitoring.php?image=' . rawurlencode((string) $row['latest_scan_id'])
            : null,
        'growth_stage' => $row['growth_stage'] ?? null,
        'plant_size_cm' => watering_number($row['plant_size_cm'] ?? null),
        'health_status' => $row['health_status'] ?? null,
        'scheduled_on' => (string) $row['scheduled_on'],
        'scheduled_time' => $time,
        'reminder_time' => $row['reminder_time'] !== null ? substr((string) $row['reminder_time'], 0, 5) : null,
        'amount_ml' => $row['amount_ml'] !== null ? (int) $row['amount_ml'] : null,
        'amount_min_ml' => $row['amount_min_ml'] !== null ? (int) $row['amount_min_ml'] : null,
        'amount_max_ml' => $row['amount_max_ml'] !== null ? (int) $row['amount_max_ml'] : null,
        'status' => (string) $row['status'],
        'display_status' => $overdue ? 'overdue' : (string) $row['status'],
        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        'reminder_sent_at' => $row['reminder_sent_at'] !== null ? (string) $row['reminder_sent_at'] : null,
    ];
}

function watering_count(PDO $pdo, int $userId, string $today): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM watering_tasks WHERE user_id = :user_id AND status = :status AND scheduled_on = :today');
    $statement->execute(['user_id' => $userId, 'status' => 'pending', 'today' => $today]);
    return (int) $statement->fetchColumn();
}

function watering_tasks(PDO $pdo, int $userId, string $where, array $parameters): array
{
    $statement = $pdo->prepare(
        'SELECT t.*, p.name plant_name, h.id latest_scan_id,
                h.plant_size_cm,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(h.analysis_result, \'$.growth_stage\')), a.growth_stage) growth_stage,
                h.health_status
         FROM watering_tasks t
         JOIN plants p ON p.user_id = t.user_id AND p.id = t.plant_id
         LEFT JOIN (
             SELECT user_id, plant_id, id, image_path, health_status, plant_size_cm, analysis_result,
                    ROW_NUMBER() OVER (PARTITION BY user_id, plant_id ORDER BY created_at DESC, id DESC) scan_rank
             FROM plant_analysis_history
             WHERE user_id = :scan_user_id AND plant_id IS NOT NULL
         ) h ON h.user_id = t.user_id AND h.plant_id = t.plant_id AND h.scan_rank = 1
         LEFT JOIN (
             SELECT user_id, plant_id, growth_stage,
                    ROW_NUMBER() OVER (PARTITION BY user_id, plant_id ORDER BY activity_date DESC, created_at DESC, id DESC) stage_rank
             FROM farm_activities
             WHERE user_id = :stage_user_id AND activity_type = :activity_type AND growth_stage IS NOT NULL AND growth_stage <> \'\'
         ) a ON a.user_id = t.user_id AND a.plant_id = t.plant_id AND a.stage_rank = 1
         WHERE t.user_id = :user_id AND ' . $where . '
         ORDER BY t.scheduled_on, t.scheduled_time, t.id'
    );
    $statement->execute([
        'scan_user_id' => $userId,
        'stage_user_id' => $userId,
        'activity_type' => 'Plant Growth',
        'user_id' => $userId,
        ...$parameters,
    ]);
    $now = watering_now();
    return array_map(static fn (array $row): array => watering_format_task($row, $now), $statement->fetchAll());
}

function watering_sync_notifications(PDO $pdo, int $userId): void
{
    $now = watering_now();
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO notifications
            (user_id, notification_type, title, message, related_module, related_record_id, event_key)
         SELECT t.user_id, :type, CONCAT(:title, p.name),
                CONCAT(:message, DATE_FORMAT(t.scheduled_on, :date_format), :at_text, TIME_FORMAT(t.scheduled_time, :time_format)),
                :module, t.id,
                CONCAT(:event_prefix, t.plant_id, :sep1, DATE_FORMAT(t.scheduled_on, :key_date),
                       :sep2, TIME_FORMAT(t.scheduled_time, :key_time), :sep3, TIME_FORMAT(t.reminder_time, :key_reminder))
         FROM watering_tasks t
         JOIN plants p ON p.user_id = t.user_id AND p.id = t.plant_id
         WHERE t.user_id = :user_id AND t.status = :status AND t.reminder_time IS NOT NULL
           AND (t.scheduled_on < :before_today OR (t.scheduled_on = :on_today AND t.reminder_time <= :now_time))'
    );
    $statement->execute([
        'type' => 'watering_reminder', 'title' => 'Watering reminder: ',
        'message' => 'Watering task scheduled for ', 'date_format' => '%b %e, %Y',
        'at_text' => ' at ', 'time_format' => '%H:%i', 'module' => 'watering',
        'event_prefix' => 'watering-slot:', 'sep1' => ':', 'key_date' => '%Y-%m-%d',
        'sep2' => ':', 'key_time' => '%H:%i', 'sep3' => ':', 'key_reminder' => '%H:%i',
        'user_id' => $userId, 'status' => 'pending',
        'before_today' => $now->format('Y-m-d'), 'on_today' => $now->format('Y-m-d'),
        'now_time' => $now->format('H:i:s'),
    ]);
}

function watering_schedule(PDO $pdo, int $userId, string $month, string $selectedDate): array
{
    $now = watering_now();
    watering_sync_notifications($pdo, $userId);
    $plantsStatement = $pdo->prepare(
        'SELECT p.id, p.name, p.species, p.location, p.planted_on,
                (SELECT a.growth_stage FROM farm_activities a
                 WHERE a.user_id = p.user_id AND a.plant_id = p.id
                   AND a.activity_type = :activity_type AND a.growth_stage IS NOT NULL AND a.growth_stage <> \'\'
                 ORDER BY a.activity_date DESC, a.created_at DESC LIMIT 1) logged_growth_stage
         FROM plants p WHERE p.user_id = :user_id ORDER BY p.created_at DESC'
    );
    $plantsStatement->execute(['activity_type' => 'Plant Growth', 'user_id' => $userId]);
    $plants = [];
    foreach ($plantsStatement->fetchAll() as $row) {
        $plantId = (string) $row['id'];
        $scans = watering_scans($pdo, $userId, $plantId);
        $latest = $scans[0] ?? null;
        $plan = watering_plan($pdo, $userId, $plantId);
        $settings = [
            'container_volume_l' => $plan ? watering_number($plan['container_volume_l']) : null,
            'growing_area_m2' => $plan ? watering_number($plan['growing_area_m2']) : null,
            'soil_type' => $plan ? $plan['soil_type'] : null,
            'soil_moisture_percent' => $plan ? watering_number($plan['soil_moisture_percent']) : null,
            'soil_moisture_recorded_at' => $plan ? $plan['soil_moisture_recorded_at'] : null,
            'custom_amount_ml' => $plan && $plan['custom_amount_ml'] !== null ? (int) $plan['custom_amount_ml'] : null,
            'custom_frequency_days' => $plan && $plan['custom_frequency_days'] !== null ? (int) $plan['custom_frequency_days'] : null,
            'reminder_time' => $plan && $plan['reminder_time'] !== null ? substr((string) $plan['reminder_time'], 0, 5) : null,
        ];
        $soilRecent = false;
        if ($settings['soil_moisture_recorded_at'] !== null) {
            $soilDate = new DateTimeImmutable((string) $settings['soil_moisture_recorded_at'], new DateTimeZone(WATERING_TZ));
            $soilAge = $now->getTimestamp() - $soilDate->getTimestamp();
            $soilRecent = $soilAge >= 0 && $soilAge <= 86400;
        }
        $recommendation = null;
        if ($plan && $plan['decision'] !== null) {
            $recommendation = [
                'source_scan_id' => (string) $plan['source_scan_id'],
                'source_weather_at' => $plan['source_weather_at'],
                'decision' => (string) $plan['decision'],
                'evidence_status' => (string) $plan['evidence_status'],
                'confidence' => (string) $plan['confidence'],
                'frequency_days' => (int) $plan['frequency_days'],
                'amount_min_ml' => $plan['amount_min_ml'] !== null ? (int) $plan['amount_min_ml'] : null,
                'amount_max_ml' => $plan['amount_max_ml'] !== null ? (int) $plan['amount_max_ml'] : null,
                'recommended_time' => substr((string) $plan['recommended_time'], 0, 5),
                'first_scheduled_on' => $plan['first_scheduled_on'],
                'reason' => (string) $plan['reason'],
                'soil_check_instruction' => (string) $plan['soil_check_instruction'],
                'warnings' => array_values(array_filter(watering_read_json($plan['warnings']), 'is_string')),
            ];
        }
        $needs = !$plan || (bool) $plan['needs_reassessment']
            || ($latest !== null && $recommendation && $recommendation['source_scan_id'] !== $latest['id']);
        $lastStatement = $pdo->prepare(
            'SELECT completed_at, amount_ml FROM watering_tasks
             WHERE user_id = :user_id AND plant_id = :plant_id AND status = :status
             ORDER BY completed_at DESC, id DESC LIMIT 1'
        );
        $lastStatement->execute(['user_id' => $userId, 'plant_id' => $plantId, 'status' => 'completed']);
        $last = $lastStatement->fetch();
        $nextStatement = $pdo->prepare(
            'SELECT scheduled_on, scheduled_time FROM watering_tasks
             WHERE user_id = :user_id AND plant_id = :plant_id AND status = :status AND scheduled_on >= :today
             ORDER BY scheduled_on, scheduled_time LIMIT 1'
        );
        $nextStatement->execute(['user_id' => $userId, 'plant_id' => $plantId, 'status' => 'pending', 'today' => $now->format('Y-m-d')]);
        $next = $nextStatement->fetch();
        $growth = $latest['growth_stage'] ?? null;
        if (!$growth || $growth === 'Unable to Determine') {
            $growth = $row['logged_growth_stage'];
        }
        $plants[] = [
            'id' => $plantId, 'name' => (string) $row['name'],
            'species' => $row['species'], 'location' => $row['location'],
            'growth_stage' => $growth, 'latest_scan' => $latest, 'scan_history' => $scans,
            'plan' => [
                'settings' => $settings, 'recommendation' => $recommendation,
                'needs_reassessment' => $needs, 'soil_moisture_recent' => $soilRecent,
            ],
            'last_watering' => $last === false ? null : ['completed_at' => (string) $last['completed_at'], 'amount_ml' => $last['amount_ml'] !== null ? (int) $last['amount_ml'] : null],
            'next_watering' => $next === false ? null : ['scheduled_on' => (string) $next['scheduled_on'], 'scheduled_time' => substr((string) $next['scheduled_time'], 0, 5)],
            'weather' => watering_weather($pdo, $row['location'] !== null ? (string) $row['location'] : null),
        ];
    }

    $start = $month . '-01';
    $end = (new DateTimeImmutable($start, new DateTimeZone(WATERING_TZ)))->modify('last day of this month')->format('Y-m-d');
    $tasks = watering_tasks($pdo, $userId, 't.scheduled_on BETWEEN :start_date AND :end_date', ['start_date' => $start, 'end_date' => $end]);
    $today = $now->format('Y-m-d');
    $due = watering_tasks($pdo, $userId, 't.scheduled_on = :today AND t.status = :status', ['today' => $today, 'status' => 'pending']);
    $overdue = watering_tasks($pdo, $userId, '(t.scheduled_on < :before_today OR (t.scheduled_on = :on_today AND t.scheduled_time < :time)) AND t.status = :status', [
        'before_today' => $today, 'on_today' => $today, 'time' => $now->format('H:i:s'), 'status' => 'pending',
    ]);
    return [
        'success' => true, 'today' => $today, 'now_time' => $now->format('H:i'),
        'timezone' => WATERING_TZ, 'month' => $month, 'selected_date' => $selectedDate,
        'today_count' => watering_count($pdo, $userId, $today),
        'plants' => $plants, 'tasks' => $tasks, 'tasks_due_today' => $due, 'overdue_tasks' => $overdue,
    ];
}

function watering_ai(array $context): array
{
    $url = trim(getenv('TANODBUKID_AI_WATERING_URL') ?: 'http://ai:8080/watering-recommendation');
    $request = curl_init($url);
    if ($request === false) {
        throw new RuntimeException('Unable to contact the AI watering service.');
    }
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
        error_log('Gemini watering call failed: ' . $error);
        throw new RuntimeException('The AI watering service could not be reached.');
    }
    $result = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($result)) {
        throw new RuntimeException(is_array($result) && is_string($result['message'] ?? null) ? $result['message'] : 'The AI watering service returned an invalid response.');
    }
    $min = $result['amount_min_ml'] ?? null;
    $max = $result['amount_max_ml'] ?? null;
    if (($result['success'] ?? null) !== true
        || !in_array($result['decision'] ?? null, ['water_now', 'check_soil', 'not_needed'], true)
        || !in_array($result['confidence'] ?? null, ['low', 'moderate', 'high'], true)
        || !is_int($result['frequency_days'] ?? null) || $result['frequency_days'] < 1 || $result['frequency_days'] > 30
        || !is_string($result['recommended_time'] ?? null) || !watering_time_valid($result['recommended_time'])
        || !is_string($result['reason'] ?? null) || trim($result['reason']) === ''
        || !is_string($result['soil_check_instruction'] ?? null) || trim($result['soil_check_instruction']) === ''
        || !is_array($result['warnings'] ?? null) || count($result['warnings']) > 5
        || (($min === null) !== ($max === null))
        || ($min !== null && (!is_int($min) || $min < 1 || $min > 1000000))
        || ($max !== null && (!is_int($max) || $max < 1 || $max > 1000000 || $max < $min))
    ) {
        throw new RuntimeException('The AI service returned an invalid watering recommendation.');
    }
    foreach ($result['warnings'] as $warning) {
        if (!is_string($warning) || trim($warning) === '') {
            throw new RuntimeException('The AI service returned an invalid watering warning.');
        }
    }
    return $result;
}

function watering_reassess(PDO $pdo, int $userId, string $plantId): array
{
    $plant = watering_owned_plant($pdo, $userId, $plantId);
    if ($plant === null) {
        json_response(['success' => false, 'message' => 'This plant is not available in your account.'], 404);
    }
    $scans = watering_scans($pdo, $userId, $plantId);
    if ($scans === []) {
        json_response(['success' => false, 'code' => 'scan_required', 'message' => 'No AI scan is available. Use the existing Plant/Crop Scan to establish a baseline.'], 409);
    }
    $now = watering_now();
    $plan = watering_plan($pdo, $userId, $plantId);
    $settings = [
        'container_volume_l' => $plan ? watering_number($plan['container_volume_l']) : null,
        'growing_area_m2' => $plan ? watering_number($plan['growing_area_m2']) : null,
        'soil_type' => $plan['soil_type'] ?? null,
        'soil_moisture_percent' => $plan ? watering_number($plan['soil_moisture_percent']) : null,
        'soil_moisture_recorded_at' => $plan['soil_moisture_recorded_at'] ?? null,
        'custom_amount_ml' => isset($plan['custom_amount_ml']) ? (int) $plan['custom_amount_ml'] : null,
        'custom_frequency_days' => isset($plan['custom_frequency_days']) ? (int) $plan['custom_frequency_days'] : null,
    ];
    $soilRecent = false;
    if ($settings['soil_moisture_recorded_at']) {
        $soilDate = new DateTimeImmutable((string) $settings['soil_moisture_recorded_at'], new DateTimeZone(WATERING_TZ));
        $age = $now->getTimestamp() - $soilDate->getTimestamp();
        $soilRecent = $age >= 0 && $age <= 86400;
    }
    $historyStatement = $pdo->prepare(
        'SELECT completed_at, amount_ml FROM watering_tasks
         WHERE user_id = :user_id AND plant_id = :plant_id AND status = :status
         ORDER BY completed_at DESC LIMIT 20'
    );
    $historyStatement->execute(['user_id' => $userId, 'plant_id' => $plantId, 'status' => 'completed']);
    $history = array_map(static fn (array $row): array => [
        'completed_at' => (string) $row['completed_at'],
        'amount_ml' => $row['amount_ml'] !== null ? (int) $row['amount_ml'] : null,
    ], $historyStatement->fetchAll());
    $weather = watering_weather($pdo, $plant['location'] !== null ? (string) $plant['location'] : null);
    $soilForAi = $settings;
    if (!$soilRecent) {
        $soilForAi['soil_moisture_percent'] = null;
    }
    $context = [
        'plant_name' => (string) $plant['name'], 'species' => $plant['species'],
        'growth_stage' => $scans[0]['growth_stage'] ?? $plant['logged_growth_stage'],
        'scan_history' => $scans, 'watering_history' => $history,
        'soil' => $soilForAi, 'weather' => $weather['available'] ? $weather['data'] : null,
        'timezone' => WATERING_TZ,
    ];
    $result = watering_ai($context);
    $frequency = $settings['custom_frequency_days'] ?? $result['frequency_days'];
    $lastWatering = $history[0]['completed_at'] ?? null;
    $scheduledOn = null;
    if ($result['decision'] === 'water_now') {
        $scheduledOn = $lastWatering !== null && substr($lastWatering, 0, 10) === $now->format('Y-m-d')
            ? $now->modify('+1 day')->format('Y-m-d') : $now->format('Y-m-d');
    } elseif ($result['decision'] === 'not_needed') {
        $candidate = $lastWatering
            ? (new DateTimeImmutable($lastWatering, new DateTimeZone(WATERING_TZ)))->modify('+' . $frequency . ' days')->format('Y-m-d')
            : $now->modify('+' . $frequency . ' days')->format('Y-m-d');
        $scheduledOn = max($now->format('Y-m-d'), $candidate);
    }
    $scanAt = new DateTimeImmutable($scans[0]['scanned_at'], new DateTimeZone(WATERING_TZ));
    $evidence = $result['decision'] === 'check_soil' && !$soilRecent
        ? 'awaiting_soil_confirmation'
        : ($scanAt->diff($now)->days <= 30 ? 'recent_scan' : 'previous_scan');
    $recommendationId = app_uuid_v4();
    $planId = $plan ? (string) $plan['id'] : app_uuid_v4();
    $amountMin = $settings['custom_amount_ml'] ?? $result['amount_min_ml'];
    $amountMax = $settings['custom_amount_ml'] ?? $result['amount_max_ml'];
    $reminderTime = $plan['reminder_time'] ?? ($result['recommended_time'] . ':00');

    $pdo->beginTransaction();
    try {
        if (!$plan) {
            $insertPlan = $pdo->prepare('INSERT INTO watering_plans (id, user_id, plant_id, needs_reassessment) VALUES (:id, :user_id, :plant_id, :needs)');
            $insertPlan->execute(['id' => $planId, 'user_id' => $userId, 'plant_id' => $plantId, 'needs' => 1]);
        }
        $insertRecommendation = $pdo->prepare(
            'INSERT INTO watering_recommendations
             (id, user_id, plant_id, source_scan_id, source_weather_at, decision, evidence_status,
              confidence, frequency_days, amount_min_ml, amount_max_ml, recommended_time,
              first_scheduled_on, reason, soil_check_instruction, warnings)
             VALUES
             (:id, :user_id, :plant_id, :scan_id, :weather_at, :decision, :evidence,
              :confidence, :frequency, :amount_min, :amount_max, :time,
              :scheduled_on, :reason, :soil_instruction, :warnings)'
        );
        $insertRecommendation->execute([
            'id' => $recommendationId, 'user_id' => $userId, 'plant_id' => $plantId,
            'scan_id' => $scans[0]['id'], 'weather_at' => $weather['available'] ? $weather['fetched_at'] : null,
            'decision' => $result['decision'], 'evidence' => $evidence, 'confidence' => $result['confidence'],
            'frequency' => $result['frequency_days'], 'amount_min' => $result['amount_min_ml'],
            'amount_max' => $result['amount_max_ml'], 'time' => $result['recommended_time'] . ':00',
            'scheduled_on' => $scheduledOn, 'reason' => mb_substr(trim($result['reason']), 0, 1200),
            'soil_instruction' => mb_substr(trim($result['soil_check_instruction']), 0, 500),
            'warnings' => json_encode($result['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $cancel = $pdo->prepare('UPDATE watering_tasks SET status = :cancelled WHERE user_id = :user_id AND plant_id = :plant_id AND status = :pending');
        $cancel->execute(['cancelled' => 'cancelled', 'user_id' => $userId, 'plant_id' => $plantId, 'pending' => 'pending']);
        $updatePlan = $pdo->prepare(
            'UPDATE watering_plans SET current_recommendation_id = :recommendation, needs_reassessment = :needs
             WHERE user_id = :user_id AND plant_id = :plant_id'
        );
        $updatePlan->execute(['recommendation' => $recommendationId, 'needs' => 0, 'user_id' => $userId, 'plant_id' => $plantId]);
        if ($scheduledOn !== null) {
            $insertTask = $pdo->prepare(
                'INSERT INTO watering_tasks
                 (id, user_id, plant_id, recommendation_id, scheduled_on, scheduled_time, reminder_time,
                  amount_ml, amount_min_ml, amount_max_ml, status)
                 VALUES (:id, :user_id, :plant_id, :recommendation, :date, :time, :reminder, :amount, :min, :max, :status)'
            );
            $start = new DateTimeImmutable($scheduledOn, new DateTimeZone(WATERING_TZ));
            $horizon = $now->modify('+90 days');
            for ($day = $start; $day <= $horizon; $day = $day->modify('+' . $frequency . ' days')) {
                $insertTask->execute([
                    'id' => app_uuid_v4(), 'user_id' => $userId, 'plant_id' => $plantId,
                    'recommendation' => $recommendationId, 'date' => $day->format('Y-m-d'),
                    'time' => $result['recommended_time'] . ':00', 'reminder' => $reminderTime,
                    'amount' => $settings['custom_amount_ml'], 'min' => $amountMin, 'max' => $amountMax,
                    'status' => 'pending',
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return [
        'success' => true, 'plant_id' => $plantId, 'source_scan_id' => $scans[0]['id'],
        'recommendation_id' => $recommendationId, 'recommendation' => $result,
    ];
}

function watering_number_input(array $data, string $key, float $min, float $max, bool $integer = false): ?float
{
    if (!isset($data[$key]) || $data[$key] === '') return null;
    if (!is_numeric($data[$key])) {
        json_response(['success' => false, 'message' => 'Enter valid numbers for the watering settings.'], 422);
    }
    $value = (float) $data[$key];
    if (!is_finite($value) || $value < $min || $value > $max || ($integer && floor($value) !== $value)) {
        json_response(['success' => false, 'message' => 'A watering setting is outside its allowed range.'], 422);
    }
    return $value;
}

function watering_save_settings(PDO $pdo, int $userId, array $data, DateTimeImmutable $now): array
{
    $plantId = is_string($data['plant_id'] ?? null) ? trim($data['plant_id']) : '';
    if (!watering_uuid($plantId) || watering_owned_plant($pdo, $userId, $plantId) === null) {
        json_response(['success' => false, 'message' => 'Select one of your registered plants.'], 404);
    }
    $container = watering_number_input($data, 'container_volume_l', 0.01, 100000);
    $area = watering_number_input($data, 'growing_area_m2', 0.001, 100000);
    $moisture = watering_number_input($data, 'soil_moisture_percent', 0, 100);
    $amount = watering_number_input($data, 'custom_amount_ml', 1, 1000000, true);
    $frequency = watering_number_input($data, 'custom_frequency_days', 1, 30, true);
    $soil = trim(is_string($data['soil_type'] ?? null) ? $data['soil_type'] : '');
    $reminder = trim(is_string($data['reminder_time'] ?? null) ? $data['reminder_time'] : '');
    if (mb_strlen($soil, 'UTF-8') > 80 || ($reminder !== '' && !watering_time_valid($reminder))) {
        json_response(['success' => false, 'message' => 'Check the soil type and reminder time.'], 422);
    }
    $statement = $pdo->prepare(
        'INSERT INTO watering_plans
         (id, user_id, plant_id, container_volume_l, growing_area_m2, soil_type, soil_moisture_percent,
          soil_moisture_recorded_at, custom_amount_ml, custom_frequency_days, reminder_time, needs_reassessment)
         VALUES (:id, :user_id, :plant_id, :container, :area, :soil, :moisture, :recorded, :amount, :frequency, :reminder, 1)
         ON DUPLICATE KEY UPDATE container_volume_l = VALUES(container_volume_l),
          growing_area_m2 = VALUES(growing_area_m2), soil_type = VALUES(soil_type),
          soil_moisture_percent = VALUES(soil_moisture_percent), soil_moisture_recorded_at = VALUES(soil_moisture_recorded_at),
          custom_amount_ml = VALUES(custom_amount_ml), custom_frequency_days = VALUES(custom_frequency_days),
          reminder_time = VALUES(reminder_time), needs_reassessment = 1'
    );
    $statement->execute([
        'id' => app_uuid_v4(), 'user_id' => $userId, 'plant_id' => $plantId,
        'container' => $container, 'area' => $area, 'soil' => $soil !== '' ? $soil : null,
        'moisture' => $moisture, 'recorded' => $moisture !== null ? $now->format('Y-m-d H:i:s') : null,
        'amount' => $amount, 'frequency' => $frequency, 'reminder' => $reminder !== '' ? $reminder . ':00' : null,
    ]);
    return ['success' => true, 'message' => 'Plant-specific watering settings saved.'];
}

function watering_action(PDO $pdo, int $userId, array $data, DateTimeImmutable $now): array
{
    $action = $data['action'] ?? '';
    if ($action === 'save-settings') return watering_save_settings($pdo, $userId, $data, $now);
    if ($action === 'reassess') {
        $plantId = is_string($data['plant_id'] ?? null) ? trim($data['plant_id']) : '';
        if (!watering_uuid($plantId)) json_response(['success' => false, 'message' => 'Select a registered plant.'], 422);
        return watering_reassess($pdo, $userId, $plantId);
    }
    if ($action === 'complete') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        if (!watering_uuid($taskId)) json_response(['success' => false, 'message' => 'Select a valid watering task.'], 422);
        $statement = $pdo->prepare('UPDATE watering_tasks SET status = :completed, completed_at = :at WHERE id = :id AND user_id = :user_id AND status = :pending');
        $statement->execute(['completed' => 'completed', 'at' => $now->format('Y-m-d H:i:s'), 'id' => $taskId, 'user_id' => $userId, 'pending' => 'pending']);
        if ($statement->rowCount() !== 1) json_response(['success' => false, 'message' => 'Task not found or already updated. Refresh the schedule.'], 409);
        return ['success' => true, 'message' => 'Watering marked complete.', 'today_count' => watering_count($pdo, $userId, $now->format('Y-m-d'))];
    }
    if ($action === 'create-task') {
        $plantId = is_string($data['plant_id'] ?? null) ? trim($data['plant_id']) : '';
        $date = is_string($data['scheduled_on'] ?? null) ? trim($data['scheduled_on']) : '';
        $time = is_string($data['scheduled_time'] ?? null) ? trim($data['scheduled_time']) : '';
        $reminder = is_string($data['reminder_time'] ?? null) ? trim($data['reminder_time']) : '';
        $amount = watering_number_input($data, 'amount_ml', 1, 1000000, true);
        if (!watering_uuid($plantId) || watering_owned_plant($pdo, $userId, $plantId) === null
            || !watering_date_valid($date) || $date < $now->format('Y-m-d')
            || !watering_time_valid($time) || ($reminder !== '' && !watering_time_valid($reminder))) {
            json_response(['success' => false, 'message' => 'Choose a registered plant and valid date and time.'], 422);
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO watering_tasks (id, user_id, plant_id, scheduled_on, scheduled_time, reminder_time, amount_ml, status)
                 VALUES (:id, :user_id, :plant_id, :date, :time, :reminder, :amount, :status)'
            );
            $statement->execute([
                'id' => app_uuid_v4(), 'user_id' => $userId, 'plant_id' => $plantId,
                'date' => $date, 'time' => $time . ':00', 'reminder' => $reminder !== '' ? $reminder . ':00' : null,
                'amount' => $amount, 'status' => 'pending',
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') json_response(['success' => false, 'message' => 'A task already exists at that plant and time.'], 409);
            throw $error;
        }
        return ['success' => true, 'message' => 'Watering task added.', 'today_count' => watering_count($pdo, $userId, $now->format('Y-m-d'))];
    }
    if ($action === 'reschedule') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        $date = is_string($data['scheduled_on'] ?? null) ? trim($data['scheduled_on']) : '';
        $time = is_string($data['scheduled_time'] ?? null) ? trim($data['scheduled_time']) : '';
        if (!watering_uuid($taskId) || !watering_date_valid($date) || $date < $now->format('Y-m-d') || !watering_time_valid($time)) {
            json_response(['success' => false, 'message' => 'Choose a valid future date and time.'], 422);
        }
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare('SELECT * FROM watering_tasks WHERE id = :id AND user_id = :user_id FOR UPDATE');
            $select->execute(['id' => $taskId, 'user_id' => $userId]);
            $old = $select->fetch();
            if (!$old || $old['status'] !== 'pending') {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'Only your pending tasks can be rescheduled.'], 409);
            }
            $cancel = $pdo->prepare('UPDATE watering_tasks SET status = :cancelled WHERE id = :id AND user_id = :user_id AND status = :pending');
            $cancel->execute(['cancelled' => 'cancelled', 'id' => $taskId, 'user_id' => $userId, 'pending' => 'pending']);
            $insert = $pdo->prepare(
                'INSERT INTO watering_tasks
                 (id, user_id, plant_id, recommendation_id, rescheduled_from_task_id, scheduled_on, scheduled_time,
                  reminder_time, amount_ml, amount_min_ml, amount_max_ml, status)
                 VALUES (:id, :user_id, :plant_id, :recommendation, :old_id, :date, :time, :reminder, :amount, :min, :max, :status)'
            );
            $insert->execute([
                'id' => app_uuid_v4(), 'user_id' => $userId, 'plant_id' => $old['plant_id'],
                'recommendation' => $old['recommendation_id'], 'old_id' => $taskId, 'date' => $date,
                'time' => $time . ':00', 'reminder' => $old['reminder_time'], 'amount' => $old['amount_ml'],
                'min' => $old['amount_min_ml'], 'max' => $old['amount_max_ml'], 'status' => 'pending',
            ]);
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error->getCode() === '23000') json_response(['success' => false, 'message' => 'A task already exists at that date and time.'], 409);
            throw $error;
        }
        return ['success' => true, 'message' => 'Watering task rescheduled.', 'today_count' => watering_count($pdo, $userId, $now->format('Y-m-d'))];
    }
    if ($action === 'claim-reminder') {
        $taskId = is_string($data['task_id'] ?? null) ? trim($data['task_id']) : '';
        if (!watering_uuid($taskId)) json_response(['success' => false, 'message' => 'Select a valid reminder.'], 422);
        $statement = $pdo->prepare(
            'UPDATE watering_tasks SET reminder_sent_at = :sent
             WHERE id = :id AND user_id = :user_id AND status = :pending
               AND reminder_time IS NOT NULL AND reminder_sent_at IS NULL
               AND (scheduled_on < :before_today OR (scheduled_on = :on_today AND reminder_time <= :time))'
        );
        $statement->execute([
            'sent' => $now->format('Y-m-d H:i:s'), 'id' => $taskId, 'user_id' => $userId,
            'pending' => 'pending', 'before_today' => $now->format('Y-m-d'),
            'on_today' => $now->format('Y-m-d'), 'time' => $now->format('H:i:s'),
        ]);
        return ['success' => true, 'claimed' => $statement->rowCount() === 1];
    }
    json_response(['success' => false, 'message' => 'Unknown watering action.'], 400);
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
    $now = watering_now();
    if ($method === 'GET') {
        if (($_GET['action'] ?? 'schedule') === 'count') {
            watering_sync_notifications($pdo, (int) $user['id']);
            json_response(['success' => true, 'today' => $now->format('Y-m-d'), 'today_count' => watering_count($pdo, (int) $user['id'], $now->format('Y-m-d'))]);
        }
        $month = is_string($_GET['month'] ?? null) ? trim($_GET['month']) : $now->format('Y-m');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) json_response(['success' => false, 'message' => 'Choose a valid month.'], 422);
        $selectedDate = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : $now->format('Y-m-d');
        if (!watering_date_valid($selectedDate) || substr($selectedDate, 0, 7) !== $month) $selectedDate = $month . '-01';
        json_response(watering_schedule($pdo, (int) $user['id'], $month, $selectedDate));
    }
    $data = read_json_request();
    try {
        $response = watering_action($pdo, (int) $user['id'], $data, $now);
    } catch (RuntimeException $error) {
        error_log('TanimTrack watering AI failure: ' . $error->getMessage());
        json_response(['success' => false, 'message' => $error->getMessage()], 503);
    }
    json_response($response, ($data['action'] ?? '') === 'create-task' ? 201 : 200);
} catch (PDOException $error) {
    error_log('TanimTrack watering database request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load or save watering data. Apply the watering schedule migration and try again.'], 503);
} catch (Throwable $error) {
    error_log('TanimTrack watering request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to process the watering request. Please try again.'], 503);
}
