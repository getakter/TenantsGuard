<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanResult extends Model
{
    public $timestamps = false;

    protected $fillable = ['scan_id', 'check_id', 'status', 'summary', 'evidence', 'affected', 'fix'];

    protected $casts = ['evidence' => 'array', 'affected' => 'array', 'fix' => 'array'];
}
