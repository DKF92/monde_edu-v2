<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Droit "Arreter les notes et cloturer les periodes" (Parametres > Arret des
 * notes), reserve au directeur : il n'est plus compris dans "Moyennes".
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'notes.arreter', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'notes.arreter')->value('id');
        foreach (DB::table('roles')->whereIn('code', ['fondateur', 'super_admin', 'directeur_etudes'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'notes.arreter')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
