import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';

export type Numerotation = 'chiffres' | 'lettres';

export type Cycle = 'maternelle' | 'primaire' | 'college' | 'lycee';

export interface NiveauClasse {
  id: number;
  code: string;
  libelle: string;
  cycle: Cycle;
  ordre: number;
}

export interface Personne {
  id: number;
  nom: string;
}

export interface Classe {
  id: number;
  libelle: string;
  /** Nom court, sans le niveau ("A", "1"). */
  nom: string;
  niveau: NiveauClasse | null;
  salle: string | null;
  capacite: number | null;
  limite: number | null;
  source_limite: 'classe' | 'niveau' | 'etablissement' | null;
  langue_vivante_2: string | null;
  professeur_principal: Personne | null;
  educateur: Personne | null;
  effectif: number;
  garcons: number;
  filles: number;
  affectes: number;
  non_affectes: number;
  redoublants: number;
  attente_versement: number;
  attente_solde: number;
  soldes: number;
  complete: boolean;
}

export interface TotauxClasses {
  classes: number;
  effectif: number;
  garcons: number;
  filles: number;
  affectes: number;
  non_affectes: number;
  redoublants: number;
  completes: number;
}

export interface ListeClasses {
  annee: string;
  annee_cloturee: boolean;
  data: Classe[];
  totaux: TotauxClasses;
  niveaux: NiveauClasse[];
  /** Numerotation des classes (Parametres > Classes). */
  numerotation: Numerotation;
  /** Nom propose pour la prochaine classe de chaque niveau (id du niveau). */
  prochains: Record<number, string | null>;
  niveaux_restreints: boolean;
  personnels: Personne[];
  langues: string[];
}

export interface EleveClasse {
  inscription_id: number;
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F';
  date_naissance: string | null;
  lieu_naissance: string | null;
  telephone: string | null;
  affecte: boolean;
  redoublant: boolean;
  langue_vivante_2: string | null;
  statut: number;
}

export interface ListeDeClasse {
  annee: string;
  classe: Classe;
  eleves: EleveClasse[];
  autres: { id: number; libelle: string }[];
}

export interface SaisieClasse {
  niveau_id: number;
  nom: string;
  salle: string | null;
  capacite: number | null;
  langue_vivante_2: string | null;
  professeur_principal_id: number | null;
  educateur_id: number | null;
}

export const LIBELLES_CYCLE: Record<Cycle, string> = {
  maternelle: 'Maternelle',
  primaire: 'Primaire',
  college: 'Premier cycle',
  lycee: 'Second cycle',
};

/** Classes de l'annee de travail (classes.voir / classes.gerer). */
@Injectable({ providedIn: 'root' })
export class ClasseService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly url = `${environment.apiUrl}/classes`;

  lister(): Observable<ListeClasses> {
    return this.http.get<ListeClasses>(this.url);
  }

  liste(id: number): Observable<ListeDeClasse> {
    return this.http.get<ListeDeClasse>(`${this.url}/${id}`);
  }

  creer(saisie: SaisieClasse): Observable<Classe> {
    return this.http.post<Classe>(this.url, saisie);
  }

  /** Plusieurs classes d'un niveau, numerotees a la suite de la plus grande. */
  creerLot(niveauId: number, nombre: number): Observable<Classe[]> {
    return this.http.post<Classe[]>(`${this.url}/lot`, { niveau_id: niveauId, nombre });
  }

  modifier(id: number, saisie: SaisieClasse): Observable<Classe> {
    return this.http.put<Classe>(`${this.url}/${id}`, saisie);
  }

  supprimer(id: number): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.url}/${id}`);
  }

  /** Liste des classes : les classes choisies (ids) ou d'un niveau / cycle. */
  imprimerClasses(filtres: { ids?: number[]; niveau_id?: number | null; cycle?: string | null }, annee: string): Promise<void> {
    let params = new HttpParams();
    if (filtres.ids) params = params.set('ids', filtres.ids.join(',') || '0');
    if (filtres.niveau_id) params = params.set('niveau_id', filtres.niveau_id);
    if (filtres.cycle) params = params.set('cycle', filtres.cycle);
    return this.documents.afficher({ titre: `Liste des classes ${annee}`, chemin: '/classes/document', params, nomFichier: `classes-${annee}` });
  }

  /** Listes des eleves de plusieurs classes (une classe par page). */
  imprimerListes(classes: Classe[], annee: string): Promise<void> {
    const params = new HttpParams().set('ids', classes.map((c) => c.id).join(','));
    return this.documents.afficher({
      titre: classes.length === 1 ? `Liste de classe ${classes[0].libelle}` : `Listes de ${classes.length} classes ${annee}`,
      chemin: '/classes/listes/document',
      params,
      nomFichier: `listes-classes-${annee}`,
    });
  }

  /** Liste de classe : tous les eleves, ou filtres (affectes / non affectes, sexe). */
  imprimerListe(classe: Classe, filtres: { affecte: boolean | null; sexe: 'M' | 'F' | null; redoublant?: boolean | null }, libelleFiltre: string): Promise<void> {
    let params = new HttpParams();
    if (filtres.affecte !== null) params = params.set('affecte', filtres.affecte ? 1 : 0);
    if (filtres.redoublant != null) params = params.set('redoublant', filtres.redoublant ? 1 : 0);
    if (filtres.sexe) params = params.set('sexe', filtres.sexe);
    return this.documents.afficher({
      titre: `Liste de classe ${classe.libelle}${libelleFiltre ? ' · ' + libelleFiltre : ''}`,
      chemin: `/classes/${classe.id}/document`,
      params,
      nomFichier: `liste-${classe.libelle.toLowerCase().replace(/\s+/g, '-')}`,
    });
  }
}
