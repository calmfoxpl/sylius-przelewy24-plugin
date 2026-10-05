<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Core;

/** The `status` of a transaction as `transaction/by/sessionId` reports it. */
enum TransactionStatus: int
{
    case NoPayment = 0;

    /** The customer has paid and the money waits for `transaction/verify`. */
    case AwaitingVerification = 1;

    case Verified = 2;
    case Returned = 3;

    public function isPaid(): bool
    {
        return self::AwaitingVerification === $this || self::Verified === $this;
    }
}
