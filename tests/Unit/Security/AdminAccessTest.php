<?php

namespace GK2\NfseNacional\Tests\Unit\Security;

use GK2\NfseNacional\Security\AdminAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminAccessTest extends TestCase
{
    #[DataProvider('casos')]
    public function testPermite(?string $lista, $roleId, bool $esperado): void
    {
        self::assertSame($esperado, AdminAccess::permite($lista, $roleId));
    }

    /**
     * @return array<string, array{0: ?string, 1: mixed, 2: bool}>
     */
    public static function casos(): array
    {
        return [
            'lista vazia nega' => ['', 1, false],
            'lista nula nega' => [null, 1, false],
            'role na lista' => ['1', 1, true],
            'role fora da lista' => ['1,4', 5, false],
            'lista com espacos' => [' 1, 4 ,6 ', '4', true],
            'compara como string' => ['5,9', 9, true],
        ];
    }
}
