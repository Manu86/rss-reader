<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CurrentUser;
use App\Service\OpmlExporter;
use App\Service\OpmlImportService;

final readonly class OpmlController
{
    private const MAX_UPLOAD_BYTES = 1_048_576;

    public function __construct(
        private OpmlImportService $importer,
        private OpmlExporter $exporter,
        private CurrentUser $currentUser,
    ) {}

    public function import(Request $request): Response
    {
        $user = $this->currentUser->require();
        $contentType = strtolower($request->header('content-type') ?? '');
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            throw new ValidationException(['file' => 'Un fichier OPML multipart est requis.']);
        }
        $file = $request->uploadedFile('file');
        if ($file === null) {
            throw new ValidationException(['file' => 'Le fichier OPML est requis.']);
        }

        return Response::json(['data' => $this->importer->import(
            $user->id,
            $file->contents(self::MAX_UPLOAD_BYTES),
        )]);
    }

    public function export(): Response
    {
        $user = $this->currentUser->require();

        return new Response(200, $this->exporter->export($user->id), [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rss-reader.opml"',
        ]);
    }
}
