<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AuditLogger
{
    public static function logModel(Model $model, string $actionType, array $changes = []): void
    {
        if ($model instanceof AuditLog || empty($changes)) {
            return;
        }

        self::record([
            'module_name' => self::moduleName($model),
            'record_id' => $model->getKey(),
            'action_type' => $actionType,
            'changes' => $changes,
            'client_id' => $model->getAttribute('client_id'),
        ]);
    }

    public static function record(array $payload): void
    {
        try {
            $changes = self::normaliseChanges($payload['changes'] ?? []);

            if (empty($changes)) {
                $changes[] = [
                    'field_name' => $payload['field_name'] ?? 'record',
                    'old_value' => $payload['old_value'] ?? null,
                    'new_value' => $payload['new_value'] ?? null,
                ];
            }

            $firstChange = $changes[0] ?? [];
            $user = self::currentUser();

            AuditLog::withoutEvents(function () use ($payload, $changes, $firstChange, $user) {
                AuditLog::create(self::payloadForCurrentSchema($payload, $changes, $firstChange, $user));
            });
        } catch (Throwable $exception) {
            return;
        }
    }

    public static function changesForCreate(Model $model): array
    {
        return self::changesFromArrays([], $model->getAttributes());
    }

    public static function changesForUpdate(Model $model): array
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $newValue) {
            if (self::ignoredField($field)) {
                continue;
            }

            $oldValue = $model->getOriginal($field);

            if (self::valueToString($oldValue) === self::valueToString($newValue)) {
                continue;
            }

            $changes[] = [
                'field_name' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ];
        }

        return $changes;
    }

    public static function changesForDelete(Model $model): array
    {
        return self::changesFromArrays($model->getOriginal(), []);
    }

    private static function changesFromArrays(array $old, array $new): array
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changes = [];

        foreach ($fields as $field) {
            if (self::ignoredField($field)) {
                continue;
            }

            $changes[] = [
                'field_name' => $field,
                'old_value' => $old[$field] ?? null,
                'new_value' => $new[$field] ?? null,
            ];
        }

        return $changes;
    }

    private static function normaliseChanges(array $changes): array
    {
        return array_values(array_map(function ($change) {
            return [
                'field_name' => $change['field_name'] ?? 'record',
                'old_value' => $change['old_value'] ?? null,
                'new_value' => $change['new_value'] ?? null,
            ];
        }, $changes));
    }

    private static function currentUser(): ?object
    {
        if (Auth::check()) {
            return Auth::user();
        }

        $apiToken = Request::header('apiToken');

        if (!$apiToken) {
            return null;
        }

        return DB::table('users')
            ->select('id', 'name', 'role_id', 'tenant_id')
            ->where('api_token', $apiToken)
            ->where('is_active', 1)
            ->first();
    }

    private static function payloadForCurrentSchema(array $payload, array $changes, array $firstChange, ?object $user): array
    {
        $columns = Schema::getColumnListing('audit_logs');
        $action = $payload['action_type'] ?? 'Update';
        $module = $payload['module_name'] ?? 'System';
        $recordId = isset($payload['record_id']) ? (string) $payload['record_id'] : null;
        $businessId = $payload['business_id'] ?? $payload['client_id'] ?? $payload['tenant_id'] ?? session('business_id') ?? session('tenant_id') ?? ($user->tenant_id ?? null);

        if (in_array('module_name', $columns, true)) {
            return array_filter([
                'client_id' => $businessId,
                'module_name' => $module,
                'record_id' => $recordId,
                'action_type' => $action,
                'field_name' => $firstChange['field_name'] ?? null,
                'old_value' => self::valueToString($firstChange['old_value'] ?? null),
                'new_value' => self::valueToString($firstChange['new_value'] ?? null),
                'changes' => $changes,
                'changed_by_user_id' => $user->id ?? null,
                'changed_by_name' => $user->name ?? null,
                'user_role' => self::roleName($user->role_id ?? null),
                'ip_address' => Request::ip(),
                'summary' => $payload['summary'] ?? self::summary($action, $changes),
            ], fn ($value) => $value !== null);
        }

        return array_filter([
            'tenant_id' => $businessId,
            'actor_id' => $user->id ?? null,
            'module' => $module,
            'action' => $action,
            'record_type' => $payload['record_type'] ?? $module,
            'record_id' => $recordId,
            'before_data' => collect($changes)->pluck('old_value', 'field_name')->all(),
            'after_data' => collect($changes)->pluck('new_value', 'field_name')->all(),
            'ip_address' => Request::ip(),
        ], fn ($value) => $value !== null);
    }

    private static function moduleName(Model $model): string
    {
        $class = class_basename($model);

        $map = [
            'User' => 'Staff',
            'UserDetail' => 'Staff',
            'PlayerReport' => 'Report',
            'PlayerTeamReport' => 'Team Report',
            'RecruitmentTracker' => 'Recruitment',
            'RecruitmentPosition' => 'Recruitment',
            'RecruitedPlayer' => 'Recruitment',
            'CalendarEvent' => 'Calendar',
            'ShadowTeam' => 'Shadow Team',
            'Sponsorship' => 'Sponsorship',
            'TeamAthlete' => 'Team Athlete',
            'Shortlist' => 'Shortlist',
            'NationalTeam' => 'National Team',
        ];

        return $map[$class] ?? Str::headline($class);
    }

    private static function roleName($privilege): ?string
    {
        if ($privilege === null) {
            return null;
        }

        return match ((int) $privilege) {
            1 => 'Super Admin',
            2 => 'Business Admin',
            3 => 'User',
            default => 'User',
        };
    }

    private static function summary(string $actionType, array $changes): string
    {
        $count = count($changes);

        if ($actionType === 'Update') {
            return $count.' field'.($count === 1 ? '' : 's').' changed';
        }

        return $actionType.' record';
    }

    private static function ignoredField(string $field): bool
    {
        return in_array($field, ['updated_at', 'created_at', 'remember_token', 'api_token', 'password'], true);
    }

    private static function valueToString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value);
    }
}
