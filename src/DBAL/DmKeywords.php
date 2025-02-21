<?php

namespace Xiaoshao\LaravelDm8\DBAL;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Xiaoshao\LaravelDm8\Dm8ReservedWords;

class DmKeywords extends KeywordList {
    use Dm8ReservedWords;

    public function getName()
    {
        return 'Dm';
    }

    protected function getKeywords()
    {
        return $this->getReserveds();
    }
}