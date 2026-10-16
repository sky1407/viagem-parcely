<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonResponse;
use App\Repository\CadastralAreaRepository;

final class CadastralAreaController
{
    public function __construct(private readonly CadastralAreaRepository $cadastralAreas)
    {
    }

    public function index(): void
    {
        header('Cache-Control: public, max-age=86400');
        JsonResponse::send($this->cadastralAreas->all());
    }
}
