<?php

namespace App\Identity\Security;

use App\Identity\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses the sign-in of a blocked account. Registered on the firewall in config/packages/security.yaml.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // After the password check on purpose: only someone who knows the password learns that the account is blocked
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('To konto jest zablokowane. Skontaktuj się z administratorem.');
        }
    }
}
