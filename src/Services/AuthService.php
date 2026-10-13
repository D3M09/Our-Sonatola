<?php
declare(strict_types=1);

final class AuthService
{
    private PDO $pdo;
    private UserRepository $users;
    private Logger $log;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->users = new UserRepository($pdo);
        $this->log = Logger::default();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function register(array $data, Request $req): array
    {
        $errors = Validator::validate($data, [
            'name' => 'required|max:100',
            'email' => 'required|email|max:190',
            'password' => 'required|min:8|max:200',
            'phone' => 'phone|max:20',
        ]);
        if ($errors !== []) {
            throw new AppException('Validation failed.', 422, 'validation_failed');
        }
        $email = strtolower(trim((string) $data['email']));
        if ($this->users->findByEmail($email)) {
            throw AppException::conflict('Email already registered.');
        }
        $unionId = isset($data['union_id']) ? (int) $data['union_id'] : null;
        $userId = $this->users->create([
            'name' => trim((string) $data['name']),
            'email' => $email,
            'phone' => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'password_hash' => Auth::hashPassword((string) $data['password']),
            'union_id' => $unionId,
            'account_status' => 'active', // citizen self-registration active; change to pending if moderation needed
        ]);
        $citizenRole = $this->users->roleIdBySlug('citizen');
        if ($citizenRole) {
            $this->users->assignRole($userId, $citizenRole);
        }
        Audit::log($this->pdo, $userId, 'user.register', 'users', $userId, $req);
        $this->log->info('user registered', ['user_id' => $userId]);
        return ['id' => $userId, 'email' => $email];
    }

    /** @return array<string,mixed> */
    public function login(string $email, string $password, Request $req): array
    {
        $key = 'login:' . $req->ip() . ':' . strtolower(trim($email));
        $max = Config::getInt('LOGIN_MAX_ATTEMPTS', 5);
        $window = Config::getInt('LOGIN_LOCKOUT_MINUTES', 15) * 60;
        if (!RateLimit::hit($key, $max, $window)) {
            throw AppException::rateLimited('Too many login attempts. Try again later.');
        }
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if (!$user || !Auth::verifyPassword($password, $user['password_hash'] ?? '')) {
            throw AppException::unauthorized('Invalid email or password.');
        }
        if (($user['account_status'] ?? '') !== 'active') {
            throw AppException::forbidden('Account is ' . ($user['account_status'] ?? 'inactive') . '. Contact administration.');
        }
        if (Auth::needsRehash($user['password_hash'])) {
            $this->users->updatePassword((int) $user['id'], Auth::hashPassword($password));
        }
        RateLimit::clear($key);
        $this->users->touchLogin((int) $user['id']);
        Audit::log($this->pdo, (int) $user['id'], 'user.login', 'users', $user['id'], $req);
        return $user;
    }

    public function issueApiToken(int $userId, int $ttlDays = 30): string
    {
        return Auth::issueToken($this->pdo, $userId, Auth::TYPE_API, $ttlDays * 86400);
    }

    /**
     * Whether password-reset issuance is enabled.
     * Disabled by default (safe when SMTP is not configured).
     * Enable via env PASSWORD_RESET_ENABLED=true AND settings key
     * auth.password_reset_enabled=1 (both required), with SMTP documented
     * in docs/api-v1.md. When disabled no token is issued; callers still
     * return a generic message to avoid user enumeration.
     */
    public function isPasswordResetEnabled(): bool
    {
        if (!Config::getBool('PASSWORD_RESET_ENABLED', false)) {
            return false;
        }
        try {
            $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=:k LIMIT 1');
            $st->execute([':k' => 'auth.password_reset_enabled']);
            $r = $st->fetch();
            return $r && (string) $r['setting_value'] === '1';
        } catch (Throwable) {
            return false;
        }
    }

    public function requestPasswordReset(string $email, Request $req): void
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        // Always return success to avoid user enumeration; log only internally
        if (!$user) {
            return;
        }
        if (!$this->isPasswordResetEnabled()) {
            // Safely disabled: record the request for audit without issuing a token.
            Audit::log($this->pdo, (int) $user['id'], 'user.password_reset_requested_disabled', 'users', $user['id'], $req);
            return;
        }
        $raw = Auth::issueToken($this->pdo, (int) $user['id'], Auth::TYPE_RESET, 3600);
        // Delivery requires configured SMTP (see docs/api-v1.md).
        // The raw token must be emailed immediately and never logged, stored, or echoed.
        // TODO: dispatch via mailer queue here once SMTP is configured.
        Audit::log($this->pdo, (int) $user['id'], 'user.password_reset_requested', 'users', $user['id'], $req);
        // Store raw nowhere; caller (controller in dev) must NOT echo it in production.
        unset($raw);
    }

    public function resetPassword(string $rawToken, string $newPassword, Request $req): void
    {
        if (strlen($newPassword) < 8) {
            throw AppException::unprocessable('Password must be at least 8 characters.');
        }
        $row = Auth::consumeToken($this->pdo, $rawToken, Auth::TYPE_RESET);
        if (!$row) {
            throw AppException::badRequest('Invalid or expired reset token.');
        }
        $this->users->updatePassword((int) $row['user_id'], Auth::hashPassword($newPassword));
        // Revoke all API sessions so stolen sessions die with the password change.
        Auth::revokeUserApiSessions($this->pdo, (int) $row['user_id']);
        Audit::log($this->pdo, (int) $row['user_id'], 'user.password_reset', 'users', $row['user_id'], $req);
    }
}
