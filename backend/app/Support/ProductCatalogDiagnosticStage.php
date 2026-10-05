<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ProductCatalogDiagnosticStage
{
    private const ATTRIBUTE = 'product_catalog_diagnostic_stage';

    public static function start(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, 'query');
    }

    public static function mark(Request $request, string $stage): void
    {
        if ($request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $stage);
        }
    }

    public static function current(Request $request): string
    {
        return (string) $request->attributes->get(self::ATTRIBUTE, 'query');
    }
}
