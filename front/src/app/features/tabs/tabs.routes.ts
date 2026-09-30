import { Routes } from '@angular/router';
import { TabsPage } from './tabs.page';
import { permissionGuard } from '../../core/guards/permission.guard';

export const TABS_ROUTES: Routes = [
  {
    path: '',
    component: TabsPage,
    children: [
      {
        path: 'dashboard',
        loadComponent: () =>
          import('../dashboard/dashboard.page').then((m) => m.DashboardPage),
      },
      {
        path: 'eleves',
        canActivate: [permissionGuard('eleves.voir')],
        loadComponent: () =>
          import('../eleves/eleves-list.page').then((m) => m.ElevesListPage),
      },
      {
        path: 'eleves/:id',
        canActivate: [permissionGuard('eleves.voir')],
        loadComponent: () => import('../eleves/eleve-dossier.page').then((m) => m.EleveDossierPage),
      },
      {
        path: 'evaluations',
        canActivate: [permissionGuard(['notes.saisir', 'notes.voir', 'moyennes.gerer', 'bulletins.voir'])],
        loadComponent: () => import('../evaluations/notes.page').then((m) => m.NotesPage),
      },
      {
        path: 'evaluations/resultats',
        canActivate: [permissionGuard(['notes.voir', 'moyennes.gerer', 'bulletins.voir', 'notes.saisir'])],
        loadComponent: () => import('../evaluations/resultats.page').then((m) => m.ResultatsPage),
      },
      {
        path: 'absences',
        canActivate: [permissionGuard('absences.gerer')],
        loadComponent: () => import('../absences/absences.page').then((m) => m.AbsencesPage),
      },
      {
        path: 'absences/conduite',
        canActivate: [permissionGuard('absences.gerer')],
        loadComponent: () => import('../absences/conduite.page').then((m) => m.ConduitePage),
      },
      {
        path: 'rapports',
        canActivate: [permissionGuard('rapports.voir')],
        loadComponent: () => import('../rapports/rapports.page').then((m) => m.RapportsPage),
      },
      {
        path: 'parametres/arret-notes',
        canActivate: [permissionGuard('notes.arreter')],
        loadComponent: () => import('../parametres/parametres-arret-notes.page').then((m) => m.ParametresArretNotesPage),
      },
      {
        path: 'parametres/matieres',
        canActivate: [permissionGuard('matieres.gerer')],
        loadComponent: () => import('../parametres/parametres-matieres.page').then((m) => m.ParametresMatieresPage),
      },
      {
        path: 'classes',
        canActivate: [permissionGuard(['classes.voir', 'classes.gerer'])],
        loadComponent: () => import('../classes/classes.page').then((m) => m.ClassesPage),
      },
      {
        path: 'classes/:id',
        canActivate: [permissionGuard(['classes.voir', 'classes.gerer'])],
        loadComponent: () => import('../classes/liste-classe.page').then((m) => m.ListeClassePage),
      },
      {
        path: 'paiements',
        canActivate: [permissionGuard('reglements.encaisser')],
        loadComponent: () => import('../paiements/point-caisse.page').then((m) => m.PointCaissePage),
      },
      {
        path: 'paiements/reste-a-payer',
        canActivate: [permissionGuard('reglements.voir')],
        loadComponent: () => import('../paiements/reste-a-payer.page').then((m) => m.ResteAPayerPage),
      },
      {
        // Liste des dettes (V1 liste_dette) : meme page que le reste a payer.
        path: 'paiements/dettes',
        canActivate: [permissionGuard(['reglements.voir', 'dettes.gerer'])],
        data: { mode: 'dettes' },
        loadComponent: () => import('../paiements/reste-a-payer.page').then((m) => m.ResteAPayerPage),
      },
      {
        // Point des dettes (V1 Point_dette) : tableau comme le point de caisse.
        path: 'paiements/point-dettes',
        canActivate: [permissionGuard(['reglements.voir', 'dettes.gerer'])],
        data: { mode: 'dettes' },
        loadComponent: () => import('../points/point.page').then((m) => m.PointPage),
      },
      {
        path: 'paiements/liste',
        canActivate: [permissionGuard(['reglements.encaisser', 'reglements.voir'])],
        loadComponent: () => import('../paiements/paiements.page').then((m) => m.PaiementsPage),
      },
      {
        path: 'reductions',
        canActivate: [permissionGuard('reductions.gerer')],
        loadComponent: () => import('../reductions/reductions.page').then((m) => m.ReductionsPage),
      },
      {
        path: 'reductions/nouvelle',
        canActivate: [permissionGuard('reductions.gerer')],
        loadComponent: () => import('../reductions/reduction-formulaire.page').then((m) => m.ReductionFormulairePage),
      },
      {
        path: 'depenses',
        canActivate: [permissionGuard(['depenses.gerer', 'depenses.voir'])],
        loadComponent: () => import('../depenses/depenses.page').then((m) => m.DepensesPage),
      },
      {
        path: 'depenses/nouvelle',
        canActivate: [permissionGuard('depenses.gerer')],
        loadComponent: () => import('../depenses/depense-formulaire.page').then((m) => m.DepenseFormulairePage),
      },
      {
        path: 'depenses/point',
        canActivate: [permissionGuard('depenses.voir')],
        loadComponent: () => import('../depenses/point-depenses.page').then((m) => m.PointDepensesPage),
      },
      {
        path: 'depenses/:id/modifier',
        canActivate: [permissionGuard('depenses.modifier')],
        loadComponent: () => import('../depenses/depense-formulaire.page').then((m) => m.DepenseFormulairePage),
      },
      {
        path: 'paiements/encaisser',
        canActivate: [permissionGuard('reglements.encaisser')],
        loadComponent: () => import('../paiements/encaisser.page').then((m) => m.EncaisserPage),
      },
      // Apres les chemins fixes (encaisser, liste, reste-a-payer).
      {
        path: 'paiements/:id',
        canActivate: [permissionGuard(['reglements.encaisser', 'reglements.voir'])],
        loadComponent: () => import('../paiements/paiement-detail.page').then((m) => m.PaiementDetailPage),
      },
      {
        path: 'paiements/:id/modifier',
        canActivate: [permissionGuard('reglements.modifier')],
        loadComponent: () => import('../paiements/paiement-modifier.page').then((m) => m.PaiementModifierPage),
      },
      {
        path: 'inscriptions',
        canActivate: [permissionGuard('inscriptions.gerer')],
        loadComponent: () => import('../inscriptions/inscriptions.page').then((m) => m.InscriptionsPage),
      },
      {
        // Point des inscriptions (V1 Point_inscription).
        path: 'inscriptions/point',
        canActivate: [permissionGuard(['inscriptions.gerer', 'rapports.voir'])],
        data: { mode: 'inscriptions' },
        loadComponent: () => import('../points/point.page').then((m) => m.PointPage),
      },
      {
        path: 'inscriptions/nouvelle',
        canActivate: [permissionGuard('inscriptions.gerer')],
        loadComponent: () => import('../inscriptions/inscription-formulaire.page').then((m) => m.InscriptionFormulairePage),
      },
      {
        path: 'inscriptions/:id',
        canActivate: [permissionGuard('inscriptions.gerer')],
        loadComponent: () => import('../inscriptions/inscription-detail.page').then((m) => m.InscriptionDetailPage),
      },
      {
        path: 'inscriptions/:id/modifier',
        canActivate: [permissionGuard('inscriptions.gerer')],
        loadComponent: () => import('../inscriptions/inscription-formulaire.page').then((m) => m.InscriptionFormulairePage),
      },
      {
        path: 'utilisateurs',
        canActivate: [permissionGuard('utilisateurs.gerer')],
        loadComponent: () =>
          import('../utilisateurs/utilisateurs.page').then((m) => m.UtilisateursPage),
      },
      {
        path: 'roles',
        canActivate: [permissionGuard('roles.gerer')],
        loadComponent: () => import('../roles/roles.page').then((m) => m.RolesPage),
      },
      {
        path: 'parametres',
        canActivate: [permissionGuard(['classes.gerer', 'matieres.gerer', 'notes.arreter', 'etablissement.gerer', 'tarifs.gerer', 'annees_scolaires.gerer', 'sms.gerer'])],
        loadComponent: () => import('../parametres/parametres.page').then((m) => m.ParametresPage),
      },
      {
        path: 'parametres/classes',
        canActivate: [permissionGuard('classes.gerer')],
        loadComponent: () => import('../parametres/parametres-classes.page').then((m) => m.ParametresClassesPage),
      },
      {
        path: 'parametres/inscriptions',
        canActivate: [permissionGuard('etablissement.gerer')],
        loadComponent: () => import('../parametres/parametres-inscriptions.page').then((m) => m.ParametresInscriptionsPage),
      },
      {
        path: 'parametres/etablissement',
        canActivate: [permissionGuard('etablissement.gerer')],
        loadComponent: () => import('../parametres/parametres-etablissement.page').then((m) => m.ParametresEtablissementPage),
      },
      {
        path: 'parametres/annees',
        canActivate: [permissionGuard('annees_scolaires.gerer')],
        loadComponent: () => import('../parametres/parametres-annees.page').then((m) => m.ParametresAnneesPage),
      },
      {
        path: 'parametres/sms',
        canActivate: [permissionGuard('sms.gerer')],
        loadComponent: () => import('../parametres/parametres-sms.page').then((m) => m.ParametresSmsPage),
      },
      {
        path: 'parametres/ordre-paiement',
        canActivate: [permissionGuard('tarifs.gerer')],
        loadComponent: () => import('../parametres/parametres-ordre-paiement.page').then((m) => m.ParametresOrdrePaiementPage),
      },
      {
        path: 'parametres/paiements',
        canActivate: [permissionGuard('tarifs.gerer')],
        loadComponent: () => import('../parametres/parametres-paiements.page').then((m) => m.ParametresPaiementsPage),
      },
      {
        path: 'profil',
        loadComponent: () =>
          import('../profil/profil.page').then((m) => m.ProfilPage),
      },
      { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
    ],
  },
];
