<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class OutboundTemplate extends Model
{
    public const PROTOCOL_SHADOWSOCKS = 'shadowsocks';
    public const PROTOCOL_SOCKS5 = 'socks5';
    public const PROTOCOL_VLESS = 'vless';
    public const PROTOCOLS = [self::PROTOCOL_SHADOWSOCKS, self::PROTOCOL_SOCKS5, self::PROTOCOL_VLESS];

    protected $table = 'v2_outbound_template';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? decrypt($value) : [],
            set: fn ($value) => encrypt(is_array($value) ? $value : []),
        );
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'v2_server_outbound_template')
            ->withPivot(['tag', 'sort', 'enabled'])->withTimestamps();
    }
}
