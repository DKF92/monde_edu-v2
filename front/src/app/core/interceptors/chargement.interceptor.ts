import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize } from 'rxjs';
import { ChargementService } from '../services/chargement.service';

/**
 * Retient le loader global tant que les requetes lancees a l'ouverture d'une
 * page ne sont pas terminees (voir ChargementService).
 */
export const chargementInterceptor: HttpInterceptorFn = (req, next) => {
  const chargement = inject(ChargementService);
  if (!chargement.suivre()) {
    return next(req);
  }
  return next(req).pipe(finalize(() => chargement.terminer()));
};
