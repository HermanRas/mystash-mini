<?php

declare(strict_types=1);

namespace MyStash;

require_once __DIR__ . '/VideoCreators.php';

/**
 * The wall's filter + sort state, read from the query string and applied to
 * the index's video list (Docs/SPECIFICATIONS.md §2.8).
 *
 * Filtering and sorting happen on the server, against the already-decrypted
 * index, so a filtered wall renders exactly the tiles it should rather than
 * hiding rows in the browser — and so a filter can be linked to, which is how
 * clicking a category on the Categories screen opens the wall on that category.
 */
final class VideoQuery
{
    /**
     * Sort options, in menu order: value => label.
     */
    public const SORTS = [
        'uploaded_desc' => 'Uploaded: new → old',
        'uploaded_asc' => 'Uploaded: old → new',
        'title_asc' => 'Title: A → Z',
        'title_desc' => 'Title: Z → A',
        'length_asc' => 'Length: short → long',
        'length_desc' => 'Length: long → short',
        'views_desc' => 'Views: max → min',
        'views_asc' => 'Views: min → max',
    ];

    public const DEFAULT_SORT = 'uploaded_desc';

    /**
     * Video types by running time, in filter order: value => label. A short is
     * under 4 minutes, a movie 30 minutes or more, and a video everything in
     * between — so exactly 4:00 is a video and exactly 30:00 a movie.
     */
    public const TYPES = [
        'short' => 'Shorts',
        'video' => 'Videos',
        'movie' => 'Movies',
        'picture' => 'Pictures',
    ];

    public const SHORT_MAX_SECONDS = 4 * 60;
    public const MOVIE_MIN_SECONDS = 30 * 60;

    /**
     * What the wall shows when it opens: everything. (MyStash proper hides
     * shorts; in Mini the filter panel starts closed, so a hidden type would
     * look like a lost upload.)
     */
    public const DEFAULT_TYPES = ['short', 'video', 'movie', 'picture'];

    /** Upper end of the length slider; at the maximum it means "no limit". */
    public const MAX_LENGTH_MINUTES = 180;

    /**
     * Ends of the creator age slider. The bottom is 18 because that is the
     * minimum the creator form accepts; the top is an open end like the length
     * slider's, not an 80-year ceiling.
     */
    public const MIN_AGE = 18;
    public const MAX_AGE = 80;

    /**
     * Longest search term accepted. A search runs over the already-decrypted
     * index, so this is not about cost — it just stops a pathological URL from
     * being echoed back into the input.
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * @param list<string> $categories
     * @param list<string> $creators
     * @param list<string> $types
     */
    private function __construct(
        public readonly array $categories,
        public readonly array $creators,
        public readonly int $minMinutes,
        public readonly int $maxMinutes,
        public readonly int $minAge,
        public readonly int $maxAge,
        public readonly string $gender,
        public readonly string $sort,
        public readonly string $search,
        public readonly array $types,
    ) {
    }

    public static function fromRequest(array $query): self
    {
        $names = static fn(string $key): array => array_values(array_filter(
            array_map('strval', (array) ($query[$key] ?? [])),
            static fn(string $name) => $name !== '',
        ));

        $categories = $names('category');
        $creators = $names('creator');

        // The filter form always sends an empty `type[]`, so a submitted form
        // with every box unticked is told apart from a wall opened bare: only
        // the bare wall gets the default. Nothing ticked means no type filter,
        // as it does for categories and creators.
        if (array_key_exists('type', $query)) {
            $types = array_values(array_intersect(array_keys(self::TYPES), $names('type')));
            $types = $types === [] ? array_keys(self::TYPES) : $types;
        } else {
            $types = self::DEFAULT_TYPES;
        }

        $min = max(0, (int) ($query['len_min'] ?? 0));
        $max = (int) ($query['len_max'] ?? self::MAX_LENGTH_MINUTES);
        $max = min(self::MAX_LENGTH_MINUTES, max($min, $max));

        $minAge = max(self::MIN_AGE, (int) ($query['age_min'] ?? self::MIN_AGE));
        $maxAge = (int) ($query['age_max'] ?? self::MAX_AGE);
        $maxAge = min(self::MAX_AGE, max($minAge, $maxAge));

        $gender = is_array($query['gender'] ?? null) ? '' : trim((string) ($query['gender'] ?? ''));

        $sort = (string) ($query['sort'] ?? self::DEFAULT_SORT);
        if (!isset(self::SORTS[$sort])) {
            $sort = self::DEFAULT_SORT;
        }

        // `q[]=x` would otherwise reach the search as the string "Array".
        $search = is_array($query['q'] ?? null) ? '' : (string) ($query['q'] ?? '');
        $search = mb_substr(trim(preg_replace('/\s+/u', ' ', $search) ?? ''), 0, self::MAX_SEARCH_LENGTH);

        return new self($categories, $creators, $min, $max, $minAge, $maxAge, $gender, $sort, $search, $types);
    }

    public function isFiltered(): bool
    {
        return $this->categories !== []
            || $this->creators !== []
            || $this->search !== ''
            || $this->minMinutes > 0
            || $this->maxMinutes < self::MAX_LENGTH_MINUTES
            || $this->filtersByType()
            || $this->filtersByCreatorAttributes();
    }

    /**
     * True when some type is hidden — which includes the bare wall, since it
     * opens with shorts hidden and its count should say so.
     */
    public function filtersByType(): bool
    {
        return count($this->types) < count(self::TYPES);
    }

    public function hasType(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /** picture, or the video's band by running time. */
    public static function typeOfEntry(array $entry): string
    {
        return ($entry['kind'] ?? 'video') === 'picture'
            ? 'picture'
            : self::typeOf((int) ($entry['length_seconds'] ?? 0));
    }

    /** short, video or movie, from a running time in seconds. */
    public static function typeOf(int $seconds): string
    {
        return match (true) {
            $seconds < self::SHORT_MAX_SECONDS => 'short',
            $seconds < self::MOVIE_MIN_SECONDS => 'video',
            default => 'movie',
        };
    }

    /**
     * True when the age slider has been moved off its ends or a gender picked —
     * i.e. when the creator-attribute filter actually narrows anything.
     */
    public function filtersByCreatorAttributes(): bool
    {
        return $this->gender !== ''
            || $this->minAge > self::MIN_AGE
            || $this->maxAge < self::MAX_AGE;
    }

    /**
     * Search terms, in the order typed. Several words all have to match, each
     * possibly in a different field — so "jamie skate" finds a skating video by
     * Jamie without either word having to match title and creator at once.
     *
     * @return list<string>
     */
    public function searchTerms(): array
    {
        return $this->search === '' ? [] : explode(' ', $this->search);
    }

    /**
     * True when a video matches the search: every term appears somewhere in its
     * title, one of its creators, or one of its category tags
     * (Docs/PLAN.md 5.1). Matching is case-insensitive and on substrings, so
     * "skat" finds "Skateboarding".
     */
    public function matchesSearch(array $video): bool
    {
        $terms = $this->searchTerms();

        if ($terms === []) {
            return true;
        }

        $haystack = implode("\n", array_merge(
            [(string) ($video['title'] ?? '')],
            VideoCreators::of($video),
            array_map('strval', $video['categories'] ?? []),
        ));

        foreach ($terms as $term) {
            if (mb_stripos($haystack, $term) === false) {
                return false;
            }
        }

        return true;
    }

    public function hasCategory(string $name): bool
    {
        return in_array($name, $this->categories, true);
    }

    public function hasCreator(string $name): bool
    {
        return in_array($name, $this->creators, true);
    }

    /**
     * True when a video is credited to at least one creator matching the age
     * and gender filters (Docs/PLAN.md 5.2).
     *
     * "At least one" is the only sensible reading for a co-credited video: if
     * you filter for a creator in her twenties, a video she made with someone
     * older is still one of hers. A creator whose age or gender is simply not
     * recorded does not match an active filter on that field — an unknown age
     * is not evidence of being in the range you asked for.
     *
     * @param array<string, array> $creators name => index summary
     */
    public function matchesCreatorAttributes(array $video, array $creators): bool
    {
        if (!$this->filtersByCreatorAttributes()) {
            return true;
        }

        foreach (VideoCreators::of($video) as $name) {
            $creator = $creators[$name] ?? null;

            if ($creator === null) {
                continue;
            }

            if ($this->gender !== ''
                && strcasecmp(trim((string) ($creator['gender'] ?? '')), $this->gender) !== 0) {
                continue;
            }

            if ($this->minAge > self::MIN_AGE || $this->maxAge < self::MAX_AGE) {
                $age = $creator['age'] ?? null;

                if ($age === null || $age === '') {
                    continue;
                }

                $age = (int) $age;

                if ($age < $this->minAge) {
                    continue;
                }

                // The top of the slider is an open end, not an 80-year ceiling.
                if ($this->maxAge < self::MAX_AGE && $age > $this->maxAge) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * This query as wall.php URL parameters, with only what differs from a bare
     * wall — so the watch page can carry it to its prev/next links and back.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $params = [];
        if ($this->search !== '') {
            $params['q'] = $this->search;
        }
        if ($this->categories !== []) {
            $params['category'] = $this->categories;
        }
        if ($this->creators !== []) {
            $params['creator'] = $this->creators;
        }
        if ($this->types != self::DEFAULT_TYPES) {
            $params['type'] = ['', ...$this->types];
        }
        if ($this->minMinutes > 0) {
            $params['len_min'] = $this->minMinutes;
        }
        if ($this->maxMinutes < self::MAX_LENGTH_MINUTES) {
            $params['len_max'] = $this->maxMinutes;
        }
        if ($this->sort !== self::DEFAULT_SORT) {
            $params['sort'] = $this->sort;
        }

        return $params;
    }

    /**
     * @param list<array> $videos index entries
     * @param array<string, array> $creators name => index summary, for the age
     *        and gender filters; they are attributes of the creator, not of the
     *        video, so the video list alone cannot answer them
     * @return list<array>
     */
    public function apply(array $videos, array $creators = []): array
    {
        $videos = array_values(array_filter($videos, function (array $video) use ($creators): bool {
            if (!$this->matchesSearch($video)) {
                return false;
            }

            if (!$this->hasType(self::typeOfEntry($video))) {
                return false;
            }

            $minutes = ((int) ($video['length_seconds'] ?? 0)) / 60;
            $isPicture = ($video['kind'] ?? 'video') === 'picture';

            if (!$isPicture && $minutes < $this->minMinutes) {
                return false;
            }

            // The top of the slider is an open end, not a 180-minute ceiling.
            if ($this->maxMinutes < self::MAX_LENGTH_MINUTES && $minutes > $this->maxMinutes) {
                return false;
            }

            if ($this->creators !== []
                && array_intersect($this->creators, VideoCreators::of($video)) === []) {
                return false;
            }

            if (!$this->matchesCreatorAttributes($video, $creators)) {
                return false;
            }

            if ($this->categories === []) {
                return true;
            }

            return array_intersect($this->categories, $video['categories'] ?? []) !== [];
        }));

        usort($videos, $this->comparator());

        return $videos;
    }

    private function comparator(): callable
    {
        return match ($this->sort) {
            'title_asc' => static fn($a, $b) => strcasecmp($a['title'], $b['title']),
            'title_desc' => static fn($a, $b) => strcasecmp($b['title'], $a['title']),
            'length_asc' => static fn($a, $b) => $a['length_seconds'] <=> $b['length_seconds'],
            'length_desc' => static fn($a, $b) => $b['length_seconds'] <=> $a['length_seconds'],
            'views_asc' => static fn($a, $b) => $a['views'] <=> $b['views'],
            'views_desc' => static fn($a, $b) => $b['views'] <=> $a['views'],
            // Entries predating uploaded_at have no date to sort on; they sort
            // oldest either way rather than jumping to the top of the wall.
            // A bulk upload lands many entries in the same second, so the id
            // (which only ever grows) breaks the tie in upload order.
            'uploaded_asc' => static fn($a, $b) => [($a['uploaded_at'] ?? ''), (int) $a['id']] <=> [($b['uploaded_at'] ?? ''), (int) $b['id']],
            default => static fn($a, $b) => [($b['uploaded_at'] ?? ''), (int) $b['id']] <=> [($a['uploaded_at'] ?? ''), (int) $a['id']],
        };
    }
}
