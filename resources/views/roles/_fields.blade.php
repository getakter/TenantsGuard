<div class="grid grid-2">
    <div class="field"><label>Name</label><input type="text" name="name" value="{{ $role->name }}" required></div>
    <div class="field"><label>Description</label><input type="text" name="description" value="{{ $role->description }}"></div>
</div>
<div class="grid grid-2" style="margin-bottom:12px">
    @foreach(\App\Support\Permissions::GROUPS as $group => $perms)
        <div>
            <h3>{{ $group }}</h3>
            @foreach($perms as $perm => $label)
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin-bottom:6px">
                    <input type="checkbox" name="permissions[]" value="{{ $perm }}" @checked($role->allows($perm)) @disabled($perm === 'tenants.view')>
                    <span>{{ $label }} <small class="mono muted">{{ $perm }}</small>@if($perm === 'tenants.view') <small class="muted">(always on)</small>@endif</span>
                </label>
            @endforeach
        </div>
    @endforeach
</div>
