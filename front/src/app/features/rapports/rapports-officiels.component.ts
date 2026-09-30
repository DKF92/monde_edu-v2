import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonIcon, IonModal, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { bookOutline, schoolOutline, calendarOutline, ribbonOutline, closeOutline, documentTextOutline, saveOutline } from 'ionicons/icons';
import { CatalogueOfficiels, RapportsAvancesService, TextesRapport, TypeRapportOfficiel } from '../../core/services/rapports-avances.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

const VIDES: TextesRapport = { introduction: null, observations: null, difficultes: null, perspectives: null, conclusion: null, signataire: null };

/**
 * Rapports officiels (rentree, fin de periode, fin d'annee) : un document
 * organise comme un memoire qui regroupe les rapports pedagogiques. Les
 * textes libres (observations, difficultes...) sont rediges ici puis
 * imprimes dans le document ; laisses vides, le document propose des lignes
 * a remplir a la main.
 */
@Component({
  selector: 'app-rapports-officiels',
  standalone: true,
  imports: [IonIcon, IonModal, IonSpinner, SelecteurComponent],
  styleUrls: ['./rapports.page.scss'],
  template: `
    @if (catalogue(); as c) {
      <h2 class="rubrique">Rapports officiels</h2>
      <section class="catalogue" aria-label="Rapports officiels">
        @for (t of c.types; track t.code) {
          <div class="me-carte rapport officiel" role="button" tabindex="0" (click)="ouvrir(t.code)" (keydown.enter)="ouvrir(t.code)">
            <span class="me-pastille" [class]="teintes[t.code]"><ion-icon [name]="icones[t.code]"></ion-icon></span>
            <span class="texte"><strong>{{ t.titre }}</strong><small>{{ t.description }}</small></span>
            <ion-icon name="document-text-outline" class="fleche"></ion-icon>
          </div>
        }
      </section>
    }

    <ion-modal class="me-modale-formulaire large" [isOpen]="!!type()" (didDismiss)="type.set(null)">
      <ng-template>
        <div class="me-modale">
          <header>
            <span class="me-pastille" [class]="teintes[type() ?? 'rentree']"><ion-icon [name]="icones[type() ?? 'rentree']"></ion-icon></span>
            <div>
              <h1>{{ titreType() }}</h1>
              <p>Tableaux et chiffres calculés automatiquement ; rédigez ici vos observations.</p>
            </div>
            <button type="button" class="me-fermer" (click)="type.set(null)" aria-label="Fermer"><ion-icon name="close-outline"></ion-icon></button>
          </header>
          <div class="corps textes-rapport">
            @if (type() === 'periode') {
              <div class="me-champ">
                <span class="libelle">Période</span>
                <app-selecteur idChamp="ro-periode" icone="calendar-outline" [options]="optionsPeriode()" [valeur]="periodeId()" (valeurChange)="choisirPeriode($event)"></app-selecteur>
              </div>
            }
            @for (ch of champs; track ch.cle) {
              <div class="me-champ">
                <label [for]="'ro-' + ch.cle">{{ ch.libelle }}</label>
                @if (ch.cle === 'signataire') {
                  <input [id]="'ro-' + ch.cle" class="me-saisie" maxlength="150" [placeholder]="ch.aide" [value]="textes()[ch.cle] ?? ''" (input)="saisir(ch.cle, $event)" />
                } @else {
                  <textarea [id]="'ro-' + ch.cle" class="me-saisie" rows="3" maxlength="6000" [placeholder]="ch.aide" [value]="textes()[ch.cle] ?? ''" (input)="saisir(ch.cle, $event)"></textarea>
                }
              </div>
            }
            <p class="me-aide-champ">Un paragraphe par ligne vide. Les rubriques laissées vides sont imprimées avec des lignes à remplir à la main (l'introduction est alors rédigée automatiquement).</p>
          </div>
          <footer>
            <button type="button" class="me-bouton-texte" (click)="enregistrer(false)" [disabled]="!!action() || !pret()">
              @if (action() === 'enregistrer') { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="save-outline"></ion-icon> Enregistrer les textes }
            </button>
            <button type="button" class="me-bouton-principal" (click)="enregistrer(true)" [disabled]="!!action() || !pret()">
              @if (action() === 'generer') { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="document-text-outline"></ion-icon> Générer le rapport }
            </button>
          </footer>
        </div>
      </ng-template>
    </ion-modal>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      gap: inherit;
    }
    .textes-rapport {
      display: grid;
      gap: 14px;
    }
    .textes-rapport textarea {
      min-height: 84px;
    }
    footer ion-icon {
      font-size: 18px;
    }
  `,
})
export class RapportsOfficielsComponent implements OnInit {
  private readonly service = inject(RapportsAvancesService);
  private readonly toasts = inject(ToastController);

  readonly icones: Record<TypeRapportOfficiel, string> = { rentree: 'school-outline', periode: 'calendar-outline', annuel: 'ribbon-outline' };
  readonly teintes: Record<TypeRapportOfficiel, string> = { rentree: 'teinte-cyan', periode: 'teinte-violet', annuel: 'teinte-vert' };
  readonly champs: { cle: keyof TextesRapport; libelle: string; aide: string }[] = [
    { cle: 'introduction', libelle: 'Introduction', aide: 'Vide : introduction rédigée automatiquement' },
    { cle: 'observations', libelle: 'Observations', aide: 'Climat de l\'établissement, travail des élèves, faits marquants…' },
    { cle: 'difficultes', libelle: 'Difficultés rencontrées', aide: 'Manque d\'enseignants, effectifs pléthoriques, infrastructures…' },
    { cle: 'perspectives', libelle: 'Perspectives et suggestions', aide: 'Actions prévues, besoins, propositions…' },
    { cle: 'conclusion', libelle: 'Conclusion', aide: 'Vide : lignes à remplir à la main' },
    { cle: 'signataire', libelle: 'Signataire', aide: 'Le Chef d\'établissement' },
  ];

  readonly catalogue = signal<CatalogueOfficiels | null>(null);
  readonly type = signal<TypeRapportOfficiel | null>(null);
  readonly periodeId = signal(0);
  readonly textes = signal<TextesRapport>({ ...VIDES });
  readonly action = signal<'enregistrer' | 'generer' | null>(null);

  readonly optionsPeriode = computed<OptionSelecteur[]>(() => (this.catalogue()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle })));
  readonly pret = computed(() => this.type() !== 'periode' || !!this.periodeId());
  readonly titreType = computed(() => {
    const t = this.catalogue()?.types.find((x) => x.code === this.type());
    const p = this.catalogue()?.periodes.find((x) => x.id === this.periodeId());
    return (t?.titre ?? '') + (this.type() === 'periode' && p ? ' · ' + p.libelle : '');
  });

  constructor() {
    addIcons({ bookOutline, schoolOutline, calendarOutline, ribbonOutline, closeOutline, documentTextOutline, saveOutline });
  }

  ngOnInit(): void {
    this.service.officiels().subscribe({ next: (c) => this.catalogue.set(c), error: () => this.catalogue.set(null) });
  }

  ouvrir(type: TypeRapportOfficiel): void {
    const c = this.catalogue();
    if (!c) return;
    this.type.set(type);
    if (type === 'periode') {
      this.periodeId.set((c.periodes.find((p) => p.active) ?? c.periodes[0])?.id ?? 0);
    }
    this.chargerTextes();
  }

  choisirPeriode(id: number): void {
    this.periodeId.set(id);
    this.chargerTextes();
  }

  saisir(cle: keyof TextesRapport, evenement: Event): void {
    const valeur = (evenement.target as HTMLInputElement | HTMLTextAreaElement).value;
    this.textes.update((t) => ({ ...t, [cle]: valeur }));
  }

  /** Enregistre les textes puis, si demande, genere le document. */
  enregistrer(generer: boolean): void {
    const type = this.type();
    if (!type) return;
    const periode = type === 'periode' ? this.periodeId() : null;
    this.action.set(generer ? 'generer' : 'enregistrer');
    this.service.enregistrerTextes(type, periode, this.textes()).subscribe({
      next: async (r) => {
        this.catalogue.update((c) => (c ? { ...c, textes: { ...c.textes, [this.cle()]: r.textes } } : c));
        if (generer) {
          await this.service.imprimerOfficiel(type, periode, this.titreType());
        } else {
          this.notifier(r.message);
        }
        this.action.set(null);
      },
      error: (e: HttpErrorResponse) => {
        this.action.set(null);
        this.notifier(e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  private cle(): string {
    return this.type() === 'periode' ? 'periode-' + this.periodeId() : (this.type() ?? '');
  }

  private chargerTextes(): void {
    this.textes.set({ ...VIDES, ...(this.catalogue()?.textes[this.cle()] ?? {}) });
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
