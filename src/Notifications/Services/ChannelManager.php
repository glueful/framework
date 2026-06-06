<?php

declare(strict_types=1);

namespace Glueful\Notifications\Services;

use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Exceptions\ChannelAlreadyRegisteredException;
use InvalidArgumentException;

/**
 * Channel Manager Service
 *
 * Manages notification channels for delivery of notifications.
 * Acts as a registry for channel drivers and handles channel operations.
 *
 * @package Glueful\Notifications\Services
 */
class ChannelManager
{
    /**
     * @var array<string, NotificationChannel> Registered notification channels
     */
    private array $channels = [];

    /**
     * @var array<string, mixed> Configuration options for the channel manager
     */
    private array $config;

    /**
     * ChannelManager constructor
     *
     * @param array<string, mixed> $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Register a notification channel.
     *
     * Idempotent for the same concrete class (safe across repeated boots), but a *different*
     * class claiming an already-registered name is a real conflict and throws. Use
     * {@see self::replaceChannel()} to override intentionally.
     *
     * @param NotificationChannel $channel The channel to register
     * @return self
     * @throws ChannelAlreadyRegisteredException If a different channel class already holds the name
     */
    public function registerChannel(NotificationChannel $channel): self
    {
        $channelName = $channel->getChannelName();

        if ($this->hasChannel($channelName)) {
            $existing = $this->channels[$channelName];
            if ($existing::class === $channel::class) {
                return $this; // same class already registered — no-op
            }

            throw ChannelAlreadyRegisteredException::forName($channelName, $existing::class, $channel::class);
        }

        $this->channels[$channelName] = $channel;

        return $this;
    }

    /**
     * Register a channel, overwriting any existing channel with the same name.
     *
     * Intended for tests and intentional overrides where {@see self::registerChannel()}'s
     * conflict protection should be bypassed.
     *
     * @param NotificationChannel $channel The channel to register
     * @return self
     */
    public function replaceChannel(NotificationChannel $channel): self
    {
        $this->channels[$channel->getChannelName()] = $channel;

        return $this;
    }

    /**
     * Get a registered channel by name
     *
     * @param string $name Channel name
     * @return NotificationChannel The notification channel
     * @throws InvalidArgumentException If the channel doesn't exist
     */
    public function getChannel(string $name): NotificationChannel
    {
        if (!$this->hasChannel($name)) {
            throw new InvalidArgumentException("Channel '{$name}' is not registered.");
        }

        return $this->channels[$name];
    }

    /**
     * Check if a channel is registered
     *
     * @param string $name Channel name
     * @return bool Whether the channel exists
     */
    public function hasChannel(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /**
     * Remove a registered channel
     *
     * @param string $name Channel name
     * @return self
     */
    public function removeChannel(string $name): self
    {
        if ($this->hasChannel($name)) {
            unset($this->channels[$name]);
        }

        return $this;
    }

    /**
     * Get all registered channels
     *
     * @return array<string, NotificationChannel> Array of registered channels
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Get the names of all registered channels.
     *
     * @return array<string> Registered channel names
     */
    public function getRegisteredChannelNames(): array
    {
        return array_keys($this->channels);
    }

    /**
     * Get only channels that are currently available for sending.
     *
     * @return array<string, NotificationChannel> Array of available channels
     */
    public function getActiveChannels(): array
    {
        return array_filter($this->channels, function (NotificationChannel $channel) {
            return $channel->isAvailable();
        });
    }

    /**
     * Get the names of channels that are currently available for sending.
     *
     * @return array<string> Active channel names
     */
    public function getActiveChannelNames(): array
    {
        return array_keys($this->getActiveChannels());
    }

    /**
     * Get the manager configuration
     *
     * @return array<string, mixed> Manager configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set a configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function setConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }
}
