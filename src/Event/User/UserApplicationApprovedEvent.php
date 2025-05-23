<?php

declare(strict_types=1);

namespace App\Event\User;

use App\Entity\User;

class UserApplicationApprovedEvent
{
    public function __construct(
        public readonly User $user,
    ) {
    }
}
