<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;

class ServicePhoto extends Model
{
    use HasUlid;

    protected $fillable = ['service_id', 'path', 'position'];
}
