<?php

namespace App\Catalog\Enums;

// Where a detector found evidence of a surface on a scanned page — the
// ordering signal for candidate ranking (higher weight() wins on conflict).
enum EvidenceSurface: string
{
    case Url = 'url';
    case ScriptSrc = 'script_src';
    case IframeSrc = 'iframe_src';
    case Anchor = 'anchor';
    case FormAction = 'form_action';
    case DomSelector = 'dom_selector';
    case JsonLdSameAs = 'jsonld_same_as';
    case MetaTag = 'meta_tag';
    case AssetOrigin = 'asset_origin';
    case DnsCname = 'dns_cname';
    case QrImage = 'qr_image';

    /** Ranking weight for candidate conflicts — higher wins. */
    public function weight(): int
    {
        return match ($this) {
            self::Url => 100,
            self::ScriptSrc => 90,
            self::IframeSrc => 85,
            self::FormAction => 70,
            self::DomSelector => 60,
            self::AssetOrigin => 55,
            self::DnsCname => 55,
            self::JsonLdSameAs => 50,
            self::Anchor => 40,
            self::QrImage => 30,
            self::MetaTag => 20,
        };
    }
}
