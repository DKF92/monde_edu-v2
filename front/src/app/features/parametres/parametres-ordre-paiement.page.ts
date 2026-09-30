import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chevronUpOutline,
  chevronDownOutline,
  informationCircleOutline,
  cloudOfflineOutline,
  swapVerticalOutline,
  calculatorOutline,
  arrowForwardOutline,
  refreshOutline,
} from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { TarifsNiveaux, TypeFrais } from '../../core/models/parametre.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

const NATURES: Partial<Record<string, string>> = { inscription: 'Inscription', scolarite: 'Scolarité', annexe: 'Frais annexe' };

/**
 * Ordre de paiement (V1 : ordre fixe annexes, inscription, scolarite) : quand
 * un montant est encaisse, le 1er frais de la liste est solde, le reste du
 * versement passe au suivant, et ainsi de suite (CaisseService::repartir).
 * Simulation sur les montants d'inscription d'un niveau.
 */
@Component({
  selector: 'app-parametres-ordre-paiement',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent],
  templateUrl: './parametres-ordre-paiement.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-ordre-paiement.page.scss'],
})
export class ParametresOrdrePaiementPage {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly tarifs = signal<TarifsNiveaux | null>(null);
  readonly erreur = signal(false);
  readonly enregistrement = signal(false);
  /** Ordre en cours de modification (ids des frais en argent). */
  readonly ordre = signal<number[]>([]);

  // Simulation
  readonly niveauId = signal(0);
  readonly affecte = signal(false);
  readonly montant = signal('');

  readonly natures = NATURES;

  readonly types = computed(() => {
    const parId = new Map((this.tarifs()?.types_frais ?? []).map((t) => [t.id, t]));
    return this.ordre().map((id) => parId.get(id)).filter((t): t is TypeFrais => !!t);
  });
  readonly ordreInitial = computed(() => this.argent(this.tarifs()?.types_frais ?? []).map((t) => t.id));
  readonly modifie = computed(() => this.ordre().join(',') !== this.ordreInitial().join(','));

  readonly optionsNiveau = computed<OptionSelecteur[]>(() => (this.tarifs()?.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })));

  /** Repartition simulee du montant saisi, frais par frais. */
  readonly simulation = computed(() => {
    const niveau = this.tarifs()?.niveaux.find((n) => n.id === this.niveauId());
    const grille = this.affecte() ? niveau?.affecte : niveau?.non_affecte;
    if (!grille) return null;
    let reste = Math.max(0, Math.round(Number(this.montant().replace(/\s/g, '')) || 0));
    const verse = reste;
    const lignes = this.types()
      .filter((t) => t.is_active && (this.affecte() ? t.applicable_affecte : t.applicable_non_affecte) && (grille.montants[t.id] ?? 0) > 0)
      .map((t) => {
        const du = grille.montants[t.id] ?? 0;
        const part = Math.min(reste, du);
        reste -= part;
        return { id: t.id, libelle: t.libelle, du, part };
      });
    return { verse, lignes, total: lignes.reduce((s, l) => s + l.du, 0), reste };
  });

  constructor() {
    addIcons({ chevronBackOutline, chevronUpOutline, chevronDownOutline, informationCircleOutline, cloudOfflineOutline, swapVerticalOutline, calculatorOutline, arrowForwardOutline, refreshOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.erreur.set(false);
    this.service.tarifs().subscribe({
      next: (t) => {
        this.tarifs.set(t);
        this.ordre.set(this.argent(t.types_frais).map((x) => x.id));
        if (!t.niveaux.some((n) => n.id === this.niveauId())) this.niveauId.set(t.niveaux[0]?.id ?? 0);
        if (!this.montant()) {
          const g = t.niveaux[0]?.non_affecte;
          this.montant.set(g ? String(Math.round(Object.values(g.montants).reduce((s, v) => s + v, 0) / 2)) : '50000');
        }
      },
      error: () => this.erreur.set(true),
    });
  }

  deplacer(index: number, sens: -1 | 1): void {
    const liste = [...this.ordre()];
    const cible = index + sens;
    if (cible < 0 || cible >= liste.length) return;
    [liste[index], liste[cible]] = [liste[cible], liste[index]];
    this.ordre.set(liste);
  }

  /** Glisser-deposer (souris) : la ligne deplacee prend la place de la cible. */
  private glisse: number | null = null;
  debutGlisser(index: number): void {
    this.glisse = index;
  }
  deposer(index: number): void {
    if (this.glisse === null || this.glisse === index) return;
    const liste = [...this.ordre()];
    const [id] = liste.splice(this.glisse, 1);
    liste.splice(index, 0, id);
    this.ordre.set(liste);
    this.glisse = null;
  }

  annuler(): void {
    this.ordre.set(this.ordreInitial());
  }

  /** Ordre de l'ancienne version : frais annexes, inscription, scolarite. */
  ordreV1(): void {
    const rang = (t: TypeFrais) => ({ annexe: 0, inscription: 1, scolarite: 2 })[t.nature as 'annexe'] ?? 3;
    this.ordre.set([...this.types()].sort((a, b) => rang(a) - rang(b) || a.ordre - b.ordre).map((t) => t.id));
  }

  enregistrer(): void {
    this.enregistrement.set(true);
    this.service.ordonnerTypesFrais(this.ordre()).subscribe({
      next: (types) => {
        this.enregistrement.set(false);
        this.tarifs.update((t) => (t ? { ...t, types_frais: types } : t));
        this.ordre.set(this.argent(types).map((x) => x.id));
        this.notifier('Ordre de paiement enregistré : il s\'applique aux prochains encaissements.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        this.notifier(e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  valeurTexte(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  private argent(types: TypeFrais[]): TypeFrais[] {
    return types.filter((t) => t.nature !== 'en_nature' && t.nature !== 'dette').sort((a, b) => a.ordre - b.ordre || a.id - b.id);
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
