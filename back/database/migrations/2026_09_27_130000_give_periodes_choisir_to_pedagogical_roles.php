<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Donne le droit "periodes.choisir" aux postes pedagogiques des
 * etablissements existants (les nouveaux l'obtiennent via config/roles.php).
 */
return new class extends Migration
{
    private const POSTES = ['Directeur des etudes', 'Educateur', 'Professeur'];

    public function up(): void
    {
        Role::whereIn('name', self::POSTES)->each(fn (Role $role) => $role->givePermissionTo('periodes.choisir'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::whereIn('name', self::POSTES)->each(fn (Role $role) => $role->revokePermissionTo('periodes.choisir'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
