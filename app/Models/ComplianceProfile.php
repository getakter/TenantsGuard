<?php

namespace App\Models;

use App\Services\Compliance\FrameworkRegistry;
use App\Services\Compliance\PlanDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceProfile extends Model
{
    protected $fillable = ['name', 'description', 'frameworks', 'phases', 'excluded_controls', 'control_settings', 'intune_bundle_id', 'exclude_from_score', 'is_system'];

    protected $casts = ['frameworks' => 'array', 'phases' => 'array', 'excluded_controls' => 'array', 'control_settings' => 'array', 'exclude_from_score' => 'boolean', 'is_system' => 'boolean'];

    // ------------------------------------------------------------------ rollout

    /** @return array<int, array{n: int, name: string, description: string}> keyed by phase number */
    public function phaseList(): array
    {
        $phases = $this->phases ?: PlanDefaults::PHASES;

        return collect($phases)->keyBy('n')->sortKeys()->all();
    }

    /** @return array{phase: int, assignment: ?string, type: string, impact: string} */
    public function setting(string $controlId): array
    {
        $s = $this->control_settings[$controlId] ?? [];
        $phases = array_keys($this->phaseList());
        $phase = (int) ($s['phase'] ?? PlanDefaults::phaseFor($controlId));

        $bundleItem = $this->bundleItem($controlId);

        return [
            'phase' => in_array($phase, $phases, true) ? $phase : (max($phases) ?: 1),
            'assignment' => $s['assignment'] ?? $bundleItem['assignment'] ?? PlanDefaults::assignmentFor($controlId),
            'group' => $bundleItem['group'] ?? null,
            'params' => $bundleItem['params'] ?? [],
            'from_bundle' => $bundleItem !== null,
            'type' => PlanDefaults::typeFor($controlId),
            'impact' => PlanDefaults::impactFor($controlId),
        ];
    }

    public function intuneBundle(): BelongsTo
    {
        return $this->belongsTo(IntuneBundle::class);
    }

    /** Settings for an Intune template taken from the plan's linked bundle (tuned parameters, group). */
    public function bundleItem(string $controlId): ?array
    {
        if (! $this->intune_bundle_id) {
            return null;
        }
        $bundle = $this->relationLoaded('intuneBundle') ? $this->intuneBundle : $this->intuneBundle()->first();
        $this->setRelation('intuneBundle', $bundle);

        return collect($bundle?->items ?? [])->firstWhere('template', $controlId);
    }

    /** In-scope controls in a stable order. */
    public function inScopeControls(): array
    {
        $ids = array_values(array_filter($this->candidateControls(), fn ($id) => ! $this->isExcluded($id)));
        sort($ids, SORT_NATURAL);

        return $ids;
    }

    /** @return array<int, string[]> phase number => control ids */
    public function controlsByPhase(): array
    {
        $out = array_fill_keys(array_keys($this->phaseList()), []);
        foreach ($this->inScopeControls() as $id) {
            $out[$this->setting($id)['phase']][] = $id;
        }

        return $out;
    }

    /** Selected frameworks a control belongs to, with the requirement IDs (for overlap tags). */
    public function controlFrameworks(string $controlId): array
    {
        $out = [];
        foreach (FrameworkRegistry::requirementsForControl($controlId) as $x) {
            if (! in_array($x['framework'], $this->frameworkKeys(), true)) {
                continue;
            }
            $target = $this->targetLevel($x['framework']);
            if ($target && $x['requirement']['level'] && $x['requirement']['level'] > $target) {
                continue;
            }
            $out[$x['framework']][] = $x['requirement']['id'].($x['requirement']['level'] ? ' (ML'.$x['requirement']['level'].')' : '');
        }

        return $out;
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function frameworkKeys(): array
    {
        return array_values(array_filter(array_column($this->frameworks ?? [], 'key'), fn ($k) => FrameworkRegistry::find($k)));
    }

    public function targetLevel(string $key): ?int
    {
        foreach ($this->frameworks ?? [] as $f) {
            if ($f['key'] === $key) {
                return isset($f['target_level']) ? (int) $f['target_level'] : null;
            }
        }

        return null;
    }

    /** Controls referenced by the profile's frameworks (respecting the Essential Eight target level). */
    public function candidateControls(): array
    {
        $ids = [];
        foreach ($this->frameworkKeys() as $key) {
            $target = $this->targetLevel($key);
            foreach (FrameworkRegistry::find($key)['requirements'] as $r) {
                if ($target && $r['level'] && $r['level'] > $target) {
                    continue;
                }
                foreach ($r['controls'] as $c) {
                    $ids[$c] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public function isExcluded(string $controlId): bool
    {
        return array_key_exists($controlId, $this->excluded_controls ?? []);
    }

    /** Posture checks removed from the security score for tenants using this profile. */
    public function scoreExclusions(): array
    {
        if (! $this->exclude_from_score) {
            return [];
        }

        return array_values(array_filter(array_keys($this->excluded_controls ?? []), fn ($id) => (FrameworkRegistry::control($id)['source'] ?? null) === 'posture'));
    }
}
