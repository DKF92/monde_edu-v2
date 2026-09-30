import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, saveOutline, documentAttachOutline, closeCircleOutline, cloudUploadOutline, informationCircleOutline } from 'ionicons/icons';
import { firstValueFrom } from 'rxjs';
import { DepenseService, DetailDepense } from '../../core/services/depense.service';
import { LIBELLES_MODE, ModePaiement } from '../../core/models/caisse.model';

/**
 * Saisie d'une depense (V1 new_dps : date, objet, montant, receveur, piece
 * comptable) avec categorie, mode et justificatif scanne ; en modification,
 * motif obligatoire (saisi avant d'arriver ici, modifiable).
 */
@Component({
  selector: 'app-depense-formulaire',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './depense-formulaire.page.html',
  styleUrl: './depense-formulaire.page.scss',
})
export class DepenseFormulairePage {
  private readonly service = inject(DepenseService);
  private readonly route = inject(ActivatedRoute);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly modes = Object.keys(LIBELLES_MODE) as ModePaiement[];
  readonly categories: { valeur: string; libelle: string }[] = [
    { valeur: 'salaires', libelle: 'Salaires' },
    { valeur: 'fournitures', libelle: 'Fournitures et matériel' },
    { valeur: 'entretien', libelle: 'Entretien et réparations' },
    { valeur: 'factures', libelle: 'Eau, électricité, internet' },
    { valeur: 'transport', libelle: 'Transport et déplacements' },
    { valeur: 'activites', libelle: 'Activités et événements' },
    { valeur: 'autres', libelle: 'Autres dépenses' },
  ];
  readonly maxDate = this.aujourdhui();

  readonly id = signal<number | null>(null);
  readonly modification = computed(() => this.id() !== null);
  readonly existante = signal<DetailDepense | null>(null);
  readonly chargement = signal(false);
  readonly enregistrement = signal(false);
  readonly erreurs = signal<Record<string, string>>({});

  readonly motif = signal('');
  readonly date = signal(this.maxDate);
  readonly categorie = signal('');
  readonly libelle = signal('');
  readonly montant = signal<number | null>(null);
  readonly mode = signal<ModePaiement>('especes');
  readonly beneficiaire = signal('');
  readonly pieceComptable = signal('');
  readonly justificatif = signal<File | null>(null);
  readonly retirerJustificatif = signal(false);

  constructor() {
    addIcons({ chevronBackOutline, saveOutline, documentAttachOutline, closeCircleOutline, cloudUploadOutline, informationCircleOutline });
  }

  async ionViewWillEnter(): Promise<void> {
    const id = Number(this.route.snapshot.paramMap.get('id')) || null;
    this.id.set(id);
    this.erreurs.set({});
    this.justificatif.set(null);
    this.retirerJustificatif.set(false);
    if (!id) {
      this.reinitialiser();
      return;
    }
    this.motif.set((history.state?.motif as string | undefined) ?? '');
    this.chargement.set(true);
    try {
      const d = await firstValueFrom(this.service.detail(id));
      this.existante.set(d);
      this.date.set(d.date);
      this.categorie.set(d.categorie);
      this.libelle.set(d.libelle);
      this.montant.set(d.montant);
      this.mode.set(d.mode);
      this.beneficiaire.set(d.beneficiaire ?? '');
      this.pieceComptable.set(d.piece_comptable ?? '');
    } catch {
      await this.notifier('Impossible de charger la dépense.', true);
      this.router.navigateByUrl('/tabs/depenses');
    } finally {
      this.chargement.set(false);
    }
  }

  choisirFichier(evenement: Event): void {
    const fichier = (evenement.target as HTMLInputElement).files?.[0] ?? null;
    (evenement.target as HTMLInputElement).value = '';
    if (!fichier) return;
    if (fichier.size > 5 * 1024 * 1024) {
      this.erreurs.update((e) => ({ ...e, justificatif: 'Le justificatif ne doit pas dépasser 5 Mo.' }));
      return;
    }
    this.justificatif.set(fichier);
    this.retirerJustificatif.set(false);
    this.effacer('justificatif');
  }

  enregistrer(): void {
    const erreurs: Record<string, string> = {};
    if (this.modification() && this.motif().trim().length < 3) erreurs['motif'] = 'Le motif est obligatoire (3 caractères au moins).';
    if (!this.date()) erreurs['date_depense'] = 'Précisez la date.';
    if (!this.categorie()) erreurs['categorie'] = 'Choisissez la catégorie.';
    if (!this.libelle().trim()) erreurs['libelle'] = "Précisez l'objet de la dépense.";
    if (!this.montant() || this.montant()! <= 0) erreurs['montant'] = 'Saisissez le montant.';
    this.erreurs.set(erreurs);
    if (Object.keys(erreurs).length || this.enregistrement()) return;

    const saisie = {
      date_depense: this.date(),
      categorie: this.categorie(),
      libelle: this.libelle().trim(),
      montant: this.montant()!,
      mode_paiement: this.mode(),
      beneficiaire: this.beneficiaire().trim() || null,
      piece_comptable: this.pieceComptable().trim() || null,
      justificatif: this.justificatif(),
      retirer_justificatif: this.retirerJustificatif(),
      motif: this.modification() ? this.motif().trim() : undefined,
    };
    this.enregistrement.set(true);
    const id = this.id();
    (id ? this.service.modifier(id, saisie) : this.service.creer(saisie)).subscribe({
      next: async (d) => {
        this.enregistrement.set(false);
        await this.notifier(id ? `Dépense n° ${d.numero} modifiée.` : `Dépense n° ${d.numero} enregistrée.`);
        this.router.navigateByUrl('/tabs/depenses', { replaceUrl: true });
      },
      error: async (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const brutes = (e.error?.errors ?? {}) as Record<string, string[]>;
        const liste = Object.fromEntries(Object.entries(brutes).map(([k, v]) => [k, v[0]]));
        this.erreurs.set(liste);
        await this.notifier(Object.values(liste)[0] || e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  effacer(cle: string): void {
    if (this.erreurs()[cle]) {
      const { [cle]: _, ...reste } = this.erreurs();
      this.erreurs.set(reste);
    }
  }

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  valeurNombre(evenement: Event): number | null {
    const brut = (evenement.target as HTMLInputElement).value.replace(/\s/g, '');
    return brut === '' || isNaN(Number(brut)) ? null : Math.round(Number(brut));
  }

  private reinitialiser(): void {
    this.existante.set(null);
    this.motif.set('');
    this.date.set(this.maxDate);
    this.categorie.set('');
    this.libelle.set('');
    this.montant.set(null);
    this.mode.set('especes');
    this.beneficiaire.set('');
    this.pieceComptable.set('');
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
