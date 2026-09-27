import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Fabrique un guard qui verifie une permission donnee, pour proteger cote
 * front les routes deja filtrees dans le menu (l'API refuse de toute facon
 * la requete correspondante : ce guard n'est qu'un confort de navigation).
 */
export function permissionGuard(permission: string): CanActivateFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    return auth.aPermission(permission) ? true : router.createUrlTree(['/tabs/dashboard']);
  };
}
