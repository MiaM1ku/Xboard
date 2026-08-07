<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNodeTraffic extends Model
{
    protected $table = 'v2_user_node_traffic';
    protected $guarded = ['id'];
    protected $casts = [
        'u' => 'integer',
        'd' => 'integer',
        'record_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function routeProfile(): BelongsTo
    {
        return $this->belongsTo(ServerRouteProfile::class, 'route_profile_id');
    }

    public function outboundTemplate(): BelongsTo
    {
        return $this->belongsTo(OutboundTemplate::class, 'outbound_template_id');
    }
}
