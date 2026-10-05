# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
