import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Empeche d'acceder a des ecrans qui presupposent une annee scolaire deja
 * choisie (ex: /choisir-periode), en renvoyant vers les onglets ou la modale
 * bloquante de choix d'annee (TabsPage) prendra le relais.
 */
export const anneeScolaireGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.anneeScolaire()) {
    return true;
  }

  return router.createUrlTree(['/tabs/dashboard']);
};
