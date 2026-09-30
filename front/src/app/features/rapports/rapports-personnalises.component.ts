import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonIcon, IonModal, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline, arrowDownOutline, arrowUpOutline, closeOutline, constructOutline, createOutline, eyeOutline, gridOutline,
  printOutline, saveOutline, textOutline, trashOutline, maleFemaleOutline, checkmarkCircleOutline, repeatOutline, layersOutline,
  schoolOutline, peopleOutline, swapVerticalOutline, calendarOutline,
} from 'ionicons/icons';
import { ApercuPerso, CataloguePerso, ColonnePerso, ConfigRapportPerso, RapportsAvancesService } from '../../core/services/rapports-avances.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { OptionMultiple, SelecteurMultipleComponent } from '../../shared/selecteur-multiple.component';

const NOUVEAU: ConfigRapportPerso = {
  titre: '',
  regroupement: 'classe',
  tri: 'nom',
  numeroter: true,
  saut_page: true,
  sans_classe: false,
  periode_id: null,
  colonnes: [
    { type: 'champ', cle: 'matricule', libelle: null },
    { type: 'champ', cle: 'nom_prenoms', libelle: null },
    { type: 'champ', cle: 'sexe', libelle: null },
  ],
  filtres: {},
};

/**
 * Rapports parametrables : l'etablissement compose une liste d'eleves
 * (colonnes de la base ou colonnes libres dont il donne l'en-tete), choisit
 * le regroupement (etablissement, cycle, niveau, classe), les filtres et le
 * tri, l'apercoit puis l'imprime ; il peut l'enregistrer comme modele.
 */
@Component({
  selector: 'app-rapports-personnalises',
  standalone: true,
  imports: [IonIcon, IonModal, IonSpinner, SelecteurComponent, SelecteurMultipleComponent],
  styleUrls: ['./rapports.page.scss', './rapports-personnalises.component.scss'],
  templateUrl: './rapports-personnalises.component.html',
})
export class RapportsPersonnalisesComponent implements OnInit {
  private readonly service = inject(RapportsAvancesService);
  private readonly toasts = inject(ToastController);
  private readonly alertes = inject(AlertController);

  readonly catalogue = signal<CataloguePerso | null>(null);
  readonly config = signal<ConfigRapportPerso | null>(null);
  readonly apercu = signal<ApercuPerso | null>(null);
  readonly erreurs = signal<Record<string, string>>({});
  readonly action = signal<'apercu' | 'imprimer' | 'enregistrer' | string | null>(null);
  readonly libre = signal('');

  readonly optionsChamp = computed<OptionSelecteur[]>(() => {
    const deja = new Set((this.config()?.colonnes ?? []).filter((c) => c.type === 'champ').map((c) => c.cle));
    return [
      { valeur: 0, libelle: 'Ajouter une colonne…' },
      ...(this.catalogue()?.champs ?? []).map((c, i) => ({ valeur: i + 1, libelle: c.rubrique + ' · ' + c.libelle, desactivee: deja.has(c.cle) })),
    ];
  });
  readonly optionsTri = computed<OptionSelecteur[]>(() => (this.catalogue()?.tris ?? []).map((t, i) => ({ valeur: i + 1, libelle: t.libelle })));
  readonly rangTri = computed(() => (this.catalogue()?.tris ?? []).findIndex((t) => t.valeur === this.config()?.tri) + 1);
  readonly optionsPeriode = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Choisir la période…' },
    ...(this.catalogue()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle })),
  ]);
  readonly optionsCycles = computed<OptionMultiple[]>(() => (this.catalogue()?.cycles ?? []).map((c, i) => ({ valeur: i + 1, libelle: c.libelle })));
  readonly optionsNiveaux = computed<OptionMultiple[]>(() => (this.catalogue()?.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })));
  readonly optionsClasses = computed<OptionMultiple[]>(() => (this.catalogue()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));
  readonly cyclesChoisis = computed(() => {
    const cycles = this.catalogue()?.cycles ?? [];
    return (this.config()?.filtres.cycles ?? []).map((c) => cycles.findIndex((x) => x.valeur === c) + 1).filter((v) => v > 0);
  });
  readonly optionsSexe: OptionSelecteur[] = [{ valeur: 0, libelle: 'Filles et garçons' }, { valeur: 1, libelle: 'Filles' }, { valeur: 2, libelle: 'Garçons' }];
  readonly optionsStatut: OptionSelecteur[] = [{ valeur: 0, libelle: 'Affectés et non affectés' }, { valeur: 1, libelle: 'Affectés' }, { valeur: 2, libelle: 'Non affectés' }];
  readonly optionsRedoublant: OptionSelecteur[] = [{ valeur: 0, libelle: 'Redoublants ou non' }, { valeur: 1, libelle: 'Redoublants' }, { valeur: 2, libelle: 'Non redoublants' }];
  readonly besoinPeriode = computed(() => {
    const c = this.config();
    return !!c && (c.tri === 'moyenne' || c.colonnes.some((x) => x.cle === 'moyenne_periode' || x.cle === 'rang_periode'));
  });

  constructor() {
    addIcons({ addOutline, arrowDownOutline, arrowUpOutline, closeOutline, constructOutline, createOutline, eyeOutline, gridOutline, printOutline, saveOutline, textOutline, trashOutline,
      maleFemaleOutline, checkmarkCircleOutline, repeatOutline, layersOutline, schoolOutline, peopleOutline, swapVerticalOutline, calendarOutline });
  }

  ngOnInit(): void {
    this.service.personnalises().subscribe({ next: (c) => this.catalogue.set(c), error: () => this.catalogue.set(null) });
  }

  // ------------------------------------------------ Modeles

  nouveau(): void {
    this.ouvrirEditeur(structuredClone(NOUVEAU));
  }

  modifier(m: ConfigRapportPerso): void {
    this.ouvrirEditeur(structuredClone(m));
  }

  async imprimerModele(m: ConfigRapportPerso): Promise<void> {
    this.action.set('modele-' + m.id);
    await this.service.imprimerPersonnalise(m);
    this.action.set(null);
  }

  async supprimer(m: ConfigRapportPerso): Promise<void> {
    const alerte = await this.alertes.create({
      header: 'Supprimer ce rapport ?',
      message: `« ${m.titre} » ne sera plus proposé dans les rapports.`,
      buttons: [{ text: 'Annuler', role: 'cancel' }, { text: 'Supprimer', role: 'destructive' }],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'destructive') return;
    this.service.supprimer(m.id!).subscribe({
      next: (r) => {
        this.catalogue.update((c) => (c ? { ...c, modeles: c.modeles.filter((x) => x.id !== m.id) } : c));
        this.notifier(r.message);
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  // ------------------------------------------------ Editeur

  maj(changement: Partial<ConfigRapportPerso>): void {
    this.config.update((c) => (c ? { ...c, ...changement } : c));
    this.apercu.set(null);
    this.erreurs.set({});
  }

  majFiltres(changement: Partial<ConfigRapportPerso['filtres']>): void {
    const c = this.config();
    if (!c) return;
    const filtres = { ...c.filtres, ...changement };
    for (const k of Object.keys(filtres) as (keyof typeof filtres)[]) {
      const v = filtres[k];
      if (v === undefined || (Array.isArray(v) && !v.length)) delete filtres[k];
    }
    this.maj({ filtres });
  }

  ajouterChamp(rang: number): void {
    const champ = this.catalogue()?.champs[rang - 1];
    const c = this.config();
    if (!champ || !c) return;
    this.maj({ colonnes: [...c.colonnes, { type: 'champ', cle: champ.cle, libelle: null }] });
  }

  ajouterLibre(): void {
    const libelle = this.libre().trim();
    const c = this.config();
    if (!libelle || !c) return;
    this.maj({ colonnes: [...c.colonnes, { type: 'libre', cle: null, libelle }] });
    this.libre.set('');
  }

  renommer(i: number, libelle: string): void {
    const c = this.config();
    if (!c) return;
    this.maj({ colonnes: c.colonnes.map((x, j) => (j === i ? { ...x, libelle: libelle.trim() || (x.type === 'libre' ? x.libelle : null) } : x)) });
  }

  deplacer(i: number, sens: -1 | 1): void {
    const c = this.config();
    if (!c) return;
    const colonnes = [...c.colonnes];
    const j = i + sens;
    if (j < 0 || j >= colonnes.length) return;
    [colonnes[i], colonnes[j]] = [colonnes[j], colonnes[i]];
    this.maj({ colonnes });
  }

  retirer(i: number): void {
    const c = this.config();
    if (!c) return;
    this.maj({ colonnes: c.colonnes.filter((_, j) => j !== i) });
  }

  libelleChamp(col: ColonnePerso): string {
    return col.type === 'libre' ? 'Colonne libre' : (this.catalogue()?.champs.find((c) => c.cle === col.cle)?.libelle ?? col.cle ?? '');
  }

  choisirSexe(v: number): void {
    this.majFiltres({ sexe: v === 1 ? 'F' : v === 2 ? 'M' : undefined });
  }

  choisirStatut(v: number): void {
    this.majFiltres({ affecte: v === 1 ? true : v === 2 ? false : undefined });
  }

  choisirRedoublant(v: number): void {
    this.majFiltres({ redoublant: v === 1 ? true : v === 2 ? false : undefined });
  }

  choisirCycles(rangs: number[]): void {
    const cycles = this.catalogue()?.cycles ?? [];
    this.majFiltres({ cycles: rangs.map((r) => cycles[r - 1]?.valeur).filter((v): v is string => !!v) });
  }

  valeurSexe(): number {
    const s = this.config()?.filtres.sexe;
    return s === 'F' ? 1 : s === 'M' ? 2 : 0;
  }

  valeurBool(v: boolean | undefined): number {
    return v === true ? 1 : v === false ? 2 : 0;
  }

  voirApercu(): void {
    const c = this.config();
    if (!c) return;
    this.action.set('apercu');
    this.service.apercu(c).subscribe({
      next: (a) => {
        this.apercu.set(a);
        this.action.set(null);
        setTimeout(() => document.getElementById('apercu-perso')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
      },
      error: (e) => this.erreur(e),
    });
  }

  async imprimer(): Promise<void> {
    const c = this.config();
    if (!c) return;
    // Controle de la configuration avant d'ouvrir la visionneuse.
    this.action.set('imprimer');
    this.service.apercu(c).subscribe({
      next: async () => {
        await this.service.imprimerPersonnalise({ ...c, id: undefined });
        this.action.set(null);
      },
      error: (e) => this.erreur(e),
    });
  }

  enregistrer(): void {
    const c = this.config();
    if (!c) return;
    this.action.set('enregistrer');
    this.service.enregistrer(c).subscribe({
      next: (r) => {
        this.action.set(null);
        this.config.set(structuredClone(r.modele));
        this.catalogue.update((cat) => {
          if (!cat) return cat;
          const existe = cat.modeles.some((m) => m.id === r.modele.id);
          return { ...cat, modeles: existe ? cat.modeles.map((m) => (m.id === r.modele.id ? r.modele : m)) : [...cat.modeles, r.modele] };
        });
        this.notifier(r.message);
      },
      error: (e) => this.erreur(e),
    });
  }

  fermer(): void {
    this.config.set(null);
    this.apercu.set(null);
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  cellule(ligne: Record<string, unknown>, cle: string, type?: string): string {
    const v = ligne[cle];
    if (v === null || v === undefined) return '';
    if (type === 'moyenne') return (v as number).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (type === 'montant') return (v as number).toLocaleString('fr-FR');
    return String(v);
  }

  private ouvrirEditeur(config: ConfigRapportPerso): void {
    this.config.set(config);
    this.apercu.set(null);
    this.erreurs.set({});
    this.libre.set('');
  }

  private erreur(e: HttpErrorResponse): void {
    this.action.set(null);
    const erreurs = e.error?.errors as Record<string, string[]> | undefined;
    if (erreurs) {
      this.erreurs.set(Object.fromEntries(Object.entries(erreurs).map(([k, v]) => [k.startsWith('colonnes') ? 'colonnes' : k, v[0]])));
    }
    this.notifier(e.error?.message || 'Action impossible.', true);
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
