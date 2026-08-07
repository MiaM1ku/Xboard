<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;

class SelfHostedCompatibilityController extends Controller
{
    /**
     * Keep the legacy admin bundle bootable without exposing or re-enabling
     * payment gateways in self-hosted distribution mode.
     */
    public function payments()
    {
        return $this->success([]);
    }
}
