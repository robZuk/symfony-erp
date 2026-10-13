<?php

namespace App\Identity\Entity;

use App\Identity\Repository\UserRepository;
use App\Shared\Audit\Actor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'identity_user')]
#[UniqueEntity('email', message: 'Użytkownik z tym adresem e-mail już istnieje.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, Actor
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_WAREHOUSE = 'ROLE_WAREHOUSE';
    public const string ROLE_SALES = 'ROLE_SALES';
    public const string ROLE_ACCOUNTING = 'ROLE_ACCOUNTING';

    /** What each role is called on screen. */
    public const array ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrator',
        self::ROLE_WAREHOUSE => 'Magazynier',
        self::ROLE_SALES => 'Handlowiec',
        self::ROLE_ACCOUNTING => 'Księgowość',
    ];

    public const array ASSIGNABLE_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_WAREHOUSE,
        self::ROLE_SALES,
        self::ROLE_ACCOUNTING,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $fullName;

    /** @var list<string> */
    #[ORM\Column]
    #[Assert\Choice(choices: self::ASSIGNABLE_ROLES, multiple: true)]
    private array $roles;

    #[ORM\Column]
    private string $password = '';

    /** A blocked user keeps their history but cannot sign in. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * @param list<string> $roles
     */
    public function __construct(string $email, string $fullName, array $roles = [])
    {
        $this->email = mb_strtolower(trim($email));
        $this->fullName = trim($fullName);
        $this->roles = array_values(array_unique($roles));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function rename(string $fullName): void
    {
        $this->fullName = trim($fullName);
    }

    /**
     * @return list<string> the roles given to the user, without the implicit ROLE_USER
     */
    public function getAssignedRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param list<string> $roles
     */
    public function assignRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function block(): void
    {
        $this->active = false;
    }

    public function unblock(): void
    {
        $this->active = true;
    }

    public function actorId(): int
    {
        return (int) $this->id;
    }

    public function actorName(): string
    {
        return $this->fullName;
    }

    /**
     * Called on every request with the user freshly loaded from the database. When this returns false
     * the session ends, so a blocked user, or one whose password or roles were changed, is signed out at once.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->email === $user->email
            && $this->password === $user->password
            && $this->roles === $user->roles
            && $user->active;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('A user without an e-mail cannot be identified.');
        }

        return $this->email;
    }

    /**
     * Every user gets ROLE_USER on top of the roles stored in the database.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }
}
