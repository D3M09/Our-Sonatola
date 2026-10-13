<?php
declare(strict_types=1);

final class SettingsRepository extends BaseRepository
{
    public function get(string $key, string $default = ''): string
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=:k LIMIT 1');
        $st->execute([':k' => $key]);
        $r = $st->fetch();
        return $r ? (string) $r['setting_value'] : $default;
    }

    public function set(string $key, string $value): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k,:v)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
        );
        $st->execute([':k' => $key, ':v' => $value]);
    }

    public function onlineApplicationsEnabled(): bool
    {
        return $this->get('applications.online_enabled', '0') === '1';
    }
}
