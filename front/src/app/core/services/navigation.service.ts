import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from './auth.service';
import { EntreeNavigation, NAVIGATION, SectionNavigation } from '../navigation';

/** Navigation filtree selon les permissions de l'utilisateur connecte. */
@Injectable({ providedIn: 'root' })
export class NavigationService {
  private readonly auth = inject(AuthService);

  readonly sections = computed<SectionNavigation[]>(() => {
    // Relu a chaque changement d'utilisateur (connexion / deconnexion).
    this.auth.user();

    return NAVIGATION.map((section) => ({
      ...section,
      entrees: section.entrees.filter((entree) => this.estAutorisee(entree)),
    })).filter((section) => section.entrees.length > 0);
  });

  /** Tuiles de l'accueil : tout sauf l'accueil lui-meme, ecrans disponibles
   * en premier. */
  readonly accesRapides = computed<EntreeNavigation[]>(() =>
    this.sections()
      .flatMap((section) => section.entrees)
      .filter((entree) => entree.route !== '/tabs/dashboard')
      .sort((a, b) => Number(b.disponible) - Number(a.disponible)),
  );

  readonly barreMobile = computed<EntreeNavigation[]>(() =>
    this.sections()
      .flatMap((section) => section.entrees)
      .filter((entree) => entree.barreMobile && entree.disponible)
      .slice(0, 4),
  );

  private estAutorisee(entree: EntreeNavigation): boolean {
    return entree.permissions.length === 0 || entree.permissions.some((p) => this.auth.aPermission(p));
  }
}
