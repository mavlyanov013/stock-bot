<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $fillable = [
        'symbol',
        'company_name',
        'last_price',
        'prev_price',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_price' => 'float',
            'prev_price' => 'float',
            'last_checked_at' => 'datetime',
        ];
    }
}
