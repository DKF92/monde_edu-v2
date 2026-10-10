<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Droit "Consulter les inscriptions" : liste des inscrits et fiches, sans rien
 * modifier. Donne au comptable (V1 : menu Inscriptions > recherche et
 * consultation des inscriptions).
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'inscriptions.voir', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'inscriptions.voir')->value('id');
        foreach (DB::table('roles')->where('code', 'comptable')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'inscriptions.voir')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
