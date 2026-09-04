<?php

declare(strict_types=1);

namespace App\Schema\Errors;

use OpenApi\Attributes as OA;

#[OA\Schema(
    type: 'object',
    properties: [
        new OA\Property(property: 'type', type: 'string', example: 'https://tools.ietf.org/html/rfc2616#section-10'),
        new OA\Property(property: 'title', type: 'string', example: 'An error occurred'),
        new OA\Property(property: 'status', type: 'integer', example: 400),
        new OA\Property(property: 'detail', type: 'string', example: 'Bad Request'),
        new OA\Property(
            property: 'violations',
            description: 'Present when the request failed validation: one entry per rejected field',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'propertyPath', type: 'string', example: 'name'),
                    new OA\Property(property: 'title', type: 'string', example: 'A magazine name may only contain letters, numbers and underscores, so "My Community" cannot be used.'),
                ],
                type: 'object'
            )
        ),
    ]
)]
class BadRequestErrorSchema
{
}
