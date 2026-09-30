import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Fabrique un guard qui verifie une permission (ou au moins une d'une liste), pour proteger cote
 * front les routes deja filtrees dans le menu (l'API refuse de toute facon
 * la requete correspondante : ce guard n'est qu'un confort de navigation).
 */
export function permissionGuard(permission: string | string[]): CanActivateFn {
  const permissions = Array.isArray(permission) ? permission : [permission];
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    return permissions.some((p) => auth.aPermission(p)) ? true : router.createUrlTree(['/tabs/dashboard']);
  };
}
