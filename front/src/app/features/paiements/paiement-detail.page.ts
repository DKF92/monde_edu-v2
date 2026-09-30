import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  printOutline,
  createOutline,
  trashOutline,
  cashOutline,
  receiptOutline,
  personOutline,
  timeOutline,
  cloudOfflineOutline,
  eyeOutline,
} from 'ionicons/icons';
import { CaisseService } from '../../core/services/caisse.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { AuthService } from '../../core/services/auth.service';
import { DetailPaiement, LIBELLES_MODE } from '../../core/models/caisse.model';
import { LIBELLES_STATUT, TONS_STATUT } from '../../core/models/inscription.model';

/**
 * Detail d'un paiement (consultation, sans formulaire) : paiement et sa
 * repartition, situation de l'inscription, versements, historique des
 * corrections ; imprimer, modifier / supprimer (motif), encaisser.
 */
@Component({
  selector: 'app-paiement-detail',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './paiement-detail.page.html',
  styleUrl: './paiement-detail.page.scss',
})
export class PaiementDetailPage {
  private readonly caisse = inject(CaisseService);
  private readonly actions = inject(ActionsCaisseService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;
  readonly peutModifier = computed(() => this.auth.aPermission('reglements.modifier'));
  readonly peutEncaisser = computed(() => this.auth.aPermission('reglements.encaisser'));

  readonly detail = signal<DetailPaiement | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal<string | null>(null);
  readonly action = signal<string | null>(null);

  constructor() {
    addIcons({ chevronBackOutline, printOutline, createOutline, trashOutline, cashOutline, receiptOutline, personOutline, timeOutline, cloudOfflineOutline, eyeOutline });
  }

  /** A chaque affichage (retour de la modification) : donnees a jour. */
  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.erreur.set(null);
    this.caisse.detail(Number(this.route.snapshot.paramMap.get('id'))).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.chargement.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        this.erreur.set(e.status === 404 ? 'Ce paiement n\'existe plus (supprimé).' : 'Impossible de charger le paiement.');
      },
    });
  }

  imprimer(): void {
    const r = this.detail()!.reglement;
    this.caisse.ouvrirRecu(r.id, r.numero_recu);
  }

  /** Motif obligatoire, puis formulaire de modification. */
  async modifier(): Promise<void> {
    const r = this.detail()!.reglement;
    const motif = await this.actions.motif('modifier', r.numero_recu);
    if (motif) {
      this.router.navigate(['/tabs/paiements', r.id, 'modifier'], { state: { motif } });
    }
  }

  /** Motif obligatoire, puis suppression immediate. */
  async supprimer(): Promise<void> {
    const r = this.detail()!.reglement;
    const motif = await this.actions.motif('supprimer', r.numero_recu);
    if (!motif) {
      return;
    }
    this.action.set('supprimer');
    this.caisse.supprimer(r.id, motif).subscribe({
      next: async () => {
        this.action.set(null);
        await this.notifier(`Paiement n° ${r.numero_recu} supprimé.`);
        this.router.navigateByUrl('/tabs/paiements/liste', { replaceUrl: true });
      },
      error: async (e: HttpErrorResponse) => {
        this.action.set(null);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        await this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Suppression impossible.', true);
      },
    });
  }

  /** Encaisser : le matricule est redemande (pre-rempli avec celui de l'eleve). */
  encaisser(): void {
    this.actions.encaisser(this.detail()?.reglement.eleve?.matricule ?? '');
  }

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  dateFr(date: string | null | undefined): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  heure(date: string | null | undefined): string {
    return date ? date.slice(11, 16) : '';
  }

  versement(n: number | null): string {
    return n ? (n === 1 ? '1er versement' : `${n}e versement`) : 'Règlement de dette';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
