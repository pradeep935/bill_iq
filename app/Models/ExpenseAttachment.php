<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseAttachment extends Model
{
    protected $table = 'expense_attachments';
    protected $guarded = [];

    public function voucher() { return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
