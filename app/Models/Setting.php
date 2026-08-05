<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'key';

    protected $fillable = ['key', 'value', 'agency_id'];

    public function scopeForAgency($query, ?int $agencyId)
    {
        return $query->where('agency_id', $agencyId);
    }
}