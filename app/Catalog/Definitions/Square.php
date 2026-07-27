<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Square — two surfaces resolve the legacy square/square-ordering dual
 * identity: square.site + squareup.com both detect ONLY square.book (booking
 * wins the ambiguous host). square.order gets no detector at P1 — see its
 * note. Neither surface has a catalog 'connect' capability (no registered
 * ->connect() anywhere — square's real connect is a bespoke controller,
 * connectInput-only in PRSP), but square.book stays connectable (isConnectable
 * default true) since that bespoke flow genuinely works today; square.order
 * is marked notConnectable() as a P1 placeholder.
 */
class Square
{
    public static function brand(): Brand
    {
        return Brand::make('square', 'Square', 'https://squareup.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('square.book')
                ->displayName('Square')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('square.site is assigned to square.book only — square.order is undetectable by host alone at P1; see square.order\'s note')
                ->detect(
                    Detector::url('squareup.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('square.site')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
            SurfaceBuilder::for('square.order')
                ->displayName('Square Online')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->note('detector intentionally absent: square.site hosting is ambiguous between booking and ordering — P2 evidence rules disambiguate')
                ->notConnectable()
                ->build(),
        ];
    }
}
