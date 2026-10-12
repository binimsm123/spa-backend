<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessCategory extends Pivot
{
    protected $table = 'business_category';

    protected $fillable = ['business_id', 'category_id'];
}
