<?php

use App\Http\Controllers\Api\AnneeScolaireController;
use App\Http\Controllers\Api\ArretNoteController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AnneeScolaireGestionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaisseController;
use App\Http\Controllers\Api\ClasseController;
use App\Http\Controllers\Api\AbsenceController;
use App\Http\Controllers\Api\MatiereController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\RapportCompileController;
use App\Http\Controllers\Api\RapportController;
use App\Http\Controllers\Api\RapportPersonnaliseController;
use App\Http\Controllers\Api\ResultatController;
use App\Http\Controllers\Api\DepenseController;
use App\Http\Controllers\Api\ResteAPayerController;
use App\Http\Controllers\Api\ContexteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EleveController;
use App\Http\Controllers\Api\EtablissementController;
use App\Http\Controllers\Api\EtablissementParametreController;
use App\Http\Controllers\Api\InscriptionController;
use App\Http\Controllers\Api\ParametreController;
use App\Http\Controllers\Api\ParametrePaiementController;
use App\Http\Controllers\Api\PeriodeController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\ReductionController;
use App\Http\Controllers\Api\PosteController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\UtilisateurController;
use Illuminate\Support\Facades\Route;

Route::get('/etablissements', [EtablissementController::class, 'index']);
Route::post('/login', [AuthController::class, 'login']);

// Premiere connexion (mot de passe provisoire) ou changement volontaire.
Route::post('/mot-de-passe', [AuthController::class, 'changerMotDePasse'])->middleware(['auth:sanctum', 'throttle:10,1']);

Route::middleware(['auth:sanctum', 'mot-de-passe-definitif', 'etablissement', 'poste', 'contexte-scolaire'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/contexte', [ContexteController::class, 'index']);

    Route::get('/annees-scolaires', [AnneeScolaireController::class, 'index']);
    Route::get('/periodes', [PeriodeController::class, 'index']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/eleves', [EleveController::class, 'index']);
    Route::get('/eleves/{id}', [EleveController::class, 'show'])->whereNumber('id');

    // Caisse (droit reglements.encaisser) et journal des paiements (reglements.voir).
    Route::get('/caisse/recherche', [CaisseController::class, 'rechercher']);
    Route::get('/caisse/inscriptions/{id}', [CaisseController::class, 'situation'])->whereNumber('id');
    Route::post('/caisse/inscriptions/{id}/reglements', [CaisseController::class, 'encaisser'])->whereNumber('id');
    Route::get('/caisse/bilan', [CaisseController::class, 'bilan']);
    Route::get('/caisse/bilan/pdf', [CaisseController::class, 'bilanPdf']);
    Route::get('/caisse/bilan/general/pdf', [CaisseController::class, 'bilanGeneralPdf']);
    Route::get('/reglements', [CaisseController::class, 'journal']);
    // Matieres et coefficients (matieres.gerer), professeurs des classes.
    Route::get('/matieres', [MatiereController::class, 'index']);
    Route::post('/matieres', [MatiereController::class, 'store']);
    Route::put('/matieres/coefficients', [MatiereController::class, 'coefficients']);
    Route::put('/matieres/{id}', [MatiereController::class, 'update'])->whereNumber('id');
    Route::delete('/matieres/{id}', [MatiereController::class, 'destroy'])->whereNumber('id');
    Route::get('/classes/{id}/enseignants', [MatiereController::class, 'enseignants'])->whereNumber('id');
    Route::put('/classes/{id}/enseignants', [MatiereController::class, 'enregistrerEnseignants'])->whereNumber('id');

    // Evaluations : notes, moyennes, resultats, bulletins.
    Route::get('/evaluations/options', [NoteController::class, 'options']);
    Route::get('/notes', [NoteController::class, 'index']);
    Route::put('/notes', [NoteController::class, 'enregistrer']);
    Route::delete('/notes/evaluation', [NoteController::class, 'supprimerEvaluation']);
    // Arret des notes et cloture des periodes (notes.arreter, directeur).
    Route::get('/arret-notes', [ArretNoteController::class, 'index']);
    Route::post('/arret-notes/arreter', [ArretNoteController::class, 'arreter']);
    Route::post('/arret-notes/rouvrir', [ArretNoteController::class, 'rouvrir']);
    Route::patch('/arret-notes/periodes/{id}/cloture', [ArretNoteController::class, 'cloturer'])->whereNumber('id');
    Route::get('/resultats', [ResultatController::class, 'index']);
    Route::post('/resultats/enregistrer', [ResultatController::class, 'enregistrer']);
    Route::post('/resultats/decisions', [ResultatController::class, 'decisions']);
    Route::get('/resultats/document', [ResultatController::class, 'document']);
    Route::get('/bulletins/document', [ResultatController::class, 'bulletins']);

    // Absences et conduite (absences.gerer).
    Route::get('/absences', [AbsenceController::class, 'index']);
    Route::get('/absences/document', [AbsenceController::class, 'document']);
    Route::get('/absences/classe/{id}', [AbsenceController::class, 'classe'])->whereNumber('id');
    Route::post('/absences', [AbsenceController::class, 'store']);
    Route::put('/absences/{id}', [AbsenceController::class, 'update'])->whereNumber('id');
    Route::patch('/absences/{id}/justifier', [AbsenceController::class, 'justifier'])->whereNumber('id');
    Route::delete('/absences/{id}', [AbsenceController::class, 'destroy'])->whereNumber('id');
    Route::get('/conduite', [AbsenceController::class, 'conduite']);
    Route::put('/conduite', [AbsenceController::class, 'enregistrerConduite']);

    // Assistant de support (tous les postes, consultation seule).
    Route::get('/assistant', [AssistantController::class, 'etat']);
    Route::post('/assistant/messages', [AssistantController::class, 'repondre'])->middleware('throttle:30,1');

    // Rapports (rapports.voir).
    Route::get('/rapports', [RapportController::class, 'index']);
    Route::put('/rapports/masques', [RapportController::class, 'masquer']);
    // Rapports officiels (rentree, periode, annuel) et rapports parametrables.
    Route::get('/rapports/compiles', [RapportCompileController::class, 'index']);
    Route::put('/rapports/compiles/{type}/textes', [RapportCompileController::class, 'textes'])->where('type', 'rentree|periode|annuel');
    Route::get('/rapports/compiles/{type}/document', [RapportCompileController::class, 'document'])->where('type', 'rentree|periode|annuel');
    Route::get('/rapports/personnalises', [RapportPersonnaliseController::class, 'index']);
    Route::post('/rapports/personnalises/apercu', [RapportPersonnaliseController::class, 'apercu']);
    Route::get('/rapports/personnalises/document', [RapportPersonnaliseController::class, 'document']);
    Route::post('/rapports/personnalises', [RapportPersonnaliseController::class, 'store']);
    Route::put('/rapports/personnalises/{id}', [RapportPersonnaliseController::class, 'update']);
    Route::delete('/rapports/personnalises/{id}', [RapportPersonnaliseController::class, 'destroy']);
    Route::get('/rapports/{code}', [RapportController::class, 'show'])->where('code', '[a-z_]+');
    Route::get('/rapports/{code}/document', [RapportController::class, 'document'])->where('code', '[a-z_]+');

    // Classes (classes.voir / classes.gerer).
    Route::get('/classes', [ClasseController::class, 'index']);
    Route::get('/classes/document', [ClasseController::class, 'document']);
    Route::get('/classes/listes/document', [ClasseController::class, 'documentListes']);
    Route::post('/classes', [ClasseController::class, 'store']);
    Route::post('/classes/lot', [ClasseController::class, 'storeLot']);
    Route::get('/classes/{id}', [ClasseController::class, 'show'])->whereNumber('id');
    Route::get('/classes/{id}/document', [ClasseController::class, 'documentEleves'])->whereNumber('id');
    Route::put('/classes/{id}', [ClasseController::class, 'update'])->whereNumber('id');
    Route::delete('/classes/{id}', [ClasseController::class, 'destroy'])->whereNumber('id');

    // Depenses (depenses.gerer / depenses.voir / depenses.modifier).
    Route::get('/depenses', [DepenseController::class, 'index']);
    Route::get('/depenses/document', [DepenseController::class, 'document']);
    Route::get('/depenses/point', [DepenseController::class, 'point']);
    Route::get('/depenses/point/document', [DepenseController::class, 'pointDocument']);
    Route::post('/depenses', [DepenseController::class, 'store']);
    Route::get('/depenses/{id}', [DepenseController::class, 'show'])->whereNumber('id');
    // POST (multipart) : un nouveau justificatif peut accompagner la modification.
    Route::post('/depenses/{id}', [DepenseController::class, 'update'])->whereNumber('id');
    Route::delete('/depenses/{id}', [DepenseController::class, 'destroy'])->whereNumber('id');
    Route::get('/reste-a-payer', [ResteAPayerController::class, 'index']);
    Route::get('/reste-a-payer/document', [ResteAPayerController::class, 'document']);
    // Reductions et cas sociaux (reductions.gerer).
    Route::get('/reductions', [ReductionController::class, 'index']);
    Route::get('/reductions/document', [ReductionController::class, 'document']);
    Route::get('/reductions/eleve', [ReductionController::class, 'eleve']);
    Route::post('/reductions', [ReductionController::class, 'store']);
    Route::post('/reductions/dette', [ReductionController::class, 'storeDette']);
    Route::delete('/reductions/{lot}', [ReductionController::class, 'destroy']);
    // Points des inscrits et des dettes (tableaux comme le point de caisse).
    Route::get('/points/inscriptions', [PointController::class, 'inscriptions']);
    Route::get('/points/inscriptions/document', [PointController::class, 'inscriptionsDocument']);
    Route::get('/points/inscriptions/eleves', [PointController::class, 'inscriptionsEleves']);
    Route::get('/points/dettes', [PointController::class, 'dettes']);
    Route::get('/points/dettes/document', [PointController::class, 'dettesDocument']);
    Route::get('/dettes', [ResteAPayerController::class, 'dettes']);
    Route::get('/dettes/document', [ResteAPayerController::class, 'documentDettes']);
    Route::get('/reglements/{id}', [CaisseController::class, 'show'])->whereNumber('id');
    Route::put('/reglements/{id}', [CaisseController::class, 'modifier'])->whereNumber('id');
    Route::delete('/reglements/{id}', [CaisseController::class, 'supprimer'])->whereNumber('id');
    Route::get('/reglements/{id}/recu', [CaisseController::class, 'recu'])->whereNumber('id');

    // Inscriptions (droit inscriptions.gerer).
    Route::get('/inscriptions', [InscriptionController::class, 'index']);
    Route::get('/inscriptions/options', [InscriptionController::class, 'options']);
    Route::get('/inscriptions/apercu-frais', [InscriptionController::class, 'apercuFrais']);
    Route::get('/inscriptions/matricule/{matricule}', [InscriptionController::class, 'rechercher']);
    Route::get('/inscriptions/en-ligne/{matricule}', [InscriptionController::class, 'enLigne']);
    Route::post('/inscriptions/en-ligne/mise-a-jour', [InscriptionController::class, 'miseAJourEnLigne']);
    Route::post('/inscriptions', [InscriptionController::class, 'store']);
    Route::get('/inscriptions/{id}', [InscriptionController::class, 'show'])->whereNumber('id');
    Route::put('/inscriptions/{id}', [InscriptionController::class, 'update'])->whereNumber('id');
    Route::patch('/inscriptions/{id}/classe', [InscriptionController::class, 'changerClasse'])->whereNumber('id');
    Route::patch('/inscriptions/{id}/fournitures', [InscriptionController::class, 'fournitures'])->whereNumber('id');
    Route::delete('/inscriptions/{id}', [InscriptionController::class, 'destroy'])->whereNumber('id');
    Route::post('/inscriptions/{id}/photo', [InscriptionController::class, 'photo'])->whereNumber('id');

    // Administration des utilisateurs (droit utilisateurs.gerer).
    Route::get('/utilisateurs', [UtilisateurController::class, 'index']);
    Route::get('/utilisateurs/options', [UtilisateurController::class, 'options']);
    Route::post('/utilisateurs', [UtilisateurController::class, 'store']);
    Route::put('/utilisateurs/{id}', [UtilisateurController::class, 'update'])->whereNumber('id');
    Route::patch('/utilisateurs/{id}/statut', [UtilisateurController::class, 'changerStatut'])->whereNumber('id');
    Route::post('/utilisateurs/{id}/mot-de-passe', [UtilisateurController::class, 'reinitialiserMotDePasse'])->whereNumber('id');

    // Administration des postes (droit roles.gerer).
    Route::get('/postes', [PosteController::class, 'index']);
    Route::get('/postes/catalogue', [PosteController::class, 'catalogue']);
    Route::get('/postes/{id}', [PosteController::class, 'show'])->whereNumber('id');
    Route::post('/postes', [PosteController::class, 'store']);
    Route::put('/postes/{id}', [PosteController::class, 'update'])->whereNumber('id');
    Route::patch('/postes/{id}/statut', [PosteController::class, 'changerStatut'])->whereNumber('id');

    // Parametres de l'etablissement.
    Route::get('/parametres/classes', [ParametreController::class, 'classes']);
    Route::put('/parametres/classes', [ParametreController::class, 'enregistrerClasses']);
    Route::get('/parametres/inscriptions', [ParametreController::class, 'inscriptions']);
    Route::put('/parametres/inscriptions', [ParametreController::class, 'enregistrerInscriptions']);
    Route::get('/parametres/paiements/tarifs', [ParametrePaiementController::class, 'tarifs']);
    Route::put('/parametres/paiements/tarifs/{niveau}', [ParametrePaiementController::class, 'enregistrerTarif'])->whereNumber('niveau');
    Route::post('/parametres/paiements/types-frais', [ParametrePaiementController::class, 'creerTypeFrais']);
    Route::put('/parametres/paiements/types-frais/ordre', [ParametrePaiementController::class, 'ordonnerTypesFrais']);
    Route::put('/parametres/paiements/types-frais/{id}', [ParametrePaiementController::class, 'modifierTypeFrais'])->whereNumber('id');
    Route::get('/parametres/paiements/types-reductions', [ParametrePaiementController::class, 'typesReductions']);
    Route::post('/parametres/paiements/types-reductions', [ParametrePaiementController::class, 'creerTypeReduction']);
    Route::put('/parametres/paiements/types-reductions/{id}', [ParametrePaiementController::class, 'modifierTypeReduction'])->whereNumber('id');

    Route::get('/parametres/etablissement', [EtablissementParametreController::class, 'show']);
    Route::put('/parametres/etablissement', [EtablissementParametreController::class, 'update']);
    Route::post('/parametres/etablissement/logo', [EtablissementParametreController::class, 'enregistrerLogo']);
    Route::delete('/parametres/etablissement/logo', [EtablissementParametreController::class, 'supprimerLogo']);

    Route::get('/parametres/annees', [AnneeScolaireGestionController::class, 'index']);
    Route::post('/parametres/annees', [AnneeScolaireGestionController::class, 'store']);
    Route::put('/parametres/annees/{id}', [AnneeScolaireGestionController::class, 'update'])->whereNumber('id');
    Route::post('/parametres/annees/{id}/activer', [AnneeScolaireGestionController::class, 'activer'])->whereNumber('id');
    Route::patch('/parametres/annees/{id}/cloture', [AnneeScolaireGestionController::class, 'cloturer'])->whereNumber('id');
    Route::patch('/parametres/annees/{id}/decoupage', [AnneeScolaireGestionController::class, 'changerDecoupage'])->whereNumber('id');
    Route::put('/parametres/periodes/{id}', [AnneeScolaireGestionController::class, 'modifierPeriode'])->whereNumber('id');
    Route::post('/parametres/periodes/{id}/activer', [AnneeScolaireGestionController::class, 'activerPeriode'])->whereNumber('id');
    Route::patch('/parametres/periodes/{id}/cloture', [AnneeScolaireGestionController::class, 'cloturerPeriode'])->whereNumber('id');

    Route::get('/parametres/sms', [SmsController::class, 'show']);
    Route::put('/parametres/sms', [SmsController::class, 'update']);
    Route::get('/parametres/sms/rechargements', [SmsController::class, 'rechargements']);
    Route::post('/parametres/sms/rechargements', [SmsController::class, 'recharger']);
    Route::get('/parametres/sms/messages', [SmsController::class, 'messages']);
    Route::post('/parametres/sms/test', [SmsController::class, 'tester']);
});
