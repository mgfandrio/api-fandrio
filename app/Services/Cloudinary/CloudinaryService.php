<?php

namespace App\Services\Cloudinary;

use Cloudinary\Cloudinary;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;

class CloudinaryService
{
    private Cloudinary $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => config('cloudinary.cloud_name'),
                'api_key'    => config('cloudinary.api_key'),
                'api_secret' => config('cloudinary.api_secret'),
            ],
            'url' => [
                'secure' => true,
            ],
        ]);
    }

    /**
     * Upload un logo de compagnie vers Cloudinary
     *
     * @param string $filePath Chemin temporaire du fichier uploadé
     * @param int $compagnieId ID de la compagnie (pour nommer le fichier)
     * @return array ['url' => string, 'public_id' => string]
     */
    public function uploadLogo(string $filePath, int $compagnieId): array
    {
        $folder = config('cloudinary.folder', 'fandrio/logos');
        $publicId = $folder . '/compagnie_' . $compagnieId;

        $result = $this->cloudinary->uploadApi()->upload($filePath, [
            'public_id'      => $publicId,
            'overwrite'      => true,
            'resource_type'  => 'image',
            'transformation' => [
                'width'   => config('cloudinary.logo_transformation.width', 400),
                'height'  => config('cloudinary.logo_transformation.height', 400),
                'crop'    => config('cloudinary.logo_transformation.crop', 'fill'),
                'quality' => config('cloudinary.logo_transformation.quality', 'auto'),
                'fetch_format' => config('cloudinary.logo_transformation.fetch_format', 'auto'),
            ],
        ]);

        return [
            'url'       => $result['secure_url'],
            'public_id' => $result['public_id'],
        ];
    }

    /**
     * Supprime un logo de Cloudinary
     *
     * @param string $publicId L'identifiant public Cloudinary
     * @return bool
     */
    public function deleteLogo(string $publicId): bool
    {
        try {
            $result = $this->cloudinary->uploadApi()->destroy($publicId);
            return ($result['result'] ?? '') === 'ok';
        } catch (\Exception $e) {
            \Log::warning('Cloudinary delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Génère une URL optimisée pour un logo
     *
     * @param string $publicId
     * @param int $width
     * @param int $height
     * @return string
     */
    public function getOptimizedUrl(string $publicId, int $width = 200, int $height = 200): string
    {
        return $this->cloudinary->image($publicId)
            ->resize("c_fill,w_{$width},h_{$height}")
            ->delivery('q_auto,f_auto')
            ->toUrl();
    }
}
