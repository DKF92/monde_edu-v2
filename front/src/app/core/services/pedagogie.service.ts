import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';

// ------------------------------------------------------------ Matieres

export type Groupe = 'LITTERAIRE' | 'SCIENTIFIQUE' | 'AUTRES';

export interface MatiereParametre {
  id: number;
  code: string;
  libelle: string;
  groupe: Groupe;
  reservee: boolean;
  utilisee: boolean;
  coefficients: Record<number, { coefficient: number; obligatoire: boolean }>;
}

export interface ParametresMatieres {
  groupes: { valeur: Groupe; libelle: string }[];
  niveaux: { id: number; code: string; libelle: string; cycle: string }[];
  matieres: MatiereParametre[];
}

export interface EnseignantsClasse {
  matieres: { id: number; libelle: string; coefficient: number; personnel_id: number | null }[];
  personnels: { id: number; nom: string; matieres: number[] }[];
}

// ------------------------------------------------------------ Notes

export interface Periode {
  id: number;
  libelle: string;
  numero?: number;
  active: boolean;
  cloturee: boolean;
}

/** Parametres > Arret des notes : etat d'une classe pour la periode. */
export interface ClasseArret {
  id: number;
  libelle: string;
  niveau: string | null;
  effectif: number;
  matieres: number;
  matieres_notees: number;
  notes: number;
  matieres_arretees: number;
}

export interface PeriodeArret extends Periode {
  date_debut: string | null;
  date_fin: string | null;
}

export interface PageArretNotes {
  periodes: PeriodeArret[];
  periode: PeriodeArret;
  classes: ClasseArret[];
  totaux: { classes: number; classes_arretees: number; classes_notees: number; notes: number };
}

export interface OptionsEvaluations {
  periodes: Periode[];
  baremes: number[];
  peut_arreter: boolean;
  classes: {
    id: number;
    libelle: string;
    niveau: string | null;
    matieres: { id: number; libelle: string; coefficient: number; peut_saisir: boolean }[];
  }[];
}

export interface Evaluation {
  numero: number;
  bareme: number;
  date: string | null;
  nombre: number;
  moyenne: number;
}

export interface LigneNotes {
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F';
  notes: Record<number, number>;
  moyenne: number | null;
  rang: number | null;
  rang_ex_aequo: boolean;
  arretee: boolean;
  appreciation: string | null;
}

export interface GrilleNotes {
  classe: { id: number; libelle: string };
  matiere: { id: number; libelle: string; coefficient: number; langue: string | null };
  periode: { id: number; libelle: string; cloturee: boolean };
  evaluations: Evaluation[];
  eleves: LigneNotes[];
  arretee: boolean;
  moyenne_classe: number | null;
  peut_saisir: boolean;
  peut_arreter: boolean;
}

export interface SaisieEvaluation {
  classe_id: number;
  matiere_id: number;
  periode_id: number;
  numero: number | null;
  bareme: number;
  date: string | null;
  notes: { eleve_id: number; valeur: number | null }[];
}

// ------------------------------------------------------------ Resultats

export interface MatiereResultat {
  id: number;
  code: string;
  libelle: string;
  groupe: Groupe;
  coefficient: number;
  conduite: boolean;
  langue: string | null;
  evaluations: number;
  arretee: boolean;
}

export interface LigneResultat {
  eleve_id: number;
  inscription_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F';
  affecte: boolean;
  redoublant: boolean;
  moyennes: Record<number, { moyenne: number; arretee: boolean; rang?: number; appreciation?: string }>;
  total_points: number;
  total_coefficients: number;
  moyenne: number | null;
  lettres: number | null;
  sciences: number | null;
  rang: number | null;
  rang_ex_aequo: boolean;
  conduite: number | null;
  distinction: string | null;
  sanctions: string[];
  appreciation: string | null;
  periodes?: Record<number, number | null>;
  decision: string | null;
  decision_proposee?: string | null;
}

export interface StatistiquesResultats {
  effectif: number;
  classes: number;
  moyenne_classe: number | null;
  maximum: number | null;
  minimum: number | null;
  moyenne_10: number;
  taux_reussite: number | null;
  filles: { classes: number; admis: number };
  garcons: { classes: number; admis: number };
}

export interface Resultats {
  classe: { id: number; libelle: string; niveau: string | null };
  periode: { id: number | null; libelle: string; cloturee: boolean };
  annuel: boolean;
  enregistre_le: string | null;
  peut_gerer: boolean;
  peut_bulletins: boolean;
  decisions: string[];
  matieres: MatiereResultat[];
  periodes: { id: number; libelle: string; poids: number }[];
  eleves: LigneResultat[];
  statistiques: StatistiquesResultats;
}

// ------------------------------------------------------------ Absences

export interface Absence {
  id: number;
  eleve: { id: number; matricule: string; nom: string; prenoms: string; sexe: 'M' | 'F' } | null;
  classe: string | null;
  classe_id: number | null;
  periode: string | null;
  periode_id: number | null;
  date_debut: string;
  date_fin: string | null;
  nombre_heures: number;
  is_justifiee: boolean;
  motif: string | null;
  saisi_par: string | null;
}

export interface PageAbsences {
  data: Absence[];
  total: number;
  page: number;
  par_page: number;
  totaux: { nombre: number; heures: number; justifiees: number; non_justifiees: number; eleves: number };
  periodes: Periode[];
  classes: { id: number; libelle: string }[];
}

export interface FiltresAbsences {
  periode_id?: number | null;
  classe_id?: number | null;
  justifiee?: boolean | null;
  recherche?: string;
  page?: number;
  par_page?: number;
}

export interface SaisieAbsence {
  periode_id: number;
  date_debut: string;
  date_fin: string | null;
  nombre_heures: number;
  is_justifiee: boolean;
  motif: string | null;
  eleve_ids?: number[];
}

export interface EleveAppel {
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F';
  heures: number;
  non_justifiees: number;
}

export interface GrilleConduite {
  classe: { id: number; libelle: string };
  periode: { id: number; libelle: string; cloturee: boolean };
  enseignee: boolean;
  eleves: { eleve_id: number; matricule: string; nom: string; prenoms: string; sexe: 'M' | 'F'; note: number | null; heures_non_justifiees: number }[];
}

// ------------------------------------------------------------ Rapports

export interface ColonneRapport {
  cle: string;
  libelle: string;
  type?: 'texte' | 'nombre' | 'moyenne' | 'pourcent' | 'centre' | 'montant' | 'libre';
  fort?: boolean;
}

export interface Rapport {
  code: string;
  titre: string;
  annee: string;
  periode: string | null;
  sous_titre?: string;
  colonnes: ColonneRapport[];
  groupes: { titre: string | null; lignes: Record<string, unknown>[]; total: Record<string, unknown> | null }[];
  total: Record<string, unknown> | null;
}

export interface AccueilRapports {
  annee: string;
  rubriques: string[];
  rapports: { code: string; titre: string; description: string; periode: boolean; rubrique: string; masque: boolean }[];
  /** Retirer / remettre des rapports du catalogue (droit etablissement.gerer). */
  peut_masquer: boolean;
  periodes: { id: number; libelle: string; active: boolean }[];
  indicateurs: { eleves: number; filles: number; affectes: number; classes: number; heures_absence: number; heures_non_justifiees: number; notes: number };
}

export const LIBELLES_DECISION: Partial<Record<string, string>> = { ADMIS: 'Admis(e)', REDOUBLE: 'Redouble', EXCLU: 'Exclu(e)' };

/**
 * Pedagogie : matieres et coefficients, notes, resultats et bulletins,
 * absences et conduite, rapports.
 */
@Injectable({ providedIn: 'root' })
export class PedagogieService {
  private readonly http = inject(HttpClient);
  private readonly documents = inject(DocumentService);
  private readonly api = environment.apiUrl;

  // Matieres
  matieres(): Observable<ParametresMatieres> {
    return this.http.get<ParametresMatieres>(`${this.api}/matieres`);
  }
  creerMatiere(m: { code: string; libelle: string; groupe: Groupe }): Observable<ParametresMatieres> {
    return this.http.post<ParametresMatieres>(`${this.api}/matieres`, m);
  }
  modifierMatiere(id: number, m: { code: string; libelle: string; groupe: Groupe }): Observable<ParametresMatieres> {
    return this.http.put<ParametresMatieres>(`${this.api}/matieres/${id}`, m);
  }
  supprimerMatiere(id: number): Observable<ParametresMatieres> {
    return this.http.delete<ParametresMatieres>(`${this.api}/matieres/${id}`);
  }
  enregistrerCoefficients(coefficients: { matiere_id: number; niveau_id: number; coefficient: number | null; obligatoire: boolean }[]): Observable<ParametresMatieres> {
    return this.http.put<ParametresMatieres>(`${this.api}/matieres/coefficients`, { coefficients });
  }
  enseignants(classeId: number): Observable<EnseignantsClasse> {
    return this.http.get<EnseignantsClasse>(`${this.api}/classes/${classeId}/enseignants`);
  }
  enregistrerEnseignants(classeId: number, enseignants: { matiere_id: number; personnel_id: number | null }[]): Observable<EnseignantsClasse> {
    return this.http.put<EnseignantsClasse>(`${this.api}/classes/${classeId}/enseignants`, { enseignants });
  }

  // Notes
  options(): Observable<OptionsEvaluations> {
    return this.http.get<OptionsEvaluations>(`${this.api}/evaluations/options`);
  }
  notes(classeId: number, matiereId: number, periodeId: number): Observable<GrilleNotes> {
    return this.http.get<GrilleNotes>(`${this.api}/notes`, { params: { classe_id: classeId, matiere_id: matiereId, periode_id: periodeId } });
  }
  enregistrerEvaluation(s: SaisieEvaluation): Observable<GrilleNotes & { numero: number }> {
    return this.http.put<GrilleNotes & { numero: number }>(`${this.api}/notes`, s);
  }
  supprimerEvaluation(classeId: number, matiereId: number, periodeId: number, numero: number): Observable<GrilleNotes> {
    return this.http.delete<GrilleNotes>(`${this.api}/notes/evaluation`, { params: { classe_id: classeId, matiere_id: matiereId, periode_id: periodeId, numero } });
  }

  // Arret des notes et cloture des periodes (notes.arreter, directeur)
  arretNotes(periodeId?: number): Observable<PageArretNotes> {
    return this.http.get<PageArretNotes>(`${this.api}/arret-notes`, { params: periodeId ? { periode_id: periodeId } : {} });
  }
  /** classeId null = toutes les classes ; matiereId null = toutes les matieres. */
  arreter(periodeId: number, classeId: number | null, matiereId: number | null = null): Observable<{ message: string; nombre: number }> {
    return this.http.post<{ message: string; nombre: number }>(`${this.api}/arret-notes/arreter`, { periode_id: periodeId, classe_id: classeId, matiere_id: matiereId });
  }
  rouvrir(periodeId: number, classeId: number | null, matiereId: number | null = null): Observable<{ message: string; nombre: number }> {
    return this.http.post<{ message: string; nombre: number }>(`${this.api}/arret-notes/rouvrir`, { periode_id: periodeId, classe_id: classeId, matiere_id: matiereId });
  }
  cloturerPeriode(periodeId: number, cloturee: boolean): Observable<{ message: string }> {
    return this.http.patch<{ message: string }>(`${this.api}/arret-notes/periodes/${periodeId}/cloture`, { cloturee });
  }

  // Resultats
  resultats(classeId: number, periode: number | 'annuel'): Observable<Resultats> {
    return this.http.get<Resultats>(`${this.api}/resultats`, { params: { classe_id: classeId, periode } });
  }
  enregistrerResultats(classeId: number, periodeId: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.api}/resultats/enregistrer`, { classe_id: classeId, periode: periodeId });
  }
  enregistrerDecisions(classeId: number, decisions: { inscription_id: number; decision: string | null }[]): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.api}/resultats/decisions`, { classe_id: classeId, decisions });
  }
  imprimerResultats(classe: { id: number; libelle: string }, periode: number | 'annuel', libellePeriode: string): Promise<void> {
    return this.documents.afficher({
      titre: `Résultats ${classe.libelle} · ${libellePeriode}`,
      chemin: '/resultats/document',
      params: new HttpParams().set('classe_id', classe.id).set('periode', periode),
      nomFichier: `resultats-${this.slug(classe.libelle)}`,
    });
  }
  imprimerBulletins(classe: { id: number; libelle: string }, periode: number | 'annuel', libellePeriode: string, eleve?: { id: number; nom: string }): Promise<void> {
    let params = new HttpParams().set('classe_id', classe.id).set('periode', periode);
    if (eleve) params = params.set('eleve_id', eleve.id);
    return this.documents.afficher({
      titre: eleve ? `Bulletin de ${eleve.nom} · ${libellePeriode}` : `Bulletins ${classe.libelle} · ${libellePeriode}`,
      chemin: '/bulletins/document',
      params,
      nomFichier: eleve ? `bulletin-${this.slug(eleve.nom)}` : `bulletins-${this.slug(classe.libelle)}`,
    });
  }

  // Absences
  absences(f: FiltresAbsences): Observable<PageAbsences> {
    return this.http.get<PageAbsences>(`${this.api}/absences`, { params: this.parametresAbsences(f) });
  }
  elevesAppel(classeId: number, periodeId: number | null): Observable<{ classe: { id: number; libelle: string }; eleves: EleveAppel[] }> {
    const params = periodeId ? { periode_id: periodeId } : undefined;
    return this.http.get<{ classe: { id: number; libelle: string }; eleves: EleveAppel[] }>(`${this.api}/absences/classe/${classeId}`, { params });
  }
  creerAbsences(s: SaisieAbsence): Observable<{ message: string; ids: number[] }> {
    return this.http.post<{ message: string; ids: number[] }>(`${this.api}/absences`, s);
  }
  modifierAbsence(id: number, s: SaisieAbsence): Observable<Absence> {
    return this.http.put<Absence>(`${this.api}/absences/${id}`, s);
  }
  justifier(id: number, justifiee: boolean, motif: string | null): Observable<Absence> {
    return this.http.patch<Absence>(`${this.api}/absences/${id}/justifier`, { is_justifiee: justifiee, motif });
  }
  supprimerAbsence(id: number): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.api}/absences/${id}`);
  }
  imprimerAbsences(f: FiltresAbsences, titre: string): Promise<void> {
    return this.documents.afficher({ titre, chemin: '/absences/document', params: this.parametresAbsences({ ...f, page: undefined, par_page: undefined }), nomFichier: 'absences' });
  }
  conduite(classeId: number, periodeId: number): Observable<GrilleConduite> {
    return this.http.get<GrilleConduite>(`${this.api}/conduite`, { params: { classe_id: classeId, periode_id: periodeId } });
  }
  enregistrerConduite(classeId: number, periodeId: number, notes: { eleve_id: number; valeur: number | null }[]): Observable<GrilleConduite> {
    return this.http.put<GrilleConduite>(`${this.api}/conduite`, { classe_id: classeId, periode_id: periodeId, notes });
  }

  // Rapports
  accueilRapports(): Observable<AccueilRapports> {
    return this.http.get<AccueilRapports>(`${this.api}/rapports`);
  }
  masquerRapports(codes: string[]): Observable<{ masques: string[] }> {
    return this.http.put<{ masques: string[] }>(`${this.api}/rapports/masques`, { codes });
  }
  rapport(code: string, params: Record<string, string | number>): Observable<Rapport> {
    return this.http.get<Rapport>(`${this.api}/rapports/${code}`, { params });
  }
  imprimerRapport(code: string, params: Record<string, string | number>, titre: string): Promise<void> {
    let p = new HttpParams();
    for (const [k, v] of Object.entries(params)) p = p.set(k, v);
    return this.documents.afficher({ titre, chemin: `/rapports/${code}/document`, params: p, nomFichier: `rapport-${code}` });
  }

  private parametresAbsences(f: FiltresAbsences): HttpParams {
    let p = new HttpParams();
    if (f.page) p = p.set('page', f.page);
    if (f.par_page) p = p.set('par_page', f.par_page);
    if (f.periode_id) p = p.set('periode_id', f.periode_id);
    if (f.classe_id) p = p.set('classe_id', f.classe_id);
    if (f.justifiee !== null && f.justifiee !== undefined) p = p.set('justifiee', f.justifiee ? 1 : 0);
    if (f.recherche?.trim()) p = p.set('recherche', f.recherche.trim());
    return p;
  }

  private slug(texte: string): string {
    return texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-');
  }
}
