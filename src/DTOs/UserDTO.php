<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Email as EmailRule, Length, InArray};

/**
 * Enhanced User Data Transfer Object (migrated to new Validation rules)
 */
class UserDTO
{
    public string $name;
    public string $email;
    public ?string $password = null;
    public ?string $username = null;
    public string $status = 'active';
    public string $role = 'user';
    public ?\DateTime $createdAt = null;
    public ?\DateTime $updatedAt = null;
    public ?\DateTime $lastLogin = null;
    public ?string $avatar = null;
    public ?string $phoneNumber = null;
    public ?\DateTime $dateOfBirth = null;
    public ?string $bio = null;
    public ?string $location = null;
    public ?string $website = null;

    /** @var array<string, mixed> */
    public array $preferences = [];

    /** @var array<int, string> */
    public array $permissions = [];
    public ?string $internalNotes = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public ?UserDTO $manager = null;

    /** @var array<int, UserDTO> */
    public array $subordinates = [];
    public bool $isVerified = false;
    public bool $isOnline = false;
    public bool $profileCompleted = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    /**
     * Validate and create from input using new Validation rules.
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function from(array $input): self
    {
        $v = RuleFactory::of([
            'name' => [new Sanitize(['trim', 'strip_tags']), new Required(), new Length(2, 50)],
            'email' => [new Sanitize(['trim', 'strip_tags']), new Required(), new EmailRule()],
            'password' => [new Sanitize(['trim']), new Length(8, 255)],
            'username' => [new Sanitize(['trim', 'strip_tags']), new Length(3, 30)],
            'status' => [new Sanitize(['trim']), new InArray(['active','inactive','suspended','banned'])],
            'role' => [new Sanitize(['trim']), new InArray(['user','admin','moderator','guest'])],
        ]);

        $errors = $v->validate($input);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        $data = $v->filtered();

        $dto = new self();
        $dto->name = (string)($data['name'] ?? '');
        $dto->email = (string)($data['email'] ?? '');
        $dto->password = isset($data['password']) && $data['password'] !== '' ? (string)$data['password'] : null;
        $dto->username = isset($data['username']) && $data['username'] !== '' ? (string)$data['username'] : null;
        if (isset($data['status'])) {
            $dto->status = (string)$data['status'];
        }
        if (isset($data['role'])) {
            $dto->role = (string)$data['role'];
        }

        // Optional additional fields (no validation here)
        $dto->avatar = isset($input['avatar']) ? (string)$input['avatar'] : null;
        $dto->phoneNumber = $input['phone_number'] ?? $dto->phoneNumber;
        $dto->bio = $input['bio'] ?? $dto->bio;
        $dto->location = $input['location'] ?? $dto->location;
        $dto->website = $input['website'] ?? $dto->website;
        $dto->preferences = is_array($input['preferences'] ?? null) ? $input['preferences'] : $dto->preferences;
        $dto->permissions = is_array($input['permissions'] ?? null) ? $input['permissions'] : $dto->permissions;

        return $dto;
    }

    /**
     * Get public representation
     * @return array<string, mixed>
     */
    public function getPublicData(): array
    {
        return [
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
        ];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function getDisplayName(): string
    {
        return $this->name !== '' ? $this->name : ($this->username !== null ? $this->username : 'Anonymous');
    }

    public function isProfileComplete(): bool
    {
        return $this->name !== '' &&
               $this->email !== '' &&
               $this->username !== null && $this->username !== '' &&
               $this->profileCompleted;
    }
}
