import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AlertController, ToastController } from '@ionic/angular';
import { firstValueFrom } from 'rxjs';
import { CaisseService } from './caisse.service';

/**
 * Dialogues de la caisse :
 * - "Encaisser" hors fiche d'inscription : le matricule est demande a chaque
 *   fois avant d'ouvrir le formulaire de l'eleve ;
 * - modifier / supprimer un paiement : motif obligatoire.
 */
@Injectable({ providedIn: 'root' })
export class ActionsCaisseService {
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  private readonly router = inject(Router);
  private readonly caisse = inject(CaisseService);

  /** Demande le matricule, retrouve l'inscription de l'annee et ouvre le formulaire d'encaissement. */
  async encaisser(matriculeInitial = ''): Promise<void> {
    let valeur = matriculeInitial;
    for (;;) {
      const alerte = await this.alertes.create({
        header: 'Encaisser',
        message: "Saisissez le matricule de l'élève.",
        cssClass: 'alerte-me',
        inputs: [{ name: 'matricule', type: 'text', value: valeur, placeholder: 'Ex : 18583394P', attributes: { autocapitalize: 'characters', autocomplete: 'off', maxlength: 20 } }],
        buttons: [
          { text: 'Annuler', role: 'cancel' },
          { text: 'Continuer', role: 'confirm' },
        ],
      });
      await alerte.present();
      const { role, data } = await alerte.onDidDismiss<{ values: { matricule: string } }>();
      if (role !== 'confirm') {
        return;
      }
      valeur = (data?.values?.matricule ?? '').trim().toUpperCase();
      if (!valeur) {
        await this.notifier("Saisissez le matricule de l'élève.");
        continue;
      }
      const inscriptionId = await this.trouverInscription(valeur);
      if (inscriptionId) {
        await this.router.navigate(['/tabs/paiements/encaisser'], { queryParams: { inscription: inscriptionId } });
        return;
      }
      await this.notifier(`Aucun élève inscrit cette année avec le matricule ${valeur}.`);
    }
  }

  /** Inscription de l'annee pour ce matricule exact (null si aucune). */
  async trouverInscription(matricule: string): Promise<number | null> {
    try {
      const resultats = await firstValueFrom(this.caisse.rechercher(matricule));
      return resultats.find((r) => r.eleve.matricule.toUpperCase() === matricule.toUpperCase())?.id ?? null;
    } catch {
      return null;
    }
  }

  /** Motif obligatoire (modification ou suppression d'un paiement) ; null si abandon. */
  async motif(action: 'modifier' | 'supprimer', numero: string, objet: 'paiement' | 'dépense' = 'paiement'): Promise<string | null> {
    const article = objet === 'paiement' ? 'le paiement' : 'la dépense';
    const alerte = await this.alertes.create({
      header: action === 'supprimer' ? `Supprimer ${article} n° ${numero} ?` : `Modifier ${article} n° ${numero}`,
      message:
        action === 'supprimer'
          ? (objet === 'paiement' ? 'Le paiement sera retiré et les montants payés recalculés. ' : 'La dépense sera retirée du point de caisse. ') + "Le motif est obligatoire et conservé dans l'historique."
          : "Le motif est obligatoire et conservé dans l'historique.",
      cssClass: 'alerte-me',
      inputs: [{ name: 'motif', type: 'textarea', placeholder: 'Motif (obligatoire)', attributes: { maxlength: 500 } }],
      buttons: [
        { text: 'Retour', role: 'cancel' },
        {
          text: action === 'supprimer' ? 'Supprimer' : 'Continuer',
          role: 'confirm',
          cssClass: action === 'supprimer' ? 'bouton-danger' : '',
          // La pop-up reste ouverte tant que le motif est vide.
          handler: (valeurs: { motif: string }) => {
            if ((valeurs?.motif ?? '').trim().length < 3) {
              alerte.subHeader = 'Saisissez le motif (3 caractères au moins).';
              return false;
            }
            return true;
          },
        },
      ],
    });
    await alerte.present();
    const { role, data } = await alerte.onDidDismiss<{ values: { motif: string } }>();
    return role === 'confirm' ? (data?.values?.motif ?? '').trim() : null;
  }

  private async notifier(message: string): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: 'danger' });
    await toast.present();
  }
}
