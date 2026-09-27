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
        path: 'profil',
        loadComponent: () =>
          import('../profil/profil.page').then((m) => m.ProfilPage),
      },
      { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
    ],
  },
];
