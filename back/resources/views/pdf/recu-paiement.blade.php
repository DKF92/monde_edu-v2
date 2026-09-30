@php($m = fn ($v) => \App\Support\RecuPaiement::montant((int) $v))
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu {{ $r->numero_recu }}</title>
<style>
  @page { margin: 12mm 14mm; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { margin: 0; color: #1d2433; font-size: 9.5pt; }
  .exemplaire { height: 122mm; position: relative; }
  .coupe { border-top: 1px dashed #8a93a6; margin: 9mm 0 7mm; text-align: center; font-size: 7pt; color: #8a93a6; height: 0; }
  .coupe span { position: relative; top: -5pt; background: #fff; padding: 0 6pt; }
  table { border-collapse: collapse; width: 100%; }
  .entete td { vertical-align: top; }
  .entete .tutelle { width: 52%; text-align: center; font-size: 7pt; }
  .entete .tutelle .ministere { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
  .entete .tutelle .trait { font-size: 6pt; color: #8a93a6; line-height: 1; }
  .entete .tutelle .direction { font-weight: bold; }
  .entete .tutelle .ligne-tutelle { color: #56607a; }
  .entete .ecole { text-align: center; padding-left: 20mm; }
  .logo { width: 32pt; height: 32pt; }
  .nom-etab { font-size: 10pt; font-weight: bold; color: #1b2f5e; }
  .annee-doc { font-size: 7.5pt; color: #56607a; }
  .bande { margin-top: 2mm; border-top: 2pt solid #1b2f5e; }
  .titre-doc { margin-top: 2mm; font-size: 11pt; font-weight: bold; color: #1b2f5e; letter-spacing: 1pt; }
  .titre-doc .ex { float: right; font-size: 7pt; font-weight: normal; letter-spacing: 0; color: #8a93a6; text-transform: uppercase; }
  .infos { margin-top: 3mm; }
  .infos td { padding: 1.5pt 0; font-size: 9pt; }
  .infos .l { color: #56607a; width: 26mm; }
  .infos .v { font-weight: bold; }
  .detail { margin-top: 4mm; }
  .detail th { background: #eef2fa; color: #1b2f5e; font-size: 8pt; text-transform: uppercase; text-align: left; padding: 4pt 6pt; }
  .detail td { padding: 4pt 6pt; border-bottom: 0.5pt solid #d9deea; }
  .detail .a-droite { text-align: right; }
  .detail .total td { font-weight: bold; font-size: 10.5pt; border-bottom: none; border-top: 1pt solid #1b2f5e; }
  .situation { margin-top: 4mm; }
  .situation td { width: 25%; text-align: center; padding: 4pt; background: #f5f7fb; border: 2pt solid #fff; }
  .situation small { display: block; font-size: 7pt; color: #56607a; text-transform: uppercase; }
  .situation strong { font-size: 10pt; }
  .situation .reste strong { color: #b35c00; }
  .situation .solde strong { color: #16603a; }
  .pied { position: absolute; bottom: 0; left: 0; right: 0; }
  .pied td { font-size: 8pt; color: #56607a; vertical-align: bottom; }
  .signature { text-align: right; }
  .signature span { display: inline-block; border-top: 0.5pt solid #8a93a6; padding-top: 2pt; width: 55mm; text-align: center; }
</style>
</head>
<body>
@foreach (['Exemplaire parent', 'Exemplaire établissement'] as $i => $exemplaire)
  @if ($i > 0)
    <div class="coupe"><span>✂ découper ici</span></div>
  @endif
  <div class="exemplaire">
    @include('pdf._entete-caisse', ['titreDocument' => null, 'annee' => $r->anneeScolaire?->libelle])
    <div class="titre-doc">REÇU DE PAIEMENT N° {{ $r->numero_recu }} <span class="ex">{{ $exemplaire }}</span></div>

    <table class="infos">
      <tr>
        <td class="l">Élève</td><td class="v">{{ $r->eleve->nom }} {{ $r->eleve->prenoms }}</td>
        <td class="l">Date</td><td class="v">{{ $r->date_paiement->format('d/m/Y à H:i') }}</td>
      </tr>
      <tr>
        <td class="l">Matricule</td><td class="v">{{ $r->eleve->matricule }}</td>
        <td class="l">Versement</td><td class="v">{{ $versement }}</td>
      </tr>
      <tr>
        <td class="l">Classe</td><td class="v">{{ $r->inscription?->classe?->libelle ?? '—' }} · {{ $r->anneeScolaire?->libelle }}</td>
        <td class="l">Mode</td><td class="v">{{ $modes[$r->mode_paiement] ?? $r->mode_paiement }}</td>
      </tr>
      @if ($r->date_expiration)
        <tr><td class="l">Valable jusqu'au</td><td class="v" colspan="3">{{ $r->date_expiration->format('d/m/Y') }}</td></tr>
      @endif
    </table>

    <table class="detail">
      <tr><th>Désignation</th><th class="a-droite">Montant</th></tr>
      @foreach ($lignes as $ligne)
        <tr><td>{{ $ligne['libelle'] }}</td><td class="a-droite">{{ $m($ligne['montant']) }}</td></tr>
      @endforeach
      <tr class="total"><td>Total versé</td><td class="a-droite">{{ $m($r->montant_total) }}</td></tr>
    </table>

    @if ($situation)
      <table class="situation">
        <tr>
          <td><small>Total dû</small><strong>{{ $m($situation['du']) }}</strong></td>
          <td><small>Réduction</small><strong>{{ $situation['reduit'] ? $m($situation['reduit']) : '—' }}</strong></td>
          <td><small>Payé à ce jour</small><strong>{{ $m($situation['paye']) }}</strong></td>
          <td class="{{ $situation['reste'] > 0 ? 'reste' : 'solde' }}"><small>Reste à payer</small><strong>{{ $situation['reste'] > 0 ? $m($situation['reste']) : 'Soldé' }}</strong></td>
        </tr>
      </table>
    @endif

    <table class="pied">
      <tr>
        <td>Encaissé par {{ $r->caissier ? trim($r->caissier->name.' '.$r->caissier->prenoms) : '—' }}</td>
        <td class="signature"><span>Cachet et signature</span></td>
      </tr>
    </table>
  </div>
@endforeach
</body>
</html>
