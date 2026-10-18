<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Microsoft Secure Score captured on every scan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_score_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('ms_created_at')->nullable();   // when Microsoft calculated it
            $t->decimal('current_score', 8, 2)->nullable();
            $t->decimal('max_score', 8, 2)->nullable();
            $t->decimal('percent', 5, 2)->nullable();
            $t->text('categories')->nullable();           // {Identity: {score, max}, ...}
            $t->text('comparatives')->nullable();         // {AllTenants: points, TotalSeats: points, IndustryTypes: points}
            $t->longText('controls')->nullable();         // improvement actions
            $t->longText('history')->nullable();          // [{date, score, max}] daily from Microsoft
            $t->unsignedInteger('licensed_users')->nullable();
            $t->unsignedInteger('active_users')->nullable();
            $t->text('error')->nullable();                // e.g. missing SecurityEvents.Read.All
            $t->timestamps();
            $t->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_score_snapshots');
    }
};
