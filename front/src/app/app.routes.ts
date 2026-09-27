import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { etablissementGuard } from './core/guards/etablissement.guard';
import { anneeScolaireGuard } from './core/guards/annee-scolaire.guard';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./features/etablissements/landing-etablissement.page').then(
        (m) => m.LandingEtablissementPage,
      ),
  },
  {
    path: 'login',
    loadComponent: () => import('./features/auth/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'select-etablissement',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/etablissements/select-etablissement.page').then(
        (m) => m.SelectEtablissementPage,
      ),
  },
  {
    // Le choix de l'annee scolaire n'est plus une page : c'est une modale
    // bloquante montee dans TabsPage (voir ChoisirAnneeModalComponent).
    path: 'choisir-periode',
    canActivate: [authGuard, etablissementGuard, anneeScolaireGuard],
    loadComponent: () =>
      import('./features/annee-scolaire/choisir-periode.page').then((m) => m.ChoisirPeriodePage),
  },
  {
    path: 'tabs',
    canActivate: [authGuard, etablissementGuard],
    loadChildren: () => import('./features/tabs/tabs.routes').then((m) => m.TABS_ROUTES),
  },
  { path: '**', redirectTo: '' },
];
