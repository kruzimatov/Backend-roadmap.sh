<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Url extends Model
{
    protected $fillable = ['user_id', 'long_url', 'code', 'click_count', 'expires_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function clicks()
    {
        return $this->hasMany(Click::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
