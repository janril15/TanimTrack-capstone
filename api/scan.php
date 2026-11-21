<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

require_method('POST');
start_app_session();
if (session_user() === null) {
    json_response(['success' => false, 'message' => 'Sign in before submitting a crop image.'], 401);
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

json_response($result);
