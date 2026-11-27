<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const FARM_REPORTS_TIMEZONE = 'Asia/Manila';

function farm_reports_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(FARM_REPORTS_TIMEZONE));
}

function farm_reports_date(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(FARM_REPORTS_TIMEZONE));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function farm_reports_filters(array $input): array
{
    $now = farm_reports_now();
    $from = is_string($input['from'] ?? null) ? trim($input['from']) : '';
    $to = is_string($input['to'] ?? null) ? trim($input['to']) : '';
    if ($from === '' && $to === '') {
        $to = $now->format('Y-m-d');
        $from = $now->modify('-29 days')->format('Y-m-d');
    }
    if (!farm_reports_date($from) || !farm_reports_date($to) || $from > $to) {
        json_response(['success' => false, 'message' => 'Choose a valid report date range.'], 422);
    }
    $start = new DateTimeImmutable($from, new DateTimeZone(FARM_REPORTS_TIMEZONE));
    $end = new DateTimeImmutable($to, new DateTimeZone(FARM_REPORTS_TIMEZONE));
    if ($start->diff($end)->days > 1825) {
        json_response(['success' => false, 'message' => 'Choose a date range of five years or less.'], 422);
    }

    $plantId = is_string($input['plant_id'] ?? null) ? trim($input['plant_id']) : '';
    if ($plantId !== '' && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $plantId) !== 1) {
        json_response(['success' => false, 'message' => 'Choose a valid registered plant.'], 422);
    }
    return ['from' => $from, 'to' => $to, 'plant_id' => $plantId !== '' ? $plantId : null];
}

function farm_reports_health_group(?string $healthStatus, ?string $plantHealth): string
{
    if ($healthStatus === null) {
        return 'Unable to Determine';
    }
    if ($healthStatus === 'Uncertain' || $plantHealth === 'Unable to Determine') {
        return 'Unable to Determine';
    }
    if ($plantHealth === 'Unhealthy' || in_array($healthStatus, ['Diseased', 'Pest-Affected'], true)) {
        return 'Unhealthy';
    }
    if ($healthStatus === 'Healthy' && ($plantHealth === null || $plantHealth === 'Healthy')) {
        return 'Healthy';
    }
    if ($plantHealth === 'Showing Possible Stress' || $healthStatus === 'Nutrient Deficiency') {
        return 'Needs Attention';
    }
    return 'Needs Attention';
}

function farm_reports_local_datetime(?string $value, DateTimeZone $sourceZone): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return (new DateTimeImmutable($value, $sourceZone))
        ->setTimezone(new DateTimeZone(FARM_REPORTS_TIMEZONE))
        ->format(DATE_ATOM);
}

function farm_reports_data(PDO $pdo, int $userId, array $filters): array
{
    $today = farm_reports_now();
    $startUtc = (new DateTimeImmutable($filters['from'] . ' 00:00:00', new DateTimeZone(FARM_REPORTS_TIMEZONE)))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
    $endUtc = (new DateTimeImmutable($filters['to'] . ' 00:00:00', new DateTimeZone(FARM_REPORTS_TIMEZONE)))
        ->modify('+1 day')
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $plantStatement = $pdo->prepare(
        'SELECT p.id, p.name, p.species, latest.created_at scanned_at, latest.health_status,
                latest.plant_health, latest.growth_stage, latest.detected_issue, latest.analysis
         FROM plants p
         LEFT JOIN (
             SELECT scans.user_id, scans.plant_id, scans.created_at, scans.health_status,
                    JSON_UNQUOTE(JSON_EXTRACT(scans.analysis_result, \'$.plant_health\')) plant_health,
                    JSON_UNQUOTE(JSON_EXTRACT(scans.analysis_result, \'$.growth_stage\')) growth_stage,
                    scans.detected_issue,
                    JSON_UNQUOTE(JSON_EXTRACT(scans.analysis_result, \'$.analysis\')) analysis,
                    ROW_NUMBER() OVER (
                        PARTITION BY scans.user_id, scans.plant_id
                        ORDER BY scans.created_at DESC, scans.id DESC
                    ) scan_rank
             FROM plant_analysis_history scans
             WHERE scans.user_id = :scan_user_id AND scans.plant_id IS NOT NULL
         ) latest ON latest.user_id = p.user_id AND latest.plant_id = p.id AND latest.scan_rank = 1
         WHERE p.user_id = :plant_user_id
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $plantStatement->execute(['scan_user_id' => $userId, 'plant_user_id' => $userId]);
    $plants = [];
    $distribution = [
        'Healthy' => 0,
        'Needs Attention' => 0,
        'Unhealthy' => 0,
        'Unable to Determine' => 0,
    ];
    foreach ($plantStatement->fetchAll() as $row) {
        $status = $row['health_status'] !== null ? (string) $row['health_status'] : null;
        $plantHealth = $row['plant_health'] !== null ? (string) $row['plant_health'] : null;
        $group = farm_reports_health_group($status, $plantHealth);
        $distribution[$group]++;
        $analysis = $row['analysis'] !== null ? trim((string) $row['analysis']) : '';
        $issue = $row['detected_issue'] !== null ? trim((string) $row['detected_issue']) : '';
        $plants[] = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'species' => $row['species'] !== null ? (string) $row['species'] : '',
            'health_group' => $group,
            'health_status' => $status,
            'plant_health' => $plantHealth,
            'growth_stage' => $row['growth_stage'] !== null ? (string) $row['growth_stage'] : null,
            'latest_scan_at' => farm_reports_local_datetime(
                $row['scanned_at'] !== null ? (string) $row['scanned_at'] : null,
                new DateTimeZone('UTC')
            ),
            'finding' => mb_substr($issue !== '' ? $issue : $analysis, 0, 360, 'UTF-8'),
        ];
    }
    if ($filters['plant_id'] !== null
        && !in_array($filters['plant_id'], array_column($plants, 'id'), true)) {
        json_response(['success' => false, 'message' => 'This plant is not available in your account.'], 404);
    }
    $summaryPlants = $filters['plant_id'] === null
        ? $plants
        : array_values(array_filter(
            $plants,
            static fn (array $plant): bool => $plant['id'] === $filters['plant_id']
        ));
    $summaryDistribution = array_fill_keys(array_keys($distribution), 0);
    foreach ($summaryPlants as $plant) {
        $summaryDistribution[$plant['health_group']]++;
    }

    $scanSql = 'SELECT scans.id, scans.plant_id, plants.name plant_name, scans.created_at,
                       scans.health_status,
                       JSON_UNQUOTE(JSON_EXTRACT(scans.analysis_result, \'$.plant_health\')) plant_health,
                       JSON_UNQUOTE(JSON_EXTRACT(scans.analysis_result, \'$.analysis\')) analysis,
                       scans.detected_issue
                FROM plant_analysis_history scans
                JOIN plants ON plants.id = scans.plant_id AND plants.user_id = scans.user_id
                WHERE scans.user_id = :scan_user_id
                  AND scans.created_at >= :from_utc AND scans.created_at < :until_utc';
    $scanParameters = ['scan_user_id' => $userId, 'from_utc' => $startUtc, 'until_utc' => $endUtc];
    if ($filters['plant_id'] !== null) {
        $scanSql .= ' AND scans.plant_id = :scan_plant_id';
        $scanParameters['scan_plant_id'] = $filters['plant_id'];
    }
    $scanSql .= ' ORDER BY scans.created_at, scans.id';
    $scanStatement = $pdo->prepare($scanSql);
    $scanStatement->execute($scanParameters);
    $scanHistory = array_map(static function (array $row): array {
        $status = (string) $row['health_status'];
        $plantHealth = $row['plant_health'] !== null ? (string) $row['plant_health'] : null;
        $analysis = $row['analysis'] !== null ? trim((string) $row['analysis']) : '';
        $issue = $row['detected_issue'] !== null ? trim((string) $row['detected_issue']) : '';
        return [
            'id' => (string) $row['id'],
            'plant_id' => (string) $row['plant_id'],
            'plant_name' => (string) $row['plant_name'],
            'scanned_at' => farm_reports_local_datetime((string) $row['created_at'], new DateTimeZone('UTC')),
            'health_status' => $status,
            'plant_health' => $plantHealth,
            'health_group' => farm_reports_health_group($status, $plantHealth),
            'finding' => mb_substr($issue !== '' ? $issue : $analysis, 0, 360, 'UTF-8'),
        ];
    }, $scanStatement->fetchAll());
    $previousHealthByPlant = [];
    foreach ($scanHistory as &$scan) {
        $scan['previous_health_group'] = $previousHealthByPlant[$scan['plant_id']] ?? null;
        $scan['health_changed'] = $scan['previous_health_group'] !== null
            && $scan['previous_health_group'] !== $scan['health_group'];
        $previousHealthByPlant[$scan['plant_id']] = $scan['health_group'];
    }
    unset($scan);

    $taskWhere = 'user_id = :task_user_id AND scheduled_on BETWEEN :from_date AND :to_date AND status <> :cancelled';
    $taskParameters = [
        'task_user_id' => $userId,
        'from_date' => $filters['from'],
        'to_date' => $filters['to'],
        'cancelled' => 'cancelled',
    ];
    if ($filters['plant_id'] !== null) {
        $taskWhere .= ' AND plant_id = :task_plant_id';
        $taskParameters['task_plant_id'] = $filters['plant_id'];
    }
    $taskSummaryStatement = $pdo->prepare(
        'SELECT status, COUNT(*) task_count,
                SUM(CASE WHEN status = :pending_status
                          AND (scheduled_on < :overdue_before
                               OR (scheduled_on = :overdue_today AND scheduled_time < :overdue_time))
                         THEN 1 ELSE 0 END) overdue_count
         FROM watering_tasks WHERE ' . $taskWhere . ' GROUP BY status'
    );
    $taskSummaryStatement->execute([
        'pending_status' => 'pending',
        'overdue_before' => $today->format('Y-m-d'),
        'overdue_today' => $today->format('Y-m-d'),
        'overdue_time' => $today->format('H:i:s'),
        ...$taskParameters,
    ]);
    $taskTotals = ['completed' => 0, 'pending' => 0, 'overdue' => 0];
    foreach ($taskSummaryStatement->fetchAll() as $row) {
        $status = (string) $row['status'];
        if ($status === 'completed') {
            $taskTotals['completed'] = (int) $row['task_count'];
        } elseif ($status === 'pending') {
            $taskTotals['pending'] = (int) $row['task_count'];
            $taskTotals['overdue'] = (int) $row['overdue_count'];
        }
    }
    $taskTotals['scheduled'] = $taskTotals['completed'] + $taskTotals['pending'];
    $taskTotals['completion_percent'] = $taskTotals['scheduled'] > 0
        ? round(100 * $taskTotals['completed'] / $taskTotals['scheduled'], 1)
        : null;

    $taskSql = 'SELECT tasks.id, tasks.plant_id, plants.name plant_name, tasks.scheduled_on,
                       tasks.scheduled_time, tasks.amount_ml, tasks.amount_min_ml, tasks.amount_max_ml,
                       tasks.status, tasks.completed_at
                FROM watering_tasks tasks
                JOIN plants ON plants.id = tasks.plant_id AND plants.user_id = tasks.user_id
                WHERE tasks.user_id = :history_user_id
                  AND tasks.scheduled_on BETWEEN :history_from AND :history_to
                  AND tasks.status <> :history_cancelled';
    $historyParameters = [
        'history_user_id' => $userId,
        'history_from' => $filters['from'],
        'history_to' => $filters['to'],
        'history_cancelled' => 'cancelled',
    ];
    if ($filters['plant_id'] !== null) {
        $taskSql .= ' AND tasks.plant_id = :history_plant_id';
        $historyParameters['history_plant_id'] = $filters['plant_id'];
    }
    $taskSql .= ' ORDER BY tasks.scheduled_on DESC, tasks.scheduled_time DESC, tasks.id DESC LIMIT 100';
    $taskHistoryStatement = $pdo->prepare($taskSql);
    $taskHistoryStatement->execute($historyParameters);
    $taskHistory = array_map(static function (array $row) use ($today): array {
        $isOverdue = $row['status'] === 'pending'
            && ((string) $row['scheduled_on'] < $today->format('Y-m-d')
                || ((string) $row['scheduled_on'] === $today->format('Y-m-d')
                    && substr((string) $row['scheduled_time'], 0, 8) < $today->format('H:i:s')));
        return [
            'id' => (string) $row['id'],
            'plant_id' => (string) $row['plant_id'],
            'plant_name' => (string) $row['plant_name'],
            'scheduled_on' => (string) $row['scheduled_on'],
            'scheduled_time' => substr((string) $row['scheduled_time'], 0, 5),
            'amount_ml' => $row['amount_ml'] !== null ? (int) $row['amount_ml'] : null,
            'amount_min_ml' => $row['amount_min_ml'] !== null ? (int) $row['amount_min_ml'] : null,
            'amount_max_ml' => $row['amount_max_ml'] !== null ? (int) $row['amount_max_ml'] : null,
            'status' => $isOverdue ? 'overdue' : (string) $row['status'],
            'completed_at' => farm_reports_local_datetime(
                $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
                new DateTimeZone(FARM_REPORTS_TIMEZONE)
            ),
        ];
    }, $taskHistoryStatement->fetchAll());

    $nextWhere = 'user_id = :next_user_id AND status = :next_pending
                  AND (scheduled_on > :next_after_today
                       OR (scheduled_on = :next_on_today AND scheduled_time >= :next_time))';
    $nextParameters = [
        'next_user_id' => $userId,
        'next_pending' => 'pending',
        'next_after_today' => $today->format('Y-m-d'),
        'next_on_today' => $today->format('Y-m-d'),
        'next_time' => $today->format('H:i:s'),
    ];
    if ($filters['plant_id'] !== null) {
        $nextWhere .= ' AND plant_id = :next_plant_id';
        $nextParameters['next_plant_id'] = $filters['plant_id'];
    }
    $nextStatement = $pdo->prepare(
        'SELECT scheduled_on, scheduled_time FROM watering_tasks WHERE ' . $nextWhere
        . ' ORDER BY scheduled_on, scheduled_time LIMIT 1'
    );
    $nextStatement->execute($nextParameters);
    $next = $nextStatement->fetch();
    $healthyPlants = $summaryDistribution['Healthy'];
    $plantCount = count($summaryPlants);
    foreach ($summaryDistribution as $group => $count) {
        $summaryDistribution[$group] = [
            'count' => $count,
            'percent' => $plantCount > 0 ? round(100 * $count / $plantCount, 1) : null,
        ];
    }

    return [
        'success' => true,
        'period' => ['from' => $filters['from'], 'to' => $filters['to']],
        'selected_plant_id' => $filters['plant_id'],
        'summary' => [
            'registered_plants' => $plantCount,
            'healthy_percent' => $plantCount > 0 ? round(100 * $healthyPlants / $plantCount, 1) : null,
            'health_distribution' => $summaryDistribution,
        ],
        'plants' => $plants,
        'scan_history' => $scanHistory,
        'watering_activity' => [
            ...$taskTotals,
            'next_scheduled' => $next === false ? null : [
                'scheduled_on' => (string) $next['scheduled_on'],
                'scheduled_time' => substr((string) $next['scheduled_time'], 0, 5),
            ],
        ],
        'watering_history' => $taskHistory,
        'insights_available' => $scanHistory !== [] || $taskTotals['scheduled'] > 0,
    ];
}

function farm_reports_ai_insights(array $reportData): array
{
    if (!$reportData['insights_available']) {
        json_response([
            'success' => false,
            'code' => 'insufficient_data',
            'message' => 'Save a plant scan or watering record before generating AI insights.',
        ], 409);
    }
    $plants = array_values(array_filter(
        $reportData['plants'],
        static fn (array $plant): bool => $plant['latest_scan_at'] !== null
            && ($reportData['selected_plant_id'] === null || $plant['id'] === $reportData['selected_plant_id'])
    ));
    $context = [
        'period' => $reportData['period'],
        'plants' => array_slice(array_map(static fn (array $plant): array => [
            'plant_name' => $plant['name'],
            'species' => $plant['species'] !== '' ? $plant['species'] : null,
            'health_group' => $plant['health_group'],
            'health_status' => $plant['health_status'],
            'latest_scan_at' => $plant['latest_scan_at'],
            'finding' => $plant['finding'] !== '' ? $plant['finding'] : null,
        ], $plants), 0, 50),
        'scan_history' => array_slice(array_map(static fn (array $scan): array => [
            'plant_name' => $scan['plant_name'],
            'scanned_at' => $scan['scanned_at'],
            'health_group' => $scan['health_group'],
            'health_status' => $scan['health_status'],
            'finding' => $scan['finding'] !== '' ? $scan['finding'] : null,
        ], $reportData['scan_history']), -100),
        'watering_activity' => [
            'scheduled' => $reportData['watering_activity']['scheduled'],
            'completed' => $reportData['watering_activity']['completed'],
            'pending' => $reportData['watering_activity']['pending'],
            'overdue' => $reportData['watering_activity']['overdue'],
            'completion_percent' => $reportData['watering_activity']['completion_percent'],
        ],
        'watering_history' => array_slice(array_map(static fn (array $task): array => [
            'plant_name' => $task['plant_name'],
            'scheduled_on' => $task['scheduled_on'],
            'status' => $task['status'],
            'amount_ml' => $task['amount_ml'],
        ], $reportData['watering_history']), 0, 50),
    ];

    $url = trim(getenv('TANODBUKID_AI_REPORTS_URL') ?: 'http://ai:8080/report-insights');
    $request = curl_init($url);
    if ($request === false) {
        throw new RuntimeException('Unable to contact the AI reporting service.');
    }
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
    ]);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);
    if (!is_string($body)) {
        error_log('Gemini report insight request failed: ' . $error);
        throw new RuntimeException('The AI reporting service could not be reached.');
    }
    $result = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($result) || ($result['success'] ?? null) !== true
        || !is_array($result['insights'] ?? null) || count($result['insights']) > 5) {
        $message = is_array($result) && is_string($result['message'] ?? null)
            ? $result['message']
            : 'The AI reporting service returned an invalid response.';
        throw new RuntimeException($message);
    }
    foreach ($result['insights'] as $insight) {
        if (!is_array($insight)
            || !in_array($insight['category'] ?? null, ['trend', 'attention', 'watering', 'care'], true)
            || !is_string($insight['title'] ?? null) || trim($insight['title']) === ''
            || mb_strlen($insight['title'], 'UTF-8') > 100
            || !is_string($insight['detail'] ?? null) || trim($insight['detail']) === ''
            || mb_strlen($insight['detail'], 'UTF-8') > 450
            || (($insight['plant_name'] ?? null) !== null
                && (!is_string($insight['plant_name']) || mb_strlen($insight['plant_name'], 'UTF-8') > 120))) {
            throw new RuntimeException('The AI reporting service returned an invalid insight.');
        }
    }
    return ['success' => true, 'insights' => $result['insights']];
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

start_app_session();
$sessionUser = session_user();
if ($sessionUser === null) {
    json_response(['success' => false, 'message' => 'Authentication is required.'], 401);
}

try {
    $pdo = pdo_connection();
    $pdo->exec("SET time_zone = '+00:00'");
    $input = $method === 'GET' ? $_GET : read_json_request();
    $filters = farm_reports_filters($input);
    $data = farm_reports_data($pdo, (int) $sessionUser['id'], $filters);
    if ($method === 'GET') {
        json_response($data);
    }
    if (($input['action'] ?? '') !== 'insights') {
        json_response(['success' => false, 'message' => 'Unknown report action.'], 400);
    }
    json_response(farm_reports_ai_insights($data));
} catch (PDOException $error) {
    error_log('TanimTrack farm report database request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to load farm reports. Please try again.'], 503);
} catch (RuntimeException $error) {
    error_log('TanimTrack report insights failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('TanimTrack farm report request failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to process farm reports. Please try again.'], 503);
}
