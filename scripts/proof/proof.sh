#!/bin/bash
# Proof read for one public-flow build. Usage: proof.sh <build_id>
S=$(dirname "$0")
BID=$1
$S/tinker.sh '
$b = \App\Models\Core\User\PreAccountBuild::find("'"$BID"'");
if (! $b) { echo "no build"; return; }
$u = \App\Models\Core\User\User::find($b->user_id);
$site = $u?->site;
$conns = $u->integrationConnections()->whereNull("deleted_at")->get()->map(fn ($c) => [
    "platform" => $c->platform, "surface" => $c->surface_key, "active" => (bool) $c->is_active,
    "url" => (string) (((array) $c->payload)["url"] ?? ""),
    "teamMember" => ((array) $c->payload)["teamMember"]["displayName"] ?? null,
    "autoSelected" => ((array) $c->payload)["autoSelected"] ?? null,
])->values()->toArray();
$connIds = $u->integrationConnections()->pluck("id")->map(fn ($i) => (string) $i)->all();
$sources = \DB::table("ingest.sources")->whereIn("connection_id", $connIds)->get(["source_key", "identifier", "health", "last_run_at"])->toArray();
$events = \App\Models\Core\User\PreAccountBuildEvent::where("build_id", $b->id)->orderBy("created_at")->get()->map(fn ($e) => $e->stage.":".$e->status." ".$e->label)->all();
$kinds = \DB::table("content.items")->where("user_id", $u->id)->whereNull("removed_at")->selectRaw("kind, count(*) as n")->groupBy("kind")->pluck("n", "kind")->toArray();
$links = \DB::table("content.items as i")->where("i.user_id", $u->id)->where("i.kind", "link")->whereNull("i.removed_at")
    ->leftJoin("content.item_links as il", "il.item_id", "=", "i.id")
    ->get(["i.id", "i.headline_cache", "il.platform"])->map(function ($r) {
        $cover = \DB::table("content.item_media")->where("item_id", $r->id)->whereIn("role", ["cover", "logo"])->exists();
        return ($r->headline_cache ?? "?")." [".($r->platform ?? "-")."]".($cover ? " img" : " NOIMG");
    })->all();
$services = \DB::table("content.items")->where("user_id", $u->id)->where("kind", "service")->whereNull("removed_at")->pluck("headline_cache")->all();
$wp = \App\Models\Core\Site\Workplace::where("site_id", (string) $site?->id)->first();
$store = \DB::table("content.storefronts")->where("user_id", $u->id)->get(["url", "discount_code"])->toArray();
echo json_encode([
    "build" => $b->only(["build_state", "failure_code", "subdomain", "source_type", "source_ref"]),
    "user" => ["handle" => $u->handle, "display_name" => $u->display_name, "account_type" => $u->account_type],
    "connections" => $conns,
    "sources" => $sources,
    "events" => $events,
    "kinds" => $kinds,
    "links" => $links,
    "services" => $services,
    "contact" => $wp ? ["email" => $wp->contact_email, "phone" => $wp->phone] : null,
    "storefronts" => $store,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
'
