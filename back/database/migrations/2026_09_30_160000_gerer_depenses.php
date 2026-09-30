<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Depenses (V1 table "depense" : date, libelle, montant, mois, annee,
 * receveur, payeur, piece comptable) :
 * - annee_scolaire_id (V1 an_dep), numero ("D2627-00001"), mode de sortie,
 *   piece_comptable (V1, reference ecrite) en plus du justificatif scanne ;
 * - payeur_id pointe sur users (V1 payeur_dep = compte connecte) ;
 * - depenses_historique : modification / suppression avec motif obligatoire ;
 * - droits parametrables : depenses.gerer (enregistrer, voit ses depenses),
 *   depenses.voir (toutes les depenses, point et solde de caisse),
 *   depenses.modifier (corriger / supprimer avec motif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeign(['payeur_id']);
        });
        Schema::table('depenses', function (Blueprint $table) {
            $table->foreign('payeur_id')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('annee_scolaire_id')->nullable()->after('etablissement_id')->constrained('annees_scolaires')->nullOnDelete();
            $table->string('numero', 30)->nullable()->after('annee_scolaire_id');
            $table->enum('mode_paiement', ['especes', 'mobile_money', 'cheque', 'virement'])->default('especes')->after('montant');
            $table->string('piece_comptable', 100)->nullable()->after('beneficiaire');
            $table->unique(['etablissement_id', 'numero']);
            $table->index(['etablissement_id', 'date_depense']);
        });

        Schema::create('depenses_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            // Pas de cle etrangere : la depense supprimee n'existe plus.
            $table->unsignedBigInteger('depense_id')->index();
            $table->string('numero', 30)->nullable();
            $table->enum('action', ['modification', 'suppression']);
            $table->string('motif', 500);
            $table->json('avant');
            $table->json('apres')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $maintenant = now();
        $droits = [
            'depenses.voir' => ['fondateur', 'super_admin', 'directeur_administratif', 'comptable', 'econome'],
            'depenses.modifier' => ['fondateur', 'super_admin', 'econome'],
            // V1 : le caissier saisit les sorties de caisse.
            'depenses.gerer' => ['caissier'],
        ];
        foreach ($droits as $nom => $postes) {
            DB::table('permissions')->insertOrIgnore(['name' => $nom, 'guard_name' => 'web', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            $permissionId = DB::table('permissions')->where('name', $nom)->value('id');
            foreach (DB::table('roles')->whereIn('code', $postes)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses_historique');
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeign(['payeur_id']);
            $table->dropUnique(['etablissement_id', 'numero']);
            $table->dropIndex(['etablissement_id', 'date_depense']);
            $table->dropConstrainedForeignId('annee_scolaire_id');
            $table->dropColumn(['numero', 'mode_paiement', 'piece_comptable']);
        });
        Schema::table('depenses', function (Blueprint $table) {
            $table->foreign('payeur_id')->references('id')->on('personnels')->nullOnDelete();
        });
        foreach (['depenses.voir', 'depenses.modifier'] as $nom) {
            $id = DB::table('permissions')->where('name', $nom)->value('id');
            DB::table('role_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
