<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A hand-written public page — terms, privacy, about — docs/company-website-plan.md W1.
 *
 * Landlord. The body is Markdown as typed and rendered on read; a draft is simply absent from the
 * public side (404, never 403), which is why every public query goes through `published()`.
 */
class SitePage extends Model
{
    protected $fillable = ['slug', 'title', 'meta_description', 'body', 'is_published', 'published_at', 'sort'];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'sort' => 'integer',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('title');
    }

    public function html(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    public function url(): string
    {
        return route('site.page', ['slug' => $this->slug]);
    }

    protected static function booted(): void
    {
        static::saving(function (SitePage $page): void {
            $page->slug = Str::slug((string) ($page->slug ?: $page->title));

            if ($page->is_published && $page->published_at === null) {
                $page->published_at = now();
            }
        });
    }
}
