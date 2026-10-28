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

$aiUrl = getenv('TANODBUKID_AI_URL') ?: 'http://127.0.0.1:8080/predict';
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
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$responseBody = curl_exec($request);
$status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($request);
curl_close($request);

if (!is_string($responseBody) || $status < 200 || $status >= 300) {
    error_log('TanimTrack AI request failed: ' . ($curlError !== '' ? $curlError : 'upstream HTTP ' . $status));
    json_response(['success' => false, 'message' => 'The AI scanner is temporarily unavailable. Check the AI service logs and try again.'], 503);
}

$result = json_decode($responseBody, true);
if (!is_array($result) || ($result['success'] ?? false) !== true || !is_array($result['prediction'] ?? null)) {
    error_log('TanimTrack AI returned an invalid prediction response.');
    json_response(['success' => false, 'message' => 'The AI scanner returned an invalid result. Please try again.'], 502);
}

json_response($result);
