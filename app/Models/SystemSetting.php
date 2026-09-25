<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'value',
        'description',
    ];

    /**
     * Get setting value by key with optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (!$setting || $setting->value === null) {
            return $default;
        }

        $val = $setting->value;

        // Try JSON decoding
        if (is_string($val) && (str_starts_with($val, '[') || str_starts_with($val, '{') || $val === 'true' || $val === 'false' || $val === 'null')) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $val;
    }

    /**
     * Store or update a setting value.
     */
    public static function set(string $key, mixed $value, ?string $description = null): self
    {
        $storedValue = is_array($value) || is_object($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE)
            : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        $attributes = ['value' => $storedValue];
        if ($description !== null) {
            $attributes['description'] = $description;
        }

        return static::updateOrCreate(
            ['key' => $key],
            $attributes
        );
    }

    /**
     * Get list of recipient emails for ticket notifications.
     * Falls back to TECH_SUPPORT_EMAIL environment variable if not configured.
     *
     * @return array<string>
     */
    public static function getTicketEmails(): array
    {
        $setting = static::get('ticket_notification_emails');

        // If never saved in DB, fallback to config/env
        if ($setting === null) {
            $fallback = config('mail.tech_support_email', env('TECH_SUPPORT_EMAIL', 'tech.vmst@gmail.com'));
            $emails = preg_split('/[,\n;]+/', (string) $fallback);
        } elseif (is_array($setting)) {
            $emails = $setting;
        } elseif (is_string($setting) && trim($setting) !== '') {
            $emails = preg_split('/[,\n;]+/', $setting);
        } else {
            $emails = [];
        }

        $cleaned = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $cleaned[] = strtolower($email);
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Check if a specific ticket event notification is enabled.
     */
    public static function isTicketEventEnabled(string $event): bool
    {
        $key = 'ticket_notify_' . $event;
        $val = static::get($key);

        if ($val === null) {
            return true; // Enabled by default
        }

        return in_array($val, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get outgoing SMTP mail configuration from database, falling back to config/env.
     */
    public static function getSmtpConfig(): array
    {
        return [
            'mailer' => static::get('mail_mailer') ?: config('mail.default', 'smtp'),
            'host' => static::get('mail_host') ?: config('mail.mailers.smtp.host', 'smtp.gmail.com'),
            'port' => (int)(static::get('mail_port') ?: config('mail.mailers.smtp.port', 587)),
            'encryption' => static::get('mail_encryption') !== null ? static::get('mail_encryption') : config('mail.mailers.smtp.encryption', 'tls'),
            'username' => static::get('mail_username') ?: config('mail.mailers.smtp.username', ''),
            'password' => static::get('mail_password') ?: config('mail.mailers.smtp.password', ''),
            'from_address' => static::get('mail_from_address') ?: config('mail.from.address', 'noreply@menglish.edu.vn'),
            'from_name' => static::get('mail_from_name') ?: config('mail.from.name', 'MEnglish Support'),
            'has_password' => !empty(static::get('mail_password')) || !empty(config('mail.mailers.smtp.password')),
        ];
    }

    /**
     * Apply database-configured outgoing mail settings to runtime Laravel config.
     */
    public static function applyDynamicMailConfig(): void
    {
        try {
            $smtp = static::getSmtpConfig();

            $configUpdates = [
                'mail.default' => $smtp['mailer'] ?: 'smtp',
                'mail.mailers.smtp.host' => $smtp['host'],
                'mail.mailers.smtp.port' => (int) $smtp['port'],
                'mail.mailers.smtp.encryption' => $smtp['encryption'] ?: null,
            ];

            if (!empty($smtp['username'])) {
                $configUpdates['mail.mailers.smtp.username'] = $smtp['username'];
            }
            if (!empty($smtp['password'])) {
                $configUpdates['mail.mailers.smtp.password'] = $smtp['password'];
            }
            if (!empty($smtp['from_address'])) {
                $configUpdates['mail.from.address'] = $smtp['from_address'];
            }
            if (!empty($smtp['from_name'])) {
                $configUpdates['mail.from.name'] = $smtp['from_name'];
            }

            config($configUpdates);
            \Illuminate\Support\Facades\Mail::purge('smtp');
        } catch (\Throwable $e) {
            // Silently ignore if table not yet created
        }
    }
}
