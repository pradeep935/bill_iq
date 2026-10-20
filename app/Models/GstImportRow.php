<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GstImportRow extends Model
{
    protected $fillable = [
        'gst_import_batch_id',
        'row_number',
        'section',
        'source_key',
        'counterparty_gstin',
        'document_number',
        'document_date',
        'hsn_code',
        'taxable_value',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'cess_amount',
        'total_value',
        'severity',
        'messages_json',
        'payload_json',
        'duplicate_key',
    ];

    protected $casts = [
        'document_date' => 'date',
        'messages_json' => 'array',
        'payload_json' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(GstImportBatch::class, 'gst_import_batch_id');
    }
}
