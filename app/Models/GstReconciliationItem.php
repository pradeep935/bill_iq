<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstReconciliationItem extends Model
{
    protected $fillable = [
        'gst_import_batch_id',
        'category',
        'status',
        'expected_amount',
        'actual_amount',
        'difference_amount',
        'details_json',
    ];

    protected $casts = [
        'details_json' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(GstImportBatch::class, 'gst_import_batch_id');
    }
}
