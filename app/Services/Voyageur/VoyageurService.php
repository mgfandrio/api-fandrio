<?php

namespace App\Services\Voyageur;

use App\DTOs\VoyageurDTO;
use App\Models\Voyageur\Voyageur;
use App\Models\Reservation\ReservationVoyageur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class VoyageurService
{
    /**
     * Ajouter un nouveau voyageur
     */
    public function ajouterVoyageur(VoyageurDTO $voyageurDto): Voyageur
    {
        $donneesVoyageur = $voyageurDto->convertionDonneesEnTableau();
        return Voyageur::create($donneesVoyageur);
    }


    /**
     * Ajouter plusieurs voyageurs en une seule opération
     */
    public function ajouterPlusieursVoyageurs(array $voyageursData, int $utilId): Collection
    {
        $voyageursCreer = collect();

        DB::transaction(function () use ($voyageursData, $utilId, &$voyageursCreer) {
            foreach ($voyageursData as $voyageurData) {
                $voyageurData['util_id'] = $utilId;
                $voyageurDto = VoyageurDTO::creationObjet($voyageurData);
                $voyageur = Voyageur::create($voyageurDto->convertionDonneesEnTableau());
                $voyageursCreer->push($voyageur);
            }
        });

        return $voyageursCreer;
    }


    /**
     * Trouver un voyageur par son ID
     */
    public function trouverUnVoyageur(int $idVoyageur): ?Voyageur
    {
        return Voyageur::find($idVoyageur);
    }


    /**
     * Trouver un voyageur par son ID et vérifier qu'il appartient à l'utilisateur
     */
    public function trouverVoyageurParUtilisateur(int $idVoyageur, int $utilId): ?Voyageur
    {
        return Voyageur::where('voya_id', $idVoyageur)
            ->where('util_id', $utilId)
            ->first();
    }


    /**
     * Récupérer tous les voyageurs d'un utilisateur
     */
    public function recupererVoyageursParUtilisateur(int $utilId): Collection
    {
        return Voyageur::where('util_id', $utilId)
            ->orderBy('created_at', 'desc')
            ->get();
    }


    /**
     * Modifier un voyageur existant
     */
    public function modifierVoyageur(int $idVoyageur, VoyageurDTO $voyageurDto): ?Voyageur
    {
        $voyageur = $this->trouverUnVoyageur($idVoyageur);

        if (!$voyageur) {
            return null;
        }

        $donneesVoyageur = $voyageurDto->convertionDonneesEnTableau();
        
        // Retirer util_id des données à modifier (ne pas changer le propriétaire)
        unset($donneesVoyageur['util_id']);

        $voyageur->update($donneesVoyageur);
        return $voyageur->fresh();
    }


    /**
     * Supprimer un voyageur
     * 
     * Note: On vérifie d'abord s'il n'est pas lié à une réservation active
     */
    public function supprimerVoyageur(int $idVoyageur): array
    {
        $voyageur = $this->trouverUnVoyageur($idVoyageur);

        if (!$voyageur) {
            return [
                'succes' => false,
                'message' => 'Voyageur non trouvé'
            ];
        }

        // Vérifier si le voyageur est lié à une réservation active
        $reservationsActives = ReservationVoyageur::where('voya_id', $idVoyageur)
            ->where('res_voya_statut', 1) // 1 = confirmé
            ->exists();

        if ($reservationsActives) {
            return [
                'succes' => false,
                'message' => 'Ce voyageur est lié à une ou plusieurs réservations actives. Impossible de le supprimer.'
            ];
        }

        $voyageur->delete();
        
        return [
            'succes' => true,
            'message' => 'Voyageur supprimé avec succès'
        ];
    }


    /**
     * Supprimer plusieurs voyageurs en une seule opération
     */
    public function supprimerPlusieursVoyageurs(array $idsVoyageurs): array
    {
        $resultats = [
            'supprimes' => [],
            'echecs' => []
        ];

        DB::transaction(function () use ($idsVoyageurs, &$resultats) {
            foreach ($idsVoyageurs as $idVoyageur) {
                $resultat = $this->supprimerVoyageur($idVoyageur);
                
                if ($resultat['succes']) {
                    $resultats['supprimes'][] = $idVoyageur;
                } else {
                    $resultats['echecs'][] = [
                        'id' => $idVoyageur,
                        'raison' => $resultat['message']
                    ];
                }
            }
        });

        return $resultats;
    }


    /**
     * Vérifier si un CIN existe déjà
     */
    public function verifierCinExiste(string $cin, ?int $exclureId = null): bool
    {
        $query = Voyageur::where('voya_cin', $cin);
        
        if ($exclureId) {
            $query->where('voya_id', '!=', $exclureId);
        }

        return $query->exists();
    }


    /**
     * Récupérer les voyageurs liés à une réservation
     */
    public function recupererVoyageursParReservation(int $resId): Collection
    {
        return Voyageur::whereHas('reservationVoyageurs', function ($query) use ($resId) {
            $query->where('res_id', $resId);
        })->with(['reservationVoyageurs' => function ($query) use ($resId) {
            $query->where('res_id', $resId);
        }])->get();
    }


    /**
     * Associer des voyageurs à une réservation avec leurs numéros de place
     */
    public function associerVoyageursReservation(int $resId, array $voyageursPlaces): Collection
    {
        $associations = collect();

        DB::transaction(function () use ($resId, $voyageursPlaces, &$associations) {
            foreach ($voyageursPlaces as $voyageurPlace) {
                $association = ReservationVoyageur::create([
                    'res_id' => $resId,
                    'voya_id' => $voyageurPlace['voya_id'],
                    'place_numero' => $voyageurPlace['place_numero'],
                    'res_voya_statut' => 1 // Confirmé par défaut
                ]);
                $associations->push($association);
            }
        });

        return $associations;
    }
}
