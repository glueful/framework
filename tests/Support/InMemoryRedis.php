<?php

declare(strict_types=1);

namespace Glueful\Tests\Support;

/**
 * In-memory stand-in for the phpredis client, for driver tests that need no Redis server.
 */
class InMemoryRedis extends \Redis
{
    /** @var array<string, array<string, mixed>> */
    public array $hashes = [];
    /** @var array<string, list<string>> */
    public array $lists = [];
    /** @var array<string, array<string, int|float>> */
    public array $sets = [];

    public function multi($value = \Redis::MULTI): \Redis|bool
    {
        return true;
    }

    public function exec(): \Redis|array|false
    {
        return [];
    }

    public function hMset($key, $fields): \Redis|false
    {
        $this->hashes[(string) $key] = array_merge($this->hashes[(string) $key] ?? [], (array) $fields);
        return $this;
    }

    public function hGetAll($key): \Redis|array|false
    {
        return $this->hashes[(string) $key] ?? [];
    }

    public function hDel($key, ...$fields): \Redis|int|false
    {
        $count = 0;
        foreach ($fields as $field) {
            if (isset($this->hashes[(string) $key][(string) $field])) {
                unset($this->hashes[(string) $key][(string) $field]);
                $count++;
            }
        }
        return $count;
    }

    public function expire($key, $timeout, $mode = null): \Redis|bool
    {
        return true;
    }

    public function sAdd($key, $value, ...$other_values): \Redis|int|false
    {
        return 1;
    }

    public function rPush($key, ...$elements): \Redis|int|false
    {
        foreach ($elements as $element) {
            $this->lists[(string) $key][] = (string) $element;
        }
        return count($this->lists[(string) $key]);
    }

    public function lPush($key, ...$elements): \Redis|int|false
    {
        foreach ($elements as $element) {
            array_unshift($this->lists[(string) $key], (string) $element);
        }
        return count($this->lists[(string) $key]);
    }

    public function lPop($key, $count = 0): \Redis|array|string|false
    {
        return array_shift($this->lists[(string) $key]);
    }

    public function zAdd($key, $score_or_options, ...$more_scores_and_mems): \Redis|int|float|false
    {
        $member = (string) ($more_scores_and_mems[0] ?? '');
        $this->sets[(string) $key][$member] = (int) $score_or_options;
        return 1;
    }

    public function zRem($key, $member, ...$other_members): \Redis|int|false
    {
        unset($this->sets[(string) $key][(string) $member]);
        return 1;
    }

    public function zRangeByScore($key, $start, $end, array $options = []): \Redis|array|false
    {
        $max = (int) $end;
        $members = [];
        foreach ($this->sets[(string) $key] ?? [] as $member => $score) {
            if ($score <= $max) {
                $members[] = $member;
            }
        }
        return $members;
    }

    public function lRange($key, $start, $end): \Redis|array|false
    {
        $list = $this->lists[(string) $key] ?? [];
        $length = $end < 0 ? count($list) + $end + 1 - $start : $end - $start + 1;

        return array_slice($list, $start, max(0, $length));
    }

    public function lLen($key): \Redis|int|false
    {
        return count($this->lists[(string) $key] ?? []);
    }

    public function lRem($key, $value, $count = 0): \Redis|int|false
    {
        $removed = 0;
        foreach ($this->lists[(string) $key] ?? [] as $i => $element) {
            if ($element === (string) $value && ($count === 0 || $removed < abs($count))) {
                unset($this->lists[(string) $key][$i]);
                $removed++;
            }
        }
        $this->lists[(string) $key] = array_values($this->lists[(string) $key] ?? []);

        return $removed;
    }

    public function del($key, ...$other_keys): \Redis|int|false
    {
        $removed = 0;
        foreach (array_merge((array) $key, $other_keys) as $k) {
            if (isset($this->lists[(string) $k])) {
                unset($this->lists[(string) $k]);
                $removed++;
            }
        }

        return $removed;
    }

    public function keys($pattern): \Redis|array|false
    {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote((string) $pattern, '/')) . '$/';

        return array_values(array_filter(
            array_keys($this->lists),
            fn(string $k): bool => preg_match($regex, $k) === 1 && ($this->lists[$k] ?? []) !== []
        ));
    }
}
