<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

require_method('POST');
$data = read_json_request();
$mobile = normalize_mobile_number(is_string($data['mobile_number'] ?? null) ? $data['mobile_number'] : '');
$code = is_string($data['otp'] ?? null) ? trim($data['otp']) : '';
$errors = [];
if ($mobile === null) {
    $errors['mobile_number'] = 'Enter the mobile number used to register.';
}
if (preg_match('/^\d{6}$/', $code) !== 1) {
    $errors['otp'] = 'Enter the six-digit verification code.';
}
if ($errors !== []) {
    json_response(['success' => false, 'message' => 'Please enter a valid mobile number and verification code.', 'errors' => $errors], 422);
}

$pdo = pdo_connection();
$pdo->beginTransaction();
try {
    $lookup = $pdo->prepare(
        'SELECT id, full_name, mobile_number, email, password_hash, otp_hash, expires_at, attempts
         FROM registration_otps
         WHERE mobile_number = :mobile_number
           AND consumed_at IS NULL
           AND attempts < 5
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $lookup->execute(['mobile_number' => $mobile]);
    $pending = $lookup->fetch();
    if ($pending === false) {
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired. Request a new code.'], 422);
    }

    $expiresAt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        (string) $pending['expires_at'],
        new DateTimeZone('UTC')
    );
    if ($expiresAt === false || $expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        $pdo->prepare('UPDATE registration_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $pending['id']]);
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired. Request a new code.'], 422);
    }

    if (!password_verify($code, (string) $pending['otp_hash'])) {
        $attempts = (int) $pending['attempts'] + 1;
        $pdo->prepare(
            'UPDATE registration_otps
             SET attempts = :attempts,
                 consumed_at = IF(:attempts_for_lock >= 5, UTC_TIMESTAMP(), consumed_at)
             WHERE id = :id'
        )->execute([
            'attempts' => $attempts,
            'attempts_for_lock' => $attempts,
            'id' => $pending['id'],
        ]);
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired. Request a new code.'], 422);
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (full_name, mobile_number, email, password_hash)
         VALUES (:full_name, :mobile_number, :email, :password_hash)'
    );
    $insert->execute([
        'full_name' => $pending['full_name'],
        'mobile_number' => $pending['mobile_number'],
        'email' => $pending['email'],
        'password_hash' => $pending['password_hash'],
    ]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE registration_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => $pending['id']]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error->getCode() === '23000') {
        json_response([
            'success' => false,
            'message' => 'An account with this email address or mobile number already exists. Start registration again with different details.',
        ], 409);
    }
    error_log('TanimTrack registration verification failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to verify the code right now. Please try again.'], 500);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TanimTrack registration verification failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to verify the code right now. Please try again.'], 500);
}

start_app_session();
session_regenerate_id(true);
$_SESSION['user_id'] = $userId;
$_SESSION['full_name'] = (string) $pending['full_name'];
$_SESSION['role'] = 'farmer';

json_response([
    'success' => true,
    'message' => 'Your phone number is verified and your account has been created.',
    'user' => [
        'id' => $userId,
        'full_name' => (string) $pending['full_name'],
        'email' => (string) $pending['email'],
        'role' => 'farmer',
    ],
], 201);
