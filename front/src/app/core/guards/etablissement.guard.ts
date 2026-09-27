import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Empeche d'acceder aux ecrans metier tant qu'aucun etablissement n'a ete
 * selectionne (utilisateur ayant acces a plusieurs etablissements).
 */
export const etablissementGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.etablissementActif()) {
    return true;
  }

  return router.createUrlTree(['/select-etablissement']);
};
