{{-- Rapport officiel (rentree, periode, annuel) organise comme un memoire. Donnees : RapportCompileController::construire(). --}}
@php
  $romains = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
  $numeroObservations = count($r['chapitres']);
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $r['titre'] }} {{ $annee }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 14mm 13mm 18mm; }
  .pied-page { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7.5pt; color: #8a93a6; border-top: 0.5pt solid #d9deea; padding-top: 2mm; }
  .pied-page .num:after { content: counter(page); }
  .saut { page-break-before: always; }

  .garde { text-align: center; }
  .garde .espace { height: 40mm; }
  .garde .cadre { border: 2pt solid #1b2f5e; padding: 12mm 8mm; margin: 0 10mm; }
  .garde .type { font-size: 22pt; font-weight: bold; color: #1b2f5e; letter-spacing: 1pt; text-transform: uppercase; }
  .garde .periode { font-size: 14pt; color: #1b2f5e; margin-top: 3mm; }
  .garde .annee { font-size: 13pt; margin-top: 6mm; }
  .garde .etab { font-size: 12pt; font-weight: bold; margin-top: 30mm; }
  .garde .date { font-size: 9.5pt; color: #56607a; margin-top: 3mm; }

  h2.chapitre { font-size: 14pt; color: #1b2f5e; margin: 0 0 4mm; padding-bottom: 2mm; border-bottom: 1.5pt solid #1b2f5e; text-transform: uppercase; }
  h3.section { font-size: 11pt; color: #1b2f5e; margin: 6mm 0 2mm; }
  p.texte { font-size: 9.5pt; line-height: 1.5; text-align: justify; margin: 0 0 3mm; }
  .sommaire td { padding: 2.5pt 0; font-size: 10pt; }
  .sommaire .ch { font-weight: bold; color: #1b2f5e; padding-top: 5pt; }
  .sommaire .se { padding-left: 8mm; color: #3a4257; font-size: 9pt; }
  .identite td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e3e7f0; font-size: 9.5pt; }
  .identite td.l { width: 45mm; color: #56607a; }
  .identite td.v { font-weight: bold; }
  .lignes-vides div { border-bottom: 0.5pt dotted #8a93a6; height: 8mm; }
  .signature-bloc { margin-top: 14mm; text-align: right; font-size: 10pt; }
  .signature-bloc .lieu { margin-bottom: 18mm; }

  .tab { width: 100%; border-collapse: collapse; margin-top: 1mm; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; text-transform: uppercase; padding: 3.5pt 3pt; border: 0.5pt solid #d9deea; }
  .tab td { font-size: 8pt; padding: 2.5pt 3pt; border: 0.5pt solid #e3e7f0; }
  .tab.serre th { font-size: 5.8pt; padding: 3pt 1.5pt; }
  .tab.serre td { font-size: 6.8pt; padding: 2pt 1.5pt; }
  .tab .g { text-align: left; } .tab .c { text-align: center; } .tab .d { text-align: right; }
  .tab .fort { font-weight: bold; }
  .tab .groupe td { background: #1b2f5e; color: #fff; font-weight: bold; font-size: 7.5pt; }
  .tab .sous-total td { background: #f2f5fb; font-weight: bold; }
  .tab .total td { background: #d5dcef; font-weight: bold; }
  .tab tr { page-break-inside: avoid; }
</style>
</head>
<body>
  <div class="pied-page">
    <table><tr>
      <td>{{ $etablissement->nom }} · {{ $r['titre'] }}{{ $r['type'] === 'periode' ? '' : ' '.$annee }}</td>
      <td style="text-align: right">Page <span class="num"></span></td>
    </tr></table>
  </div>

  {{-- ================= Page de garde ================= --}}
  <div class="garde">
    @include('pdf._entete-caisse', ['titreDocument' => null])
    <div class="espace"></div>
    <div class="cadre">
      <div class="type">{{ $r['titre'] }}</div>
      @if ($r['type'] === 'periode')<div class="periode">Année scolaire {{ $annee }}</div>@endif
      @if ($r['type'] !== 'periode')<div class="annee">Année scolaire {{ $annee }}</div>@endif
    </div>
    <div class="etab">{{ $etablissement->nom }}</div>
    <div class="date">Édité le {{ $genere_le->format('d/m/Y') }} par {{ $edite_par }}</div>
  </div>

  {{-- ================= Sommaire ================= --}}
  <div class="saut"></div>
  <h2 class="chapitre">Sommaire</h2>
  <table class="sommaire">
    <tr><td class="ch">Introduction</td></tr>
    @foreach ($r['chapitres'] as $i => $ch)
      <tr><td class="ch">{{ $romains[$i] }}. {{ $ch['titre'] }}</td></tr>
      @foreach ($ch['sections'] as $j => $s)
        <tr><td class="se">{{ $j + 1 }}. {{ $s['titre'] }}</td></tr>
      @endforeach
    @endforeach
    <tr><td class="ch">{{ $romains[$numeroObservations] }}. Observations, difficultés et perspectives</td></tr>
    <tr><td class="ch">Conclusion</td></tr>
  </table>

  {{-- ================= Introduction ================= --}}
  <div class="saut"></div>
  <h2 class="chapitre">Introduction</h2>
  @foreach (preg_split("/\n\s*\n/", $r['introduction']) as $paragraphe)
    <p class="texte">{!! nl2br(e($paragraphe)) !!}</p>
  @endforeach

  {{-- ================= Chapitres ================= --}}
  @foreach ($r['chapitres'] as $i => $ch)
    <div class="saut"></div>
    <h2 class="chapitre">{{ $romains[$i] }}. {{ $ch['titre'] }}</h2>
    @foreach ($ch['sections'] as $j => $s)
      <h3 class="section">{{ $j + 1 }}. {{ $s['titre'] }}</h3>
      @if (!empty($s['texte']))<p class="texte">{{ $s['texte'] }}</p>@endif
      @if (!empty($s['identite']))
        <table class="identite">
          @foreach ($s['identite'] as $libelle => $valeur)
            <tr><td class="l">{{ $libelle }}</td><td class="v">{{ $valeur }}</td></tr>
          @endforeach
        </table>
      @endif
      @if (!empty($s['tableau']))
        @include('pdf._tableau-rapport', ['rapport' => $s['tableau']])
      @endif
    @endforeach
  @endforeach

  {{-- ================= Observations ================= --}}
  <div class="saut"></div>
  <h2 class="chapitre">{{ $romains[$numeroObservations] }}. Observations, difficultés et perspectives</h2>
  @forelse ($r['observations'] as $titre => $texte)
    <h3 class="section">{{ $titre }}</h3>
    @foreach (preg_split("/\n\s*\n/", $texte) as $paragraphe)
      <p class="texte">{!! nl2br(e($paragraphe)) !!}</p>
    @endforeach
  @empty
    @foreach (['Observations', 'Difficultés rencontrées', 'Perspectives et suggestions'] as $titre)
      <h3 class="section">{{ $titre }}</h3>
      <div class="lignes-vides">@for ($k = 0; $k < 5; $k++)<div></div>@endfor</div>
    @endforeach
  @endforelse

  {{-- ================= Conclusion ================= --}}
  <h2 class="chapitre" style="margin-top: 10mm">Conclusion</h2>
  @if ($r['conclusion'])
    @foreach (preg_split("/\n\s*\n/", $r['conclusion']) as $paragraphe)
      <p class="texte">{!! nl2br(e($paragraphe)) !!}</p>
    @endforeach
  @else
    <div class="lignes-vides">@for ($k = 0; $k < 5; $k++)<div></div>@endfor</div>
  @endif

  <div class="signature-bloc">
    <div class="lieu">Fait à {{ $etablissement->ville ?: '……………………' }}, le {{ $genere_le->format('d/m/Y') }}</div>
    <strong>{{ $r['signataire'] }}</strong>
  </div>
</body>
</html>
