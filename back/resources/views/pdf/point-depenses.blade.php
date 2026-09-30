@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $p['titre'] }}</title>
@include('pdf._style-caisse')
<style>
  .resume td { width: 33.3%; text-align: center; padding: 8pt; background: #f5f7fb; border: 3pt solid #fff; }
  .resume small { display: block; font-size: 7.5pt; color: #56607a; text-transform: uppercase; }
  .resume strong { font-size: 13pt; }
  .resume .negatif strong { color: #b3261e; }
  .resume .positif strong { color: #16603a; }
  h2 { font-size: 11pt; color: #1b2f5e; margin: 4mm 0 2mm; }
  .tab { width: 100%; border-collapse: collapse; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: 7.5pt; text-transform: uppercase; padding: 4pt 5pt; border: 0.5pt solid #d9deea; text-align: right; }
  .tab th:first-child, .tab td:first-child { text-align: left; }
  .tab td { font-size: 8.5pt; padding: 3pt 5pt; border: 0.5pt solid #e3e7f0; text-align: right; }
  .tab .total td { font-weight: bold; background: #d5dcef; }
  .negatif { color: #b3261e; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')
  <h1>{{ $p['titre'] }}</h1>
  <div class="sous-titre">Édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <table class="resume">
    <tr>
      <td><small>Encaissements</small><strong>{{ $m($p['encaissements']) }} F</strong></td>
      <td><small>Dépenses</small><strong>{{ $m($p['depenses']) }} F</strong></td>
      <td class="{{ $p['solde'] < 0 ? 'negatif' : 'positif' }}"><small>Solde de caisse</small><strong>{{ $m($p['solde']) }} F</strong></td>
    </tr>
  </table>

  <h2>Par mois</h2>
  <table class="tab">
    <tr><th>Mois</th><th>Encaissements</th><th>Salaires</th><th>Autres dépenses</th><th>Total dépenses</th><th>Solde</th></tr>
    @foreach ($p['par_mois'] as $l)
      <tr>
        <td>{{ $l['mois'] }}</td><td>{{ $m($l['encaissements']) }}</td><td>{{ $m($l['salaires']) }}</td><td>{{ $m($l['autres']) }}</td><td>{{ $m($l['depenses']) }}</td>
        <td class="{{ $l['solde'] < 0 ? 'negatif' : '' }}">{{ $m($l['solde']) }}</td>
      </tr>
    @endforeach
    <tr class="total">
      <td>Total</td>
      <td>{{ $m($p['encaissements']) }}</td>
      <td>{{ $m(collect($p['par_mois'])->sum('salaires')) }}</td>
      <td>{{ $m(collect($p['par_mois'])->sum('autres')) }}</td>
      <td>{{ $m($p['depenses']) }}</td>
      <td class="{{ $p['solde'] < 0 ? 'negatif' : '' }}">{{ $m($p['solde']) }}</td>
    </tr>
  </table>

  <h2>Dépenses par catégorie</h2>
  <table class="tab" style="width: 65%">
    <tr><th>Catégorie</th><th>Montant</th></tr>
    @foreach ($p['par_categorie'] as $c)
      <tr><td>{{ $c['libelle'] }}</td><td>{{ $m($c['montant']) }}</td></tr>
    @endforeach
    <tr class="total"><td>Total</td><td>{{ $m($p['depenses']) }}</td></tr>
  </table>
  <p style="font-size: 7.5pt; color: #56607a; margin-top: 3mm;">Montants en F CFA. Encaissements : tous les paiements des élèves de la période.</p>
</body>
</html>
