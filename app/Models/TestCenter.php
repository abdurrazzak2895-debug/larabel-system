<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of the test centers served by the real SVP / Takamol API.
 *
 * Seeded from the official test-center dataset and kept in sync through the
 * "Sync from SVP API" action in the admin panel (which calls the live
 * /individual_labor_space/exam_sessions endpoint through TakamolProvider).
 */
class TestCenter extends Model
{
    protected $fillable = [
        'svp_id',
        'name',
        'city',
        'country_code',
    ];

    protected $casts = [
        'svp_id' => 'string',
    ];
}
