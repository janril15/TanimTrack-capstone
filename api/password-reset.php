<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

require_method('POST');
$data = read_json_request();
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$mobile = normalize_mobile_number(is_string($data['mobile_number'] ?? null) ? $data['mobile_number'] : '');
if ($mobile === null) {
    json_response(['success' => false, 'message' => 'Enter a valid Philippine mobile number.'], 422);
}

$pdo = pdo_connection();

if ($action === 'send') {
    if (getenv('TANODBUKID_DEV_OTP') !== '1') {
        json_response([
            'success' => false,
            'message' => 'Password reset is unavailable because SMS delivery is not configured. Enable the local-only development OTP only on a private machine.',
        ], 503);
    }

    $lookup = $pdo->prepare('SELECT id FROM users WHERE mobile_number = :mobile_number LIMIT 1');
    $lookup->execute(['mobile_number' => $mobile]);
    $user = $lookup->fetch();
    if ($user === false) {
        json_response(['success' => true, 'message' => 'If the number is registered, a reset code is available for this local demo.']);
    }

    $userId = (int) $user['id'];
    $limits = $pdo->prepare(
        'SELECT COUNT(*) AS recent_count, MAX(created_at) AS latest_created
         FROM password_reset_otps
         WHERE user_id = :user_id AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR'
    );
    $limits->execute(['user_id' => $userId]);
    $rate = $limits->fetch();
    if ((int) ($rate['recent_count'] ?? 0) >= 5) {
        json_response(['success' => false, 'message' => 'Too many reset requests. Try again later.'], 429);
    }
    if (is_string($rate['latest_created'] ?? null)
        && strtotime($rate['latest_created'] . ' UTC') > time() - 60
    ) {
        json_response(['success' => false, 'message' => 'Wait one minute before requesting another reset code.'], 429);
    }

    try {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            throw new RuntimeException('Password reset code hashing failed.');
        }
        $pdo->prepare('DELETE FROM password_reset_otps WHERE user_id = :user_id AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')
            ->execute(['user_id' => $userId]);
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_otps (user_id, otp_hash, expires_at)
             VALUES (:user_id, :otp_hash, UTC_TIMESTAMP() + INTERVAL 15 MINUTE)'
        );
        $insert->execute(['user_id' => $userId, 'otp_hash' => $codeHash]);
    } catch (Throwable $error) {
        error_log('TanimTrack password reset code creation failed: ' . $error->getMessage());
        json_response(['success' => false, 'message' => 'Unable to create a reset code right now.'], 500);
    }

    json_response([
        'success' => true,
        'message' => 'A reset code is ready for this local development demo.',
        'dev_otp' => $code,
        'expires_in_seconds' => 900,
    ]);
}

if ($action !== 'reset') {
    json_response(['success' => false, 'message' => 'Choose a valid password reset action.'], 422);
}
if (getenv('TANODBUKID_DEV_OTP') !== '1') {
    json_response(['success' => false, 'message' => 'Password reset is unavailable because SMS delivery is not configured.'], 503);
}

$code = is_string($data['otp'] ?? null) ? trim($data['otp']) : '';
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
$errors = [];
if (preg_match('/^\d{6}$/', $code) !== 1) {
    $errors['otp'] = 'Enter the six-digit reset code.';
}
if (strlen($password) < 8 || strlen($password) > 72
    || preg_match('/[A-Z]/', $password) !== 1
    || preg_match('/[a-z]/', $password) !== 1
    || preg_match('/[0-9]/', $password) !== 1
    || preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1
    || preg_match('/\s/u', $password) === 1
) {
    $errors['password'] = 'Password must meet the account password requirements.';
}
if ($errors !== []) {
    json_response(['success' => false, 'message' => 'Please correct the reset information.', 'errors' => $errors], 422);
}

$pdo->beginTransaction();
try {
    $lookup = $pdo->prepare(
        'SELECT requests.id, requests.user_id, requests.otp_hash, requests.expires_at, requests.attempts
         FROM password_reset_otps AS requests
         INNER JOIN users ON users.id = requests.user_id
         WHERE users.mobile_number = :mobile_number
           AND requests.consumed_at IS NULL
           AND requests.attempts < 5
         ORDER BY requests.id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $lookup->execute(['mobile_number' => $mobile]);
    $reset = $lookup->fetch();
    if ($reset === false) {
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired. Request a new code.'], 422);
    }

    $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $reset['expires_at'], new DateTimeZone('UTC'));
    if ($expiresAt === false || $expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        $pdo->prepare('UPDATE password_reset_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $reset['id']]);
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired. Request a new code.'], 422);
    }

    if (!password_verify($code, (string) $reset['otp_hash'])) {
        $attempts = (int) $reset['attempts'] + 1;
        $update = $pdo->prepare(
            'UPDATE password_reset_otps
             SET attempts = :attempts, consumed_at = IF(:attempts_for_lock >= 5, UTC_TIMESTAMP(), consumed_at)
             WHERE id = :id'
        );
        $update->execute(['attempts' => $attempts, 'attempts_for_lock' => $attempts, 'id' => $reset['id']]);
        $pdo->commit();
        json_response(['success' => false, 'message' => 'The code is invalid or has expired.'], 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password reset password hashing failed.');
    }
    $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id')
        ->execute(['password_hash' => $passwordHash, 'user_id' => $reset['user_id']]);
    $pdo->prepare('UPDATE password_reset_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => $reset['id']]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TanimTrack password reset failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to reset the password right now.'], 500);
}

json_response(['success' => true, 'message' => 'Your password has been reset. You can sign in now.']);
