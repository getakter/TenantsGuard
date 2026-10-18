<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Baseline extends Model
{
    protected $fillable = ['name', 'description', 'check_ids'];

    protected $casts = ['check_ids' => 'array'];
}
