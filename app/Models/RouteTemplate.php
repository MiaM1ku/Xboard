<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class RouteTemplate extends Model
{
    protected $table = 'v2_route_template';
    protected $guarded = ['id'];
    protected $casts = ['rules' => 'array', 'enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'v2_server_route_template')
            ->withPivot(['sort', 'enabled'])->withTimestamps();
    }
}
