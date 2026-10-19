<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends alerts to a Microsoft Teams (Workflows), Slack or generic JSON webhook.
 * Never throws: a broken webhook must not break scans or deployments.
 */
class Notifier
{
    public const EVENTS = [
        'drift' => 'Drift detected (a control that passed now fails)',
        'scan_failed' => 'Scheduled or manual scan failed',
        'change_failed' => 'A fix, control plan or Intune deployment had failures',
        'consent' => 'Admin consent failed or tenant access was lost',
        'script' => 'PowerShell scripts: waiting for approval, failed runs, integrity alerts',
        'security_alert' => 'Security monitoring: new critical or high alerts (role changes, risky users, Defender alerts, break-glass use)',
        'licensing' => 'Licence optimisation: weekly summary of potential savings',
    ];

    public const FORMATS = ['teams' => 'Microsoft Teams (Workflows webhook)', 'slack' => 'Slack incoming webhook', 'generic' => 'Generic JSON (PSA, n8n, Power Automate…)'];

    public static function config(): array
    {
        $events = json_decode((string) Setting::find('notify_events')?->value, true);

        return [
            'url' => (string) Setting::find('notify_webhook_url')?->value,
            'format' => Setting::find('notify_format')?->value ?: 'teams',
            'events' => is_array($events) ? $events : array_keys(self::EVENTS),
        ];
    }

    public static function send(string $event, string $title, string $text, ?Tenant $tenant = null, bool $force = false): bool
    {
        $cfg = self::config();
        if ($cfg['url'] === '' || (! $force && ! in_array($event, $cfg['events'], true))) {
            return false;
        }
        $brand = Setting::branding()['company_name'];
        $link = $tenant ? route('tenants.show', $tenant) : route('dashboard');
        $heading = ($tenant ? "[{$tenant->name}] " : '').$title;

        $payload = match ($cfg['format']) {
            'slack' => ['text' => "*{$brand}: {$heading}*\n{$text}\n<{$link}|Open in {$brand}>"],
            'generic' => ['source' => $brand, 'event' => $event, 'title' => $title, 'text' => $text, 'tenant' => $tenant?->only(['id', 'name', 'ms_tenant_id']), 'url' => $link, 'at' => now()->toIso8601String()],
            default => ['type' => 'message', 'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $brand, 'size' => 'Small', 'isSubtle' => true],
                        ['type' => 'TextBlock', 'text' => $heading, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                        ['type' => 'TextBlock', 'text' => $text, 'wrap' => true],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => $link]],
                ],
            ]]],
        };

        try {
            return Http::timeout(6)->post($cfg['url'], $payload)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
