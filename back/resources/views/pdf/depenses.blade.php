@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($modes = \App\Models\Depense::MODES)
@php($c = fn ($texte, $mm) => \App\Support\Noms::couper($texte, $mm, 7))
{{-- Bilan des depenses : liste (une ligne par depense, sans retour a la ligne), puis bilans par source,
     par categorie et par personne ayant saisi. A4 paysage, largeurs hors padding (2 pt de chaque cote). --}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 10mm 12mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 4pt 2pt; border: 0.5pt solid #d9deea; text-align: left; white-space: nowrap; }
  .liste td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .liste .d { text-align: right; }
  .liste .total td { font-weight: bold; background: #d5dcef; }
  .liste tr { page-break-inside: avoid; }
  .bilans { width: 100%; margin-top: 5mm; page-break-inside: avoid; }
  .bilans > tbody > tr > td { width: 33.3%; vertical-align: top; padding: 0 2mm 0 0; }
  .bilan { width: 100%; border-collapse: collapse; }
  .bilan th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #d9deea; text-align: left; white-space: nowrap; }
  .bilan td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .bilan .d { text-align: right; }
  .bilan .total td { font-weight: bold; background: #d5dcef; }
  .pied-doc { margin-top: 3mm; font-size: 7.5pt; color: #56607a; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">{{ count($depenses) }} dépense{{ count($depenses) > 1 ? 's' : '' }} · {{ $m($total) }} F CFA · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <table class="liste">
    <thead>
      <tr>
        <th style="width: 14mm">Date</th>
        <th style="width: 34mm">Source</th>
        <th style="width: 30mm">Catégorie</th>
        <th>Objet</th>
        <th style="width: 38mm">Bénéficiaire</th>
        <th style="width: 19mm">Mode</th>
        <th style="width: 32mm">Saisie par</th>
        <th class="d" style="width: 17mm">Montant</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($depenses as $d)
        <tr>
          <td>{{ \Carbon\Carbon::parse($d['date'])->format('d/m/Y') }}</td>
          <td>{{ $c($d['source'] === 'banque' ? 'Banque '.$d['banque'] : 'Caisse', 34) }}</td>
          <td>{{ $c($d['categorie_libelle'], 30) }}</td>
          <td>{{ $c($d['libelle'], 79) }}</td>
          <td>{{ $c($d['beneficiaire'], 38) }}</td>
          <td>{{ $c($modes[$d['mode']] ?? $d['mode'], 19) }}</td>
          <td>{{ $d['payeur'] ? \App\Support\Noms::prenoms($d['payeur'], 32, 7) : '—' }}</td>
          <td class="d">{{ $m($d['montant']) }}</td>
        </tr>
      @empty
        <tr><td colspan="8">Aucune dépense pour ces critères.</td></tr>
      @endforelse
      <tr class="total"><td colspan="7">Total</td><td class="d">{{ $m($total) }}</td></tr>
    </tbody>
  </table>

  @if (count($depenses))
    <table class="bilans">
      <tr>
        @foreach ($bilans as $titreBilan => $lignes)
          <td>
            <table class="bilan">
              <tr><th>{{ $titreBilan }}</th><th class="d" style="width: 11mm">Nombre</th><th class="d" style="width: 17mm">Montant</th></tr>
              @foreach ($lignes as $l)
                <tr><td>{{ $c($l['libelle'], 54) }}</td><td class="d">{{ $l['nombre'] }}</td><td class="d">{{ $m($l['montant']) }}</td></tr>
              @endforeach
              <tr class="total"><td>Total</td><td class="d">{{ count($depenses) }}</td><td class="d">{{ $m($total) }}</td></tr>
            </table>
          </td>
        @endforeach
      </tr>
    </table>
  @endif
  <p class="pied-doc">Montants en F CFA.</p>
</body>
</html>
