<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ServerRouteProfile extends Model
{
    protected $table = 'v2_server_route_profile';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
        'entry_server_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            $profile->uuid ??= (string) Str::uuid();
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function outboundTemplate(): BelongsTo
    {
        return $this->belongsTo(OutboundTemplate::class, 'outbound_template_id');
    }
}
