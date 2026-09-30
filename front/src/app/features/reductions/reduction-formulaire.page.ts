import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  pricetagOutline,
  alertCircleOutline,
  cloudOfflineOutline,
  informationCircleOutline,
  radioButtonOn,
  radioButtonOffOutline,
  checkboxOutline,
  squareOutline,
  personOutline,
} from 'ionicons/icons';
import { CibleReduction, DetteReduction, EleveReduction, ReductionsService, TypeReductionEleve } from '../../core/services/reductions.service';

/**
 * Formulaire de reduction (V1 new_reduc / traitement_dette), ouvert apres la
 * saisie du matricule : reduction d'inscription (type, montant entre les
 * bornes du type, donneur d'ordre, apercu de l'imputation sur les frais) ou
 * reduction de dette (dette, montant ou annulation totale, donneur d'ordre).
 */
@Component({
  selector: 'app-reduction-formulaire',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './reduction-formulaire.page.html',
  styleUrl: './reduction-formulaire.page.scss',
})
export class ReductionFormulairePage {
  private readonly service = inject(ReductionsService);
  private readonly toasts = inject(ToastController);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly cible = signal<CibleReduction>('inscription');
  readonly donnees = signal<EleveReduction | null>(null);
  readonly erreur = signal<string | null>(null);
  readonly enregistrement = signal(false);
  readonly erreurs = signal<Partial<Record<'montant' | 'motif', string>>>({});

  readonly typeId = signal(0);
  readonly detteId = signal(0);
  readonly montant = signal('');
  readonly motif = signal('');
  readonly annuler = signal(false);

  readonly type = computed<TypeReductionEleve | null>(() => this.donnees()?.types?.find((t) => t.id === this.typeId()) ?? null);
  readonly dette = computed<DetteReduction | null>(() => this.donnees()?.dettes?.find((d) => d.id === this.detteId()) ?? null);
  readonly valeur = computed(() => Math.round(Number(this.montant().replace(/\s/g, '')) || 0));

  /** Apercu : part de la reduction sur chaque frais (ordre du type). */
  readonly apercu = computed(() => {
    const t = this.type();
    const frais = this.donnees()?.frais ?? [];
    const parts: Record<number, number> = {};
    let reste = this.valeur();
    for (const id of t?.ordre ?? []) {
      const f = frais.find((x) => x.id === id);
      if (!f) continue;
      const part = Math.min(reste, f.reste);
      if (part > 0) parts[id] = part;
      reste -= part;
    }
    return parts;
  });

  constructor() {
    addIcons({ chevronBackOutline, pricetagOutline, alertCircleOutline, cloudOfflineOutline, informationCircleOutline, radioButtonOn, radioButtonOffOutline, checkboxOutline, squareOutline, personOutline });
  }

  ionViewWillEnter(): void {
    const p = this.route.snapshot.queryParamMap;
    this.cible.set(p.get('cible') === 'dette' ? 'dette' : 'inscription');
    this.charger(p.get('matricule') ?? '');
  }

  charger(matricule: string): void {
    this.donnees.set(null);
    this.erreur.set(null);
    this.montant.set('');
    this.motif.set('');
    this.annuler.set(false);
    this.erreurs.set({});
    if (!matricule) {
      this.erreur.set('Matricule manquant.');
      return;
    }
    this.service.eleve(matricule, this.cible()).subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.typeId.set(d.types?.find((t) => t.bornes.max > 0)?.id ?? d.types?.[0]?.id ?? 0);
        this.detteId.set(d.dettes?.find((x) => x.reste > 0)?.id ?? 0);
      },
      error: (e: HttpErrorResponse) => this.erreur.set(e.error?.message || 'Impossible de charger l\'élève.'),
    });
  }

  choisirType(t: TypeReductionEleve): void {
    this.typeId.set(t.id);
    this.erreurs.update((x) => ({ ...x, montant: undefined }));
  }

  maximum(): void {
    const max = this.cible() === 'dette' ? this.dette()?.reste : this.type()?.bornes.max;
    if (max) this.montant.set(String(max));
  }

  enregistrer(): void {
    const erreurs: Partial<Record<'montant' | 'motif', string>> = {};
    if (!this.motif().trim()) erreurs.motif = 'Indiquez qui accorde la réduction (ex. : Fondateur).';
    if (this.cible() === 'inscription') {
      const t = this.type();
      if (!t) return;
      if (t.bornes.max <= 0) erreurs.montant = 'Plus rien à réduire avec ce type : les frais concernés sont payés ou déjà réduits.';
      else if (this.valeur() < t.bornes.min || this.valeur() > t.bornes.max) erreurs.montant = `Saisissez un montant entre ${this.f(t.bornes.min)} et ${this.f(t.bornes.max)}.`;
    } else if (!this.annuler()) {
      const d = this.dette();
      if (!d) return;
      if (this.valeur() < 1 || this.valeur() > d.reste) erreurs.montant = `Saisissez un montant entre 1 F et ${this.f(d.reste)}.`;
    }
    this.erreurs.set(erreurs);
    if (Object.keys(erreurs).length) return;

    const donnees = this.donnees()!;
    this.enregistrement.set(true);
    const requete = this.cible() === 'inscription'
      ? this.service.accorder({ inscription_id: donnees.inscription.id, type_reduction_id: this.typeId(), montant: this.valeur(), motif: this.motif().trim() })
      : this.service.accorderDette({ dette_id: this.detteId(), montant: this.annuler() ? null : this.valeur(), annuler: this.annuler(), motif: this.motif().trim() });
    requete.subscribe({
      next: (r) => {
        this.enregistrement.set(false);
        this.notifier(r.message);
        this.router.navigateByUrl('/tabs/reductions');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const liste = e.error?.errors as Record<string, string[]> | undefined;
        if (liste?.['montant']) this.erreurs.set({ montant: liste['montant'][0] });
        else if (liste?.['motif']) this.erreurs.set({ motif: liste['motif'][0] });
        else this.notifier(e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  valeurTexte(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
