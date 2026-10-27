<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessStaff extends Model
{
    use HasUlid, SoftDeletes;

    protected $table = 'business_staff';

    protected $fillable = ['business_id', 'name', 'job_title', 'mobile_number', 'avatar_path'];

    protected $hidden = ['avatar_path'];
}
