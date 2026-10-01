<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class CloudinaryService
{
    public function configured(): bool
    {
        return filled(config('cloudinary.cloud_name'))
            && filled(config('cloudinary.api_key'))
            && filled(config('cloudinary.api_secret'));
    }

    public function upload(UploadedFile $file, string $folder): array
    {
        if (! $this->configured()) {
            if (config('cloudinary.required')) {
                throw new RuntimeException('Cloudinary n\'est pas configuré.');
            }

            $path = $file->store($folder, 'public');

            return [
                'url' => asset('storage/'.$path),
                'public_id' => null,
            ];
        }

        $timestamp = time();
        $targetFolder = trim(config('cloudinary.folder').'/'.$folder, '/');
        $signature = sha1("folder={$targetFolder}&timestamp={$timestamp}".config('cloudinary.api_secret'));

        $ch = curl_init('https://api.cloudinary.com/v1_1/'.rawurlencode(config('cloudinary.cloud_name')).'/image/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName()),
                'api_key' => config('cloudinary.api_key'),
                'timestamp' => $timestamp,
                'folder' => $targetFolder,
                'signature' => $signature,
            ],
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Échec de l’upload Cloudinary'.($error ? ': '.$error : '.'));
        }

        $data = json_decode($body, true);
        if (! is_array($data) || empty($data['secure_url'])) {
            throw new RuntimeException('Réponse Cloudinary invalide.');
        }

        return [
            'url' => $data['secure_url'],
            'public_id' => $data['public_id'] ?? null,
        ];
    }

    public function delete(?string $publicId): void
    {
        if (! $publicId || ! $this->configured()) {
            return;
        }

        $timestamp = time();
        $signature = sha1("public_id={$publicId}&timestamp={$timestamp}".config('cloudinary.api_secret'));
        $ch = curl_init('https://api.cloudinary.com/v1_1/'.rawurlencode(config('cloudinary.cloud_name')).'/image/destroy');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => [
                'public_id' => $publicId,
                'api_key' => config('cloudinary.api_key'),
                'timestamp' => $timestamp,
                'signature' => $signature,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
