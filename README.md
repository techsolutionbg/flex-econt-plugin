# Flex Econt 0.1.2

Flex Econt connects Flex Commerce checkout to Econt delivery. It adds office/parcel-locker and address methods, a browser office locator, delivery calculations and optional automatic shipment labels after an order is placed. Administration and checkout messages are Bulgarian.

## Requirements and installation

Requires Flex CMS 0.1.56+, PHP 8.3+ and Flex Commerce 0.2.3+ with shipping hooks. Use the platform's installed dependencies, including Guzzle. This extension is not a standalone courier client.

Place the repository contents in `plugins/flex/econt`, with `plugin.json` directly in that directory, or use a correctly packaged extension ZIP. Install Commerce first, then install and activate Econt. The manifest declares `admin.ui`, `routes.admin`, `routes.public` and `frontend.assets` permissions and a Commerce dependency.

A new installation is disabled with empty credentials. Installation and reading settings do not enable a demo account or change Commerce shipping mode. Existing saved configurations are preserved on updates. Deactivation preserves configuration and shipment records; the plugin has no automatic data-removal routine.

## Configuration

In Commerce settings select Econt shipping mode. In the Econt administration page configure:

- Enabled delivery, office/address switches and test or live mode.
- Separate usernames and passwords for the two modes.
- Sender name and phone, plus an office code or a complete city/postcode/address.
- Default parcel weight between 0.01 and 1000 kg.
- Optional automatic label creation, disabled by default.

Live activation requires credentials and sender details. Blank password fields preserve saved passwords; responses mask passwords and expose only whether they are set. Credentials are stored in the platform database settings table and sent server-side to the selected Econt endpoint. Protect database backups accordingly. The public configuration endpoint does not return credentials or sender details.

Test mode uses `https://demo.econt.com/ee/services`; live mode uses `https://ee.econt.com/services`. Use the Test connection button after saving settings. It requests the country nomenclature, without creating a shipment. Test credentials must be provided explicitly; do not use a live account for development.

## Checkout and pricing

The Commerce shipping-method hook registers `econt_office` and `econt_address` when Econt mode, the enabled switch and the selected mode's credentials are present. The office and address switches control which methods are offered. Commerce currently falls back to its fixed-rate address method when no courier methods are registered.

Office delivery opens the Econt locator in an iframe. The selected office supplies a code, name, city, postcode and address to Commerce. Messages must come from an exact approved locator origin and the locator iframe; matching hostname prefixes alone are rejected. Address delivery uses the customer's city, postcode and full address.

The server calls `LabelService.createLabel` with the top-level request mode `calculate`. A calculation does not create a shipment. Sender and receiver office fields are separate; parcel type is `pack`. Address text is sent through `fullAddress`, rather than assigning an invented street number.

A courier response must contain a finite, non-negative total. Its currency must match the Commerce order currency; the plugin does not convert currencies. In **live mode**, API errors, incomplete responses or currency mismatch block checkout instead of substituting a made-up fee. Before the customer selects a destination, live quotes have `calculated: false` and submission is rejected until a calculation can be confirmed.

In **test mode only**, API failure can produce a deterministic synthetic demo fee. Its metadata has `price_source: demo`; it is not an actual courier quote. Successful calculations use `price_source: econt`. Do not treat a demo price as a production rate.

A positive Commerce free-shipping threshold waives the customer delivery fee when the product subtotal reaches that threshold. Courier calculation is still required in live mode. The default weight is for the whole parcel; product-specific weights, dimensions and packaging rules are not implemented.

## Automatic labels

When automatic labels are enabled, a committed Commerce order using an Econt method triggers a label request in top-level `create` mode. It uses the stored customer details, the order number and the order total as cash-on-delivery amount in the order currency. The recipient delivery payment percentage is zero because delivery is already included in the checkout total; the sender pays the courier fee.

A unique order record is claimed before contacting Econt to prevent repeated order hooks from creating duplicate labels. Successful responses save the shipment number and PDF URL. Errors are recorded without rolling back the Commerce order. There is no automatic retry queue: an interrupted request may leave a pending record whose outcome must be checked with Econt before any manual retry. The plugin does not automatically cancel a courier shipment when its Commerce order is cancelled.

Creating a live label affects the courier account and transmits customer delivery details to Econt. Enable this feature only after verifying your account, sender data, payment arrangement and test workflow. This release was tested with mocked label requests; no real shipment was created during validation.

## HTTP endpoints

All endpoints use the platform plugin route prefix.

| Endpoint | Purpose |
| --- | --- |
| `GET /plugins/flex/econt/config` | Enabled state, mode and locator URL |
| `GET /plugins/flex/econt/offices` | Bulgarian offices, optionally filtered by city ID |
| `POST /plugins/flex/econt/calculate` | Delivery calculation; requires CSRF |
| `GET /admin/plugins/flex/econt/settings` | Masked administration settings |
| `PUT /admin/plugins/flex/econt/settings` | Save configuration |
| `POST /admin/plugins/flex/econt/test-connection` | Test selected credentials; requires CSRF |

Admin routes use the platform administration access controls. Public calculation returns amount, currency, calculation state, source and mode; it does not expose the raw courier response. Requests have timeouts, require successful HTTP status and do not follow redirects with account credentials.

## Storage and integrations

`flex_econt_settings` stores configuration. `flex_econt_shipments` stores one record per order with mode, shipment number, label URL, state, response payload/error and timestamps. These tables use the configured platform database prefix. API responses and shipment records may contain delivery-related personal data.

The plugin registers Commerce hooks for checkout assets, shipping methods, quote adjustment, customer validation and placed orders. Accounts can prefill a previously saved office through Commerce; no Accounts dependency is required. The Econt locator UI integrates through `commerce:shipping-meta` events.

## Development checks

Run from the Flex CMS root with development dependencies installed:

```sh
vendor/bin/phpunit plugins/flex/econt/tests plugins/flex/commerce/tests plugins/flex/accounts/tests --display-warnings
node --test plugins/flex/econt/tests/checkout.test.cjs
```

Tests use temporary SQLite storage and mocked HTTP responses; they do not create shipments or call a live account. See `TEST_REPORT.md` for the performed checks and limitations.

## Scope and API references

There is no tracking synchronization, label-management administration page, cancellation synchronization, refund handling, product weight model, persistent quote cache or retry queue. Test calculations cannot certify the pricing or service options of a particular live contract.

Request structures follow the [Econt OpenAPI specification](https://ee.econt.com/services/openapi.yaml) and [official JSON example](https://ee.econt.com/xml_api_example.php). TLS verification remains enabled; insecure settings in example code are not used.

## Licensing

This plugin is licensed under the GNU General Public License, version 2 or (at your option) any later version (`GPL-2.0-or-later`). The full text of version 2 is in [`LICENSE`](LICENSE).
