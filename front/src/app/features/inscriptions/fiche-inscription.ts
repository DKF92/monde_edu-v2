import { DetailInscription, LIBELLES_DECISION, LIBELLES_STATUT } from '../../core/models/inscription.model';

/**
 * Fiche d'inscription imprimable (V1 recu_inscr) : A4, ouverte dans une
 * fenetre puis imprimee (ou enregistree en PDF depuis la boite d'impression).
 */
export function imprimerFicheInscription(d: DetailInscription): boolean {
  const fenetre = window.open('', '_blank', 'width=900,height=1000');
  if (!fenetre) {
    return false;
  }
  fenetre.document.open();
  fenetre.document.write(ficheHtml(d));
  fenetre.document.close();
  return true;
}

function ficheHtml(d: DetailInscription): string {
  const e = d.eleve;
  const etab = d.etablissement;
  const montant = (v: number | null | undefined) => (v ?? 0).toLocaleString('fr-FR') + ' F';
  const date = (v: string | null | undefined) => (v ? v.slice(0, 10).split('-').reverse().join('/') : '—');
  const lien = { pere: 'Père', mere: 'Mère', tuteur_legal: 'Tuteur' } as Record<string, string>;
  const totalDettes = d.dettes.reduce((s, x) => s + x.reste, 0);

  const ligne = (libelle: string, valeur: string | null | undefined) =>
    `<div class="ligne"><span>${esc(libelle)}</span><strong>${esc(valeur || '—')}</strong></div>`;

  const frais = d.frais.length
    ? `<table>
        <thead><tr><th>Frais</th><th>Montant</th><th>Réduction</th><th>Payé</th><th>Reste</th></tr></thead>
        <tbody>
          ${d.frais.map((f) => `<tr><td>${esc(f.libelle)}</td><td>${montant(f.montant_du)}</td><td>${f.montant_reduit ? montant(f.montant_reduit) : '—'}</td><td>${montant(f.montant_paye)}</td><td>${montant(f.reste)}</td></tr>`).join('')}
          ${d.dettes.map((x) => `<tr class="dette"><td>Dette ${esc(x.annee)}</td><td>${montant(x.montant)}</td><td>—</td><td>${montant(x.montant - x.reste)}</td><td>${montant(x.reste)}</td></tr>`).join('')}
        </tbody>
        <tfoot><tr><td>Total</td><td>${montant(d.total_du + d.dettes.reduce((s, x) => s + x.montant, 0))}</td><td>${montant(d.total_reduit)}</td><td>${montant(d.total_paye)}</td><td>${montant(d.reste + totalDettes)}</td></tr></tfoot>
      </table>
      ${d.montant_minimum !== null ? `<p class="note">Minimum à verser pour valider l'inscription : <strong>${montant(d.montant_minimum)}</strong></p>` : ''}`
    : `<p class="note">${d.classe ? 'Aucun frais défini pour ce niveau.' : 'Les frais seront fixés au choix de la classe.'}</p>`;

  const fournitures = d.fournitures.length
    ? `<h2>Fournitures à apporter</h2><p>${d.fournitures.map((f) => `${esc(f.libelle)} : ${f.quantite_remise} / ${f.quantite_due}`).join(' &nbsp;·&nbsp; ')}</p>`
    : '';

  const parents = e.parents.length
    ? e.parents
        .map((p) => ligne(lien[p.lien_parente] + (p.is_contact_principal ? ' (contact)' : '') + (p.is_payeur ? ' (payeur)' : ''), [p.nom_complet, p.telephone, p.profession].filter(Boolean).join(' · ')))
        .join('')
    : '<p class="note">Aucun parent renseigné.</p>';

  return `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Fiche d'inscription ${esc(e.matricule)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1d2433; }
  .barre { position: sticky; top: 0; padding: 10px; background: #f1f4fa; text-align: center; }
  .barre button { padding: 8px 18px; border: none; border-radius: 8px; background: #1f4fd8; color: #fff; font-size: 14px; cursor: pointer; }
  .page { max-width: 190mm; margin: 0 auto; padding: 12px 0; }
  header { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 2px solid #1d2433; }
  header img { width: 64px; height: 64px; object-fit: contain; }
  header h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
  header p { margin: 2px 0 0; color: #5b6477; }
  .titre { margin: 14px 0; padding: 8px; background: #1d2433; color: #fff; text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 0.06em; }
  .identite { display: flex; gap: 16px; }
  .identite .photo { width: 30mm; height: 38mm; border: 1px solid #c9d0de; object-fit: cover; display: flex; align-items: center; justify-content: center; color: #9aa3b5; font-size: 10px; text-align: center; }
  .colonnes { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; }
  .ligne { display: flex; justify-content: space-between; gap: 10px; padding: 4px 0; border-bottom: 1px dotted #c9d0de; }
  .ligne span { color: #5b6477; }
  .ligne strong { text-align: right; }
  h2 { margin: 16px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #5b6477; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 5px 6px; border: 1px solid #c9d0de; text-align: right; }
  th:first-child, td:first-child { text-align: left; }
  th { background: #f1f4fa; font-size: 11px; }
  tfoot td { font-weight: bold; background: #f1f4fa; }
  tr.dette td { color: #a35200; }
  .note { margin: 6px 0; color: #5b6477; }
  .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 28px; }
  .signatures div { height: 26mm; padding: 6px; border: 1px solid #c9d0de; color: #5b6477; }
  footer { margin-top: 14px; font-size: 10px; color: #9aa3b5; text-align: center; }
  @media print { .barre { display: none; } }
</style></head>
<body>
  <div class="barre"><button onclick="window.print()">Imprimer / Enregistrer en PDF</button></div>
  <div class="page">
    <header>
      ${etab?.logo_url ? `<img src="${esc(etab.logo_url)}" alt="">` : ''}
      <div>
        <h1>${esc(etab?.nom ?? '')}</h1>
        ${etab?.slogan ? `<p>${esc(etab.slogan)}</p>` : ''}
        ${etab?.contacts ? `<p>${esc(etab.contacts)}</p>` : ''}
      </div>
    </header>

    <div class="titre">FICHE D'INSCRIPTION ${esc(d.annee ?? '')}</div>

    <div class="identite">
      ${e.photo_url ? `<img class="photo" src="${esc(e.photo_url)}" alt="">` : '<div class="photo">Photo</div>'}
      <div class="colonnes">
        ${ligne('Matricule', e.matricule)}
        ${ligne('Sexe', e.sexe === 'F' ? 'Féminin' : e.sexe === 'M' ? 'Masculin' : '—')}
        ${ligne('Nom', e.nom)}
        ${ligne('Prénoms', e.prenoms)}
        ${ligne('Né(e) le', date(e.date_naissance))}
        ${ligne('À', e.lieu_naissance)}
        ${ligne('Nationalité', e.nationalite)}
        ${ligne('Téléphone', e.telephone)}
        ${ligne('Quartier', e.quartier)}
        ${ligne('Orphelin(e)', [e.orphelin_pere ? 'de père' : '', e.orphelin_mere ? 'de mère' : ''].filter(Boolean).join(' et ') || 'Non')}
      </div>
    </div>

    <h2>Scolarité</h2>
    <div class="colonnes">
      ${ligne('Niveau', d.niveau?.libelle)}
      ${ligne('Classe', d.classe?.libelle ?? 'Non attribuée')}
      ${ligne('Statut', d.affecte ? 'Affecté(e)' : 'Non affecté(e)')}
      ${ligne('Redoublant(e)', d.redoublant ? 'Oui' : 'Non')}
      ${ligne('Boursier(ère)', d.boursier ? 'Oui' : 'Non')}
      ${ligne('LV2', d.langue_vivante_2)}
      ${d.precedente
        ? ligne(`Décision ${d.precedente.annee ?? ''}`, d.precedente.decision_finale ? LIBELLES_DECISION[d.precedente.decision_finale] ?? d.precedente.decision_finale : null) +
          ligne(`Classe ${d.precedente.annee ?? ''}`, d.precedente.classe)
        : ligne('Provenance', [d.etablissement_origine, d.classe_origine].filter(Boolean).join(' · ')) +
          ligne('Décision précédente', d.decision_origine ? LIBELLES_DECISION[d.decision_origine] ?? d.decision_origine : null)}
      ${ligne('Inscription en ligne', d.inscrit_en_ligne ? `Oui${d.inscription_en_ligne?.numero_recu ? ' (reçu ' + d.inscription_en_ligne.numero_recu + ')' : ''}` : 'Non')}
      ${ligne('État', LIBELLES_STATUT[d.statut])}
    </div>

    <h2>Parents et tuteur</h2>
    ${parents}

    <h2>Frais d'inscription</h2>
    ${frais}
    ${fournitures}

    <div class="signatures">
      <div>Signature du parent / tuteur</div>
      <div>Cachet et signature de l'établissement</div>
    </div>
    <footer>Inscrit(e) le ${date(d.date_inscription)}${d.enregistre_par ? ' par ' + esc(d.enregistre_par) : ''} · imprimé le ${new Date().toLocaleDateString('fr-FR')}</footer>
  </div>
</body></html>`;
}

function esc(valeur: string | null | undefined): string {
  return (valeur ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}
