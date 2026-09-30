<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Bilan général · {{ $b['titre'] }}</title>
@include('pdf._style-caisse')
<style>
  h2 { font-size: 11.5pt; color: #1b2f5e; margin: 7mm 0 1mm; padding-bottom: 2pt; border-bottom: 1pt solid #d9deea; page-break-after: avoid; }
  h3 { font-size: 9.5pt; color: #1d2433; margin: 4mm 0 1.5mm; page-break-after: avoid; }
  .bloc { page-break-inside: avoid; }
  .bilan td, .bilan th { padding: 3.5pt 6pt; font-size: 8.5pt; }
  .bilan .ligne-total td { font-size: 9pt; }
  .sommaire { font-size: 8.5pt; color: #56607a; margin: 0 0 3mm; }
  .vide { font-size: 8.5pt; color: #8a93a6; font-style: italic; }
  .saut { page-break-before: always; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')

  <h1>Bilan général · {{ $b['titre'] }}</h1>
  <div class="sous-titre">
    Encaissements de {{ $b['caissier']['nom'] }} · édité le {{ \Carbon\Carbon::parse($b['genere_le'])->format('d/m/Y à H:i') }}
  </div>
  <p class="sommaire">
    @foreach ($b['sections'] as $s){{ $s['numero'] }}. {{ $s['titre'] }}@if (! $loop->last) · @endif @endforeach
  </p>

  @foreach ($b['sections'] as $s)
    {{-- Sections par niveau et par classe : nouvelle page (nombreux tableaux). --}}
    <h2 class="{{ in_array($s['numero'], ['5', '6'], true) ? 'saut' : '' }}">{{ $s['numero'] }}. {{ $s['titre'] }}</h2>
    @forelse ($s['sous'] as $sous)
      <div class="bloc">
        @if ($s['numero'] !== '1')
          <h3>{{ $sous['numero'] }}. {{ $sous['titre'] }}</h3>
        @endif
        @include('pdf._tableau-bilan', ['bilan' => $sous['bilan']])
      </div>
    @empty
      <p class="vide">Aucun élément pour ces critères.</p>
    @endforelse
  @endforeach

  <table class="pied">
    <tr>
      <td class="signature" style="text-align: left"><span>Le caissier · {{ $b['caissier']['nom'] }}</span></td>
      <td class="signature"><span>Visa du responsable</span></td>
    </tr>
  </table>
</body>
</html>
