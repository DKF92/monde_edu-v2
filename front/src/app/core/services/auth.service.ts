import { Injectable, computed, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AnneeScolaire,
  Etablissement,
  LoginResponse,
  Periode,
  Poste,
  User,
  PERMISSION_CHOIX_PERIODE,
} from '../models/user.model';

const STORAGE_TOKEN = 'monde_edu_token';
const STORAGE_USER = 'monde_edu_user';
const STORAGE_ETABLISSEMENTS = 'monde_edu_etablissements';
const STORAGE_ETABLISSEMENT_ACTIF = 'monde_edu_etablissement_actif';
const STORAGE_ANNEE_SCOLAIRE = 'monde_edu_annee_scolaire';
const STORAGE_PERIODE = 'monde_edu_periode';
const STORAGE_POSTE = 'monde_edu_poste';
/** Dernier espace de travail utilise, conserve apres deconnexion pour
 * le proposer par defaut a la prochaine connexion du meme compte. */
const STORAGE_DERNIER_CONTEXTE = 'monde_edu_dernier_contexte';

/** Espace de travail choisi juste apres la connexion. */
export interface Contexte {
  etablissement: Etablissement;
  anneeScolaire: AnneeScolaire;
  periode: Periode | null;
  poste: Poste;
}

export interface DernierContexte {
  userId: number;
  etablissementId: number;
  posteId: number;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly tokenSignal = signal<string | null>(localStorage.getItem(STORAGE_TOKEN));
  private readonly userSignal = signal<User | null>(this.lire<User>(STORAGE_USER));
  private readonly etablissementsSignal = signal<Etablissement[]>(this.lire<Etablissement[]>(STORAGE_ETABLISSEMENTS) ?? []);
  private readonly etablissementActifSignal = signal<Etablissement | null>(this.lire<Etablissement>(STORAGE_ETABLISSEMENT_ACTIF));
  private readonly anneeScolaireSignal = signal<AnneeScolaire | null>(this.lire<AnneeScolaire>(STORAGE_ANNEE_SCOLAIRE));
  private readonly periodeSignal = signal<Periode | null>(this.lire<Periode>(STORAGE_PERIODE));
  private readonly posteSignal = signal<Poste | null>(this.lire<Poste>(STORAGE_POSTE));

  readonly user = this.userSignal.asReadonly();
  readonly etablissements = this.etablissementsSignal.asReadonly();
  readonly etablissementActif = this.etablissementActifSignal.asReadonly();
  readonly anneeScolaire = this.anneeScolaireSignal.asReadonly();
  readonly periode = this.periodeSignal.asReadonly();
  readonly poste = this.posteSignal.asReadonly();
  readonly estConnecte = computed(() => !!this.tokenSignal());
  /** Mot de passe provisoire (creation ou reinitialisation) : a changer avant tout. */
  readonly doitChangerMotDePasse = computed(() => !!this.userSignal()?.doit_changer_mot_de_passe);

  /** Etablissement + annee + poste choisis : prerequis de tous les ecrans metier. */
  readonly contexteComplet = computed(
    () => !!this.etablissementActifSignal() && !!this.anneeScolaireSignal() && !!this.posteSignal(),
  );

  /** Droits effectifs = ceux du poste choisi (l'API applique la meme regle
   * via l'en-tete X-Poste-Id). */
  private readonly permissions = computed(() => this.posteSignal()?.permissions ?? []);

  /** Le poste choisi peut-il changer de periode (trimestre/semestre) ? */
  readonly peutChoisirPeriode = computed(() => this.permissions().includes(PERMISSION_CHOIX_PERIODE));

  /** Prenom usuel pour les salutations ("Bonjour Fabrice"). */
  readonly prenom = computed(() => {
    const user = this.userSignal();
    return (user?.prenoms?.trim().split(/\s+/)[0] || user?.name || '').trim();
  });

  readonly nomComplet = computed(() => {
    const user = this.userSignal();
    return user ? [user.prenoms, user.name].filter(Boolean).join(' ') : '';
  });

  readonly initiales = computed(() => {
    const user = this.userSignal();
    const lettres = [user?.prenoms, user?.name].map((mot) => mot?.trim().charAt(0) ?? '').join('');
    return lettres.toUpperCase() || '?';
  });

  /** Libelle du poste choisi (ex: "Directeur des etudes"). */
  readonly role = computed(() => this.posteSignal()?.nom ?? '');

  constructor(private http: HttpClient) {}

  token(): string | null {
    return this.tokenSignal();
  }

  aPermission(permission: string): boolean {
    return this.permissions().includes(permission);
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${environment.apiUrl}/login`, { email, password }).pipe(
      tap((reponse) => {
        this.effacerSession();

        this.tokenSignal.set(reponse.token);
        this.userSignal.set(reponse.user);
        this.etablissementsSignal.set(reponse.etablissements);

        localStorage.setItem(STORAGE_TOKEN, reponse.token);
        localStorage.setItem(STORAGE_USER, JSON.stringify(reponse.user));
        localStorage.setItem(STORAGE_ETABLISSEMENTS, JSON.stringify(reponse.etablissements));
      }),
    );
  }

  /** Choix du mot de passe (obligatoire apres un mot de passe provisoire). */
  changerMotDePasse(actuel: string, nouveau: string, confirmation: string): Observable<{ data: User }> {
    return this.http
      .post<{ data: User }>(`${environment.apiUrl}/mot-de-passe`, { actuel, nouveau, nouveau_confirmation: confirmation })
      .pipe(tap((r) => this.enregistrer(this.userSignal, STORAGE_USER, r.data)));
  }

  /** L'API signale un mot de passe provisoire (session ouverte avant la reinitialisation). */
  exigerChangementMotDePasse(): void {
    const user = this.userSignal();
    if (user && !user.doit_changer_mot_de_passe) {
      this.enregistrer(this.userSignal, STORAGE_USER, { ...user, doit_changer_mot_de_passe: true });
    }
  }

  logout(): void {
    if (this.tokenSignal()) {
      // Revoque le jeton cote serveur (sans attendre : la deconnexion locale
      // doit rester immediate, meme hors ligne).
      this.http.post(`${environment.apiUrl}/logout`, {}).subscribe({ error: () => undefined });
    }
    this.effacerSession();
  }

  /** Apres modification dans Parametres > Etablissement (nom, sigle, ville...). */
  majEtablissementActif(modif: Partial<Etablissement>): void {
    const actuel = this.etablissementActifSignal();
    if (actuel) {
      this.enregistrer(this.etablissementActifSignal, STORAGE_ETABLISSEMENT_ACTIF, { ...actuel, ...modif });
    }
  }

  /** Enregistre l'espace de travail choisi (ecran "contexte"). */
  appliquerContexte(contexte: Contexte): void {
    this.enregistrer(this.etablissementActifSignal, STORAGE_ETABLISSEMENT_ACTIF, contexte.etablissement);
    this.enregistrer(this.anneeScolaireSignal, STORAGE_ANNEE_SCOLAIRE, contexte.anneeScolaire);
    this.enregistrer(this.periodeSignal, STORAGE_PERIODE, contexte.periode);
    this.enregistrer(this.posteSignal, STORAGE_POSTE, contexte.poste);

    const user = this.userSignal();
    if (user) {
      const dernier: DernierContexte = {
        userId: user.id,
        etablissementId: contexte.etablissement.id,
        posteId: contexte.poste.id,
      };
      localStorage.setItem(STORAGE_DERNIER_CONTEXTE, JSON.stringify(dernier));
    }
  }

  /** Dernier espace de travail de l'utilisateur connecte (pre-selection). */
  dernierContexte(): DernierContexte | null {
    const dernier = this.lire<DernierContexte>(STORAGE_DERNIER_CONTEXTE);
    return dernier && dernier.userId === this.userSignal()?.id ? dernier : null;
  }

  choisirPeriode(periode: Periode): void {
    this.enregistrer(this.periodeSignal, STORAGE_PERIODE, periode);
  }

  private effacerSession(): void {
    this.tokenSignal.set(null);
    this.userSignal.set(null);
    this.etablissementsSignal.set([]);
    this.etablissementActifSignal.set(null);
    this.anneeScolaireSignal.set(null);
    this.periodeSignal.set(null);
    this.posteSignal.set(null);
    [
      STORAGE_TOKEN,
      STORAGE_USER,
      STORAGE_ETABLISSEMENTS,
      STORAGE_ETABLISSEMENT_ACTIF,
      STORAGE_ANNEE_SCOLAIRE,
      STORAGE_PERIODE,
      STORAGE_POSTE,
    ].forEach((cle) => localStorage.removeItem(cle));
  }

  private enregistrer<T>(cible: { set(valeur: T | null): void }, cle: string, valeur: T | null): void {
    cible.set(valeur);
    if (valeur === null) {
      localStorage.removeItem(cle);
    } else {
      localStorage.setItem(cle, JSON.stringify(valeur));
    }
  }

  private lire<T>(cle: string): T | null {
    const valeur = localStorage.getItem(cle);
    return valeur ? (JSON.parse(valeur) as T) : null;
  }
}
