# Changelog

## 0.1.2 — 2026-10-10

- Stop automatically enabling demo credentials or changing Commerce shipping mode on installation/settings reads.
- Use successful HTTP status checks, credential-safe redirect handling and injectable HTTP transport.
- Fix top-level API request mode, distinct sender/receiver office fields, parcel type and full-address payloads.
- Add cash-on-delivery amount/currency and order reference to shipment labels.
- Block failed/mismatched-currency live quotes; retain explicitly test-only synthetic prices.
- Preserve initial live checkout rendering while preventing submission of uncalculated destinations.
- Apply the Commerce free-shipping threshold.
- Claim one shipment record per order before requesting a label.
- Restrict locator messages to exact trusted origins and the locator iframe.
- Add regression tests and English documentation.
