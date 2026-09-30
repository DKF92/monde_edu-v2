import { computed, signal } from '@angular/core';
import { Observable, Subscription } from 'rxjs';

export interface PageProgressive<T> {
  data: T[];
  total: number;
}

/**
 * Chargement progressif d'un tableau : la premiere page s'affiche tout de
 * suite, les suivantes sont chargees en arriere-plan (par paquets) pendant
 * que l'utilisateur consulte le debut du tableau.
 *
 * Toute nouvelle recherche ou nouveau filtre (charger()) annule les requetes
 * en cours (le HttpClient d'Angular interrompt la requete au desabonnement)
 * et repart de la premiere page : les resultats d'une recherche arrivent donc
 * aussi vite qu'une premiere page.
 */
export class ListeProgressive<T> {
  readonly lignes = signal<T[]>([]);
  readonly total = signal(0);
  /** Tout premier chargement (aucune donnee encore affichee). */
  readonly chargementInitial = signal(true);
  /** Premiere page d'une nouvelle recherche en cours (les anciennes lignes restent affichees). */
  readonly actualisation = signal(false);
  /** Pages suivantes en cours de chargement en arriere-plan. */
  readonly chargementFond = signal(false);
  readonly erreur = signal(false);
  readonly charges = computed(() => this.lignes().length);

  private abonnement?: Subscription;
  private generation = 0;

  constructor(private readonly taillePaquet = 50) {}

  /**
   * @param requete   appel de l'API pour une page (numero, taille du paquet)
   * @param surPremiere appelee avec la reponse de la premiere page (compteurs...)
   */
  charger<R extends PageProgressive<T>>(requete: (page: number, parPage: number) => Observable<R>, surPremiere?: (reponse: R) => void): void {
    this.arreter();
    const generation = ++this.generation;
    this.erreur.set(false);
    this.actualisation.set(true);

    const chargerPage = (page: number) => {
      this.abonnement = requete(page, this.taillePaquet).subscribe({
        next: (reponse) => {
          if (generation !== this.generation) {
            return;
          }
          if (page === 1) {
            this.lignes.set(reponse.data);
            this.total.set(reponse.total);
            this.chargementInitial.set(false);
            this.actualisation.set(false);
            surPremiere?.(reponse);
          } else {
            this.lignes.update((l) => [...l, ...reponse.data]);
          }
          const reste = this.lignes().length < reponse.total && reponse.data.length > 0;
          this.chargementFond.set(reste);
          if (reste) {
            // Laisse le navigateur afficher la page avant la requete suivante.
            setTimeout(() => generation === this.generation && chargerPage(page + 1), 0);
          }
        },
        error: () => {
          if (generation !== this.generation) {
            return;
          }
          this.chargementInitial.set(false);
          this.actualisation.set(false);
          this.chargementFond.set(false);
          // Une page de fond en echec ne masque pas ce qui est deja affiche.
          if (page === 1) {
            this.erreur.set(true);
          }
        },
      });
    };
    chargerPage(1);
  }

  /** Annule les requetes en cours (changement de recherche, sortie de page). */
  arreter(): void {
    this.generation++;
    this.abonnement?.unsubscribe();
    this.chargementFond.set(false);
    this.actualisation.set(false);
  }

  /** Lignes d'une page du tableau (pagination locale). */
  page(numero: number, taille: number): T[] {
    return this.lignes().slice((numero - 1) * taille, numero * taille);
  }

  /** La page demandee n'est pas encore arrivee (chargement de fond). */
  pageEnAttente(numero: number, taille: number): boolean {
    return this.lignes().length < Math.min(this.total(), numero * taille);
  }
}
