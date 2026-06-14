<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;

class GroupNameCategorizer extends AbstractCategorizer
{
    protected int $priority = 5;

    public function getName(): string
    {
        return 'GroupName';
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        $groupName = $context->groupName;
        if (empty($groupName)) {
            return $this->noMatch();
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*anime/i', $groupName)) {
            return $this->matched(Category::TV_ANIME, 0.8, 'group_name_anime');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(tv|hdtv|tvseries)/i', $groupName)) {
            return $this->matched(Category::TV_OTHER, 0.6, 'group_name_tv');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(movies?|movie[.-]?classic|dvd[.-]classic|dvd[.-]movies?|dvd[.-]documentar|bluray|blu[.-]?ray|uhd|x264|vintage[.-]?film)/i', $groupName)) {
            if (preg_match('/[._ -]s\d{1,3}[._ -]?(e|d(isc)?)\d{1,3}([._ -]|$)/i', $context->releaseName)) {
                return $this->matched(Category::TV_OTHER, 0.75, 'group_name_movie_tv_episode');
            }

            return $this->matched(Category::MOVIE_OTHER, 0.6, 'group_name_movie');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(erotica|pictures\.erotica|xxx)/i', $groupName)) {
            return $this->matched(Category::XXX_OTHER, 0.7, 'group_name_xxx');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?lossless/i', $groupName)) {
            return $this->matched(Category::MUSIC_LOSSLESS, 0.65, 'group_name_lossless');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(sounds?|mp3|music)/i', $groupName)) {
            return $this->matched(Category::MUSIC_OTHER, 0.6, 'group_name_music');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(games?|console|psx|nintendo)/i', $groupName)) {
            return $this->matched(Category::GAME_OTHER, 0.6, 'group_name_game');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(warez|0day|apps?|software)/i', $groupName)) {
            return $this->matched(Category::PC_0DAY, 0.6, 'group_name_pc');
        }
        if (preg_match('/(?:alt\.binaries|a\.b)\..*?(e-?book|ebook|comics?)/i', $groupName)) {
            return $this->matched(Category::BOOKS_EBOOK, 0.5, 'group_name_book');
        }

        return $this->noMatch();
    }
}
