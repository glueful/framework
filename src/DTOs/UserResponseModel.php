<?php

declare(strict_types=1);

namespace Glueful\DTOs;


/**
 * User Response Model
 *
 * Specialized DTO for API responses with carefully controlled serialization
 * groups to ensure appropriate data exposure at different access levels.
 */
class UserResponseModel
{
    public string $id;
    public string $name;
    public ?string $username = null;
    public string $email;
    public string $status;
    public string $role;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;
    public ?\DateTime $lastLogin = null;
    public ?string $avatar = null;
    public ?string $bio = null;
    public ?string $location = null;
    public ?string $website = null;
    public ?string $phoneNumber = null;
    public ?\DateTime $dateOfBirth = null;

    /** @var array<string, mixed> */
    public array $preferences = [];

    /** @var array<int, string> */
    public array $permissions = [];
    public bool $isVerified = false;
    public bool $isOnline = false;
    public bool $profileCompleted = false;
    public ?UserResponseModel $manager = null;

    /** @var array<int, UserResponseModel> */
    public array $subordinates = [];
    public \DateTime $memberSince;
    public int $totalPosts = 0;
    public int $totalComments = 0;
    public int $reputationScore = 0;
    public ?string $internalId = null;
    public ?string $internalNotes = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public int $loginAttempts = 0;
    public ?string $lastIp = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->memberSince = new \DateTime();
    }

    /**
     * Create from UserDTO
     */
    public static function fromUserDTO(UserDTO $user): self
    {
        $response = new self();

        // Map basic properties
        $response->id = $user->username ?? uniqid();
        $response->name = $user->name;
        $response->username = $user->username;
        $response->email = $user->email;
        $response->status = $user->status;
        $response->role = $user->role;
        $response->avatar = $user->avatar;
        $response->bio = $user->bio;
        $response->location = $user->location;
        $response->website = $user->website;
        $response->phoneNumber = $user->phoneNumber;
        $response->dateOfBirth = $user->dateOfBirth;
        $response->preferences = $user->preferences;
        $response->permissions = $user->permissions;
        $response->isVerified = $user->isVerified;
        $response->isOnline = $user->isOnline;
        $response->profileCompleted = $user->profileCompleted;
        $response->internalNotes = $user->internalNotes;
        $response->ipAddress = $user->ipAddress;
        $response->userAgent = $user->userAgent;

        // Handle dates
        if ($user->createdAt !== null) {
            $response->createdAt = $user->createdAt;
            $response->memberSince = $user->createdAt;
        }
        if ($user->updatedAt !== null) {
            $response->updatedAt = $user->updatedAt;
        }
        if ($user->lastLogin !== null) {
            $response->lastLogin = $user->lastLogin;
        }

        return $response;
    }

    /**
     * Get public profile data
     *
     * @return array<string, mixed>
     */
    public function getPublicProfile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
            'member_since' => $this->memberSince->format('Y-m-d'),
            'total_posts' => $this->totalPosts,
            'reputation_score' => $this->reputationScore,
        ];
    }

    /**
     * Get summary for lists
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
        ];
    }
}
