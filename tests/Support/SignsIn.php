<?php

namespace App\Tests\Support;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait SignsIn
{
    /**
     * Creates a user with the given roles and signs them in without going through the login form.
     */
    private function signInAs(KernelBrowser $client, string ...$roles): User
    {
        $user = new User(\sprintf('user%d@example.com', random_int(1, \PHP_INT_MAX)), 'Test User', array_values($roles));
        $user->setPassword('not-used');
        self::getContainer()->get(UserRepository::class)->save($user);

        $client->loginUser($user);

        return $user;
    }
}
