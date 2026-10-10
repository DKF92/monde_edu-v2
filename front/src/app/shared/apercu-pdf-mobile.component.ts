import { Component, ElementRef, Input, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { IonSpinner } from '@ionic/angular';
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist/legacy/build/pdf.mjs';

/**
 * Apercu PDF pour les telephones seulement : leurs navigateurs n'affichent
 * pas un PDF dans un cadre (bouton « Ouvrir » a la place). Les pages sont
 * dessinees par pdf.js, a la largeur de l'ecran, au fil du defilement
 * (memoire des telephones). Sur ordinateur, la visionneuse garde le lecteur
 * PDF du navigateur.
 */
@Component({
  selector: 'app-apercu-pdf-mobile',
  standalone: true,
  imports: [IonSpinner],
  template: `
    @if (erreur()) {
      <p class="message">Aperçu impossible sur ce téléphone : utilisez le bouton PDF pour l'ouvrir.</p>
    } @else if (!pages().length) {
      <div class="message"><ion-spinner name="crescent"></ion-spinner> Préparation de l'aperçu…</div>
    }
    @for (p of pages(); track p) {
      <div class="page" [attr.data-page]="p" [style.aspect-ratio]="ratios[p - 1]"></div>
    }
  `,
  styles: [
    `
      :host {
        position: absolute;
        inset: 0;
        overflow: auto;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        -webkit-overflow-scrolling: touch;
      }
      .page {
        flex-shrink: 0;
        width: 100%;
        background: #fff;
        box-shadow: 0 1px 6px rgba(16, 24, 40, 0.18);
      }
      .message {
        margin: auto;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px;
        text-align: center;
        color: var(--me-texte-doux);
        font-size: 14px;
      }
    `,
  ],
})
export class ApercuPdfMobileComponent implements OnInit, OnDestroy {
  private readonly hote = inject(ElementRef<HTMLElement>);

  @Input({ required: true }) pdf!: Blob;

  readonly pages = signal<number[]>([]);
  readonly erreur = signal(false);
  /** Largeur / hauteur de chaque page (place reservee avant le dessin). */
  ratios: string[] = [];

  private document: PDFDocumentProxy | null = null;
  private observateur: IntersectionObserver | null = null;
  private rendus = new Map<number, RenderTask>();

  async ngOnInit(): Promise<void> {
    try {
      const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
      // Worker copie dans public/pdfjs (meme version que la bibliotheque).
      pdfjs.GlobalWorkerOptions.workerSrc = 'pdfjs/pdf.worker.min.mjs';
      this.document = await pdfjs.getDocument({ data: new Uint8Array(await this.pdf.arrayBuffer()) }).promise;
      for (let n = 1; n <= this.document.numPages; n++) {
        const vue = (await this.document.getPage(n)).getViewport({ scale: 1 });
        this.ratios.push(`${vue.width} / ${vue.height}`);
      }
      this.pages.set(Array.from({ length: this.document.numPages }, (_, i) => i + 1));
      setTimeout(() => this.observer());
    } catch {
      this.erreur.set(true);
    }
  }

  ngOnDestroy(): void {
    this.observateur?.disconnect();
    this.rendus.forEach((r) => r.cancel());
    void this.document?.destroy();
  }

  /** Pages proches de l'ecran dessinees, pages eloignees liberees. */
  private observer(): void {
    const zone: HTMLElement = this.hote.nativeElement;
    this.observateur = new IntersectionObserver(
      (entrees) =>
        entrees.forEach((e) => {
          const page = e.target as HTMLElement;
          const numero = Number(page.dataset['page']);
          if (e.isIntersecting) {
            void this.dessiner(page, numero);
          } else {
            this.rendus.get(numero)?.cancel();
            page.replaceChildren();
          }
        }),
      { root: zone, rootMargin: '800px 0px' },
    );
    zone.querySelectorAll<HTMLElement>('.page').forEach((p) => this.observateur!.observe(p));
  }

  private async dessiner(conteneur: HTMLElement, numero: number): Promise<void> {
    if (!this.document || conteneur.firstChild || this.rendus.has(numero)) return;
    const page = await this.document.getPage(numero);
    // Largeur de l'ecran, nette (densite limitee a 2 pour la memoire).
    const densite = Math.min(window.devicePixelRatio || 1, 2);
    const echelle = (conteneur.clientWidth * densite) / page.getViewport({ scale: 1 }).width;
    const vue = page.getViewport({ scale: echelle });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(vue.width);
    canvas.height = Math.floor(vue.height);
    // Style en ligne : un canvas cree a la main ne recoit pas les styles du composant.
    canvas.style.cssText = 'display:block;width:100%;height:100%';
    const rendu = page.render({ canvasContext: canvas.getContext('2d')!, viewport: vue });
    this.rendus.set(numero, rendu);
    try {
      await rendu.promise;
      conteneur.replaceChildren(canvas);
    } catch {
      // Dessin annule (page sortie de l'ecran) : refait au retour.
    } finally {
      this.rendus.delete(numero);
    }
  }
}
