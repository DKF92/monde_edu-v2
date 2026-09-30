<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Nouveau droit "periodes.choisir" : le poste qui l'a voit le champ Periode
 * dans la modale d'espace de travail. Attribuable a n'importe quel poste
 * (futur ecran d'administration des postes) ; donne des maintenant au role
 * Fondateur de chaque etablissement existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'periodes.choisir', 'guard_name' => 'web']);

        Role::where('name', 'Fondateur')->each(function (Role $role) use ($permission) {
            $role->givePermissionTo($permission);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'periodes.choisir')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
