<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Droit "Saisir les notes pour les professeurs" (toutes les classes et
 * matieres), donne a l'informaticien et attribuable a tout poste dans Postes.
 * « Moyennes » (moyennes.gerer) ne donne plus la saisie des notes ; l'educateur
 * ne saisit que la conduite (absences.gerer).
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'notes.saisir_toutes', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'notes.saisir_toutes')->value('id');
        foreach (DB::table('roles')->where('code', 'informaticien')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'notes.saisir_toutes')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
