<?php

use App\Services\Scripts\ScriptSamples;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ScriptSamples::install();
    }

    public function down(): void {}
};
