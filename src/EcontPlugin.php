<?php
declare(strict_types=1);
namespace Flex\Econt;

use Flex\Extension\V1\{BootablePluginInterface, PluginContext, PluginInterface, UpdatablePluginInterface};

final class EcontPlugin implements PluginInterface, BootablePluginInterface, UpdatablePluginInterface
{
    public function install(PluginContext $context): void
    {
        (new EcontSettings())->install();
    }

    public function activate(PluginContext $context): void
    {
        (new EcontSettings())->install();
    }

    public function update(PluginContext $context, string $fromVersion): void
    {
        $this->install($context);
    }

    public function deactivate(PluginContext $context): void {}

    public function boot(PluginContext $context): void
    {
        $context->admin?->page('settings', 'Еконт', 'assets/admin.js', false, ['group' => 'commerce', 'icon' => 'shopping-bag', 'section' => 'settings', 'order' => 2]);
        $context->routes?->admin(['GET', 'PUT'], '/settings', AdminController::class, 'settings');
        $context->routes?->admin('POST', '/test-connection', AdminController::class, 'test-connection', [\Flex\Session\CsrfMiddleware::class]);
        $context->routes?->get('/config', PublicController::class, 'config');
        $context->routes?->get('/offices', PublicController::class, 'offices');
        $context->routes?->post('/calculate', PublicController::class, 'calculate', [\Flex\Session\CsrfMiddleware::class]);

        $context->api->addFilter('commerce.checkout.assets', static function (mixed $assets): mixed {
            $list = is_array($assets) ? $assets : [];
            $list[] = ['type' => 'style', 'href' => '/extensions/flex/econt/assets/assets/econt.css?v=4'];
            $list[] = ['type' => 'script', 'href' => '/extensions/flex/econt/assets/assets/checkout.js?v=8'];

            return $list;
        });
        $shipping = new ShippingService();
        $context->api->addFilter('commerce.shipping.methods', static function (mixed $methods, array $context) use ($shipping): mixed {
            return $shipping->methods(is_array($methods) ? $methods : [], $context);
        });
        $context->api->addFilter('commerce.checkout.quote', static function (mixed $quote, array $context) use ($shipping): mixed {
            return is_array($quote) ? $shipping->adjustQuote($quote, $context) : $quote;
        });
        $context->api->addFilter('commerce.order.validate', static function (mixed $customer, array $context) use ($shipping): mixed {
            return is_array($customer) ? $shipping->validateCustomer($customer, $context) : $customer;
        });
        $context->api->addAction('commerce.order.placed', static function (array $context): void {
            $settings = new EcontSettings();
            $values = $settings->all();
            if (!($values['enabled'] ?? false) || !($values['auto_label'] ?? false)) {
                return;
            }
            $order = is_array($context['order'] ?? null) ? $context['order'] : null;
            if ($order === null) {
                return;
            }
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $method = is_string($customer['shipping'] ?? null) ? $customer['shipping'] : '';
            if (!in_array($method, [ShippingService::METHOD_OFFICE, ShippingService::METHOD_ADDRESS], true)) {
                return;
            }
            $db = \Illuminate\Database\Eloquent\Model::resolveConnection();
            $now = gmdate('Y-m-d H:i:s');
            if (!$db->table('flex_econt_shipments')->insertOrIgnore([
                'order_id' => (int) $order['id'], 'mode' => $values['mode'], 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ])) {
                return;
            }
            try {
                $client = new EcontClient($settings);
                $service = new ShippingService($settings, $client);
                $meta = is_array($customer['shipping_meta'] ?? null) ? $customer['shipping_meta'] : [];
                $meta['city'] = $customer['city'] ?? ($meta['city'] ?? '');
                $meta['postcode'] = $customer['postcode'] ?? ($meta['postcode'] ?? '');
                $meta['address'] = $customer['address'] ?? ($meta['address'] ?? '');
                $label = $service->buildLabel($method, $meta, ['currency' => $order['currency'] ?? 'EUR', 'total_minor' => $order['total_minor']], $values);
                $label['orderNumber'] = $order['number'];
                $label['receiverClient'] = [
                    'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
                    'phones' => [(string) ($customer['phone'] ?? '')],
                ];
                $result = $client->createLabel($label);
                $number = (string) ($result['label']['shipmentNumber'] ?? $result['shipmentNumber'] ?? '');
                $db = \Illuminate\Database\Eloquent\Model::resolveConnection();
                $db->table('flex_econt_shipments')->updateOrInsert(
                    ['order_id' => (int) $order['id']],
                    [
                        'mode' => (string) $values['mode'],
                        'shipment_number' => $number !== '' ? $number : null,
                        'label_url' => is_string($result['label']['pdfURL'] ?? null) ? $result['label']['pdfURL'] : null,
                        'status' => $number !== '' ? 'created' : 'error',
                        'payload' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'error' => null,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ],
                );
            } catch (\Throwable $error) {
                try {
                    $db = \Illuminate\Database\Eloquent\Model::resolveConnection();
                    $db->table('flex_econt_shipments')->updateOrInsert(
                        ['order_id' => (int) $order['id']],
                        [
                            'mode' => (string) $values['mode'],
                            'status' => 'error',
                            'error' => mb_substr($error->getMessage(), 0, 1000),
                            'updated_at' => gmdate('Y-m-d H:i:s'),
                            'created_at' => gmdate('Y-m-d H:i:s'),
                        ],
                    );
                } catch (\Throwable) {
                    // Order must remain placed even if shipment logging fails.
                }
            }
        });
    }
}
