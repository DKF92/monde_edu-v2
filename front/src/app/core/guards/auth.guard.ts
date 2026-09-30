import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/** Espace connecte : un mot de passe provisoire doit d'abord etre change. */
export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.estConnecte()) {
    return router.createUrlTree(['/login']);
  }

  return auth.doitChangerMotDePasse() ? router.createUrlTree(['/mot-de-passe']) : true;
};

/** Ecran de connexion : inutile si l'utilisateur est deja connecte (la
 * modale d'espace de travail prend le relais sur l'accueil si besoin). */
export const inviteGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.estConnecte()) {
    return true;
  }

  return router.createUrlTree([auth.doitChangerMotDePasse() ? '/mot-de-passe' : '/tabs/dashboard']);
};

/** Choix du mot de passe : connecte, avec un mot de passe provisoire. */
export const motDePasseGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!auth.estConnecte()) {
    return router.createUrlTree(['/login']);
  }

  return auth.doitChangerMotDePasse() ? true : router.createUrlTree(['/tabs/dashboard']);
};
