<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Click extends Model
{
    protected $fillable = ['url_id', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            // 'created_at' => 'datetime',
            // 'expires_at' => 'datetime',
        ];
    }

    public function url()
    {
        return $this->belongsTo(Url::class);
    }
}
