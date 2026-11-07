<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Message d'erreur affichable au client.
     *
     * - Messages métier (`throw new \Exception('Ce siège n'est pas disponible')`,
     *   validation, abort()) : renvoyés tels quels, l'app les affiche.
     * - Erreurs techniques (SQL, PHP, réseau...) : message générique au client,
     *   détail complet dans les logs. Évite d'exposer requêtes SQL, chemins de
     *   fichiers ou noms de classes, même avec APP_DEBUG=false.
     */
    protected function messageErreur(Throwable $e): string
    {
        if (in_array(get_class($e), [\Exception::class, \RuntimeException::class], true)
            || $e instanceof ValidationException
            || $e instanceof HttpExceptionInterface) {
            return $e->getMessage();
        }

        if ($e instanceof ModelNotFoundException) {
            return 'Ressource introuvable.';
        }

        Log::error($e->getMessage(), ['exception' => $e]);

        return 'Une erreur interne est survenue. Veuillez réessayer.';
    }
}
