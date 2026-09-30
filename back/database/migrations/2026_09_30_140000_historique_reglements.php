<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Modification / suppression d'un paiement, toujours avec un motif :
 * - reglements_historique garde l'etat avant (et apres pour une modification),
 *   le motif et l'auteur ; une suppression retire le paiement et ses lignes
 *   (les montants payes sont recalcules) mais la trace reste ici ;
 * - nouveau droit "reglements.modifier", parametrable par poste (Roles),
 *   donne au caissier, au fondateur et au super admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglements_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            // Pas de cle etrangere : le paiement supprime n'existe plus.
            $table->unsignedBigInteger('reglement_id')->index();
            $table->string('numero_recu', 30);
            $table->foreignId('eleve_id')->nullable()->constrained('eleves')->nullOnDelete();
            $table->enum('action', ['modification', 'suppression']);
            $table->string('motif', 500);
            $table->json('avant');
            $table->json('apres')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $maintenant = now();
        DB::table('permissions')->insertOrIgnore(['name' => 'reglements.modifier', 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        $permissionId = DB::table('permissions')->where('name', 'reglements.modifier')->value('id');
        foreach (DB::table('roles')->whereIn('code', ['caissier', 'fondateur', 'super_admin'])->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('reglements_historique');
        $permissionId = DB::table('permissions')->where('name', 'reglements.modifier')->value('id');
        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
