# Sylius Przelewy24 Plugin

[![Build](https://github.com/calmfoxpl/sylius-przelewy24-plugin/actions/workflows/build.yml/badge.svg)](https://github.com/calmfoxpl/sylius-przelewy24-plugin/actions/workflows/build.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

[Przelewy24](https://www.przelewy24.pl) payments for Sylius 2 — BLIK, Polish bank transfers, cards,
Apple Pay and Google Pay, whatever the merchant has switched on in the Przelewy24 panel — built on
the current REST API and on Sylius's own payment requests, without Payum.

The plugins that came before this one talk to the Przelewy24 API 3.2 (`trnRegister`, MD5
signatures), which new merchant accounts no longer get credentials for, and they hand the work to
Payum, which Sylius 2 keeps only for compatibility. This one speaks REST API v1 and plugs into the
payment request flow Sylius 2 is built around.

## Features

- **The payment page, in the customer's language.** The order is registered as a transaction and
  the customer goes to Przelewy24 to choose how to pay; Przelewy24 waits for the bank's answer
  before sending them back.
- **Money is verified, not assumed.** A payment is completed only after `transaction/verify`
  succeeds — a transaction that is never verified is returned to the customer by Przelewy24, so a
  shop that skips this step ships goods for money it will not keep. Amount and currency are checked
  against the payment before anything is verified.
- **Notifications that cannot be forged.** Every notification is checked against the shop's CRC
  key and its merchant and point-of-sale IDs. A notification that fails is logged and completes
  nothing; a notification that cannot be verified because Przelewy24 is unreachable is answered
  with an error, so Przelewy24 sends it again.
- **No dependence on the notification arriving.** When the customer comes back, the transaction is
  looked up and settled there and then. A firewall or a maintenance page in front of the
  notification address costs nothing but a log line.
- **Exactly once.** The notification and the customer's return usually arrive within the same
  second. The payment row is locked while it is settled, so the order is paid once and the
  confirmation goes out once.
- **Every visit is a fresh transaction.** A Przelewy24 token expires and a session ID may be used
  only once, so a customer who comes back to pay an hour later is not sent to a dead link. A
  notification for any attempt — including one paid in a tab left open — finds its payment.
- **Keys that stay where they were put.** The CRC key and the reports key are stored in the gateway
  configuration (encrypted, when the shop has Sylius's encryption key) and are never rendered back
  into the panel; a key field left empty on save keeps the stored key.
- **A sandbox switch that cannot be forgotten in the wrong position.** A method that says nothing
  about its environment talks to the sandbox.
- **A check before customers find out.** `bin/console calmfox:przelewy24:status` tells whether each
  Przelewy24 method has its keys and whether Przelewy24 accepts them, and exits non-zero when an
  enabled method does not work — usable in a deployment pipeline.

## Requirements

- PHP 8.2 or newer
- Sylius 2.1 or newer
- A Przelewy24 merchant account, and for testing a [sandbox account](https://sandbox.przelewy24.pl)
  (it is a separate registration with separate keys)

## Installation

```bash
composer require calmfox/sylius-przelewy24-plugin
```

Register the bundle in `config/bundles.php`:

```php
Calmfox\SyliusPrzelewy24Plugin\CalmfoxSyliusPrzelewy24Plugin::class => ['all' => true],
```

Import its configuration, for example in `config/packages/calmfox_sylius_przelewy24.yaml`:

```yaml
imports:
    - { resource: '@CalmfoxSyliusPrzelewy24Plugin/config/config.yaml' }
```

There is no database schema of its own: the keys live in the payment method's gateway
configuration, and transactions in Sylius's payment requests and payment details.

The payment request transport has to stay synchronous (Sylius's default,
`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://`): the customer is redirected to the
payment page in the same request in which the transaction is registered.

## Configuration

In the panel: **Configuration → Payment methods → Create → Przelewy24**, then:

| Field | Where to find it in the Przelewy24 panel |
| --- | --- |
| Test mode | ticked for sandbox.przelewy24.pl, unticked for production |
| Merchant ID | My data → API data and configuration |
| Point of sale ID | the same place; leave empty when it equals the merchant ID |
| CRC key | My data → API data and configuration → "CRC key" |
| Reports key | My data → API data and configuration → "Reports key" (the REST API password) |

Then check it:

```bash
bin/console calmfox:przelewy24:status
```

Nothing has to be entered on the Przelewy24 side: the return and notification addresses travel
with every transaction. The notification address is shown under the form — usually
`https://your-shop/payment-methods/<method code>` — and the server has to accept POST requests to it
from Przelewy24: no HTTP authentication and no maintenance page in front of it. Set the router's
default URI (`framework.router.default_uri`) if you want the console command to print it with your
domain.

Przelewy24 accepts PLN, EUR, GBP and CZK, subject to what is switched on for the merchant.

## How it works

1. The customer places the order and Sylius opens a `capture` payment request. The plugin
   registers a transaction (`POST /api/v1/transaction/register`) and redirects the customer to the
   Przelewy24 payment page.
2. Przelewy24 POSTs a notification to `/payment-methods/{code}`. The plugin finds the payment by
   the session ID, checks the signature, verifies the money (`PUT /api/v1/transaction/verify`) and
   completes the payment — which marks the order paid.
3. The customer returns to `/order/after-pay/{hash}`. The plugin looks the transaction up
   (`GET /api/v1/transaction/by/sessionId/…`) and, if the money is there and the notification has
   not settled it yet, settles it. The customer sees the thank-you page, or their order with a
   button to pay again.

The Przelewy24 order ID, the payment method used and the statement title are kept in the payment's
details.

## Limitations

- Refunds are made in the Przelewy24 panel; the plugin does not send them.
- The customer always chooses the payment method on the Przelewy24 page; there is no BLIK code
  field or card form embedded in the shop's checkout.

## Moving from bitbag/przelewy24-plugin

Keep the gateway factory name (`przelewy24`), so existing payment methods carry over; switch their
gateway configuration away from Payum and to the new keys, then enter the reports key in the panel:

```sql
UPDATE sylius_gateway_config
SET use_payum = 0,
    config = '{"sandbox": true, "merchant_id": "", "pos_id": "", "crc": "", "api_key": ""}'
WHERE factory_name = 'przelewy24';
```

## Development

```bash
vendor/bin/phpunit -c phpunit.xml.dist   # unit tests
vendor/bin/phpstan analyse              # static analysis
vendor/bin/ecs check                    # coding standard
```

The core (signatures, notifications, session IDs) and the checks of the wiring and the
translations run without `composer install`. The tests of the API client and the gateway need
Symfony and Sylius; `AUTOLOAD=/path/to/a/sylius/shop/vendor/autoload.php` runs them against an
existing shop's vendor directory.

## License

MIT — see [LICENSE](LICENSE).
