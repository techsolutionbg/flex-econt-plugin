# Validation report

Date: 2026-10-10. PHP 8.3.35, PHPUnit 12.5.35, local Docker Flex CMS.

- Combined Econt, Commerce and Accounts suites: 82 tests, 614 assertions, passed.
- Econt-specific suite: 9 tests covering disabled installation, password masking/preservation, request mode and office fields, COD payload, HTTP failure, live failures, currency mismatch, test fallback, incomplete destination and free shipping.
- Locator JavaScript test: exact accepted origins, rejected suffix-host attacks and rejected unrelated message sources.
- PHP syntax and browser-module syntax checks passed.
- Real Econt **test-mode** API: connection returned 236 countries; a synthetic address calculation returned 577 minor units in EUR. No shipment was created. No real customer details were used.

## Limits

Automatic creation was not exercised against a real courier account. Its unique record claim was reviewed but not subjected to concurrent-worker or process-crash tests. No browser office selection, parcel-locker workflow, tracking, cancellation or production-contract pricing was certified. Live transport failures and currency mismatch were tested with mocked HTTP responses. The API calculation smoke helper is outside the release repository and uses existing local test configuration.

Shipment records may remain pending if the process stops after an API call; verify the courier outcome before manually retrying. No retry queue or cancellation synchronization exists. Test fallback prices are synthetic and remain test-only.
