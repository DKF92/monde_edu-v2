import { Routes } from '@angular/router';
import { authGuard, inviteGuard, motDePasseGuard } from './core/guards/auth.guard';

/**
 * Parcours : connexion (e-mail + mot de passe) -> accueil, sur lequel une
 * modale bloquante demande l'espace de travail (annee scolaire,
 * etablissement, poste) tant qu'il n'est pas choisi (voir TabsPage).
 */
export const routes: Routes = [
  { path: '', redirectTo: 'login', pathMatch: 'full' },
  {
    path: 'login',
    canActivate: [inviteGuard],
    loadComponent: () => import('./features/auth/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'mot-de-passe',
    canActivate: [motDePasseGuard],
    loadComponent: () => import('./features/auth/mot-de-passe/mot-de-passe.page').then((m) => m.MotDePassePage),
  },
  {
    path: 'tabs',
    canActivate: [authGuard],
    loadChildren: () => import('./features/tabs/tabs.routes').then((m) => m.TABS_ROUTES),
  },
  { path: '**', redirectTo: 'login' },
];
