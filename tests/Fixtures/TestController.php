<?php

declare(strict_types=1);

namespace Mjoris\Router\Tests\Fixtures;

class TestController
{
    public function show(string $id): void
    {
        echo $id;
    }

    public function showNumber(int $id): void
    {
        echo 'number ' . $id;
    }

    public function returnFalse(): bool
    {
        echo 'returnFalse';

        return false;
    }

    public static function staticReturnFalse(): bool
    {
        echo 'staticReturnFalse';

        return false;
    }

    public function notFound(): void
    {
        echo 'route not found';
    }

    private function secret(): void
    {
        echo 'secret';
    }
}
