<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthThrowsWhenUserIsInactive(): void
    {
        $checker = new UserChecker();
        $user = (new User())
            ->setEmail('inactive@example.local')
            ->setPassword('hashed')
            ->setIsActive(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $checker->checkPreAuth($user);
    }

    public function testCheckPreAuthDoesNotThrowWhenUserIsActive(): void
    {
        $checker = new UserChecker();
        $user = (new User())
            ->setEmail('active@example.local')
            ->setPassword('hashed')
            ->setIsActive(true);

        $checker->checkPreAuth($user);
        self::assertTrue(true);
    }

    public function testCheckPreAuthIgnoresNonAppUser(): void
    {
        $checker = new UserChecker();
        $user = $this->createMock(UserInterface::class);

        $checker->checkPreAuth($user);
        self::assertTrue(true);
    }
}
