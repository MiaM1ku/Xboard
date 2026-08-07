<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RealityTemplate extends Model
{
    protected $table = 'v2_reality_template';
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
}
