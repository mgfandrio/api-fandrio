<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Proxys de confiance : nginx (conteneur Docker) et, en multi-serveurs, le
     * répartiteur de charge. Sans eux, sous Octane, $request->ip() renvoie l'IP
     * de nginx pour tout le monde : tous les visiteurs non connectés partagent
     * alors le même quota de requêtes (limiteurs « api » et « disponibilite »).
     *
     * Par défaut : réseaux privés (Docker, réseau privé entre serveurs). Le client
     * retenu est la première adresse non fiable en partant de la droite de
     * X-Forwarded-For : une adresse ajoutée par le client lui-même est ignorée.
     * TRUSTED_PROXIES : liste séparée par des virgules (« * » déconseillé).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $liste = config('app.trusted_proxies', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');
        $this->proxies = array_values(array_filter(array_map('trim', explode(',', (string) $liste))));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
