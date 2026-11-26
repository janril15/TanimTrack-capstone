<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

require_method('POST');
start_app_session();
$sessionUser = session_user();
if ($sessionUser === null) {
    json_response(['success' => false, 'message' => 'Sign in before submitting a crop image.'], 401);
}

$plantId = '';
if (array_key_exists('plant_id', $_POST)) {
    if (!is_string($_POST['plant_id']) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $_POST['plant_id']) !== 1) {
        json_response(['success' => false, 'message' => 'Choose a valid registered plant before scanning.'], 422);
    }
    $plantId = $_POST['plant_id'];
    try {
        $plantQuery = pdo_connection()->prepare('SELECT id FROM plants WHERE id = :id AND user_id = :user_id LIMIT 1');
        $plantQuery->execute(['id' => $plantId, 'user_id' => $sessionUser['id']]);
    } catch (PDOException $error) {
        error_log('TanimTrack scanner plant lookup failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to confirm the selected plant. Please try again.'], 503);
    }
    if ($plantQuery->fetchColumn() === false) {
        json_response(['success' => false, 'message' => 'This plant could not be found in your account.'], 404);
    }
}

$upload = $_FILES['scan_image'] ?? null;
if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) {
    json_response(['success' => false, 'message' => 'Choose a crop image to analyze.'], 422);
}
if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
    json_response(['success' => false, 'message' => 'The crop image must be 5 MB or smaller.'], 413);
}
if ($upload['error'] !== UPLOAD_ERR_OK || !isset($upload['tmp_name']) || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
    json_response(['success' => false, 'message' => 'The crop image upload failed. Please try again.'], 400);
}

$size = filesize($upload['tmp_name']);
if ($size === false || $size <= 0 || $size > 5 * 1024 * 1024) {
    json_response(['success' => false, 'message' => 'The crop image must be 5 MB or smaller.'], 413);
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
$image = @getimagesize($upload['tmp_name']);
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!is_string($mimeType) || !in_array($mimeType, $allowedTypes, true) || $image === false || ($image['mime'] ?? null) !== $mimeType) {
    json_response(['success' => false, 'message' => 'Upload a valid JPG, PNG, or WebP crop image.'], 415);
}

$aiUrl = getenv('TANODBUKID_AI_URL') ?: 'http://ai:8080/predict';
$curlFile = new CURLFile($upload['tmp_name'], $mimeType, 'crop-image');
$request = curl_init($aiUrl);
if ($request === false) {
    error_log('TanimTrack scanner could not initialize the AI request.');
    json_response(['success' => false, 'message' => 'The AI scanner is temporarily unavailable.'], 503);
}

curl_setopt_array($request, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['image' => $curlFile],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$responseBody = curl_exec($request);
$status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
$curlErrorNumber = curl_errno($request);
$curlError = curl_error($request);
curl_close($request);

if (!is_string($responseBody)) {
    if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT) {
        error_log('TanimTrack AI request timed out.');
        json_response(['success' => false, 'message' => 'The AI scanner timed out. Please try again.'], 504);
    }
    error_log('TanimTrack AI request failed: ' . ($curlError !== '' ? $curlError : 'no response'));
    json_response(['success' => false, 'message' => 'The AI scanner is temporarily unavailable. Please try again.'], 503);
}

if ($status < 200 || $status >= 300) {
    $upstreamMessages = [
        413 => [413, 'The image is too large to analyze.'],
        415 => [415, 'Upload a valid JPG, PNG, or WebP plant image.'],
        429 => [429, 'The AI scanner is rate limited. Please wait and try again.'],
        504 => [504, 'The AI scanner timed out. Please try again.'],
    ];
    if (isset($upstreamMessages[$status])) {
        [$responseStatus, $message] = $upstreamMessages[$status];
        json_response(['success' => false, 'message' => $message], $responseStatus);
    }
    error_log('TanimTrack AI request failed with HTTP status ' . $status . '.');
    json_response(['success' => false, 'message' => 'The AI scanner is temporarily unavailable. Please try again.'], 503);
}

$result = json_decode($responseBody, true);
if (
    !is_array($result)
    || ($result['success'] ?? false) !== true
    || !array_key_exists('plant_name', $result)
    || (!is_string($result['plant_name'] ?? null) && ($result['plant_name'] ?? null) !== null)
    || !array_key_exists('detected_issue', $result)
    || !in_array($result['health_status'] ?? null, ['Healthy', 'Diseased', 'Pest-Affected', 'Nutrient Deficiency', 'Uncertain'], true)
    || (!is_string($result['detected_issue'] ?? null) && ($result['detected_issue'] ?? null) !== null)
    || !in_array($result['severity'] ?? null, ['None', 'Low', 'Moderate', 'High', 'Unknown'], true)
    || !in_array($result['confidence'] ?? null, ['Low', 'Medium', 'High', 'Unknown'], true)
    || !is_string($result['analysis'] ?? null)
    || trim($result['analysis']) === ''
    || !is_bool($result['needs_verification'] ?? null)
    || (($result['health_status'] ?? null) === 'Uncertain' && $result['needs_verification'] !== true)
    || (($result['plant_name'] ?? null) === null && (
        ($result['health_status'] ?? null) !== 'Uncertain'
        || ($result['detected_issue'] ?? null) !== null
        || ($result['severity'] ?? null) !== 'Unknown'
        || ($result['confidence'] ?? null) !== 'Unknown'
    ))
    || (($result['health_status'] ?? null) === 'Healthy' && (
        ($result['detected_issue'] ?? null) !== null
        || ($result['severity'] ?? null) !== 'None'
    ))
) {
    error_log('TanimTrack AI returned an invalid analysis response.');
    json_response(['success' => false, 'message' => 'The AI scanner returned an invalid result. Please try again.'], 502);
}

foreach (['visible_symptoms', 'recommendations', 'prevention_tips'] as $listField) {
    if (!is_array($result[$listField] ?? null) || !array_is_list($result[$listField])) {
        error_log('TanimTrack AI returned an invalid analysis response.');
        json_response(['success' => false, 'message' => 'The AI scanner returned an invalid result. Please try again.'], 502);
    }
    foreach ($result[$listField] as $item) {
        if (!is_string($item)) {
            error_log('TanimTrack AI returned an invalid analysis response.');
            json_response(['success' => false, 'message' => 'The AI scanner returned an invalid result. Please try again.'], 502);
        }
    }
}

if ($plantId !== '') {
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $filename = null;
    try {
        $directory = __DIR__ . '/../uploads/plant-scans';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('The plant scan image directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The plant scan image directory is not writable.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
        if (!move_uploaded_file($upload['tmp_name'], $directory . '/' . $filename)) {
            throw new RuntimeException('The plant scan image could not be saved.');
        }

        $scanId = app_uuid_v4();
        $insert = pdo_connection()->prepare(
            'INSERT INTO plant_analysis_history
                (id, user_id, plant_id, image_path, image_mime_type, plant_name,
                 health_status, detected_issue, severity, confidence, analysis_result)
             VALUES
                (:id, :user_id, :plant_id, :image_path, :image_mime_type, :plant_name,
                 :health_status, :detected_issue, :severity, :confidence, :analysis_result)'
        );
        $insert->execute([
            'id' => $scanId,
            'user_id' => $sessionUser['id'],
            'plant_id' => $plantId,
            'image_path' => 'uploads/plant-scans/' . $filename,
            'image_mime_type' => $mimeType,
            'plant_name' => $result['plant_name'],
            'health_status' => $result['health_status'],
            'detected_issue' => $result['detected_issue'],
            'severity' => $result['severity'],
            'confidence' => $result['confidence'],
            'analysis_result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $result['scan_id'] = $scanId;
        $result['plant_id'] = $plantId;
        $result['scan_saved'] = true;
    } catch (Throwable $error) {
        $imagePath = is_string($filename) ? __DIR__ . '/../uploads/plant-scans/' . $filename : '';
        if ($imagePath !== '' && is_file($imagePath) && !unlink($imagePath)) {
            error_log('TanimTrack failed to remove an unrecorded plant scan image.');
        }
        error_log('TanimTrack plant scan could not be persisted: ' . $error->getMessage());
        $result['scan_saved'] = false;
        $result['scan_save_error'] = 'The analysis completed, but this scan could not be saved to the selected plant. Please try again.';
    }
}

json_response($result);
