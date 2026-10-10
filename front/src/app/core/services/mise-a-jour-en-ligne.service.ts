import { Injectable, computed, effect, inject, signal } from '@angular/core';
import { InscriptionService } from './inscription.service';
import { AuthService } from './auth.service';
import { EtatScanEnLigne } from '../models/inscription.model';

/**
 * "Mettre a jour" (liste des inscriptions) : le scan des inscriptions sur le
 * site de l'Etat tourne sur le serveur (commande en arriere-plan). Il continue
 * si l'on change de page, recharge ou ferme l'application ; ce service suit
 * son etat (toutes les 2 s pendant qu'il tourne) pour l'indicateur affiche sur
 * toutes les pages et le bilan de la liste des inscriptions.
 */
@Injectable({ providedIn: 'root' })
export class MiseAJourEnLigneService {
  private readonly inscriptions = inject(InscriptionService);
  private readonly auth = inject(AuthService);

  readonly etat = signal<EtatScanEnLigne | null>(null);
  /** Bilan ferme par l'utilisateur (jusqu'au prochain lancement). */
  private readonly bilanFerme = signal(false);
  /** Incremente quand un scan se termine apres avoir trouve des recus : la liste se recharge. */
  readonly version = signal(0);

  readonly enCours = computed(() => !!this.etat()?.en_cours);
  readonly termine = computed(() => !!this.etat() && !this.etat()!.en_cours && !!this.etat()!.debut && !this.bilanFerme());
  readonly total = computed(() => this.etat()?.total ?? 0);
  readonly nbTraitees = computed(() => this.etat()?.traitees ?? 0);
  readonly trouvees = computed(() => this.etat()?.trouvees ?? 0);
  readonly introuvables = computed(() => this.etat()?.introuvables ?? 0);
  readonly erreur = computed(() => this.etat()?.erreur ?? null);
  readonly avecRemarques = computed(() => (this.etat()?.dernieres ?? []).filter((t) => t.remarques.length && !t.introuvable));
  readonly progression = computed(() => (this.total() ? Math.min(100, Math.round((this.nbTraitees() / this.total()) * 100)) : 0));

  private minuterie: ReturnType<typeof setTimeout> | null = null;
  private dernierEtatEnCours = false;

  constructor() {
    // Connexion / changement d'etablissement : un scan peut deja tourner.
    effect(() => {
      if (this.auth.contexteComplet() && this.peutSuivre()) {
        this.lire();
      } else {
        this.arreterSuivi();
        this.etat.set(null);
      }
    }, { allowSignalWrites: true });
  }

  /** Postes qui voient les inscriptions ou les mises a jour. */
  peutSuivre(): boolean {
    return ['inscriptions.gerer', 'inscriptions.voir', 'reglements.voir', 'reglements.modifier'].some((p) => this.auth.aPermission(p));
  }

  demarrer(): void {
    if (this.enCours()) return;
    this.bilanFerme.set(false);
    this.inscriptions.lancerScanEnLigne().subscribe({
      next: (e) => this.recevoir(e),
      error: () => this.etat.update((e) => ({ ...(e ?? ({} as EtatScanEnLigne)), en_cours: false, erreur: 'Lancement impossible. Vérifiez votre connexion puis relancez.' })),
    });
  }

  arreter(): void {
    this.inscriptions.arreterScanEnLigne().subscribe({ next: (e) => this.recevoir(e), error: () => undefined });
  }

  /** Ferme le bilan affiche apres la fin. */
  effacer(): void {
    if (!this.enCours()) this.bilanFerme.set(true);
  }

  private lire(): void {
    this.inscriptions.etatScanEnLigne().subscribe({ next: (e) => this.recevoir(e), error: () => this.planifier() });
  }

  private recevoir(e: EtatScanEnLigne): void {
    const finVientDArriver = this.dernierEtatEnCours && !e.en_cours;
    this.dernierEtatEnCours = e.en_cours;
    this.etat.set(e);
    if (finVientDArriver && e.trouvees > 0) {
      this.version.update((v) => v + 1);
    }
    this.planifier();
  }

  private planifier(): void {
    this.arreterSuivi();
    if (this.enCours()) {
      this.minuterie = setTimeout(() => this.lire(), 2000);
    }
  }

  private arreterSuivi(): void {
    if (this.minuterie) clearTimeout(this.minuterie);
    this.minuterie = null;
  }
}
