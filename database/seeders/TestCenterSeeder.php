<?php

namespace Database\Seeders;

use App\Models\TestCenter;
use Illuminate\Database\Seeder;

/**
 * Seeds the official SVP / Takamol test-center dataset.
 *
 * Source data (test_center_id, test_center_name, city) provided by the client:
 *   svp_test_centers_3_columns (1).csv
 *
 * The seeder is idempotent — running it multiple times updates names/cities
 * in place and never duplicates rows.
 */
class TestCenterSeeder extends Seeder
{
    /**
     * @var array<int, array{svp_id: string, name: string, city: string, country_code: string}>
     */
    public const TEST_CENTERS = [
        ['svp_id' => '2',    'name' => 'Mymensingh Technical Training Centre',    'city' => 'Mymensingh',   'country_code' => 'BD'],
        ['svp_id' => '3',    'name' => 'Bangladesh Korea TTC Chattogram',         'city' => 'Chattogram',   'country_code' => 'BD'],
        ['svp_id' => '17',   'name' => 'Bangladesh Korea TTC Dhaka',              'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '45',   'name' => 'Bangladesh German TTC',                   'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '53',   'name' => 'Bangladesh Korea TTC Chattogram',         'city' => 'Chattogram',   'country_code' => 'BD'],
        ['svp_id' => '54',   'name' => 'Rajshahi Technical Training Centre',      'city' => 'Rajshahi',     'country_code' => 'BD'],
        ['svp_id' => '60',   'name' => 'Technical Training Centre, Barishal',     'city' => 'Barishal',     'country_code' => 'BD'],
        ['svp_id' => '62',   'name' => 'Cumilla Technical Training Centre',       'city' => 'Cumilla',      'country_code' => 'BD'],
        ['svp_id' => '71',   'name' => 'Sylhet Technical Training Center',        'city' => 'Sylhet',       'country_code' => 'BD'],
        ['svp_id' => '78',   'name' => 'Technical Training Center TTC',           'city' => 'Nilphamari',   'country_code' => 'BD'],
        ['svp_id' => '107',  'name' => 'Bogura Technical Training Centre',        'city' => 'Rajshahi',     'country_code' => 'BD'],
        ['svp_id' => '115',  'name' => 'BRTC Central Training Institute Gazipur', 'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '124',  'name' => 'Technical Training Centre (TTC), Bogura', 'city' => 'Rajshahi',     'country_code' => 'BD'],
        ['svp_id' => '134',  'name' => 'Technical Training Center, Rajshahi',     'city' => 'Rajshahi',     'country_code' => 'BD'],
        ['svp_id' => '156',  'name' => 'Khulna Technical Training Centre',        'city' => 'Khulna',       'country_code' => 'BD'],
        ['svp_id' => '166',  'name' => 'Faridpur Technical Training Centre',      'city' => 'Barishal',     'country_code' => 'BD'],
        ['svp_id' => '168',  'name' => 'Chapainawabganj Technical Training Centre', 'city' => 'Rajshahi',   'country_code' => 'BD'],
        ['svp_id' => '171',  'name' => 'Jashore Technical Training Centre',       'city' => 'Khulna',       'country_code' => 'BD'],
        ['svp_id' => '174',  'name' => 'Brahmanbaria Technical Training Centre',  'city' => 'Cumilla',      'country_code' => 'BD'],
        ['svp_id' => '180',  'name' => 'Madaripur Technical Training Centre',     'city' => 'Barishal',     'country_code' => 'BD'],
        ['svp_id' => '181',  'name' => 'Narail Technical Training Centre',        'city' => 'Khulna',       'country_code' => 'BD'],
        ['svp_id' => '201',  'name' => 'Pabna Technical Training Centre',         'city' => 'Rajshahi',     'country_code' => 'BD'],
        ['svp_id' => '203',  'name' => 'Noakhali Technical Training Centre',      'city' => 'Cumilla',      'country_code' => 'BD'],
        ['svp_id' => '216',  'name' => 'Tangail Technical Training Center',       'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '218',  'name' => 'Narsingdi Technical Training Center',     'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '220',  'name' => 'Kishoreganj Technical Training Centre',   'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '221',  'name' => 'Shariatpur Technical Training Centre',    'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '223',  'name' => 'Manikganj Technical Training Center',     'city' => 'Dhaka',        'country_code' => 'BD'],
        ['svp_id' => '240',  'name' => 'Patuakhali Technical Training Centre',    'city' => 'Barishal',     'country_code' => 'BD'],
        ['svp_id' => '265',  'name' => 'Joypurhat Technical Training Center',     'city' => 'Rajshahi',     'country_code' => 'BD'],
    ];

    public function run(): void
    {
        $count = 0;

        foreach (self::TEST_CENTERS as $center) {
            TestCenter::updateOrCreate(
                ['svp_id' => $center['svp_id']],
                [
                    'name'         => $center['name'],
                    'city'         => $center['city'],
                    'country_code' => $center['country_code'],
                ]
            );
            $count++;
        }

        $this->command?->info("Seeded {$count} SVP test centers.");
    }
}
