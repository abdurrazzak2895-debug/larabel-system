<?php

namespace App\Console\Commands;

use App\Services\T2Hub\T2HubClient;
use App\Services\T2Hub\T2HubProvider;
use App\Services\T2Hub\T2HubSessionStore;
use Illuminate\Console\Command;

/**
 * Manage and verify the T2Hub agent session used by the booking flow.
 *
 *   php artisan t2hub:session status
 *   php artisan t2hub:session refresh
 *   php artisan t2hub:session probe            # occupations + a live chain probe
 *   php artisan t2hub:session probe --city=Dhaka --date=2026-10-05
 */
class T2HubSessionCommand extends Command
{
    protected $signature = 't2hub:session
        {action=status : status | refresh | probe | forget}
        {--city= : city used by the probe}
        {--date= : exam date (Y-m-d) used by the probe}
        {--occupation= : occupation/category id used by the probe}';

    protected $description = 'Inspect, refresh or probe the T2Hub agent session';

    public function handle(T2HubClient $client, T2HubProvider $provider, T2HubSessionStore $store): int
    {
        $action = (string) $this->argument('action');

        $this->line('base_url:   ' . $client->baseUrl() . $client->appPath());
        $this->line('credentials: ' . ($client->configured() ? 'configured' : 'MISSING (T2HUB_EMAIL / T2HUB_PASSWORD)'));

        if ($action === 'forget') {
            $store->forget();
            $this->info('Stored session removed.');

            return self::SUCCESS;
        }

        if ($action === 'refresh') {
            try {
                $client->login();
                $this->info('Session refreshed.');
            } catch (\Throwable $e) {
                $this->error('Refresh failed: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->line('session:    ' . json_encode($store->summary()));

        if ($action !== 'probe') {
            return self::SUCCESS;
        }

        try {
            $occupations = $provider->occupations();
            $this->info('occupations: ' . count($occupations));
            $first = $occupations[0] ?? null;

            if ($first !== null) {
                $this->line('  first: ' . json_encode(array_intersect_key($first, array_flip([
                    'id', 'category_id', 'occupation_id', 'name', 'name_en',
                ]))));
            }

            $categoryId = (string) ($this->option('occupation') ?: ($first['category_id'] ?? $first['id'] ?? ''));
            $city = (string) ($this->option('city') ?: 'Dhaka');
            $date = (string) ($this->option('date') ?: now()->addDays(3)->toDateString());

            if ($categoryId === '') {
                $this->warn('No category id available for the probe.');

                return self::SUCCESS;
            }

            $this->line("probe: category={$categoryId} city={$city} date={$date}");
            $result = $provider->sessions($categoryId, $city, $date);
            $this->info('sessions: ' . count($result['sessions']) . ' · centres with sessions: ' . count($result['sites']));

            foreach (array_slice($result['sessions'], 0, 5) as $session) {
                $this->line(sprintf(
                    '  %s | %s | seats=%s | centre=%s',
                    (string) ($session['session_date'] ?? $session['exam_date'] ?? $date),
                    (string) ($session['session_time'] ?? $session['start_time'] ?? '—'),
                    (string) ($session['available_seats'] ?? $session['seats'] ?? '—'),
                    (string) ($session['test_center']['name'] ?? $session['center_name'] ?? '—'),
                ));
            }
        } catch (\Throwable $e) {
            $this->error('Probe failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
