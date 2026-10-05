<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Account;

use Calmfox\SyliusPrzelewy24Plugin\Core\Credentials;

/** The account saved in Calmfox services → Przelewy24, if one has been saved there. */
interface SavedAccount
{
    public function credentials(): ?Credentials;
}
