<?php

namespace App\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class AuditLogObserver
{
    public function created(Model $model): void
    {
        AuditLogger::logModel($model, 'Create', AuditLogger::changesForCreate($model));
    }

    public function updated(Model $model): void
    {
        AuditLogger::logModel($model, 'Update', AuditLogger::changesForUpdate($model));
    }

    public function deleted(Model $model): void
    {
        AuditLogger::logModel($model, 'Delete', AuditLogger::changesForDelete($model));
    }
}
