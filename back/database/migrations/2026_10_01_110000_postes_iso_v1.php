<?php

use App\Models\Etablissement;
use App\Services\EtablissementProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Postes alignes sur les menus de la V1 (shared/menu/*.php) :
 * - droit reductions.voir (consulter / imprimer les reductions, V1 econome) ;
 * - postes Adjoint au directeur des etudes, Secretaire du directeur des
 *   etudes et Correspondant fichier crees dans chaque etablissement ;
 * - droits V1 manquants AJOUTES aux postes standard existants (config/roles.php).
 *   Rien n'est retire : les droits choisis par l'ecole sont conserves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'reductions.voir', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissions = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        foreach (DB::table('roles')->whereIn('code', ['fondateur', 'super_admin'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissions['reductions.voir'], 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Etablissement::withoutGlobalScopes()->get() as $etablissement) {
            app(EtablissementProvisioningService::class)->provisionRoles($etablissement);
        }

        foreach (config('roles.roles') as $definition) {
            $noms = array_diff($definition['permissions'], ['*']);
            foreach (DB::table('roles')->where('code', $definition['code'])->pluck('id') as $roleId) {
                foreach ($noms as $nom) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissions[$nom], 'role_id' => $roleId]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Les droits ajoutes aux postes existants ne sont pas retires (on ne
        // sait plus lesquels l'ecole avait deja) ; seuls les nouveaux postes
        // sans compte et le droit reductions.voir disparaissent.
        $nouveaux = DB::table('roles')->whereIn('code', ['adjoint_directeur_etudes', 'secretaire_directeur_etudes', 'correspondant_fichier'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('model_has_roles')->whereColumn('model_has_roles.role_id', 'roles.id'))
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('role_id', $nouveaux)->delete();
        DB::table('roles')->whereIn('id', $nouveaux)->delete();
        $id = DB::table('permissions')->where('name', 'reductions.voir')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
