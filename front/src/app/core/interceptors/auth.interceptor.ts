import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';

/**
 * Ajoute a chaque requete API le token Bearer et le contexte courant
 * (etablissement, annee scolaire, trimestre), et redirige vers /login en cas
 * de session expiree (401).
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const token = auth.token();
  const etablissement = auth.etablissementActif();
  const anneeScolaire = auth.anneeScolaire();
  const periode = auth.periode();

  let requeteModifiee = req;
  const headers: Record<string, string> = {};

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  if (etablissement) {
    headers['X-Etablissement-Id'] = String(etablissement.id);
  }
  if (anneeScolaire) {
    headers['X-Annee-Scolaire-Id'] = String(anneeScolaire.id);
  }
  if (periode) {
    headers['X-Periode-Id'] = String(periode.id);
  }
  if (Object.keys(headers).length > 0) {
    requeteModifiee = req.clone({ setHeaders: headers });
  }

  return next(requeteModifiee).pipe(
    catchError((erreur: HttpErrorResponse) => {
      if (erreur.status === 401) {
        auth.logout();
        router.navigate(['/login']);
      }
      return throwError(() => erreur);
    }),
  );
};
