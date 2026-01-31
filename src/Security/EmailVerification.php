<?php

declare(strict_types=1);

namespace Glueful\Security;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Http\RequestContext;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Repository\UserRepository;
use Glueful\Helpers\CacheHelper;

/**
 * Email Verification System
 *
 * Handles OTP generation, verification, and rate limiting for email verification.
 * Includes protection against brute force and spam attempts.
 */
class EmailVerification
{
    /** @var int Length of generated OTP codes */
    public const OTP_LENGTH = 6;

    /** @var int OTP validity period in minutes */
    public const OTP_EXPIRY_MINUTES = 15;

    /** @var string Cache prefix for OTP storage */
    private const OTP_PREFIX = 'email_verification:';

    /** @var string Cache prefix for failed attempts */
    private const ATTEMPTS_PREFIX = 'email_verification_attempts:';

    /** @var int Maximum failed attempts before blocking */
    private const MAX_ATTEMPTS = 3;

    /** @var int Cooldown period in minutes after max attempts */
    private const COOLDOWN_MINUTES = 30;

    /** @var NotificationService Notification service instance */
    private NotificationService $notificationService;

    /** @var object|null Email notification provider (loaded from extension) */
    private ?object $emailProvider = null;

    /** @var RequestContext Request context service */
    private RequestContext $requestContext;

    /** @var CacheStore<mixed> Cache driver instance */
    private CacheStore $cache;

    private ?ApplicationContext $context;

    /**
     * Constructor
     *
     * Initializes cache driver for OTP storage and notification service.
     */
    /**
     * @param RequestContext|null $requestContext
     * @param CacheStore<mixed>|null $cache
     * @param ApplicationContext|null $context
     */
    public function __construct(
        ?RequestContext $requestContext = null,
        ?CacheStore $cache = null,
        ?ApplicationContext $context = null
    ) {
        $this->context = $context;
        $this->requestContext = $requestContext ?? RequestContext::fromGlobals();
        $this->cache = $cache ?? CacheHelper::createCacheInstance();

        if ($this->cache === null) {
            throw new \RuntimeException(
                'Cache is required for EmailVerification. Please ensure cache is properly configured.'
            );
        }

        // Prefer DI-provided dispatcher/channel manager; fall back to ad-hoc instances
        $dispatcher = null;
        $channelManager = null;
        try {
            $c = $this->context !== null ? container($this->context) : null;
            if ($c === null) {
                throw new \RuntimeException('Container unavailable without ApplicationContext.');
            }
            if ($c->has(\Glueful\Notifications\Services\NotificationDispatcher::class)) {
                /** @var \Glueful\Notifications\Services\NotificationDispatcher $diDispatcher */
                $diDispatcher = $c->get(\Glueful\Notifications\Services\NotificationDispatcher::class);
                $dispatcher = $diDispatcher;
                $channelManager = $diDispatcher->getChannelManager();
            }
        } catch (\Throwable $e) {
            // Container not available yet; continue with fallback
        }
        if ($dispatcher === null || $channelManager === null) {
            $channelManager = new \Glueful\Notifications\Services\ChannelManager();
            $events = null;
            try {
                $c = $this->context !== null ? container($this->context) : null;
                if ($c !== null && $c->has(\Glueful\Events\EventService::class)) {
                    $events = $c->get(\Glueful\Events\EventService::class);
                }
            } catch (\Throwable) {
                $events = null;
            }
            $dispatcher = new \Glueful\Notifications\Services\NotificationDispatcher(
                $channelManager,
                null,
                [],
                $events instanceof \Glueful\Events\EventService ? $events : null
            );
        }

        // Initialize EmailNotificationProvider if extension is available and context exists
        $emailExtensionClass = '\Glueful\Extensions\EmailNotification\EmailNotificationProvider';
        if (class_exists($emailExtensionClass) && $this->context !== null) {
            /** @var class-string $providerClass */
            $providerClass = $emailExtensionClass;
            $this->emailProvider = new $providerClass($this->context);
            if (method_exists($this->emailProvider, 'initialize')) {
                $this->emailProvider->initialize();
            }
            // If using DI, provider/channel likely registered during extension boot.
            // Only register manually in fallback mode.
            $usingDi = false;
            try {
                $c = container($this->context);
                $usingDi = $c->has(\Glueful\Notifications\Services\NotificationDispatcher::class);
            } catch (\Throwable $e) {
                $usingDi = false;
            }
            if ($usingDi === false) {
                if (method_exists($this->emailProvider, 'register')) {
                    $this->emailProvider->register($channelManager);
                }
            }
            // Ensure provider hooks (before/after send) are active
            if (method_exists($dispatcher, 'getExtension') && method_exists($dispatcher, 'registerExtension')) {
                $name = method_exists($this->emailProvider, 'getExtensionName')
                    ? $this->emailProvider->getExtensionName()
                    : 'email_notification';
                if ($dispatcher->getExtension($name) === null) {
                    $dispatcher->registerExtension($this->emailProvider);
                }
            }
        }

        // Initialize NotificationService with required dispatcher and repository
        $notificationRepository = new \Glueful\Repository\NotificationRepository();
        $this->notificationService = new NotificationService(
            $dispatcher,
            $notificationRepository,
            context: $this->context
        );
    }

    /**
     * Generate OTP code
     *
     * @return string Numeric OTP code
     */
    public function generateOTP(): string
    {
        return OTP::generateNumeric(self::OTP_LENGTH);
    }

    /**
     * Send verification email
     *
     * Handles rate limiting and sends OTP via notification system.
     *
     * @param string $email Recipient email address
     * @param string $otp Generated OTP code
     * @return array{success: bool, message: string, error_code?: string} Operation result with status and message
     */
    public function sendVerificationEmail(string $email, string $otp): array
    {
        try {
            // Store OTP in Redis before sending email
            $hashedOTP = OTP::hashOTP($otp);
            $stored = $this->storeOTP($email, $hashedOTP);

            if (!$stored) {
                error_log("Failed to store OTP in cache for email: $email");
                return [
                    'success' => false,
                    'message' => 'Failed to initialize verification process. Please try again.',
                    'error_code' => 'cache_failure'
                ];
            }

            // Create a temporary notifiable for this email
            $notifiable = new class ($email) implements Notifiable {
                private string $email;

                public function __construct(string $email)
                {
                    $this->email = $email;
                }

                public function routeNotificationFor(string $channel): ?string
                {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }

                public function getNotifiableId(): string
                {
                    return md5($this->email);
                }

                public function getNotifiableType(): string
                {
                    return 'verification_recipient';
                }

                public function shouldReceiveNotification(string $notificationType, string $channel): bool
                {
                    return $channel === 'email';
                }

                public function getNotificationPreferences(): array
                {
                    return ['email' => true];
                }
            };

            // Send via notification system using the verification template
            $result = $this->notificationService->send(
                'email_verification',
                $notifiable,
                'Verify your email address',
                [
                    'otp' => $otp,
                    'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                    'template_name' => 'verification',
                    'subject' => 'Verify your email address',
                    'title' => 'Email Verification',
                ],
                ['channels' => ['email']]
            );

            // Soft diagnostics: log if email channel is not available
            $channelsInfo = $result['channels'] ?? [];
            if (isset($channelsInfo['email'])) {
                $emailStatus = $channelsInfo['email']['status'] ?? '';
                $reason = $channelsInfo['email']['reason'] ?? '';
                $isChannelUnavailable = in_array(
                    $reason,
                    ['channel_not_found', 'channel_unavailable'],
                    true
                );
                if ($emailStatus !== 'success' && $isChannelUnavailable) {
                    $msg = 'EmailVerification: email channel not available: ' . $reason;
                    error_log($msg);
                }
            } elseif (($result['status'] ?? '') === 'failed' && (int)($result['sent_count'] ?? 0) === 0) {
                error_log(
                    'EmailVerification: notification failed; no channels succeeded for email verification'
                );
            }

            // Parse the result using the NotificationResultParser
            $parsedResult = \Glueful\Notifications\Utils\NotificationResultParser::parseEmailResult(
                $result,
                [
                    'email' => $email,
                    'expires_in' => self::OTP_EXPIRY_MINUTES * 60
                ],
                'Verification code sent successfully'
            );

            return $parsedResult;
        } catch (\Exception $e) {
            $errorResult = [
                'success' => false,
                'message' => 'An error occurred during email verification: ' . $e->getMessage(),
                'error_code' => 'system_error'
            ];


            return $errorResult;
        }
    }

    /**
     * Store OTP in cache
     *
     * @param string $email User email
     * @param string $hashedOTP Hashed OTP value
     * @return bool True if stored successfully
     */
    private function storeOTP(string $email, string $hashedOTP): bool
    {
        try {
            $key = self::OTP_PREFIX . $this->sanitizeEmailForCacheKey($email);
            $data = [
                'otp' => $hashedOTP,
                'timestamp' => time()
            ];

            // Try to store the data
            $result = $this->cache->set($key, $data, self::OTP_EXPIRY_MINUTES * 60);

            // Debug what's happening with the cache operation
            if (!$result) {
                $driver = $this->context !== null
                    ? (string) config($this->context, 'cache.default')
                    : 'unknown';
                error_log("Failed to store OTP in cache. Key: $key, Driver: " . $driver);

                // Try with a shorter expiry as fallback
                $fallbackResult = $this->cache->set($key, $data, 900); // 15 minutes in seconds
                if ($fallbackResult) {
                    error_log("Successfully stored OTP using fallback method for email: $email");
                    return true;
                }

                // If still failing, try alternative storage approach
                error_log("Attempting alternative storage method for OTP");
                return $this->storeOTPAlternative($key, $data);
            }

            return $result;
        } catch (\Exception $e) {
            error_log("Exception storing OTP in cache: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Alternative OTP storage method
     *
     * Used as a fallback when primary cache storage fails
     *
     * @param string $key Cache key
     * @param array<string, mixed> $data OTP data to store
     * @return bool True if stored successfully
     */
    private function storeOTPAlternative(string $key, array $data): bool
    {
        try {
            // Try to use file-based storage as last resort
            $storagePath = $this->context !== null
                ? config($this->context, 'app.paths.storage_path', __DIR__ . '/../../storage') . '/cache/'
                : __DIR__ . '/../../storage/cache/';
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $filePath = $storagePath . md5($key) . '.tmp';
            $data['expiry'] = time() + self::OTP_EXPIRY_MINUTES * 60;
            $success = file_put_contents($filePath, json_encode($data)) !== false;

            if ($success) {
                error_log("Successfully stored OTP using file-based fallback");
            }

            return $success;
        } catch (\Exception $e) {
            error_log("Alternative OTP storage failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify provided OTP
     *
     * Validates OTP and handles failed attempts.
     *
     * @param string $email User email
     * @param string $providedOTP OTP to verify
     * @return bool True if OTP is valid
     */
    public function verifyOTP(string $email, string $providedOTP): bool
    {
        $key = self::OTP_PREFIX . $this->sanitizeEmailForCacheKey($email);
        $stored = $this->cache->get($key);

        if (
            !is_array($stored) ||
            !isset($stored['otp']) ||
            $stored['otp'] === '' ||
            !isset($stored['timestamp']) ||
            $stored['timestamp'] === ''
        ) {
            $this->incrementAttempts($email);


            return false;
        }

        $isValid = OTP::verifyHashedOTP($providedOTP, $stored['otp']);

        if ($isValid) {
            // Clear rate limiting on success
            $this->cache->delete($key);

            // Update email_verified_at timestamp if user exists
            try {
                $userRepository = new UserRepository();
                $user = $userRepository->findByEmail($email);
                if (is_array($user) && isset($user['uuid'])) {
                    // Update the email_verified_at timestamp
                    $userRepository->update($user['uuid'], [
                        'email_verified_at' => date('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Exception $e) {
                // Log the error but don't fail the verification
                error_log("Failed to update email_verified_at timestamp: " . $e->getMessage());
            }


            return true;
        }

        // Increment failed attempts
        $this->incrementAttempts($email);


        return false;
    }

    /**
     * Check rate limiting status
     *
     * @param string $email User email
     * @return bool True if rate limited
     */
    private function isRateLimited(string $email): bool
    {
        $attempts = $this->cache->get(self::ATTEMPTS_PREFIX . $this->sanitizeEmailForCacheKey($email)) ?? 0;
        return $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Track failed verification attempts
     *
     * @param string $email User email
     */
    private function incrementAttempts(string $email): void
    {
        $key = self::ATTEMPTS_PREFIX . $this->sanitizeEmailForCacheKey($email);
        $attempts = (int)($this->cache->get($key) ?? 0) + 1;
        $this->cache->set($key, $attempts, self::COOLDOWN_MINUTES * 60);

        if ($attempts >= self::MAX_ATTEMPTS) {
            error_log("Account temporary locked for email: $email");
        }
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool True if email format is valid
     */
    public function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Send password reset email
     *
     * Handles password reset flow with verification using notification system.
     *
     * @param string $email User email address
     * @return array{success: bool, message: string, error_code?: string} Operation result with status
     */
    public static function sendPasswordResetEmail(string $email): array
    {
        try {
            $requestContext = RequestContext::fromGlobals();
            $verifier = new self($requestContext, CacheHelper::createCacheInstance());

            if (!$verifier->isValidEmail($email)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format',
                    'error_code' => 'invalid_email_format'
                ];
            }

            // Check if email exists in users table
            $userRepository = new UserRepository();
            $userData = $userRepository->findByEmail($email);
            if ($userData === null) {
                return [
                    'success' => false,
                    'message' => 'Email address not found',
                    'error_code' => 'email_not_found'
                ];
            }

            // Check rate limiting
            if ($verifier->isRateLimited($email)) {
                return [
                    'success' => false,
                    'message' => 'Too many attempts. Please try again later.',
                    'error_code' => 'rate_limited'
                ];
            }

            // Generate OTP
            $otp = $verifier->generateOTP();

            // Store OTP
            $hashedOTP = OTP::hashOTP($otp);
            if (!$verifier->storeOTP($email, $hashedOTP)) {
                throw new \RuntimeException('Failed to initialize password reset');
            }

            // Create a temporary notifiable for this email
            $notifiable = new class ($email) implements Notifiable {
                private string $email;

                public function __construct(string $email)
                {
                    $this->email = $email;
                }

                public function routeNotificationFor(string $channel): ?string
                {
                    if ($channel === 'email') {
                        return $this->email;
                    }
                    return null;
                }

                public function getNotifiableId(): string
                {
                    return md5($this->email);
                }

                public function getNotifiableType(): string
                {
                    return 'password_reset_recipient';
                }

                public function shouldReceiveNotification(string $notificationType, string $channel): bool
                {
                    return $channel === 'email';
                }

                public function getNotificationPreferences(): array
                {
                    return ['email' => true];
                }
            };

            // Send via notification system using the password-reset template
            $userProfile = $userRepository->getProfile($userData['uuid'] ?? null);
            $result = $verifier->notificationService->send(
                'password_reset',
                $notifiable,
                'Password Reset Code',
                [
                    'name' => $userProfile['first_name'] ?? '',
                    'otp' => $otp,
                    'expiry_minutes' => self::OTP_EXPIRY_MINUTES,
                    'subject' => 'Password reset requested',
                    'title' => 'Password reset requested for',
                    'template_name' => 'password-reset',
                ],
                ['channels' => ['email']]
            );

            // Soft diagnostics: log if email channel is not available
            $channelsInfo = $result['channels'] ?? [];
            if (isset($channelsInfo['email'])) {
                $emailStatus = $channelsInfo['email']['status'] ?? '';
                $reason = $channelsInfo['email']['reason'] ?? '';
                $isChannelUnavailable = in_array(
                    $reason,
                    ['channel_not_found', 'channel_unavailable'],
                    true
                );
                if ($emailStatus !== 'success' && $isChannelUnavailable) {
                    $msg = 'EmailVerification: email channel not available'
                        . ' (password reset): ' . $reason;
                    error_log($msg);
                }
            } elseif (($result['status'] ?? '') === 'failed' && (int)($result['sent_count'] ?? 0) === 0) {
                error_log(
                    'EmailVerification: notification failed; no channels succeeded for password reset'
                );
            }

            // Use the NotificationResultParser to handle the result
            $parsedResult = \Glueful\Notifications\Utils\NotificationResultParser::parseEmailResult(
                $result,
                [
                    'email' => $email,
                    'expires_in' => self::OTP_EXPIRY_MINUTES * 60
                ],
                'Password reset code sent to your email'
            );


            return $parsedResult;
        } catch (\Exception $e) {
            $errorResult = [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'system_error'
            ];


            return $errorResult;
        }
    }

    /**
     * Check if email provider is properly configured
     *
     * @return bool True if email provider is properly configured
     */
    public function isEmailProviderConfigured(): bool
    {
        try {
            // Delegate to EmailNotificationProvider's implementation
            return $this->emailProvider->isEmailProviderConfigured();
        } catch (\Exception $e) {
            error_log("Error checking email provider configuration: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sanitize email address for use in cache keys
     *
     * PSR-16 cache implementations reject certain characters in cache keys,
     * including '@' which is present in all email addresses. This method
     * converts email addresses to a cache-safe format using base64 encoding.
     *
     * @param string $email Email address to sanitize
     * @return string Sanitized cache-safe string
     */
    private function sanitizeEmailForCacheKey(string $email): string
    {
        // Use base64 encoding and replace characters that might still cause issues
        // The resulting string will be URL-safe and cache-key compliant
        return str_replace(['/', '+', '='], ['_', '-', ''], base64_encode($email));
    }
}
