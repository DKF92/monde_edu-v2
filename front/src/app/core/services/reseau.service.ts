import { Injectable, computed, signal } from '@angular/core';
import { Subject } from 'rxjs';
import { environment } from '../../../environments/environment';

/** Serveur joint : en ligne (internet), serveur de l'ecole (reseau local) ou aucun. */
export type EtatReseau = 'verification' | 'en_ligne' | 'local' | 'hors_ligne';
/** Choix de l'utilisateur (Profil) : automatique, ou forcer un serveur. */
export type ModeServeur = 'auto' | 'en_ligne' | 'local';

const CLE_MODE = 'monde_edu_serveur_mode';
const CLE_LOCAL = 'monde_edu_serveur_local';
const DELAI_PING = 4000;

function lire(cle: string): string | null {
  try {
    return localStorage.getItem(cle);
  } catch {
    return null;
  }
}

function ecrire(cle: string, valeur: string | null): void {
  try {
    if (valeur) localStorage.setItem(cle, valeur);
    else localStorage.removeItem(cle);
  } catch {
    // Stockage indisponible : le choix vaut pour la session.
  }
}

/**
 * Choix du serveur de l'API :
 * - automatique : le serveur de l'ecole s'il repond (il fait foi ; adresse
 *   enregistree dans les parametres de l'etablissement et memorisee a la
 *   connexion), sinon le serveur en ligne, sinon hors ligne ;
 * - l'utilisateur peut forcer « en ligne » ou « local » (Profil).
 * Le serveur indique lui-meme son role (/api/ping) : c'est lui qui donne le
 * libelle affiche.
 */
@Injectable({ providedIn: 'root' })
export class ReseauService {
  /** API de l'adresse d'ou l'application a ete ouverte (le serveur en ligne en production). */
  readonly apiEnLigne = environment.apiUrl.replace(/\/$/, '');

  readonly mode = signal<ModeServeur>((lire(CLE_MODE) as ModeServeur) || 'auto');
  readonly serveurLocal = signal<string | null>(lire(CLE_LOCAL));
  readonly apiLocal = computed(() => (this.serveurLocal() ? `${this.serveurLocal()!.replace(/\/$/, '')}/api` : null));

  readonly etat = signal<EtatReseau>('verification');
  /** API utilisee par toutes les requetes (voir reseauInterceptor). */
  readonly base = signal(this.apiEnLigne);
  readonly connecte = computed(() => this.etat() === 'en_ligne' || this.etat() === 'local');
  readonly horsLigne = computed(() => this.etat() === 'hors_ligne');
  readonly libelle = computed(() => ({ verification: 'Connexion…', en_ligne: 'En ligne', local: 'Serveur de l\'école', hors_ligne: 'Hors ligne' })[this.etat()]);

  /** Retour du reseau apres une coupure : la file d'attente est envoyee. */
  readonly reconnexion = new Subject<void>();

  private enCours: Promise<void> | null = null;

  constructor() {
    void this.verifier();
    window.addEventListener('online', () => void this.verifier());
    window.addEventListener('offline', () => void this.verifier());
    // Hors ligne : on reessaie souvent ; connecte : on surveille de loin.
    setInterval(() => {
      if (this.horsLigne() || Date.now() - this.derniereVerification > 120000) void this.verifier();
    }, 20000);
  }

  private derniereVerification = 0;

  /** Cherche un serveur qui repond, dans l'ordre du mode choisi. */
  verifier(): Promise<void> {
    this.enCours ??= this.chercher().finally(() => {
      this.enCours = null;
      this.derniereVerification = Date.now();
    });
    return this.enCours;
  }

  /** Une requete vient d'echouer faute de reseau. */
  signalerEchec(): void {
    if (!this.horsLigne()) void this.verifier();
  }

  choisirMode(mode: ModeServeur): void {
    this.mode.set(mode);
    ecrire(CLE_MODE, mode === 'auto' ? null : mode);
    void this.verifier();
  }

  /** Adresse du serveur de l'ecole (parametres de l'etablissement), recue a la connexion. */
  memoriserServeurLocal(adresse: string | null | undefined): void {
    const propre = adresse?.trim().replace(/\/$/, '') || null;
    if (propre === this.serveurLocal()) return;
    this.serveurLocal.set(propre);
    ecrire(CLE_LOCAL, propre);
  }

  private async chercher(): Promise<void> {
    const avant = this.etat();
    const local = this.apiLocal();
    // Automatique : le serveur de l'ecole d'abord (il fait foi, et seul lui
    // accepte les inscriptions, paiements...), sinon le serveur en ligne. Les
    // deux sont interroges en meme temps.
    const candidats = (this.mode() === 'en_ligne' ? [this.apiEnLigne] : this.mode() === 'local' ? [local ?? this.apiEnLigne] : [local, this.apiEnLigne])
      .filter((c, i, l): c is string => !!c && l.indexOf(c) === i);
    const roles = await Promise.all(candidats.map((api) => this.ping(api)));
    for (const [i, api] of candidats.entries()) {
      const role = roles[i];
      if (role) {
        this.base.set(api);
        this.etat.set(role === 'en_ligne' ? 'en_ligne' : 'local');
        if (avant === 'hors_ligne' || avant === 'verification') this.reconnexion.next();
        return;
      }
    }
    this.etat.set('hors_ligne');
  }

  private async ping(api: string): Promise<string | null> {
    const controle = new AbortController();
    const minuterie = setTimeout(() => controle.abort(), DELAI_PING);
    try {
      const r = await fetch(`${api}/ping`, { cache: 'no-store', signal: controle.signal, headers: { Accept: 'application/json' } });
      if (!r.ok) return null;
      const corps = (await r.json()) as { ok?: boolean; role?: string };
      return corps.ok ? corps.role || 'local' : null;
    } catch {
      return null;
    } finally {
      clearTimeout(minuterie);
    }
  }
}
