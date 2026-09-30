<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Droit "Voir les classes" (liste des classes, listes de classe), distinct de
 * "Gerer les classes" (creation, modification, effectifs maximums).
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'classes.voir', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'classes.voir')->value('id');
        $postes = ['fondateur', 'super_admin', 'directeur_etudes', 'educateur', 'secretaire'];
        foreach (DB::table('roles')->whereIn('code', $postes)->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'classes.voir')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
