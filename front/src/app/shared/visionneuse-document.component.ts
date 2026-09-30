import { Component, ElementRef, Input, OnDestroy, OnInit, inject, signal, viewChild } from '@angular/core';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { IonIcon, IonSpinner, ModalController, ToastController } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { closeOutline, documentTextOutline, gridOutline, documentOutline, printOutline, checkmarkOutline, cloudOfflineOutline, downloadOutline } from 'ionicons/icons';
import { DemandeDocument, DocumentService, FormatDocument } from '../core/services/document.service';

/**
 * Visionneuse d'un document de l'API : apercu PDF, telechargement Word /
 * Excel / PDF, impression. Fermeture uniquement par "Terminer".
 */
@Component({
  selector: 'app-visionneuse-document',
  standalone: true,
  imports: [IonIcon, IonSpinner],
  template: `
    <div class="me-modale visionneuse">
      <header>
        <span class="me-pastille teinte-cyan"><ion-icon name="document-text-outline"></ion-icon></span>
        <div>
          <h1>{{ demande.titre }}</h1>
          <p>Aperçu du document</p>
        </div>
      </header>

      <div class="apercu">
        @if (erreur()) {
          <div class="me-etat-vide">
            <ion-icon name="cloud-offline-outline"></ion-icon>
            <strong>{{ erreur() }}</strong>
            <button type="button" class="me-lien" (click)="charger()">Réessayer</button>
          </div>
        } @else if (!url()) {
          <div class="attente"><ion-spinner name="crescent"></ion-spinner> Préparation du document…</div>
        } @else {
          <iframe #cadre [src]="url()" title="Aperçu du document"></iframe>
        }
      </div>

      <footer>
        <div class="telecharger">
          <span class="libelle"><ion-icon name="download-outline"></ion-icon> Télécharger :</span>
          <button type="button" class="me-bouton-secondaire" (click)="telecharger('doc')" [disabled]="!!enCours()">
            @if (enCours() === 'doc') { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="document-outline"></ion-icon> Word }
          </button>
          <button type="button" class="me-bouton-secondaire" (click)="telecharger('xlsx')" [disabled]="!!enCours()">
            @if (enCours() === 'xlsx') { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="grid-outline"></ion-icon> Excel }
          </button>
          <button type="button" class="me-bouton-secondaire" (click)="telecharger('pdf')" [disabled]="!!enCours() || !pdf">
            @if (enCours() === 'pdf') { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="document-text-outline"></ion-icon> PDF }
          </button>
        </div>
        <div class="fin">
          <button type="button" class="me-bouton-secondaire" (click)="imprimer()" [disabled]="!url()">
            <ion-icon name="print-outline"></ion-icon> Imprimer
          </button>
          <button type="button" class="me-bouton-principal" (click)="terminer()">
            <ion-icon name="checkmark-outline"></ion-icon> Terminer
          </button>
        </div>
      </footer>
    </div>
  `,
  styles: [
    `
      .visionneuse {
        height: 100%;
      }
      .apercu {
        flex: 1;
        min-height: 0;
        display: flex;
        background: var(--me-fond);
      }
      iframe {
        flex: 1;
        width: 100%;
        height: 100%;
        border: none;
        background: #fff;
      }
      .attente {
        margin: auto;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--me-texte-doux);
        font-size: 14px;
      }
      .me-etat-vide {
        margin: auto;
      }
      footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
      }
      .telecharger,
      .fin {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
      }
      .libelle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 700;
        color: var(--me-texte-doux);
      }
      footer button {
        height: 40px;
      }
    `,
  ],
})
export class VisionneuseDocumentComponent implements OnInit, OnDestroy {
  private readonly documents = inject(DocumentService);
  private readonly modales = inject(ModalController);
  private readonly toasts = inject(ToastController);
  private readonly assainisseur = inject(DomSanitizer);
  private readonly hote = inject(ElementRef<HTMLElement>);

  /** Passe par ModalController (componentProps) : propriete simple, pas un signal. */
  @Input({ required: true }) demande!: DemandeDocument;
  readonly cadre = viewChild<ElementRef<HTMLIFrameElement>>('cadre');

  readonly url = signal<SafeResourceUrl | null>(null);
  readonly erreur = signal<string | null>(null);
  readonly enCours = signal<FormatDocument | null>(null);

  /** PDF deja recu (apercu) : reutilise pour le telechargement PDF. */
  pdf: Blob | null = null;
  private urlBrute: string | null = null;

  constructor() {
    addIcons({ closeOutline, documentTextOutline, gridOutline, documentOutline, printOutline, checkmarkOutline, cloudOfflineOutline, downloadOutline });
  }

  ngOnInit(): void {
    this.charger();
  }

  ngOnDestroy(): void {
    if (this.urlBrute) {
      URL.revokeObjectURL(this.urlBrute);
    }
  }

  charger(): void {
    this.erreur.set(null);
    this.documents.telecharger(this.demande, 'pdf').subscribe({
      next: (r) => {
        this.pdf = r.body;
        // Document en paysage : modale elargie (voir .visionneuse-document.paysage).
        this.hote.nativeElement.closest('ion-modal')?.classList.toggle('paysage', r.headers.get('X-Orientation') === 'landscape');
        this.urlBrute = URL.createObjectURL(new Blob([r.body!], { type: 'application/pdf' }));
        this.url.set(this.assainisseur.bypassSecurityTrustResourceUrl(this.urlBrute));
      },
      error: () => this.erreur.set('Impossible de préparer le document.'),
    });
  }

  telecharger(format: FormatDocument): void {
    const extension = { pdf: 'pdf', doc: 'doc', xlsx: 'xlsx' }[format];
    const nom = `${this.demande.nomFichier}.${extension}`;
    if (format === 'pdf' && this.pdf) {
      this.documents.enregistrer(this.pdf, nom);
      return;
    }
    this.enCours.set(format);
    this.documents.telecharger(this.demande, format).subscribe({
      next: (r) => {
        this.enCours.set(null);
        this.documents.enregistrer(r.body!, nom);
      },
      error: async () => {
        this.enCours.set(null);
        const toast = await this.toasts.create({ message: 'Téléchargement impossible. Réessayez.', duration: 3000, color: 'danger' });
        await toast.present();
      },
    });
  }

  /** Imprime le PDF affiche (impression du navigateur). */
  imprimer(): void {
    const fenetre = this.cadre()?.nativeElement.contentWindow;
    try {
      fenetre?.focus();
      fenetre?.print();
    } catch {
      // Apercu non imprimable (certains mobiles) : le PDF est telecharge pour etre imprime.
      this.telecharger('pdf');
    }
  }

  terminer(): void {
    this.modales.dismiss(null, 'terminer');
  }
}
