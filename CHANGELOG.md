# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-10-05

### Added

- **Calmfox services → Przelewy24**: the account (test or production mode, merchant and point of sale
  IDs, CRC key, reports key) is set once for the shop on a page of its own, in the admin menu group
  every Calmfox plugin shares. The page has a connection check and lists the notification address
  of every Przelewy24 method.
- Table `calmfox_przelewy24_account` for that account; the keys are encrypted with Sylius's payment
  encryption key.

### Changed

- The payment method form has no account fields any more. It says which account the method pays
  into, and links to the account page.
- `calmfox:przelewy24:status` says whether a method uses the saved account or keys of its own.

### Upgrading

- Import `@CalmfoxSyliusPrzelewy24Plugin/config/routes/admin.yaml` under the admin prefix and run the
  migrations. Until the account is saved, methods keep paying with the keys stored with them; saving
  it moves the keys to the account page and removes them from the methods.

## [1.0.0] - 2026-10-05

### Added

- A Przelewy24 gateway for Sylius 2 payment requests, on the Przelewy24 REST API v1 and without
  Payum: transaction registration, the redirect to the payment page, and the return.
- Verification of every payment with `transaction/verify`, after the amount and currency have been
  checked against the payment.
- Notifications checked against the CRC key and the merchant and point-of-sale IDs, answered with
  `200 OK`; a notification that cannot be verified because the API is unreachable fails, so
  Przelewy24 sends it again.
- Settlement on the customer's return, so a payment does not depend on the notification arriving,
  with the payment row locked so that it is completed once.
- A fresh transaction for every visit to the payment page, with notifications for earlier attempts
  still finding their payment.
- The account details in the payment method form, with keys that are never rendered back and are
  kept when their fields are left empty; sandbox by default.
- `calmfox:przelewy24:status`, which checks the keys of every Przelewy24 method against the API and
  exits non-zero when an enabled method does not work.
- English and Polish translations.
