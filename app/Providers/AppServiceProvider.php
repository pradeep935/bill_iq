<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Observers\AuditLogObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;

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
        if (config('app.force_root_url') && config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        $this->registerReadableValidationMessages();

        foreach (File::files(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (!class_exists($class) || $class === AuditLog::class || !is_subclass_of($class, Model::class)) {
                continue;
            }

            $class::observe(AuditLogObserver::class);
        }
    }

    private function registerReadableValidationMessages(): void
    {
        $labels = [
            'id' => 'record',
            'branch_id' => 'branch',
            'warehouse_id' => 'warehouse',
            'source_branch_id' => 'source branch',
            'source_warehouse_id' => 'source warehouse',
            'destination_branch_id' => 'destination branch',
            'destination_warehouse_id' => 'destination warehouse',
            'product_id' => 'product',
            'customer_id' => 'customer',
            'supplier_id' => 'supplier',
            'account_id' => 'account',
            'employee_id' => 'employee',
            'category_id' => 'category',
            'brand_id' => 'brand',
            'unit_id' => 'unit',
            'hsn_master_id' => 'HSN/SAC',
            'sku' => 'SKU',
            'gstin' => 'GSTIN',
            'pan' => 'PAN',
        ];

        $fieldLabel = function (string $attribute) use ($labels): string {
            $attribute = preg_replace('/\.\d+\./', ' row ', $attribute);
            $attribute = preg_replace('/\.\d+$/', '', $attribute);
            $attribute = str_replace('.', ' ', $attribute);
            $lastPart = collect(explode(' ', $attribute))->last();

            return $labels[$lastPart] ?? str_replace('_', ' ', $lastPart ?: $attribute);
        };

        Validator::replacer('required', fn ($message, $attribute) => 'Please enter/select ' . $fieldLabel($attribute) . '.');
        Validator::replacer('required_with', fn ($message, $attribute) => 'Please enter/select ' . $fieldLabel($attribute) . '.');
        Validator::replacer('integer', fn ($message, $attribute) => 'Please select a valid ' . $fieldLabel($attribute) . '.');
        Validator::replacer('numeric', fn ($message, $attribute) => ucfirst($fieldLabel($attribute)) . ' must be a valid number.');
        Validator::replacer('date', fn ($message, $attribute) => 'Please enter a valid date for ' . $fieldLabel($attribute) . '.');
        Validator::replacer('exists', fn ($message, $attribute) => 'Selected ' . $fieldLabel($attribute) . ' was not found.');
        Validator::replacer('unique', fn ($message, $attribute) => ucfirst($fieldLabel($attribute)) . ' already exists. Please use a different value.');
        Validator::replacer('in', fn ($message, $attribute) => 'Please select a valid ' . $fieldLabel($attribute) . '.');
        Validator::replacer('min', fn ($message, $attribute) => ucfirst($fieldLabel($attribute)) . ' is below the allowed minimum.');
        Validator::replacer('max', fn ($message, $attribute) => ucfirst($fieldLabel($attribute)) . ' is above the allowed maximum.');
        Validator::replacer('gt', fn ($message, $attribute) => ucfirst($fieldLabel($attribute)) . ' must be greater than zero.');
    }
}
