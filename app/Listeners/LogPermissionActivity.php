<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Records role/permission grants and revocations to the audit log
 * (activity_log table). Requires config('permission.events_enabled') = true.
 * Registered as an event subscriber in AppServiceProvider::boot().
 */
class LogPermissionActivity
{
    public function handleRoleAttached(RoleAttached $event): void
    {
        activity('access_control')
            ->causedBy(auth()->user())
            ->performedOn($event->model)
            ->withProperties(['roles' => $this->roleNames($event->rolesOrIds)])
            ->log('Role(s) assigned');
    }

    public function handleRoleDetached(RoleDetached $event): void
    {
        activity('access_control')
            ->causedBy(auth()->user())
            ->performedOn($event->model)
            ->withProperties(['roles' => $this->roleNames($event->rolesOrIds)])
            ->log('Role(s) removed');
    }

    public function handlePermissionAttached(PermissionAttached $event): void
    {
        activity('access_control')
            ->causedBy(auth()->user())
            ->performedOn($event->model)
            ->withProperties(['permissions' => $this->permissionNames($event->permissionsOrIds)])
            ->log('Permission(s) assigned');
    }

    public function handlePermissionDetached(PermissionDetached $event): void
    {
        activity('access_control')
            ->causedBy(auth()->user())
            ->performedOn($event->model)
            ->withProperties(['permissions' => $this->permissionNames($event->permissionsOrIds)])
            ->log('Permission(s) removed');
    }

    /**
     * $rolesOrIds arrives as a mix of ids, names, or Role model instances
     * depending on the call site inside spatie/laravel-permission (e.g.
     * assignRole() passes ids, HasRoles::collectRoles() already resolved
     * them by the time the event fires) — normalized to plain names here so
     * the logged property is always human-readable, not a bare id.
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        $items = Collection::wrap($rolesOrIds);

        $ids = $items->reject(fn ($item) => $item instanceof Role)->values();
        $idToName = $ids->isEmpty() ? collect() : RoleModel::whereIn('id', $ids)->pluck('name', 'id');

        return $items
            ->map(fn ($item) => $item instanceof Role ? $item->name : ($idToName[$item] ?? $item))
            ->values()
            ->all();
    }

    private function permissionNames(mixed $permissionsOrIds): array
    {
        $items = Collection::wrap($permissionsOrIds);

        $ids = $items->reject(fn ($item) => $item instanceof Permission)->values();
        $idToName = $ids->isEmpty() ? collect() : PermissionModel::whereIn('id', $ids)->pluck('name', 'id');

        return $items
            ->map(fn ($item) => $item instanceof Permission ? $item->name : ($idToName[$item] ?? $item))
            ->values()
            ->all();
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            RoleAttached::class => 'handleRoleAttached',
            RoleDetached::class => 'handleRoleDetached',
            PermissionAttached::class => 'handlePermissionAttached',
            PermissionDetached::class => 'handlePermissionDetached',
        ];
    }
}
