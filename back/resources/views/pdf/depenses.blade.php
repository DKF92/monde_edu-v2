@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($modes = \App\Models\Depense::MODES)
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 10mm 12mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; text-transform: uppercase; padding: 4pt; border: 0.5pt solid #d9deea; text-align: left; }
  .liste td { font-size: 8pt; padding: 3.5pt 4pt; border: 0.5pt solid #e3e7f0; }
  .liste .d { text-align: right; white-space: nowrap; }
  .liste .total td { font-weight: bold; background: #d5dcef; font-size: 9pt; }
  .liste tr { page-break-inside: avoid; }
  .categories { width: 60%; border-collapse: collapse; margin-top: 5mm; }
  .categories th, .categories td { font-size: 8.5pt; padding: 4pt 6pt; border: 0.5pt solid #e3e7f0; }
  .categories th { background: #eef2fa; color: #1b2f5e; text-align: left; text-transform: uppercase; font-size: 7.5pt; }
  .categories td.d { text-align: right; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">{{ count($depenses) }} dépense{{ count($depenses) > 1 ? 's' : '' }} · {{ $m($total) }} F CFA · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <table class="liste">
    <thead>
      <tr>
        <th>Date</th><th>N°</th><th>Catégorie</th><th>Objet</th><th>Bénéficiaire</th><th>Pièce</th><th>Mode</th><th>Saisie par</th><th class="d">Montant</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($depenses as $d)
        <tr>
          <td>{{ \Carbon\Carbon::parse($d['date'])->format('d/m/Y') }}</td>
          <td>{{ $d['numero'] }}</td>
          <td>{{ $d['categorie_libelle'] }}</td>
          <td>{{ $d['libelle'] }}</td>
          <td>{{ $d['beneficiaire'] }}</td>
          <td>{{ $d['piece_comptable'] }}</td>
          <td>{{ $modes[$d['mode']] ?? $d['mode'] }}</td>
          <td>{{ $d['payeur'] }}</td>
          <td class="d">{{ $m($d['montant']) }}</td>
        </tr>
      @empty
        <tr><td colspan="9">Aucune dépense pour ces critères.</td></tr>
      @endforelse
      <tr class="total"><td colspan="8">Total</td><td class="d">{{ $m($total) }}</td></tr>
    </tbody>
  </table>

  @if (count($parCategorie))
    <table class="categories">
      <tr><th>Catégorie</th><th>Nombre</th><th class="d">Montant</th></tr>
      @foreach ($parCategorie as $c)
        <tr><td>{{ $c['libelle'] }}</td><td>{{ $c['nombre'] }}</td><td class="d">{{ $m($c['montant']) }}</td></tr>
      @endforeach
    </table>
  @endif
  <p class="pied-doc" style="font-size: 7.5pt; color: #56607a; margin-top: 3mm;">Montants en F CFA.</p>
</body>
</html>
