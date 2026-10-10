<?php
declare(strict_types=1);
namespace Flex\Econt;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Http\RequestInput;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class PublicController
{
    public function __construct(private ResponseFactoryInterface $responses, private RequestInput $input) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $settings = new EcontSettings();
        $values = $settings->all();
        if (!($values['enabled'] ?? false)) {
            return $this->responses->json(['error' => ['message' => 'Еконт доставката не е активна.']], 503, ['Cache-Control' => 'no-store']);
        }
        $client = new EcontClient($settings);
        $path = $request->getUri()->getPath();
        try {
            if (str_contains($path, '/config')) {
                $mode = ($values['mode'] ?? '') === EcontSettings::MODE_LIVE ? EcontSettings::MODE_LIVE : EcontSettings::MODE_TEST;

                return $this->responses->json([
                    'enabled' => true,
                    'mode' => $mode,
                    'locator_url' => $mode === EcontSettings::MODE_LIVE
                        ? 'https://officelocator.econt.com/'
                        : 'https://staging.officelocator.econt.com/',
                ], 200, ['Cache-Control' => 'no-store']);
            }
            if (str_contains($path, '/offices')) {
                $query = $request->getQueryParams();
                $payload = ['countryCode' => 'BGR'];
                if (isset($query['city_id']) && is_numeric($query['city_id'])) {
                    $payload['cityID'] = (int) $query['city_id'];
                }
                $offices = $client->offices($payload);
                $records = array_map(static function (array $office): array {
                    return [
                        'code' => (string) ($office['code'] ?? $office['officeCode'] ?? ''),
                        'name' => (string) ($office['name'] ?? $office['nameEn'] ?? ''),
                        'address' => (string) ($office['address']['fullAddress'] ?? $office['address']['street'] ?? ''),
                        'city' => (string) ($office['address']['city']['name'] ?? $office['city']['name'] ?? ''),
                        'is_aps' => (bool) ($office['isAPS'] ?? false),
                    ];
                }, $offices);

                return $this->responses->json([
                    'mode' => $values['mode'],
                    'records' => array_values(array_filter($records, static fn (array $row): bool => $row['code'] !== '')),
                ], 200, ['Cache-Control' => 'no-store']);
            }
            if (str_contains($path, '/calculate')) {
                $body = $this->input->all($request);
                $method = is_string($body['shipping'] ?? null) ? $body['shipping'] : ShippingService::METHOD_OFFICE;
                if (!in_array($method, [ShippingService::METHOD_OFFICE, ShippingService::METHOD_ADDRESS], true)) {
                    $method = ShippingService::METHOD_OFFICE;
                }
                $meta = is_array($body['shipping_meta'] ?? null) ? $body['shipping_meta'] : [];
                $service = new ShippingService($settings, $client);
                $commerce = (new \Flex\Commerce\CatalogStore())->settings();
                $quote = $service->adjustQuote([
                    'shipping' => $method, 'currency' => $commerce['currency'],
                    'subtotal_minor' => 0, 'shipping_meta' => $meta,
                ], ['settings' => array_replace($commerce, ['shipping_mode' => 'econt']), 'selection' => ['shipping_meta' => $meta]]);
                return $this->responses->json([
                    'amount_minor' => $quote['shipping_minor'],
                    'currency' => $commerce['currency'],
                    'calculated' => $quote['shipping_meta']['calculated'] ?? false,
                    'price_source' => $quote['shipping_meta']['price_source'] ?? null,
                    'mode' => $values['mode'],
                ], 200, ['Cache-Control' => 'no-store']);
            }

            return $this->responses->json(['error' => ['message' => 'Неизвестен endpoint.']], 404, ['Cache-Control' => 'no-store']);
        } catch (\Flex\Commerce\CommerceException $error) {
            return $this->responses->json(['error' => ['message' => $error->getMessage()]], $error->getCode(), ['Cache-Control' => 'no-store']);
        } catch (EcontException $error) {
            return $this->responses->json(['error' => ['message' => $error->getMessage()]], $error->status(), ['Cache-Control' => 'no-store']);
        }
    }
}
