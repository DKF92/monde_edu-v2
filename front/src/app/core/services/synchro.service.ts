import { Injectable, effect, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuthService } from './auth.service';
import { ReseauService } from './reseau.service';

/** Saisie faite en ligne et refusee a l'ecole (notes arretees, periode cloturee). */
export interface RejetSynchro {
  id: number;
  libelle: string;
  motif: string;
  auteur: string | null;
  date: string;
  vu: boolean;
}

export interface EtatSynchro {
  role: 'local' | 'en_ligne';
  serveur_en_ligne: string | null;
  configuree: boolean;
  en_attente: number;
  derniere_synchro: string | null;
  derniere_reception: string | null;
  derniere_erreur: string | null;
  rejets_non_vus: number;
}

/**
 * Synchronisation entre le serveur de l'ecole et le serveur en ligne, vue par
 * l'application : saisies refusees de l'utilisateur (rechargees a la
 * connexion, au retour du reseau et toutes les 5 minutes), etat et lancement
 * pour l'administrateur.
 */
@Injectable({ providedIn: 'root' })
export class SynchroService {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly reseau = inject(ReseauService);
  private readonly api = `${environment.apiUrl}/sync`;

  /** Mes saisies refusees, non vues. */
  readonly rejets = signal<RejetSynchro[]>([]);

  constructor() {
    effect(() => {
      // Connexion / changement d'espace de travail : on recharge.
      if (this.auth.contexteComplet() && this.reseau.connecte()) this.chargerRejets();
      else if (!this.auth.estConnecte()) this.rejets.set([]);
    }, { allowSignalWrites: true });
    setInterval(() => {
      if (this.auth.contexteComplet() && this.reseau.connecte()) this.chargerRejets();
    }, 5 * 60 * 1000);
  }

  chargerRejets(): void {
    this.http.get<{ data: RejetSynchro[] }>(`${this.api}/rejets`).subscribe({ next: (r) => this.rejets.set(r.data ?? []), error: () => undefined });
  }

  marquerVu(id: number): Observable<unknown> {
    return this.http.patch(`${this.api}/rejets/${id}/vu`, {}).pipe(tap(() => this.rejets.update((l) => l.filter((r) => r.id !== id))));
  }

  /** Administrateur : tous les refus (vus ou non). */
  tousLesRejets(): Observable<{ data: RejetSynchro[] }> {
    return this.http.get<{ data: RejetSynchro[] }>(`${this.api}/rejets`, { params: { tous: 1 } });
  }

  etat(): Observable<{ data: EtatSynchro }> {
    return this.http.get<{ data: EtatSynchro }>(`${this.api}/etat`);
  }

  lancer(): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.api}/lancer`, {});
  }
}
