<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Entity\User;
use MonkeysLegion\Files\Upload\UploadManager;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Http\Message\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class MediaController
{
    public function __construct(
        private UploadManager     $uploads,
        private RepositoryFactory $repos
    ) {}

    /**
     * Upload an image file and persist a Media entity.
     *
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/media/upload')]
    public function upload(ServerRequestInterface $request): JsonResponse
    {
        // 1) Handle the multipart/form-data upload (field name "file")
        try {
            $meta = $this->uploads->handle($request, 'file');
        } catch (\Throwable $e) {
            throw new RuntimeException('Upload failed: ' . $e->getMessage(), 400);
        }

        // 2) Persist Media entity
        $media = new Media();
        // use the publicly accessible URL if available, otherwise internal path
        $media->setUrl($meta->url ?? $meta->path);
        $media->setType($meta->mimeType);

        $mediaRepo = $this->repos->getRepository(Media::class);
        $mediaRepo->save($media);

        // 3) Return JSON with the new record
        return new JsonResponse([
            'id'   => $media->getId(),
            'url'  => $media->getUrl(),
            'type' => $media->getType(),
        ], 201);
    }


    /**
     * Upload an image and attach it as the User’s media.
     *
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws \ReflectionException|\JsonException
     */
    #[Route(methods: 'POST', path: '/me/media')]
    public function uploadAndAttach(ServerRequestInterface $request): JsonResponse
    {
        // 1) Ensure authenticated
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Handle file upload
        try {
            $meta = $this->uploads->handle($request, 'file');
        } catch (\Throwable $e) {
            throw new RuntimeException('Upload failed: ' . $e->getMessage(), 400);
        }

        // 3) Persist the Media entity
        $media = new Media();
        $media->setUrl($meta->url ?? $meta->path);
        $media->setType($meta->mimeType);

        $mediaRepo = $this->repos->getRepository(Media::class);
        $mediaRepo->save($media);

        // 4) Load the User and attach the Media
        $userRepo = $this->repos->getRepository(User::class);
        /** @var User|null $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        $user->setMedia($media);
        $userRepo->save($user);

        // 5) Return the new media info
        return new JsonResponse([
            'userId'   => $user->getId(),
            'mediaId'  => $media->getId(),
            'url'      => $media->getUrl(),
            'mimeType' => $media->getType(),
        ], 201);
    }
}