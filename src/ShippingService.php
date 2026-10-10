<?php
declare(strict_types=1);
namespace Flex\Econt;

use Flex\Commerce\CatalogStore;

final class ShippingService
{
    public const METHOD_OFFICE = 'econt_office';
    public const METHOD_ADDRESS = 'econt_address';

    public function __construct(
        private EcontSettings $settings = new EcontSettings(),
        private EcontClient $client = new EcontClient(),
    ) {}

    /** @param array<string, mixed> $context @return list<array{id: string, label: string, type: string}> */
    public function methods(array $methods, array $context = []): array
    {
        $commerce = is_array($context['settings'] ?? null) ? $context['settings'] : (new CatalogStore())->settings();
        if (($commerce['shipping_mode'] ?? 'fixed') !== 'econt') {
            return is_array($methods) ? $methods : [];
        }
        $values = $this->settings->all();
        if (!($values['enabled'] ?? false)) {
            return is_array($methods) ? $methods : [];
        }
        $credentials = $this->settings->credentials($values);
        if ($credentials['username'] === '' || $credentials['password'] === '') {
            return is_array($methods) ? $methods : [];
        }
        $list = is_array($methods) ? $methods : [];
        if ($values['office_enabled'] ?? true) {
            $list[] = ['id' => self::METHOD_OFFICE, 'label' => 'Еконт — до офис / автомат', 'type' => 'office'];
        }
        if ($values['address_enabled'] ?? true) {
            $list[] = ['id' => self::METHOD_ADDRESS, 'label' => 'Еконт — до адрес', 'type' => 'address'];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function adjustQuote(array $quote, array $context = []): array
    {
        $shipping = is_string($quote['shipping'] ?? null) ? $quote['shipping'] : '';
        if (!in_array($shipping, [self::METHOD_OFFICE, self::METHOD_ADDRESS], true)) {
            return $quote;
        }
        $commerce = is_array($context['settings'] ?? null) ? $context['settings'] : (new CatalogStore())->settings();
        if (($commerce['shipping_mode'] ?? 'fixed') !== 'econt') {
            return $quote;
        }
        $values = $this->settings->all();
        if (!($values['enabled'] ?? false)) {
            return $quote;
        }
        $selection = is_array($context['selection'] ?? null) ? $context['selection'] : [];
        $meta = is_array($selection['shipping_meta'] ?? null) ? $selection['shipping_meta'] : (is_array($quote['shipping_meta'] ?? null) ? $quote['shipping_meta'] : []);
        $amount = null;
        $source = 'demo';
        $ready = $shipping === self::METHOD_OFFICE ? !empty($meta['office_code']) : (!empty($meta['city']) && !empty($meta['postcode']) && !empty($meta['address']));
        if (!$ready && $values['mode'] === EcontSettings::MODE_LIVE) {
            $quote['shipping_minor'] = 0;
            $quote['shipping_meta'] = array_replace($meta, ['calculated' => false, 'provider' => 'econt']);
            return $quote;
        }
        try {
            $label = $this->buildLabel($shipping, $meta, $quote, $values);
            $result = $this->client->calculate($label);
            $currency = $result['label']['currency'] ?? $result['currency'] ?? null;
            if ($currency !== ($quote['currency'] ?? 'EUR')) {
                throw new EcontException('Валутата на куриерската цена не съвпада с магазина.', 503);
            }
            $amount = $this->extractAmountMinor($result);
            if ($amount !== null) {
                $source = 'econt';
            }
        } catch (\Throwable) {
            $amount = null;
        }
        if ($amount === null) {
            if ($values['mode'] === EcontSettings::MODE_LIVE) {
                throw new \Flex\Commerce\CommerceException('Цената за доставка с Еконт не може да бъде потвърдена. Опитайте отново.', 503);
            }
            // Demo/test path mirrors a real quote when the API is unavailable or incomplete.
            $amount = $this->demoAmountMinor($shipping, $meta, $values);
            $source = 'demo';
        }
        if (($commerce['free_shipping_minor'] ?? 0) > 0 && (int) ($quote['subtotal_minor'] ?? 0) >= $commerce['free_shipping_minor']) {
            $amount = 0;
        }
        $quote['shipping_minor'] = $amount;
        $quote['total_minor'] = (int) ($quote['subtotal_minor'] ?? 0) + $amount;
        $quote['shipping_meta'] = array_replace($meta, [
            'provider' => 'econt',
            'mode' => $values['mode'],
            'calculated' => true,
            'price_source' => $source,
        ]);

        return $quote;
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function validateCustomer(array $customer, array $context = []): array
    {
        if (isset($context['quote']['shipping_meta']['calculated']) && !$context['quote']['shipping_meta']['calculated']) {
            throw new \Flex\Commerce\CommerceException('Попълнете данните за доставка и изчакайте потвърдена цена.', 422);
        }
        $method = is_array($context['method'] ?? null) ? $context['method'] : [];
        $id = is_string($method['id'] ?? null) ? $method['id'] : '';
        if ($id === self::METHOD_OFFICE) {
            $meta = is_array($customer['shipping_meta'] ?? null) ? $customer['shipping_meta'] : [];
            $code = is_string($meta['office_code'] ?? null) ? trim($meta['office_code']) : '';
            if ($code === '') {
                throw new \Flex\Commerce\CommerceException('Изберете офис на Еконт.', 422);
            }
            $meta['office_code'] = $code;
            $meta['provider'] = 'econt';
            $customer['shipping_meta'] = $meta;
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function buildLabel(string $method, array $meta, array $quote, array $values): array
    {
        if (($values['mode'] ?? 'test') === EcontSettings::MODE_LIVE) {
            foreach (['sender_name', 'sender_phone'] as $field) {
                if (trim((string) ($values[$field] ?? '')) === '') throw new EcontException('Липсват данни на подателя.', 422);
            }
            if (empty($values['sender_office_code']) && (empty($values['sender_city']) || empty($values['sender_postcode']) || empty($values['sender_address']))) {
                throw new EcontException('Липсва пълен адрес на подателя.', 422);
            }
        }
        $weight = (float) ($values['default_weight'] ?? 1);
        $sender = [
            'name' => (string) ($values['sender_name'] ?: 'Demo магазин'),
            'phones' => [(string) ($values['sender_phone'] ?: '+359888000000')],
        ];
        $senderSide = [];
        if (trim((string) ($values['sender_office_code'] ?? '')) !== '') {
            $senderSide['senderOfficeCode'] = trim((string) $values['sender_office_code']);
        } else {
            $senderSide['senderAddress'] = [
                'city' => [
                    'country' => ['code3' => 'BGR'],
                    'name' => (string) ($values['sender_city'] ?: 'София'),
                    'postCode' => (string) ($values['sender_postcode'] ?: '1000'),
                ],
                'fullAddress' => (string) ($values['sender_address'] ?: 'ул. Витоша 1'),
            ];
        }
        $receiverCity = [
            'country' => ['code3' => 'BGR'],
            'name' => is_string($meta['city'] ?? null) && $meta['city'] !== '' ? $meta['city'] : 'София',
            'postCode' => is_string($meta['postcode'] ?? null) && $meta['postcode'] !== '' ? $meta['postcode'] : '1000',
        ];
        $label = [
            'senderClient' => $sender,
            'receiverClient' => [
                'name' => 'Receiver',
                'phones' => ['+359888111111'],
            ],
            'packCount' => 1,
            'shipmentType' => 'pack',
            'weight' => max(0.01, $weight),
        ] + $senderSide;
        if ($method === self::METHOD_OFFICE) {
            $code = is_string($meta['office_code'] ?? null) ? trim($meta['office_code']) : '';
            if ($code === '') {
                $label['receiverAddress'] = ['city' => $receiverCity];
            } else {
                $label['receiverOfficeCode'] = $code;
            }
        } else {
            $label['receiverAddress'] = [
                'city' => $receiverCity,
                'fullAddress' => is_string($meta['address'] ?? null) && $meta['address'] !== '' ? $meta['address'] : 'ул. Пример 1',
            ];
        }

        if (isset($quote['total_minor'])) {
            $label['services'] = ['cdAmount' => (int) $quote['total_minor'] / 100, 'cdCurrency' => (string) ($quote['currency'] ?? 'EUR')];
            // The checkout total already includes shipping; the sender pays the courier charge.
            $label['paymentReceiverAmountIsPercent'] = true;
            $label['paymentReceiverAmount'] = 0;
        }
        return $label;
    }

    /**
     * Deterministic demo quote shaped like a real Econt calculation.
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $values
     */
    public function demoAmountMinor(string $method, array $meta, array $values): int
    {
        $weight = max(0.01, (float) ($values['default_weight'] ?? 1));
        $base = $method === self::METHOD_OFFICE ? 499 : 699;
        $weightFee = (int) round(max(0, $weight - 1) * 120);
        $code = is_string($meta['office_code'] ?? null) ? trim($meta['office_code']) : '';
        // Stable variation from office code so changing office visibly updates the price.
        $variation = $code !== '' ? (abs(crc32($code)) % 250) : 150;

        return $base + $weightFee + $variation;
    }

    /** @param array<string, mixed> $result */
    public function extractPublicAmountMinor(array $result): ?int
    {
        return $this->extractAmountMinor($result);
    }

    /** @param array<string, mixed> $result */
    private function extractAmountMinor(array $result): ?int
    {
        $candidates = [
            $result['label']['totalPrice'] ?? null,
            $result['label']['totalPriceWithVAT'] ?? null,
            $result['label']['pricingDetails']['totalPrice'] ?? null,
            $result['label']['pricingDetails']['price'] ?? null,
            $result['totalPrice'] ?? null,
        ];
        foreach ($candidates as $amount) {
            if (is_numeric($amount) && is_finite((float) $amount) && (float) $amount >= 0 && (float) $amount <= 1000000) {
                return (int) round(((float) $amount) * 100);
            }
        }

        return null;
    }
}
