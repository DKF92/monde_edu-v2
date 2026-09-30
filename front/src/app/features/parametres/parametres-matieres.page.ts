import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, addOutline, createOutline, trashOutline, closeOutline, bookOutline, cloudOfflineOutline, informationCircleOutline } from 'ionicons/icons';
import { Groupe, MatiereParametre, ParametresMatieres, PedagogieService } from '../../core/services/pedagogie.service';

type Cycle = 'maternelle' | 'primaire' | 'college' | 'lycee';

/**
 * Matieres et coefficients par niveau (V1 matiere / aff_coef) : un
 * coefficient vide = matiere non enseignee dans le niveau. La conduite
 * (V1 CONDUITE) est reservee.
 */
@Component({
  selector: 'app-parametres-matieres',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-matieres.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-matieres.page.scss'],
})
export class ParametresMatieresPage {
  private readonly service = inject(PedagogieService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly cycles: { valeur: Cycle; libelle: string }[] = [
    { valeur: 'maternelle', libelle: 'Maternelle' },
    { valeur: 'primaire', libelle: 'Primaire' },
    { valeur: 'college', libelle: 'Premier cycle' },
    { valeur: 'lycee', libelle: 'Second cycle' },
  ];
  readonly libellesGroupe: Record<Groupe, string> = { LITTERAIRE: 'Lettres', SCIENTIFIQUE: 'Sciences', AUTRES: 'Autres' };

  readonly donnees = signal<ParametresMatieres | null>(null);
  readonly erreur = signal(false);
  readonly cycle = signal<Cycle>('college');
  /** Saisies : "matiere-niveau" -> texte du coefficient. */
  readonly saisies = signal<Record<string, string>>({});
  readonly enregistrement = signal(false);

  readonly niveaux = computed(() => (this.donnees()?.niveaux ?? []).filter((n) => n.cycle === this.cycle()));
  readonly matieres = computed(() => {
    const ordre: Record<Groupe, number> = { LITTERAIRE: 1, SCIENTIFIQUE: 2, AUTRES: 3 };
    return [...(this.donnees()?.matieres ?? [])].sort((a, b) => Number(a.reservee) - Number(b.reservee) || ordre[a.groupe] - ordre[b.groupe] || a.libelle.localeCompare(b.libelle));
  });
  readonly invalides = computed(() => Object.entries(this.saisies()).filter(([, v]) => !this.valide(v)).map(([k]) => k));
  readonly changements = computed(() => {
    const d = this.donnees();
    if (!d) return [];
    return Object.entries(this.saisies())
      .filter(([k, v]) => {
        const [m, n] = k.split('-').map(Number);
        const actuel = d.matieres.find((x) => x.id === m)?.coefficients[n]?.coefficient ?? null;
        return (v.trim() === '' ? null : Number(v)) !== actuel;
      })
      .map(([k, v]) => {
        const [m, n] = k.split('-').map(Number);
        return { matiere_id: m, niveau_id: n, coefficient: v.trim() === '' ? null : Number(v), obligatoire: d.matieres.find((x) => x.id === m)?.coefficients[n]?.obligatoire ?? true };
      });
  });

  // Matiere (fenetre).
  readonly modale = signal(false);
  readonly edition = signal<MatiereParametre | null>(null);
  readonly mCode = signal('');
  readonly mLibelle = signal('');
  readonly mGroupe = signal<Groupe>('LITTERAIRE');
  readonly erreursMatiere = signal<Record<string, string>>({});

  constructor() {
    addIcons({ chevronBackOutline, addOutline, createOutline, trashOutline, closeOutline, bookOutline, cloudOfflineOutline, informationCircleOutline });
  }

  ionViewWillEnter(): void {
    this.service.matieres().subscribe({
      next: (d) => {
        this.appliquer(d);
        // Cycle affiche : celui qui a deja des coefficients (college par defaut).
        const utilises = new Set(d.matieres.flatMap((m) => Object.keys(m.coefficients).map(Number)));
        const cycle = d.niveaux.find((n) => utilises.has(n.id))?.cycle as Cycle | undefined;
        if (cycle) this.cycle.set(cycle);
      },
      error: () => this.erreur.set(true),
    });
  }

  cle(matiereId: number, niveauId: number): string {
    return `${matiereId}-${niveauId}`;
  }

  saisir(matiereId: number, niveauId: number, valeur: string): void {
    this.saisies.update((s) => ({ ...s, [this.cle(matiereId, niveauId)]: valeur }));
  }

  enseignees(niveauId: number): number {
    return this.matieres().filter((m) => (this.saisies()[this.cle(m.id, niveauId)] ?? '').trim() !== '').length;
  }

  annuler(): void {
    const d = this.donnees();
    if (d) this.appliquer(d);
  }

  enregistrer(): void {
    if (this.invalides().length || !this.changements().length) return;
    this.enregistrement.set(true);
    this.service.enregistrerCoefficients(this.changements()).subscribe({
      next: (d) => {
        this.enregistrement.set(false);
        this.appliquer(d);
        this.notifier('Coefficients enregistrés.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  // ------------------------------------------------ Matieres

  nouvelle(): void {
    this.ouvrir(null);
  }

  modifier(m: MatiereParametre): void {
    if (!m.reservee) this.ouvrir(m);
  }

  enregistrerMatiere(): void {
    const saisie = { code: this.mCode().trim().toUpperCase(), libelle: this.mLibelle().trim(), groupe: this.mGroupe() };
    const e: Record<string, string> = {};
    if (!saisie.code) e['code'] = 'Indiquez un code court (ex : MATH).';
    if (!saisie.libelle) e['libelle'] = 'Indiquez le nom de la matière.';
    this.erreursMatiere.set(e);
    if (Object.keys(e).length) return;
    const edition = this.edition();
    this.enregistrement.set(true);
    (edition ? this.service.modifierMatiere(edition.id, saisie) : this.service.creerMatiere(saisie)).subscribe({
      next: (d) => {
        this.enregistrement.set(false);
        this.modale.set(false);
        this.appliquer(d, true);
        this.notifier(edition ? 'Matière modifiée.' : `Matière ${saisie.libelle} ajoutée : fixez ses coefficients par niveau.`);
      },
      error: (err: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = err.error?.errors as Record<string, string[]> | undefined;
        if (erreurs) this.erreursMatiere.set(Object.fromEntries(Object.entries(erreurs).map(([k, v]) => [k, v[0]])));
        else this.notifier(err.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  async supprimer(m: MatiereParametre): Promise<void> {
    const alerte = await this.alertes.create({
      header: 'Supprimer la matière',
      message: `${m.libelle} sera supprimée avec ses coefficients.`,
      cssClass: 'alerte-me',
      buttons: [{ text: 'Annuler', role: 'cancel' }, { text: 'Supprimer', role: 'confirm' }],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') return;
    this.service.supprimerMatiere(m.id).subscribe({
      next: (d) => {
        this.modale.set(false);
        this.appliquer(d, true);
        this.notifier(`Matière ${m.libelle} supprimée.`);
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private ouvrir(m: MatiereParametre | null): void {
    this.edition.set(m);
    this.mCode.set(m?.code ?? '');
    this.mLibelle.set(m?.libelle ?? '');
    this.mGroupe.set(m?.groupe ?? 'LITTERAIRE');
    this.erreursMatiere.set({});
    this.modale.set(true);
  }

  /** @param garder conserve les coefficients saisis non enregistres. */
  private appliquer(d: ParametresMatieres, garder = false): void {
    const avant = garder ? this.changements() : [];
    this.donnees.set(d);
    const s: Record<string, string> = {};
    for (const m of d.matieres) {
      for (const n of d.niveaux) {
        s[this.cle(m.id, n.id)] = m.coefficients[n.id]?.coefficient?.toString() ?? '';
      }
    }
    for (const c of avant) s[this.cle(c.matiere_id, c.niveau_id)] = c.coefficient?.toString() ?? '';
    this.saisies.set(s);
    this.erreur.set(false);
  }

  private valide(v: string): boolean {
    if (!v.trim()) return true;
    const n = Number(v);
    return Number.isInteger(n) && n >= 1 && n <= 20;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
