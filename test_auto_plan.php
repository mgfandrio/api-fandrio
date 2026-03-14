<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Voiture\VoitureService;
use App\DTOs\VoitureDTO;
use App\Models\Voitures\PlanSiege;
use App\Models\Compagnies\Compagnie;
use App\Models\Chauffeurs\Chauffeurs;

try {
    $svc = app(VoitureService::class);
    
    $compId = Compagnie::first()->comp_id;
    $chauffId = Chauffeurs::first()->chauff_id;

    if (!$compId || !$chauffId) {
        throw new \Exception("Impossible de trouver une compagnie ou un chauffeur en base.");
    }

    // Test Ajout
    echo "Test Ajout Voiture (Compagnie: $compId, Chauffeur: $chauffId)...\n";
    $dto = new VoitureDTO('AUTO' . time(), 'Marque', 'Modele', 12, 1, $compId, $chauffId);
    $voiture = $svc->ajouterVoiture($dto);
    
    $plan = PlanSiege::where('voit_id', $voiture->voit_id)->first();
    if ($plan) {
        echo "Succès: Plan généré automatiquement pour Voiture ID " . $voiture->voit_id . "\n";
        echo "Config: " . json_encode($plan->config_sieges) . "\n";
    } else {
        echo "Échec: Aucun plan généré\n";
    }

    // Test Modification (changement de places)
    echo "\nTest Modification (changement places 12 -> 8)...\n";
    $dtoMod = new VoitureDTO($voiture->voit_matricule, 'Marque Mod', 'Modele Mod', 8, 1, $compId, $chauffId);
    $voitureMod = $svc->modifierVoiture($voiture->voit_id, $dtoMod);
    
    $planMod = PlanSiege::where('voit_id', $voiture->voit_id)->first();
    echo "Config après modification: " . json_encode($planMod->config_sieges) . "\n";
    if (count($planMod->config_sieges['sieges']) === 8) {
        echo "Succès: Plan mis à jour vers 8 sièges\n";
    } else {
        echo "Échec: Le plan n'a pas été mis à jour correctement\n";
    }

} catch (\Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
