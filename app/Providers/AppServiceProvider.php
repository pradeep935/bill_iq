<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (!class_exists($class) || $class === AuditLog::class || !is_subclass_of($class, Model::class)) {
                continue;
            }

            $class::observe(AuditLogObserver::class);
        }
    }
}
