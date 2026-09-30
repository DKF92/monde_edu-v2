import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chevronDownOutline,
  informationCircleOutline,
  businessOutline,
  layersOutline,
  schoolOutline,
  cloudOfflineOutline,
  warningOutline,
  radioButtonOn,
  radioButtonOffOutline,
  listOutline,
} from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { ParametresClasses } from '../../core/models/parametre.model';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

type Source = 'classe' | 'niveau' | 'etablissement' | null;

/**
 * Effectif maximum par classe, sur 3 niveaux (V1 : limite de l'etablissement,
 * niveau.eff_limite_classe, classe.limite_effclas). La limite la plus precise
 * l'emporte : classe, puis niveau, puis etablissement.
 */
@Component({
  selector: 'app-parametres-classes',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, PaginationComponent],
  templateUrl: './parametres-classes.page.html',
  styleUrl: './parametres.commun.scss',
})
export class ParametresClassesPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly donnees = signal<ParametresClasses | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly enregistrement = signal(false);

  /** Saisies en cours (texte brut des champs). */
  readonly general = signal('');
  readonly numerotation = signal<'chiffres' | 'lettres'>('chiffres');
  readonly niveaux = signal<Record<number, string>>({});
  readonly classes = signal<Record<number, string>>({});
  readonly ouverts = signal<Set<number>>(new Set());
  readonly erreursServeur = signal<Record<string, string>>({});
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  /** Niveaux de la page affichee (les saisies des autres pages sont conservees). */
  readonly niveauxPage = computed(() => paginer(this.donnees()?.niveaux ?? [], this.page(), this.taille()));

  readonly modifie = computed(() => {
    const d = this.donnees();
    if (!d) {
      return false;
    }
    if (this.nombre(this.general()) !== d.effectif_max_classe || this.numerotation() !== d.numerotation_classes) {
      return true;
    }
    return d.niveaux.some(
      (n) =>
        this.nombre(this.niveaux()[n.id]) !== n.effectif_max ||
        n.classes.some((c) => this.nombre(this.classes()[c.id]) !== c.capacite),
    );
  });

  /** Premier champ invalide (entier de 1 a 500 attendu). */
  readonly invalide = computed(() => {
    const valeurs = [this.general(), ...Object.values(this.niveaux()), ...Object.values(this.classes())];
    return valeurs.some((v) => !this.valide(v));
  });

  constructor() {
    addIcons({
      chevronBackOutline,
      chevronDownOutline,
      informationCircleOutline,
      businessOutline,
      layersOutline,
      schoolOutline,
      cloudOfflineOutline,
      warningOutline,
      radioButtonOn,
      radioButtonOffOutline,
      listOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.classes().subscribe({
      next: (d) => {
        this.appliquer(d);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  annuler(): void {
    const d = this.donnees();
    if (d) {
      this.appliquer(d);
    }
  }

  basculer(niveauId: number): void {
    this.ouverts.update((set) => {
      const suivant = new Set(set);
      suivant.has(niveauId) ? suivant.delete(niveauId) : suivant.add(niveauId);
      return suivant;
    });
  }

  saisirNiveau(id: number, valeur: string): void {
    this.niveaux.update((v) => ({ ...v, [id]: valeur }));
  }

  saisirClasse(id: number, valeur: string): void {
    this.classes.update((v) => ({ ...v, [id]: valeur }));
  }

  valide(valeur: string | undefined): boolean {
    if (!valeur?.trim()) {
      return true;
    }
    const n = Number(valeur);
    return Number.isInteger(n) && n >= 1 && n <= 500;
  }

  /** Limite qui s'applique a une classe, et d'ou elle vient. */
  limiteClasse(niveauId: number, classeId: number): { limite: number | null; source: Source } {
    const classe = this.nombre(this.classes()[classeId]);
    if (classe) {
      return { limite: classe, source: 'classe' };
    }
    const niveau = this.limiteNiveau(niveauId);
    return niveau.limite ? niveau : { limite: null, source: null };
  }

  /** Limite qui s'applique aux classes d'un niveau (sans limite de classe). */
  limiteNiveau(niveauId: number): { limite: number | null; source: Source } {
    const niveau = this.nombre(this.niveaux()[niveauId]);
    if (niveau) {
      return { limite: niveau, source: 'niveau' };
    }
    const general = this.nombre(this.general());
    return general ? { limite: general, source: 'etablissement' } : { limite: null, source: null };
  }

  libelleSource(source: Source): string {
    return source === 'classe' ? 'limite de la classe' : source === 'niveau' ? 'limite du niveau' : 'limite générale';
  }

  enregistrer(): void {
    const d = this.donnees();
    if (!d || this.invalide()) {
      return;
    }
    this.enregistrement.set(true);
    this.erreursServeur.set({});
    this.service
      .enregistrerClasses({
        effectif_max_classe: this.nombre(this.general()),
        numerotation_classes: this.numerotation(),
        niveaux: d.niveaux.map((n) => ({ id: n.id, effectif_max: this.nombre(this.niveaux()[n.id]) })),
        classes: d.niveaux.flatMap((n) => n.classes.map((c) => ({ id: c.id, capacite: this.nombre(this.classes()[c.id]) }))),
      })
      .subscribe({
        next: (maj) => {
          this.enregistrement.set(false);
          this.appliquer(maj);
          this.notifier('Limites enregistrées.');
        },
        error: (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const premier = e.error?.errors ? (Object.values(e.error.errors)[0] as string[])[0] : null;
          this.notifier(premier ?? 'Enregistrement impossible. Vérifiez votre connexion puis réessayez.', true);
        },
      });
  }

  private appliquer(d: ParametresClasses): void {
    this.donnees.set(d);
    this.general.set(d.effectif_max_classe?.toString() ?? '');
    this.numerotation.set(d.numerotation_classes ?? 'chiffres');
    this.niveaux.set(Object.fromEntries(d.niveaux.map((n) => [n.id, n.effectif_max?.toString() ?? ''])));
    this.classes.set(
      Object.fromEntries(d.niveaux.flatMap((n) => n.classes.map((c) => [c.id, c.capacite?.toString() ?? '']))),
    );
    // Deplie les niveaux qui ont deja une limite de classe.
    this.ouverts.set(new Set(d.niveaux.filter((n) => n.classes.some((c) => c.capacite)).map((n) => n.id)));
  }

  private nombre(valeur: string | undefined): number | null {
    return valeur?.trim() && this.valide(valeur) ? Number(valeur) : null;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
