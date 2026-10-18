# Security policy

TenantsGuard holds application access to customer Microsoft 365 tenants. Security issues are taken seriously.

## Reporting a vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

Report them privately through GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability), using **Security > Report a vulnerability** on this repository. Please include:

- the affected version or commit
- steps to reproduce, or a proof of concept
- the impact you expect, for example which tenant data or which permission is exposed

You will receive an acknowledgement, and the fix will be coordinated with you before anything is disclosed publicly.

## Supported versions

Only the latest commit on `main` receives security fixes.

## Scope

In scope:
- authentication, MFA and session handling in the console
- authorisation: role permissions, tenant scoping, and the per-client access policy (capabilities and default deny)
- the PowerShell script sandbox and approval signing
- handling of tokens, the app secret and other credentials
- injection, XSS, CSRF and access control flaws in the web application

Out of scope:
- vulnerabilities in Microsoft services themselves (report them to the [Microsoft Security Response Center](https://msrc.microsoft.com/))
- issues that need an already compromised server or database administrator account
- self-hosted deployments that ignore the hardening guidance below

## Hardening a deployment

- Serve the console over **HTTPS** only, with `APP_ENV=production` and `APP_DEBUG=false`.
- Run the web server, the scheduler and the workers under a **dedicated, low-privilege account**, not an administrator or SYSTEM.
- Keep `.env` out of version control and readable only by the service account. It holds `APP_KEY`, which encrypts the app secret and signs script approvals.
- Prefer a **certificate credential** over a client secret for the multi-tenant app, and rotate it before it expires.
- In your own tenant, use **Conditional Access for workload identities** to restrict where the app can sign in from.
- Give each customer only the capabilities their contract covers. Use the **least-privilege grant script** instead of full admin consent, and keep Tier 0 capabilities time-limited.
- If PowerShell scripts are enabled:
  - restrict outbound traffic from `pwsh.exe` to `graph.microsoft.com`, `login.microsoftonline.com` and `outlook.office365.com`;
  - consider enforcing Constrained Language Mode system-wide with App Control for Business (WDAC) or AppLocker.
- Back up the database and review the audit log regularly. The audit log can be exported as CSV.
