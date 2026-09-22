<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Url extends Model
{
    protected $fillable = ['long_url', 'code', 'click_count', 'expires_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function shortenUrl($long_url = null)
    {

    }
}
