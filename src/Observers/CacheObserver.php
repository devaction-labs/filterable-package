<?php

namespace DevactionLabs\FilterablePackage;

use Illuminate\Database\Eloquent\Model;

class CacheObserver
{
    public function saved(Model $model): void
    {
        CacheManager::clear();
    }

    public function deleted(Model $model): void
    {
        CacheManager::clear();
    }
}
