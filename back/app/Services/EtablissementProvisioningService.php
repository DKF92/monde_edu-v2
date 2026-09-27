<?php

namespace App\Services;

use App\Models\Etablissement;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cree les roles standard (voir config/roles.php) pour un etablissement qui
 * vient d'etre cree, scopes via le systeme "teams" de spatie/laravel-permission.
 */
class EtablissementProvisioningService
{
    public function provisionRoles(Etablissement $etablissement): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($etablissement->id);

        foreach (config('roles.roles') as $nomRole => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $nomRole,
                'guard_name' => 'web',
                'etablissement_id' => $etablissement->id,
            ]);

            if ($permissions === ['*']) {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($permissions);
            }
        }
    }
}
