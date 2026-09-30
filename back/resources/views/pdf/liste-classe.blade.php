{{-- Liste(s) de classe : une classe par page, en-tete officiel repete, effectifs au-dessus du tableau. --}}
@php $statuts = [1 => 'Attente 1er vers.', 2 => 'Attente solde', 3 => 'Soldé']; @endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ count($listes) > 1 ? 'Listes des classes '.$annee : 'Liste de classe '.$listes[0]['classe']['libelle'] }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 12mm 16mm; }
  .page-classe + .page-classe { page-break-before: always; }
  .effectifs { margin-top: 2mm; }
  .effectifs td { width: 16.66%; text-align: center; padding: 4pt 2pt; background: #f5f7fb; border: 2pt solid #fff; }
  .effectifs small { display: block; font-size: 6.8pt; color: #56607a; text-transform: uppercase; }
  .effectifs strong { font-size: 11pt; color: #1b2f5e; }
  .encadrement { margin-top: 1mm; }
  .encadrement td { font-size: 8.5pt; color: #56607a; padding: 2pt 4pt; }
  .encadrement strong { color: #1d2433; }
  .liste { width: 100%; border-collapse: collapse; margin-top: 3mm; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7.2pt; text-transform: uppercase; padding: 4pt 3pt; text-align: center; border: 0.5pt solid #d9deea; }
  .liste th.g, .liste td.g { text-align: left; }
  .liste td { font-size: 8.5pt; padding: 3.5pt 3pt; border: 0.5pt solid #e3e7f0; text-align: center; }
  .liste tr { page-break-inside: avoid; }
  .liste tr:nth-child(even) td { background: #fafbfd; }
  .liste .nw { white-space: nowrap; }
</style>
</head>
<body>
@foreach ($listes as $bloc)
  @php
    $classe = $bloc['classe'];
    $liste = collect($bloc['eleves']);
  @endphp
  <div class="page-classe">
    @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DE CLASSE'])

    <h1>{{ $classe['libelle'] }}{{ $bloc['filtre'] ? ' · '.$bloc['filtre'] : '' }}</h1>
    <div class="sous-titre">Édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

    <table class="effectifs">
      <tr>
        <td><small>Effectif total</small><strong>{{ $liste->count() }}</strong></td>
        <td><small>Filles</small><strong>{{ $liste->where('sexe', 'F')->count() }}</strong></td>
        <td><small>Garçons</small><strong>{{ $liste->where('sexe', 'M')->count() }}</strong></td>
        <td><small>Affectés</small><strong>{{ $liste->where('affecte', true)->count() }}</strong></td>
        <td><small>Non affectés</small><strong>{{ $liste->where('affecte', false)->count() }}</strong></td>
        <td><small>Redoublants</small><strong>{{ $liste->where('redoublant', true)->count() }}</strong></td>
      </tr>
    </table>
    <table class="encadrement">
      <tr>
        <td>Professeur principal : <strong>{{ $classe['professeur_principal']['nom'] ?? '—' }}</strong></td>
        <td>Éducateur : <strong>{{ $classe['educateur']['nom'] ?? '—' }}</strong></td>
        @if (!empty($classe['salle']))<td>Salle : <strong>{{ $classe['salle'] }}</strong></td>@endif
      </tr>
    </table>

    <table class="liste">
      <thead>
        <tr>
          <th>N°</th>
          <th class="g">Matricule</th>
          <th class="g">Nom</th>
          <th class="g">Prénoms</th>
          <th>Sexe</th>
          <th>Né(e) le</th>
          <th class="g">Lieu de naissance</th>
          <th>Statut</th>
          <th>Red.</th>
          <th>LV2</th>
          <th>Paiement</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($liste as $e)
          <tr>
            <td>{{ $loop->iteration }}</td>
            <td class="g">{{ $e['matricule'] }}</td>
            <td class="g"><strong>{{ $e['nom'] }}</strong></td>
            <td class="g">{{ $e['prenoms'] }}</td>
            <td>{{ $e['sexe'] === 'F' ? 'F' : 'G' }}</td>
            <td>{{ $e['date_naissance'] ? \Illuminate\Support\Carbon::parse($e['date_naissance'])->format('d/m/Y') : '' }}</td>
            <td class="g">{{ $e['lieu_naissance'] }}</td>
            <td class="nw">{{ $e['affecte'] ? 'Affecté' : 'Non aff.' }}</td>
            <td>{{ $e['redoublant'] ? 'Oui' : '' }}</td>
            <td>{{ $e['langue_vivante_2'] }}</td>
            <td class="nw">{{ $statuts[$e['statut']] ?? '' }}</td>
          </tr>
        @empty
          <tr><td colspan="11">Aucun élève.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
@endforeach
</body>
</html>
