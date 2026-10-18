<ol style="padding-left:18px;margin-top:0">
    <li>Go to <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Entra admin center &gt; App registrations</a> and click <strong>New registration</strong>.</li>
    <li>Set <strong>Supported account types</strong> to <em>Accounts in any organizational directory (Multitenant)</em>.</li>
    <li>Add both <strong>Web</strong> redirect URIs:
        @foreach(\App\Support\MicrosoftApp::redirectUris() as $u)<br><code>{{ $u }}</code>@endforeach
    </li>
    <li>Go to <strong>Certificates &amp; secrets</strong> and click <strong>New client secret</strong>. Copy the <em>Value</em>.</li>
    <li>Go to <strong>API permissions</strong> and add the Microsoft Graph <em>application</em> permissions listed at the bottom of this page, plus the delegated permissions <code>openid</code>, <code>profile</code> and <code>email</code>.</li>
</ol>
<form method="post" action="{{ route('ms-setup.manual') }}">
    @csrf
    <div class="field"><label>Application (client) ID</label><input type="text" name="client_id" value="{{ old('client_id') }}" placeholder="00000000-0000-0000-0000-000000000000" required></div>
    <div class="field"><label>Client secret value</label><input type="password" name="client_secret" required autocomplete="off"><div class="help">Stored encrypted in the database.</div></div>
    <div class="field"><label>Secret expiry date (recommended)</label><input type="date" name="secret_expires" value="{{ old('secret_expires') }}"><div class="help">TenantsGuard warns you 30 days before the secret expires. An expired secret stops access to every tenant.</div></div>
    <div class="field"><label>Your tenant (optional)</label><input type="text" name="home_tenant" value="{{ old('home_tenant') }}" placeholder="yourmsp.com or tenant ID"><div class="help">Lets people from your tenant use "Sign in with Microsoft".</div></div>
    <button class="btn btn-primary">Save app registration</button>
</form>
