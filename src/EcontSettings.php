<?php
declare(strict_types=1);
namespace Flex\Econt;

use Illuminate\Database\{Connection, Eloquent\Model, Schema\Blueprint};

final class EcontSettings
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public const DEFAULTS = [
        'enabled' => false,
        'mode' => self::MODE_TEST,
        'office_enabled' => true,
        'address_enabled' => true,
        'auto_label' => false,
        'test_username' => '',
        'test_password' => '',
        'live_username' => '',
        'live_password' => '',
        'sender_name' => '',
        'sender_phone' => '',
        'sender_office_code' => '',
        'sender_city' => '',
        'sender_postcode' => '',
        'sender_address' => '',
        'default_weight' => 1.0,
    ];

    /** Official Econt demo credentials + sample sender for local/test setups. */
    public const DEMO = [
        'enabled' => true,
        'mode' => self::MODE_TEST,
        'office_enabled' => true,
        'address_enabled' => true,
        'auto_label' => false,
        'test_username' => 'iasp-dev',
        'test_password' => '1Asp-dev',
        'sender_name' => 'Demo магазин',
        'sender_phone' => '+359888000000',
        'sender_office_code' => '',
        'sender_city' => 'София',
        'sender_postcode' => '1000',
        'sender_address' => 'ул. Витоша 1',
        'default_weight' => 1.0,
    ];

    public function __construct(private ?Connection $db = null)
    {
        $this->db = $db ?? Model::resolveConnection();
    }

    public function install(): void
    {
        if (!$this->db->getSchemaBuilder()->hasTable('flex_commerce_orders')) {
            throw new \RuntimeException('Първо инсталирайте Flex Commerce.');
        }
        if (!$this->db->getSchemaBuilder()->hasTable('flex_econt_settings')) {
            $this->db->getSchemaBuilder()->create('flex_econt_settings', static function (Blueprint $t): void {
                $t->integer('id')->primary();
                $t->text('values');
            });
            $this->db->table('flex_econt_settings')->insert([
                'id' => 1,
                'values' => json_encode(self::DEFAULTS, JSON_UNESCAPED_UNICODE),
            ]);
        }
        if (!$this->db->getSchemaBuilder()->hasTable('flex_econt_shipments')) {
            $this->db->getSchemaBuilder()->create('flex_econt_shipments', static function (Blueprint $t): void {
                $t->increments('id');
                $t->unsignedInteger('order_id')->unique();
                $t->string('mode', 8);
                $t->string('shipment_number', 64)->nullable();
                $t->string('label_url', 500)->nullable();
                $t->string('status', 32)->default('pending');
                $t->text('payload')->nullable();
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }
    }

    /** Fill official demo credentials/sender once when the store is still empty. */
    public function ensureDemoDefaults(): void
    {
        // Retained for compatibility; reading settings must never enable a demo account.
    }

    /**
     * Set Commerce shipping_mode=econt when the key is still absent (legacy stores / first install).
     * Does not override an explicit admin choice of fixed|econt.
     */
    public function ensureCommerceEcontMode(): void
    {
        try {
            if (!$this->db->getSchemaBuilder()->hasTable('flex_commerce_settings')) {
                return;
            }
            $raw = $this->db->table('flex_commerce_settings')->where('id', 1)->value('values');
            $decoded = json_decode(is_string($raw) ? $raw : '{}', true) ?: [];
            if (array_key_exists('shipping_mode', $decoded)) {
                return;
            }
            $catalog = new \Flex\Commerce\CatalogStore($this->db);
            $settings = $catalog->settings();
            $catalog->saveSettings(array_replace($settings, [
                'shipping_mode' => 'econt',
                'version' => (int) ($settings['version'] ?? 1),
            ]));
        } catch (\Throwable) {
            // Commerce may be unavailable during early install; mode can be set manually.
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $row = $this->db->table('flex_econt_settings')->where('id', 1)->value('values');

        return array_replace(self::DEFAULTS, json_decode(is_string($row) ? $row : '{}', true) ?: []);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(array $input): array
    {
        $values = $this->all();
        foreach (['enabled', 'office_enabled', 'address_enabled', 'auto_label'] as $key) {
            if (array_key_exists($key, $input)) {
                if (!is_bool($input[$key])) {
                    throw new \InvalidArgumentException('Невалидна настройка: ' . $key, 422);
                }
                $values[$key] = $input[$key];
            }
        }
        if (array_key_exists('mode', $input)) {
            if (!in_array($input['mode'], [self::MODE_TEST, self::MODE_LIVE], true)) {
                throw new \InvalidArgumentException('Невалиден режим.', 422);
            }
            $values['mode'] = $input['mode'];
        }
        foreach (['test_username', 'test_password', 'live_username', 'live_password', 'sender_name', 'sender_phone', 'sender_office_code', 'sender_city', 'sender_postcode', 'sender_address'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_string($input[$key]) || mb_strlen($input[$key]) > 190) {
                throw new \InvalidArgumentException('Невалидна стойност: ' . $key, 422);
            }
            // Keep previously stored secrets when the admin form leaves the password blank.
            if (str_ends_with($key, '_password') && trim($input[$key]) === '') {
                continue;
            }
            $values[$key] = trim($input[$key]);
        }
        if (array_key_exists('default_weight', $input)) {
            $weight = is_numeric($input['default_weight']) ? (float) $input['default_weight'] : -1;
            if (!is_finite($weight) || $weight < 0.01 || $weight > 1000) {
                throw new \InvalidArgumentException('Теглото трябва да е между 0.01 и 1000 кг.', 422);
            }
            $values['default_weight'] = round($weight, 3);
        }
        if ($values['enabled'] && $values['mode'] === self::MODE_LIVE) {
            foreach (['live_username', 'live_password', 'sender_name', 'sender_phone'] as $required) {
                if (trim((string) $values[$required]) === '') throw new \InvalidArgumentException('Попълнете live достъпа и данните на подателя.', 422);
            }
            if ($values['sender_office_code'] === '' && ($values['sender_city'] === '' || $values['sender_postcode'] === '' || $values['sender_address'] === '')) {
                throw new \InvalidArgumentException('Попълнете офис или пълен адрес на подателя.', 422);
            }
        }
        $this->db->table('flex_econt_settings')->where('id', 1)->update(['values' => json_encode($values, JSON_UNESCAPED_UNICODE)]);

        return $this->publicSettings($values);
    }

    /** Hide secrets in API responses (keep non-empty flags). @param array<string, mixed>|null $values */
    public function publicSettings(?array $values = null): array
    {
        $values ??= $this->all();
        foreach (['test_password', 'live_password'] as $secret) {
            $values[$secret . '_set'] = trim((string) ($values[$secret] ?? '')) !== '';
            $values[$secret] = '';
        }

        return $values;
    }

    /** @param array<string, mixed>|null $values */
    public function credentials(?array $values = null): array
    {
        $values ??= $this->all();
        $mode = $values['mode'] === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_TEST;
        $userKey = $mode === self::MODE_LIVE ? 'live_username' : 'test_username';
        $passKey = $mode === self::MODE_LIVE ? 'live_password' : 'test_password';

        return [
            'mode' => $mode,
            'username' => trim((string) ($values[$userKey] ?? '')),
            'password' => (string) ($values[$passKey] ?? ''),
        ];
    }
}
