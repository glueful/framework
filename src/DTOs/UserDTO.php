<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, SerializedName, Ignore, DateFormat, MaxDepth};
use Glueful\Validation\Support\Rules as RuleFactory;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Rules\{Sanitize, Required, Email as EmailRule, Length, InArray};

/**
 * Enhanced User Data Transfer Object (migrated to new Validation rules)
 */
class UserDTO
{
    #[Groups(['user:read', 'user:write', 'user:public'])]
    public string $name;

    #[Groups(['user:read', 'user:write', 'user:private'])]
    public string $email;

    #[Ignore]
    public ?string $password = null;

    #[Groups(['user:read', 'user:write', 'user:public'])]
    public ?string $username = null;

    #[Groups(['user:read', 'admin:read'])]
    public string $status = 'active';

    #[Groups(['user:read', 'admin:read'])]
    public string $role = 'user';

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('created_at')]
    #[DateFormat('Y-m-d H:i:s')]
    public ?\DateTime $createdAt = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('updated_at')]
    #[DateFormat('Y-m-d H:i:s')]
    public ?\DateTime $updatedAt = null;

    #[Groups(['user:read', 'admin:read'])]
    #[SerializedName('last_login')]
    #[DateFormat('c')]
    public ?\DateTime $lastLogin = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $avatar = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('phone_number')]
    public ?string $phoneNumber = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('date_of_birth')]
    #[DateFormat('Y-m-d')]
    public ?\DateTime $dateOfBirth = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $bio = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $location = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $website = null;

    /** @var array<string, mixed> */
    #[Groups(['user:read', 'user:private'])]
    public array $preferences = [];

    /** @var array<int, string> */
    #[Groups(['user:read', 'user:private'])]
    public array $permissions = [];

    #[Groups(['admin:read'])]
    #[SerializedName('internal_notes')]
    public ?string $internalNotes = null;

    #[Groups(['admin:read'])]
    #[SerializedName('ip_address')]
    public ?string $ipAddress = null;

    #[Groups(['admin:read'])]
    #[SerializedName('user_agent')]
    public ?string $userAgent = null;

    #[Groups(['user:detailed'])]
    #[MaxDepth(2)]
    public ?UserDTO $manager = null;

    /** @var array<int, UserDTO> */
    #[Groups(['user:detailed'])]
    #[MaxDepth(3)]
    public array $subordinates = [];

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('is_verified')]
    public bool $isVerified = false;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('is_online')]
    public bool $isOnline = false;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('profile_completed')]
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
