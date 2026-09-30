import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';
import { Rapport } from './pedagogie.service';

// ------------------------------------------------------------ Rapports officiels

export type TypeRapportOfficiel = 'rentree' | 'periode' | 'annuel';

export interface TextesRapport {
  introduction: string | null;
  observations: string | null;
  difficultes: string | null;
  perspectives: string | null;
  conclusion: string | null;
  signataire: string | null;
}

export interface CatalogueOfficiels {
  types: { code: TypeRapportOfficiel; titre: string; description: string }[];
  periodes: { id: number; libelle: string; active: boolean }[];
  /** Textes deja rediges : cle "rentree", "annuel" ou "periode-{id}". */
  textes: Record<string, TextesRapport>;
}

// ------------------------------------------------------------ Rapports parametrables

export interface ColonnePerso {
  type: 'champ' | 'libre';
  cle: string | null;
  /** En-tete (obligatoire pour une colonne libre, facultatif pour un champ). */
  libelle: string | null;
}

export interface ConfigRapportPerso {
  id?: string;
  titre: string;
  regroupement: 'etablissement' | 'cycle' | 'niveau' | 'classe';
  tri: 'nom' | 'matricule' | 'date_naissance' | 'moyenne';
  numeroter: boolean;
  saut_page: boolean;
  sans_classe: boolean;
  periode_id: number | null;
  colonnes: ColonnePerso[];
  filtres: {
    sexe?: 'M' | 'F';
    affecte?: boolean;
    redoublant?: boolean;
    cycles?: string[];
    niveaux?: number[];
    classes?: number[];
  };
}

export interface CataloguePerso {
  champs: { cle: string; libelle: string; rubrique: string }[];
  regroupements: { valeur: string; libelle: string }[];
  tris: { valeur: string; libelle: string }[];
  cycles: { valeur: string; libelle: string }[];
  niveaux: { id: number; libelle: string; cycle: string }[];
  classes: { id: number; libelle: string; niveau_id: number }[];
  periodes: { id: number; libelle: string }[];
  modeles: ConfigRapportPerso[];
  peut_enregistrer: boolean;
}

export type ApercuPerso = Omit<Rapport, 'code' | 'annee' | 'periode' | 'groupes'> & {
  effectif: number;
  groupes: { titre: string | null; effectif: number; filles: number; garcons: number; lignes: Record<string, unknown>[]; total: null }[];
};

/** Rapports officiels (rentree, periode, annuel) et rapports parametrables. */
@Injectable({ providedIn: 'root' })
export class RapportsAvancesService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly api = `${environment.apiUrl}/rapports`;

  officiels(): Observable<CatalogueOfficiels> {
    return this.http.get<CatalogueOfficiels>(`${this.api}/compiles`);
  }

  enregistrerTextes(type: TypeRapportOfficiel, periodeId: number | null, textes: TextesRapport): Observable<{ message: string; textes: TextesRapport }> {
    const params = periodeId ? new HttpParams().set('periode', periodeId) : undefined;
    return this.http.put<{ message: string; textes: TextesRapport }>(`${this.api}/compiles/${type}/textes`, textes, { params });
  }

  imprimerOfficiel(type: TypeRapportOfficiel, periodeId: number | null, titre: string): Promise<void> {
    const params = periodeId ? new HttpParams().set('periode', periodeId) : undefined;
    return this.documents.afficher({ titre, chemin: `/rapports/compiles/${type}/document`, params, nomFichier: `rapport-${type}` });
  }

  personnalises(): Observable<CataloguePerso> {
    return this.http.get<CataloguePerso>(`${this.api}/personnalises`);
  }

  apercu(config: ConfigRapportPerso): Observable<ApercuPerso> {
    return this.http.post<ApercuPerso>(`${this.api}/personnalises/apercu`, config);
  }

  enregistrer(config: ConfigRapportPerso): Observable<{ message: string; modele: ConfigRapportPerso }> {
    return config.id
      ? this.http.put<{ message: string; modele: ConfigRapportPerso }>(`${this.api}/personnalises/${config.id}`, config)
      : this.http.post<{ message: string; modele: ConfigRapportPerso }>(`${this.api}/personnalises`, config);
  }

  supprimer(id: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.api}/personnalises/${id}`);
  }

  /** Modele enregistre (id) ou configuration en cours. */
  imprimerPersonnalise(config: ConfigRapportPerso): Promise<void> {
    const params = config.id ? new HttpParams().set('modele', config.id) : new HttpParams().set('config', JSON.stringify(config));
    return this.documents.afficher({ titre: config.titre, chemin: '/rapports/personnalises/document', params, nomFichier: 'rapport-personnalise' });
  }
}
