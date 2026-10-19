# TenantsGuard user guide

The detailed guide to every TenantsGuard module. For installation, see the [README](../README.md#quick-start).

## Contents

- [Connecting real Microsoft 365 tenants](#connecting-real-microsoft-365-tenants)
- [Interface and client Dashboard](#interface-and-client-dashboard)
- [Three lenses on every client](#three-lenses-on-every-client)
- [Identity management: onboarding and offboarding](#identity-management-onboarding-and-offboarding)
- [Day-to-day MSP workflow](#day-to-day-msp-workflow)
- [Security monitoring and alerts](#security-monitoring-and-alerts)
- [Licence optimisation](#licence-optimisation)
- [Intune and device management](#intune--device-management-module)
- [Compliance frameworks and control plans](#compliance-frameworks-and-profiles)
- [Data security: Microsoft Purview programmes](#data-security-microsoft-purview-programmes)
- [Microsoft 365 Copilot programmes](#microsoft-365-copilot-programmes)
- [PowerShell scripts](#powershell-scripts-tools)
- [Permission scope (least privilege per client)](#permission-scope-least-privilege-per-client)
- [Multi-factor authentication](#multi-factor-authentication-mandatory)
- [Users, roles and tenant access](#users-roles-and-tenant-access)
- [Controls not available through Graph](#controls-not-available-through-graph)
- [Framework mappings](#framework-mappings)
- [Adding a control](#adding-a-control)
- [Scoring](#scoring)

## Connecting real Microsoft 365 tenants

Every Microsoft popup, including "Connect with Microsoft", "Grant admin consent", and "Sign in with Microsoft", runs on **one multi-tenant Entra app that you own**.
You set it up once, using either method below. After that, customers only pick their account and click **Accept**.

Open the app on **http://localhost:8000**. Microsoft only accepts `http://` redirect URIs on `localhost`, so requests to 127.0.0.1 are redirected there automatically.

### Method A: Automatic setup (recommended)

Go to **Settings > Microsoft app > Sign in with Microsoft to set up**. Copy the code, open Microsoft sign-in, and sign in as a Global Administrator of your own tenant. TenantsGuard then creates the app registration, the client secret, both redirect URIs, and every Graph permission for you. The secret is stored encrypted in the database.

The same setup is available from the command line:
```
C:\xampp\php\php.exe artisan tenantsguard:setup-app            # add --read-only to skip write permissions
```

Sign-in uses Microsoft's public "Microsoft Graph Command Line Tools" client, the same one behind `Connect-MgGraph`, with the device code flow. It only asks for `Application.ReadWrite.All`. If your tenant's Conditional Access blocks that client, use Method B.

### Method B: Manual registration

1. Go to Entra admin center > **App registrations** > **New registration**.
   - Supported account types: **Accounts in any organizational directory (Multitenant)**.
   - Redirect URI (Web): `http://localhost:8000/consent/callback`. Use your real URL in production.
2. Go to **Certificates & secrets** > **New client secret**.
3. Go to **API permissions** and add the **application** permissions of the capabilities you want in your app plan. **Settings > Application permissions** lists them per capability, and [Permission scope](#permission-scope-least-privilege-per-client) explains the tiers. Also add the delegated permissions `openid`, `profile` and `email` for console sign-in.

   The **Security monitoring** capability (read-only) is enough for assessment. Write and privileged capabilities are only needed for fixes, deployments and lifecycle automation.
4. Paste the client ID and secret into **Settings > Microsoft app > Method B**. Alternatively, put them in `.env` as `MS_CLIENT_ID` and `MS_CLIENT_SECRET`, then run `php artisan config:clear`. Values saved in Settings take precedence.

### Onboarding a customer

You can connect a customer in either of two ways:

- **Connect with Microsoft:** a popup opens. The customer's Global Administrator picks their account and clicks **Accept**. Microsoft reports the tenant, and TenantsGuard adds it, verifies access, and scans it. Nothing has to be typed.
- **Add by domain or tenant ID:** add the tenant, then click **Grant admin consent** for the popup. You can also copy the consent link from the tenant page and send it to the customer's admin, who opens it in their own browser without a TenantsGuard login.

### Sign in with Microsoft (console login)

The login page offers **Sign in with Microsoft** in a popup, as well as email and password.
An existing console user can sign in with a Microsoft account that has the same email.
Users from tenants listed under **Settings > Sign in with Microsoft** get an account automatically on first sign-in. Automatic setup adds your own tenant to that list.

No customer passwords are stored. Access uses app-only tokens, which you can revoke at any time. To revoke, delete the TenantsGuard enterprise application in the customer tenant.

## Interface and client Dashboard

The console follows the CIPP layout.

**Top bar.**
- A **client switcher** opens any client's Dashboard. "All clients" opens the portfolio.
- **Refresh** re-runs every test for the client you are viewing.
- A **light and dark theme** toggle. Dark is the default, and the choice is remembered in the browser.
- **People search** across all clients. Press `/` to open it. A result opens the person's page with their profile, MFA methods, roles, licences, devices, groups, owned groups and inbox rules, plus Offboard and "Make a template from this person".
- **Alerts**: failures and warnings from the last 7 days.
- Your user menu.

**Sidebar.** Collapsible groups: Portfolio, the current client (Dashboard, Security posture, Secure Score, People, Control plan, Framework scorecard, Intune and devices, Client settings), Identity Management, Security & Compliance, Intune, Monitor and TenantsGuard.

**Client Dashboard** (Portfolio > client, or the client switcher) has three tabs plus a link to the classic Security posture page.

- **Action bar.**
  - **Portals** opens the client's Microsoft 365, Entra, Exchange, Intune, Defender, Purview, Teams, SharePoint and Azure admin portals.
  - **Executive Summary** opens a live preview with on/off switches for each report section, and **Download PDF**.
  - **Excel workbook** downloads the findings.
  - **Select a report** picks which tests the Assessment card and the test tabs use.
  - **Create custom report**, **Update report** (re-run everything) and **Delete** for custom reports.
- **Overview.**
  - Tenant facts.
  - Users, guests, groups, app registrations, managed and compliant devices.
  - The **Assessment** card: identity and device tests passed for the selected report, and when data was last collected.
  - The **Secure Score** trend.
  - **User authentication**: enabled users, then MFA registered or not, then what enforces MFA.
  - **All users auth methods**: single factor, phishable MFA or phishing-resistant.
  - **License overview**.
  - Overview data is cached for 15 minutes.
- **Identity** and **Devices.** Every test with its result, risk, user impact, effort and whether TenantsGuard can apply a standard for it. You can search, filter by result and risk, and export to CSV.
  - Click a test to open the details panel: result and evidence, affected objects, what was checked, remediation, framework references and a link to fix it.
  - Use the arrow keys to move between tests.

**Where the tests come from.**
- Identity tests are the TenantsGuard controls from the latest scan.
- Device tests are the Devices controls plus the Intune and Zero Trust template library: deployed, a prerequisite met, or not licensed.

**Built-in reports.** All tests, Zero Trust, CIS Microsoft 365 Foundations, Essential Eight, NIST CSF 2.0 and Cyber insurance readiness.

**Custom reports.** Choose any identity and device tests and give the report a name. Custom reports are shared by all clients. Creating them needs the `tenants.scan` permission.

**Executive report sections.** Executive summary, Identity and MFA, Secure Score, Compliance frameworks, Posture by area, Framework alignment, Licence management, Device management, What changed, Score trend, Priority findings and All controls. The report URL accepts `sections[]=…` to choose sections directly.

**Branding.** The interface follows Microsoft Fluent 2. The default accent colour is Microsoft blue (`#0f6cbd`). Change it, the company name and the logo in Settings > Branding.

## Three lenses on every client

| Lens | Question it answers | Source |
|---|---|---|
| **TenantsGuard posture score** | Is the tenant configured to our baseline? | TenantsGuard controls (Microsoft 365 + Intune), weighted by severity |
| **Microsoft Secure Score** | How does Microsoft rate the tenant, and how does it compare with peers? | Microsoft Graph `security/secureScores` and `secureScoreControlProfiles` (`SecurityEvents.Read.All`) |
| **Compliance frameworks** | Does it meet the regulatory or contractual bar? | Control plan: Essential Eight, HIPAA, CIS, NIST, ISO, PCI, Cyber Essentials |

**Microsoft Secure Score** is captured with every scan. It records the current score, 90 days of daily history, points by category (Identity, Data, Device, Apps, Infrastructure), the averages for similar-size and all tenants, and every improvement action with its points, status (including risk accepted or third party), user impact and cost. It appears in:
- **Portfolio:** a Secure Score column with trend, and the average across clients.
- **Client > Security posture:** the three-lens overview.
- **Client > Microsoft Secure Score:** improvement actions ranked by points available. Each action is matched to the TenantsGuard control that fixes it (with its live status and control plan phase) and to the frameworks that control satisfies. Filter to "TenantsGuard can help" or "also closes a framework gap".
- **Framework scorecard and rollout:** a Secure Score card, plus a "+N Secure Score" chip on each plan control that would earn points.
- **Executive report and Excel:** score, peer comparison, categories and the top actions.
- **Alerts:** a drop of 5 points or more triggers a webhook alert and appears in "Needs attention", as does missing access to Secure Score.

## Identity management: onboarding and offboarding

The sidebar section **Identity management** follows the CIPP layout. It has five items:

| Item | What it does |
|---|---|
| **Onboard user** | Create one account. Choose the client, or go straight in when you manage only one. |
| **Bulk onboard users** | Create up to 50 accounts from a CSV list, with a checked preview first. |
| **Offboard users** | Offboard one or several leavers, now or at a scheduled time. |
| **User templates** | Per-client defaults for new accounts, with one default template per client. |
| **Lifecycle activity** | Open checklist items, scheduled actions you can cancel, and recent joiners and leavers. |

A client's **People** tab has the same actions for that client.

**User templates.** A template holds:
- the username format (`firstname.lastname`, `f.lastname`, `flastname`, `firstname.l` and others) and a display name suffix such as "(Contractor)"
- the domain and usage location
- job title, department and office
- company, address, business phone and manager
- the first sign-in method
- licences, groups and the hand-over checklist

Mark one template as the client's default and it is pre-selected on the onboarding forms. You can build a template from an existing person in the same role, or save one from the onboarding form.

**Onboard user.** Choose only what applies:
1. Start from a template, and/or **copy access from a colleague**, which adds their licences and groups.
2. **Identity.** The display name and username are suggested from the template's format. Add job title, department, office, phones, company, address, usage location (required for licences) and manager. The sign-in name is checked to be free before anything is created.
3. **First sign-in.** Use a **Temporary Access Pass** (recommended: passwordless onboarding, the user registers Authenticator or a passkey) or a strong temporary password that must be changed.
4. **Licences** with live free-seat counts. Full SKUs are disabled.
5. **Groups and Teams.** Dynamic, synced and Exchange-only groups are shown but disabled, with the reason.
6. **Start date.** A future date creates the account disabled and enables it automatically at 07:00 on the day. Add a ticket reference and a hand-over checklist.

Credentials are **shown once and never stored**.

**Bulk onboard users.** Paste CSV or upload a file. Download the example to start.
- `FirstName` and `LastName` are required.
- Optional columns are `DisplayName`, `Username`, `JobTitle`, `Department`, `Office`, `Mobile`, `BusinessPhone`, `Manager` (email or name), `UsageLocation` and `StartDate`.
- Commas, semicolons and tabs all work.
- One template, the licences and groups, the sign-in method and the start date apply to everyone.
- The preview flags taken usernames, duplicates within the file, unknown managers and licences without enough free seats. Rows with problems are skipped.
- First sign-in details are shown once, with a CSV download.

**Offboard users.** Tick one or several people. You then see what each person has before anything changes: licences, groups, groups and Teams they own, admin roles, MFA methods, inbox rules (forwarding rules are flagged) and devices. This snapshot is kept with each record. The steps run in this order:
- **Lock out:** block sign-in, revoke sessions, reset the password, remove MFA methods.
- **Mailbox and files:**
  - Automatic: an out-of-office reply, with `{name}`, `{manager}` and `{company}` filled in per person, and removal of inbox rules, including forwarding rules.
  - Checklist items, each with the exact PowerShell: convert to a shared mailbox, delegate to the manager, hide from the global address list, and OneDrive hand-over.
- **Access:** remove admin roles. **Transfer group and Teams ownership** adds the new owner first, then removes the leaver. The leaver is then removed from groups and Teams. Revoking third-party apps is a checklist item.
- **Devices:** retire or wipe each Intune device for one leaver, or choose one action for all devices of several leavers.
- **Licences:** remove them now, or after 7, 14 or 30 days so the mailbox can be converted first. **Account deletion** can be scheduled, and a deleted account can be restored for 30 days.

**When.** Choose **Now** or **Schedule**. A scheduled offboarding changes nothing until the chosen time, for example 17:30 on the last working day. The scheduler then runs the whole process, and you can cancel it from Lifecycle activity until then.

**Defaults per client.** Tick "Save these steps and options as the default" to pre-fill the steps, licence delay, deletion delay, device action and reply text next time for that client.

**Safety rules.**
- The last Global Administrator can't be offboarded.
- To confirm one leaver, type their UPN. To confirm several, type `OFFBOARD <number>`.
- The new owner can't be one of the leavers.
- Every step is recorded with its result, and failures trigger an alert.

**Scheduler.** Start dates, delayed licence removal, deletions and scheduled offboardings run with `lifecycle:process` every 5 minutes. The scheduler must be running: see Settings > System health.

**Permissions.**
- TenantsGuard roles: `staff.onboard` and `staff.offboard`, which Administrators and Technicians have.
- Microsoft Graph permissions to add: `User.ReadWrite.All`, `Group.ReadWrite.All`, `UserAuthenticationMethod.ReadWrite.All`, `MailboxSettings.ReadWrite` (for automatic replies and inbox rules), `RoleManagement.ReadWrite.Directory` and `DeviceManagementManagedDevices.PrivilegedOperations.All`.
- Add them with **Settings > Update app permissions**, then **Re-grant consent** for each client.

## Day-to-day MSP workflow

| Where | What you do |
|---|---|
| **Clients > Portfolio** | All clients in one table: score and trend, critical gaps, drift, control plan progress and last scan. Filter by search, **tags**, plan or "needs attention". Filters are remembered. A **Needs attention** queue lists drift, critical gaps, consent problems, checks that could not run, stale scans, failed changes, overdue reviews, an expiring app secret and a stopped scheduler. |
| **Client pages** | Every client has the same tabs: *Security posture · Control plan · Framework scorecard · Intune and devices · Client settings*. New clients show an **onboarding checklist**: connect, first scan, choose plan, preview phase 1, apply. |
| **Client settings** | Tags (industry, tier, region, team), client contact, internal notes, re-grant consent, and offboarding, which requires typing the client name. |
| **Configure > Control plans** | Frameworks, scope and phases per client or segment. Duplicate, **export or import as JSON**, and **roll out across clients**: pick the clients on a plan, then preview or apply a phase or all phases to all of them. |
| **Configure > Intune bundles** | Your tuned Intune settings. **Link a bundle to a control plan** and the plan deploys with those parameters and groups. Export and import as JSON. |
| **Configure > Microsoft 365 baselines** | Quick grid to push individual Microsoft 365 settings across clients. |
| **Monitor > Activity** | Full audit trail with date filters and **CSV export** for client evidence or PSA tickets. |
| **Admin > Settings** | **System health** (Microsoft app, secret expiry, scheduler heartbeat, MFA enrolment, client connections) and **alert notifications** to Microsoft Teams (Workflows), Slack, or a generic JSON webhook for PSA or automation tools, with a test button. |

**Safety for teams.** Changing operations (scan, fix, plan apply, Intune deployment) take a per-client lock, so two technicians or the scheduler can't collide on the same tenant.

**Scheduler on Windows.** Create a Scheduled Task that runs every minute:
```
C:\xampp\php\php.exe C:\path\to\TenantsGuard\artisan schedule:run
```

## Security monitoring and alerts

Go to **Monitor > Security alerts** for all clients, or a client's **Security alerts** tab. Scans tell you how a tenant is *configured*. Monitoring tells you what *happened*. It runs `tenants:monitor` every 15 minutes and is read-only.

| Detection | Severity | Source |
|---|---|---|
| Privileged role assigned (permanent or eligible) | Critical for Tier 0 roles (Global, Privileged Role, Security, Conditional Access, Application administrators), otherwise high | Entra audit log |
| Conditional Access policy added, changed or deleted | High for deletion, otherwise medium | Entra audit log |
| Application consent | High for admin consent, otherwise medium | Entra audit log |
| Application permissions granted to an app | High when sensitive, for example `Mail.ReadWrite`, `*.ReadWrite.All` or `RoleManagement`. Grouped into one alert per consent. | Entra audit log |
| Credential added to an app or service principal | High. Critical when it is the TenantsGuard app itself. | Entra audit log |
| Domain federation or authentication changed | Critical | Entra audit log |
| MFA methods removed by an admin | Medium | Entra audit log |
| Break-glass account sign-in | Critical when the sign-in succeeded, high when it failed | Sign-in log (Entra ID P1) |
| High-risk user | High; critical when confirmed compromised | Entra ID Protection (P2) |
| Microsoft Defender alert | As reported by Defender; informational alerts are skipped | `SecurityAlert.Read.All` |
| Microsoft 365 service incident | Medium; advisories are skipped | `ServiceHealth.Read.All` |

- **No duplicates.** Each event raises one alert. The two audit records Microsoft writes for one role assignment become one alert. Application permission grants are grouped per consent.
- **Self-clearing.** Risky-user, Defender and service-incident alerts resolve themselves ("Cleared in Microsoft 365") when the condition clears at the source.
- **Your own changes are excluded.** Changes made by TenantsGuard and consent to the TenantsGuard app are not reported. A *new credential on the TenantsGuard app* is always critical, because it would give access to every client.
- **Triage.** Use Investigate, Assign, Resolve (confirmed, expected change, or false positive), Reopen and timestamped notes, singly or in bulk. Every action is in the audit log. New critical and high alerts go to Microsoft Teams, Slack or your webhook (Settings > Alert notifications > *Security monitoring*).
- **Per client settings.** Name the **break-glass accounts** and switch individual detections on or off. The status column shows whether each detection is *Working* or *Unavailable* (missing licence or permission).
- **Permissions.** Defender alerts and service health need the **Threat alerts and service health** capability (`SecurityAlert.Read.All`, `ServiceHealth.Read.All`). It is included in the Read-only and Standard presets; grant it with the client's grant script or consent.
- **Demo clients** have a **Simulate suspicious activity** button. It runs an attack-like sequence: a federation change, a new app credential, a rogue app with mailbox permissions, MFA removal, and a deleted Conditional Access policy.
- **Roles.** `alerts.view` lets someone see alerts; `alerts.manage` lets them triage, run checks and change settings. Technicians and Administrators have both, and Read-only users can view.

## Licence optimisation

Go to **Monitor > Licence optimisation** for all clients, or a client's **Licences** tab.

- **Unassigned seats** of paid subscriptions. Seats on annual commitments can usually only be reduced at renewal, so reassign them in the meantime.
- **Disabled accounts that still hold licences.**
- **Licensed users without a sign-in** for 30, 60, 90 or 180 days. This needs Entra ID P1 for sign-in activity.
- **Duplicate licences.** A licence is reported as duplicate when every service in it is already included in another licence the same user has, for example Exchange Online Plan 1 on top of Business Premium.

Free, viral and trial SKUs are ignored. Costs are **estimates** from the editable price list (currency and monthly price per SKU) at the bottom of the portfolio page; only users who manage settings can change it. Data refreshes daily (`licensing:refresh`). Each Monday a savings summary goes to your alert channel when *Licence optimisation* is selected. Export the findings to CSV for the client, and offboard people straight from the list. Clients with savings of 100 or more per month appear under **Needs attention** on the portfolio.

## Intune & device management module

Go to **Intune > Intune bundles**. The module builds and deploys Zero Trust device baselines for Windows, iOS/iPadOS and Android.

- **Template library.** 29 templates, each with tunable settings, end-user impact, licence requirements, Zero Trust pillars and framework tags: NIST 800-53, NIST CSF 2.0, CIS Benchmarks, CIS Controls v8.1, HIPAA, ISO 27001, PCI DSS, Essential Eight and Cyber Essentials.
  - **Prerequisites**, verified automatically: Apple MDM push certificate, Managed Google Play, Windows automatic enrollment, Defender for Endpoint connector, Entra LAPS, and enrollment restrictions as a guided step.
  - **Windows:** Autopilot profile, compliance (BitLocker, Secure Boot, TPM, Defender, firewall, minimum build, optional Defender risk score), Defender Antivirus, BitLocker (XTS-AES 256, keys escrowed to Entra), firewall, 12 ASR rules (audit, warn or block), LAPS, Credential Guard and LSA protection, hardening (screen lock, SmartScreen), Windows Hello for Business, Pilot and Broad update rings, and a feature update pin.
  - **iOS/iPadOS:** compliance, corporate device restrictions, and app protection.
  - **Android:** work profile (BYOD) compliance and restrictions, fully managed compliance and restrictions, and app protection.
  - **App protection** follows Microsoft's data protection framework: Level 1 Enterprise basic, Level 2 enhanced, Level 3 high.
  - **Zero Trust Conditional Access:** require a compliant Windows device, require app protection on mobile, and block unsupported platforms. These are always created in report-only mode.
- **Bundles.** Combine any templates and set parameters and assignment per item: pilot group, all users, all devices, a named group, or none. Each bundle records its context: licensing plan, device ownership (corporate, BYOD or mixed), industry and platforms. Five prebuilt bundles are included:
  - SMB Business Premium
  - BYOD app protection only
  - Corporate-owned mobile
  - Healthcare / HIPAA high security
  - Windows hardening
- **Deploy.** Choose tenants. The page shows each tenant's licence fit. Run **Preview** for a dry run with no changes, then **Deploy**.
  - Previews and deployments run in a **background worker** (`intune:process`), so large bundles are not limited by the web request time. The run page shows live progress and you can leave it. Deployments stuck for 30 minutes are closed with a clear message, and a busy client waits its turn.
  - Policies are named `TG - …`. Re-runs never duplicate them, and policies you created yourself are never touched.
  - Turn on "update existing" to re-apply settings.
  - The `TenantsGuard Pilot` group is created on demand.
  - Templates the tenant isn't licensed for are skipped, not failed.
  - Prerequisites are reported as action items.
  - Every run is kept in the deployment history and the audit log.
- **Tenant Intune page.** Shows licences, devices by OS, non-compliant and personal devices, prerequisite status, TenantsGuard-managed policies, every Intune policy in the tenant, and the deployment history.

**Permissions.** The module needs extra Microsoft Graph permissions:
- `DeviceManagementConfiguration.ReadWrite.All`
- `DeviceManagementApps.ReadWrite.All`
- `DeviceManagementServiceConfig.ReadWrite.All`
- `Group.ReadWrite.All`
- the matching read permissions

For an app created by automatic setup, use **Settings > Microsoft app > Update app permissions**. For a manually registered app, add the permissions in Entra. Then click **Re-grant consent** on each tenant.

**RBAC.** There are three Intune permissions: `intune.view`, `intune.manage` (bundles) and `intune.deploy`. Administrators get all three. Technicians can view and deploy. Read-only users can view.

> Payloads follow the Microsoft Graph Intune schema and the documented CSPs, such as Defender, LAPS, ASR, DeviceGuard and PassportForWork. Before broad rollout, validate each bundle on a test tenant and the pilot group. Framework mappings are guidance, not a certification.

## Compliance frameworks and profiles

Go to **Security & Compliance > Control plans**.

| Framework | Publisher | How it is assessed |
|---|---|---|
| **Essential Eight** (Maturity Levels 1–3) | Australian Signals Directorate (ASD) | 37 curated requirements across the 8 strategies, each with a maturity level. Maturity is calculated per strategy. |
| **ASD ISM** (Microsoft 365 and endpoint guideline areas) | ASD | 28 requirements grouped by ISM guideline |
| **HIPAA Security Rule** | US HHS | 19 administrative, physical and technical safeguards (§164.308/310/312) |
| CIS Microsoft 365 Foundations, CIS Controls v8.1, CIS Benchmarks | CIS | Derived from control mappings |
| NIST CSF 2.0, NIST SP 800-53 r5 | NIST | Derived from control mappings |
| ISO/IEC 27001:2022 Annex A, PCI DSS v4.0, Cyber Essentials | ISO, PCI SSC, NCSC | Derived from control mappings |

Each requirement is linked to TenantsGuard **controls**: Microsoft 365 posture checks and Intune templates.
- **Automated status.** It comes from the latest scan, where a compensating-control annotation counts as met, and from the latest Intune deployment.
- **Manual evidence.** Requirements without an automated control, such as backups, application control or incident response, are answered by **attestation**: status, note, evidence link and review date. An attestation can also override an automated result.

### Control plans: choose, phase, apply, monitor

A **control plan**, for example "Contoso - CIS and HIPAA", is how you run a framework programme for a customer:

1. **Frameworks.** Pick one or more frameworks. Starting from a tenant (tenant page > Control plan > *New plan for this tenant*) pre-fills the name and assigns the plan.
2. **Controls, scope and phases.** TenantsGuard merges the frameworks into one de-duplicated list. Each control is tagged with every selected framework it satisfies, with requirement IDs on hover. Controls that serve more than one framework are marked **Shared**.
   - Filter by framework, shared or unique, type (automatic fix, Intune deployment, manual), phase or scope.
   - Switch controls out of scope with a reason.
   - Move controls between phases, singly or in bulk.
   - Choose the Intune assignment per control.
   Defaults:
   - Phase 1: foundations and quick wins
   - Phase 2: identity and access, with Conditional Access in report-only mode
   - Phase 3: device baseline for the pilot group
   - Phase 4: enforcement and high impact
   Phase names are editable.
3. **Apply and monitor** (tenant > Control plan).
   - Preview or apply a single phase, selected controls, or all phases at once.
   - Automatic fixes run in safe mode, so Conditional Access is never enforced from a plan.
   - Intune templates deploy with the plan's assignment. Manual items are listed with their remediation.
   - The user's permissions apply per control type. One rescan runs after each apply.
   - The page shows progress per phase and per framework, Essential Eight maturity, **drift** (controls this plan applied that no longer pass), and the full run history.

**Compliance profiles let you choose the controls.**
1. Pick frameworks. For Essential Eight, also pick the target maturity level.
2. TenantsGuard lists every control mapped to those frameworks. Untick the ones that don't apply to this customer and record why. Out-of-scope controls are ignored in framework results and, optionally, removed from the security score.
3. Assign the profile to tenants. A tenant has one profile.

Eight prebuilt profiles are included:
- Essential Eight ML1, ML2 + ASD ISM, and ML3
- HIPAA + NIST CSF + CIS v8
- CIS M365 + CIS v8 + NIST CSF
- Cyber Essentials + ISO 27001
- PCI DSS + CIS v8
- NIST 800-53 + CSF

You can duplicate any of them or build your own.

**Tenant scorecard.** Tenant page > **Compliance** shows the Essential Eight maturity matrix (strategy by ML1–3, with achieved against target), the percentage per framework, every requirement with its mapped controls and their live status, and attestation forms. The executive report and the Excel export include the compliance results.

**Permissions.** `compliance.manage` covers profiles and scope. `compliance.attest` covers attestations. Administrators have both. Technicians can attest. Read-only users can view.

> Mappings are expert guidance for MSP assessments, not a certification. ASD ISM requirements are grouped by guideline. Map them to ISM control IDs in your System Security Plan before formal assessment.

## Data security: Microsoft Purview programmes

Go to **Data security (Purview) > Purview programmes**. This module guides an administrator through a complete Purview rollout for one client, from requirements to operations. It covers sensitivity labels and information protection, data classification, DLP, retention and records, and insider risk management.

**Stages:** Requirements → Design → Foundations → Discover → Pilot → Production → Operate. A signed gate unlocks each next stage, and the gates are recorded with a name, a date and a note.

1. **Requirements.** A workshop questionnaire in eight sections:
   - organisation and compliance owner
   - regulations (Australian or NZ Privacy, GDPR, HIPAA, PCI DSS, SOX, CCPA, ISO 27001, Essential Eight, CMMC)
   - sensitive data
   - locations and sharing
   - protection approach
   - retention and records
   - insider risk concerns, with privacy approval
   - pilot members and length
2. **Design.** Generated from the requirements and the client's real licences, read from the service plans of `/subscribedSkus`.
   - **Labels:** Public, General, Confidential and Highly Confidential, with encryption sub-labels where licensed. Unencrypted partner sharing is kept when partners need access. Headers, watermarks and container settings (privacy, guests, sharing) are included, along with label recommendations by sensitive information type.
   - **Label policy:** a default label, a downgrade justification, and optional mandatory labelling.
   - **DLP:** one policy per data class (payment cards, health, personal identifiers, banking, credentials). They use the sensitive information types for the client's jurisdiction (AU, NZ, US, UK, EU, CA). Low and high volume rules follow the chosen response style. Teams and device DLP appear only when licensed.
   - **Auto-labelling:** service-side policies and trainable classifiers, when E5-level licensing exists.
   - **Retention:** a baseline policy, Teams retention, and a retention label schedule per record class with regional default periods. Disposition review is used when records management is licensed.
   - **Insider risk:** policy templates per concern, pseudonymisation, and a hard stop until legal and HR approval is recorded.
   - **Architecture decisions and licence gaps,** stated plainly.
   - Every value can be edited. The compliance owner approves the design, and approval is tied to a hash of the design, so any later change needs a new approval.
3. **Plan and checklist.** About 30 tasks across Foundations, Discover, Pilot, Production and Operate. Each task has:
   - why it matters, the exact steps and a portal link
   - the method: TenantsGuard, PowerShell, Purview portal, people/process, or gate
   - an owner, a due date (calculated from the start date and pilot length), evidence notes and a verification status
   - Required tasks can only be marked "not needed" with a written reason.
4. **Implement.**
   - **TenantsGuard (Graph):** checks licences, creates the pilot Microsoft 365 group with its members, and creates retention labels from the approved schedule.
   - **PowerShell:** per-phase Security & Compliance scripts generated from the approved design. They run the phases in order: labels and DLP in simulation, then pilot label and DLP policies and retention label publishing, then production rollout, enforcement, baseline retention and removal of the pilot policies.
   - Every script refuses the wrong tenant and is idempotent. It supports `-WhatIf`, writes a transcript, and skips sensitive information types that the tenant does not have.
   - TenantsGuard never holds compliance administrator rights. The administrator runs the scripts interactively with their own MFA.
5. **Verify.** A read-only verification script exports labels, label policies, DLP and auto-labelling modes, retention, audit and co-authoring settings. Uploading the export checks every task against the design and marks matching tasks as done. Exports from another tenant are rejected. Labels are also re-checked through Graph where permissions allow.
6. **Documents** for the client:
   - a printable **design document** covering scope, requirements, licensing, decisions, labels, DLP matrix, retention schedule, insider risk, timeline, RACI, risks and operating model
   - a **staff announcement email**
   - a **one-page label guide**

**Permissions.**
- `purview.view`: Read-only users, Technicians and Administrators.
- `purview.manage`: Administrators.
- `purview.approve`: the compliance owner role. Global Administrators by default; give it to a custom role for the client's approver.

**New Graph permissions to add** with Settings > Update app permissions, then Re-grant consent:
- `InformationProtectionPolicy.Read.All`, to read sensitivity labels.
- `RecordsManagement.ReadWrite.All`, to create retention labels.

> Retention periods and regulatory mappings are starting points. Confirm them with the client's legal counsel. Microsoft changes cmdlet parameters and sensitive information type names over time, so verify after every deployment.

## Microsoft 365 Copilot programmes

Go to **Microsoft 365 Copilot > Copilot programmes**. The module takes one client from assessment to adoption.

**Stages:** Requirements → Assess → Prepare → Pilot → Rollout → Adopt. Three sign-off gates sit between them: **Ready for pilot**, **Pilot exit** and **Rollout complete**.

Copilot only shows people what they can already open. Its main risk is therefore existing oversharing, which Copilot makes easy to find. That is why the Prepare phase is mostly about permissions.

1. **Requirements.** Sponsor and IT owner, seats planned, business goals and success targets (weekly active %, hours saved, satisfaction). Also the data protection approach:
   - **Strict:** Restricted SharePoint Search during the pilot, plus Restricted Content Discovery.
   - **Balanced:** Restricted Content Discovery on named sensitive sites, plus oversharing fixes.
   - **Standard:** oversharing fixes only.

   Then feature choices (web search, agents, Teams meeting Copilot with or without transcript, Copilot Chat for unlicensed users), works council consultation, champions and training, pilot members, and wave size.
2. **Assess** (live, read-only, through Graph). It produces a readiness score and lists blockers and items needing attention:
   - Copilot seats and eligible base licences.
   - Per-user mailbox, OneDrive, Teams, Office apps and usage location.
   - MFA enforcement and registration, plus the TenantsGuard posture and Secure Score.
   - SharePoint sharing level, public Teams and groups, guests and sensitivity labels.
   - Purview programme status, consultation status, and whether usage reports conceal user names.
3. **Prepare.** A prerequisite checklist:
   - licences, user services, update channel, network and WebSocket, MFA
   - oversharing reports (SharePoint Advanced Management), public groups, the default sharing link
   - Restricted Content Discovery and Restricted SharePoint Search
   - labels and DLP for the "Microsoft 365 Copilot" location, audit and retention of Copilot interactions, report privacy
   - Teams meeting policy, Copilot settings, consultation, and the change plan

   Checks that pass in the assessment complete their tasks automatically. "Ready for pilot" cannot be signed while blockers remain, unless a risk-acceptance note is recorded.
4. **Pilot.** TenantsGuard creates the "Copilot Pilot" group and assigns Copilot to pilot members. The pilot charter covers the kick-off, prompt library and baseline survey. Usage is reviewed weekly against the target, data exposure findings are tracked, and the pilot is evaluated against its success criteria.
5. **Rollout.** Waves are sized from the seat plan. The licence assignment tool filters by pilot, assigned, eligible, inactive or missing prerequisites. Restricted SharePoint Search is turned off after remediation. Training goes to each wave, and a champion community supports adoption.
6. **Adopt.** A monthly usage review and reclaim of licences unused for 30 days, a quarterly value report, oversharing review and agent review.

**Licence guard rails.**
- Nothing is assigned before "Ready for pilot", and only pilot members can be licensed before the pilot exit.
- Every user needs an eligible base licence and a usage location, and assignments never exceed the free seats.
- Assigning or removing licences needs a typed confirmation (`ASSIGN 3`), and every change is audited.

**Scripts** (SharePoint Online and Microsoft Teams PowerShell, run by an administrator) cover what Graph cannot:
- data access governance reports
- Restricted Content Discovery for the listed sites
- Restricted SharePoint Search on and off
- the default sharing link
- `Set-CsTeamsMeetingPolicy -Copilot`

Scripts refuse the wrong tenant (Teams connection) and support `-WhatIf`.

**Documents.** A readiness report, a pilot charter (success criteria, cohort, baseline and end surveys, prompt library for the chosen scenarios) and a staff announcement email.

**Permissions.**
- `copilot.view`: Read-only users, Technicians and Administrators.
- `copilot.manage`: Administrators.
- `copilot.approve`: the sponsor role. Global Administrators by default.

**New Graph permission:** `Reports.Read.All`, for Copilot usage. Add it with Settings > Update app permissions, then Re-grant consent. Licence assignment uses the existing `User.ReadWrite.All`, and making groups private uses `Group.ReadWrite.All`.

## PowerShell scripts (Tools)

Run approved PowerShell scripts against one client or any group of clients you choose. Go to **Tools > PowerShell scripts**.

**Workflow.**
1. **Write.** Someone with `scripts.manage` writes a script. They choose whether it is **read-only** or **makes changes**, and whether it connects Exchange Online. They can add typed parameters: text, number, yes/no, choice, email/UPN or GUID. Each save creates a new immutable version with a SHA-256 hash.
2. **Check.** Safety checks run on every save. Blocked items stop submission. Warnings are shown to the approver.
3. **Approve.** A second person with `scripts.approve` reviews the code, the safety findings and a diff against the approved version. Approval signs the hash together with every security setting.
4. **Run.** Pick clients by search or by tag, up to 50. Fill in the parameters and give a reason (and a ticket if you have one). Change scripts also need you to type `RUN <number of clients>` and a fresh authenticator code.
5. **Results.** Each client runs in turn in its own session. The run page updates live and shows each client's status, a results table, the log and errors. Results export to CSV or JSON. Failures raise an alert.

Six sample scripts ship with TenantsGuard: users without MFA, guest accounts, licence usage, admin role members, block sign-in and sign out a user, and mailboxes forwarding externally. They arrive **waiting for approval**, so even these need your review first.

**Writing scripts.**
- Scripts run once per client in PowerShell 7 and are already connected to that client.
- Use `Invoke-TGGraph -Uri '/users' -All` for Microsoft Graph. Add `-Method PATCH/POST/DELETE -Body @{...}` for changes and `-Beta` for the beta endpoint.
- `Get-TGTenant` returns the current client.
- Return results as objects. Use `Select-Object`, or `[ordered]@{ Name = ... }`, because `[pscustomobject]` is not available in Constrained Language Mode.
- With **Connect Exchange Online**, the EXO cmdlets are available.
- Demo clients return sample data, and changes in them are simulated, not applied.

### Security design

| Threat | Control |
|---|---|
| A malicious or careless script | Four-eyes approval (authors cannot approve their own work unless a Global Administrator allows self-approval for one-person teams). Linter blocks Invoke-Expression, Add-Type, Start-Process, web requests, remote sessions, jobs, file system, environment variables, Import-Module and Connect-*. |
| Script changed after approval (database tampering) | Approval signs the SHA-256 of the code plus risk, connections, time limit and parameters with an HMAC keyed by APP_KEY. Every run verifies it; a mismatch refuses the run, logs it and raises an alert. |
| Code reaching the TenantsGuard server | PowerShell Constrained Language Mode (no Add-Type, no arbitrary .NET or COM, cannot be switched back). Separate pwsh process per client: no profile, non-interactive, private temp folder deleted afterwards, scrubbed environment (APP_KEY, database and Microsoft app secrets are removed). |
| Token theft | An app-only token is fetched per run and passed over STDIN, never on the command line, in environment variables or on disk. It lives inside a private module and only `Invoke-TGGraph` uses it, with relative Graph paths and a call budget. Tokens, bearer headers, JWTs and the app secret are redacted from all stored output. |
| A read-only script that writes | `Invoke-TGGraph` refuses write methods. Exchange is connected with Get- cmdlets only. The Microsoft Graph PowerShell module is only offered to change scripts. |
| Too much power for staff | `scripts.view`, `scripts.run` (read-only scripts), `scripts.run_change`, `scripts.manage` and `scripts.approve` are separate permissions. Technicians get view and run; Administrators can also run change scripts; only Global Administrators write and approve by default. Users only see and target their assigned clients, and a shared run shows each person only their own clients. |
| Change scripts started by a stolen session | A fresh, single-use authenticator code and typed confirmation for every change run. Run submission is rate-limited. |
| Runaway scripts | Per-client time limit (default 5 minutes, maximum 30), output cap (512 KB), Graph call budget (2000), max 50 clients per run, cancel button, and crash recovery for workers that die. One script per client at a time (shared tenant lock). |
| Data exposure in results | Output is escaped in the UI; CSV export neutralises spreadsheet formulas; output is deleted after 90 days (TG_SCRIPTS_RETENTION_DAYS) while the audit record stays. |
| Unknown activity | Activity log records who saved, submitted, approved, ran, cancelled and exported, with the code hash, parameters, reason, IP address and outcome. |
| Emergency | Kill switch: a Global Administrator can switch execution off; queued work then stops too. Execution is **off by default**. |

**Hardening the server (recommended for production).**
- Run TenantsGuard and its worker under a dedicated, low-privilege Windows account, not an administrator or SYSTEM.
- Restrict outbound traffic from `pwsh.exe` with Windows Firewall to `graph.microsoft.com`, `login.microsoftonline.com` and `outlook.office365.com`.
- Optionally enforce Constrained Language Mode system-wide with App Control for Business (WDAC) or AppLocker.
- Keep PowerShell 7 and the Microsoft.Graph.Authentication and ExchangeOnlineManagement modules up to date. Install them for all users.
- For many clients or heavy use, run the worker on a separate host.

**Settings.**

| `.env` key | Purpose |
|---|---|
| `TG_PWSH` | Path to pwsh.exe. Auto-detected when empty. |
| `TG_SCRIPTS_DISPATCH` | `spawn` (default): start a background worker immediately. `scheduler`: wait for `schedule:run`. |
| `TG_SCRIPTS_MAX_TENANTS`, `TG_SCRIPTS_OUTPUT_KB`, `TG_SCRIPTS_RETENTION_DAYS` | Limits and retention. |

The scheduler also runs `scripts:process` every minute as a safety net.

**Exchange Online scripts** need extra one-time setup per client:
1. Add the **Office 365 Exchange Online > Exchange.ManageAsApp** application permission to the TenantsGuard app and re-grant consent.
2. Assign the app's enterprise application the **Exchange Administrator** role, or a narrower Exchange role, in the client tenant.

## Permission scope (least privilege per client)

TenantsGuard's permissions are organised as **capabilities**, not one long permission list. Each capability is a small set of Microsoft Graph or Exchange Online application permissions, in one of three tiers:

| Tier | Capabilities | Meaning |
|---|---|---|
| Read | Security monitoring (always on), Intune read, Threat alerts and service health | Sees configuration and reports. Changes nothing. |
| Write | Security configuration, Intune management, Groups, User lifecycle, Licence assignment, Records management | Changes the configuration of one workload. |
| Privileged (Tier 0) | Privileged operations, Exchange Online | Can change admin roles or authentication methods, wipe devices, or run Exchange as the app. |

Three layers decide what TenantsGuard can do in a client:

1. **App plan** (*Monitor > Permission scope*, Global Administrator only). These are the capabilities the app registration requests at all. *Settings > Update app permissions* applies the plan to the app registration. Exchange Online is opt-in and adds `Exchange.ManageAsApp` on the Office 365 Exchange Online resource.
2. **Access profile** (*client > Access and permissions*). This is what TenantsGuard may do in this client. Presets are **Read-only monitoring**, **Standard management** (no Tier 0) and **Full** (follows the app plan), or you can choose capabilities one by one.
   - Every Graph write passes this policy first, for real and demo clients alike.
   - Writes to paths that are not mapped to a capability are refused (**default deny**).
   - Change scripts cannot run in a read-only client, and Exchange scripts need the Exchange capability.
   - New clients start on **Standard**. Clients added before this feature keep **Full** until you change them.
3. **Client grant**. These are the permissions the client actually granted. They are read from the `roles` claim of a fresh app-only token, which needs no extra permission and changes nothing. The page shows *Granted*, *Partly granted*, *Not granted* and **Granted, not used** (over-granted) for each capability.

**Granting with least privilege.** On a client's *Access and permissions* page, use **Download grant script (.ps1)**. A Global Administrator of the client runs it (`-WhatIf` previews it first). The script:
- grants exactly the enabled capabilities;
- **revokes** any other Graph and Exchange permissions the app holds (`-KeepOthers` only adds);
- checks that the signed-in tenant is the right one, and is idempotent.

It needs only `Microsoft.Graph.Authentication`. For Exchange Online, add `-AssignExchangeRole`, or better, use Exchange RBAC for applications. **Full admin consent** is still available as the quick path, but it grants the whole app plan.

**Tier 0 controls.**
- Only a Global Administrator can enable a privileged capability, and must give a written justification.
- A time limit (1, 7, 30 or 90 days) can be set. When it expires, TenantsGuard stops using the capability by itself.
- Every profile change, plan change, grant check and script download is written to the audit log.

**Portfolio view.** *Monitor > Permission scope* shows a clients × capabilities matrix, the number of clients with Tier 0 enabled, over-granted clients, the app secret expiry, and recommendations:
- a certificate instead of a secret;
- Conditional Access for workload identities;
- GDAP for technicians;
- quarterly review.

## Multi-factor authentication (mandatory)

Every TenantsGuard account must use an authenticator app, whatever its role (Global Administrator, Administrator, Technician, Read-only or custom).

- **Two steps.** Password or "Sign in with Microsoft" is only the first factor. The session is signed in only after a 6-digit code from Microsoft Authenticator, Google Authenticator or any TOTP app (RFC 6238).
- **First sign-in.** The user is required to scan a QR code, confirm a code, and save 10 one-time recovery codes.
- **Existing sessions.** Sessions created before MFA, or restored from "remember me", are challenged again.
- **Protections:**
  - Codes are single-use (no replay) and accept ±30 s clock drift.
  - A pending sign-in is dropped after 5 wrong codes or 10 minutes.
  - Rate limits apply separately to login and MFA.
  - Secrets are encrypted at rest. Recovery codes are stored as bcrypt hashes.
  - All failures and resets are written to the audit log.
- **Self-service (My account).** Users can generate new recovery codes and move MFA to a new phone. The old app keeps working until the new one is confirmed.
- **Named devices.** Each registration records a device name, such as "Megan's iPhone 15". It is shown on My account, the Users list and the user's edit page.
- **Lost or stolen phone, removed by an admin.** A Global Administrator, or anyone with `users.manage`, clicks **Remove MFA device** on the Users page or the user's edit page. Codes from the old phone stop working immediately, the user is signed out of every session ("remember me" cookies included), and they must register a new device at their next sign-in.
- **Lost phone, self-service.** On **My account > Lost your phone?**, the user confirms with their password or a recovery code instead of the app. The old device is removed, other sessions are signed out, and they register the new phone straight away. They cannot use TenantsGuard until a new device is registered.

## Users, roles and tenant access

Go to **Admin > Users** to add staff. Each user has a **role**, which sets what they can do, and **tenant access**, which is either all tenants or only selected tenants.

| Built-in role | Can do |
|---|---|
| **Global Administrator** | Everything, including settings, the Microsoft app, users and roles. Always sees all tenants. |
| **Administrator** | Add and remove tenants, scan, fix, enforce Conditional Access, manage and apply baselines, annotate, and view reports and the audit log. |
| **Technician** | Scan, apply quick fixes and baseline items, create report-only Conditional Access policies, annotate, and view reports. Cannot enforce Conditional Access or add or remove tenants. |
| **Read-only** | View findings, reports and the audit log. Cannot make changes. |

- **Admin > Roles & permissions** shows the full permission matrix. Global Administrators can edit built-in roles, except Global Administrator, and create custom roles.
- Users limited to selected tenants never see other tenants. Those tenants are hidden from the dashboard, baselines, reports and the activity log, and direct links return 403.
- A scoped user keeps access to tenants they add themselves.
- Safeguards:
  - The last active Global Administrator cannot be demoted, disabled or deleted.
  - You cannot change your own role.
  - Only Global Administrators can edit Global Administrators or roles.
  - Anyone else who manages users can only assign roles within their own permissions.
- Disabling a user ends their session immediately.
- Users who sign in with Microsoft for the first time from an allowed tenant get the role chosen under **Settings > Sign in with Microsoft**. The default is Read-only.

## Controls not available through Graph

Some settings are only available in Exchange Online PowerShell. Examples are unified audit logging and external sender tagging. These controls are marked **Manual review** with the PowerShell command to verify them. Record the outcome as an annotation.

## Framework mappings

Framework references are best-effort mappings maintained in `app/Services/Checks/Catalog.php`.
Before you present them as formal compliance evidence, validate them against the exact benchmark versions you report on, such as the current CIS Microsoft 365 Foundations Benchmark.

## Project layout

See the [project structure](../README.md#project-structure) in the README.

### Adding a control

Add an entry to `Catalog::definitions()` with `id`, `title`, `category`, `severity`, `license`, `permission`, `frameworks`, `description`, `remediation`, a `run` closure that returns `self::r(status, summary, evidence, affected, fix)`, and an optional `fix` closure.
If the control uses a new Graph endpoint, also add that endpoint to `MockGraphClient` so the demo tenant and the tests can evaluate it.

## Scoring

Severity weights are critical 10, high 6, medium 3, and low 1. The score is the passed weight divided by the sum of passed and failed weights. Manual, not-applicable, error, and info results are excluded. Failures annotated as *Risk accepted* or *Compensating control* are also excluded.
