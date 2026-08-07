<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMachineBinding extends Model
{
    public const STATE_ACTIVE = 'active';
    public const STATE_DRAINING = 'draining';
    public const STATE_DISABLED = 'disabled';

    public const STATES = [
        self::STATE_ACTIVE,
        self::STATE_DRAINING,
        self::STATE_DISABLED,
    ];

    protected $table = 'v2_server_machine_binding';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'machine_id' => 'integer',
        'desired_config_version' => 'integer',
        'applied_config_version' => 'integer',
        'last_seen_at' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function isServing(): bool
    {
        return in_array($this->state, [self::STATE_ACTIVE, self::STATE_DRAINING], true);
    }
}
