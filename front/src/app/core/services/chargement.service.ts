import { Injectable, inject, signal } from '@angular/core';
import { NavigationCancel, NavigationEnd, NavigationError, NavigationStart, Router } from '@angular/router';

/** Duree minimum d'affichage : evite un clignotement sur les pages instantanees. */
const DUREE_MINIMUM = 300;
/** Apres la navigation, delai pendant lequel la page lance ses premieres requetes. */
const FENETRE_REQUETES = 150;
/** Securite : le loader ne reste jamais bloque (API muette...). */
const DUREE_MAXIMUM = 15000;

/**
 * Loader global : actionne a chaque changement de page et retire une fois la
 * page affichee avec ses premieres donnees (requetes lancees a l'ouverture de
 * la page). Les chargements de fond (pages suivantes d'une liste, actions
 * lancees ensuite) ne le reactivent pas.
 *
 * Un changement de parametres sur la meme page (filtres dans l'URL) ne
 * l'active pas.
 */
@Injectable({ providedIn: 'root' })
export class ChargementService {
  private readonly router = inject(Router);

  readonly actif = signal(false);

  private debut = 0;
  private finNavigation = 0;
  private navigationEnCours = false;
  private enAttente = 0;
  private minuterie?: ReturnType<typeof setTimeout>;
  private securite?: ReturnType<typeof setTimeout>;

  constructor() {
    this.router.events.subscribe((e) => {
      if (e instanceof NavigationStart) {
        if (this.chemin(e.url) !== this.chemin(this.router.url) || !this.router.navigated) {
          this.demarrer();
        }
      } else if (e instanceof NavigationEnd || e instanceof NavigationCancel || e instanceof NavigationError) {
        if (this.navigationEnCours) {
          this.navigationEnCours = false;
          this.finNavigation = Date.now();
          this.verifier();
        }
      }
    });
  }

  /** Une requete HTTP qui demarre maintenant doit-elle retenir le loader ? */
  suivre(): boolean {
    const suivie = this.actif() && (this.navigationEnCours || Date.now() - this.finNavigation < FENETRE_REQUETES);
    if (suivie) {
      this.enAttente++;
    }
    return suivie;
  }

  /** Fin d'une requete suivie (succes, erreur ou annulation). */
  terminer(): void {
    this.enAttente = Math.max(0, this.enAttente - 1);
    this.verifier();
  }

  private demarrer(): void {
    this.debut = Date.now();
    this.navigationEnCours = true;
    this.enAttente = 0;
    this.actif.set(true);
    clearTimeout(this.securite);
    this.securite = setTimeout(() => this.arreter(), DUREE_MAXIMUM);
  }

  private verifier(): void {
    if (!this.actif() || this.navigationEnCours || this.enAttente > 0) {
      return;
    }
    const maintenant = Date.now();
    const attente = Math.max(this.debut + DUREE_MINIMUM, this.finNavigation + FENETRE_REQUETES) - maintenant;
    clearTimeout(this.minuterie);
    if (attente > 0) {
      this.minuterie = setTimeout(() => this.verifier(), attente);
    } else {
      this.arreter();
    }
  }

  private arreter(): void {
    clearTimeout(this.minuterie);
    clearTimeout(this.securite);
    this.navigationEnCours = false;
    this.enAttente = 0;
    this.actif.set(false);
  }

  private chemin(url: string): string {
    return url.split(/[?#]/)[0];
  }
}
