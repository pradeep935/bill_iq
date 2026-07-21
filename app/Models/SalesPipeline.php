<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesPipeline extends Model
{
    use SoftDeletes;
    protected $table = 'sales_pipelines';
    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];
    public function stages() { return $this->hasMany(PipelineStage::class)->orderBy('stage_order'); }
}
