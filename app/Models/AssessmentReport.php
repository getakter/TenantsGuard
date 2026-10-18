<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A custom Dashboard report: a named selection of identity and device tests, shared by all clients. */
class AssessmentReport extends Model
{
    protected $fillable = ['name', 'description', 'test_ids', 'created_by'];

    protected $casts = ['test_ids' => 'array'];
}
