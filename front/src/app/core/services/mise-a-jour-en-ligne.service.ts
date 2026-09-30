import { Injectable, computed, inject, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { InscriptionService } from './inscription.service';
import { LigneMiseAJourEnLigne } from '../models/inscription.model';

/**
 * "Mettre a jour" (liste des inscriptions) : toutes les inscriptions de
 * l'annee sans verification en ligne sont consultees sur le site de l'Etat,
 * lot par lot, en arriere-plan (le traitement continue si on change de page).
 * L'API met la base a jour directement quand le recu existe.
 */
@Injectable({ providedIn: 'root' })
export class MiseAJourEnLigneService {
  private readonly inscriptions = inject(InscriptionService);

  readonly enCours = signal(false);
  /** Nombre d'inscriptions a verifier au lancement. */
  readonly total = signal(0);
  readonly traitees = signal<LigneMiseAJourEnLigne[]>([]);
  readonly erreur = signal<string | null>(null);
  readonly termine = signal(false);
  /** Incremente a chaque lot qui a modifie des inscriptions : la liste se recharge. */
  readonly version = signal(0);

  readonly trouvees = computed(() => this.traitees().filter((t) => t.trouve).length);
  readonly avecRemarques = computed(() => this.traitees().filter((t) => t.remarques.length));
  readonly progression = computed(() => (this.total() ? Math.min(100, Math.round((this.traitees().length / this.total()) * 100)) : 0));

  private abonnement?: Subscription;

  demarrer(total: number): void {
    if (this.enCours()) {
      return;
    }
    this.total.set(total);
    this.traitees.set([]);
    this.erreur.set(null);
    this.termine.set(false);
    this.enCours.set(true);
    this.lot(0);
  }

  arreter(): void {
    this.abonnement?.unsubscribe();
    this.enCours.set(false);
    this.termine.set(true);
  }

  /** Ferme le bilan affiche apres la fin. */
  effacer(): void {
    if (!this.enCours()) {
      this.termine.set(false);
      this.traitees.set([]);
      this.erreur.set(null);
    }
  }

  private lot(apresId: number): void {
    this.abonnement = this.inscriptions.miseAJourEnLigne(apresId).subscribe({
      next: (r) => {
        this.traitees.update((t) => [...t, ...r.traitees]);
        if (r.traitees.some((t) => t.trouve)) {
          this.version.update((v) => v + 1);
        }
        if (r.site_indisponible) {
          this.erreur.set(r.site_indisponible);
          this.arreter();
        } else if (r.restantes > 0 && this.enCours()) {
          // Le total peut bouger (inscriptions ajoutees entre-temps).
          this.total.set(Math.max(this.total(), this.traitees().length + r.restantes));
          this.lot(r.dernier_id);
        } else {
          this.arreter();
        }
      },
      error: () => {
        this.erreur.set('Mise à jour interrompue. Vérifiez votre connexion puis relancez.');
        this.arreter();
      },
    });
  }
}
