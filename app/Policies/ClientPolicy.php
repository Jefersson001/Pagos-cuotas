<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function view(User $user, Client $client): bool
    {
        return $user->canAccessRecord($client);
    }

    public function update(User $user, Client $client): bool
    {
        return ($user->canOperateFinancial() || $user->isHeadOffice()) && $this->view($user, $client);
    }
}
