import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpContext } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ContexteEtablissement } from '../models/user.model';
import { SANS_CONTEXTE } from '../interceptors/auth.interceptor';

@Injectable({ providedIn: 'root' })
export class ContexteService {
  private readonly http = inject(HttpClient);

  /** Annees (avec trimestres) et postes de l'utilisateur pour un etablissement.
   * Le contexte actif n'est volontairement pas envoye : il peut concerner un
   * autre etablissement et serait refuse par l'API. */
  charger(etablissementId: number): Observable<ContexteEtablissement> {
    return this.http.get<ContexteEtablissement>(`${environment.apiUrl}/contexte`, {
      headers: { 'X-Etablissement-Id': String(etablissementId) },
      context: new HttpContext().set(SANS_CONTEXTE, true),
    });
  }
}
