<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etablissements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Code court utilise comme identifiant tenant (ex: sous-domaine, en-tete API)');
            $table->string('nom', 300);
            $table->string('sigle', 30)->nullable();
            $table->enum('type_etablissement', ['maternelle', 'primaire', 'college', 'lycee', 'college_lycee', 'superieur', 'mixte'])->default('mixte');
            $table->string('telephone1', 20)->nullable();
            $table->string('telephone2', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('site_web')->nullable();
            $table->string('boite_postale', 30)->nullable();
            $table->string('quartier', 150)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('pays', 100)->default("Côte d'Ivoire");
            $table->string('indicatif_telephonique', 10)->default('225');
            $table->string('logo_path')->nullable();
            $table->string('slogan', 300)->nullable();
            $table->enum('statut', ['actif', 'suspendu', 'expire'])->default('actif');
            $table->date('date_expiration_abonnement')->nullable();
            $table->json('parametres')->nullable()->comment('Parametres libres: sms, pdf, effectif max/classe, etc.');
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etablissements');
    }
};
