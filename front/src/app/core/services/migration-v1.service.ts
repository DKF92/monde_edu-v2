import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpResponse } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface BaseV2 {
  nom: string;
  actuelle: boolean;
  /** Schema V2 present (sinon : base vide ou non V2). */
  v2: boolean;
  vide: boolean;
  etablissements: string[];
  eleves: number;
  /** Mon compte existe (actif) dans cette base : je pourrai m'y connecter. */
  compte_present: boolean;
}

export interface MigrationLancee {
  cle: string;
  cible: string;
  debut: string;
  essai: boolean;
}

export interface ResumeMigration {
  date: string;
  cible: string;
  essai: boolean;
  duree: number;
  erreur: string | null;
  compteurs: Record<string, number | string>;
  controles: { libelle: string; v1: number; v2: number }[];
  anomalies: { code: string; libelle: string; nombre: number }[];
  rapport: string;
  mots_de_passe: string | null;
}

export interface EtatMigration {
  serveur_local: boolean;
  base_actuelle: string;
  bases: BaseV2[];
  cible_defaut: string;
  dumps: { nom: string; taille: number; date: string }[];
  en_cours: MigrationLancee | null;
  derniers: {
    date: string | null;
    cible: string | null;
    essai: boolean;
    erreur: string | null;
    duree: number | null;
    ecarts: number;
    controles: number;
    rapport: string | null;
    mots_de_passe: string | null;
  }[];
}

export interface SuiviMigration {
  cible: string;
  essai: boolean;
  debut: string;
  lignes: string[];
  termine: boolean;
  succes: boolean;
  activee: boolean;
  resume: ResumeMigration | null;
}

/** Parametres > Migration V1 : reprise des donnees V1 et base utilisee par l'application. */
@Injectable({ providedIn: 'root' })
export class MigrationV1Service {
  private readonly http = inject(HttpClient);
  private readonly api = `${environment.apiUrl}/migration-v1`;

  etat(): Observable<{ data: EtatMigration }> {
    return this.http.get<{ data: EtatMigration }>(this.api);
  }

  lancer(cible: string, essai: boolean, activer: boolean): Observable<{ message: string; data: MigrationLancee }> {
    return this.http.post<{ message: string; data: MigrationLancee }>(`${this.api}/lancer`, { cible, essai, activer });
  }

  /** Journal en temps reel (la cle suffit : la session peut disparaitre au basculement). */
  suivi(cle: string): Observable<{ data: SuiviMigration }> {
    return this.http.get<{ data: SuiviMigration }>(`${this.api}/suivi/${cle}`);
  }

  basculer(base: string): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.api}/basculer`, { base });
  }

  fichier(nom: string): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.api}/fichiers/${nom}`, { responseType: 'blob', observe: 'response' });
  }
}
