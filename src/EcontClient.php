<?php
declare(strict_types=1);
namespace Flex\Econt;

final class EcontClient
{
    public const TEST_BASE = 'https://demo.econt.com/ee/services';
    public const LIVE_BASE = 'https://ee.econt.com/services';

    public function __construct(private EcontSettings $settings = new EcontSettings(), private ?\GuzzleHttp\ClientInterface $http = null) {}

    public function baseUrl(string $mode): string
    {
        return $mode === EcontSettings::MODE_LIVE ? self::LIVE_BASE : self::TEST_BASE;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function request(string $path, array $payload = [], ?array $credentials = null): array
    {
        $credentials ??= $this->settings->credentials();
        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';
        $mode = $credentials['mode'] ?? EcontSettings::MODE_TEST;
        if ($username === '' || $password === '') {
            throw new EcontException('Липсват потребител и парола за текущия режим на Еконт.', 422);
        }
        $url = rtrim($this->baseUrl((string) $mode), '/') . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new EcontException('Невалидна заявка към Еконт.', 500);
        }
        try {
            $response = ($this->http ?? new \GuzzleHttp\Client())->request('POST', $url, [
                'auth' => [$username, $password], 'body' => $body,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'timeout' => 25, 'connect_timeout' => 10, 'allow_redirects' => false, 'http_errors' => false,
            ]);
        } catch (\Throwable) {
            throw new EcontException('Връзката с Еконт API не бе успешна.', 502);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new EcontException('Еконт API отказа заявката.', 502);
        }
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new EcontException('Невалиден отговор от Еконт API.', 502);
        }
        if (isset($decoded['type']) && is_string($decoded['type']) && str_contains(strtolower($decoded['type']), 'error')) {
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Грешка от Еконт API.';
            throw new EcontException($message, 502);
        }
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : 'Грешка от Еконт API.';
            throw new EcontException($message, 502);
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function testConnection(): array
    {
        $credentials = $this->settings->credentials();
        $result = $this->request('Nomenclatures/NomenclaturesService.getCountries.json', [], $credentials);

        return [
            'ok' => true,
            'mode' => $credentials['mode'],
            'base_url' => $this->baseUrl((string) $credentials['mode']),
            'countries' => is_array($result['countries'] ?? null) ? count($result['countries']) : 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function offices(array $payload = []): array
    {
        $result = $this->request('Nomenclatures/NomenclaturesService.getOffices.json', array_replace([
            'countryCode' => 'BGR',
        ], $payload));
        $offices = $result['offices'] ?? [];

        return is_array($offices) ? array_values(array_filter($offices, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $label
     * @return array<string, mixed>
     */
    public function calculate(array $label): array
    {


        return $this->request('Shipments/LabelService.createLabel.json', ['label' => $label, 'mode' => 'calculate']);
    }

    /**
     * @param array<string, mixed> $label
     * @return array<string, mixed>
     */
    public function createLabel(array $label): array
    {


        return $this->request('Shipments/LabelService.createLabel.json', ['label' => $label, 'mode' => 'create']);
    }
}
