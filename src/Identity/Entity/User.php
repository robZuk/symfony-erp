<?php

namespace App\Identity\Entity;

use App\Identity\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'identity_user')]
#[UniqueEntity('email', message: 'Użytkownik z tym adresem e-mail już istnieje.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const string ROLE_WAREHOUSE = 'ROLE_WAREHOUSE';
    public const string ROLE_SALES = 'ROLE_SALES';
    public const string ROLE_ACCOUNTING = 'ROLE_ACCOUNTING';

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
