@php
  $n = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
  $h = fn ($v) => fmod((float) $v, 1) ? number_format((float) $v, 1, ',', ' ') : number_format((float) $v, 0, ',', ' ');
  $rang = fn ($r, $ex = false) => $r ? $r.($r === 1 ? 'er' : 'e').($ex ? ' ex æquo' : '') : '—';
  $groupes = ['LITTERAIRE' => 'Matières littéraires', 'SCIENTIFIQUE' => 'Matières scientifiques', 'AUTRES' => 'Autres matières'];
  $parGroupe = collect($matieres)->groupBy('groupe');
  $s = $statistiques;
  $decisions = ['ADMIS' => 'Admis(e) en classe supérieure', 'REDOUBLE' => 'Redouble', 'EXCLU' => 'Exclu(e)'];
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Bulletins {{ $classe->libelle }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 11mm 10mm; }
  .bulletin { page-break-after: always; }
  .bulletin:last-child { page-break-after: auto; }
  .eleve { margin-top: 3mm; border: 0.8pt solid #1b2f5e; border-radius: 3pt; }
  .eleve td { font-size: 8.4pt; padding: 2.5pt 5pt; color: #56607a; }
  .eleve strong { color: #1d2433; }
  .eleve .nom { font-size: 11pt; color: #1b2f5e; font-weight: bold; }
  .notes { width: 100%; border-collapse: collapse; margin-top: 3mm; }
  .notes th { background: #1b2f5e; color: #fff; font-size: 7.4pt; text-transform: uppercase; padding: 4pt 3pt; text-align: center; }
  .notes th.g, .notes td.g { text-align: left; }
  .notes td { font-size: 8.4pt; padding: 3pt; border-bottom: 0.5pt solid #e3e7f0; text-align: center; }
  .notes .groupe td { background: #eef2fa; color: #1b2f5e; font-size: 7.4pt; font-weight: bold; text-transform: uppercase; }
  .notes .bilan td { background: #f7f9fd; font-weight: bold; }
  .notes .total td { background: #d5dcef; font-weight: bold; font-size: 9pt; }
  .faible { color: #b3261e; }
  .bas { width: 100%; border-collapse: collapse; margin-top: 3mm; }
  .bas > tbody > tr > td { width: 33.3%; vertical-align: top; border: 0.8pt solid #1b2f5e; padding: 4pt 6pt; }
  .bas h3 { margin: 0 0 3pt; font-size: 7.6pt; text-transform: uppercase; color: #1b2f5e; letter-spacing: 0.5pt; }
  .bas p { margin: 1.5pt 0; font-size: 8.4pt; }
  .grande { font-size: 16pt; font-weight: bold; color: #1b2f5e; }
  .mention { font-weight: bold; color: #16603a; }
  .sanction { font-weight: bold; color: #b3261e; }
  .signatures { width: 100%; margin-top: 4mm; }
  .signatures td { width: 50%; font-size: 8.4pt; color: #56607a; text-align: center; padding-top: 2mm; height: 18mm; vertical-align: top; }
</style>
</head>
<body>
@foreach ($eleves as $e)
  @php($abs = $absences[$e['eleve_id']] ?? null)
  <div class="bulletin">
    @include('pdf._entete-caisse', ['titreDocument' => $annuel ? 'BULLETIN ANNUEL' : 'BULLETIN DE NOTES'])

    <table class="eleve">
      <tr>
        <td colspan="2"><span class="nom">{{ $e['nom'] }} {{ $e['prenoms'] }}</span></td>
        <td>Classe : <strong>{{ $classe->libelle }}</strong></td>
        <td>Période : <strong>{{ $periode_libelle }}</strong></td>
      </tr>
      <tr>
        <td>Matricule : <strong>{{ $e['matricule'] }}</strong></td>
        <td>Sexe : <strong>{{ $e['sexe'] === 'F' ? 'Féminin' : 'Masculin' }}</strong></td>
        <td>Né(e) le : <strong>{{ $e['date_naissance'] ? \Illuminate\Support\Carbon::parse($e['date_naissance'])->format('d/m/Y') : '—' }}</strong>{{ $e['lieu_naissance'] ? ' à '.$e['lieu_naissance'] : '' }}</td>
        <td>Statut : <strong>{{ $e['affecte'] ? 'Affecté(e)' : 'Non affecté(e)' }}</strong> · Redoublant : <strong>{{ $e['redoublant'] ? 'Oui' : 'Non' }}</strong></td>
      </tr>
    </table>

    <table class="notes">
      <tr>
        <th class="g">Matière</th>
        <th>Moy. /20</th><th>Coef</th><th>Moy. × coef</th><th>Rang</th><th class="g">Appréciation</th><th class="g">Professeur</th>
      </tr>
      @foreach (['LITTERAIRE', 'SCIENTIFIQUE', 'AUTRES'] as $groupe)
        @if ($parGroupe->has($groupe))
          <tr class="groupe"><td colspan="7" class="g">{{ $groupes[$groupe] }}</td></tr>
          @foreach ($parGroupe[$groupe] as $m)
            @php($moy = $e['moyennes'][$m['id']] ?? null)
            @continue(! $moy && $m['langue'])
            <tr>
              <td class="g">{{ $m['libelle'] }}</td>
              <td class="{{ $moy && $moy['moyenne'] < 10 ? 'faible' : '' }}"><strong>{{ $n($moy['moyenne'] ?? null) }}</strong></td>
              <td>{{ $m['coefficient'] }}</td>
              <td>{{ $moy ? $n($moy['moyenne'] * $m['coefficient']) : '—' }}</td>
              <td>{{ $moy ? $rang($moy['rang'] ?? null) : '—' }}</td>
              <td class="g">{{ $moy['appreciation'] ?? '' }}</td>
              <td class="g">{{ $professeurs[$m['id']] ?? '' }}</td>
            </tr>
          @endforeach
          @if ($groupe !== 'AUTRES')
            <tr class="bilan">
              <td class="g">Bilan {{ $groupe === 'LITTERAIRE' ? 'lettres' : 'sciences' }}</td>
              <td>{{ $n($groupe === 'LITTERAIRE' ? $e['lettres'] : $e['sciences']) }}</td><td colspan="5"></td>
            </tr>
          @endif
        @endif
      @endforeach
      <tr class="total">
        <td class="g">Total</td>
        <td></td><td>{{ $e['total_coefficients'] }}</td><td>{{ $n($e['total_points']) }}</td><td colspan="3"></td>
      </tr>
    </table>

    <table class="bas">
      <tr>
        <td>
          <h3>{{ $annuel ? 'Moyenne annuelle' : 'Moyenne de la période' }}</h3>
          <p class="grande">{{ $n($e['moyenne']) }} <small>/ 20</small></p>
          <p>Rang : <strong>{{ $rang($e['rang'], $e['rang_ex_aequo'] ?? false) }}</strong> sur <strong>{{ $s['classes'] }}</strong></p>
          @if ($annuel)
            @foreach ($periodes as $p)<p>{{ $p['libelle'] }} : <strong>{{ $n($e['periodes'][$p['id']] ?? null) }}</strong></p>@endforeach
          @endif
          <h3 style="margin-top: 5pt">Résultats de la classe</h3>
          <p>Moyenne : <strong>{{ $n($s['moyenne_classe']) }}</strong> · maxi : <strong>{{ $n($s['maximum']) }}</strong> · mini : <strong>{{ $n($s['minimum']) }}</strong></p>
        </td>
        <td>
          <h3>Distinctions</h3>
          <p class="mention">{{ $e['distinction'] ?? '—' }}</p>
          <h3 style="margin-top: 5pt">Sanctions</h3>
          @forelse ($e['sanctions'] as $sanction)<p class="sanction">{{ $sanction }}</p>@empty<p>—</p>@endforelse
          <h3 style="margin-top: 5pt">Absences</h3>
          <p>Justifiées : <strong>{{ $h($abs->justifiees ?? 0) }} h</strong> · non justifiées : <strong>{{ $h($abs->non_justifiees ?? 0) }} h</strong></p>
          <p>Conduite : <strong>{{ $e['conduite'] !== null ? $n($e['conduite']).' / 20' : '—' }}</strong></p>
        </td>
        <td>
          <h3>Appréciation du conseil de classe</h3>
          <p><strong>{{ $e['appreciation'] ?? '—' }}</strong></p>
          @if ($annuel)
            <h3 style="margin-top: 5pt">Décision de fin d'année</h3>
            <p class="{{ $e['decision'] === 'ADMIS' ? 'mention' : 'sanction' }}">{{ $decisions[$e['decision']] ?? 'Non encore prise' }}</p>
          @endif
        </td>
      </tr>
    </table>

    <table class="signatures">
      <tr>
        <td>Le professeur principal<br><strong>{{ $classe->professeurPrincipal?->user ? trim($classe->professeurPrincipal->user->name.' '.$classe->professeurPrincipal->user->prenoms) : '' }}</strong></td>
        <td>Le chef d'établissement</td>
      </tr>
    </table>
  </div>
@endforeach
</body>
</html>
