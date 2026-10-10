import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, addOutline, cardOutline, createOutline, trashOutline, closeOutline, cloudOfflineOutline, informationCircleOutline, eyeOffOutline, eyeOutline } from 'ionicons/icons';
import { Banque, DepenseService, SaisieBanque } from '../../core/services/depense.service';

/**
 * Parametres > Banques : comptes bancaires de l'etablissement, choisis dans
 * les depenses payees par la banque. Une banque deja utilisee se desactive
 * (elle n'est plus proposee) mais ne se supprime pas.
 */
@Component({
  selector: 'app-parametres-banques',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-banques.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-banques.page.scss'],
})
export class ParametresBanquesPage {
  private readonly service = inject(DepenseService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly banques = signal<Banque[]>([]);
  readonly peutGerer = signal(false);
  readonly chargement = signal(true);
  readonly erreur = signal(false);

  readonly modale = signal(false);
  readonly edition = signal<Banque | null>(null);
  readonly saisie = signal<SaisieBanque>(this.vide());
  readonly erreurs = signal<Record<string, string>>({});
  readonly enregistrement = signal(false);
  readonly actives = computed(() => this.banques().filter((b) => b.is_active).length);

  constructor() {
    addIcons({ chevronBackOutline, addOutline, cardOutline, createOutline, trashOutline, closeOutline, cloudOfflineOutline, informationCircleOutline, eyeOffOutline, eyeOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.erreur.set(false);
    this.service.banques().subscribe({
      next: (r) => {
        this.banques.set(r.data);
        this.peutGerer.set(r.peut_gerer);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  nouvelle(): void {
    this.ouvrir(null, this.vide());
  }

  modifier(b: Banque): void {
    this.ouvrir(b, { nom: b.nom, sigle: b.sigle, numero_compte: b.numero_compte, agence: b.agence });
  }

  maj(champ: keyof SaisieBanque, evenement: Event): void {
    const valeur = (evenement.target as HTMLInputElement).value;
    this.saisie.update((s) => ({ ...s, [champ]: valeur }));
    this.erreurs.update((e) => ({ ...e, [champ]: '' }));
  }

  enregistrer(): void {
    const s = this.saisie();
    const saisie: SaisieBanque = {
      nom: s.nom.trim(),
      sigle: s.sigle?.trim() || null,
      numero_compte: s.numero_compte?.trim() || null,
      agence: s.agence?.trim() || null,
    };
    if (!saisie.nom) {
      this.erreurs.set({ nom: 'Indiquez le nom de la banque.' });
      return;
    }
    const edition = this.edition();
    this.enregistrement.set(true);
    (edition ? this.service.modifierBanque(edition.id, saisie) : this.service.creerBanque(saisie)).subscribe({
      next: (r) => {
        this.enregistrement.set(false);
        this.modale.set(false);
        this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        if (erreurs) this.erreurs.set(Object.fromEntries(Object.entries(erreurs).map(([k, v]) => [k, v[0]])));
        else this.notifier(e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  basculer(b: Banque): void {
    this.service.modifierBanque(b.id, { nom: b.nom, is_active: !b.is_active }).subscribe({
      next: () => {
        this.notifier(b.is_active ? `${b.nom} désactivée : elle n'est plus proposée dans les dépenses.` : `${b.nom} réactivée.`);
        this.charger();
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Modification impossible.', true),
    });
  }

  async supprimer(b: Banque): Promise<void> {
    const alerte = await this.alertes.create({
      header: 'Supprimer la banque',
      cssClass: 'alerte-me',
      message: `La banque ${b.nom} sera supprimée. Confirmez-vous ?`,
      buttons: [
        { text: 'Non', role: 'cancel' },
        { text: 'Oui, supprimer', role: 'destructive' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'destructive') return;
    this.service.supprimerBanque(b.id).subscribe({
      next: (r) => {
        this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  private ouvrir(banque: Banque | null, saisie: SaisieBanque): void {
    this.edition.set(banque);
    this.saisie.set(saisie);
    this.erreurs.set({});
    this.modale.set(true);
  }

  private vide(): SaisieBanque {
    return { nom: '', sigle: null, numero_compte: null, agence: null };
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
