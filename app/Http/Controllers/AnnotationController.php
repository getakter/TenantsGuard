<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Scanner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnotationController extends Controller
{
    public function store(Request $request, Tenant $tenant, string $check)
    {
        abort_unless(Catalog::find($check), 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Annotation::TYPES))],
            'note' => 'required|string|max:2000',
            'review_by' => 'nullable|date|after:today',
        ]);

        Annotation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'check_id' => $check],
            $data + ['user_id' => $request->user()->id]
        );
        Scanner::rescore($tenant);
        ActivityLog::record('annotation', $tenant, 'info', Annotation::TYPES[$data['type']].': '.$data['note'], $check);

        return redirect(route('tenants.show', $tenant).'#'.$check)->with('status', 'Annotation saved.');
    }

    public function destroy(Tenant $tenant, string $check)
    {
        Annotation::where(['tenant_id' => $tenant->id, 'check_id' => $check])->delete();
        Scanner::rescore($tenant);
        ActivityLog::record('annotation.removed', $tenant, 'info', 'Annotation removed', $check);

        return redirect(route('tenants.show', $tenant).'#'.$check)->with('status', 'Annotation removed.');
    }
}
