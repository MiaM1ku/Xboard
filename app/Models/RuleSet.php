<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RuleSet extends Model
{
    protected $table = 'v2_rule_set';
    protected $guarded = ['id'];
    protected $casts = ['settings' => 'array', 'enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $ruleSet): void {
            $ruleSet->uuid ??= (string) Str::uuid();
        });
    }
}
