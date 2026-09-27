import { Injectable, computed, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AnneeScolaire,
  Etablissement,
  LoginResponse,
  Periode,
  User,
  PERMISSIONS_PEDAGOGIQUES,
} from '../models/user.model';

const STORAGE_TOKEN = 'monde_edu_token';
const STORAGE_USER = 'monde_edu_user';
const STORAGE_ETABLISSEMENTS = 'monde_edu_etablissements';
const STORAGE_ETABLISSEMENT_ACTIF = 'monde_edu_etablissement_actif';
const STORAGE_ETABLISSEMENT_CONNEXION = 'monde_edu_etablissement_connexion';
const STORAGE_ANNEE_SCOLAIRE = 'monde_edu_annee_scolaire';
const STORAGE_PERIODE = 'monde_edu_periode';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly tokenSignal = signal<string | null>(localStorage.getItem(STORAGE_TOKEN));
  private readonly userSignal = signal<User | null>(this.lire<User>(STORAGE_USER));
  private readonly etablissementsSignal = signal<Etablissement[]>(this.lire<Etablissement[]>(STORAGE_ETABLISSEMENTS) ?? []);
  private readonly etablissementActifSignal = signal<Etablissement | null>(this.lire<Etablissement>(STORAGE_ETABLISSEMENT_ACTIF));
  /** Etablissement choisi sur l'ecran d'accueil, AVANT authentification (sert a
   * afficher le nom/logo sur le formulaire de connexion). Persiste apres
   * deconnexion pour que l'utilisateur retrouve directement son ecole. */
  private readonly etablissementConnexionSignal = signal<Etablissement | null>(
    this.lire<Etablissement>(STORAGE_ETABLISSEMENT_CONNEXION),
  );
  private readonly anneeScolaireSignal = signal<AnneeScolaire | null>(this.lire<AnneeScolaire>(STORAGE_ANNEE_SCOLAIRE));
  private readonly periodeSignal = signal<Periode | null>(this.lire<Periode>(STORAGE_PERIODE));

  readonly user = this.userSignal.asReadonly();
  readonly etablissements = this.etablissementsSignal.asReadonly();
  readonly etablissementActif = this.etablissementActifSignal.asReadonly();
  readonly etablissementConnexion = this.etablissementConnexionSignal.asReadonly();
  readonly anneeScolaire = this.anneeScolaireSignal.asReadonly();
  readonly periode = this.periodeSignal.asReadonly();
  readonly estConnecte = computed(() => !!this.tokenSignal());

  /** Un profil "finance" (caissier, comptable, econome...) n'a aucune de ces
   * permissions pedagogiques : on ne lui propose donc pas le choix du trimestre. */
  readonly estProfilPedagogique = computed(() => {
    const permissions = this.userSignal()?.permissions ?? [];
    return PERMISSIONS_PEDAGOGIQUES.some((p) => permissions.includes(p));
  });

  constructor(private http: HttpClient) {}

  token(): string | null {
    return this.tokenSignal();
  }

  aPermission(permission: string): boolean {
    return (this.userSignal()?.permissions ?? []).includes(permission);
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${environment.apiUrl}/login`, { email, password }).pipe(
      tap((reponse) => {
        this.tokenSignal.set(reponse.token);
        this.userSignal.set(reponse.user);
        this.etablissementsSignal.set(reponse.etablissements);

        localStorage.setItem(STORAGE_TOKEN, reponse.token);
        localStorage.setItem(STORAGE_USER, JSON.stringify(reponse.user));
        localStorage.setItem(STORAGE_ETABLISSEMENTS, JSON.stringify(reponse.etablissements));

        // Nouvelle connexion : on repart sur un choix d'annee/trimestre frais.
        this.anneeScolaireSignal.set(null);
        this.periodeSignal.set(null);
        localStorage.removeItem(STORAGE_ANNEE_SCOLAIRE);
        localStorage.removeItem(STORAGE_PERIODE);

        // Selection automatique si l'utilisateur n'a acces qu'a un seul etablissement.
        if (reponse.etablissements.length === 1) {
          this.selectionnerEtablissement(reponse.etablissements[0]);
        } else {
          this.etablissementActifSignal.set(null);
          localStorage.removeItem(STORAGE_ETABLISSEMENT_ACTIF);
        }
      }),
    );
  }

  logout(): void {
    this.tokenSignal.set(null);
    this.userSignal.set(null);
    this.etablissementsSignal.set([]);
    this.etablissementActifSignal.set(null);
    this.anneeScolaireSignal.set(null);
    this.periodeSignal.set(null);
    localStorage.removeItem(STORAGE_TOKEN);
    localStorage.removeItem(STORAGE_USER);
    localStorage.removeItem(STORAGE_ETABLISSEMENTS);
    localStorage.removeItem(STORAGE_ETABLISSEMENT_ACTIF);
    localStorage.removeItem(STORAGE_ANNEE_SCOLAIRE);
    localStorage.removeItem(STORAGE_PERIODE);
  }

  selectionnerEtablissement(etablissement: Etablissement): void {
    this.etablissementActifSignal.set(etablissement);
    localStorage.setItem(STORAGE_ETABLISSEMENT_ACTIF, JSON.stringify(etablissement));

    // Un changement d'etablissement invalide l'annee/le trimestre precedemment choisis.
    this.anneeScolaireSignal.set(null);
    this.periodeSignal.set(null);
    localStorage.removeItem(STORAGE_ANNEE_SCOLAIRE);
    localStorage.removeItem(STORAGE_PERIODE);
  }

  choisirEtablissementConnexion(etablissement: Etablissement): void {
    this.etablissementConnexionSignal.set(etablissement);
    localStorage.setItem(STORAGE_ETABLISSEMENT_CONNEXION, JSON.stringify(etablissement));
  }

  oublierEtablissementConnexion(): void {
    this.etablissementConnexionSignal.set(null);
    localStorage.removeItem(STORAGE_ETABLISSEMENT_CONNEXION);
  }

  choisirAnneeScolaire(annee: AnneeScolaire): void {
    this.anneeScolaireSignal.set(annee);
    localStorage.setItem(STORAGE_ANNEE_SCOLAIRE, JSON.stringify(annee));

    // Un changement d'annee invalide le trimestre precedemment choisi.
    this.periodeSignal.set(null);
    localStorage.removeItem(STORAGE_PERIODE);
  }

  choisirPeriode(periode: Periode): void {
    this.periodeSignal.set(periode);
    localStorage.setItem(STORAGE_PERIODE, JSON.stringify(periode));
  }

  private lire<T>(cle: string): T | null {
    const valeur = localStorage.getItem(cle);
    return valeur ? (JSON.parse(valeur) as T) : null;
  }
}
