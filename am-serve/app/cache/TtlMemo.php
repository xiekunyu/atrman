<?php

namespace app\cache;

/**
 * 进程内有界备忘。
 *
 * cap：最大条目数，LRU 淘汰最久未访问；ttl：条目有效期秒数，0 表示永不过期；
 * remember() 的 loader 在 memo 临界区之外执行，允许内部 I/O。
 * flush() 仅清当前 worker，跨 worker 一致性靠短 TTL 收敛。
 */
final class TtlMemo
{
    /** @var array<int|string, array{e:int, v:mixed}> 数组顺序即 LRU 序，头部最旧 */
    private array $items = [];

    private readonly \Closure $clock;

    public function __construct(
        private readonly int $cap,
        private readonly int $ttl = 0,
        ?\Closure $clock = null,
    ) {
        if ($cap < 1) {
            throw new \InvalidArgumentException('TtlMemo 的 cap 必须大于 0');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    /**
     * 取键对应值；未命中或已过期则调 loader 重算并回填。
     * loader 在 memo 读写临界区之外执行，允许内部存在 I/O。
     */
    public function remember(int|string $key, callable $loader): mixed
    {
        if (isset($this->items[$key])) {
            $entry = $this->items[$key];
            if ($entry['e'] === 0 || $entry['e'] > $this->now()) {
                // LRU：触碰后移到最新端
                unset($this->items[$key]);
                $this->items[$key] = $entry;
                return $entry['v'];
            }
            unset($this->items[$key]);
        }

        $value = $loader();
        $this->put($key, $value);
        return $value;
    }

    /** 直接回填（键已存在则刷新 TTL 与 LRU 顺序） */
    public function put(int|string $key, mixed $value): void
    {
        unset($this->items[$key]);
        $this->items[$key] = [
            'e' => $this->ttl > 0 ? $this->now() + $this->ttl : 0,
            'v' => $value,
        ];
        while (count($this->items) > $this->cap) {
            unset($this->items[array_key_first($this->items)]);
        }
    }

    public function forget(int|string $key): void
    {
        unset($this->items[$key]);
    }

    public function flush(): void
    {
        $this->items = [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
