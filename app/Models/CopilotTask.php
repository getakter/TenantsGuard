<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CopilotTask extends Model
{
    protected $fillable = ['copilot_project_id', 'key', 'phase', 'position', 'title', 'method', 'why', 'steps', 'link', 'verify', 'who', 'required', 'due_date',
        'status', 'owner_id', 'note', 'completed_by', 'completed_at', 'verify_status', 'verify_detail', 'stale'];

    protected $casts = ['steps' => 'array', 'required' => 'boolean', 'stale' => 'boolean', 'due_date' => 'date', 'completed_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(CopilotProject::class, 'copilot_project_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function overdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && ! in_array($this->status, ['done', 'skipped'], true);
    }
}
