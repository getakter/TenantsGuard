<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of a script. Approval signs the body hash together with every
 * security-relevant setting, so changing any of them in the database breaks the signature.
 */
class ScriptVersion extends Model
{
    public const STATUS = [
        'draft' => ['Draft', 's-na'],
        'pending' => ['Waiting for approval', 's-manual'],
        'approved' => ['Approved', 's-pass'],
        'rejected' => ['Rejected', 's-fail'],
        'superseded' => ['Superseded', 's-na'],
    ];

    public const RISK = [
        'read' => ['Read-only', 's-info', 'Can only read data. Write requests through Invoke-TGGraph are blocked.'],
        'change' => ['Makes changes', 's-fail', 'Can change tenants. Running it needs the scripts.run_change permission and an authenticator code.'],
    ];

    protected $fillable = ['script_id', 'version', 'body', 'sha256', 'risk', 'use_graph_module', 'use_exchange', 'timeout', 'parameters', 'lint',
        'change_note', 'status', 'created_by', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'signature'];

    protected $casts = ['parameters' => 'array', 'lint' => 'array', 'use_graph_module' => 'boolean', 'use_exchange' => 'boolean',
        'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The exact material that approval vouches for. */
    public function signedPayload(): string
    {
        return implode('|', [
            'tg-script-v1', $this->id, $this->script_id, $this->version, $this->sha256, $this->risk,
            (int) $this->use_graph_module, (int) $this->use_exchange, $this->timeout,
            hash('sha256', json_encode($this->parameters)), $this->reviewed_by,
        ]);
    }

    public function sign(): string
    {
        return hash_hmac('sha256', $this->signedPayload(), (string) config('app.key'));
    }

    /** Body unchanged since approval and signature valid. */
    public function intact(): bool
    {
        return $this->status === 'approved'
            && hash_equals($this->sha256, hash('sha256', $this->body))
            && is_string($this->signature)
            && hash_equals($this->sign(), $this->signature);
    }

    public function blocked(): array
    {
        return $this->lint['blocked'] ?? [];
    }
}
