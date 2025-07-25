<?php

namespace App\Services\Themes;

use App\Models\WpOrg\Theme;
use App\Utils\Regex;
use App\Values\WpOrg\Themes\QueryThemesRequest;
use App\Values\WpOrg\Themes\QueryThemesResponse;
use App\Values\WpOrg\Themes\ThemeResponse;
use Illuminate\Database\Eloquent\Builder;

class QueryThemesService
{
    public function queryThemes(QueryThemesRequest $req): QueryThemesResponse
    {
        $page = $req->page;
        $perPage = $req->per_page;
        $skip = ($page - 1) * $perPage;

        $search = self::normalizeSearchString($req->search);
        $author = self::normalizeSearchString($req->author);

        $themesBaseQuery = Theme::query()
            ->orderBy('last_updated', 'desc')   // default sort
            ->when($req->browse, self::applyBrowse(...))
            ->when($search, self::applySearch(...))
            ->when($req->theme, self::applyTheme(...))
            ->when($author, self::applyAuthor(...))
            ->when($req->tags, self::applyTags(...))
            ->when($req->ac_tags, self::applyAcTags(...));


        $total = $themesBaseQuery->count();

        $themes = $themesBaseQuery
            ->skip($skip)
            ->take($perPage)
            ->with('author')
            ->get();

        $collection = ThemeResponse::collect($themes)->map(fn($theme) => $theme->withFields($req->fields ?? []));

        return QueryThemesResponse::from(
            themes: $collection,
            info: ['page' => $page, 'pages' => (int)ceil($total / $perPage), 'results' => $total],
        );
    }

    /** @param Builder<Theme> $query */
    private static function applyBrowse(Builder $query, string $browse): void
    {
        // TODO: replicate 'featured' browse (currently it's identical to 'popular')
        match ($browse) {
            'featured' => $query->reorder('rating', 'desc'),
            'new' => $query->reorder('creation_time', 'desc'),
            default => $query->reorder('active_installs', 'desc'), // 'popular' is also the default
        };
    }

    /** @param Builder<Theme> $query */
    private static function applySearch(Builder $query, string $search): void
    {
        $slug = Regex::replace('/[^a-z0-9-]+/i', '-', $search);
        $query->where('slug', $slug); // need an initial condition or it retrieves everything

        $q = Theme::query();

        $slug_similar = $q->clone()->whereRaw("slug %> '$search'");
        $name_exact = $q->clone()->where('name', $search);
        $name_similar = $q->clone()->whereRaw("name %> '$search'");
        $description_fulltext = $q->clone()->whereFullText('description', $search);

        $query->unionAll($name_exact);
        $query->unionAll($slug_similar);
        $query->unionAll($name_similar);
        $query->unionAll($description_fulltext);
    }

    /** @param Builder<Theme> $query */
    private static function applyTheme(Builder $query, string $theme): void
    {
        $query->whereRaw("slug %> '$theme'");
    }

    /** @param Builder<Theme> $query */
    private static function applyAuthor(Builder $query, string $author): void
    {
        $query->whereHas(
            'author',
            fn(Builder $q) => $q->whereRaw("user_nicename %> '$author'")->orWhereRaw("display_name %> '$author'"),
        );
    }

    /**
     * @param Builder<Theme> $query
     * @param string[] $tags
     */
    private static function applyTags(Builder $query, array $tags): void
    {
        $query->whereHas('tags', fn(Builder $q) => $q->whereIn('slug', $tags));
    }

    /**
     * @param Builder<Theme> $query
     * @param string[]       $ac_tags
     */
    private static function applyAcTags(Builder $query, array $ac_tags): void
    {
        foreach ($ac_tags as $tag) {
            $query->whereHas('tags', fn(Builder $q) => $q->where('slug', $tag));
        }
    }

    private static function normalizeSearchString(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $search = trim($search);
        $search = Regex::replace('/\s+/i', ' ', $search);
        return Regex::replace('/[^\w.,!?@#$_-]/i', ' ', $search); // strip most punctuation, allow a small subset
    }
}
