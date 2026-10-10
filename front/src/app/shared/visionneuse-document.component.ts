import { Component, ElementRef, Input, OnDestroy, OnInit, inject, signal, viewChild } from '@angular/core';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { IonIcon, IonSpinner, ModalController, ToastController } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { closeOutline, documentTextOutline, gridOutline, documentOutline, printOutline, checkmarkOutline, cloudOfflineOutline, downloadOutline } from 'ionicons/icons';
import { DemandeDocument, DocumentService, FormatDocument } from '../core/services/document.service';
import { ApercuPdfMobileComponent } from './apercu-pdf-mobile.component';

/**
 * Visionneuse d'un document de l'API : apercu PDF, telechargement Word /
 * Excel / PDF, impression. Fermeture uniquement par "Terminer".
 */
@Component({
  selector: 'app-visionneuse-document',
  standalone: true,
  imports: [IonIcon, IonSpinner, ApercuPdfMobileComponent],
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
          @if (sansLecteurPdf && pdf) {
            <!-- Telephone : pas de lecteur PDF dans un cadre, pages dessinees par l'application. -->
            <app-apercu-pdf-mobile [pdf]="pdf"></app-apercu-pdf-mobile>
          } @else {
            <iframe #cadre [src]="url()" title="Aperçu du document"></iframe>
          }
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
          <button type="button" class="me-bouton-secondaire" (click)="imprimer()" [disabled]="!url() || impression()">
            @if (impression()) { <ion-spinner name="dots"></ion-spinner> } @else { <ion-icon name="print-outline"></ion-icon> Imprimer }
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
      /* Toute la hauteur de la modale : le pied reste sous l'apercu, jamais par-dessus. */
      .visionneuse {
        height: 100%;
        max-height: none;
      }
      .apercu {
        position: relative;
        flex: 1 1 0;
        min-height: 0;
        display: flex;
        background: var(--me-fond);
      }
      /* Le PDF s'arrete juste au-dessus du pied : derniere page visible en entier. */
      iframe {
        position: absolute;
        inset: 0;
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
        flex-shrink: 0;
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
  /** Navigateur sans lecteur PDF integrable (telephones) : apercu dessine par l'application. */
  readonly sansLecteurPdf = (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled === false || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
  readonly erreur = signal<string | null>(null);
  readonly enCours = signal<FormatDocument | null>(null);
  /** Telephone : pages en preparation pour l'impression. */
  readonly impression = signal(false);
  private paysage = false;

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
    document.getElementById('me-impression-pdf')?.remove();
    this.retirerZoneImpression();
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
        this.paysage = r.headers.get('X-Orientation') === 'landscape';
        this.hote.nativeElement.closest('ion-modal')?.classList.toggle('paysage', this.paysage);
        this.urlBrute = URL.createObjectURL(new Blob([r.body!], { type: 'application/pdf' }));
        // #zoom=100 : le lecteur PDF du navigateur ouvre le document a 100 %.
        this.url.set(this.assainisseur.bypassSecurityTrustResourceUrl(this.urlBrute + '#zoom=100'));
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

  /**
   * Lance l'impression du PDF : charge dans un cadre invisible (le PDF seul,
   * pas la page), la fenetre d'impression s'ouvre des qu'il est pret. Telephone
   * (pas d'impression depuis un cadre) : pages dessinees puis imprimees depuis
   * la page elle-meme, sans ouvrir d'onglet.
   */
  imprimer(): void {
    if (!this.urlBrute) return;
    if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
      void this.imprimerSurTelephone();
      return;
    }
    document.getElementById('me-impression-pdf')?.remove();
    const cadre = document.createElement('iframe');
    cadre.id = 'me-impression-pdf';
    cadre.setAttribute('aria-hidden', 'true');
    cadre.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';
    cadre.onload = () =>
      // Petit delai : le lecteur PDF du navigateur doit avoir fini d'afficher le document.
      setTimeout(() => {
        try {
          cadre.contentWindow?.focus();
          cadre.contentWindow?.print();
        } catch {
          this.telecharger('pdf');
        }
      }, 400);
    cadre.src = this.urlBrute;
    document.body.appendChild(cadre);
  }

  /**
   * Telephone : chaque page du PDF devient une image dans une zone seule
   * visible a l'impression (@media print), puis window.print().
   */
  private async imprimerSurTelephone(): Promise<void> {
    if (!this.pdf || this.impression()) return;
    this.impression.set(true);
    this.retirerZoneImpression();
    try {
      const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = 'pdfjs/pdf.worker.min.mjs';
      const doc = await pdfjs.getDocument({ data: new Uint8Array(await this.pdf.arrayBuffer()) }).promise;
      const zone = document.createElement('div');
      zone.id = 'me-zone-impression';
      const style = document.createElement('style');
      style.id = 'me-style-impression';
      style.textContent = `
        #me-zone-impression { display: none; }
        @media print {
          @page { margin: 0; size: A4 ${this.paysage ? 'landscape' : 'portrait'}; }
          html, body { height: auto !important; overflow: visible !important; position: static !important; background: #fff !important; }
          body > :not(#me-zone-impression) { display: none !important; }
          #me-zone-impression { display: block; }
          #me-zone-impression img { display: block; width: 100%; height: 100vh; object-fit: contain; break-after: page; }
          #me-zone-impression img:last-child { break-after: auto; }
        }`;
      for (let n = 1; n <= doc.numPages; n++) {
        const page = await doc.getPage(n);
        // ~150 dpi : net a l'impression, memoire raisonnable sur telephone.
        const vue = page.getViewport({ scale: 150 / 72 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(vue.width);
        canvas.height = Math.floor(vue.height);
        await page.render({ canvasContext: canvas.getContext('2d')!, viewport: vue }).promise;
        const image = await new Promise<Blob | null>((ok) => canvas.toBlob(ok, 'image/jpeg', 0.9));
        canvas.width = canvas.height = 0;
        const img = document.createElement('img');
        img.src = URL.createObjectURL(image!);
        zone.appendChild(img);
        page.cleanup();
      }
      await doc.destroy();
      document.head.appendChild(style);
      document.body.appendChild(zone);
      await Promise.all(Array.from(zone.querySelectorAll('img')).map((i) => i.decode().catch(() => undefined)));
      window.addEventListener('afterprint', () => setTimeout(() => this.retirerZoneImpression(), 500), { once: true });
      window.print();
    } catch {
      this.retirerZoneImpression();
      this.telecharger('pdf');
    } finally {
      this.impression.set(false);
    }
  }

  private retirerZoneImpression(): void {
    document.querySelectorAll<HTMLImageElement>('#me-zone-impression img').forEach((i) => URL.revokeObjectURL(i.src));
    document.getElementById('me-zone-impression')?.remove();
    document.getElementById('me-style-impression')?.remove();
  }

  terminer(): void {
    this.modales.dismiss(null, 'terminer');
  }
}
