<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstImportBatch extends Model
{
    protected $fillable = [
        'business_id',
        'company_id',
        'source_type',
        'status',
        'original_filename',
        'storage_path',
        'file_hash',
        'source_gstin',
        'return_period',
        'detected_legal_name',
        'totals_json',
        'validation_json',
        'summary_json',
        'uploaded_by',
        'approved_by',
        'approved_at',
        'committed_at',
    ];

    protected $casts = [
        'totals_json' => 'array',
        'validation_json' => 'array',
        'summary_json' => 'array',
        'approved_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(GstImportRow::class);
    }

    public function reconciliationItems()
    {
        return $this->hasMany(GstReconciliationItem::class);
    }
}
