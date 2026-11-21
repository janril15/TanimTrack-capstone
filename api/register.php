<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

require_method('POST');
$data = read_json_request();

$firstName = trim(is_string($data['first_name'] ?? null) ? $data['first_name'] : '');
$lastName = trim(is_string($data['last_name'] ?? null) ? $data['last_name'] : '');
$fullName = trim($firstName . ' ' . $lastName);
$mobile = normalize_mobile_number(is_string($data['mobile_number'] ?? null) ? $data['mobile_number'] : '');
$email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';

$errors = [];
if ($firstName === '' || strlen($firstName) > 75 || preg_match('/[\x00-\x1F\x7F]/', $firstName) === 1) {
    $errors['first_name'] = 'Enter a valid first name (up to 75 characters).';
}
if ($lastName === '' || strlen($lastName) > 75 || preg_match('/[\x00-\x1F\x7F]/', $lastName) === 1) {
    $errors['last_name'] = 'Enter a valid last name (up to 75 characters).';
}
if ($fullName === '' || strlen($fullName) > 150) {
    $errors['full_name'] = 'Your full name must not exceed 150 characters.';
}
if ($mobile === null) {
    $errors['mobile_number'] = 'Enter a valid Philippine mobile number, such as 09123456789.';
}
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Enter a valid email address.';
}
if (strlen($password) < 8 || strlen($password) > 72
    || preg_match('/[A-Z]/', $password) !== 1
    || preg_match('/[a-z]/', $password) !== 1
    || preg_match('/[0-9]/', $password) !== 1
    || preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1
    || preg_match('/\s/u', $password) === 1
) {
    $errors['password'] = 'Password must be at least 8 characters, no more than 72 bytes, include uppercase and lowercase letters, a number and a special character, and contain no spaces.';
}
if ($errors !== []) {
    json_response(['success' => false, 'message' => 'Please correct the highlighted information.', 'errors' => $errors], 422);
}

if (!unisms_sms_configured()) {
    json_response([
        'success' => false,
        'message' => 'SMS verification is not configured. Add your UniSMS API key and sender ID to the private environment file.',
    ], 503);
}

$pdo = pdo_connection();
$duplicate = $pdo->prepare('SELECT email, mobile_number FROM users WHERE email = :email OR mobile_number = :mobile_number LIMIT 1');
$duplicate->execute(['email' => $email, 'mobile_number' => $mobile]);
$existing = $duplicate->fetch();
if ($existing !== false) {
    $errors = [];
    if (strcasecmp((string) $existing['email'], $email) === 0) {
        $errors['email'] = 'An account with this email address already exists.';
    }
    if ((string) $existing['mobile_number'] === $mobile) {
        $errors['mobile_number'] = 'An account with this mobile number already exists.';
    }
    json_response(['success' => false, 'message' => 'This account information is already registered.', 'errors' => $errors], 409);
}

$limits = $pdo->prepare(
    'SELECT COUNT(*) AS recent_count, MAX(created_at) AS latest_created
     FROM registration_otps
     WHERE mobile_number = :mobile_number AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR'
);
$limits->execute(['mobile_number' => $mobile]);
$rate = $limits->fetch();
if ((int) ($rate['recent_count'] ?? 0) >= 5) {
    json_response(['success' => false, 'message' => 'Too many verification requests. Try again later.'], 429);
}
if (is_string($rate['latest_created'] ?? null)
    && strtotime($rate['latest_created'] . ' UTC') > time() - 60
) {
    json_response(['success' => false, 'message' => 'Wait one minute before requesting another verification code.'], 429);
}

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    if ($passwordHash === false || $codeHash === false) {
        throw new RuntimeException('Registration data hashing failed.');
    }

    $pdo->beginTransaction();
    $pdo->prepare(
        'UPDATE registration_otps
         SET consumed_at = UTC_TIMESTAMP()
         WHERE mobile_number = :mobile_number AND consumed_at IS NULL'
    )->execute(['mobile_number' => $mobile]);
    $pdo->prepare(
        'DELETE FROM registration_otps
         WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
    )->execute();
    $insert = $pdo->prepare(
        'INSERT INTO registration_otps
            (full_name, mobile_number, email, password_hash, otp_hash, expires_at)
         VALUES
            (:full_name, :mobile_number, :email, :password_hash, :otp_hash, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)'
    );
    $insert->execute([
        'full_name' => $fullName,
        'mobile_number' => $mobile,
        'email' => $email,
        'password_hash' => $passwordHash,
        'otp_hash' => $codeHash,
    ]);
    $otpId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TanimTrack registration verification setup failed: ' . $error->getMessage());
    json_response(['success' => false, 'message' => 'Unable to start registration right now. Please try again.'], 500);
}

try {
    send_unisms_otp($mobile, $code, 'registration', 10);
} catch (Throwable $error) {
    $pdo->prepare('UPDATE registration_otps SET consumed_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => $otpId]);
    error_log('TanimTrack registration SMS delivery failed: ' . $error->getMessage());
    json_response([
        'success' => false,
        'message' => $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Unable to send the verification code right now. Try again later.',
    ], 502);
}

json_response([
    'success' => true,
    'message' => 'A verification code SMS request was accepted.',
    'mobile_number' => $mobile,
    'expires_in_seconds' => 600,
]);
