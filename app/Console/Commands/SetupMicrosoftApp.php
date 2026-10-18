<?php

namespace App\Console\Commands;

use App\Services\Microsoft\AppProvisioner;
use App\Support\MicrosoftApp;
use Illuminate\Console\Command;
use Throwable;

class SetupMicrosoftApp extends Command
{
    protected $signature = 'tenantsguard:setup-app {--read-only : Only add read permissions (no quick fixes)} {--force : Create a new app even if one is configured}';

    protected $description = 'Create the TenantsGuard multi-tenant Entra app registration by signing in once with Microsoft (device code).';

    public function handle(): int
    {
        if (MicrosoftApp::configured() && ! $this->option('force')) {
            $this->warn('A Microsoft app is already configured ('.MicrosoftApp::clientId().'). Use --force to create another one.');

            return self::SUCCESS;
        }

        try {
            $dc = AppProvisioner::startDeviceCode();
            $this->newLine();
            $this->line("  Open <info>{$dc['verification_uri']}</info> and enter the code <comment>{$dc['user_code']}</comment>");
            $this->line('  Sign in as a Global Administrator of your own (MSP) tenant and accept.');
            $this->newLine();

            $deadline = time() + $dc['expires_in'];
            $interval = $dc['interval'];
            while (time() < $deadline) {
                sleep($interval);
                $poll = AppProvisioner::pollDeviceCode($dc['device_code']);
                if ($poll['status'] === 'slow_down') {
                    $interval += 5;
                }
                if ($poll['status'] === 'done') {
                    $result = AppProvisioner::provision($poll['token'], ! $this->option('read-only'));
                    $this->info("Created app {$result['client_id']} with {$result['permissions']} permissions in tenant {$result['tenant']}.");
                    $this->line('Redirect URIs: '.implode(', ', MicrosoftApp::redirectUris()));

                    return self::SUCCESS;
                }
            }
            $this->error('The sign-in code expired.');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        return self::FAILURE;
    }
}
